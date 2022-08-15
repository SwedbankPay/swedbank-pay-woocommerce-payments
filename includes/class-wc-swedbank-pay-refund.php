<?php

namespace SwedbankPay\Payments\WooCommerce;

use Exception;
use WC_Order_Refund;
use SwedbankPay\Core\Log\LogLevel;

defined( 'ABSPATH' ) || exit;

class WC_Swedbank_Pay_Refund {
	public function __construct() {
		add_action( 'woocommerce_create_refund', __CLASS__ . '::save_refund_parameters', 10, 2 );
		add_action( 'woocommerce_order_refunded', __CLASS__ . '::remove_refund_parameters', 10, 2 );
	}

	/**
	 * Save refund parameters to perform refund with specified products and amounts.
	 *
	 * @param \WC_Order_Refund $refund
	 * @param $args
	 */
	public static function save_refund_parameters( $refund, $args ) {
		if ( ! isset( $args['order_id'] ) ) {
			return;
		}

		$order = wc_get_order( $args['order_id'] );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), WC_Swedbank_Plugin::PAYMENT_METHODS ) ) {
			return;
		}

		// Prevent refund credit memo creation through Callback
		set_transient( 'sb_refund_block_' . $args['order_id'], $args['order_id'], 5 * MINUTE_IN_SECONDS );

		// Save order items of refund
		set_transient(
			'sb_refund_parameters_' . $args['order_id'],
			$args,
			5 * MINUTE_IN_SECONDS
		);

		// Preserve refund
		$refund_id = $refund->save();
		if ( $refund_id ) {
			// Save refund ID to store transaction_id
			set_transient(
				'sb_current_refund_id_' . $args['order_id'],
				$refund_id,
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Remove refund parameters.
	 *
	 * @param $order_id
	 * @param $refund_id
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function remove_refund_parameters( $order_id, $refund_id ) {
		delete_transient( 'sb_refund_parameters_' . $order_id );
		delete_transient( 'sb_current_refund_id_' . $order_id );
		delete_transient( 'sb_refund_block_' . $order_id );
	}

	/**
	 * Perform Refund.
	 *
	 * @param \WC_Gateway_Swedbank_Pay_Cc $gateway
	 * @param \WC_Order $order
	 * @param $amount
	 * @param $reason
	 *
	 * @return void
	 * @throws \SwedbankPay\Core\Exception
	 */
	public static function refund( $gateway, $order, $amount, $reason ) {
		$args = (array) get_transient( 'sb_refund_parameters_' . $order->get_id() );
		$lines = isset( $args['line_items'] ) ? $args['line_items'] : [];

		// Calculate amount and vat_amount
		$vat_amount = 0;
		if ( count( $lines ) > 0 ) {
			$amount = 0;

			foreach ( $lines as $item_id => $line ) {
				$qty           = (int) $line['qty'];
				$refund_total  = (float) $line['refund_total'];
				$refund_tax    = (float) array_shift( $line['refund_tax'] );
				$refund_amount = $refund_total + $refund_tax;
				$amount        += $refund_amount;
				$vat_amount    += $refund_tax;

				$gateway->core->log(
					LogLevel::INFO,
					sprintf(
						'Refund item %s. qty: %s, total: %s. tax: %s. amount: %s',
						$item_id,
						$qty,
						$refund_total,
						$refund_tax,
						$refund_amount
					)
				);
			}
		}

		if ( 'payex_psp_invoice' === $gateway->id ) {
			$result = $gateway->core->refundInvoice( $order->get_id(), $amount, $vat_amount );
		} else {
			$result = $gateway->core->refund( $order->get_id(), $amount, $vat_amount );
		}

		if ( ! isset( $result['reversal'] ) ) {
			throw new Exception( 'Refund has been failed.' );
		}

		$transaction_id = $result['reversal']['transaction']['number'];

		$order->add_order_note(
			sprintf(
				__(
					'Refund process has been executed from order admin. Transaction ID: %s. State: %s. Reason: %s',
					'swedbank-pay-woocommerce-checkout'
				),
				$transaction_id,
				$result['reversal']['transaction']['state'],
				$reason
			)
		);

		// Add transaction id
		$refund_id = get_transient( 'sb_current_refund_id_' . $order->get_id() );
		if ( $refund_id && $transaction_id ) {
			// Save transaction id
			$refund = new WC_Order_Refund( $refund_id );
			if ( $refund->get_id() ) {
				$refund->update_meta_data( '_transaction_id', $transaction_id );
				$refund->save_meta_data();
			}
		}
	}
}

new WC_Swedbank_Pay_Refund();
