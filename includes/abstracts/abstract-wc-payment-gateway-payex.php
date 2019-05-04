<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WC_Payment_Gateway_Payex extends WC_Payment_Gateway
	implements WC_Payment_Gateway_Payex_Interface {

	/** @var \PayEx\Api\Client */
	public $client;

	/**
	 * @var \WC_Payex_Transactions
	 */
	public $transactions;

	/**
	 * Merchant Token
	 * @var string
	 */
	public $merchant_token = '';

	/**
	 * Test Mode
	 * @var string
	 */
	public $testmode = 'yes';

	/**
	 * Debug Mode
	 * @var string
	 */
	public $debug = 'no';

	/**
	 * Get PayEx Client
	 * @return \PayEx\Api\Client
	 */
	public function getClient() {
		if ( ! $this->client ) {
			global $wp_version;
			$plugin_version = get_file_data(
				dirname(__FILE__) . '/../../woocommerce-payex-psp.php',
				array('Version'),
				'woocommerce-gateway-payex-psp'
			);

			$this->client = new \PayEx\Api\Client();
			$this->client->setMerchantToken( $this->merchant_token );
			$this->client->setMode( $this->testmode === 'yes' ? \PayEx\Api\Client::MODE_TEST : \PayEx\Api\Client::MODE_PRODUCTION );
			$this->client->setPlatform( sprintf("WordPress/%s WooCommerce/%s PayEx.Psp.WooCommerce/%s",
				$wp_version,
				WC_VERSION,
				$plugin_version[0]
			) );
		}

		return $this->client;
	}

	/**
	 * Debug Log
	 *
	 * @param $message
	 * @param $level
	 *
	 * @see WC_Log_Levels
	 *
	 * @return void
	 */
	protected function log( $message, $level = 'notice' ) {
		// Is Enabled
		if ( $this->debug !== 'yes' ) {
			return;
		}

		// Get Logger instance
		$log = new WC_Logger();

		// Write message to log
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, TRUE );
		}

		if ( $this->is_wc3() ) {
			$log->log( $level, $message, array(
				'source'  => $this->id,
				'_legacy' => TRUE
			) );
		} else {
			$log->add( $this->id, sprintf( '[%s] %s', $level, $message ) );
		}
	}

	/**
	 * Do API Request
	 *
	 * @param       $method
	 * @param       $url
	 * @param array $params
	 *
	 * @return array|mixed|object
	 * @throws \Exception
	 */
	public function request( $method, $url, $params = array() ) {
		$start = microtime(true);
		if ( $this->debug === 'yes' ) {
			$this->log( sprintf('Request: %s %s %s', $method, $url, json_encode( $params, JSON_PRETTY_PRINT ) ) );
		}

		try {
			/** @var \PayEx\Api\Response $response */
			$response = $this->getClient()->request( $method, $url, $params );
			$result   = $response->toArray();

			if ( $this->debug === 'yes' ) {
                $time = microtime(true) - $start;
				$this->log( sprintf( '[%.4F] Response: %s', $time, $response->getBody() ) );
			}

			return $result;
		} catch ( \PayEx\Api\Exception $e ) {
			if ( $this->debug === 'yes' ) {
                $time = microtime(true) - $start;
				$this->log( sprintf( '[%.4F] Exception: %s', $time, $e->getMessage() ) );
			}

			throw $e;
		}
	}

	/**
	 * Finds an Order based on an order key.
	 *
	 * @param $order_key
	 *
	 * @return bool|WC_Order
	 */
	protected function get_order_by_order_key( $order_key ) {
		$order_id = wc_get_order_id_by_order_key( $order_key );
		if ( $order_id ) {
			return wc_get_order( $order_id );
		}

		return FALSE;
	}

	/**
	 * Get Post Id by Meta
	 *
	 * @deprecated
	 * @param $key
	 * @param $value
	 *
	 * @return null|string
	 */
	protected function get_post_id_by_meta( $key, $value ) {
		return px_get_post_id_by_meta( $key, $value );
	}


	/**
	 * Check is WooCommerce >= 3.0
	 * @return bool
	 */
	public function is_wc3() {
		return version_compare( WC()->version, '3.0', '>=' );
	}

	/**
	 * Get Order Lines
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	protected function get_order_items( $order ) {
		$item = array();

		// WooCommerce 3
		if ( $this->is_wc3() ) {
			foreach ( $order->get_items() as $order_item ) {
				/** @var WC_Order_Item_Product $order_item */
				$price        = $order->get_line_subtotal( $order_item, FALSE, FALSE );
				$priceWithTax = $order->get_line_subtotal( $order_item, TRUE, FALSE );
				$tax          = $priceWithTax - $price;
				$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'product',
					'name'              => $order_item->get_name(),
					'qty'               => $order_item->get_quantity(),
					'price_with_tax'    => sprintf( "%.2f", $priceWithTax ),
					'price_without_tax' => sprintf( "%.2f", $price ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			};

			// Add Shipping Line
			if ( (float) $order->get_shipping_total() > 0 ) {
				$shipping        = $order->get_shipping_total();
				$tax             = $order->get_shipping_tax();
				$shippingWithTax = $shipping + $tax;
				$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'shipping',
					'name'              => $order->get_shipping_method(),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", $shippingWithTax ),
					'price_without_tax' => sprintf( "%.2f", $shipping ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			// Add fee lines
			foreach ( $order->get_fees() as $order_fee ) {
				/** @var WC_Order_Item_Fee $order_fee */
				$fee        = $order_fee->get_total();
				$tax        = $order_fee->get_total_tax();
				$feeWithTax = $fee + $tax;
				$taxPercent = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'fee',
					'name'              => $order_fee->get_name(),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", $feeWithTax ),
					'price_without_tax' => sprintf( "%.2f", $fee ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			// Add discount line
			if ( $order->get_total_discount( FALSE ) > 0 ) {
				$discount        = $order->get_total_discount( TRUE );
				$discountWithTax = $order->get_total_discount( FALSE );
				$tax             = $discountWithTax - $discount;
				$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'discount',
					'name'              => __( 'Discount', 'woocommerce-gateway-payex-psp' ),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", - 1 * $discountWithTax ),
					'price_without_tax' => sprintf( "%.2f", - 1 * $discount ),
					'tax_price'         => sprintf( "%.2f", - 1 * $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			return $item;
		}

		// WooCommerce 2.6
		foreach ( $order->get_items() as $order_item ) {
			$price        = $order->get_line_subtotal( $order_item, FALSE, FALSE );
			$priceWithTax = $order->get_line_subtotal( $order_item, TRUE, FALSE );
			$tax          = $priceWithTax - $price;
			$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'product',
				'name'              => $order_item['name'],
				'qty'               => $order_item['qty'],
				'price_with_tax'    => sprintf( "%.2f", $priceWithTax ),
				'price_without_tax' => sprintf( "%.2f", $price ),
				'tax_price'         => sprintf( "%.2f", $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		};

		// Add Shipping Line
		if ( (float) $order->order_shipping > 0 ) {
			$taxPercent = ( $order->order_shipping_tax > 0 ) ? round( 100 / ( $order->order_shipping / $order->order_shipping_tax ) ) : 0;

			$item[] = array(
				'type'              => 'shipping',
				'name'              => $order->get_shipping_method(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $order->order_shipping + $order->order_shipping_tax ),
				'price_without_tax' => sprintf( "%.2f", $order->order_shipping ),
				'tax_price'         => sprintf( "%.2f", $order->order_shipping_tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			$taxPercent = ( $order_fee['line_tax'] > 0 ) ? round( 100 / ( $order_fee['line_total'] / $order_fee['line_tax'] ) ) : 0;

			$item[] = array(
				'type'              => 'fee',
				'name'              => $order_fee['name'],
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $order_fee['line_total'] + $order_fee['line_tax'] ),
				'price_without_tax' => sprintf( "%.2f", $order_fee['line_total'] ),
				'tax_price'         => sprintf( "%.2f", $order_fee['line_tax'] ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		// Add discount line
		if ( $order->get_total_discount( FALSE ) > 0 ) {
			$discount        = $order->get_total_discount( TRUE );
			$discountWithTax = $order->get_total_discount( FALSE );
			$tax             = $discountWithTax - $discount;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'discount',
				'name'              => __( 'Discount', 'woocommerce-gateway-payex-psp' ),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", - 1 * $discountWithTax ),
				'price_without_tax' => sprintf( "%.2f", - 1 * $discount ),
				'tax_price'         => sprintf( "%.2f", - 1 * $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		return $item;
	}

	/**
	 * Get Order Info
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	protected function get_order_info( $order ) {
		$amount       = 0;
		$vatAmount    = 0;
		$descriptions = array();
		$items        = $this->get_order_items( $order );
		foreach ( $items as $item ) {
			$amount         += $item['price_with_tax'];
			$vatAmount      += $item['tax_price'];
			$descriptions[] = array(
				'amount'      => $item['price_with_tax'],
				'vatAmount'   => $item['tax_price'], // @todo Validate
				'itemAmount'  => sprintf( "%.2f", $item['price_with_tax'] / $item['qty'] ),
				'quantity'    => $item['qty'],
				'description' => $item['name']
			);
		}

		return array(
			'amount'     => $amount,
			'vat_amount' => $vatAmount,
			'items'      => $descriptions
		);
	}

	/**
	 * Extract operation value from operations list
	 *
	 * @param array  $operations
	 * @param string $operation_id
	 * @param bool   $single
	 *
	 * @return bool|string|array
	 */
	protected static function get_operation( $operations, $operation_id, $single = TRUE ) {
		$operation = array_filter( $operations, function ( $value, $key ) use ( $operation_id ) {
			return ( is_array( $value ) && $value['rel'] === $operation_id );
		}, ARRAY_FILTER_USE_BOTH );

		if ( count( $operation ) > 0 ) {
			$operation = array_shift( $operation );

			return $single ? $operation['href'] : $operation;
		}

		return FALSE;
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param  int    $order_id
	 * @param  float  $amount
	 * @param  string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return FALSE;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			$amount = $order->get_total();
		}

		try {
			px_refund_payment( $order, $amount, $reason );

			return TRUE;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund', $e->getMessage() );
		}
	}

	/**
	 * Process Transaction
	 * @param array $transaction
	 * @param WC_Order $order
	 *
	 * @throws Exception
	 */
	public function process_transaction($transaction, $order) {
		// Disable status change hook
		remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

		try {
			// Apply action
			switch ( $transaction['type'] ) {
				case 'Authorization':
					// Check is action was performed
					if ( $state = $order->get_meta('_payex_payment_state' ) && ! empty( $state ) ) {
						throw new Exception( sprintf( 'Action of Transaction #%s already performed', $transaction['number'] ) );
					}

					if ( $transaction['state'] === 'Failed' ) {
						$order->update_meta_data( '_transaction_id', $transaction['number'] );
						$order->save_meta_data();

						$reason = implode('; ', [$transaction['failedReason'], $transaction['failedErrorCode'], $transaction['failedErrorDescription']]);
						$order->update_status( 'failed', sprintf( __( 'Transaction failed. Reason: %s.', 'woocommerce-gateway-payex-psp' ), $reason ) );
						break;
					}

					if ( $transaction['state'] === 'Pending' ) {
						$order->update_meta_data( '_transaction_id', $transaction['number'] );
						$order->save_meta_data();
						$order->update_status( 'on-hold', __( 'Transaction pending.', 'woocommerce-gateway-payex-psp' ) );
						break;
					}

					$order->update_meta_data( '_payex_payment_state', 'Authorized' );
					$order->update_meta_data( '_payex_transaction_authorize', $transaction['id'] );
					$order->update_meta_data( '_transaction_id', $transaction['number'] );
					$order->save_meta_data();

					// Reduce stock
					$order_stock_reduced = $order->get_meta( '_order_stock_reduced' );
					if ( ! $order_stock_reduced ) {
						wc_reduce_stock_levels( $order->get_id() );
					}

					$order->update_status( 'on-hold', __( 'Payment authorized.', 'woocommerce-gateway-payex-psp' ) );

					// Save Payment Token
					if ( $order->get_meta( '_payex_generate_token' ) === '1' &&
					     count( $order->get_payment_tokens() ) === 0
					) {
						$payment_id = $order->get_meta( '_payex_payment_id' );
						$result     = $this->request( 'GET', $payment_id . '/authorizations' );
						if ( isset( $result['authorizations']['authorizationList'][0] ) &&
						     isset( $result['authorizations']['authorizationList'][0]['paymentToken'] ) )
						{
							$authorization = $result['authorizations']['authorizationList'][0];
							$paymentToken  = $authorization['paymentToken'];
							$cardBrand     = $authorization['cardBrand'];
							$maskedPan     = $authorization['maskedPan'];
							$expiryDate    = explode('/', $authorization['expiryDate'] );

							// Create Payment Token
							$token = new WC_Payment_Token_Payex();
							$token->set_gateway_id( $this->id );
							$token->set_token( $paymentToken );
							$token->set_last4( substr( $maskedPan, -4 ) );
							$token->set_expiry_year( $expiryDate[1] );
							$token->set_expiry_month( $expiryDate[0] );
							$token->set_card_type( strtolower( $cardBrand ) );
							$token->set_user_id( $order->get_user_id() );
							$token->set_masked_pan( $maskedPan );
							$token->save();
							if ( ! $token->get_id() ) {
								throw new Exception( __( 'There was a problem adding the card.', 'woocommerce-gateway-payex-psp' ) );
							}

							// Add payment token
							$order->add_payment_token( $token );
						}
					}
					break;
				case 'Capture':
				case 'Sale':
					// Check is action was performed
					if ( $order->get_meta('_payex_payment_state' ) === 'Captured' ) {
						throw new Exception( sprintf( 'Action of Transaction #%s already performed', $transaction['number'] ) );
					}

					if ( $transaction['state'] === 'Failed' ) {
						$order->update_meta_data( '_transaction_id', $transaction['number'] );
						$order->save_meta_data();

						$reason = implode('; ', [$transaction['failedReason'], $transaction['failedErrorCode'], $transaction['failedErrorDescription']]);
						$order->update_status( 'failed', sprintf( __( 'Transaction failed. Reason: %s.', 'woocommerce-gateway-payex-psp' ), $reason ) );
						break;
					}

					if ( $transaction['state'] === 'Pending' ) {
						$order->update_meta_data( '_transaction_id', $transaction['number'] );
						$order->save_meta_data();
						$order->update_status( 'on-hold', __( 'Transaction pending.', 'woocommerce-gateway-payex-psp' ) );
						break;
					}

					$order->update_meta_data( '_payex_payment_state', 'Captured' );
					$order->update_meta_data( '_payex_transaction_capture', $transaction['id'] );
					$order->save_meta_data();

					$order->payment_complete( $transaction['number'] );
					$order->add_order_note( __( 'Transaction captured.', 'woocommerce-gateway-payex-psp' ) );
					break;
				case 'Cancellation':
					// Check is action was performed
					if ( $order->get_meta('_payex_payment_state' ) === 'Cancellation' ) {
						throw new Exception( sprintf( 'Action of Transaction #%s already performed', $transaction['number'] ) );
					}

					if ( $transaction['state'] === 'Failed' ) {
						throw new Exception( 'Cancellation transaction is failed' );
					}

					$order->update_meta_data( '_payex_payment_state', 'Cancelled' );
					$order->update_meta_data( '_payex_transaction_cancel', $transaction['id'] );
					$order->update_meta_data( '_transaction_id', $transaction['number'] );
					$order->save_meta_data();

					if ( ! $order->has_status( 'cancelled' ) ) {
						$order->update_status( 'cancelled', __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
					} else {
						$order->add_order_note( __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
					}
					break;
				case 'Reversal':
					// @todo Implement Refunds creation
					// @see wc_create_refund()
					throw new Exception( 'Error: Reversal transaction don\'t implemented yet.' );
				default:
					throw new Exception( sprintf( 'Error: Unknown type %s', $transaction['type'] ) );
			}
		} catch ( Exception $e ) {
			if ( $this->debug === 'yes' ) {
				$this->log( sprintf(  '%s::%s Exception: %s', __CLASS__, __METHOD__, $e->getMessage() ) );
			}

			// Enable status change hook
			add_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10, 4 );

			throw $e;
		}

		// Enable status change hook
		add_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10, 4 );
	}

	/**
	 * Checks an order to see if it contains a subscription.
	 * @see wcs_order_contains_subscription()
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public static function order_contains_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return FALSE;
		}

		return wcs_order_contains_subscription( $order );
	}

	/**
	 * WC Subscriptions: Is Payment Change
	 * @return bool
	 */
	public static function wcs_is_payment_change() {
		return class_exists( 'WC_Subscriptions_Change_Payment_Gateway', FALSE ) &&
		       WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
	}

	/**
	 * Check is Cart have Subscription Products
	 * @return bool
	 */
	public static function wcs_cart_have_subscription() {
		if ( ! class_exists( 'WC_Product_Subscription', FALSE ) ) {
			return FALSE;
		}

		// Check is Recurring Payment
		$cart = WC()->cart->get_cart();
		foreach ( $cart as $key => $item ) {
			if ( is_object( $item['data'] ) && get_class( $item['data'] ) === 'WC_Product_Subscription' ) {
				return TRUE;
			}
		}

		return FALSE;
	}
}
