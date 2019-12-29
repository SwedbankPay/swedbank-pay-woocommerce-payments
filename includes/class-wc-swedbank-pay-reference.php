<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Swedbank_Pay_Reference
{
	public function __construct() {
		add_filter( 'swedbank_pay_get_payee_reference' , [ $this, 'get_payee_reference' ], 10, 3 );
		add_filter( 'swedbank_pay_get_payee_reference_order' , [ $this, 'get_orderid_by_payee_reference' ], 10, 2 );
	}

	/**
	 * Create Reference for Order
	 *
	 * @param string $reference
	 * @param int|WC_Order $order_id
	 * @param bool $add_suffix
	 *
	 * @return mixed|string
	 */
	public function get_payee_reference( $reference, $order_id, $add_suffix = false ) {
		if ( is_object( $order_id ) ) {
			$order = $order_id;
		} else {
			$order = wc_get_order( $order_id );
		}

		$reference = $order->get_meta( '_sb_payee_reference' );
		if ( empty( $reference ) ) {
			$reference = $this->generate_payee_reference( $order, $add_suffix );
			$order->add_meta_data( '_sb_payee_reference', $reference, true );
			$order->save_meta_data();
		}

		return $reference;
	}

	/**
	 * Get Order Id by Reference
	 *
	 * @param int|WC_Order $order_id
	 * @param string $reference
	 *
	 * @return bool|string|null
	 */
	public function get_orderid_by_payee_reference( $order_id, $reference ) {
		global $wpdb;

		$query = "
		SELECT post_id FROM {$wpdb->prefix}postmeta 
		LEFT JOIN {$wpdb->prefix}posts ON ({$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id)
		WHERE meta_key = %s AND meta_value = %s;";
		$sql = $wpdb->prepare( $query, '_sb_payee_reference', $reference );
		$order_id = $wpdb->get_var( $sql );
		if ( ! $order_id ) {
			return false;
		}

		return $order_id;
	}


	/**
	 * Create payeeReference
	 *
	 * @param int $order_id
	 * @param bool $add_suffix
	 *
	 * @return string
	 */
	protected function generate_payee_reference( $order_id, $add_suffix = false ) {
		if ( is_object( $order_id ) ) {
			$order = $order_id;
		} else {
			$order = wc_get_order( $order_id );
		}

		$payeeReference = $order->get_order_number();

		if ( $add_suffix ) {
			$payeeReference = $payeeReference . 'x' . wp_generate_password( 4, false );
		}

		return $payeeReference;
	}
}

new WC_Swedbank_Pay_Reference();

