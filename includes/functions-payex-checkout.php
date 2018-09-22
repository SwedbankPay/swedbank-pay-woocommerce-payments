<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access an object's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 * @see wcs_get_objects_property()
 *
 * @param WC_Order|WC_Product|WC_Subscription $object
 * @param string                              $property
 *
 * @return mixed
 */
function px_obj_prop( $object, $property ) {
	switch ( $property ) {
		case 'order_currency' :
		case 'currency' :
			if ( method_exists( $object, 'get_currency' ) ) { // WC 3.0+
				$value = $object->get_currency();
			} else { // WC 2.1-2.6
				$value = $object->get_order_currency();
			}
			break;
		default:
			$function_name = 'get_' . $property;
			if ( is_callable( array( $object, $function_name ) ) ) {
				$value = $object->$function_name();
			} else {
				$value = isset( $object->$property ) ? $object->$property : NULL;
			}
			break;
	}

	return $value;
}

/**
 * Get Remove Address
 * @return string
 */
function px_get_remote_address() {
	$headers = array(
		'CLIENT_IP',
		'FORWARDED',
		'FORWARDED_FOR',
		'FORWARDED_FOR_IP',
		'HTTP_CLIENT_IP',
		'HTTP_FORWARDED',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED_FOR_IP',
		'HTTP_PC_REMOTE_ADDR',
		'HTTP_PROXY_CONNECTION',
		'HTTP_VIA',
		'HTTP_X_FORWARDED',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED_FOR_IP',
		'HTTP_X_IMFORWARDS',
		'HTTP_XROXY_CONNECTION',
		'VIA',
		'X_FORWARDED',
		'X_FORWARDED_FOR'
	);

	$remote_address = FALSE;
	foreach ( $headers as $header ) {
		if ( ! empty( $_SERVER[ $header ] ) ) {
			$remote_address = $_SERVER[ $header ];
			break;
		}
	}

	if ( ! $remote_address ) {
		$remote_address = $_SERVER['REMOTE_ADDR'];
	}

	// Extract address from list
	if ( strpos( $remote_address, ',' ) !== FALSE ) {
		$tmp            = explode( ',', $remote_address );
		$remote_address = trim( array_shift( $tmp ) );
	}

	// Remove port if exists (IPv4 only)
	$regEx = "/^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}$/";
	if ( preg_match( $regEx, $remote_address )
	     && ( $pos_temp = stripos( $remote_address, ':' ) ) !== FALSE
	) {
		$remote_address = substr( $remote_address, 0, $pos_temp );
	}

	return $remote_address;
}

/**
 * Filter data source by conditionals array
 *
 * @param array $source
 * @param array $conditionals
 * @param bool  $single
 *
 * @return array|bool
 */
function px_filter( array $source, array $conditionals, $single = TRUE ) {
	$data = array_filter( $source, function ( $data, $key ) use ( $conditionals ) {
		$status = TRUE;
		foreach ( $conditionals as $ckey => $cvalue ) {
			if ( ! isset( $data[ $ckey ] ) || $data[ $ckey ] != $cvalue ) {
				$status = FALSE;
				break;
			}
		}

		return $status;
	}, ARRAY_FILTER_USE_BOTH );

	if ( count( $data ) === 0 ) {
		return $single ? FALSE : array();
	}

	return $single ? array_shift( $data ) : $data;
}

/**
 * Get Payment Method Instance Of Order
 *
 * @param WC_Order|int $order
 *
 * @return false|WC_Payment_Gateway
 */
function px_payment_method_instance( $order ) {
	$order = wc_get_order( $order );

	if ( ! $order ) {
		return FALSE;
	}

	$payment_method = px_obj_prop( $order, 'payment_method' );

	// Get Payment Gateway
	$gateways = WC()->payment_gateways()->get_available_payment_gateways();

	if ( isset( $gateways[ $payment_method ] ) ) {
		return $gateways[ $payment_method ];
	}

	return FALSE;
}

