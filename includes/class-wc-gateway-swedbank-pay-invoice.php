<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Instant_Capture;
use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\Core;

class WC_Gateway_Swedbank_Pay_Invoice extends WC_Gateway_Swedbank_Pay_Cc {

    const METHOD_REDIRECT = 'redirect';
    const METHOD_SEAMLESS = 'seamless';

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
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_psp_invoice';
		$this->has_fields   = true;
		$this->method_title = __( 'Invoice', 'swedbank-pay-woocommerce-payments' );
		//$this->icon         = apply_filters( 'wc_swedbank_pay_invoice_icon', plugins_url( '/assets/images/invoice.png', dirname( __FILE__ ) ) );
		$this->supports = array(
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
		$this->auto_capture    = 'no';
		$this->instant_capture = isset( $this->settings['instant_capture'] ) ? $this->settings['instant_capture'] : $this->instant_capture;
		$this->terms_url       = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->logo_url        = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;

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
				'default'     => __( 'Invoice', 'swedbank-pay-woocommerce-payments' ),
			),
			'description'    => array(
				'title'       => __( 'Description', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Invoice', 'swedbank-pay-woocommerce-payments' ),
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->payee_id,
			),
			'access_token'   => array(
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
			'instant_capture'         => array(
				'title'          => __( 'Instant Capture', 'swedbank-pay-woocommerce-payments' ),
				'description'    => __( 'Capture payment automatically depends on the product type.', 'swedbank-pay-woocommerce-payments' ),
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
					self::METHOD_REDIRECT   => __( 'Redirect', 'swedbank-pay-woocommerce-payments' ),
					self::METHOD_SEAMLESS   => __( 'Seamless View', 'swedbank-pay-woocommerce-payments' ),
				),
				'description' => __( 'Checkout Method', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->method,
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
			'wc-sb-invoice',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/seamless-invoice' . $suffix . '.js',
			array(
				'wc-sb-seamless',
			),
			false,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'wc-sb-invoice',
			'WC_Gateway_Swedbank_Pay_Invoice',
			array(
				'culture' => $this->culture
			)
		);

		wp_enqueue_script( 'wc-sb-invoice' );
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
		if ( empty( $_POST['billing_country'] ) ) {
			wc_add_notice( __( 'Please specify country.', 'swedbank-pay-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( empty( $_POST['billing_postcode'] ) ) {
			wc_add_notice( __( 'Please specify postcode.', 'swedbank-pay-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( ! in_array( mb_strtoupper( $_POST['billing_country'], 'UTF-8' ), array( 'SE', 'NO', 'FI' ), true ) ) {
			wc_add_notice(
				__( 'This country is not supported by the payment system.', 'swedbank-pay-woocommerce-payments' ),
				'error'
			);

			return false;
		}

		// Validate country phone code
		if ( in_array( $_POST['billing_country'], array( 'SE', 'NO' ), true ) ) {
			$phone_code = mb_substr( ltrim( $_POST['billing_phone'], '+' ), 0, 2, 'UTF-8' );
			if ( ! in_array( $phone_code, array( '46', '47' ), true ) ) {
				wc_add_notice(
					__(
						'Invalid phone number. Phone code must include country phone code.',
						'swedbank-pay-woocommerce-payments'
					),
					'error'
				);

				return false;
			}
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
			$result = $this->core->initiateInvoicePayment(
				$order_id
			);
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
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
				// Get Redirect

				return array(
					'result'   => 'success',
					'redirect' => $redirect_authorization,
				);
            case self::METHOD_SEAMLESS:
				return array(
					'result'                   => 'success',
					'redirect'                 => '#!swedbank-pay-invoice',
					'is_swedbank_pay_invoice'  => true,
					'js_url'                   => $view_authorization,
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function capture_payment( $order, $amount = false, $vat_amount = 0 ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		try {
			$this->core->captureInvoice( $order->get_id() );
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
			$this->core->cancelInvoice( $order->get_id() );
		} catch ( \SwedbankPay\Core\Exception $e ) {
			throw new Exception( $e->getMessage() );
		}
	}

	/**
	 * Get Order Lines
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 * @deprecated
	 */
	private function get_order_items( $order ) {
		$item = array();

		foreach ( $order->get_items() as $order_item ) {
			/** @var \WC_Order_Item_Product $order_item */
			$price          = $order->get_line_subtotal( $order_item, false, false );
			$price_with_tax = $order->get_line_subtotal( $order_item, true, false );
			$tax            = $price_with_tax - $price;
			$tax_percent    = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'product',
				'name'              => $order_item->get_name(),
				'qty'               => $order_item->get_quantity(),
				'price_with_tax'    => sprintf( '%.2f', $price_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $price ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		};

		// Add Shipping Line
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping          = $order->get_shipping_total();
			$tax               = $order->get_shipping_tax();
			$shipping_with_tax = $shipping + $tax;
			$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'shipping',
				'name'              => $order->get_shipping_method(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', $shipping_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $shipping ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			/** @var \WC_Order_Item_Fee $order_fee */
			$fee          = $order_fee->get_total();
			$tax          = $order_fee->get_total_tax();
			$fee_with_tax = $fee + $tax;
			$tax_percent  = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'fee',
				'name'              => $order_fee->get_name(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', $fee_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $fee ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		}

		// Add discount line
		if ( $order->get_total_discount( false ) > 0 ) {
			$discount          = $order->get_total_discount( true );
			$discount_with_tax = $order->get_total_discount( false );
			$tax               = $discount_with_tax - $discount;
			$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'discount',
				'name'              => __( 'Discount', 'swedbank-pay-woocommerce-payments' ),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', - 1 * $discount_with_tax ),
				'price_without_tax' => sprintf( '%.2f', - 1 * $discount ),
				'tax_price'         => sprintf( '%.2f', - 1 * $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
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
	 * @deprecated
	 */
	private function get_order_info( $order ) {
		$amount       = 0;
		$vat_amount   = 0;
		$descriptions = array();
		$items        = $this->get_order_items( $order );
		foreach ( $items as $item ) {
			$amount        += $item['price_with_tax'];
			$vat_amount    += $item['tax_price'];
			$unit_price     = sprintf( '%.2f', $item['price_without_tax'] / $item['qty'] );
			$descriptions[] = array(
				OrderItemInterface::FIELD_NAME        => $item['name'],
				OrderItemInterface::FIELD_QTY         => $item['qty'],
				OrderItemInterface::FIELD_UNITPRICE   => (int) round( $unit_price * 100 ),
				OrderItemInterface::FIELD_AMOUNT      => (int) round( $item['price_with_tax'] * 100 ),
				OrderItemInterface::FIELD_VAT_AMOUNT  => (int) round( $item['tax_price'] * 100 ),
				OrderItemInterface::FIELD_VAT_PERCENT => (int) round( $item['tax_percent'] * 100 ),
			);
		}

		return array(
			'amount'     => $amount,
			'vat_amount' => $vat_amount,
			'items'      => $descriptions,
		);
	}
}
