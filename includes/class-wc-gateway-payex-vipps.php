<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Gateway_Payex_Vipps extends WC_Gateway_Payex_Cc
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
	 * Checkout Method
	 * @var string
	 */
	public $method = 'redirect';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Payex_Transactions::instance();

		$this->id           = 'payex_vipps';
		$this->has_fields   = TRUE;
		$this->method_title = __( 'Vipps', 'woocommerce-gateway-payex-checkout' );
		$this->icon         = apply_filters( 'woocommerce_payex_vipps_icon', plugins_url( '/assets/images/vipps.png', dirname( __FILE__ ) ) );
		$this->supports     = array(
			'products',
			'refunds',
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
		$this->method         = isset( $this->settings['method'] ) ? $this->settings['method'] : $this->method;

		if ( $this->testmode === 'yes' ) {
			$this->backend_api_endpoint = 'https://api.externalintegration.payex.com';
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
		add_action( 'woocommerce_api_wc_gateway_' . $this->id, array(
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
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-gateway-payex-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'woocommerce-gateway-payex-checkout' ),
				'default' => 'no'
			),
			'title'          => array(
				'title'       => __( 'Title', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-payex-checkout' ),
				'default'     => __( 'Vipps payment', 'woocommerce-gateway-payex-checkout' )
			),
			'description'    => array(
				'title'       => __( 'Description', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-payex-checkout' ),
				'default'     => __( 'Vipps payment', 'woocommerce-gateway-payex-checkout' ),
			),
			'merchant_token' => array(
				'title'       => __( 'Merchant Token', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->merchant_token
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->payee_id
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'woocommerce-gateway-payex-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayEx Test Mode', 'woocommerce-gateway-payex-checkout' ),
				'default' => $this->testmode
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'woocommerce-gateway-payex-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'woocommerce-gateway-payex-checkout' ),
				'default' => $this->debug
			),
			'culture'        => array(
				'title'       => __( 'Language', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'select',
				'options'     => array(
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				),
				'description' => __( 'Language of pages displayed by PayEx during payment.', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->culture
			),
			'method'         => array(
				'title'       => __( 'Checkout Method', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'select',
				'options'     => array(
					'redirect' => __( 'Redirect', 'woocommerce-gateway-payex-checkout' ),
					'direct'   => __( 'Direct', 'woocommerce-gateway-payex-checkout' ),
				),
				'description' => __( 'Checkout Method', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->method
			),
		);
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
		return TRUE;
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
		$order = wc_get_order( $order_id );

		$amount   = $order->get_total();
		$currency = px_obj_prop( $order, 'order_currency' );
		$email    = px_obj_prop( $order, 'billing_email' );
		$phone    = px_obj_prop( $order, 'billing_phone' );

		$user_id = $order->get_customer_id();

		// Get Customer UUID
		if ( $user_id > 0 ) {
			$customer_uuid = get_user_meta( $user_id, '_payex_customer_uuid', TRUE );
			if ( empty( $customer_uuid ) ) {
				$customer_uuid = px_uuid( $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $customer_uuid );
			}
		} else {
			$customer_uuid = px_uuid( uniqid( $email ) );
		}

		// Get Order UUID
		$order_uuid = px_uuid( $order_id );

		$params = [
			'payment' => [
				'operation'      => 'Purchase',
				'intent'         => 'Authorization',
				'currency'       => $currency,
				'prices'         => [
					[
						'type'      => 'Vipps',
						'amount'    => round( $amount * 100 ),
						'vatAmount' => '0'
					]
				],
				'description'    => sprintf( __( 'Order #%s', 'woocommerce-gateway-payex-checkout' ), $order->get_order_number() ),
				'payerReference' => $customer_uuid,
				'userAgent'      => px_get_remote_address(),
				'language'       => $this->culture,
				'urls'           => [
					'completeUrl' => html_entity_decode( $this->get_return_url( $order ) ),
					'cancelUrl'   => $order->get_cancel_order_url_raw(),
					'callbackUrl' => WC()->api_request_url( __CLASS__ )
				],
				'payeeInfo'      => [
					'payeeId'        => $this->payee_id,
					'payeeReference' => $order_uuid,
				],
				'prefillInfo'    => [
					'msisdn' => '+' . ltrim( $phone, '+' )
				]
			]
		];

		try {
			$result = $this->request( 'POST', '/psp/vipps/payments', $params );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return FALSE;
		}

		// Save payment ID
		update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

		switch ( $this->method ) {
			case 'redirect':
				// Get Redirect
				$redirect = self::get_operation( $result['operations'], 'redirect-authorization' );

				return array(
					'result'   => 'success',
					'redirect' => $redirect
				);
				break;
			case 'direct':
				// Authorize payment
				$authorization = self::get_operation( $result['operations'], 'create-authorization' );

				try {
					$params = [
						'transaction' => [
							'msisdn' => '+' . ltrim( $phone, '+' )
						]
					];

					$result = $this->request( 'POST', $authorization, $params );
				} catch ( \Exception $e ) {
					$this->log( sprintf( '[ERROR] Create Authorization: %s', $e->getMessage() ) );
					wc_add_notice( $e->getMessage(), 'error' );

					return FALSE;
				}

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);

				break;

			default:
				wc_add_notice( __( 'Wrong method', 'woocommerce-gateway-payex-checkout' ), 'error' );

				return FALSE;
		}

	}
}

// Register Gateway
WC_Payex_Checkout::register_gateway( 'WC_Gateway_Payex_Vipps' );
