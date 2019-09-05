<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Gateway_Payex_Cc extends WC_Payment_Gateway_Payex
	implements WC_Payment_Gateway_Payex_Interface {

	/**
	 * Merchant Token
	 * @var string
	 */
	public $merchant_token = '';

	/**
	 * Payee Id
	 * @var string
	 */
	public $payee_id = '';

	/**
	 * Test Mode
	 * @var string
	 */
	public $testmode = 'yes';

	/**
	 * Debug Mode
	 * @var string
	 */
	public $debug = 'yes';

	/**
	 * Locale
	 * @var string
	 */
	public $culture = 'en-US';

	/**
	 * Auto Capture
	 * @var string
	 */
	public $auto_capture = 'no';

	/**
	 * Save CC
	 * @var string
	 */
	public $save_cc = 'no';

	/**
	 * Terms URL
	 * @var string
	 */
	public $terms_url = '';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Payex_Transactions::instance();

		$this->id           = 'payex_psp_cc';
		$this->has_fields   = true;
		$this->method_title = __( 'Credit Card', 'payex-woocommerce-payments' );
		$this->icon         = apply_filters( 'woocommerce_payex_cc_icon', plugins_url( '/assets/images/creditcards.png', dirname( __FILE__ ) ) );
		$this->supports     = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			//'multiple_subscriptions',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled        = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
		$this->title          = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->description    = isset( $this->settings['description'] ) ? $this->settings['description'] : '';
		$this->merchant_token = isset( $this->settings['merchant_token'] ) ? $this->settings['merchant_token'] : $this->merchant_token;
		$this->payee_id       = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;
		$this->testmode       = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->debug          = isset( $this->settings['debug'] ) ? $this->settings['debug'] : $this->debug;
		$this->culture        = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->auto_capture   = isset( $this->settings['auto_capture'] ) ? $this->settings['auto_capture'] : $this->auto_capture;
		$this->save_cc        = isset( $this->settings['save_cc'] ) ? $this->settings['save_cc'] : $this->save_cc;
		$this->terms_url      = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'woocommerce_thankyou_' . $this->id, array(
			$this,
			'thankyou_page'
		) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array(
			$this,
			'return_handler'
		) );

		// Payment confirmation
		add_action( 'the_post', array( &$this, 'payment_confirm' ) );

		// Pending Cancel
		add_action( 'woocommerce_order_status_pending_to_cancelled', array(
			$this,
			'cancel_pending'
		), 10, 2 );

		// Action for "Add Payment Method"
		add_action( 'wp_ajax_payex_card_store', array( $this, 'payex_card_store' ) );
		add_action( 'wp_ajax_nopriv_payex_card_store', array( $this, 'payex_card_store' ) );

		// Subscriptions
		add_action( 'woocommerce_payment_complete', array( $this, 'add_subscription_card_id' ), 10, 1 );

		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array(
			$this,
			'update_failing_payment_method'
		), 10, 2 );

		add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );

		// Allow store managers to manually set card id as the payment method on a subscription
		add_filter( 'woocommerce_subscription_payment_meta', array(
			$this,
			'add_subscription_payment_meta'
		), 10, 2 );

		add_filter( 'woocommerce_subscription_validate_payment_meta', array(
			$this,
			'validate_subscription_payment_meta'
		), 10, 3 );

		add_action( 'wcs_save_other_payment_meta', array( $this, 'save_subscription_payment_meta' ), 10, 4 );

		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array(
			$this,
			'scheduled_subscription_payment'
		), 10, 2 );

		// Display the credit card used for a subscription in the "My Subscriptions" table
		add_filter( 'woocommerce_my_subscriptions_payment_method', array(
			$this,
			'maybe_render_subscription_payment_method'
		), 10, 2 );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'payex-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'payex-woocommerce-payments' ),
				'default' => 'no'
			),
			'title'          => array(
				'title'       => __( 'Title', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'payex-woocommerce-payments' ),
				'default'     => __( 'Credit Card', 'payex-woocommerce-payments' )
			),
			'description'    => array(
				'title'       => __( 'Description', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'payex-woocommerce-payments' ),
				'default'     => __( 'Credit Card', 'payex-woocommerce-payments' ),
			),
			'merchant_token' => array(
				'title'       => __( 'Merchant Token', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', 'payex-woocommerce-payments' ),
				'default'     => $this->merchant_token
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'payex-woocommerce-payments' ),
				'default'     => $this->payee_id
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'payex-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayEx Test Mode', 'payex-woocommerce-payments' ),
				'default' => $this->testmode
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'payex-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'payex-woocommerce-payments' ),
				'default' => $this->debug
			),
			'culture'        => array(
				'title'       => __( 'Language', 'payex-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				),
				'description' => __( 'Language of pages displayed by PayEx during payment.', 'payex-woocommerce-payments' ),
				'default'     => $this->culture
			),
			'auto_capture'   => array(
				'title'   => __( 'Auto Capture', 'payex-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Auto Capture', 'payex-woocommerce-payments' ),
				'default' => $this->auto_capture
			),
			'save_cc'        => array(
				'title'   => __( 'Save CC', 'payex-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Save CC feature', 'payex-woocommerce-payments' ),
				'default' => $this->save_cc
			),
			'terms_url'      => array(
				'title'       => __( 'Terms & Conditions Url', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url', 'payex-woocommerce-payments' ),
				'default'     => get_site_url()
			),
		);
	}

	/**
	 * If There are no payment fields show the description if set.
	 */
	public function payment_fields() {
		parent::payment_fields();

		if ( $this->save_cc === 'yes' ):
			if ( ! is_add_payment_method_page() ):
				$this->tokenization_script();
				$this->saved_payment_methods();
				$this->save_payment_method_checkbox();

				// Lock "Save to Account" for Recurring Payments / Payment Change
				if ( self::wcs_cart_have_subscription() || self::wcs_is_payment_change() ):
					?>
                    <script type="application/javascript">
                        (function ($) {
                            $(document).ready(function () {
                                $('input[name="wc-payex_psp_cc-new-payment-method"]').prop({
                                    'checked': true,
                                    'disabled': true
                                });
                            });

                            $(document).on('updated_checkout', function () {
                                $('input[name="wc-payex_psp_cc-new-payment-method"]').prop({
                                    'checked': true,
                                    'disabled': true
                                });
                            });
                        }(jQuery));
                    </script>
				<?php
				endif;
			endif;
		endif;
	}

	/**
	 * Validate frontend fields.
	 *
	 * Validate payment fields on the frontend.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		return true;
	}

	/**
	 * Add Payment Method
	 * @return array
	 */
	public function add_payment_method() {
		$user_id = get_current_user_id();

		// Get Customer UUID
		if ( $user_id > 0 ) {
			$customer_uuid = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $customer_uuid ) ) {
				$customer_uuid = px_uuid( $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $customer_uuid );
			}
		} else {
			$customer_uuid = px_uuid( uniqid( 'add_payment_method' ) );
		}

		$params = array(
			'payment' => array(
				'operation'            => 'Verify',
				'currency'             => get_woocommerce_currency(),
				'description'          => __( 'Verification of Credit Card', 'payex-woocommerce-payments' ),
				'payerReference'       => $customer_uuid,
				'generatePaymentToken' => true,
				'pageStripdown'        => false,
				'userAgent'            => $_SERVER['HTTP_USER_AGENT'],
				'language'             => $this->culture,
				'urls'                 => array(
					'completeUrl' => add_query_arg( 'action', 'payex_card_store', admin_url( 'admin-ajax.php' ) ),
					'cancelUrl'   => wc_get_account_endpoint_url( 'payment-methods' ),
					'callbackUrl' => WC()->api_request_url( __CLASS__ )
				),
				'payeeInfo'            => array(
					'payeeId'        => $this->payee_id,
					'payeeReference' => px_uuid( uniqid( 'add_payment_method' ) ),
				),
			)
		);

		try {
			$result = $this->request( 'POST', '/psp/creditcard/payments', $params );
		} catch ( Exception $e ) {
			$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );

			WC()->session->__unset( 'verification_payment_id' );

			return array(
				'result'   => 'failure',
				'redirect' => wc_get_account_endpoint_url( 'payment-methods' ),
			);
		}

		WC()->session->set( 'verification_payment_id', $result['payment']['id'] );

		// Redirect
		wp_redirect( self::get_operation( $result['operations'], 'redirect-verification' ) );
		exit();
	}


	/**
	 * Add Payment Method: Callback for PayEx Card
	 * @return void
	 */
	public function payex_card_store() {
		try {
			if ( ! $payment_id = WC()->session->get( 'verification_payment_id' ) ) {
				throw new Exception( __( 'There was a problem adding the card.', 'payex-woocommerce-payments' ) );
			}

			$result = $this->request( 'GET', $payment_id . '/verifications' );
			if ( isset( $result['verifications']['verificationList'][0] ) &&
			     isset( $result['verifications']['verificationList'][0]['paymentToken'] ) ) {
				$verification = $result['verifications']['verificationList'][0];
				$paymentToken = $verification['paymentToken'];
				$cardBrand    = $verification['cardBrand'];
				$maskedPan    = $verification['maskedPan'];
				$expiryDate   = explode( '/', $verification['expiryDate'] );

				// Create Payment Token
				$token = new WC_Payment_Token_Payex();
				$token->set_gateway_id( $this->id );
				$token->set_token( $paymentToken );
				$token->set_last4( substr( $maskedPan, - 4 ) );
				$token->set_expiry_year( $expiryDate[1] );
				$token->set_expiry_month( $expiryDate[0] );
				$token->set_card_type( strtolower( $cardBrand ) );
				$token->set_user_id( get_current_user_id() );
				$token->set_masked_pan( $maskedPan );

				// Save Credit Card
				$token->save();
				if ( ! $token->get_id() ) {
					throw new Exception( __( 'There was a problem adding the card.', 'payex-woocommerce-payments' ) );
				}

				WC()->session->__unset( 'verification_payment_id' );

				wc_add_notice( __( 'Payment method successfully added.', 'payex-woocommerce-payments' ) );
				wp_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
				exit();
			}
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_account_endpoint_url( 'add-payment-method' ) );
			exit();
		}
	}

	/**
	 * Thank you page
	 *
	 * @param $order_id
	 *
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		//
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array|false
	 */
	public function process_payment( $order_id ) {
		$order           = wc_get_order( $order_id );
		$token_id        = isset( $_POST['wc-payex_psp_cc-payment-token'] ) ? wc_clean( $_POST['wc-payex_psp_cc-payment-token'] ) : 'new';
		$maybe_save_card = isset( $_POST['wc-payex_psp_cc-new-payment-method'] ) && (bool) $_POST['wc-payex_psp_cc-new-payment-method'];
		$generate_token  = ( $this->save_cc === 'yes' && $maybe_save_card );

		// Try to load saved token
		$token = new WC_Payment_Token_Payex();
		if ( $token_id !== 'new' ) {
			$token = new WC_Payment_Token_Payex( $token_id );
			if ( ! $token->get_id() ) {
				wc_add_notice( __( 'Failed to load token.', 'payex-woocommerce-payments' ), 'error' );

				return false;
			}

			// Check access
			if ( $token->get_user_id() !== $order->get_user_id() ) {
				wc_add_notice( __( 'Access denied.', 'payex-woocommerce-payments' ), 'error' );
			}

			$generate_token = false;
		}

		$amount   = $order->get_total();
		$currency = $order->get_currency();
		$email    = $order->get_billing_email();
		$phone    = $order->get_billing_phone();
		$user_id  = $order->get_customer_id();

		// Get Customer UUID
		if ( $user_id > 0 ) {
			$customer_uuid = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $customer_uuid ) ) {
				$customer_uuid = px_uuid( $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $customer_uuid );
			}
		} else {
			$customer_uuid = px_uuid( uniqid( $email ) );
		}

		// Get Order UUID
		$order_uuid = px_uuid( uniqid( $order_id ) );

		// Change Payment Method
		// Orders with Zero Amount
		if ( $order->get_total() == 0 || self::wcs_is_payment_change() ) {
			// Store new Card
			if ( $token_id === 'new' ) {
				$params = array(
					'payment' => array(
						'operation'            => 'Verify',
						'currency'             => $currency,
						'description'          => sprintf( __( 'Order #%s', 'payex-woocommerce-payments' ), $order->get_order_number() ),
						'payerReference'       => $customer_uuid,
						'generatePaymentToken' => true,
						'pageStripdown'        => false,
						'userAgent'            => $order->get_customer_user_agent(),
						'language'             => $this->culture,
						'urls'                 => array(
							'completeUrl'       => add_query_arg( array(
								'verify' => 'true',
								'key'    => $order->get_order_key()
							), $this->get_return_url( $order ) ),
							'cancelUrl'         => $order->get_cancel_order_url_raw(),
							'callbackUrl'       => WC()->api_request_url( __CLASS__ ),
							'termsOfServiceUrl' => $this->terms_url
						),
						'payeeInfo'            => array(
							'payeeId'        => $this->payee_id,
							'payeeReference' => $order_uuid,
						),
					)
				);

				try {
					$result = $this->request( 'POST', '/psp/creditcard/payments', $params );
				} catch ( Exception $e ) {
					$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );
					wc_add_notice( $e->getMessage(), 'error' );

					return false;
				}

				$order->update_meta_data( '_payex_generate_token', '1' );
				$order->update_meta_data( '_payex_replace_token', '1' );

				// Save payment ID
				$order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );
				$order->save_meta_data();

				// Redirect
				$order->add_order_note( __( 'Customer has been redirected to PayEx.', 'payex-woocommerce-payments' ) );

				return array(
					'result'   => 'success',
					'redirect' => self::get_operation( $result['operations'], 'redirect-verification' )
				);
			} else {
				// Replace token
				delete_post_meta( $order->get_id(), '_payment_tokens' );
				$order->add_payment_token( $token );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);
			}
		}

		// Process payment
		$params = array(
			'payment' => array(
				'operation'            => 'Purchase',
				'intent'               => $this->auto_capture === 'no' ? 'Authorization' : 'AutoCapture',
				'currency'             => $currency,
				'prices'               => array(
					array(
						'type'      => 'CreditCard',
						'amount'    => round( $amount * 100 ),
						'vatAmount' => '0'
					)
				),
				'description'          => sprintf( __( 'Order #%s', 'payex-woocommerce-payments' ), $order->get_order_number() ),
				'payerReference'       => $customer_uuid,
				'generatePaymentToken' => $generate_token,
				'pageStripdown'        => false,
				'userAgent'            => $order->get_customer_user_agent(),
				'language'             => $this->culture,
				'urls'                 => array(
					'completeUrl'       => $this->get_return_url( $order ),
					'cancelUrl'         => $order->get_cancel_order_url_raw(),
					'callbackUrl'       => WC()->api_request_url( __CLASS__ ),
					'termsOfServiceUrl' => $this->terms_url
				),
				'payeeInfo'            => array(
					'payeeId'        => $this->payee_id,
					'payeeReference' => $order_uuid,
				),
				'prefillInfo'          => array(
					'msisdn' => '+' . ltrim( $phone, '+' )
				),
			)
		);

		if ( $token->get_id() ) {
			$params['payment']['paymentToken']         = $token->get_token();
			$params['payment']['generatePaymentToken'] = false;
		}

		try {
			$result = $this->request( 'POST', '/psp/creditcard/payments', $params );
		} catch ( Exception $e ) {
			$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		// Add payment token
		if ( $token->get_id() ) {
			$order->add_payment_token( $token );
		}

		// Generate Token flag
		if ( $generate_token ) {
			$order->update_meta_data( '_payex_generate_token', '1' );
		}

		// Save payment ID
		$order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );
		$order->save_meta_data();

		// Redirect
		$order->add_order_note( __( 'Customer has been redirected to PayEx.', 'payex-woocommerce-payments' ) );

		return array(
			'result'   => 'success',
			'redirect' => self::get_operation( $result['operations'], 'redirect-authorization' )
		);
	}

	/**
	 * Payment confirm action
	 */
	public function payment_confirm() {
		if ( empty( $_GET['key'] ) ) {
			return;
		}

		// Validate Payment Method
		$order_id = wc_get_order_id_by_order_key( $_GET['key'] );
		if ( ! $order_id ) {
			return;
		}

		if ( ! ( $order = wc_get_order( $order_id ) ) ) {
			return;
		}

		if ( ! in_array( px_obj_prop( $order, 'payment_method' ), WC_Payex_Psp::PAYMENT_METHODS ) ) {
			return;
		}

		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			return;
		}

		// Fetch payment info
		try {
			$result = $this->request( 'GET', $payment_id . '?$expand=authorizations,verifications' );
		} catch ( Exception $e ) {
			$this->log( sprintf( '[ERROR] Payment confirm: %s', $e->getMessage() ) );

			return;
		}

		// Check payment state
		switch ( $result['payment']['state'] ) {
			case 'Ready':
				// Replace token for:
				// Change Payment Method
				// Orders with Zero Amount
				if ( $order->get_meta( '_payex_replace_token' ) === '1' ) {
					if ( isset( $result['payment']['verifications']['verificationList'][0] ) &&
					     isset( $result['payment']['verifications']['verificationList'][0]['paymentToken'] ) ) {
						$verification = $result['payment']['verifications']['verificationList'][0];
						$paymentToken = $verification['paymentToken'];
						$cardBrand    = $verification['cardBrand'];
						$maskedPan    = $verification['maskedPan'];
						$expiryDate   = explode( '/', $verification['expiryDate'] );

						// Create Payment Token
						$token = new WC_Payment_Token_Payex();
						$token->set_gateway_id( $this->id );
						$token->set_token( $paymentToken );
						$token->set_last4( substr( $maskedPan, - 4 ) );
						$token->set_expiry_year( $expiryDate[1] );
						$token->set_expiry_month( $expiryDate[0] );
						$token->set_card_type( strtolower( $cardBrand ) );
						$token->set_user_id( get_current_user_id() );
						$token->set_masked_pan( $maskedPan );

						// Save Credit Card
						$token->save();

						// Replace token
						delete_post_meta( $order->get_id(), '_payex_replace_token' );
						delete_post_meta( $order->get_id(), '_payment_tokens' );
						$order->add_payment_token( $token );
					}
				}

				return;
			case 'Failed':
				$order->update_status( 'failed', __( 'Payment failed.', 'payex-woocommerce-payments' ) );

				return;
			case 'Aborted':
				$order->cancel_order( __( 'Payment canceled.', 'payex-woocommerce-payments' ) );

				return;
			default:
				// Payment state is ok
		}
	}

	/**
	 * IPN Callback
	 * @return void
	 */
	public function return_handler() {
		$raw_body = file_get_contents( 'php://input' );

		$this->log( sprintf( 'Incoming Callback: Initialized %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
		$this->log( sprintf( 'Incoming Callback. Post data: %s', var_export( $raw_body, true ) ) );

		// Decode raw body
		$data = @json_decode( $raw_body, true );

		try {
			if ( empty( $data ) ) {
				throw new Exception( 'Error: Empty request received' );
			}

			if ( ! isset( $data['payment'] ) || ! isset( $data['payment']['id'] ) ) {
				throw new Exception( 'Error: Invalid payment ID' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['id'] ) ) {
				throw new Exception( 'Error: Invalid transaction ID' );
			}

			// Create Background Process Task
			$background_process = new WC_Background_Payex_Queue();
			$background_process->push_to_queue( array(
				'payment_method_id' => $this->id,
				'webhook_data'      => $raw_body,
			) );
			$background_process->save();

			$this->log( sprintf( 'Incoming Callback: Task enqueued. Transaction ID: %s', $data['transaction']['number'] ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( 'Incoming Callback: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Check is Capture possible
	 *
	 * @param WC_Order|int $order
	 * @param bool $amount
	 *
	 * @return bool
	 */
	public function can_capture( $order, $amount = false ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$state = $order->get_meta( '_payex_payment_state' );
		if ( empty( $state ) || $state === 'Authorized' ) {
			return true;
		}

		return false;
	}

	/**
	 * Check is Cancel possible
	 *
	 * @param WC_Order|int $order
	 *
	 * @return bool
	 */
	public function can_cancel( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$state = $order->get_meta( '_payex_payment_state' );

		if ( in_array( $state, array(
			'Captured',
			'Cancelled',
			'Refunded'
		) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param \WC_Order $order
	 * @param bool $amount
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function can_refund( $order, $amount = false ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		// Should have payment id
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			return false;
		}

		// Should be captured
		$state = $order->get_meta( '_payex_payment_state' );
		if ( $state !== 'Captured' ) {
			return false;
		}

		// Check refund amount
		try {
			$result = $this->request( 'GET', $payment_id . '/transactions' );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$refunded = 0;
		foreach ( $result['transactions']['transactionList'] as $key => $transaction ) {
			if ( $transaction['type'] === 'Reversal' ) {
				$refunded += ( $transaction['amount'] / 100 );
			}
		}

		$possibleToRefund = $order->get_total() - $refunded;
		if ( $amount > $possibleToRefund ) {
			return false;
		}


		return true;
	}

	/**
	 * Capture
	 *
	 * @param WC_Order|int $order
	 * @param bool $amount
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function capture_payment( $order, $amount = false ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			throw new Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$capture_href = self::get_operation( $result['operations'], 'create-capture' );
		if ( empty( $capture_href ) ) {
			throw new Exception( __( 'Capture unavailable', 'payex-woocommerce-payments' ) );
		}

		// Order Info
		$info = $this->get_order_info( $order );

		// Get Order UUID
		$payeeReference = px_uuid( uniqid( $order_id ) );

		$params = array(
			'transaction' => array(
				'amount'         => (int) round( $amount * 100 ),
				'vatAmount'      => (int) round( $info['vat_amount'] * 100 ),
				'description'    => sprintf( 'Capture for Order #%s', $order_id ),
				'payeeReference' => $payeeReference
			)
		);
		$result = $this->request( 'POST', $capture_href, $params );

		// Save transaction
		$transaction = $result['capture']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				$order->update_meta_data( '_payex_payment_state', 'Captured' );
				$order->update_meta_data( '_payex_transaction_capture', $transaction['id'] );
				$order->save_meta_data();

				$order->add_order_note( __( 'Transaction captured.', 'payex-woocommerce-payments' ) );
				$order->payment_complete( $transaction['number'] );

				break;
			case 'Initialized':
				$order->add_order_note( sprintf( __( 'Transaction capture status: %s.', 'payex-woocommerce-payments' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Capture failed.', 'payex-woocommerce-payments' );
				throw new Exception( $message );
				break;
		}
	}

	/**
	 * Cancel
	 *
	 * @param WC_Order|int $order
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function cancel_payment( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			throw new Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$cancel_href = self::get_operation( $result['operations'], 'create-cancellation' );
		if ( empty( $cancel_href ) ) {
			throw new Exception( __( 'Cancellation unavailable', 'payex-woocommerce-payments' ) );
		}

		// Get Order UUID
		$payeeReference = px_uuid( uniqid( $order_id ) );

		$params = array(
			'transaction' => array(
				'description'    => sprintf( 'Cancellation for Order #%s', $order_id ),
				'payeeReference' => $payeeReference
			),
		);
		$result = $this->request( 'POST', $cancel_href, $params );

		// Save transaction
		$transaction = $result['cancellation']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				$order->update_meta_data( '_transaction_id', $transaction['number'] );
				$order->update_meta_data( '_payex_payment_state', 'Cancelled' );
				$order->update_meta_data( '_payex_transaction_cancel', $transaction['id'] );
				$order->save_meta_data();

				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', __( 'Transaction cancelled.', 'payex-woocommerce-payments' ) );
				} else {
					$order->add_order_note( __( 'Transaction cancelled.', 'payex-woocommerce-payments' ) );
				}
				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction cancellation status: %s.', 'payex-woocommerce-payments' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Cancel failed.', 'payex-woocommerce-payments' );
				throw new Exception( $message );
				break;
		}
	}

	/**
	 * Refund
	 *
	 * @param WC_Order|int $order
	 * @param bool $amount
	 * @param string $reason
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function refund_payment( $order, $amount = false, $reason = '' ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			throw new Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$reversal_href = self::get_operation( $result['operations'], 'create-reversal' );
		if ( empty( $reversal_href ) ) {
			throw new Exception( __( 'Refund unavailable', 'payex-woocommerce-payments' ) );
		}

		// Get Order UUID
		$payeeReference = uniqid( $order_id );

		$params = array(
			'transaction' => array(
				'amount'         => round( $amount * 100 ),
				'vatAmount'      => 0,
				'description'    => sprintf( 'Refund for Order #%s. Reason: %s', $order_id, $reason ),
				'payeeReference' => $payeeReference
			)
		);
		$result = $this->request( 'POST', $reversal_href, $params );

		// Save transaction
		$transaction = $result['reversal']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				//$order->update_meta_data( '_payex_payment_state', 'Refunded' );
				$order->update_meta_data( '_payex_transaction_refund', $transaction['id'] );
				$order->save_meta_data();

				$order->add_order_note( sprintf( __( 'Refunded: %s. Reason: %s', 'woocommerce-gateway-payex-payment' ), wc_price( $amount ), $reason ) );
				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction reversal status: %s.', 'payex-woocommerce-payments' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Refund failed.', 'payex-woocommerce-payments' );
				throw new Exception( $message );
				break;
		}
	}

	/**
	 * Cancel payment on PayEx
	 *
	 * @param int $order_id
	 * @param WC_Order $order
	 */
	public function cancel_pending( $order_id, $order ) {
		$payment_method = px_obj_prop( $order, 'payment_method' );
		if ( $payment_method !== $this->id ) {
			return;
		}

		// Get Payment Id
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			return;
		}

		try {
			// @todo Check is paid
			$result = $this->request( 'GET', $payment_id );

			$abort_href = self::get_operation( $result['operations'], 'update-payment-abort' );
			if ( empty( $abort_href ) ) {
				return;
			}

			$params = [
				'payment' => [
					'operation'   => 'Abort',
					'abortReason' => 'CancelledByConsumer'
				]
			];
			$result = $this->request( 'PATCH', $abort_href, $params );
			if ( is_array( $result ) && $result['payment']['state'] === 'Aborted' ) {
				$order->add_order_note( __( 'Payment aborted', 'payex-woocommerce-payments' ) );
			}
		} catch ( Exception $e ) {
			$this->log( sprintf( 'Pending Cancel. Error: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Add Card ID when Subscription created
	 *
	 * @param $order_id
	 */
	public function add_subscription_card_id( $order_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			/** @var WC_Subscription $subscription */
			$tokens = $subscription->get_payment_tokens();
			if ( count( $tokens ) === 0 ) {
				$tokens = $subscription->get_parent()->get_payment_tokens();
				foreach ( $tokens as $token_id ) {
					$token = new WC_Payment_Token_Payex( $token_id );
					if ( $token->get_gateway_id() !== $this->id ) {
						continue;
					}

					$subscription->add_payment_token( $token );
				}
			}
		}
	}

	/**
	 * Update the card meta for a subscription after using PayEx
	 * to complete a payment to make up for an automatic renewal payment which previously failed.
	 *
	 * @access public
	 *
	 * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		// Delete tokens
		delete_post_meta( $subscription->get_id(), '_payment_tokens' );
	}

	/**
	 * Don't transfer customer meta to resubscribe orders.
	 *
	 * @access public
	 *
	 * @param WC_Order $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	 *
	 * @return void
	 */
	public function delete_resubscribe_meta( $resubscribe_order ) {
		// Delete tokens
		delete_post_meta( $resubscribe_order->get_id(), '_payment_tokens' );
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	 *
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 *
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ $this->id ] = array(
			'payex_meta' => array(
				'token_id' => array(
					'value' => implode( ',', $subscription->get_payment_tokens() ),
					'label' => 'Card Token ID',
				)
			)
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	 *
	 * @param string $payment_method_id The ID of the payment method to validate
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription
	 *
	 * @return array
	 * @throws Exception
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta, $subscription ) {
		if ( $payment_method_id === $this->id ) {
			if ( empty( $payment_meta['payex_meta']['token_id']['value'] ) ) {
				throw new Exception( 'A "Card Token ID" value is required.' );
			}

			$tokens = explode( ',', $payment_meta['payex_meta']['token_id']['value'] );
			foreach ( $tokens as $token_id ) {
				$token = new WC_Payment_Token_Payex( $token_id );
				if ( ! $token->get_id() ) {
					throw new Exception( 'This "Card Token ID" value not found.' );
				}

				if ( $token->get_gateway_id() !== $this->id ) {
					throw new Exception( 'This "Card Token ID" value should related to PayEx.' );
				}

				if ( $token->get_user_id() !== $subscription->get_user_id() ) {
					throw new Exception( 'Access denied for this "Card Token ID" value.' );
				}
			}
		}
	}

	/**
	 * Save payment method meta data for the Subscription
	 *
	 * @param WC_Subscription $subscription
	 * @param string $meta_table
	 * @param string $meta_key
	 * @param string $meta_value
	 */
	public function save_subscription_payment_meta( $subscription, $meta_table, $meta_key, $meta_value ) {
		if ( $subscription->get_payment_method() === $this->id ) {
			if ( $meta_table === 'payex_meta' && $meta_key === 'token_id' ) {
				// Delete tokens
				delete_post_meta( $subscription->get_id(), '_payment_tokens' );

				// Add tokens
				$tokens = explode( ',', $meta_value );
				foreach ( $tokens as $token_id ) {
					$token = new WC_Payment_Token_Payex( $token_id );
					if ( $token->get_id() ) {
						$subscription->add_payment_token( $token );
					}
				}
			}
		}
	}

	/**
	 * When a subscription payment is due.
	 *
	 * @param          $amount_to_charge
	 * @param WC_Order $renewal_order
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		try {
			$user_id    = $renewal_order->get_user_id();
			$email      = $renewal_order->get_billing_email();
			$order_uuid = px_uuid( uniqid( $renewal_order->get_id() ) );

			if ( $user_id > 0 ) {
				$customer_uuid = get_user_meta( $user_id, '_payex_customer_uuid', true );
				if ( empty( $customer_uuid ) ) {
					$customer_uuid = px_uuid( $user_id );
					update_user_meta( $user_id, '_payex_customer_uuid', $customer_uuid );
				}
			} else {
				$customer_uuid = px_uuid( uniqid( $email ) );
			}

			$tokens = $renewal_order->get_payment_tokens();
			foreach ( $tokens as $token_id ) {
				$token = new WC_Payment_Token_Payex( $token_id );
				if ( $token->get_gateway_id() !== $this->id ) {
					continue;
				}

				if ( ! $token->get_id() ) {
					throw new Exception( 'Invalid Token Id' );
				}

				$params = array(
					'payment' => array(
						'operation'      => 'Recur',
						'intent'         => $this->auto_capture === 'no' ? 'Authorization' : 'AutoCapture',
						'paymentToken'   => $token->get_token(),
						'currency'       => $renewal_order->get_currency(),
						'amount'         => round( $amount_to_charge * 100 ),
						'description'    => sprintf( __( 'Order #%s', 'payex-woocommerce-payments' ), $renewal_order->get_order_number() ),
						'payerReference' => $customer_uuid,
						'userAgent'      => $renewal_order->get_customer_user_agent(),
						'language'       => $this->culture,
						'urls'           => array(
							'callbackUrl' => WC()->api_request_url( __CLASS__ )
						),
						'payeeInfo'      => array(
							'payeeId'        => $this->payee_id,
							'payeeReference' => $order_uuid,
						),
					)
				);

				try {
					$result = $this->request( 'POST', '/psp/creditcard/payments', $params );
				} catch ( Exception $e ) {
					$this->log( sprintf( '[WC_Subscriptions]: API Exception: %s', $e->getMessage() ) );
				}

				$payment_id = $result['payment']['id'];

				// Save payment ID
				$renewal_order->update_meta_data( '_payex_payment_id', $payment_id );
				$renewal_order->save_meta_data();

				// Fetch transactions list
				$result       = $this->request( 'GET', $payment_id . '/transactions' );
				$transactions = $result['transactions']['transactionList'];
				$this->transactions->import_transactions( $transactions, $renewal_order->get_id() );

				// Process transactions list
				foreach ( $transactions as $transaction ) {
					$transaction_id = $transaction['number'];

					// Check transaction state
					if ( $transaction['state'] !== 'Completed' ) {
						$reason = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Transaction failed.', 'payex-woocommerce-payments' );
						$this->log( sprintf( '[WC_Subscriptions]: Warning: Transaction %s state %s. Reason: %s', $transaction['number'], $transaction['state'], $reason ) );
						continue;
					}

					// Extract transaction from list
					$transaction = px_filter( $transactions, array( 'number' => $transaction_id ) );
					if ( is_array( $transaction ) ) {
						// Process transaction
						try {
							$this->process_transaction( $transaction, $renewal_order );
						} catch ( Exception $e ) {
							$this->log( sprintf( '[WC_Subscriptions]: Warning: %s', $e->getMessage() ) );
							continue;
						}
					}
				}

				// We are wait for Authorization transaction
				$transactions = $this->transactions->select( array(
					'order_id' => $renewal_order->get_id(),
					'type'     => 'Authorization'
				) );

				if ( $transaction = px_filter( $transactions, array( 'state' => 'Failed' ) ) ) {
					$this->log( sprintf( '[WC_Subscriptions]: Failed to perform payment: %s', $transaction['id'] ) );
					throw new Exception( __( 'Failed to perform payment', 'payex-woocommerce-payments' ), 'error' );
				}
			}
		} catch ( Exception $e ) {
			$renewal_order->update_status( 'failed' );
			$renewal_order->add_order_note( sprintf( __( 'Failed to charge "%s". %s.', 'woocommerce' ), wc_price( $amount_to_charge ), $e->getMessage() ) );
		}
	}

	/**
	 * Render the payment method used for a subscription in the "My Subscriptions" table
	 *
	 * @param string $payment_method_to_display the default payment method text to display
	 * @param WC_Subscription $subscription the subscription details
	 *
	 * @return string the subscription payment method
	 */
	public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription ) {
		if ( $this->id !== $subscription->get_payment_method() || ! $subscription->get_user_id() ) {
			return $payment_method_to_display;
		}

		$tokens = $subscription->get_payment_tokens();
		foreach ( $tokens as $token_id ) {
			$token = new WC_Payment_Token_Payex( $token_id );
			if ( $token->get_gateway_id() !== $this->id ) {
				continue;
			}

			return sprintf( __( 'Via %s card ending in %s/%s', 'payex-woocommerce-payments' ),
				$token->get_masked_pan(),
				$token->get_expiry_month(),
				$token->get_expiry_year()
			);
		}

		return $payment_method_to_display;
	}
}

// Register Gateway
WC_Payex_Psp::register_gateway( 'WC_Gateway_Payex_Cc' );
