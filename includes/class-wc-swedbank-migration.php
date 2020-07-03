<?php

defined( 'ABSPATH' ) || exit;

class WC_Swedbank_Pay_Migration
{
	/**
	 * Handle updates
	 */
	public static function update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check if it has been already migrated
		if ( get_option( 'sb_payex_migrated' ) !== false ) {
			return;
		}

		self::migrate_settings();
		self::migrate_saved_cards();
		self::migrate_orders();

		// Deactivate plugin
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$plugins = get_plugins();
		foreach ( $plugins as $file => $plugin ) {
			if ( strpos( $file, 'woocommerce-gateway-payex-payment.php' ) !== false
                 && is_plugin_active( $file )
            ) {
				deactivate_plugins( $file, true );
			    break;
			}
		}

		// Migration flag
		delete_option( 'sb_payex_migrated' );
		add_option( 'sb_payex_migrated', true );

		echo esc_html__( 'Migration finished.', 'swedbank-pay-woocommerce-payments' );
		?>
		<script type="application/javascript">
            window.onload = function() {
                setTimeout(function () {
                    window.location.href = '<?php echo esc_url( admin_url( 'index.php' ) ); ?>';
                }, 3000);
            }
		</script>

		<?php
	}

	/**
	 * Migrate Settings
	 */
	private static function migrate_settings() {
		// Copy settings
		$settings = get_option( 'woocommerce_payex_settings' );
		if ( $settings ) {
			$new_settings = array(
				'enabled'      => $settings['enabled'],
				'title'        => $settings['title'],
				'description'  => $settings['description'],
				'testmode'     => $settings['testmode'],
				'debug'        => $settings['debug'],
				'terms_url'    => $settings['agreement_url'],
				'save_cc'      => $settings['save_cards'],
				'auto_capture' => $settings['purchase_operation'] === 'SALE' ? 'yes' : 'no',
			);

			// Overwrite the settings if it was exists before
			$exists_settings = get_option( 'woocommerce_payex_psp_cc_settings' );
			if ( $exists_settings ) {
				$new_settings = array_merge( $exists_settings, $new_settings );
			}

			update_option( 'woocommerce_payex_psp_cc_settings', $new_settings, true );

			self::log( sprintf( '[INFO] There are new settings: %s.',
				var_export( $new_settings, true )
			) );
		}
	}

	/**
	 * Migrate Save Cards
	 */
	private static function migrate_saved_cards() {
		foreach ( get_users() as $user ) {
			$cards = self::get_saved_cards( $user->ID );
			foreach ( $cards as $card ) {
				$card_meta = get_post_meta( $card->ID, '_payex_card', true );
				$masked_pan = $card_meta['masked_number'];
				$payment_token = $card_meta['agreement_reference'];
				$recurrence_token = $card_meta['agreement_reference'];

				// Check if token has been migrated
				$token = self::get_payment_token( $payment_token );
				if ( $token ) {
					self::log( sprintf( '[WARNING] The card %s/%s/%s is already exists. Token ID: %s.',
						$user->ID,
						$card->ID,
						$payment_token,
						$token->get_id()
					) );

					continue;
				}

				// Create Payment Token
				$token = new WC_Payment_Token_Swedbank_Pay();
				$token->set_gateway_id( 'payex_psp_cc' );
				$token->set_token( $payment_token );
				$token->set_recurrence_token( $recurrence_token );
				$token->set_last4( substr( $masked_pan, - 4 ) );
				$token->set_expiry_year( date( 'Y', strtotime( $card_meta['expire_date'] ) ) );
				$token->set_expiry_month( date( 'm', strtotime( $card_meta['expire_date'] ) ) );
				$token->set_card_type( strtolower( $card_meta['payment_method'] ) );
				$token->set_user_id( $user->ID );
				$token->set_masked_pan( $masked_pan );
				$token->set_default( $card_meta['is_default'] === 'yes' );

				// Save Credit Card
				$token->save();
				if ( ! $token->get_id() ) {
					self::log( sprintf( '[ERROR] There was a problem adding the card %s/%s/%s.',
						$user->ID,
						$card->ID,
						$payment_token
					) );

					continue;
				}

				self::log( sprintf( '[INFO] The card %s/%s/%s has been migrated. Token ID: %s.',
					$user->ID,
					$card->ID,
					$payment_token,
					$token->get_id()
				) );
			}
		}
	}

	/**
	 * Migrate Orders
	 */
	private static function migrate_orders() {
		$args = array(
			'numberposts'    => -1,
			'type'           => array( 'shop_order', 'shop_subscription' ),
			'payment_method' => 'payex'
		);

		$orders = wc_get_orders( $args );
		foreach ( $orders as $order ) {
			if ( ! $order ) {
				self::log( sprintf( '[WARNING] Order #%s has been skipped. Order can\'t be loaded.',
					$order->get_id()
				) );

				continue;
			}

			$has_migrated  = get_post_meta( $order->get_id(), '_sb_has_migrated', true );
			if ( ! empty( $has_migrated ) ) {
				self::log( sprintf( '[WARNING] Order #%s has been skipped. It has been already migrated.',
                    $order->get_id()
                ) );

			    continue;
			}

			// Change payment method
			update_post_meta( $order->get_id(), '_payment_method', 'payex_psp_cc' );

			// Check if the order has an assigned card
			$card_id = get_post_meta( $order->get_id(), '_payex_card_id', true );
			if ( ! empty( $card_id) && count( $order->get_payment_tokens() ) === 0 ) {
				// Load Saved Credit Card
				$post = get_post( $card_id );
				if ( ! $post ) {
					self::log( sprintf( '[WARNING] The saved card #%s of order #%s doesn\'t exists',
						$card_id,
						$order->get_id()
					) );

					continue;
				}

				$card = get_post_meta( $post->ID, '_payex_card', true );
				if ( empty( $card ) ) {
					self::log( sprintf( '[WARNING] The metadata of saved card #%s of order #%s dont\'t exists',
						$card_id,
						$order->get_id()
					) );

					continue;
				}

				// Load a migrated token
				$token = self::get_payment_token( $card['agreement_reference'] );
				if ( ! $token ) {
					self::log( sprintf( '[ERROR] The token of order #%s can\'t be migrated',
						$order->get_id()
					) );

					continue;
				}

				$order->add_payment_token( $token );

				self::log( sprintf( '[INFO] The token of order #%s has been migrated. Token ID: %s.',
					$order->get_id(),
					$token->get_id()
				) );
			}

			// Change recurring payment method if needs
			$recurring = get_post_meta( $order->get_id(), '_recurring_payment_method' );
			if ( ! empty( $recurring ) ) {
				update_post_meta( $order->get_id(), '_recurring_payment_method', 'payex_psp_cc' );
			}

			self::log( sprintf( '[INFO] The order #%s has been migrated.',
				$order->get_id()
			) );

			// Migration flag
			update_post_meta( $order->get_id(), '_sb_has_migrated', '1' );
		}
	}

	/**
     * Log message
     *
	 * @param string $message
	 */
	private static function log( $message ) {
		$log = new WC_Logger();
		$log->add( 'wc-swedbankpay-migration', $message );
	}

	/**
	 * Get Saved Credit Cards.
	 *
	 * @param int $user_id
	 *
	 * @return int[]|WP_Post[]
	 */
	private static function get_saved_cards( $user_id ) {
		$args = array(
			'post_type'   => 'payex_credit_card',
			'author'      => $user_id,
			'numberposts' => - 1,
			'orderby'     => 'post_date',
			'order'       => 'ASC',
		);

		return get_posts( $args );
	}

	/**
	 * Get Payment Token by Token string.
	 *
	 * @param string $token
	 *
	 * @return WC_Payment_Token_Swedbank_Pay|WC_Payment_Token|false
	 */
	private static function get_payment_token( $token ) {
		global $wpdb;

		$query = "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = '%s';";
		$token_id = $wpdb->get_var( $wpdb->prepare( $query, $token ) );
		if ( ! $token_id ) {
			return false;
		}

		return WC_Payment_Tokens::get( $token_id );
	}
}
