<?php

class WC_Unit_Gateway_Swedbank_Pay_Swish extends WC_Unit_Test_Case {
	/**
	 * @var WC_Gateway_Swedbank_Pay_Swish
	 */
	private $gateway;

	/**
	 * @var array
	 */
	private $settings = array(
		'enabled' => 'yes',
		'testmode' => 'yes',
		'debug' => 'yes',
		'method' => 'redirect'
	);

	/**
	 * Setup test case.
	 */
	public function setUp() {
		parent::setUp();

		$this->gateway = new WC_Gateway_Swedbank_Pay_Swish();

		$this->settings['payee_id'] = getenv( 'PAYEE_ID' );
		$this->settings['access_token'] = getenv( 'ACCESS_TOKEN' );
		$this->settings = array_merge( $this->gateway->settings, $this->settings );

		if ( empty( $this->settings['payee_id'] ) || empty( $this->settings['access_token'] ) ) {
			$this->fail("ACCESS_TOKEN or PAYEE_ID wasn't configured in environment variable.");
		}

		update_option(
			$this->gateway->get_option_key(),
			apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->gateway->id, $this->settings ),
			'yes'
		);

		$this->gateway->init_settings();
		$this->gateway = new WC_Gateway_Swedbank_Pay_Swish();
	}

	public function test_payment_gateway() {
		/** @var WC_Payment_Gateways $gateways */
		$gateways = WC()->payment_gateways();
		$this->assertInstanceOf( WC_Payment_Gateways::class, new $gateways );

		$gateways = $gateways->payment_gateways();
		$this->assertIsArray( $gateways );
		$this->assertArrayHasKey($this->gateway->id, $gateways );
	}

	public function test_order() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );
		$order->save();

		$this->assertEquals( $this->gateway->id, $order->get_payment_method() );
	}

	public function test_process_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );
		$order->set_customer_user_agent(
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87 Safari/537'
		);
		$order->set_billing_country( 'SE' );
		$order->set_billing_phone('+46739000001');
		$order->save();

		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'result', $result );
		$this->assertArrayHasKey( 'redirect', $result );
		$this->assertEquals( 'success', $result['result'] );
	}

	public function test_capture_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );

		$this->expectException( Exception::class );
		$this->gateway->capture_payment( $order );
	}

	public function test_cancel_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );

		$this->expectException( Exception::class );
		$this->gateway->cancel_payment( $order );
	}

	public function test_cancel_pending() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );

		$this->gateway->cancel_pending( $order->get_id(), $order );
		$this->assertEquals( $this->gateway->id, $order->get_payment_method() );
	}

	public function test_process_refund() {
		$order  = WC_Helper_Order::create_order();
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );

		$result = $this->gateway->process_refund( $order->get_id(), $order->get_total(), 'Test' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_settings() {
		$this->assertNotNull( $this->gateway->ip_check );

		$form_fields = $this->gateway->get_form_fields();
		$this->assertArrayHasKey( 'ip_check', $form_fields );
	}
}
