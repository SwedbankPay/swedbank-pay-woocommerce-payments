<?php
/*
 * Plugin Name: WooCommerce PayEx PSP Gateway
 * Plugin URI: http://payex.com/
 * Description: Provides a Credit Card Payment Gateway through PayEx for WooCommerce.
 * Author: AAIT Team
 * Author URI: http://aait.se/
 * Version: 1.1.0
 * Text Domain: woocommerce-gateway-payex-psp
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 3.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Payex_Psp {

	/** Payment IDs */
	const PAYMENT_METHODS = array(
		'payex_checkout',
		'payex_psp_cc',
		'payex_psp_invoice',
		'payex_psp_vipps',
	    'payex_psp_swish'
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		// Includes
		$this->includes();

		// Activation
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array(
			$this,
			'plugin_action_links'
		) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_loaded', array(
			$this,
			'woocommerce_loaded'
		) );

		// Add statuses for payment complete
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array(
			$this,
			'add_valid_order_statuses'
		), 10, 2 );

		// Status Change Actions
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::order_status_changed', 10, 4 );

		// Add meta boxes
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes' );

		// Add scripts and styles for admin
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::admin_enqueue_scripts' );

		// Add Admin Backend Actions
		add_action( 'wp_ajax_payex_capture', array(
			$this,
			'ajax_payex_capture'
		) );

		add_action( 'wp_ajax_payex_cancel', array(
			$this,
			'ajax_payex_cancel'
		) );

		// UUID Filter
		add_filter( 'payex_generate_uuid', array(
			$this,
			'generate_uuid'
		), 10, 1 );
	}

	public function includes() {
		$vendorsDir = dirname( __FILE__ ) . '/vendors';

		if ( ! class_exists( '\\PayEx\\Api\\Client', FALSE ) ) {
			require_once $vendorsDir . '/payex-ecom-php/vendor/autoload.php';
		}

		if ( ! class_exists( '\\Ramsey\\Uuid\\Uuid', FALSE ) ) {
			require_once $vendorsDir . '/ramsey-uuid/vendor/autoload.php';
		}

		if ( ! class_exists( 'FullNameParser', FALSE ) ) {
			require_once $vendorsDir . '/php-name-parser/vendor/autoload.php';
		}

		require_once( dirname( __FILE__ ) . '/includes/class-wc-payex-transactions.php' );
	}

	/**
	 * Install
	 */
	public function install() {
		// Install Schema
		WC_Payex_Transactions::instance()->install_schema();

		// Set Version
		if ( ! get_option( 'woocommerce_payex_psp_version' ) ) {
			add_option( 'woocommerce_payex_psp_version', '1.0.0' );
		}
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_payex_cc' ) . '">' . __( 'Settings', 'woocommerce-gateway-payex-psp' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		// Localization
		load_plugin_textdomain( 'woocommerce-gateway-payex-psp', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Functions
		include_once( dirname( __FILE__ ) . '/includes/functions-payex-checkout.php' );
	}

	/**
	 * WooCommerce Loaded: load classes
	 */
	public function woocommerce_loaded() {
		// Includes
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payment-token-payex.php' );
		include_once( dirname( __FILE__ ) . '/includes/interfaces/class-wc-payment-gateway-payex-interface.php' );
		include_once( dirname( __FILE__ ) . '/includes/abstracts/abstract-wc-payment-gateway-payex.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-cc.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-invoice.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-vipps.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-swish.php' );
	}

	/**
	 * Register payment gateway
	 *
	 * @param string $class_name
	 */
	public static function register_gateway( $class_name ) {
		global $px_gateways;

		if ( ! $px_gateways ) {
			$px_gateways = array();
		}

		if ( ! isset( $px_gateways[ $class_name ] ) ) {
			// Initialize instance
			if ( $gateway = new $class_name ) {
				$px_gateways[] = $class_name;

				// Register gateway instance
				add_filter( 'woocommerce_payment_gateways', function ( $methods ) use ( $gateway ) {
					$methods[] = $gateway;

					return $methods;
				} );
			}
		}
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param array    $statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function add_valid_order_statuses( $statuses, $order ) {
		$payment_method = px_obj_prop( $order, 'payment_method' );
		if ( in_array( $payment_method, self::PAYMENT_METHODS ) ) {
			$statuses = array_merge( $statuses, array(
				'processing',
				'completed'
			) );
		}

		return $statuses;
	}

	/**
	 * Order Status Change: Capture/Cancel
	 *
	 * @param $order_id
	 * @param $from
	 * @param $to
	 * @param $order
	 */
	public static function order_status_changed( $order_id, $from, $to, $order ) {
		// We are need "on-hold" only
		if ( $from !== 'on-hold' ) {
			return;
		}

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$order = wc_get_order( $order_id );
		}

		$payment_method = px_obj_prop( $order, 'payment_method' );
		if ( ! in_array( $payment_method, self::PAYMENT_METHODS ) ) {
			return;
		}

		/** @var WC_Payment_Gateway_Payex $gateway */
		$gateway = px_payment_method_instance( $order );

		switch ( $to ) {
			case 'cancelled':
				// Cancel payment
				if ( $gateway->can_cancel( $order ) ) {
					try {
						px_cancel_payment( $order_id );
					} catch ( Exception $e ) {
						$message = $e->getMessage();
						WC_Admin_Meta_Boxes::add_error( $message );

						// Rollback
						$order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'woocommerce-gateway-payex-psp' ), $message ) );
					}
				}
				break;
			case 'processing':
			case 'completed':
				// Capture payment
				if ( $gateway->can_capture( $order ) ) {
					try {
						px_capture_payment( $order_id );
					} catch ( Exception $e ) {
						$message = $e->getMessage();
						WC_Admin_Meta_Boxes::add_error( $message );

						// Rollback
						$order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'woocommerce-gateway-payex-psp' ), $message ) );
					}
				}
				break;
			default:
				// no break
		}
	}

	/**
	 * Add meta boxes in admin
	 * @return void
	 */
	public static function add_meta_boxes() {
		global $post_id;
		if ( $order = wc_get_order( $post_id ) ) {
			$payment_method = px_obj_prop( $order, 'payment_method' );
			if ( in_array( $payment_method, self::PAYMENT_METHODS ) ) {
				$payment_id = get_post_meta( $post_id, '_payex_payment_id', TRUE );
				if ( ! empty( $payment_id ) ) {
					add_meta_box(
						'payex_payment_actions',
						__( 'PayEx Payments Actions', 'woocommerce-gateway-payex-psp' ),
						__CLASS__ . '::order_meta_box_payment_actions',
						'shop_order',
						'side',
						'default'
					);
				}
			}
		}
	}

	/**
	 * MetaBox for Payment Actions
	 * @return void
	 */
	public static function order_meta_box_payment_actions() {
		global $post_id;
		$order      = wc_get_order( $post_id );
		$payment_id = get_post_meta( $post_id, '_payex_payment_id', TRUE );
		if ( empty( $payment_id ) ) {
			return;
		}

		/** @var WC_Payment_Gateway_Payex $gateway */
		$gateway = px_payment_method_instance( $order );

		// Fetch payment info
		try {
			$result = $gateway->request( 'GET', $payment_id );
		} catch ( \Exception $e ) {
			// Request failed
			return;
		}

		wc_get_template(
			'admin/payment-actions.php',
			array(
				'order'      => $order,
				'order_id'   => $post_id,
				'payment_id' => $payment_id,
				'info'       => $result
			),
			'',
			dirname( __FILE__ ) . '/templates/'
		);
	}

	/**
	 * Enqueue Scripts in admin
	 *
	 * @param $hook
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook ) {
		if ( $hook === 'post.php' ) {
			// Scripts
			wp_register_script( 'payex-admin-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js' );

			// Localize the script
			$translation_array = array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'text_wait' => __( 'Please wait...', 'woocommerce-gateway-payex-psp' ),
			);
			wp_localize_script( 'payex-admin-js', 'Payex_Admin', $translation_array );

			// Enqueued script with localized data
			wp_enqueue_script( 'payex-admin-js' );
		}
	}

	/**
	 * Action for Capture
	 */
	public function ajax_payex_capture() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'payex' ) ) {
			exit( 'No naughty business' );
		}

		$order_id = (int) $_REQUEST['order_id'];

		try {
			px_capture_payment( $order_id );
			wp_send_json_success( __( 'Capture success.', 'woocommerce-gateway-payex-psp' ) );
		} catch ( Exception $e ) {
			$message = $e->getMessage();
			wp_send_json_error( $message );
		}
	}

	/**
	 * Action for Cancel
	 */
	public function ajax_payex_cancel() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'payex' ) ) {
			exit( 'No naughty business' );
		}

		$order_id = (int) $_REQUEST['order_id'];

		try {
			px_cancel_payment( $order_id );
			wp_send_json_success( __( 'Cancel success.', 'woocommerce-gateway-payex-psp' ) );
		} catch ( Exception $e ) {
			$message = $e->getMessage();
			wp_send_json_error( $message );
		}
	}

	/**
	 * Generate UUID
	 *
	 * @param $node
	 *
	 * @return string
	 */
	public function generate_uuid( $node ) {
		return \Ramsey\Uuid\Uuid::uuid5( \Ramsey\Uuid\Uuid::NAMESPACE_OID, $node )->toString();
	}
}

new WC_Payex_Psp();
