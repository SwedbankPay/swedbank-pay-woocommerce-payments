<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

interface WC_Payment_Gateway_Swedbank_Pay_Interface {
	/**
	 * Check is Capture possible
	 *
	 * @param \WC_Order $order
	 * @param bool|float $amount
	 *
	 * @return bool
	 */
	public function can_capture( $order, $amount = false );

	/**
	 * Check is Cancel possible
	 *
	 * @param \WC_Order $order
	 *
	 * @return bool
	 */
	public function can_cancel( $order );

	/**
	 * Check is Refund possible
	 *
	 * @param \WC_Order $order
	 * @param bool|float $amount
	 *
	 * @return bool
	 */
	public function can_refund( $order, $amount = false );

	/**
	 * Capture
	 *
	 * @param \WC_Order $order
	 * @param bool|float $amount
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function capture_payment( $order, $amount );

	/**
	 * Cancel
	 *
	 * @param \WC_Order $order
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function cancel_payment( $order );

	/**
	 * Refund
	 *
	 * @param \WC_Order $order
	 * @param bool|float $amount
	 * @param string $reason
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function refund_payment( $order, $amount, $reason );
}
