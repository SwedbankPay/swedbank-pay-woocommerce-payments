<?php

namespace SwedbankPay\Payments\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Shortcodes;

class WC_Swedbank_Pay_Payment_Url {
	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( isset( $_GET['payment_url'] ) ) { // WPCS: input var ok, CSRF ok.
			add_action( 'init', array( $this, 'override_checkout_shortcode' ), 20 );
		}
	}

	/**
	 * Override woocommerce_checkout shortcode
	 */
	public function override_checkout_shortcode()
	{
		remove_shortcode( 'woocommerce_checkout' );
		add_shortcode(
			apply_filters( 'woocommerce_checkout_shortcode_tag', 'woocommerce_checkout' ),
			array( $this, 'shortcode_woocommerce_checkout' )
		);
	}

	/**
	 * Addes "payment-url" script to finish the payment.
	 *
	 * @param $atts
	 *
	 * @return string
	 */
	public function shortcode_woocommerce_checkout( $atts )
	{
		$order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
		$order = wc_get_order( $order_id );
		if ( ! $order_id || ! $order ) {
			return WC_Shortcodes::checkout( $atts );
		}

		// Look for payment url in meta data
		foreach ( array( '_sb_view_authorization', '_sb_view_payment', '_sb_view_sales' ) as $value ) {
			$payment_url = $order->get_meta( $value );

			if ( ! empty( $payment_url ) )  {
				break;
			}
		}

		if ( ! empty( $payment_url ) ) { // WPCS: input var ok, CSRF ok.
			wp_dequeue_script( 'featherlight' );
			wp_dequeue_script( 'wc-sb-seamless' );
			wp_dequeue_script( 'wc-sb-cc' );
			wp_dequeue_script( 'wc-sb-invoice' );
			wp_dequeue_script( 'wc-sb-mobilepay' );
			wp_dequeue_script( 'wc-sb-swish' );
			wp_dequeue_script( 'wc-sb-trustly' );
			wp_dequeue_script( 'wc-sb-vipps' );

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_register_script(
				'wc-sb-seamless',
				untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/seamless' . $suffix . '.js',
				array(
					'jquery',
				),
				false,
				true
			);

			wp_register_script(
				'wc-sb-payments-payment-url',
				untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/payment-url' . $suffix . '.js',
				array(
					'jquery',
					'wc-sb-seamless'
				),
				false,
				true
			);

			// Load settings
			$settings = get_option( 'woocommerce_' . $order->get_payment_method() . '_settings' );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			// Hosted view
			switch ($order->get_payment_method()) {
				case 'payex_psp_cc':
					$hosted_view = 'creditCard';
					break;
				case 'payex_psp_invoice':
					$hosted_view = 'invoice';
					break;
				case 'payex_psp_mobilepay':
					$hosted_view = 'mobilepay';
					break;
				case 'payex_psp_swish':
					$hosted_view = 'swish';
					break;
				case 'payex_psp_trustly':
					$hosted_view = 'trustly';
					break;
				case 'payex_psp_vipps':
					$hosted_view = 'vipps';
					break;
				default:
					$hosted_view = null;
					break;
			}

			// Localize the script with new data
			$translation_array = array(
				'culture'     => $settings['culture'],
				'payment_url' => $payment_url,
				'hostedView'  => $hosted_view
			);

			wp_localize_script(
				'wc-sb-payments-payment-url',
				'WC_Sb_Payments_Payment_Url',
				$translation_array
			);

			// Enqueued script with localized data.
			wp_enqueue_script( 'wc-sb-payments-payment-url' );

			return '<div id="payment-swedbank-pay-payments"></div>';
		}

		return WC_Shortcodes::checkout( $atts );
	}
}

new WC_Swedbank_Pay_Payment_Url();
