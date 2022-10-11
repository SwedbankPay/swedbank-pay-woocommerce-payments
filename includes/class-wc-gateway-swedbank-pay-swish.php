<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\Core;

class WC_Gateway_Swedbank_Pay_Swish extends WC_Gateway_Swedbank_Pay_Cc {

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
	public $debug = 'yes';

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
	 * ecomOnlyEnabled Flag
	 * @var string
	 */
	public $ecom_only = 'yes';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_psp_swish';
		$this->has_fields   = true;
		$this->method_title = __( 'Swish', 'swedbank-pay-woocommerce-payments' );
		$this->icon         = apply_filters(
			'wc_swedbank_pay_swish_icon',
			plugins_url( '/assets/images/swish.png', dirname( __FILE__ ) )
		);
		$this->supports     = array(
			'products',
			'refunds',
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
		$this->enabled      = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
		$this->title        = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->description  = isset( $this->settings['description'] ) ? $this->settings['description'] : '';
		$this->access_token = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token;
		$this->payee_id     = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;
		$this->subsite      = isset( $this->settings['subsite'] ) ? $this->settings['subsite'] : $this->subsite;
		$this->testmode     = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->debug        = isset( $this->settings['debug'] ) ? $this->settings['debug'] : $this->debug;
		$this->ip_check     = isset( $this->settings['ip_check'] ) ? $this->settings['ip_check'] : $this->ip_check;
		$this->culture      = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->method       = isset( $this->settings['method'] ) ? $this->settings['method'] : $this->method;
		$this->ecom_only    = isset( $this->settings['ecom_only'] ) ? $this->settings['ecom_only'] : $this->ecom_only;
		$this->terms_url    = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->logo_url     = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// JS Scrips
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'wc_get_template', array( $this, 'override_template' ), 5, 20 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'thankyou_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'thankyou_scripts' ) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'return_handler' ) );

		// Pending Cancel
		add_action( 'woocommerce_order_status_pending_to_cancelled', array( $this, 'cancel_pending' ), 10, 2 );

		add_filter( 'swedbank_pay_swish_phone_format', array( $this, 'swish_phone_format' ), 10, 2 );

		$this->adapter = new WC_Adapter( $this );
		$this->core    = new Core( $this->adapter );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'swedbank-pay-woocommerce-payments' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Swish payment', 'swedbank-pay-woocommerce-payments' ),
			),
			'description'    => array(
				'title'       => __( 'Description', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Swish payment', 'swedbank-pay-woocommerce-payments' ),
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->payee_id,
			),
			'access_token' => array(
				'title'       => __( 'Access Token', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Access Token', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->access_token,
			),
			'subsite'        => array(
				'title'       => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->subsite,
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->testmode,
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->debug,
			),
			'ip_check'       => array(
				'title'   => __( 'Enable IP checking of incoming callbacks', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable IP checking of incoming callbacks', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->ip_check,
			),
			'culture'        => array(
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
			'method'         => array(
				'title'       => __( 'Checkout Method', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					self::METHOD_REDIRECT => __( 'Redirect', 'swedbank-pay-woocommerce-payments' ),
					self::METHOD_DIRECT   => __( 'Direct', 'swedbank-pay-woocommerce-payments' ),
					self::METHOD_SEAMLESS   => __( 'Seamless View', 'swedbank-pay-woocommerce-payments' ),
				),
				'description' => __( 'Checkout Method', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->method,
			),
			'ecom_only'      => array(
				'title'       => __( 'Ecom Only', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'swedbank-pay-woocommerce-payments' ),
				'description' => __( 'If enabled then trigger the redirect payment scenario by default' ),
				'default'     => $this->ecom_only,
			),
			'terms_url'      => array(
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
		);
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
			'wc-sb-swish',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/seamless-swish' . $suffix . '.js',
			array(
				'wc-sb-seamless',
			),
			false,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'wc-sb-swish',
			'WC_Gateway_Swedbank_Pay_Swish',
			array(
				'culture' => $this->culture
			)
		);

		wp_enqueue_script( 'wc-sb-swish' );
	}

	/**
	 * If There are no payment fields show the description if set.
	 */
	public function payment_fields() {
		parent::payment_fields();
	}

	/**
	 * Validate frontend fields.
	 *
	 * Validate payment fields on the frontend.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$billing_phone = wc_clean( isset( $_POST['billing_phone'] ) ? $_POST['billing_phone'] : '' );
		if ( empty( $billing_phone ) ) {
			wc_add_notice( __( 'Phone number required.', 'swedbank-pay-woocommerce-payments' ), 'error' );
		}

		$billing_phone = apply_filters( 'swedbank_pay_swish_phone_format', $billing_phone, null );

		$matches = array();
		preg_match( '/^\+46[0-9]{6,13}$/u', $billing_phone, $matches );
		if ( ! isset( $matches[0] ) || $matches[0] !== $billing_phone ) {
			wc_add_notice( __( 'Input your number like this +46xxxxxxxxx', 'swedbank-pay-woocommerce-payments' ), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array|false
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Use `redirect` method for `order-pay` endpoints
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$this->method = self::METHOD_REDIRECT;
		}

		// Process payment
		try {
			$result = $this->core->initiateSwishPayment(
				$order_id,
				apply_filters( 'swedbank_pay_swish_phone_format', $order->get_billing_phone(), $order ),
				'yes' === $this->ecom_only
			);
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		// Save payment ID
		$order->update_meta_data( '_payex_payment_id', $result['payment']['id'] );

		$redirect_sale = $result->getOperationByRel( 'redirect-sale' );
		if ( $redirect_sale ) {
			$order->update_meta_data( '_sb_redirect_sale', $redirect_sale );
		}

		$create_sale = $result->getOperationByRel( 'create-sale' );
		if ( $create_sale ) {
			$order->update_meta_data( '_sb_create_sale', $create_sale );
		}

		$view_sales = $result->getOperationByRel( 'view-sales' );
		if ( $view_sales ) {
			$order->update_meta_data( '_sb_view_sales', $view_sales );
		}

		$order->save_meta_data();
		$order->save();

		switch ( $this->method ) {
			case self::METHOD_REDIRECT:
				// Get Redirect
				return array(
					'result'   => 'success',
					'redirect' => $redirect_sale,
				);
			case self::METHOD_DIRECT:
				// Sale payment
				try {
					$this->core->initiateSwishPaymentDirect(
						$create_sale,
						apply_filters( 'swedbank_pay_swish_phone_format', $order->get_billing_phone(), $order )
					);
				} catch ( \Exception $e ) {
					wc_add_notice( $e->getMessage(), 'error' );

					return false;
				}

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			case self::METHOD_SEAMLESS:
				return array(
					'result'                   => 'success',
					'redirect'                 => '#!swedbank-pay-swish',
					'is_swedbank_pay_swish'    => true,
					'js_url'                   => $view_sales,
				);
			default:
				wc_add_notice( __( 'Wrong method', 'swedbank-pay-woocommerce-payments' ), 'error' );

				return false;
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
	 * Format phone
	 *
	 * @param string $phone
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function swish_phone_format( $phone, $order ) {
		return str_replace( array(' ', '-'), '', $phone );
	}
}


