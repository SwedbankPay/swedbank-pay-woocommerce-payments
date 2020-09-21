<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Payments\WooCommerce\WC_Background_Swedbank_Pay_Queue;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Core\Core;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\Log\LogLevel;

class WC_Gateway_Swedbank_Pay_Cc extends WC_Payment_Gateway {
	/**
	 * @var WC_Adapter
	 */
	public $adapter;

	/**
	 * @var Core
	 */
	public $core;

	/**
	 * @var WC_Swedbank_Pay_Transactions
	 */
	public $transactions;

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
	 * Subsite
	 * @var string
	 */
	public $subsite = '';

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
     * Url of Merchant Logo.
     *
	 * @var string
	 */
	public $logo_url = '';

	/**
	 * Reject Credit Cards
	 * @var string
	 */
	public $reject_credit_cards = 'no';

	/**
	 * Reject Debit Cards
	 * @var string
	 */
	public $reject_debit_cards = 'no';

	/**
	 * Reject Consumer Cards
	 * @var string
	 */
	public $reject_consumer_cards = 'no';

	/**
	 * Reject Corporate Cards
	 * @var string
	 */
	public $reject_corporate_cards = 'no';

	public $is_new_credit_card;

	public $is_change_credit_card;

	/**
     * Payment Token Class.
     *
	 * @var string
	 */
	public $payment_token_class = 'WC_Payment_Token_Swedbank_Pay';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_psp_cc';
		$this->has_fields   = true;
		$this->method_title = __( 'Credit Card', 'swedbank-pay-woocommerce-payments' );
		$this->icon         = apply_filters(
			'wc_swedbank_pay_cc_icon',
			plugins_url( '/assets/images/creditcards.png', dirname( __FILE__ ) )
		);
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
		$this->subsite        = isset( $this->settings['subsite'] ) ? $this->settings['subsite'] : $this->subsite;
		$this->testmode       = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->debug          = isset( $this->settings['debug'] ) ? $this->settings['debug'] : $this->debug;
		$this->culture        = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->auto_capture   = isset( $this->settings['auto_capture'] ) ? $this->settings['auto_capture'] : $this->auto_capture;
		$this->save_cc        = isset( $this->settings['save_cc'] ) ? $this->settings['save_cc'] : $this->save_cc;
		$this->terms_url      = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->logo_url       = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;

