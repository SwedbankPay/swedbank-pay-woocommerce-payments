<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set PHP Settings
set_time_limit( 0 );
ini_set( 'memory_limit', '2048M' );

// Logger
$log     = new WC_Logger();
$handler = 'wc-payex-psp-update';

// Gateway
$gateway = new WC_Swedbank_Psp();

$log->add( $handler, 'Start upgrade....' );

// Install Schema
WC_Swedbank_Transactions::instance()->install_schema();
WC_Swedbank_Queue::instance()->install_schema();

$log->add( $handler, 'Upgrade has been completed!' );
