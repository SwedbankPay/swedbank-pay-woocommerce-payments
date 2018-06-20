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
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Payex_Transactions::instance();

		$this->id           = 'payex_psp_cc';
		$this->has_fields   = TRUE;
		$this->method_title = __( 'Credit Card', 'woocommerce-gateway-payex-psp' );
		$this->icon         = apply_filters( 'woocommerce_payex_cc_icon', plugins_url( '/assets/images/creditcards.png', dirname( __FILE__ ) ) );
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
				'title'   => __( 'Enable/Disable', 'woocommerce-gateway-payex-psp' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'woocommerce-gateway-payex-psp' ),
				'default' => 'no'
			),
			'title'          => array(
				'title'       => __( 'Title', 'woocommerce-gateway-payex-psp' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-payex-psp' ),
				'default'     => __( 'Credit Card', 'woocommerce-gateway-payex-psp' )
			),
			'description'    => array(
				'title'       => __( 'Description', 'woocommerce-gateway-payex-psp' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-payex-psp' ),
				'default'     => __( 'Credit Card', 'woocommerce-gateway-payex-psp' ),
			),
			'merchant_token' => array(
				'title'       => __( 'Merchant Token', 'woocommerce-gateway-payex-psp' ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', 'woocommerce-gateway-payex-psp' ),
				'default'     => $this->merchant_token
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'woocommerce-gateway-payex-psp' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'woocommerce-gateway-payex-psp' ),
				'default'     => $this->payee_id
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'woocommerce-gateway-payex-psp' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayEx Test Mode', 'woocommerce-gateway-payex-psp' ),
				'default' => $this->testmode
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'woocommerce-gateway-payex-psp' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'woocommerce-gateway-payex-psp' ),
				'default' => $this->debug
			),
			'culture'        => array(
				'title'       => __( 'Language', 'woocommerce-gateway-payex-psp' ),
				'type'        => 'select',
				'options'     => array(
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				),
				'description' => __( 'Language of pages displayed by PayEx during payment.', 'woocommerce-gateway-payex-psp' ),
				'default'     => $this->culture
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
				'intent'         => 'Authorization', // @todo PreAuthorization|Authorization|AutoCapture
				// @todo 'paymentToken'
				'currency'       => $currency,
				'prices'         => [
					[
						'type'      => 'Visa',
						'amount'    => round( $amount * 100 ),
						'vatAmount' => '0'
					],
					[
						'type'      => 'MasterCard',
						'amount'    => round( $amount * 100 ),
						'vatAmount' => '0'
					]
				],
				'description'    => sprintf( __( 'Order #%s', 'woocommerce-gateway-payex-psp' ), $order->get_order_number() ),
				'payerReference' => $customer_uuid,
				'generatePaymentToken' => false,
				'pageStripdown' => false,
				'userAgent'      => $_SERVER['HTTP_USER_AGENT'],
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
				],
				'creditCard' => [
					'no3DSecure' => false
				]
			]
		];

		try {
			$result = $this->request( 'POST', '/psp/creditcard/payments', $params );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Process payment: %s', $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );

			return FALSE;
		}

		// Save payment ID
		update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

		// Get Redirect
		$redirect = self::get_operation( $result['operations'], 'redirect-authorization' );

		return array(
			'result'   => 'success',
			'redirect' => $redirect
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

		if ( px_obj_prop( $order, 'payment_method' ) !== $this->id ) {
			return;
		}

		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
		if ( empty( $payment_id ) ) {
			return;
		}

		// Fetch payment info
		try {
			$result = $this->request( 'GET', $payment_id );
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR] Payment confirm: %s', $e->getMessage() ) );

			return;
		}

		// Check payment state
		switch ( $result['payment']['state'] ) {
			case 'Failed':
				$order->update_status( 'failed', __( 'Payment failed.', 'woocommerce-gateway-payex-psp' ) );

				return;
			case 'Aborted':
				$order->cancel_order( __( 'Payment canceled.', 'woocommerce-gateway-payex-psp' ) );

				return;
			default:
				// Payment state is ok
		}

		// Fetch transactions list
		$result       = $this->request( 'GET', $payment_id . '/transactions' );
		$transactions = $result['transactions']['transactionList'];
		$this->transactions->import_transactions( $transactions, $order_id );

		// Check payment is authorized
		$transactions = $this->transactions->select( array(
			'order_id' => $order_id,
			'type'     => 'Authorization'
		) );

		if ( $transaction = px_filter( $transactions, array( 'state' => 'Completed' ) ) ) {
			$transaction_id = px_obj_prop( $order, 'transaction_id' );
			if ( empty( $transaction_id ) || $transaction_id != $transaction['number'] ) {
				update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
				$order->update_status( 'on-hold', __( 'Payment authorized.', 'woocommerce-gateway-payex-psp' ) );
			}

			WC()->cart->empty_cart();
		} elseif ( $transaction = px_filter( $transactions, array( 'state' => 'Failed' ) ) ) {
			$transaction_id = px_obj_prop( $order, 'transaction_id' );
			if ( empty( $transaction_id ) || $transaction_id != $transaction['number'] ) {
				update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
				$order->update_status( 'failed', __( 'Transaction failed.', 'woocommerce-gateway-payex-psp' ) );
			}
		} else {
			// Pending?
		}
	}


	/**
	 * IPN Callback
	 * @return void
	 */
	public function return_handler() {
		$raw_body = file_get_contents( 'php://input' );

		$this->log( sprintf( 'IPN: Initialized %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
		$this->log( sprintf( 'Incoming Callback. Post data: %s', var_export( $raw_body, TRUE ) ) );

		// Decode raw body
		$data = @json_decode( $raw_body, TRUE );

		try {
			if ( ! isset( $data['payment'] ) || ! isset( $data['payment']['id'] ) ) {
				throw new \Exception( 'Error: Invalid payment value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			// Get Order by Payment Id
			$payment_id = $data['payment']['id'];
			$order_id   = $this->get_post_id_by_meta( '_payex_payment_id', $payment_id );
			if ( ! $order_id ) {
				throw new \Exception( sprintf( 'Error: Failed to get order Id by Payment Id %s', $payment_id ) );
			}

			// Get Order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new \Exception( sprintf( 'Error: Failed to get order by Id %s', $order_id ) );
			}

			// Fetch transactions list
			$result       = $this->request( 'GET', $payment_id . '/transactions' );
			$transactions = $result['transactions']['transactionList'];
			$this->transactions->import_transactions( $transactions, $order_id );

			// Extract transaction from list
			$transaction = px_filter( $transactions, array( 'number' => $data['transaction']['number'] ) );
			$this->log( sprintf( 'IPN: Debug: Transaction: %s', var_export( $transaction, TRUE ) ) );
			if ( ! is_array( $transaction ) || count( $transaction ) === 0 ) {
				throw new \Exception( sprintf( 'Error: Failed to fetch transaction number #%s', $data['transaction']['number'] ) );
			}

			// Check transaction state
			if ( $transaction['state'] !== 'Completed' ) {
				$reason = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Transaction failed.', 'woocommerce-gateway-payex-psp' );
				throw new \Exception( sprintf( 'Error: Transaction state %s. Reason: %s', $data['transaction']['state'], $reason ) );
			}

			// Check is action was performed
			$transaction_id = px_obj_prop( $order, 'transaction_id' );
			if ( ! empty( $transaction_id ) && $transaction_id == $transaction['number'] ) {
				throw new \Exception( sprintf( 'Action of Transaction #%s already performed', $data['transaction']['number'] ) );
			}

			// Apply action
			switch ( $transaction['type'] ) {
				case 'Authorization':
					update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
					$order->update_status( 'on-hold', __( 'Payment authorized.', 'woocommerce-gateway-payex-psp' ) );
					$this->log( sprintf( 'IPN: Order #%s marked as authorized', $order_id ) );
					break;
				case 'Capture':
					update_post_meta( $order_id, '_payex_payment_state', 'Captured' );
					update_post_meta( $order_id, '_payex_transaction_capture', $transaction['id'] );

					$order->add_order_note( __( 'Transaction captured.', 'woocommerce-gateway-payex-psp' ) );
					$order->payment_complete( $transaction['number'] );
					$this->log( sprintf( 'IPN: Order #%s marked as captured', $order_id ) );
					break;
				case 'Cancellation':
					update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
					update_post_meta( $order_id, '_payex_payment_state', 'Cancelled' );
					update_post_meta( $order_id, '_payex_transaction_cancel', $transaction['id'] );

					if ( ! $order->has_status('cancelled') ) {
						$order->update_status( 'cancelled', __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
					} else {
						$order->add_order_note( __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
					}

					$this->log( sprintf( 'IPN: Order #%s marked as cancelled', $order_id ) );
					break;
				case 'Reversal':
					// @todo Implement Refunds creation
					throw new \Exception( 'Error: Reversal transaction don\'t implemented yet.' );
				default:
					throw new \Exception( sprintf( 'Error: Unknown type %s', $transaction['type'] ) );

			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( 'IPN: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Check is Capture possible
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 *
	 * @return bool
	 */
	public function can_capture( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$order_id = px_obj_prop( $order, 'id' );

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		// @todo Improve feature

		$state = get_post_meta( $order_id, '_payex_payment_state' );

		if ( empty( $state ) ) {
			return TRUE;
		}

		return FALSE;
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

		$order_id = px_obj_prop( $order, 'id' );
		$state    = get_post_meta( $order_id, '_payex_payment_state' );

		if ( in_array( $state, array(
			'Captured',
			'Cancelled',
			'Refunded'
		) ) ) {
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * @param \WC_Order $order
	 * @param bool      $amount
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function can_refund( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		// @todo Improve feature
		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$order_id = px_obj_prop( $order, 'id' );

		// Should have payment id
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
		if ( empty( $payment_id ) ) {
			return FALSE;
		}

		// Should be captured
		$state = get_post_meta( $order_id, '_payex_payment_state', TRUE );
		if ( $state !== 'Captured' ) {
			return FALSE;
		}

		// Check refund amount
		try {
			$result = $this->request( 'GET', $payment_id . '/transactions' );
		} catch ( \Exception $e ) {
			throw new \Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		$refunded = 0;
		foreach ( $result['transactions']['transactionList'] as $key => $transaction ) {
			if ( $transaction['type'] === 'Reversal' ) {
				$refunded += ( $transaction['amount'] / 100 );
			}
		}

		$possibleToRefund = $order->get_total() - $refunded;
		if ( $amount > $possibleToRefund ) {
			return FALSE;
		}


		return TRUE;
	}

	/**
	 * Capture
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function capture_payment( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		// @todo Improve feature
		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
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
			throw new \Exception( __( 'Capture unavailable', 'woocommerce-gateway-payex-psp' ) );
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
				update_post_meta( $order_id, '_payex_payment_state', 'Captured' );
				update_post_meta( $order_id, '_payex_transaction_capture', $transaction['id'] );

				$order->add_order_note( __( 'Transaction captured.', 'woocommerce-gateway-payex-psp' ) );
				$order->payment_complete( $transaction['number'] );

				break;
			case 'Initialized':
				$order->add_order_note( sprintf( __( 'Transaction capture status: %s.', 'woocommerce-gateway-payex-psp' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Capture failed.', 'woocommerce-gateway-payex-psp' );
				throw new \Exception( $message );
				break;
		}
	}

	/**
	 * Cancel
	 *
	 * @param WC_Order|int $order
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function cancel_payment( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
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
			throw new \Exception( __( 'Cancellation unavailable', 'woocommerce-gateway-payex-psp' ) );
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
				update_post_meta( $order_id, '_transaction_id', $transaction['number'] );
				update_post_meta( $order_id, '_payex_payment_state', 'Cancelled' );
				update_post_meta( $order_id, '_payex_transaction_cancel', $transaction['id'] );

				if ( ! $order->has_status('cancelled') ) {
					$order->update_status( 'cancelled', __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
				} else {
					$order->add_order_note( __( 'Transaction cancelled.', 'woocommerce-gateway-payex-psp' ) );
				}
				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction cancellation status: %s.', 'woocommerce-gateway-payex-psp' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Cancel failed.', 'woocommerce-gateway-payex-psp' );
				throw new \Exception( $message );
				break;
		}
	}

	/**
	 * Refund
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 * @param string       $reason
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function refund_payment( $order, $amount = FALSE, $reason = '' ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		$order_id   = px_obj_prop( $order, 'id' );
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
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
			throw new \Exception( __( 'Refund unavailable', 'woocommerce-gateway-payex-psp' ) );
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
				//update_post_meta( $order_id, '_payex_payment_state', 'Refunded' );
				update_post_meta( $order_id, '_payex_transaction_refund', $transaction['id'] );
				$order->add_order_note( sprintf( __( 'Refunded: %s. Reason: %s', 'woocommerce-gateway-payex-payment' ), wc_price( $amount ), $reason ) );
				break;
			case 'Initialized':
			case 'AwaitingActivity':
				$order->add_order_note( sprintf( __( 'Transaction reversal status: %s.', 'woocommerce-gateway-payex-psp' ), $transaction['state'] ) );
				break;
			case 'Failed':
			default:
				$message = isset( $transaction['failedReason'] ) ? $transaction['failedReason'] : __( 'Refund failed.', 'woocommerce-gateway-payex-psp' );
				throw new \Exception( $message );
				break;
		}
	}

	/**
	 * Cancel payment on PayEx
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public function cancel_pending( $order_id, $order ) {
		$payment_method = px_obj_prop( $order, 'payment_method' );
		if ( $payment_method !== $this->id ) {
			return;
		}

		// Get Payment Id
		$payment_id = get_post_meta( $order_id, '_payex_payment_id', TRUE );
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
				$order->add_order_note( __( 'Payment aborted', 'woocommerce-gateway-payex-psp' ) );
			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( 'Pending Cancel. Error: %s', $e->getMessage() ) );
		}
	}
}

// Register Gateway
WC_Payex_Psp::register_gateway( 'WC_Gateway_Payex_Cc' );
