<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Gateway_Payex_Invoice extends WC_Gateway_Payex_Cc
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
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Payex_Transactions::instance();

		$this->id           = 'payex_psp_invoice';
		$this->has_fields   = true;
		$this->method_title = __( 'Invoice', 'payex-woocommerce-payments' );
		//$this->icon         = apply_filters( 'woocommerce_payex_psp_invoice_icon', plugins_url( '/assets/images/invoice.png', dirname( __FILE__ ) ) );
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
				'default'     => __( 'Invoice', 'payex-woocommerce-payments' )
			),
			'description'    => array(
				'title'       => __( 'Description', 'payex-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'payex-woocommerce-payments' ),
				'default'     => __( 'Invoice', 'payex-woocommerce-payments' ),
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
		?>
		<p class="form-row form-row-wide">
			<label for="social-security-number">
				<?php echo __( 'Social Security Number', 'payex-woocommerce-payments' ); ?>
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
			wc_add_notice( __( 'Please specify country.', 'payex-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( empty( $_POST['billing_postcode'] ) ) {
			wc_add_notice( __( 'Please specify postcode.', 'payex-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( ! in_array( mb_strtoupper( $_POST['billing_country'], 'UTF-8' ), array( 'SE', 'NO', 'FI' ) ) ) {
			wc_add_notice( __( 'This country is not supported by the payment system.', 'payex-woocommerce-payments' ), 'error' );

			return false;
		}

		// Validate country phone code
		if ( in_array( $_POST['billing_country'], [ 'SE', 'NO' ] ) ) {
			$phone_code = mb_substr( ltrim( $_POST['billing_phone'], '+' ), 0, 2, 'UTF-8' );
			if ( ! in_array( $phone_code, [ '46', '47' ] ) ) {
				wc_add_notice( __( 'Invalid phone number. Phone code must include country phone code.', 'payex-woocommerce-payments' ), 'error' );

				return false;
			}
		}

		if ( empty( $_POST['social-security-number'] ) ) {
			wc_add_notice( __( 'Please enter your Social Security Number and confirm your order.', 'payex-woocommerce-payments' ), 'error' );

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
		$currency = px_obj_prop( $order, 'order_currency' );
		$email    = px_obj_prop( $order, 'billing_email' );
		$phone    = px_obj_prop( $order, 'billing_phone' );
		$country  = px_obj_prop( $order, 'billing_country' );
		$postcode = px_obj_prop( $order, 'billing_postcode' );

		$ssn = wc_clean( $_POST['social-security-number'] );

		$user_id = $order->get_customer_id();

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
		$order_uuid = px_uuid( $order_id );

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
				'description'    => sprintf( __( 'Order #%s', 'payex-woocommerce-payments' ), $order->get_order_number() ),
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
					'orderReference'  => $order->get_id()
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
					'ip'                   => px_get_remote_address()
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

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order )
		);
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
			$unit_price     = sprintf( "%.2f", $item['price_without_tax'] / $item['qty'] );
			$descriptions[] = array(
				'product'    => $item['name'],
				'quantity'   => $item['qty'],
				'unitPrice'  => (int) round( $unit_price * 100 ),
				'amount'     => (int) round( $item['price_with_tax'] * 100 ),
				'vatAmount'  => (int) round( $item['tax_price'] * 100 ),
				'vatPercent' => sprintf( "%.2f", $item['tax_percent'] ),
			);
		}

		return array(
			'amount'     => $amount,
			'vat_amount' => $vatAmount,
			'items'      => $descriptions
		);
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

		$order_id   = px_obj_prop( $order, 'id' );
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
			throw new \Exception( __( 'Capture unavailable', 'payex-woocommerce-payments' ) );
		}

		// Order Info
		$info = $this->get_order_info( $order );

		// Get Order UUID
		$payeeReference = px_uuid( uniqid( $order_id ) );

		$params = array(
			'transaction'      => array(
				'activity'       => 'FinancingConsumer',
				'amount'         => (int) round( $amount * 100 ),
				'vatAmount'      => (int) round( $info['vat_amount'] * 100 ),
				'description'    => sprintf( 'Capture for Order #%s', $order_id ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			),
			'itemDescriptions' => $info['items']
		);
		$result = $this->request( 'POST', $capture_href, $params );

		// Save transaction
		$transaction = $result['capture']['transaction'];
		$this->transactions->import( $transaction, $order_id );

		switch ( $transaction['state'] ) {
			case 'Completed':
				update_post_meta( $order_id, '_payex_payment_state', 'Captured' );
				update_post_meta( $order_id, '_payex_transaction_capture', $transaction['id'] );

				$order->add_order_note( __( 'Transaction captured.', 'payex-woocommerce-payments' ) );
				$order->payment_complete( $transaction['number'] );

				break;
			case 'Initialized':
				$order->add_order_note( sprintf( __( 'Transaction capture status: %s.', 'payex-woocommerce-payments' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Capture failed.', 'payex-woocommerce-payments' );
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

		$order_id   = px_obj_prop( $order, 'id' );
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
			throw new \Exception( __( 'Cancellation unavailable', 'payex-woocommerce-payments' ) );
		}

		// Get Order UUID
		$payeeReference = px_uuid( uniqid( $order_id ) );

		$params = array(
			'transaction' => array(
				'activity'       => 'FinancingConsumer',
				'description'    => sprintf( 'Cancellation for Order #%s', $order_id ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			),
		);
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

		$order_id   = px_obj_prop( $order, 'id' );
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
			throw new \Exception( __( 'Refund unavailable', 'payex-woocommerce-payments' ) );
		}

		// Get Order UUID
		$payeeReference = uniqid( $order_id );

		$params = array(
			'transaction' => array(
				'activity'       => 'FinancingConsumer',
				'amount'         => (int) round( $amount * 100 ),
				'vatAmount'      => 0,
				'description'    => sprintf( 'Refund for Order #%s. Reason: %s', $order_id, $reason ),
				'payeeReference' => str_replace( '-', '', $payeeReference )
			)
		);
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
				$order->add_order_note( sprintf( __( 'Transaction reversal status: %s.', 'payex-woocommerce-payments' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Refund failed.', 'payex-woocommerce-payments' );
				throw new \Exception( $message );
				break;
		}
	}
}

// Register Gateway
WC_Payex_Psp::register_gateway( 'WC_Gateway_Payex_Invoice' );
