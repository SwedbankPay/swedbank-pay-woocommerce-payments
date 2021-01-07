<?php

class WC_Unit_Gateway_Swedbank_Pay_Invoice extends WC_Unit_Test_Case {
	/**
	 * @var WC_Gateway_Swedbank_Pay_Invoice
	 */
	private $gateway;

	/**
	 * @var WooCommerce
	 */
	private $wc;

	/**
	 * Setup test case.
	 */
	public function setUp() {
		parent::setUp();

		$this->wc = WC();

		// Init SwedbankPay Payments plugin
		$this->gateway              = new WC_Gateway_Swedbank_Pay_Invoice();
		$this->gateway->enabled     = 'yes';
		$this->gateway->testmode    = 'yes';
		$this->gateway->description = 'Test';
		$this->gateway->method      = WC_Gateway_Swedbank_Pay_Invoice::METHOD_REDIRECT;

		// Add SwedbankPay to PM List
		tests_add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
	}

	/**
	 * Register Payment Gateway.
	 *
	 * @param $gateways
	 *
	 * @return mixed
	 */
	public function payment_gateways( $gateways ) {
		$payment_gateways[ $this->gateway->id ] = $this->gateway;

		return $gateways;
	}

	public function test_payment_gateway() {
		/** @var WC_Payment_Gateways $gateways */
		$gateways = $this->wc->payment_gateways();
		$this->assertInstanceOf( WC_Payment_Gateways::class, new $gateways );

		$gateways = $gateways->payment_gateways();
		//$this->assertIsArray( $gateways );
		$this->assertTrue( is_array( $gateways ) );
		$this->assertArrayHasKey( 'payex_psp_invoice', $gateways );
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
		$order->save();

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertFalse( $result );
	}

	/**
	 * @expectedException Exception
	 */
	public function test_capture_payment() {
		$order = WC_Helper_Order::create_order();
		$this->gateway->capture_payment( $order );
	}

	/**
	 * @expectedException Exception
	 */
	public function test_cancel_payment() {
		$order = WC_Helper_Order::create_order();
		$this->gateway->cancel_payment( $order );
	}

	public function test_process_refund() {
		$order  = WC_Helper_Order::create_order();
		$result = $this->gateway->process_refund( $order->get_id(), $order->get_total(), 'Test' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
