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
$gateway = new WC_Payex_Psp();

$log->add( $handler, 'Start upgrade....' );

// Install Schema
WC_Payex_Transactions::instance()->install_schema();
WC_Payex_Queue::instance()->install_schema();

$log->add( $handler, 'Upgrade has been completed!' );
