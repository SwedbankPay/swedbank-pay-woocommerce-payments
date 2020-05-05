<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Payments\WooCommerce\Adapter;
use SwedbankPay\Core\Core;

class WC_Gateway_Swedbank_Pay_Invoice extends WC_Gateway_Swedbank_Pay_Cc {

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

		$this->id           = 'payex_psp_invoice';
		$this->has_fields   = true;
		$this->method_title = __( 'Invoice', WC_Swedbank_Pay::TEXT_DOMAIN );
		//$this->icon         = apply_filters( 'wc_swedbank_pay_invoice_icon', plugins_url( '/assets/images/invoice.png', dirname( __FILE__ ) ) );
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
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [
			$this,
			'process_admin_options'
		] );

		add_action( 'woocommerce_thankyou_' . $this->id, [
			$this,
			'thankyou_page'
		] );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), [
			$this,
			'return_handler'
		] );

		// Payment confirmation
		add_action( 'the_post', [ $this, 'payment_confirm'] );

		// Pending Cancel
		add_action( 'woocommerce_order_status_pending_to_cancelled', [
			$this,
			'cancel_pending'
		], 10, 2 );

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
				'default'     => __( 'Invoice', WC_Swedbank_Pay::TEXT_DOMAIN )
			],
			'description'    => [
				'title'       => __( 'Description', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', WC_Swedbank_Pay::TEXT_DOMAIN ),
				'default'     => __( 'Invoice', WC_Swedbank_Pay::TEXT_DOMAIN ),
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
		?>
		<p class="form-row form-row-wide">
			<label for="social-security-number">
				<?php echo __( 'Social Security Number', WC_Swedbank_Pay::TEXT_DOMAIN ); ?>
				<abbr class="required">*</abbr>
			</label>
			<input type="text" class="input-text required-entry" name="social-security-number"
				   id="social-security-number" value="" autocomplete="off">
		</p>
		<?php
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
			wc_add_notice( __( 'Please specify country.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

			return false;
		}

		if ( empty( $_POST['billing_postcode'] ) ) {
			wc_add_notice( __( 'Please specify postcode.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

			return false;
		}

		if ( ! in_array( mb_strtoupper( $_POST['billing_country'], 'UTF-8' ), [ 'SE', 'NO', 'FI' ] ) ) {
			wc_add_notice( __( 'This country is not supported by the payment system.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

			return false;
		}

		// Validate country phone code
		if ( in_array( $_POST['billing_country'], [ 'SE', 'NO' ] ) ) {
			$phone_code = mb_substr( ltrim( $_POST['billing_phone'], '+' ), 0, 2, 'UTF-8' );
			if ( ! in_array( $phone_code, [ '46', '47' ] ) ) {
				wc_add_notice( __( 'Invalid phone number. Phone code must include country phone code.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

				return false;
			}
		}

		if ( empty( $_POST['social-security-number'] ) ) {
			wc_add_notice( __( 'Please enter your Social Security Number and confirm your order.', WC_Swedbank_Pay::TEXT_DOMAIN ), 'error' );

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
		$postcode = $order->get_billing_postcode();
		$ssn = wc_clean( $_POST['social-security-number'] );

        // Process payment
		try {
            $result = $this->core->initiateInvoicePayment($order_id);

            // Save payment ID
            update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

            // Authorization
            $create_authorize_href = $result->getOperationByRel('create-authorization' );

            // Approved Legal Address
            $legal_address_href = $result->getOperationByRel('create-approved-legal-address');

            // Get Approved Legal Address
            $address = $this->core->getApprovedLegalAddress($legal_address_href, $ssn, $postcode);

            // Save legal address
            $legal_address = $address['approvedLegalAddress'];
            update_post_meta( $order_id, '_payex_legal_address', $legal_address );

            // Transaction Activity: FinancingConsumer
		    $result = $this->core->transactionFinancingConsumer(
		        $create_authorize_href,
                $order_id,
                $ssn,
                $legal_address['addressee'],
                $legal_address['coAddress'],
                $legal_address['streetAddress'],
                $legal_address['zipCode'],
                $legal_address['city'],
                $legal_address['countryCode']
            );
		} catch ( \Exception $e ) {
            wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order )
		];
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

            $this->core->refundInvoice($order->get_id(), $amount);

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
	public function capture_payment( $order, $amount = false, $vatAmount = 0 ) {
        if ( is_int( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( is_int( $order ) ) {
            $order = wc_get_order( $order );
        }

        // Order Info
        $info = $this->get_order_info( $order );

        try {
            // Disable status change hook
            remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

            $this->core->captureInvoice($order->get_id(), $amount, $vatAmount, $info['items']);
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

            $this->core->cancelInvoice($order->get_id());
        } catch (\SwedbankPay\Core\Exception $e) {
            throw new Exception( $e->getMessage() );
        }
	}

    /**
     * Get Order Lines
     *
     * @param \WC_Order $order
     *
     * @return array
     */
    private function get_order_items($order)
    {
        $item = [];

        foreach ($order->get_items() as $order_item) {
            /** @var \WC_Order_Item_Product $order_item */
            $price        = $order->get_line_subtotal($order_item, false, false);
            $priceWithTax = $order->get_line_subtotal($order_item, true, false);
            $tax          = $priceWithTax - $price;
            $taxPercent   = ($tax > 0) ? round(100 / ($price / $tax)) : 0;

            $item[] = [
                'type'              => 'product',
                'name'              => $order_item->get_name(),
                'qty'               => $order_item->get_quantity(),
                'price_with_tax'    => sprintf("%.2f", $priceWithTax),
                'price_without_tax' => sprintf("%.2f", $price),
                'tax_price'         => sprintf("%.2f", $tax),
                'tax_percent'       => sprintf("%.2f", $taxPercent)
            ];
        };

        // Add Shipping Line
        if ((float)$order->get_shipping_total() > 0) {
            $shipping        = $order->get_shipping_total();
            $tax             = $order->get_shipping_tax();
            $shippingWithTax = $shipping + $tax;
            $taxPercent      = ($tax > 0) ? round(100 / ($shipping / $tax)) : 0;

            $item[] = [
                'type'              => 'shipping',
                'name'              => $order->get_shipping_method(),
                'qty'               => 1,
                'price_with_tax'    => sprintf("%.2f", $shippingWithTax),
                'price_without_tax' => sprintf("%.2f", $shipping),
                'tax_price'         => sprintf("%.2f", $tax),
                'tax_percent'       => sprintf("%.2f", $taxPercent)
            ];
        }

        // Add fee lines
        foreach ($order->get_fees() as $order_fee) {
            /** @var \WC_Order_Item_Fee $order_fee */
            $fee        = $order_fee->get_total();
            $tax        = $order_fee->get_total_tax();
            $feeWithTax = $fee + $tax;
            $taxPercent = ($tax > 0) ? round(100 / ($fee / $tax)) : 0;

            $item[] = [
                'type'              => 'fee',
                'name'              => $order_fee->get_name(),
                'qty'               => 1,
                'price_with_tax'    => sprintf("%.2f", $feeWithTax),
                'price_without_tax' => sprintf("%.2f", $fee),
                'tax_price'         => sprintf("%.2f", $tax),
                'tax_percent'       => sprintf("%.2f", $taxPercent)
            ];
        }

        // Add discount line
        if ($order->get_total_discount(false) > 0) {
            $discount        = $order->get_total_discount(true);
            $discountWithTax = $order->get_total_discount(false);
            $tax             = $discountWithTax - $discount;
            $taxPercent      = ($tax > 0) ? round(100 / ($discount / $tax)) : 0;

            $item[] = [
                'type'              => 'discount',
                'name'              => __('Discount', \WC_Swedbank_Pay::TEXT_DOMAIN),
                'qty'               => 1,
                'price_with_tax'    => sprintf("%.2f", -1 * $discountWithTax),
                'price_without_tax' => sprintf("%.2f", -1 * $discount),
                'tax_price'         => sprintf("%.2f", -1 * $tax),
                'tax_percent'       => sprintf("%.2f", $taxPercent)
            ];
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
    private function get_order_info( $order ) {
        $amount       = 0;
        $vatAmount    = 0;
        $descriptions = [];
        $items        = $this->get_order_items( $order );
        foreach ( $items as $item ) {
            $amount         += $item['price_with_tax'];
            $vatAmount      += $item['tax_price'];
            $unit_price     = sprintf( "%.2f", $item['price_without_tax'] / $item['qty'] );
            $descriptions[] = [
                'product'    => $item['name'],
                'quantity'   => $item['qty'],
                'unitPrice'  => (int) round( $unit_price * 100 ),
                'amount'     => (int) round( $item['price_with_tax'] * 100 ),
                'vatAmount'  => (int) round( $item['tax_price'] * 100 ),
                'vatPercent' => sprintf( "%.2f", $item['tax_percent'] ),
            ];
        }

        return [
            'amount'     => $amount,
            'vat_amount' => $vatAmount,
            'items'      => $descriptions
        ];
    }
}
