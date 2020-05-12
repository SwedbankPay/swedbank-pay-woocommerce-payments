<?php

use SwedbankPay\Payments\WooCommerce\WC_Background_Swedbank_Pay_Queue;

defined( 'ABSPATH' ) || exit;

// Set PHP Settings
set_time_limit( 0 );
ini_set( 'memory_limit', '2048M' );

// Logger
$log     = new WC_Logger();
$handler = 'wc-payex-psp-update';

// Gateway
$gateway = new WC_Swedbank_Pay();

$log->add( $handler, 'Start upgrade....' );

global $wpdb;

$background_process = new WC_Background_Swedbank_Pay_Queue();

// phpcs:disable
$results = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}payex_queue WHERE processed = %d ORDER BY transaction_number ASC;", 0 ),
	ARRAY_A
);
// phpcs:enable

foreach ( $results as $result ) {
	$background_process->push_to_queue(
		array(
			'payment_method_id' => $result['payment_method_id'],
			'webhook_data'      => $result['webhook_data'],
		)
	);
	$background_process->save();

	$log->add( $handler, sprintf( 'Task %s enqueued', $result['queue_id'] ) );
}

$log->add( $handler, 'Upgrade has been completed!' );
