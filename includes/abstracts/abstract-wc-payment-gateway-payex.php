<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WC_Payment_Gateway_Payex extends WC_Payment_Gateway
	implements WC_Payment_Gateway_Payex_Interface {

	/**
	 * @var \WC_Payex_Transactions
	 */
	public $transactions;

	/**
	 * Merchant Token
	 * @var string
	 */
	public $merchant_token = '';

	/**
	 * Debug Mode
	 * @var string
	 */
	public $debug = 'no';

	/**
	 * Backend Api Endpoint
	 * @var string
	 */
	public $backend_api_endpoint = 'https://api.payex.com';

	/**
	 * Debug Log
	 *
	 * @param $message
	 * @param $level
	 *
	 * @see WC_Log_Levels
	 *
	 * @return void
	 */
	protected function log( $message, $level = 'notice' ) {
		// Is Enabled
		if ( $this->debug !== 'yes' ) {
			return;
		}

		// Get Logger instance
		$log = new WC_Logger();

		// Write message to log
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, TRUE );
		}

		if ( $this->is_wc3() ) {
			$log->log( $level, $message, array(
				'source'  => $this->id,
				'_legacy' => TRUE
			) );
		} else {
			$log->add( $this->id, sprintf( '[%s] %s', $level, $message ) );
		}
	}

	/**
	 * Do API Request
	 *
	 * @param       $method
	 * @param       $url
	 * @param array $params
	 *
	 * @return array|mixed|object
	 * @throws \Exception
	 */
	public function request( $method, $url, $params = array() ) {
		if ( mb_substr( $url, 0, 1, 'UTF-8' ) === '/' ) {
			$url = $this->backend_api_endpoint . $url;
		}

		if ( $this->debug === 'yes' ) {
			$this->log( sprintf( '%s %s %s', $method, $url, 'Data: ' . var_export( $params, TRUE ) ) );
		}

		// Session ID
		$session_id = px_uuid( uniqid() );

		// Get Payment URL
		try {
			$client  = new GuzzleHttp\Client();
			$headers = array(
				'Accept'        => 'application/json',
				'Session-Id'    => $session_id,
				'Forwarded'     => $_SERVER['REMOTE_ADDR'],
				'Authorization' => 'Bearer ' . $this->merchant_token
			);

			$response = $client->request( $method, $url, count( $params ) > 0 ? array(
				'json'    => $params,
				'headers' => $headers
			) : array( 'headers' => $headers ) );

			if ( $this->debug === 'yes' ) {
				$this->log( sprintf( 'Status code: %s', $response->getStatusCode() ) );
			}

			if ( floor( $response->getStatusCode() / 100 ) != 2 ) {
				throw new Exception( 'Request failed. Status code: ' . $response->getStatusCode() );
			}

			$response = $response->getBody()->getContents();

			$result = @json_decode( $response, TRUE );
			if ( ! $result ) {
				$result = $response;
			}

			if ( $this->debug === 'yes' ) {
				$this->log( sprintf( 'Response: %s', var_export( $result, TRUE ) ) );
			}

			return $result;
		} catch ( GuzzleHttp\Exception\ClientException $e ) {
			$response             = $e->getResponse();
			$responseBodyAsString = $response->getBody()->getContents();

			if ( $this->debug === 'yes' ) {
				$this->log( sprintf( 'ClientException: %s. URL: %s, Params: %s', $responseBodyAsString, $url, var_export( $params, TRUE ) ) );
			}

			throw new Exception( $responseBodyAsString );
		} catch ( GuzzleHttp\Exception\ServerException $e ) {
			$response             = $e->getResponse();
			$responseBodyAsString = $response->getBody()->getContents();

			if ( $this->debug === 'yes' ) {
				$this->log( sprintf( 'ServerException: %s. URL: %s, Params: %s', $responseBodyAsString, $url, var_export( $params, TRUE ) ) );
			}

			throw new Exception( $responseBodyAsString );
		} catch ( Exception $e ) {
			if ( $this->debug === 'yes' ) {
				$this->log( sprintf( 'Exception: %s. URL: %s, Params: %s', $e->getMessage(), $url, var_export( $params, TRUE ) ) );
			}

			throw $e;
		}
	}

	/**
	 * Finds an Order based on an order key.
	 *
	 * @param $order_key
	 *
	 * @return bool|WC_Order
	 */
	protected function get_order_by_order_key( $order_key ) {
		$order_id = wc_get_order_id_by_order_key( $order_key );
		if ( $order_id ) {
			return wc_get_order( $order_id );
		}

		return FALSE;
	}

	/**
	 * Get Post Id by Meta
	 *
	 * @param $key
	 * @param $value
	 *
	 * @return null|string
	 */
	protected function get_post_id_by_meta( $key, $value ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s;", $key, $value ) );
	}


	/**
	 * Check is WooCommerce >= 3.0
	 * @return bool
	 */
	public function is_wc3() {
		return version_compare( WC()->version, '3.0', '>=' );
	}

	/**
	 * Get Order Lines
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	protected function get_order_items( $order ) {
		$item = array();

		// WooCommerce 3
		if ( $this->is_wc3() ) {
			foreach ( $order->get_items() as $order_item ) {
				/** @var WC_Order_Item_Product $order_item */
				$price        = $order->get_line_subtotal( $order_item, FALSE, FALSE );
				$priceWithTax = $order->get_line_subtotal( $order_item, TRUE, FALSE );
				$tax          = $priceWithTax - $price;
				$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'product',
					'name'              => $order_item->get_name(),
					'qty'               => $order_item->get_quantity(),
					'price_with_tax'    => sprintf( "%.2f", $priceWithTax ),
					'price_without_tax' => sprintf( "%.2f", $price ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			};

			// Add Shipping Line
			if ( (float) $order->get_shipping_total() > 0 ) {
				$shipping        = $order->get_shipping_total();
				$tax             = $order->get_shipping_tax();
				$shippingWithTax = $shipping + $tax;
				$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'shipping',
					'name'              => $order->get_shipping_method(),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", $shippingWithTax ),
					'price_without_tax' => sprintf( "%.2f", $shipping ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			// Add fee lines
			foreach ( $order->get_fees() as $order_fee ) {
				/** @var WC_Order_Item_Fee $order_fee */
				$fee        = $order_fee->get_total();
				$tax        = $order_fee->get_total_tax();
				$feeWithTax = $fee + $tax;
				$taxPercent = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'fee',
					'name'              => $order_fee->get_name(),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", $feeWithTax ),
					'price_without_tax' => sprintf( "%.2f", $fee ),
					'tax_price'         => sprintf( "%.2f", $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			// Add discount line
			if ( $order->get_total_discount( FALSE ) > 0 ) {
				$discount        = $order->get_total_discount( TRUE );
				$discountWithTax = $order->get_total_discount( FALSE );
				$tax             = $discountWithTax - $discount;
				$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

				$item[] = array(
					'type'              => 'discount',
					'name'              => __( 'Discount', 'woocommerce-gateway-payex-checkout' ),
					'qty'               => 1,
					'price_with_tax'    => sprintf( "%.2f", - 1 * $discountWithTax ),
					'price_without_tax' => sprintf( "%.2f", - 1 * $discount ),
					'tax_price'         => sprintf( "%.2f", - 1 * $tax ),
					'tax_percent'       => sprintf( "%.2f", $taxPercent )
				);
			}

			return $item;
		}

		// WooCommerce 2.6
		foreach ( $order->get_items() as $order_item ) {
			$price        = $order->get_line_subtotal( $order_item, FALSE, FALSE );
			$priceWithTax = $order->get_line_subtotal( $order_item, TRUE, FALSE );
			$tax          = $priceWithTax - $price;
			$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'product',
				'name'              => $order_item['name'],
				'qty'               => $order_item['qty'],
				'price_with_tax'    => sprintf( "%.2f", $priceWithTax ),
				'price_without_tax' => sprintf( "%.2f", $price ),
				'tax_price'         => sprintf( "%.2f", $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		};

		// Add Shipping Line
		if ( (float) $order->order_shipping > 0 ) {
			$taxPercent = ( $order->order_shipping_tax > 0 ) ? round( 100 / ( $order->order_shipping / $order->order_shipping_tax ) ) : 0;

			$item[] = array(
				'type'              => 'shipping',
				'name'              => $order->get_shipping_method(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $order->order_shipping + $order->order_shipping_tax ),
				'price_without_tax' => sprintf( "%.2f", $order->order_shipping ),
				'tax_price'         => sprintf( "%.2f", $order->order_shipping_tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			$taxPercent = ( $order_fee['line_tax'] > 0 ) ? round( 100 / ( $order_fee['line_total'] / $order_fee['line_tax'] ) ) : 0;

			$item[] = array(
				'type'              => 'fee',
				'name'              => $order_fee['name'],
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $order_fee['line_total'] + $order_fee['line_tax'] ),
				'price_without_tax' => sprintf( "%.2f", $order_fee['line_total'] ),
				'tax_price'         => sprintf( "%.2f", $order_fee['line_tax'] ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		// Add discount line
		if ( $order->get_total_discount( FALSE ) > 0 ) {
			$discount        = $order->get_total_discount( TRUE );
			$discountWithTax = $order->get_total_discount( FALSE );
			$tax             = $discountWithTax - $discount;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'discount',
				'name'              => __( 'Discount', 'woocommerce-gateway-payex-checkout' ),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", - 1 * $discountWithTax ),
				'price_without_tax' => sprintf( "%.2f", - 1 * $discount ),
				'tax_price'         => sprintf( "%.2f", - 1 * $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			);
		}

		return $item;
	}

	/**
	 * Get Order Info
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	protected function get_order_info( $order ) {
		$amount       = 0;
		$vatAmount    = 0;
		$descriptions = array();
		$items        = $this->get_order_items( $order );
		foreach ( $items as $item ) {
			$amount         += $item['price_with_tax'];
			$vatAmount      += $item['tax_price'];
			$descriptions[] = array(
				'amount'      => $item['price_with_tax'],
				'vatAmount'   => $item['tax_price'], // @todo Validate
				'itemAmount'  => sprintf( "%.2f", $item['price_with_tax'] / $item['qty'] ),
				'quantity'    => $item['qty'],
				'description' => $item['name']
			);
		}

		return array(
			'amount'     => $amount,
			'vat_amount' => $vatAmount,
			'items'      => $descriptions
		);
	}

	/**
	 * Extract operation value from operations list
	 *
	 * @param array  $operations
	 * @param string $operation_id
	 * @param bool   $single
	 *
	 * @return bool|string|array
	 */
	protected static function get_operation( $operations, $operation_id, $single = TRUE ) {
		$operation = array_filter( $operations, function ( $value, $key ) use ( $operation_id ) {
			return ( is_array( $value ) && $value['rel'] === $operation_id );
		}, ARRAY_FILTER_USE_BOTH );

		if ( count( $operation ) > 0 ) {
			$operation = array_shift( $operation );

			return $single ? $operation['href'] : $operation;
		}

		return FALSE;
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param  int    $order_id
	 * @param  float  $amount
	 * @param  string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return FALSE;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			$amount = $order->get_total();
		}

		try {
			px_refund_payment( $order, $amount, $reason );

			return TRUE;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund', $e->getMessage() );
		}
	}
}
