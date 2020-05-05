<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Payments\WooCommerce\Adapter;
use SwedbankPay\Core\Core;

class WC_Gateway_Swedbank_Pay_Vipps extends WC_Gateway_Swedbank_Pay_Cc {

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
	public $debug = 'yes';

	/**
	 * Locale
	 * @var string
	 */
	public $culture = 'en-US';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_psp_vipps';
		$this->has_fields   = true;
		$this->method_title = __( 'Vipps', WC_Swedbank_Pay::TEXT_DOMAIN );
		$this->icon         = apply_filters( 'wc_swedbank_pay_vipps_icon', plugins_url( '/assets/images/vipps.png', dirname( __FILE__ ) ) );
		$this->supports     = [
			'products',
			'refunds',
		];

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
		$this->terms_url      = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), [ $this, 'return_handler' ] );

		// Payment confirmation
		add_action( 'the_post', [ $this, 'payment_confirm' ] );

		// Pending Cancel
		add_action( 'woocommerce_order_status_pending_to_cancelled', [ $this, 'cancel_pending' ], 10, 2 );

		add_filter( 'swedbank_pay_vipps_phone_format', [ $this, 'vipps_phone_format' ], 10, 2 );

        $this->adapter = new Adapter( $this );
        $this->core = new Core( $this->adapter );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'        => [
				'title'   => __( 'Enable/Disable', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default' => 'no'
			],
			'title'          => [
				'title'       => __( 'Title', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => __( 'Vipps payment', WC_Swedbank_Pay::TEXT_DOMAIN )
			],
			'description'    => [
				'title'       => __( 'Description', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => __( 'Vipps payment', WC_Swedbank_Pay::TEXT_DOMAIN ),
			],
			'merchant_token' => [
				'title'       => __( 'Merchant Token', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => $this->merchant_token
			],
			'payee_id'       => [
				'title'       => __( 'Payee Id', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Payee Id', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => $this->payee_id
			],
			'subsite'         => [
				'title'       => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->subsite
			],
			'testmode'       => [
				'title'   => __( 'Test Mode', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default' => $this->testmode
			],
			'debug'          => [
				'title'   => __( 'Debug', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default' => $this->debug
			],
			'culture'        => [
				'title'       => __( 'Language', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => [
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				],
				'description' => __( 'Language of pages displayed by Swedbank Pay during payment.', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => $this->culture
			],
			'terms_url'      => [
				'title'       => __( 'Terms & Conditions Url', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => get_site_url()
			],
		];
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
			wc_add_notice( __( 'Phone number required.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );
		}

		$matches = [];
		preg_match( '/^(\+47)(?:4[015-8]|5[89]|87|9\d)\d{6}$/u', $billing_phone, $matches );
		if ( ! isset( $matches[0] ) || $matches[0] !== $billing_phone ) {
			wc_add_notice( __( 'Input your number like this +47xxxxxxxxx', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

			return false;
		}

		return true;
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

        // Process payment
        try {
            $result = $this->core->initiateVippsPayment(
                $order_id,
                apply_filters( 'swedbank_pay_vipps_phone_format', $order->get_billing_phone(), $order )
            );

            // Save payment ID
            update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

            return [
                'result'   => 'success',
                'redirect' => $result->getOperationByRel( 'redirect-authorization' )
            ];
        } catch ( Exception $e ) {
            wc_add_notice( $e->getMessage(), 'error' );

            return false;
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
            remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

            $this->core->refund($order->get_id(), $amount, $reason);

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
     * @param mixed $vatAmount
     *
     * @return void
     * @throws \Exception
     */
    public function capture_payment( $order, $amount = false, $vatAmount = 0) {
        if ( is_int( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( is_int( $order ) ) {
            $order = wc_get_order( $order );
        }

        try {
            // Disable status change hook
            remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

            $this->core->capture($order->get_id(), $amount, $vatAmount);
        } catch (\SwedbankPay\Core\Exception $e) {
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
            remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

            $this->core->cancel( $order->get_id() );
        } catch (\SwedbankPay\Core\Exception $e) {
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
	public function vipps_phone_format( $phone, $order ) {
		return $phone;
	}
}


