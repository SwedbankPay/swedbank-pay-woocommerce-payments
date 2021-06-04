<?php

use \SwedbankPay\Payments\WooCommerce\WC_Swedbank_Subscriptions as Subscriptions;

class WC_Swedbank_Subscriptions extends WC_Unit_Test_Case {
	public function test_add_subscription_card_id() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'SE' );
		$order->set_payment_method( 'payex_psp_cc' );
		$order->save();

		$result = Subscriptions::add_subscription_card_id( $order->get_id() );

		$this->assertEquals( null, $result );
	}

	public function test_delete_resubscribe_meta() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'SE' );
		$order->set_payment_method( 'payex_psp_cc' );
		$order->save();

		Subscriptions::delete_resubscribe_meta( $order );

		$this->assertEquals( 0, count( $order->get_payment_tokens() ) );
	}

	public function test_add_subscription_payment_meta() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'SE' );
		$order->set_payment_method( 'payex_psp_cc' );
		$order->save();

		$payment_meta = Subscriptions::add_subscription_payment_meta( array(), $order );
		$this->assertIsArray( $payment_meta );
		$this->assertArrayHasKey( 'payex_psp_cc', $payment_meta );
		$this->assertArrayHasKey( 'swedbankpay_meta', $payment_meta['payex_psp_cc'] );
	}

	public function test_payment_meta_input() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'SE' );
		$order->set_payment_method( 'payex_psp_cc' );
		$order->save();

		ob_start();
		Subscriptions::payment_meta_input( $order, 'field_id', null, null );
		$result = ob_get_contents();
		ob_end_clean();
		$this->assertIsString( $result );
		$this->assertContains( '</select>', $result );
	}

	public function test_validate_subscription_payment_meta() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'SE' );
		$order->set_payment_method( 'payex_psp_cc' );
		$order->save();

		$this->expectException( Exception::class );
		Subscriptions::validate_subscription_payment_meta(
			'payex_psp_cc',
			array(
				'swedbankpay_meta' => array(
					'token_id' => array(
						'value' => null
					)
				)
			),
			$order
		);
	}

	public function test_scheduled_subscription_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'payex_psp_cc' );

		$result = Subscriptions::scheduled_subscription_payment( 10, $order );
		$this->assertNull( $result );
	}

}
