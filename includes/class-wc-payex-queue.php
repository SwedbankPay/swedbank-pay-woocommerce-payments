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
  `payment_id` varchar(255) DEFAULT NULL COMMENT 'Payment ID',
  `payment_number` varchar(255) DEFAULT NULL COMMENT 'Transaction Number',
  `transaction_id` varchar(255) DEFAULT NULL COMMENT 'Transaction ID',
  `transaction_number` varchar(255) DEFAULT NULL COMMENT 'Transaction Number',
  `webhook_data` text COMMENT 'WebHook data in JSON',
  `created_at` datetime DEFAULT NULL COMMENT 'Incoming date',
  `processed` tinyint(4) DEFAULT '0' COMMENT 'Is Processed',
  `processed_at` datetime DEFAULT NULL COMMENT 'Processing date',
  `payment_method_id` varchar(255) DEFAULT NULL COMMENT 'Payment Method ID',
  `order_id` varchar(255) DEFAULT NULL COMMENT 'Order ID',
  PRIMARY KEY (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$wpdb->charset};
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

		// Get Order by Payment Id
		$order_id = px_get_post_id_by_meta( '_payex_payment_id', $data['payment']['id'] );

		$result = $wpdb->insert( $wpdb->prefix . 'payex_queue', array(
			'payment_id' => $data['payment']['id'],
			'payment_number' => $data['payment']['number'],
			'transaction_id' => $data['transaction']['id'],
			'transaction_number' => $data['transaction']['number'],
			'webhook_data' => $raw_body,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() ),
			'processed' => 0,
			'payment_method_id' => $payment_method_id,
			'order_id' => $order_id ?: $order_id
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
	 * @return array
	 */
	public function getQueue()
	{
		global $wpdb;

		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}payex_queue WHERE processed = %d ORDER BY transaction_number ASC;", 0 );
		return $wpdb->get_results( $query, ARRAY_A );
	}

}
