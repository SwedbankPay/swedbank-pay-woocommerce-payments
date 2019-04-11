<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Payex_Queue {
	/**
	 * The single instance of the class.
	 *
	 * @var WC_Payex_Queue
	 */
	protected static $_instance = NULL;

	/**
	 * Instance.
	 *
	 * @static
	 * @return WC_Payex_Queue
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Construct is forbidden.
	 */
	private function __construct() {
		/* ... @return Singleton */
	}

	/**
	 * Cloning is forbidden.
	 */
	private function __clone() {
		/* ... @return Singleton */
	}

	/**
	 * Wakeup is forbidden.
	 */
	private function __wakeup() {
		/* ... @return Singleton */
	}

	/**
	 * Install DB Schema
	 */
	public function install_schema() {
		global $wpdb;
		$query = "
CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}payex_queue` (
  `queue_id` int(11) NOT NULL AUTO_INCREMENT,
  `number` varchar(255) DEFAULT NULL COMMENT 'Transaction Number',
  `transation_id` varchar(50) DEFAULT NULL COMMENT 'Transaction ID',
  `transaction_data` text COMMENT 'Transaction Data',
  `created_at` datetime DEFAULT NULL COMMENT 'Incoming date',
  `processed_at` datetime DEFAULT NULL COMMENT 'Processed date',
  `processed` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`queue_id`),
  UNIQUE KEY `number` (`number`)
) ENGINE=INNODB DEFAULT CHARSET={$wpdb->charset};
		";

		$wpdb->query( $query );
	}

	/**
	 * Enqueue
	 * @param string $raw_body
	 * @param string $payment_method_id
	 *
	 * @return bool|int
	 */
	public function enqueue( $raw_body, $payment_method_id )
	{
		global $wpdb;

		$data = @json_decode( $raw_body, TRUE );

		$result = $wpdb->insert( $wpdb->prefix . 'payex_queue', array(
			'payment_id' => $data['payment']['id'],
			'payment_number' => $data['payment']['number'],
			'transaction_id' => $data['transaction']['id'],
			'transaction_number' => $data['transaction']['number'],
			'webhook_data' => $raw_body,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() ),
			'processed' => 0,
			'payment_method_id' => $payment_method_id
		) );

		if ( $result > 0 ) {
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Mark queue's entry as processed
	 * @param $queue_id
	 *
	 * @return false|int
	 */
	public function setProcessed($queue_id)
	{
		global $wpdb;

		return $wpdb->update(
			$wpdb->prefix . 'payex_queue',
			array(
				'processed' => 1,
				'processed_at' => gmdate( 'Y-m-d H:i:s', time() ),
			),
			array(
				'queue_id' => (int) $queue_id
			)
		);
	}

	/**
	 * Get Unprocessed entries from Queue
	 * @return array|object|null
	 */
	public function getQueue()
	{
		global $wpdb;

		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}payex_queue WHERE processed = %d ORDER BY created_at ASC;", 0 );
		return $wpdb->get_results( $query, ARRAY_A );
	}

}
