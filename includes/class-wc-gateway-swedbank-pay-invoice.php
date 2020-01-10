<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Gateway_Swedbank_Pay_Invoice extends WC_Gateway_Swedbank_Pay_Cc
	implements WC_Payment_Gateway_Swedbank_Pay_Interface {

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

		$amount   = $order->get_total();
		$currency = swedbank_pay_obj_prop( $order, 'order_currency' );
		$email    = swedbank_pay_obj_prop( $order, 'billing_email' );
		$phone    = swedbank_pay_obj_prop( $order, 'billing_phone' );
		$country  = swedbank_pay_obj_prop( $order, 'billing_country' );
		$postcode = swedbank_pay_obj_prop( $order, 'billing_postcode' );

		$ssn = wc_clean( $_POST['social-security-number'] );

		$user_id = $order->get_customer_id();

		// Get Customer UUID
		if ( $user_id > 0 ) {
			$customer_uuid = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $customer_uuid ) ) {
				$customer_uuid = swedbank_pay_uuid( $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $customer_uuid );
			}
		} else {
			$customer_uuid = swedbank_pay_uuid( uniqid( $email ) );
		}

		// Get Order UUID
		$order_uuid = swedbank_pay_uuid( $order_id );

		$params = [
			'payment' => [
				'operation'      => 'FinancingConsumer',
				'intent'         => 'Authorization',
				'currency'       => $currency,
				'prices'         => [
					[
						'type'      => 'Invoice',
						'amount'    => round( $amount * 100 ),
						'vatAmount' => '0'
					]
				],
				'description' => apply_filters(
					'swedbank_pay_payment_description',
					sprintf( __( 'Order #%s', WC_Swedbank_Pay::TEXT_DOMAIN ),
					$order->get_order_number() ),
					$order
				),
				'payerReference' => $customer_uuid,
				'userAgent'      => $_SERVER['HTTP_USER_AGENT'],
				'language'       => $this->culture,
				'urls'           => [
					'completeUrl'       => html_entity_decode( $this->get_return_url( $order ) ),
					'cancelUrl'         => $order->get_cancel_order_url_raw(),
					'callbackUrl'       => WC()->api_request_url( __CLASS__ ),
					'termsOfServiceUrl' => $this->terms_url
				],
				'payeeInfo'      => [
					'payeeId'         => $this->payee_id,
					'payeeReference'  => str_replace( '-', '', $order_uuid ),
					'payeeName'       => get_bloginfo( 'name' ),
					'orderReference'  => $order->get_order_number(),
				],
				'riskIndicator'  => $this->get_risk_indicator( $order ),
				'metadata'       => [
					'order_id' => $order_id
				],
			],
			'invoice' => [
				'invoiceType' => 'PayExFinancing' . ucfirst( strtolower( $country ) )
			]
		];

		// Add subsite
		if ( ! empty( $this->subsite ) ) {
			$params['payment']['payeeInfo']['subsite'] = $this->subsite;
		}

		$this->log( json_encode( $params ) );

		try {
			$result = $this->request( 'POST', '/psp/invoice/payments', $params );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		// Save payment ID
		update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

		// Authorization
		$create_authorize_href = self::get_operation( $result['operations'], 'create-authorization' );

		// Approved Legal Address
		$legal_address_href = self::get_operation( $result['operations'], 'create-approved-legal-address' );

		// Get Approved Legal Address
		try {
			$params = [
				'addressee' => [
					'socialSecurityNumber' => $ssn,
					'zipCode'              => str_replace( ' ', '', $postcode )
				]
			];

			$result = $this->request( 'POST', $legal_address_href, $params );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Create Approved Legal Address: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		// Save legal address
		$legal_address = $result['approvedLegalAddress'];
		update_post_meta( $order_id, '_payex_legal_address', $legal_address );

		// Transaction Activity: FinancingConsumer
		try {
			$params = [
				'transaction'  => [
					'activity' => 'FinancingConsumer'
				],
				'consumer'     => [
					'socialSecurityNumber' => $ssn,
					'customerNumber'       => $user_id,
					'email'                => $email,
					'msisdn'               => '+' . ltrim( $phone, '+' ),
					'ip'                   => swedbank_pay_get_remote_address()
				],
				'legalAddress' => [
					'addressee'     => $legal_address['addressee'],
					'coAddress'     => $legal_address['coAddress'],
					'streetAddress' => $legal_address['streetAddress'],
					'zipCode'       => $legal_address['zipCode'],
					'city'          => $legal_address['city'],
					'countryCode'   => $legal_address['countryCode']
				]
			];

			$result = $this->request( 'POST', $create_authorize_href, $params );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Create Authorize: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order )
		];
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

		// @todo Improve feature
		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$order_id   = swedbank_pay_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', true );
		if ( empty( $payment_id ) ) {
			throw new \Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( \Exception $e ) {
			throw new \Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$capture_href = self::get_operation( $result['operations'], 'create-capture' );
		if ( empty( $capture_href ) ) {
			throw new \Exception( __( 'Capture unavailable', WC_Swedbank_Pay::TEXT_DOMAIN ) );
		}

		// Order Info
		$info = $this->get_order_info( $order );

		// Get Order UUID
		$payeeReference = swedbank_pay_uuid( uniqid( $order_id ) );

		$params = [
			'transaction'      => [
				'activity'       => 'FinancingConsumer',
				'amount'         => (int) round( $amount * 100 ),
				'vatAmount'      => (int) round( $info['vat_amount'] * 100 ),
				'description'    => sprintf( 'Capture for Order #%s', $order->get_order_number() ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			],
			'itemDescriptions' => $info['items']
		];
		$result = $this->request( 'POST', $capture_href, $params );

		// Save transaction
		$transaction = $result['capture']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				update_post_meta( $order_id, '_payex_payment_state', 'Captured' );
				update_post_meta( $order_id, '_payex_transaction_capture', $transaction['id'] );

				$order->add_order_note( __( 'Transaction captured.', WC_Swedbank_Pay::TEXT_DOMAIN ) );
				$order->payment_complete( $transaction['number'] );

				break;
			case 'Initialized':
				$order->add_order_note( sprintf( __( 'Transaction capture status: %s.', WC_Swedbank_Pay::TEXT_DOMAIN ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Capture failed.', WC_Swedbank_Pay::TEXT_DOMAIN );
				throw new \Exception( $message );
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

		$order_id   = swedbank_pay_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', true );
		if ( empty( $payment_id ) ) {
			throw new \Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( \Exception $e ) {
			throw new \Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$cancel_href = self::get_operation( $result['operations'], 'create-cancellation' );
		if ( empty( $cancel_href ) ) {
			throw new \Exception( __( 'Cancellation unavailable', WC_Swedbank_Pay::TEXT_DOMAIN ) );
		}

		// Get Order UUID
		$payeeReference = swedbank_pay_uuid( uniqid( $order_id ) );

		$params = [
			'transaction' => [
				'activity'       => 'FinancingConsumer',
				'description'    => sprintf( 'Cancellation for Order #%s', $order->get_order_number() ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			],
		];
		$result = $this->request( 'POST', $cancel_href, $params );

		// Save transaction
		$transaction = $result['cancellation']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
				update_post_meta( $order_id, '_payex_payment_state', 'Cancelled' );
				update_post_meta( $order_id, '_payex_transaction_cancel', $transaction['id'] );

				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', __( 'Transaction cancelled.', WC_Swedbank_Pay::TEXT_DOMAIN ) );
				} else {
					$order->add_order_note( __( 'Transaction cancelled.', WC_Swedbank_Pay::TEXT_DOMAIN ) );
				}

				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction cancellation status: %s.', WC_Swedbank_Pay::TEXT_DOMAIN ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Cancel failed.', WC_Swedbank_Pay::TEXT_DOMAIN );
				throw new \Exception( $message );
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

		$order_id   = swedbank_pay_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', true );
		if ( empty( $payment_id ) ) {
			throw new \Exception( 'Unable to get payment ID' );
		}

		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( \Exception $e ) {
			throw new \Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$reversal_href = self::get_operation( $result['operations'], 'create-reversal' );
		if ( empty( $reversal_href ) ) {
			throw new \Exception( __( 'Refund unavailable', WC_Swedbank_Pay::TEXT_DOMAIN ) );
		}

		// Get Order UUID
		$payeeReference = uniqid( $order_id );

		$params = [
			'transaction' => [
				'activity'       => 'FinancingConsumer',
				'amount'         => (int) round( $amount * 100 ),
				'vatAmount'      => 0,
				'description'    => sprintf( 'Refund for Order #%s. Reason: %s', $order->get_order_number(), $reason ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			]
		];
		$result = $this->request( 'POST', $reversal_href, $params );

		// Save transaction
		$transaction = $result['reversal']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				//update_post_meta( $order_id, '_payex_payment_state', 'Refunded' );
				update_post_meta( $order_id, '_payex_transaction_refund', $transaction['id'] );
				$order->add_order_note( sprintf( __( 'Refunded: %s. Reason: %s', 'woocommerce-gateway-payex-payment' ), wc_price( $amount ), $reason ) );
				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction reversal status: %s.', WC_Swedbank_Pay::TEXT_DOMAIN ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Refund failed.', WC_Swedbank_Pay::TEXT_DOMAIN );
				throw new \Exception( $message );
				break;
		}
	}
}

// Register Gateway
WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Invoice' );
