<?php

namespace SwedbankPay\Payments\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Admin_Meta_Boxes;
use Exception;

class WC_Swedbank_Intl_Tel {
	public function __construct() {
		// JS Scrips
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

		// Add settings
		add_action( 'woocommerce_after_register_post_type', array( $this, 'woocommerce_init' ), 100 );
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init() {
		add_filter(
			'woocommerce_settings_api_form_fields_payex_psp_cc',
			array(
				$this,
				'add_settings',
			)
		);
	}

	/**
	 * Add settings
	 *
	 * @param $form_fields
	 *
	 * @return mixed
	 */
	public function add_settings( $form_fields ) {
		$form_fields['enable_intl_tel'] = array(
			'title'       => __( 'Enable International Telephone Input', 'swedbank-pay-woocommerce-payments' ),
			'label'       => __( 'Enable International Telephone Input', 'swedbank-pay-woocommerce-payments' ),
			'type'        => 'checkbox',
			'description' => __( 'Improves phone field using International Telephone Input. A JavaScript plugin for entering and validating international telephone numbers. It adds a flag dropdown to any input, detects the user\'s country, displays a relevant placeholder and provides formatting/validation methods.', 'swedbank-pay-woocommerce-payments' ),
			'desc_tip'    => true,
			'default'     => 'no',
		);

		return $form_fields;
	}

	public function scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		$settings = get_option( 'woocommerce_payex_psp_cc_settings', array( 'enable_intl_tel' => 'no' ) );
		if ( ! isset( $settings['enable_intl_tel'] ) ) {
			$settings['enable_intl_tel'] = 'no';
		}

		if ( 'no' === $settings['enable_intl_tel'] ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'swedbank-intl-tel-css',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/css/intlTelInput' . $suffix . '.css',
			array(),
			'17.0.3',
			'all'
		);

		wp_register_script(
			'swedbank-intl-tel-js',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/intlTelInput' . $suffix . '.js',
			array(
				'jquery',
				'wc-checkout',
			),
			'17.0.3',
			true
		);

		wp_register_script(
			'swedbank-wc-intl-tel-js',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/wc-intl-tel' . $suffix . '.js',
			array(
				'swedbank-intl-tel-js',
			),
			false,
			true
		);

		wp_localize_script(
			'swedbank-intl-tel-js',
			'WC_Gateway_Swedbank_Pay_Intl_Tel',
			array(
				'utils_script' => untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/utils' . $suffix . '.js',
			)
		);

		// Enqueued script with localized data.
		wp_enqueue_script( 'swedbank-intl-tel-js' );
		wp_enqueue_script( 'swedbank-wc-intl-tel-js' );
	}
}

new WC_Swedbank_Intl_Tel();

