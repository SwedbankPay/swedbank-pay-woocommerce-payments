<?php
/*
 * Plugin Name: Swedbank Pay Checkout
 * Plugin URI: https://www.swedbankpay.com/
 * Description: (Preview). Provides a Credit Card Payment Gateway through Swedbank Pay for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 3.0.0-beta.1
 * Text Domain: swedbank-pay-woocommerce-checkout
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 3.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'WC_Swedbank_Plugin', false ) ) {
	include_once( dirname( __FILE__ ) . '/includes/class-wc-swedbank-plugin.php' );
}

class WC_Swedbank_Pay_Checkout extends WC_Swedbank_Plugin {
	const TEXT_DOMAIN = 'swedbank-pay-woocommerce-checkout';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Activation
		register_activation_hook( __FILE__, [ $this, 'install' ] );

		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );
		add_action( 'plugins_loaded', [ $this, 'init' ], 0 );
		add_action( 'woocommerce_loaded', [ $this, 'woocommerce_loaded' ], 30 );
	}

	/**
	 * Install
	 */
	public function install() {
		parent::install();

		// Set Version
		if ( ! get_option( 'woocommerce_payex_checkout_version' ) ) {
			add_option( 'woocommerce_payex_checkout_version', '1.0.0' );
		}
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = [
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payex_checkout' ) . '">' . __( 'Settings', WC_Swedbank_Pay_Checkout::TEXT_DOMAIN ) . '</a>'
		];

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 * @return void
	 */
	public function init() {
		// Localization
		load_plugin_textdomain( 'swedbank-pay-woocommerce-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		load_plugin_textdomain( WC_Swedbank_Pay_Checkout::TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * WooCommerce Loaded: load classes
	 * @return void
	 */
	public function woocommerce_loaded() {
		if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Gateway_Swedbank_Pay_Cc', false ) ) {
			include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-cc.php' );
		}

		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-checkout.php' );

		// Register Gateway
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Checkout' );
	}
}

new WC_Swedbank_Pay_Checkout();
