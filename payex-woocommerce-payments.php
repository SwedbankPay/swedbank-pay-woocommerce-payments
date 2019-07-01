<?php
/*
 * Plugin Name: PayEx WooCommerce payments
 * Plugin URI: http://payex.com/
 * Description: Provides a Credit Card Payment Gateway through PayEx for WooCommerce.
 * Author: AAIT Team
 * Author URI: http://aait.se/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 1.2.0
 * Text Domain: payex-woocommerce-payments
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
	 * @var WC_Background_Payex_Queue
	 */
	public static $background_process;

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
		add_action( 'woocommerce_init', array( $this, 'woocommerce_init' ) );
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

		// Process payex queue
		if ( ! is_multisite() ) {
			add_action( 'customize_save_after', array( $this, 'maybe_process_queue' ) );
			add_action( 'after_switch_theme', array( $this, 'maybe_process_queue' ) );
		}

		// Add admin menu
		add_action( 'admin_menu', array( &$this, 'admin_menu' ), 99 );

		// Add Upgrade Notice
		if ( version_compare( get_option( 'woocommerce_payex_psp_version', '1.2.0' ), '1.2.0', '<' ) ) {
			add_action( 'admin_notices', __CLASS__ . '::upgrade_notice' );
		}
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
		require_once( dirname( __FILE__ ) . '/includes/class-wc-payex-queue.php' );
	}

	/**
	 * Install
	 */
	public function install() {
		// Install Schema
		WC_Payex_Transactions::instance()->install_schema();

		// Set Version
		if ( ! get_option( 'woocommerce_payex_psp_version' ) ) {
			add_option( 'woocommerce_payex_psp_version', '1.1.0' );
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
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_payex_cc' ) . '">' . __( 'Settings', 'payex-woocommerce-payments' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		// Localization
		load_plugin_textdomain( 'payex-woocommerce-payments', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Functions
		include_once( dirname( __FILE__ ) . '/includes/functions-payex-checkout.php' );
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init()
    {
	    include_once( dirname( __FILE__ ) . '/includes/class-wc-background-payex-queue.php' );
	    self::$background_process = new WC_Background_Payex_Queue();
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
						$order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'payex-woocommerce-payments' ), $message ) );
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
						$order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'payex-woocommerce-payments' ), $message ) );
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
						__( 'PayEx Payments Actions', 'payex-woocommerce-payments' ),
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
				'text_wait' => __( 'Please wait...', 'payex-woocommerce-payments' ),
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
			wp_send_json_success( __( 'Capture success.', 'payex-woocommerce-payments' ) );
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
			wp_send_json_success( __( 'Cancel success.', 'payex-woocommerce-payments' ) );
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

	/**
	 * Dispatch Background Process
	 */
	public function maybe_process_queue()
    {
	    self::$background_process->dispatch();
    }

	/**
	 * Provide Admin Menu items
	 */
	public function admin_menu() {
		// Add Upgrade Page
		global $_registered_pages;

		$hookname = get_plugin_page_hookname( 'wc-payex-psp-upgrade', '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::upgrade_page' );
		}

		$_registered_pages[ $hookname ] = true;
	}


	/**
	 * Upgrade Page
	 */
	public static function upgrade_page() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Run Database Update
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payex-psp-update.php' );
		WC_Payex_Psp_Update::update();

		echo esc_html__( 'Upgrade finished.', 'payex-woocommerce-payments' );
	}

	/**
	 * Upgrade Notice
	 */
	public static function upgrade_notice() {
		if ( current_user_can( 'update_plugins' ) ) {
			?>
			<div id="message" class="error">
				<p>
					<?php
					echo esc_html__( 'Warning! PayEx WooCommerce payments plugin requires to update the database structure.', 'payex-woocommerce-payments' );
					echo ' ' . sprintf( esc_html__( 'Please click %s here %s to start upgrade.', 'payex-woocommerce-payments' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-payex-psp-upgrade' ) ) . '">', '</a>' );
					?>
				</p>
			</div>
			<?php
		}
	}
}

new WC_Payex_Psp();
