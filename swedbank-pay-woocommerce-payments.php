<?php // phpcs:disable
/*
 * Plugin Name: Swedbank Pay Payments
 * Plugin URI: https://www.swedbankpay.com/
 * Description: Provides the Swedbank Pay Payment Gateway for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 6.4.1
 * Text Domain: swedbank-pay-woocommerce-payments
 * Domain Path: /languages
 * WC requires at least: 5.5.1
 * WC tested up to: 8.1.1
 */

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin;

defined( 'ABSPATH' ) || exit;

include_once( dirname( __FILE__ ) . '/includes/class-wc-swedbank-plugin.php' );

class WC_Swedbank_Pay extends WC_Swedbank_Plugin {
	const TEXT_DOMAIN = 'swedbank-pay-woocommerce-payments';
	// phpcs:enable
	/**
	 * Constructor
	 */
	public function __construct() {
		define( 'SwedbankPay\Payments\WooCommerce\PLUGIN_PATH', plugin_basename( __FILE__ ) );

		parent::__construct();

		// Activation
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Actions
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_loaded', array( $this, 'woocommerce_loaded' ), 20 );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		// Localization
		load_plugin_textdomain(
			'swedbank-pay-woocommerce-payments',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * WooCommerce Loaded: load classes
	 */
	public function woocommerce_loaded() {
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-cc.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-invoice.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-vipps.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-swish.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-mobilepay.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-trustly.php' );

		// Register Gateways
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Cc' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Invoice' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Vipps' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Swish' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Mobilepay' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Trustly' );
	}
}

new WC_Swedbank_Pay();
