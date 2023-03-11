<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Refund;
use SwedbankPay\Payments\WooCommerce\WC_Background_Swedbank_Pay_Queue;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Payments\WooCommerce\WC_Payment_Token_Swedbank_Pay;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Instant_Capture;
use SwedbankPay\Core\Core;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\Log\LogLevel;

class WC_Gateway_Swedbank_Pay_Cc extends WC_Payment_Gateway {
	const METHOD_DIRECT = 'direct';
	const METHOD_REDIRECT = 'redirect';
	const METHOD_SEAMLESS = 'seamless';

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
	 * Access Token
	 * @var string
	 */
	public $access_token = '';

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
	 * IP Checking
	 * @var string
	 */
	public $ip_check = 'yes';

	/**
	 * Locale
	 * @var string
	 */
	public $culture = 'en-US';

	/**
	 * Checkout Method
	 * @var string
	 */
	public $method = self::METHOD_REDIRECT;

	/**
	 * Auto Capture
	 * @var string
	 */
	public $auto_capture = 'no';

	/**
	 * Instant Capture
	 * @var array
	 */
	public $instant_capture = array();

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
	 * Send payer info
	 * @var string
	 */
	public $use_payer_info = 'yes';

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
	public $payment_token_class = WC_Payment_Token_Swedbank_Pay::class;

	/**
	 * Swedbank Pay ip addresses
	 * @var array
	 */
	public $gateway_ip_addresses = [ '91.132.170.1' ];

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

		// Update access_token if merchant_token is exists
		if ( empty( $this->settings['access_token'] ) && ! empty( $this->settings['merchant_token'] ) ) {
			$this->settings['access_token'] = $this->settings['merchant_token'];
			$this->update_option( 'access_token', $this->settings['access_token'] );
		}

		// Define user set variables
		$this->enabled         = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
		$this->title           = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->description     = isset( $this->settings['description'] ) ? $this->settings['description'] : '';
		$this->access_token    = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token;
		$this->payee_id        = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;
		$this->subsite         = isset( $this->settings['subsite'] ) ? $this->settings['subsite'] : $this->subsite;
		$this->testmode        = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->debug           = isset( $this->settings['debug'] ) ? $this->settings['debug'] : $this->debug;
		$this->ip_check        = isset( $this->settings['ip_check'] ) ? $this->settings['ip_check'] : $this->ip_check;
		$this->culture         = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->method          = isset( $this->settings['method'] ) ? $this->settings['method'] : $this->method;
		$this->auto_capture    = isset( $this->settings['auto_capture'] ) ? $this->settings['auto_capture'] : $this->auto_capture;
		$this->instant_capture = isset( $this->settings['instant_capture'] ) ? $this->settings['instant_capture'] : $this->instant_capture;
		$this->save_cc         = isset( $this->settings['save_cc'] ) ? $this->settings['save_cc'] : $this->save_cc;
		$this->terms_url       = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->logo_url        = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;
		$this->use_payer_info  = isset( $this->settings['use_payer_info'] ) ? $this->settings['use_payer_info'] : $this->use_payer_info;

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

		// JS Scrips
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		// Actions and filters
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'wc_get_template', array( $this, 'override_template' ), 5, 20 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'thankyou_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'thankyou_scripts' ) );

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

		// Action for "Check payment"
		add_action( 'wp_ajax_swedbank_pay_check_payment', array( $this, 'ajax_check_payment' ) );
		add_action( 'wp_ajax_nopriv_swedbank_pay_check_payment', array( $this, 'ajax_check_payment' ) );