/**
 * Get Payment Method Instance by ID
 *
 * @param $payment_id
 *
 * @return false|WC_Payment_Gateway
 */
function px_payment_method( $payment_id ) {
	// @todo Use payment_gateways() instead?
	$gateways = WC()->payment_gateways()->get_available_payment_gateways();

	return isset( $gateways[ $payment_id ] ) ? $gateways[ $payment_id ] : FALSE;
}

/**
 * Generate UUID
 *
 * @param string $node
 *
 * @return string
 */
function px_uuid( $node ) {
	return apply_filters( 'payex_generate_uuid', $node );
}

/**
 * Perform Capture Action
 *
 * @param WC_Order|int $order
 * @param              $amount
 *
 * @throws \Exception
 */
function px_capture_payment( $order, $amount = FALSE ) {
	if ( is_int( $order ) ) {
		$order = wc_get_order( $order );
	}

	/** @var WC_Payment_Gateway_Payex_Interface $gateway */
	$gateway = px_payment_method_instance( $order );
	if ( ! $gateway ) {
		throw new \Exception( __( 'Unable to get payment instance.', 'woocommerce-gateway-payex-psp' ) );
	}

	if ( ! method_exists( $gateway, 'capture_payment' ) ) {
		throw new \Exception( sprintf( __( 'Capture failure: %s', 'woocommerce-gateway-payex-psp' ), 'Payment method don\'t support this feature' ) );
	}

	if ( ! $gateway->can_capture( $order, $amount ) ) {
		throw new \Exception( __( 'Capture action is not available.', 'woocommerce-gateway-payex-psp' ) );
	}

	// Disable status change hook
	remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

	$gateway->capture_payment( $order, $amount );
}

/**
 * Perform Cancel Action
 *
 * @param WC_Order|int $order
 *
 * @throws \Exception
 */
function px_cancel_payment( $order ) {
	if ( is_int( $order ) ) {
		$order = wc_get_order( $order );
	}

	/** @var WC_Payment_Gateway_Payex_Interface $gateway */
	$gateway = px_payment_method_instance( $order );
	if ( ! $gateway ) {
		throw new \Exception( __( 'Unable to get payment instance.', 'woocommerce-gateway-payex-psp' ) );
	}

	if ( ! method_exists( $gateway, 'cancel_payment' ) ) {
		throw new \Exception( sprintf( __( 'Cancel failure: %s', 'woocommerce-gateway-payex-psp' ), 'Payment method don\'t support this feature' ) );
	}

	if ( ! $gateway->can_cancel( $order ) ) {
		throw new \Exception( __( 'Cancel action is not available.', 'woocommerce-gateway-payex-psp' ) );
	}

	// Disable status change hook
	remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

	$gateway->cancel_payment( $order );
}

/**
 * Perform Capture Action
 *
 * @param WC_Order|int $order
 * @param float|bool   $amount
 * @param string       $reason
 *
 * @throws \Exception
 */
function px_refund_payment( $order, $amount = FALSE, $reason = '' ) {
	if ( is_int( $order ) ) {
		$order = wc_get_order( $order );
	}

	/** @var WC_Payment_Gateway_Payex_Interface $gateway */
	$gateway = px_payment_method_instance( $order );
	if ( ! $gateway ) {
		throw new \Exception( __( 'Unable to get payment instance.', 'woocommerce-gateway-payex-psp' ) );
	}

	if ( ! method_exists( $gateway, 'refund_payment' ) ) {
		throw new \Exception( sprintf( __( 'Refund failure: %s', 'woocommerce-gateway-payex-psp' ), 'Payment method don\'t support this feature' ) );
	}

	if ( ! $gateway->can_refund( $order, $amount ) ) {
		throw new \Exception( __( 'Refund action is not available.', 'woocommerce-gateway-payex-psp' ) );
	}

	$gateway->refund_payment( $order, $amount, $reason );
}