		// Reject Cards
		$this->reject_credit_cards    = isset( $this->settings['reject_credit_cards'] ) ? $this->settings['reject_credit_cards'] : $this->reject_credit_cards;
		$this->reject_debit_cards     = isset( $this->settings['reject_debit_cards'] ) ? $this->settings['reject_debit_cards'] : $this->reject_debit_cards;
		$this->reject_consumer_cards  = isset( $this->settings['reject_consumer_cards'] ) ? $this->settings['reject_consumer_cards'] : $this->reject_consumer_cards;
		$this->reject_corporate_cards = isset( $this->settings['reject_corporate_cards'] ) ? $this->settings['reject_corporate_cards'] : $this->reject_corporate_cards;

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions and filters
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_before_thankyou', array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_order_has_status', __CLASS__ . '::order_has_status', 10, 3 );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'return_handler' ) );

		// Pending Cancel
		add_action(
			'woocommerce_order_status_pending_to_cancelled',
			array(
				$this,
				'cancel_pending',
			),
			10,
			2
		);

		// Action for "Add Payment Method"
		add_action( 'wp_ajax_swedbank_card_store', array( $this, 'swedbank_card_store' ) );
		add_action( 'wp_ajax_nopriv_swedbank_card_store', array( $this, 'swedbank_card_store' ) );

		// Subscriptions
		add_action( 'woocommerce_payment_complete', array( $this, 'add_subscription_card_id' ), 10, 1 );

		add_action(
			'woocommerce_subscription_failing_payment_method_updated_' . $this->id,
			array(
				$this,
				'update_failing_payment_method',
			),
			10,
			2
		);

		add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );

		// Allow store managers to manually set card id as the payment method on a subscription
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );

		add_filter(
			'woocommerce_subscription_validate_payment_meta',
			array(
				$this,
				'validate_subscription_payment_meta',
			),
			10,
			3
		);

		add_action( 'wcs_save_other_payment_meta', array( $this, 'save_subscription_payment_meta' ), 10, 4 );

		add_action(
			'woocommerce_scheduled_subscription_payment_' . $this->id,
			array(
				$this,
				'scheduled_subscription_payment',
			),
			10,
			2
		);

		// Display the credit card used for a subscription in the "My Subscriptions" table
		add_filter(
			'woocommerce_my_subscriptions_payment_method',
			array(
				$this,
				'maybe_render_subscription_payment_method',
			),
			10,
			2
		);

		// Lock "Save card" if needs
		add_filter(
			'woocommerce_payment_gateway_save_new_payment_method_option_html',
			array(
				$this,
				'save_new_payment_method_option_html',
			),
			10,
			2
        );

		$this->adapter = new WC_Adapter( $this );
		$this->core    = new Core( $this->adapter );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'swedbank-pay-woocommerce-payments' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Title', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Credit Card', 'swedbank-pay-woocommerce-payments' ),
			),
			'description'            => array(
				'title'       => __( 'Description', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Credit Card', 'swedbank-pay-woocommerce-payments' ),
			),
			'payee_id'               => array(
				'title'       => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->payee_id,
				'custom_attributes' => array(
					'required' => 'required'
				),
				'sanitize_callback' => function( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( __( '"Payee Id" field can\'t be empty.', 'swedbank-pay-woocommerce-payments' ) );
					}

					return $value;
				},
			),
			'merchant_token'         => array(
				'title'       => __( 'Merchant Token', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->merchant_token,
				'custom_attributes' => array(
					'required' => 'required'
				),
                'sanitize_callback' => function( $value ) {
	                if ( empty( $value ) ) {
		                throw new Exception( __( '"Merchant Token" field can\'t be empty.', 'swedbank-pay-woocommerce-payments' ) );
	                }

				    return $value;
                },
			),
			'subsite'                => array(
				'title'       => __( 'Subsite', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Subsite', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->subsite,
			),
			'testmode'               => array(
				'title'   => __( 'Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->testmode,
			),
			'debug'                  => array(
				'title'   => __( 'Debug', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->debug,
			),
			'culture'                => array(
				'title'       => __( 'Language', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				),
				'description' => __(
					'Language of pages displayed by Swedbank Pay during payment.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => $this->culture,
			),
			'auto_capture'           => array(
				'title'   => __( 'Auto Capture', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Auto Capture', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->auto_capture,
			),
			'save_cc'                => array(
				'title'   => __( 'Save CC', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Save CC feature', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->save_cc,
			),
			'terms_url'              => array(
				'title'       => __( 'Terms & Conditions Url', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url', 'swedbank-pay-woocommerce-payments' ),
				'default'     => get_site_url(),
			),
			'logo_url'              => array(
				'title'       => __( 'Logo Url', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'The URL that will be used for showing the customer logo. Must be a picture with maximum 50px height and 400px width. Require https.', 'swedbank-pay-woocommerce-payments' ),
				'desc_tip'    => true,
				'default'     => '',
				'sanitize_callback' => function( $value ) {
					if ( ! empty( $value ) ) {
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							throw new Exception( __( 'Logo Url is invalid.', 'swedbank-pay-woocommerce-payments' ) );
						} elseif ( 'https' !== parse_url( $value, PHP_URL_SCHEME ) ) {
							throw new Exception( __( 'Logo Url should use https scheme.', 'swedbank-pay-woocommerce-payments' ) );
						}
					}

					return $value;
				},
			),
			'reject_credit_cards'    => array(
				'title'   => __( 'Reject Credit Cards', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Reject Credit Cards', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->reject_credit_cards,
			),
			'reject_debit_cards'     => array(
				'title'   => __( 'Reject Debit Cards', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Reject Debit Cards', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->reject_debit_cards,
			),
			'reject_consumer_cards'  => array(
				'title'   => __( 'Reject Consumer Cards', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Reject Consumer Cards', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->reject_consumer_cards,
			),
			'reject_corporate_cards' => array(
				'title'   => __( 'Reject Corporate Cards', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Reject Corporate Cards', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->reject_corporate_cards,
			),
		);
	}

	/**
	 * Output the gateway settings screen.
     *
	 * @return void
	 */
	public function admin_options() {
		$this->display_errors();

		parent::admin_options();
	}

	/**
	 * If There are no payment fields show the description if set.
	 */
	public function payment_fields() {
		parent::payment_fields();

		if ( 'yes' === $this->save_cc ) {
			if ( ! is_add_payment_method_page() ) {
				$this->tokenization_script();
				$this->saved_payment_methods();
				$this->save_payment_method_checkbox();
			}
		}
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

		// Create a virtual order
		$order = wc_create_order(
			array(
				'customer_id'    => $user_id,
				'created_via'    => $this->id,
				'payment_method' => $this->id,
			)
		);
		$order->calculate_totals();

		try {
			$this->is_new_credit_card = true;
			$result                   = $this->core->initiateVerifyCreditCardPayment( $order->get_id() );
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			WC()->session->__unset( 'verification_payment_id' );

			return array(
				'result'   => 'failure',
				'redirect' => wc_get_account_endpoint_url( 'payment-methods' ),
			);
		}

		WC()->session->set( 'verification_payment_id', $result['payment']['id'] );

		// Redirect
		wp_redirect( $result->getOperationByRel( 'redirect-verification' ) );
		exit();
	}


	/**
	 * Add Payment Method: Callback for Swedbank Pay Card
	 * @return void
	 */
	public function swedbank_card_store() {
		try {
			$payment_id = WC()->session->get( 'verification_payment_id' );

			if ( ! $payment_id ) {
				return;
			}

			$list = $this->core->fetchVerificationList( $payment_id );
			if ( isset( $list[0] ) &&
				( ! empty( $list[0]['paymentToken'] ) || ! empty( $list[0]['recurrenceToken'] ) )
			) {
				$verification     = $list[0];
				$payment_token    = isset( $verification['paymentToken'] ) ? $verification['paymentToken'] : '';
				$recurrence_token = isset( $verification['recurrenceToken'] ) ? $verification['recurrenceToken'] : '';
				$card_brand       = $verification['cardBrand'];
				$masked_pan       = $verification['maskedPan'];
				$expiry_date      = explode( '/', $verification['expiryDate'] );

				// Create Payment Token
				$token = new WC_Payment_Token_Swedbank_Pay();
				$token->set_gateway_id( $this->id );
				$token->set_token( $payment_token );
				$token->set_recurrence_token( $recurrence_token );
				$token->set_last4( substr( $masked_pan, - 4 ) );
				$token->set_expiry_year( $expiry_date[1] );
				$token->set_expiry_month( $expiry_date[0] );
				$token->set_card_type( strtolower( $card_brand ) );
				$token->set_user_id( get_current_user_id() );
				$token->set_masked_pan( $masked_pan );

				// Save Credit Card
				$token->save();
				if ( ! $token->get_id() ) {
					throw new Exception( __( 'There was a problem adding the card.', 'swedbank-pay-woocommerce-payments' ) );
				}

				WC()->session->__unset( 'verification_payment_id' );

				wc_add_notice( __( 'Payment method successfully added.', 'swedbank-pay-woocommerce-payments' ) );
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
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			return;
		}

		// Check tokens that should be saved or replaced
		if ( '1' === $order->get_meta( '_payex_replace_token' ) ) {
			try {
				$result = $this->core->fetchPaymentInfo( $payment_id, 'authorizations,verifications' );

				// Check payment state
				switch ( $result['payment']['state'] ) {
					case 'Ready':
						// Replace token for:
						// Change Payment Method
						// Orders with Zero Amount
						// Prepare sources where can be tokens
						$sources = array();
						if ( isset( $result['payment']['verifications'] ) ) {
							$sources = array_merge( $sources, $result['payment']['verifications']['verificationList'] );
						}

						if ( isset( $result['payment']['authorizations'] ) ) {
							$sources = array_merge($sources, $result['payment']['authorizations']['authorizationList']);
						}

						foreach ( $sources as $source ) {
							$payment_token    = $source['paymentToken'];
							$recurrence_token = $source['recurrenceToken'];
							$card_brand       = $source['cardBrand'];
							$masked_pan       = $source['maskedPan'];
							$expiry_date      = explode( '/', $source['expiryDate'] );

							// Create Payment Token
							$token = new WC_Payment_Token_Swedbank_Pay();
							$token->set_gateway_id( $this->id );
							$token->set_token( $payment_token );
							$token->set_recurrence_token( $recurrence_token );
							$token->set_last4( substr( $masked_pan, - 4 ) );
							$token->set_expiry_year( $expiry_date[1] );
							$token->set_expiry_month( $expiry_date[0] );
							$token->set_card_type( strtolower( $card_brand ) );
							$token->set_user_id( get_current_user_id() );
							$token->set_masked_pan( $masked_pan );

							// Save Credit Card
							$token->save();

							// Replace token
							delete_post_meta( $order->get_id(), '_payex_replace_token' );
							delete_post_meta( $order->get_id(), '_payment_tokens' );
							$order->add_payment_token( $token );

							wc_add_notice( __( 'Payment method was updated.', 'swedbank-pay-woocommerce-payments' ) );

							break;
						}
					default:
						// no default
				}
			} catch ( Exception $e ) {
				$this->adapter->log(
					LogLevel::WARNING, sprintf( '%s::%s %s', __CLASS__, __METHOD__, $e->getMessage() )
				);
			}
		}

		// Wait for order status update by a callback
		$status = $order->get_status();
		$times = 0;
		$updated = false;
		while ( true ) {
			sleep( 1 );
			clean_post_cache( $order->get_id() );
			$order = wc_get_order( $order->get_id() );

			// Check if status has been updated
			if ( ! $order->has_status( $status ) ) {
				$updated = true;
				break;
			}

			// by timeout
			$times ++;
			if ( $times > 60 ) {
				break;
			}
		}

		if ( ! $updated ) {
			// Update an order status
			try {
				$this->core->fetchTransactionsAndUpdateOrder( $order_id );
			} catch ( Exception $e ) {
				$this->adapter->log(
					LogLevel::WARNING, sprintf( 'fetchTransactionsAndUpdateOrder: %s', $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Workaround for "order received page".
	 * We need for actual status because order status
	 * can be updated in "woocommerce_before_thankyou" hook.
	 *
	 * @param bool $result
	 * @param string $status
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public static function order_has_status( $result, $order, $status ) {
		if ( 'payex_psp_cc' !== $order->get_payment_method() ) {
			return $result;
		}

		if ( is_order_received_page() ) {
			// Reload an order and check status again
			$order = wc_get_order( $order );

			return $order->get_status() === $status;
		}

		return $result;
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
		$generate_token  = ( 'yes' === $this->save_cc && $maybe_save_card );

		// Try to load saved token
		$token = new WC_Payment_Token_Swedbank_Pay();
		if ( absint( $token_id ) > 0 ) {
			$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
			if ( ! $token->get_id() ) {
				wc_add_notice( __( 'Failed to load token.', 'swedbank-pay-woocommerce-payments' ), 'error' );

				return false;
			}

			// Check access
			if ( $token->get_user_id() !== $order->get_user_id() ) {
				wc_add_notice( __( 'Access denied.', 'swedbank-pay-woocommerce-payments' ), 'error' );
			}

			$generate_token = false;
		}

		// Change a payment method
		// or process orders that have zero amount
		if ( (float) $order->get_total() <= 0.01 || self::wcs_is_payment_change() ) {
			if ( absint( $token_id ) > 0 ) {
				// Replace the token to another saved before
				$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
				if ( ! $token->get_id() ) {
					throw new Exception( 'Failed to load token.' );
				}

				// Check access
				if ( $token->get_user_id() !== $order->get_user_id() ) {
					throw new Exception( 'Access denied.' );
				}

				// Replace token
				delete_post_meta( $order->get_id(), '_payment_tokens' );
				$order->add_payment_token( $token );

				if ( self::wcs_is_payment_change() ) {
					wc_add_notice( __( 'Payment method was updated.', 'swedbank-pay-woocommerce-payments' ) );
				}

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			} else {
				// Initiate new payment card
				$this->is_change_credit_card = true;
				$result                      = $this->core->initiateVerifyCreditCardPayment( $order->get_id() );

				$order->update_meta_data( '_payex_generate_token', '1' );
				$order->update_meta_data( '_payex_replace_token', '1' );

				// Save payment ID
				$order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );
				$order->save();

				// Redirect
				$order->add_order_note(
					__(
						'Customer has been redirected to Swedbank Pay.',
						'swedbank-pay-woocommerce-payments'
					)
				);

				return array(
					'result'   => 'success',
					'redirect' => $result->getOperationByRel( 'redirect-verification' ),
				);
			}
		}

		// Process payment
		try {
			$payment_token = null;
			if ( $token->get_id() ) {
				$generate_token = false;
				$payment_token  = $token->get_token();
			}

			$result = $this->core->initiateCreditCardPayment( $order_id, $generate_token, $payment_token );
		} catch ( Exception $e ) {
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
		$order->save();

		// Redirect
		$order->add_order_note( __( 'Customer has been redirected to Swedbank Pay.', 'swedbank-pay-woocommerce-payments' ) );

		return array(
			'result'   => 'success',
			'redirect' => $result->getOperationByRel( 'redirect-authorization' ),
		);
	}

	/**
	 * Payment confirm action
	 */
	public function payment_confirm() {
		//
	}

	/**
	 * IPN Callback
	 * @return void
	 */
	public function return_handler() {
		$raw_body = file_get_contents( 'php://input' );

		$this->adapter->log(
			LogLevel::INFO,
			sprintf( 'Incoming Callback: Initialized %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] )
		);
		$this->adapter->log(
			LogLevel::INFO,
			sprintf( 'Incoming Callback. Post data: %s', var_export( $raw_body, true ) )
		);

		// Decode raw body
		$data = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Invalid webhook data' );
		}

		try {
			// Verify the order key
			$order_id  = absint(  wc_clean( $_GET['order_id'] ) ); // WPCS: input var ok, CSRF ok.
			$order_key = empty( $_GET['key'] ) ? '' : wc_clean( wp_unslash( $_GET['key'] ) ); // WPCS: input var ok, CSRF ok.

			if ( empty( $order_id ) || empty( $order_id ) ) {
				throw new Exception( 'An order ID or order key wasn\'t provided' );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
				throw new Exception( 'A provided order key has been invalid.' );
			}

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
			$background_process = new WC_Background_Swedbank_Pay_Queue();
			$background_process->push_to_queue(
				array(
					'payment_method_id' => $this->id,
					'webhook_data'      => $raw_body,
				)
			);
			$background_process->save();

			$this->adapter->log(
				LogLevel::INFO,
				sprintf( 'Incoming Callback: Task enqueued. Transaction ID: %s', $data['transaction']['number'] )
			);
		} catch ( Exception $e ) {
			$this->adapter->log( LogLevel::INFO, sprintf( 'Incoming Callback: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			$amount = $order->get_total();
		}

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Checkout\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);


			$this->core->refund( $order->get_id(), $amount );

			return true;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund', $e->getMessage() );
		}
	}

	/**
	 * Capture
	 *
	 * @param WC_Order|int $order
	 * @param mixed $amount
	 * @param mixed $vat_amount
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function capture_payment( $order, $amount = false, $vat_amount = 0 ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);

			$this->core->capture( $order->get_id(), $amount, $vat_amount );
		} catch ( \SwedbankPay\Core\Exception $e ) {
			throw new Exception( $e->getMessage() );
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

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);

			$this->core->cancel( $order->get_id() );
		} catch ( \SwedbankPay\Core\Exception $e ) {
			throw new Exception( $e->getMessage() );
		}
	}

	/**
	 * Cancel payment on Swedbank Pay
	 *
	 * @param int $order_id
	 * @param WC_Order $order
	 */
	public function cancel_pending( $order_id, $order ) {
		$payment_method = $order->get_payment_method();
		if ( $payment_method !== $this->id ) {
			return;
		}

		try {
			$this->core->abort( $order_id );
		} catch ( \Exception $e ) {
			$this->adapter->log( LogLevel::INFO, sprintf( 'Pending Cancel. Error: %s', $e->getMessage() ) );
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
					$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
					if ( $token->get_gateway_id() !== $this->id ) {
						continue;
					}

					$subscription->add_payment_token( $token );
				}
			}
		}
	}

	/**
	 * Update the card meta for a subscription after using Swedbank Pay
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
				),
			),
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
				$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
				if ( ! $token->get_id() ) {
					throw new Exception( 'This "Card Token ID" value not found.' );
				}

				if ( $token->get_gateway_id() !== $this->id ) {
					throw new Exception( 'This "Card Token ID" value should related to Swedbank Pay.' );
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
			if ( 'payex_meta' === $meta_table && 'token_id' === $meta_key ) {
				// Delete tokens
				delete_post_meta( $subscription->get_id(), '_payment_tokens' );

				// Add tokens
				$tokens = explode( ',', $meta_value );
				foreach ( $tokens as $token_id ) {
					$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
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
			$tokens = $renewal_order->get_payment_tokens();

			foreach ( $tokens as $token_id ) {
				$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
				if ( $token->get_gateway_id() !== $this->id ) {
					continue;
				}

				if ( ! $token->get_id() ) {
					throw new Exception( 'Invalid Token Id' );
				}

				$payment_token    = $token->get_token();
				$recurrence_token = $token->get_recurrence_token();

				$result     = $this->core->initiateCreditCardRecur(
					$renewal_order->get_id(),
					$payment_token,
					$recurrence_token
				);
				$payment_id = $result['payment']['id'];

				// Save payment ID
				$renewal_order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );
				$renewal_order->save();

				// Fetch transactions list
				$transactions = $this->core->fetchTransactionsList( $payment_id );
				$this->core->saveTransactions( $renewal_order->get_id(), $transactions );

				// Process transactions list
				foreach ( $transactions as $transaction ) {
					// Process transaction
					try {
						// Disable status change hook
						remove_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);
						remove_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Checkout\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);

						$this->core->processTransaction( $renewal_order->get_id(), $transaction );

						// Enable status change hook
						add_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);
						add_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Checkout\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);
					} catch ( \Exception $e ) {
						$this->adapter->log(
							LogLevel::INFO,
							sprintf( '[WC_Subscriptions]: Warning: %s', $e->getMessage() )
						);

						// Enable status change hook
						add_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);
						add_action(
							'woocommerce_order_status_changed',
							'\SwedbankPay\Checkout\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
							10
						);

						continue;
					}
				}

				break;
			}
		} catch ( \Exception $e ) {
			$renewal_order->update_status( 'failed' );
			$renewal_order->add_order_note(
				sprintf(
					/* translators: 1: amount 2: error */                    __( 'Failed to charge "%1$s". %2$s.', 'woocommerce' ),
					wc_price( $amount_to_charge ),
					$e->getMessage()
				)
			);
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
			$token = new WC_Payment_Token_Swedbank_Pay( $token_id );
			if ( $token->get_gateway_id() !== $this->id ) {
				continue;
			}

			return sprintf(
				/* translators: 1: pan 2: month 3: year */                __( 'Via %1$s card ending in %2$s/%3$s', 'swedbank-pay-woocommerce-payments' ),
				$token->get_masked_pan(),
				$token->get_expiry_month(),
				$token->get_expiry_year()
			);
		}

		return $payment_method_to_display;
	}

	/**
	 * Modify "Save to account" to lock that if needs.
	 *
	 * @param string $html
	 * @param WC_Payment_Gateway $gateway
	 *
	 * @return string
	 */
	public function save_new_payment_method_option_html( $html, $gateway ) {
		if ( $gateway->id !== $this->id ) {
			return $html;
		}

		// Lock "Save to Account" for Recurring Payments / Payment Change
		if ( self::wcs_cart_have_subscription() || self::wcs_is_payment_change() ) {
			// Load XML
			libxml_use_internal_errors( true );
			$doc = new \DOMDocument();
			$status = @$doc->loadXML( $html );
			if ( false !== $status ) {
				$item = $doc->getElementsByTagName('input')->item( 0 );
				$item->setAttribute('checked','checked' );
				$item->setAttribute('disabled','disabled' );

				$html = $doc->saveHTML($doc->documentElement);
			}
		}

		return $html;
	}

	/**
	 * WC Subscriptions: Is Payment Change.
	 *
	 * @return bool
	 */
	private function wcs_is_payment_change() {
		return class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false )
			   && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
	}

	/**
	 * Check is Cart have Subscription Products.
	 *
	 * @return bool
	 */
	private function wcs_cart_have_subscription() {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return false;
		}

		// Check is Recurring Payment
		$cart = WC()->cart->get_cart();
		foreach ( $cart as $key => $item ) {
			if ( is_object( $item['data'] ) && WC_Subscriptions_Product::is_subscription( $item['data'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get Custom Logo
	 *
	 * @return string
	 */
	public function get_custom_logo() {
		$logo_url = '';
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$image = wp_get_attachment_image_src( $custom_logo_id, 'thumbnail', false );
			list( $logo_url, $width, $height ) = $image;
		}

		return $logo_url;
	}
}