		// Action for "Add Payment Method"
		add_action( 'wp_ajax_swedbank_card_store', array( $this, 'swedbank_card_store' ) );
		add_action( 'wp_ajax_nopriv_swedbank_card_store', array( $this, 'swedbank_card_store' ) );

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
			'access_token'         => array(
				'title'       => __( 'Access Token', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Access Token', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->access_token,
				'custom_attributes' => array(
					'required' => 'required'
				),
				'sanitize_callback' => function( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( __( '"Access Token" field can\'t be empty.', 'swedbank-pay-woocommerce-payments' ) );
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
			'ip_check'               => array(
				'title'   => __( 'Enable IP checking of incoming callbacks', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable IP checking of incoming callbacks', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->ip_check,
			),
			'culture'                => array(
				'title'       => __( 'Language', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					'da-DK' => 'Danish',
					'de-DE' => 'German',
					'ee-EE' => 'Estonian',
					'en-US' => 'English',
					'es-ES' => 'Spanish',
					'fi-FI' => 'Finnish',
					'fr-FR' => 'French',
					'lt-LT' => 'Lithuanian',
					'lv-LV' => 'Latvian',
					'nb-NO' => 'Norway',
					'ru-RU' => 'Russian',
					'sv-SE' => 'Swedish',
				),
				'description' => __(
					'Language of pages displayed by Swedbank Pay during payment.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => $this->culture,
			),
			'method'         => array(
				'title'       => __( 'Checkout Method', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					self::METHOD_REDIRECT   => __( 'Redirect', 'swedbank-pay-woocommerce-payments' ),
					self::METHOD_SEAMLESS   => __( 'Seamless View', 'swedbank-pay-woocommerce-payments' ),
				),
				'description' => __( 'Checkout Method', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->method,
			),
			'auto_capture'           => array(
				'title'   => __( 'Auto Capture Intent', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Auto Capture Intent', 'swedbank-pay-woocommerce-payments' ),
				'description' => __( 'A one phase option that enable capture of funds automatically after authorization.', 'swedbank-pay-woocommerce-payments' ),
				'desc_tip'    => true,
				'default' => $this->auto_capture,
			),
			'instant_capture'         => array(
				'title'          => __( 'Instant Capture', 'swedbank-pay-woocommerce-payments' ),
				'description'    => __( 'Capture payment automatically depends on the product type. It\'s working when Auto Capture Intent is off.', 'swedbank-pay-woocommerce-payments' ),
				'type'           => 'multiselect',
				'css'            => 'height: 150px',
				'options'        => array(
					WC_Swedbank_Pay_Instant_Capture::CAPTURE_VIRTUAL   => __( 'Virtual products', 'swedbank-pay-woocommerce-payments' ),
					WC_Swedbank_Pay_Instant_Capture::CAPTURE_PHYSICAL  => __( 'Physical  products', 'swedbank-pay-woocommerce-payments' ),
					WC_Swedbank_Pay_Instant_Capture::CAPTURE_RECURRING => __( 'Recurring (subscription) products', 'swedbank-pay-woocommerce-payments' ),
					WC_Swedbank_Pay_Instant_Capture::CAPTURE_FEE       => __( 'Fees', 'swedbank-pay-woocommerce-payments' ),
				),
				'select_buttons' => true,
				'default'     => $this->instant_capture
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
			'use_payer_info'        => array(
				'title'   => __( 'Send payer information', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send billing/delivery addresses of payer to Swedbank Pay', 'swedbank-pay-woocommerce-paymentst' ),
				'default' => $this->use_payer_info
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
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
	public function process_admin_options() {
		$result = parent::process_admin_options();

		// Reload settings
		$this->init_settings();
		$this->access_token   = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token;
		$this->payee_id       = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;

		// Test API Credentials
		try {
			switch ( $this->id ) {
				case 'payex_psp_cc':
					new SwedbankPay\Api\Service\Creditcard\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
				case 'payex_psp_invoice':
					new SwedbankPay\Api\Service\Invoice\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
				case 'payex_psp_mobilepay':
					new SwedbankPay\Api\Service\MobilePay\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
				case 'payex_psp_swish':
					new SwedbankPay\Api\Service\Swish\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
				case 'payex_psp_trustly':
					new SwedbankPay\Api\Service\Trustly\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
				case 'payex_psp_vipps':
					new SwedbankPay\Api\Service\Vipps\Request\Test(
						$this->access_token,
						$this->payee_id,
						$this->testmode === 'yes'
					);

					break;
			}
		} catch (\Exception $e) {
			WC_Admin_Settings::add_error( $e->getMessage() );
		}

		return $result;
	}

	/**
	 * payment_scripts function.
	 *
	 * Outputs scripts used for payment
	 *
	 * @return void
	 */
	public function payment_scripts() {
		if ( ! is_checkout() || 'no' === $this->enabled || self::METHOD_SEAMLESS !== $this->method ) {
			return;
		}

		$this->enqueue_seamless();

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'wc-sb-cc',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/seamless-cc' . $suffix . '.js',
			array(
				'wc-sb-seamless',
			),
			false,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'wc-sb-cc',
			'WC_Gateway_Swedbank_Pay_Cc',
			array(
				'culture' => $this->culture
			)
		);

		wp_enqueue_script( 'wc-sb-cc' );
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

			$verifications = $this->core->fetchVerificationList( $payment_id );
			foreach ($verifications as $verification) {
				// Skip verification which failed transaction state
				if ($verification->getTransaction()->isFailed()) {
					continue;
				}

				if ($verification->getPaymentToken() || $verification->getRecurrenceToken()) {
					// Add payment token
					$expiry_date = explode( '/', $verification['expiryDate'] );

					// Create Payment Token
					$token = new WC_Payment_Token_Swedbank_Pay();
					$token->set_gateway_id( $this->id );
					$token->set_token( $verification->getPaymentToken() );
					$token->set_recurrence_token( $verification->getRecurrenceToken() );
					$token->set_last4( substr( $verification->getMaskedPan(), - 4 ) );
					$token->set_expiry_year( $expiry_date[1] );
					$token->set_expiry_month( $expiry_date[0] );
					$token->set_card_type( strtolower( $verification->getCardBrand() ) );
					$token->set_user_id( get_current_user_id() );
					$token->set_masked_pan( $verification->getMaskedPan() );

					// Save Credit Card
					$token->save();
					if ( ! $token->get_id() ) {
						throw new Exception( __( 'There was a problem adding the card.', 'swedbank-pay-woocommerce-payments' ) );
					}

					// Only first
					break;
				}
			}

			WC()->session->__unset( 'verification_payment_id' );

			wc_add_notice( __( 'Payment method successfully added.', 'swedbank-pay-woocommerce-payments' ) );
			wp_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit();
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_account_endpoint_url( 'add-payment-method' ) );
			exit();
		}
	}

	/**
	 * Override "checkout/thankyou.php" template
	 *
	 * @param $located
	 * @param $template_name
	 * @param $args
	 * @param $template_path
	 * @param $default_path
	 *
	 * @return string
	 */
	public function override_template( $located, $template_name, $args, $template_path, $default_path ) {
		if ( strpos( $located, 'checkout/thankyou.php' ) !== false ) {
			if ( ! isset( $args['order'] ) ) {
				return $located;
			}

			$order = wc_get_order( $args['order'] );
			if ( ! $order ) {
				return $located;
			}

			if ( $this->id !== $order->get_payment_method() ) {
				return $located;
			}

			$located = wc_locate_template(
				'checkout/thankyou.php',
				$template_path,
				dirname( __FILE__ ) . '/../templates/'
			);
		}

		return $located;
	}

	/**
	 * thankyou_scripts function.
	 *
	 * Outputs scripts used for "thankyou" page
	 *
	 * @return void
	 */
	public function thankyou_scripts() {
		if ( ! is_order_received_page() || 'no' === $this->enabled ) {
			return;
		}

		global $wp;

		$order_id  = absint( $wp->query_vars['order-received'] );
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // WPCS: input var ok, CSRF ok.

		$order = wc_get_order( $order_id );
		if ( ! $order->get_id() || ! $order->key_is_valid( $order_key ) ) {
			return;
		}

		if ( $this->id !== $order->get_payment_method() ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'wc-sb-order-status-check',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/order-status' . $suffix . '.js',
			array(
				'jquery',
				'jquery-blockui'
			),
			false,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'wc-sb-order-status-check',
			'WC_Gateway_Swedbank_Pay_Order_Status',
			array(
				'order_id'      => $order_id,
				'order_key'     => $order_key,
				'nonce'         => wp_create_nonce( 'swedbank_pay' ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'check_message' => __(
					'Please wait. We\'re checking the order status.',
					'swedbank-pay-woocommerce-payments'
				)
			)
		);

		wp_enqueue_script( 'wc-sb-order-status-check' );
	}

	/**
	 * Ajax: Check the payment
	 */
	public function ajax_check_payment() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? wc_clean( $_POST['order_id'] ) : '';
		$order_key  = isset( $_POST['order_key'] ) ? wc_clean( $_POST['order_key'] ) : '';

		$order = wc_get_order( $order_id );
		if ( ! $order->get_id() || ! $order->key_is_valid( $order_key ) ) {
			wp_send_json_error( 'Invalid order' );
			return;
		}

		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			wp_send_json_error( 'Invalid payment' );
			return;
		}

		try {
			// Try to update order status if order has 'failure' status.
			if ( 'failure' === $order->get_status() ) {
				$this->core->fetchTransactionsAndUpdateOrder( $order->get_id() );
			}

			$payment_info = $this->core->fetchPaymentInfo( $payment_id );

			// The aborted-payment operation means that the merchant has aborted the payment before
			// the payer has fulfilled the payment process.
			// You can see this under abortReason in the response.
			$aborted = $payment_info->getOperationByRel( 'aborted-payment', false );
			if ( ! empty( $aborted ) ) {
				$result = $this->core->request( $aborted['method'], $aborted['href'] );

				// Abort reason
				$message = $result['aborted']['abortReason'];

				wp_send_json_success( array(
					'state' => 'aborted',
					'message' => $message
				) );
			}

			// The failed-payment operation means that something went wrong during the payment process, the transaction
			// was not authorized, and no further transactions can be created if the payment is in this state.
			$failed = $payment_info->getOperationByRel( 'failed-payment', false );
			if ( ! empty( $failed ) ) {
				$result = $this->core->request( $failed['method'], $failed['href'] );

				// Extract the problem details
				$message = $result['title'];
				if ( count( $result['problem']['problems'] ) > 0 ) {
					$problems = array_column( $result['problem']['problems'], 'description' );
					$message = implode(', ', $problems );
				}

				wp_send_json_success( array(
					'state' => 'failed',
					'message' => $message
				) );

				return;
			}

			// The paid-payment operation confirms that the transaction has been successful
			// and that the payment is completed.
			$paid = $payment_info->getOperationByRel( 'paid-payment', false );
			if ( ! empty( $paid ) ) {
				$result = $this->core->request( $paid['method'], $paid['href'] );
				if ( ! isset( $result['paid'] ) ) {
					wp_send_json_success( array(
						'state' => 'failed',
						'message' => 'Unable to verify the payment'
					) );

					return;
				}

				// Get transaction and update order statuses
				$this->core->fetchTransactionsAndUpdateOrder( $order->get_id() );

				wp_send_json_success( array(
					'state' => 'paid',
					'message' => 'Order has been paid'
				) );

				return;
			}

			// No any information
			wp_send_json_success( array(
				'state' => 'unknown',
			) );
		} catch ( Exception $exception ) {
			$this->core->log(
				LogLevel::WARNING, sprintf( '%s %s', __METHOD__, $exception->getMessage() )
			);

			wp_send_json_success( array(
				'state' => 'failed',
				'message' => $exception->getMessage()
			) );

			return;
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

		$this->core->log( LogLevel::INFO, __METHOD__ );

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
				$this->core->log(
					LogLevel::WARNING, sprintf( '%s %s', __METHOD__, $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Get the transaction URL.
	 *
	 * @param  WC_Order $order Order object.
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		$payment_id = $order->get_meta( '_payex_payment_id' );
		if ( empty( $payment_id ) ) {
			return parent::get_transaction_url( $order );
		}

		if ( 'yes' === $this->testmode ) {
			$view_transaction_url = 'https://admin.externalintegration.payex.com/psp/beta/payments/details;id=%s';
		} else {
			$view_transaction_url = 'https://admin.payex.com/psp/beta/payments/details;id=%s';
		}

		return sprintf( $view_transaction_url, urlencode( $payment_id ) );
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

		// Use `redirect` method for `order-pay` endpoints
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$this->method = self::METHOD_REDIRECT;
		}

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
			//
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

		$redirect_authorization = $result->getOperationByRel( 'redirect-authorization' );
		if ( $redirect_authorization ) {
			$order->update_meta_data( '_sb_redirect_authorization', $redirect_authorization );
		}

		$view_authorization = $result->getOperationByRel( 'view-authorization' );
		if ( $view_authorization ) {
			$order->update_meta_data( '_sb_view_authorization', $view_authorization );
		}

		$order->save_meta_data();
		$order->save();

		switch ( $this->method ) {
			case self::METHOD_REDIRECT:
				// Redirect
				$order->add_order_note( __( 'Customer has been redirected to Swedbank Pay.', 'swedbank-pay-woocommerce-payments' ) );

				return array(
					'result'   => 'success',
					'redirect' => $redirect_authorization,
				);
			case self::METHOD_SEAMLESS:

				return array(
					'result'                   => 'success',
					'redirect'                 => '#!swedbank-pay-cc',
					'is_swedbank_pay_cc'       => true,
					'js_url'                   => $view_authorization,
				);

			default:
				wc_add_notice( __( 'Wrong method', 'swedbank-pay-woocommerce-payments' ), 'error' );

				return false;
		}
	}

	/**
	 * IPN Callback
	 * @return void
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public function return_handler() {
		$raw_body = file_get_contents( 'php://input' );

		$this->core->log(
			LogLevel::INFO,
			sprintf( 'Incoming Callback: Initialized %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] )
		);
		$this->core->log(
			LogLevel::INFO,
			sprintf( 'Incoming Callback. Post data: %s', var_export( $raw_body, true ) )
		);

		// Check IP address of Incoming Callback
		if ( 'yes' === $this->ip_check ) {
			if ( ! in_array( WC_Geolocation::get_ip_address(),
				apply_filters( 'swedbank_gateway_ip_addresses', $this->gateway_ip_addresses )
			) ) {
				$this->core->log(
					LogLevel::INFO,
					sprintf( 'Error: Incoming Callback has been rejected. %s', WC_Geolocation::get_ip_address() )
				);

				throw new Exception( 'Incoming Callback has been rejected' );
			}
		}

		// Decode raw body
		$data = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Invalid webhook data' );
		}

		try {
			// Verify the order key
			$order_id  = absint(  wc_clean( $_GET['order_id'] ) ); // WPCS: input var ok, CSRF ok.
			$order_key = empty( $_GET['key'] ) ? '' : wc_clean( wp_unslash( $_GET['key'] ) ); // WPCS: input var ok, CSRF ok.

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( 'Unable to load an order.' );
			}

			if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
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

			$this->core->log(
				LogLevel::INFO,
				sprintf( 'Incoming Callback: Task enqueued. Transaction ID: %s', $data['transaction']['number'] )
			);
		} catch ( Exception $e ) {
			$this->core->log( LogLevel::INFO, sprintf( 'Incoming Callback: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Process Recurring Payment.
	 *
	 * @param WC_Order $order
	 * @param string $token
	 *
	 * @return \SwedbankPay\Core\Api\Response
	 * @throws \SwedbankPay\Core\Exception
	 */
	public function process_recurring_payment( $order, $token ) {
		$result = $this->core->initiateCreditCardRecur(
			$order->get_id(),
			$token
		);

		// Save payment ID
		$order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );
		$order->save_meta_data();

		// Get transaction and update order statuses
		$this->core->fetchTransactionsAndUpdateOrder( $order->get_id() );

		return $result;
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

		if ( 0 === absint( $amount ) ) {
			return new WP_Error( 'refund', __( 'Amount must be positive.', 'swedbank-pay-woocommerce-checkout' ) );
		}

		try {
			WC_Swedbank_Pay_Refund::refund( $this, $order, $amount, $reason );

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
			$this->core->log( LogLevel::INFO, sprintf( 'Pending Cancel. Error: %s', $e->getMessage() ) );
		}
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
	 * Add seamless scripts
	 */
	protected function enqueue_seamless() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			'featherlight',
			untrailingslashit(
				plugins_url(
					'/',
					__FILE__
				)
			) . '/../assets/js/featherlight/featherlight' . $suffix . '.js',
			array( 'jquery' ),
			'1.7.13',
			true
		);

		wp_enqueue_style(
			'featherlight-css',
			untrailingslashit(
				plugins_url(
					'/',
					__FILE__
				)
			) . '/../assets/js/featherlight/featherlight' . $suffix . '.css',
			array(),
			'1.7.13',
			'all'
		);

		wp_enqueue_style(
			'featherlight-sb-seamless-css',
			untrailingslashit(
				plugins_url(
					'/',
					__FILE__
				)
			) . '/../assets/css/seamless' . $suffix . '.css',
			array(),
			null,
			'all'
		);

		wp_register_script(
			'wc-sb-seamless',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/seamless' . $suffix . '.js',
			array(
				'jquery',
				'wc-checkout',
				'featherlight',
			),
			false,
			true
		);
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
