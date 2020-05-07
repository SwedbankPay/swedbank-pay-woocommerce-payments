<?php

namespace SwedbankPay\Payments\WooCommerce;

use SwedbankPay\Core\Log\LogLevel;
use WC_Payment_Gateway;
use SwedbankPay\Core\PaymentAdapter;
use SwedbankPay\Core\PaymentAdapterInterface;
use SwedbankPay\Core\ConfigurationInterface;
use SwedbankPay\Core\Order\PlatformUrlsInterface;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\OrderItemInterface;
use SwedbankPay\Core\Order\RiskIndicatorInterface;
use SwedbankPay\Core\Order\PayeeInfoInterface;

class Adapter extends PaymentAdapter implements PaymentAdapterInterface {
	/**
	 * @var WC_Payment_Gateway
	 */
	private $gateway;

	/**
	 * WC_Adapter constructor.
	 *
	 * @param WC_Payment_Gateway $gateway
	 */
	public function __construct( WC_Payment_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Log a message.
	 *
	 * @param $level
	 * @param $message
	 * @param array $context
	 *
	 * @see WC_Log_Levels
	 */
	public function log( $level, $message, array $context = [] ) {
		$logger = wc_get_logger();

		if ( ! is_string( $message ) ) {
			$message = var_export( $message, true );
		}

		$logger->log( \WC_Log_Levels::INFO, sprintf( '%s %s %s', $level, $message, var_export( $context, true ) ), [
			'source'  => $this->gateway->id,
			'_legacy' => true
		] );
	}

	/**
	 * Get Adapter Configuration.
	 *
	 * @return array
	 */
	public function getConfiguration() {
		// @todo Fix it: Undefined property
		return [
			ConfigurationInterface::DEBUG                  => @$this->gateway->debug === 'yes',
			ConfigurationInterface::MERCHANT_TOKEN         => @$this->gateway->merchant_token,
			ConfigurationInterface::PAYEE_ID               => @$this->gateway->payee_id,
			ConfigurationInterface::PAYEE_NAME             => get_bloginfo( 'name' ),
			ConfigurationInterface::MODE                   => @$this->gateway->testmode === 'yes',
			ConfigurationInterface::AUTO_CAPTURE           => @$this->gateway->auto_capture === 'yes',
			ConfigurationInterface::SUBSITE                => @$this->gateway->subsite,
			ConfigurationInterface::LANGUAGE               => @$this->gateway->culture,
			ConfigurationInterface::SAVE_CC                => @$this->gateway->save_cc === 'yes',
			ConfigurationInterface::TERMS_URL              => @$this->gateway->terms_url,
			ConfigurationInterface::REJECT_CREDIT_CARDS    => @$this->gateway->reject_credit_cards === 'yes',
			ConfigurationInterface::REJECT_DEBIT_CARDS     => @$this->gateway->reject_debit_cards === 'yes',
			ConfigurationInterface::REJECT_CONSUMER_CARDS  => @$this->gateway->reject_consumer_cards === 'yes',
			ConfigurationInterface::REJECT_CORPORATE_CARDS => @$this->gateway->reject_corporate_cards === 'yes',
		];
	}

	/**
	 * Get Platform Urls of Actions of Order (complete, cancel, callback urls).
	 *
	 * @param mixed $orderId
	 *
	 * @return array
	 */
	public function getPlatformUrls( $orderId ) {
		$order = wc_get_order( $orderId );

		if ( $this->gateway->is_new_credit_card ) {
			return [
				PlatformUrlsInterface::COMPLETE_URL => add_query_arg( 'action', 'swedbank_card_store',
					admin_url( 'admin-ajax.php' ) ),
				PlatformUrlsInterface::CANCEL_URL   => wc_get_account_endpoint_url( 'payment-methods' ),
				PlatformUrlsInterface::CALLBACK_URL => WC()->api_request_url( get_class( $this->gateway ) ),
				PlatformUrlsInterface::TERMS_URL    => ''
			];
		}

		if ( $this->gateway->is_change_credit_card ) {
			return [
				PlatformUrlsInterface::COMPLETE_URL => add_query_arg( [
					'verify' => 'true',
					'key'    => $order->get_order_key()
				], $this->gateway->get_return_url( $order ) ),
				PlatformUrlsInterface::CANCEL_URL   => $order->get_cancel_order_url_raw(),
				PlatformUrlsInterface::CALLBACK_URL => WC()->api_request_url( get_class( $this->gateway ) ),
				PlatformUrlsInterface::TERMS_URL    => $this->getConfiguration()[ ConfigurationInterface::TERMS_URL ]
			];
		}

		return [
			PlatformUrlsInterface::COMPLETE_URL => $this->gateway->get_return_url( $order ),
			PlatformUrlsInterface::CANCEL_URL   => $order->get_cancel_order_url_raw(),
			PlatformUrlsInterface::CALLBACK_URL => WC()->api_request_url( get_class( $this->gateway ) ),
			PlatformUrlsInterface::TERMS_URL    => $this->getConfiguration()[ ConfigurationInterface::TERMS_URL ]
		];
	}

	/**
	 * Get Order Data.
	 *
	 * @param mixed $orderId
	 *
	 * @return array
	 */
	public function getOrderData( $orderId ) {
		$order = wc_get_order( $orderId );

		$countries = WC()->countries->countries;
		$states    = WC()->countries->states;

		// Order Info
		$info = $this->get_order_info( $order );

		// Get order items
		$items = [];

		foreach ( $order->get_items() as $order_item ) {
			/** @var \WC_Order_Item_Product $order_item */
			$price        = $order->get_line_subtotal( $order_item, false, false );
			$priceWithTax = $order->get_line_subtotal( $order_item, true, false );
			$tax          = $priceWithTax - $price;
			$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;
			$qty          = $order_item->get_quantity();

			if ( $image = wp_get_attachment_image_src( $order_item->get_product()->get_image_id(), 'full' ) ) {
				$image = array_shift( $image );
			} else {
				$image = wc_placeholder_img_src( 'full' );
			}

			if ( null === parse_url( $image, PHP_URL_SCHEME ) &&
			     mb_substr( $image, 0, mb_strlen( WP_CONTENT_URL ), 'UTF-8' ) === WP_CONTENT_URL
			) {
				$image = wp_guess_url() . $image;
			}

			// Get Product Class
			$product_class = get_post_meta( $order_item->get_product()->get_id(), '_sb_product_class', true );
			if ( empty( $product_class ) ) {
				$product_class = 'ProductGroup1';
			}

			// Get Product Sku
			$productReference = trim( str_replace( [ ' ', '.', ',' ], '-', $order_item->get_product()->get_sku() ) );
			if ( empty( $productReference ) ) {
				$productReference = wp_generate_password( 12, false );
			}

			$productName = trim( $order_item->get_name() );

			$items[] = [
				// The field Reference must match the regular expression '[\\w-]*'
				OrderItemInterface::FIELD_REFERENCE   => $productReference,
				OrderItemInterface::FIELD_NAME        => ! empty( $productName ) ? $productName : '-',
				OrderItemInterface::FIELD_TYPE        => OrderItemInterface::TYPE_PRODUCT,
				OrderItemInterface::FIELD_CLASS       => $product_class,
				OrderItemInterface::FIELD_ITEM_URL    => $order_item->get_product()->get_permalink(),
				OrderItemInterface::FIELD_IMAGE_URL   => $image,
				OrderItemInterface::FIELD_DESCRIPTION => $order_item->get_name(),
				OrderItemInterface::FIELD_QTY         => $qty,
				OrderItemInterface::FIELD_QTY_UNIT    => 'pcs',
				OrderItemInterface::FIELD_UNITPRICE   => round( $priceWithTax / $qty * 100 ),
				OrderItemInterface::FIELD_VAT_PERCENT => round( $taxPercent * 100 ),
				OrderItemInterface::FIELD_AMOUNT      => round( $priceWithTax * 100 ),
				OrderItemInterface::FIELD_VAT_AMOUNT  => round( $tax * 100 )
			];
		}

		// Add Shipping Line
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping        = $order->get_shipping_total();
			$tax             = $order->get_shipping_tax();
			$shippingWithTax = $shipping + $tax;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;
			$shippingMethod  = trim( $order->get_shipping_method() );

			$items[] = [
				OrderItemInterface::FIELD_REFERENCE   => 'shipping',
				OrderItemInterface::FIELD_NAME        => ! empty( $shippingMethod ) ? $shippingMethod : __( 'Shipping',
					'woocommerce' ),
				OrderItemInterface::FIELD_TYPE        => OrderItemInterface::TYPE_SHIPPING,
				OrderItemInterface::FIELD_CLASS       => 'ProductGroup1',
				OrderItemInterface::FIELD_QTY         => 1,
				OrderItemInterface::FIELD_QTY_UNIT    => 'pcs',
				OrderItemInterface::FIELD_UNITPRICE   => round( $shippingWithTax * 100 ),
				OrderItemInterface::FIELD_VAT_PERCENT => round( $taxPercent * 100 ),
				OrderItemInterface::FIELD_AMOUNT      => round( $shippingWithTax * 100 ),
				OrderItemInterface::FIELD_VAT_AMOUNT  => round( $tax * 100 )
			];
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			/** @var \WC_Order_Item_Fee $order_fee */
			$fee        = $order_fee->get_total();
			$tax        = $order_fee->get_total_tax();
			$feeWithTax = $fee + $tax;
			$taxPercent = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

			$items[] = [
				OrderItemInterface::FIELD_REFERENCE   => 'fee',
				OrderItemInterface::FIELD_NAME        => $order_fee->get_name(),
				OrderItemInterface::FIELD_TYPE        => OrderItemInterface::TYPE_OTHER,
				OrderItemInterface::FIELD_CLASS       => 'ProductGroup1',
				OrderItemInterface::FIELD_QTY         => 1,
				OrderItemInterface::FIELD_QTY_UNIT    => 'pcs',
				OrderItemInterface::FIELD_UNITPRICE   => round( $feeWithTax * 100 ),
				OrderItemInterface::FIELD_VAT_PERCENT => round( $taxPercent * 100 ),
				OrderItemInterface::FIELD_AMOUNT      => round( $feeWithTax * 100 ),
				OrderItemInterface::FIELD_VAT_AMOUNT  => round( $tax * 100 )
			];
		}

		// Add discount line
		if ( $order->get_total_discount( false ) > 0 ) {
			$discount        = abs( $order->get_total_discount( true ) );
			$discountWithTax = abs( $order->get_total_discount( false ) );
			$tax             = $discountWithTax - $discount;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$items[] = [
				OrderItemInterface::FIELD_REFERENCE   => 'discount',
				OrderItemInterface::FIELD_NAME        => __( 'Discount', \WC_Swedbank_Pay::TEXT_DOMAIN ),
				OrderItemInterface::FIELD_TYPE        => OrderItemInterface::TYPE_DISCOUNT,
				OrderItemInterface::FIELD_CLASS       => 'ProductGroup1',
				OrderItemInterface::FIELD_QTY         => 1,
				OrderItemInterface::FIELD_QTY_UNIT    => 'pcs',
				OrderItemInterface::FIELD_UNITPRICE   => round( - 100 * $discountWithTax ),
				OrderItemInterface::FIELD_VAT_PERCENT => round( 100 * $taxPercent ),
				OrderItemInterface::FIELD_AMOUNT      => round( - 100 * $discountWithTax ),
				OrderItemInterface::FIELD_VAT_AMOUNT  => round( - 100 * $tax )
			];
		}

		// Payer reference
		// Get Customer UUID
		$user_id = $order->get_customer_id();
		if ( $user_id > 0 ) {
			$payerReference = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $payerReference ) ) {
				$payerReference = $this->get_uuid( $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $payerReference );
			}
		} else {
			$payerReference = $this->get_uuid( uniqid( $order->get_billing_email() ) );
		}

		return [
			OrderInterface::ORDER_ID              => $order->get_id(),
			OrderInterface::AMOUNT                => apply_filters( 'swedbank_pay_order_amount', $order->get_total(),
				$order ),
			OrderInterface::VAT_AMOUNT            => apply_filters( 'swedbank_pay_order_vat', $info['vat_amount'],
				$order ),
			OrderInterface::VAT_RATE              => 0, // @todo
			OrderInterface::SHIPPING_AMOUNT       => 0, // @todo
			OrderInterface::SHIPPING_VAT_AMOUNT   => 0, // @todo
			OrderInterface::DESCRIPTION           => sprintf( __( 'Order #%s', \WC_Swedbank_Pay::TEXT_DOMAIN ),
				$order->get_order_number() ),
			OrderInterface::CURRENCY              => $order->get_currency(),
			OrderInterface::STATUS                => $order->get_status(),
			OrderInterface::CREATED_AT            => date( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ),
			OrderInterface::PAYMENT_ID            => $order->get_meta( '_payex_payment_id' ),
			OrderInterface::PAYMENT_ORDER_ID      => $order->get_meta( '_payex_paymentorder_id' ),
			OrderInterface::NEEDS_SAVE_TOKEN_FLAG => $order->get_meta( '_payex_generate_token' ) === '1' &&
			                                         count( $order->get_payment_tokens() ) === 0,

			OrderInterface::HTTP_ACCEPT           => isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : null,
			OrderInterface::HTTP_USER_AGENT       => $order->get_customer_user_agent(),
			OrderInterface::BILLING_COUNTRY       => $countries[ $order->get_billing_country() ],
			OrderInterface::BILLING_COUNTRY_CODE  => $order->get_billing_country(),
			OrderInterface::BILLING_ADDRESS1      => $order->get_billing_address_1(),
			OrderInterface::BILLING_ADDRESS2      => $order->get_billing_address_2(),
			OrderInterface::BILLING_ADDRESS3      => null,
			OrderInterface::BILLING_CITY          => $order->get_billing_city(),
			OrderInterface::BILLING_STATE         => $order->get_billing_state(),
			OrderInterface::BILLING_POSTCODE      => $order->get_billing_postcode(),
			OrderInterface::BILLING_PHONE         => $order->get_billing_phone(),
			OrderInterface::BILLING_EMAIL         => $order->get_billing_email(),
			OrderInterface::BILLING_FIRST_NAME    => $order->get_billing_first_name(),
			OrderInterface::BILLING_LAST_NAME     => $order->get_billing_last_name(),
			OrderInterface::SHIPPING_COUNTRY      => $countries[ $order->get_shipping_country() ],
			OrderInterface::SHIPPING_COUNTRY_CODE => $order->get_shipping_country(),
			OrderInterface::SHIPPING_ADDRESS1     => $order->get_shipping_address_1(),
			OrderInterface::SHIPPING_ADDRESS2     => $order->get_shipping_address_2(),
			OrderInterface::SHIPPING_ADDRESS3     => null,
			OrderInterface::SHIPPING_CITY         => $order->get_shipping_city(),
			OrderInterface::SHIPPING_STATE        => $order->get_shipping_state(),
			OrderInterface::SHIPPING_POSTCODE     => $order->get_shipping_postcode(),
			OrderInterface::SHIPPING_PHONE        => $order->get_billing_phone(),
			OrderInterface::SHIPPING_EMAIL        => $order->get_billing_email(),
			OrderInterface::SHIPPING_FIRST_NAME   => $order->get_shipping_first_name(),
			OrderInterface::SHIPPING_LAST_NAME    => $order->get_shipping_last_name(),
			OrderInterface::CUSTOMER_ID           => (int) $order->get_customer_id(),
			OrderInterface::CUSTOMER_IP           => $order->get_customer_ip_address(),
			OrderInterface::PAYER_REFERENCE       => $payerReference,
			OrderInterface::ITEMS                 => apply_filters( 'swedbank_pay_order_items', $items, $order ),
			OrderInterface::LANGUAGE              => $this->getConfiguration()[ ConfigurationInterface::LANGUAGE ],
		];
	}

	/**
	 * Get Risk Indicator of Order.
	 *
	 * @param mixed $orderId
	 *
	 * @return array
	 */
	public function getRiskIndicator( $orderId ) {
		$order = wc_get_order( $orderId );

		$result = [];

		// Downloadable
		if ( $order->has_downloadable_item() ) {
			// For electronic delivery, the email address to which the merchandise was delivered
			$result[ RiskIndicatorInterface::DELIVERY_EMAIL_ADDRESS ] = $order->get_billing_email();

			// Electronic Delivery
			$result[ RiskIndicatorInterface::DELIVERY_TIME_FRAME_INDICATOR ] = '01';

			// Digital goods, includes online services, electronic giftcards and redemption codes
			$result[ RiskIndicatorInterface::SHIP_INDICATOR ] = '05';
		}

		// Shippable
		if ( $order->needs_processing() ) {
			// Two-day or more shipping
			$result['deliveryTimeFrameIndicator'] = '04';

			// Compare billing and shipping addresses
			$billing  = $order->get_address( 'billing' );
			$shipping = $order->get_address( 'shipping' );
			$diff     = array_diff( $billing, $shipping );
			if ( count( $diff ) === 0 ) {
				// Ship to cardholder's billing address
				$result[ RiskIndicatorInterface::SHIP_INDICATOR ] = '01';
			} else {
				// Ship to address that is different than cardholder's billing address
				$result[ RiskIndicatorInterface::SHIP_INDICATOR ] = '03';
			}
		}

		// @todo Add features of WooThemes Order Delivery and Pre-Orders WooCommerce Extensions

		return apply_filters( 'swedbank_pay_risk_indicator', $result, $order, $this );
	}

	/**
	 * Get Payee Info of Order.
	 *
	 * @param mixed $orderId
	 *
	 * @return array
	 */
	public function getPayeeInfo( $orderId ) {
		$order = wc_get_order( $orderId );

		return [
			PayeeInfoInterface::ORDER_REFERENCE => $order->get_id(),
		];
	}

	/**
	 * Update Order Status.
	 *
	 * @param mixed $orderId
	 * @param string $status
	 * @param string|null $message
	 * @param mixed|null $transactionId
	 */
	public function updateOrderStatus( $orderId, $status, $message = null, $transactionId = null ) {
		$order = wc_get_order( $orderId );

		if ( $order->get_meta( '_payex_payment_state' ) === $status ) {
			$this->log( LogLevel::WARNING, sprintf( 'Action of Transaction #%s already performed', $transactionId ) );

			return;
		}

		if ( $transactionId ) {
			$order->update_meta_data( '_transaction_id', $transactionId );
			$order->save_meta_data();
		}

		switch ( $status ) {
			case OrderInterface::STATUS_PENDING:
				$order->update_meta_data( '_payex_payment_state', $status );
				$order->update_status( 'on-hold', $message );
				break;
			case OrderInterface::STATUS_AUTHORIZED:
				$order->update_meta_data( '_payex_payment_state', $status );
				$order->save_meta_data();

				// Reduce stock
				$order_stock_reduced = $order->get_meta( '_order_stock_reduced' );
				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order->get_id() );
				}

				$order->update_status( 'on-hold', $message );

				break;
			case OrderInterface::STATUS_CAPTURED:
				$order->update_meta_data( '_payex_payment_state', $status );
				$order->save_meta_data();

				$order->payment_complete( $transactionId );
				$order->add_order_note( $message );
				break;
			case OrderInterface::STATUS_CANCELLED:
				$order->update_meta_data( '_payex_payment_state', $status );
				$order->save_meta_data();

				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', $message );
				} else {
					$order->add_order_note( $message );
				}
				break;
			case OrderInterface::STATUS_REFUNDED:
				// @todo Implement Refunds creation
				// @see wc_create_refund()

				$order->update_meta_data( '_payex_payment_state', $status );
				$order->save_meta_data();

				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', $message );
				} else {
					$order->add_order_note( $message );
				}

				break;
			case OrderInterface::STATUS_FAILED:
				$order->update_status( 'failed', $message );
				break;
		}
	}

	/**
	 * Save Transaction data.
	 *
	 * @param mixed $orderId
	 * @param array $transactionData
	 */
	public function saveTransaction( $orderId, array $transactionData = [] ) {
		$this->gateway->transactions->import( $transactionData, $orderId );
	}

	/**
	 * Find for Transaction.
	 *
	 * @param $field
	 * @param $value
	 *
	 * @return array
	 */
	public function findTransaction( $field, $value ) {
		return $this->gateway->transactions->get_by( $field, $value, true );
	}

	/**
	 * Save Payment Token.
	 *
	 * @param mixed $customerId
	 * @param string $paymentToken
	 * @param string $recurrenceToken
	 * @param string $cardBrand
	 * @param string $maskedPan
	 * @param string $expiryDate
	 * @param mixed|null $orderId
	 */
	public function savePaymentToken(
		$customerId,
		$paymentToken,
		$recurrenceToken,
		$cardBrand,
		$maskedPan,
		$expiryDate,
		$orderId = null
	) {
		$expiryDate = explode( '/', $expiryDate );

		// Create Payment Token
		$token = new \WC_Payment_Token_Swedbank_Pay();
		$token->set_gateway_id( $this->gateway->id );
		$token->set_token( $paymentToken );
		$token->set_recurrence_token( $recurrenceToken );
		$token->set_last4( substr( $maskedPan, - 4 ) );
		$token->set_expiry_year( $expiryDate[1] );
		$token->set_expiry_month( $expiryDate[0] );
		$token->set_card_type( strtolower( $cardBrand ) );
		$token->set_user_id( $customerId );
		$token->set_masked_pan( $maskedPan );
		$token->save();
		if ( ! $token->get_id() ) {
			throw new \Exception( __( 'There was a problem adding the card.', \WC_Swedbank_Pay::TEXT_DOMAIN ) );
		}

		// Add payment token
		if ( $orderId ) {
			$order = wc_get_order( $orderId );
			$order->add_payment_token( $token );
		}
	}

	/**
	 * Get Order Lines
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	private function get_order_items( $order ) {
		$item = [];

		foreach ( $order->get_items() as $order_item ) {
			/** @var \WC_Order_Item_Product $order_item */
			$price        = $order->get_line_subtotal( $order_item, false, false );
			$priceWithTax = $order->get_line_subtotal( $order_item, true, false );
			$tax          = $priceWithTax - $price;
			$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

			$item[] = [
				'type'              => 'product',
				'name'              => $order_item->get_name(),
				'qty'               => $order_item->get_quantity(),
				'price_with_tax'    => sprintf( "%.2f", $priceWithTax ),
				'price_without_tax' => sprintf( "%.2f", $price ),
				'tax_price'         => sprintf( "%.2f", $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			];
		};

		// Add Shipping Line
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping        = $order->get_shipping_total();
			$tax             = $order->get_shipping_tax();
			$shippingWithTax = $shipping + $tax;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

			$item[] = [
				'type'              => 'shipping',
				'name'              => $order->get_shipping_method(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $shippingWithTax ),
				'price_without_tax' => sprintf( "%.2f", $shipping ),
				'tax_price'         => sprintf( "%.2f", $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			];
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			/** @var \WC_Order_Item_Fee $order_fee */
			$fee        = $order_fee->get_total();
			$tax        = $order_fee->get_total_tax();
			$feeWithTax = $fee + $tax;
			$taxPercent = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

			$item[] = [
				'type'              => 'fee',
				'name'              => $order_fee->get_name(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", $feeWithTax ),
				'price_without_tax' => sprintf( "%.2f", $fee ),
				'tax_price'         => sprintf( "%.2f", $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			];
		}

		// Add discount line
		if ( $order->get_total_discount( false ) > 0 ) {
			$discount        = $order->get_total_discount( true );
			$discountWithTax = $order->get_total_discount( false );
			$tax             = $discountWithTax - $discount;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$item[] = [
				'type'              => 'discount',
				'name'              => __( 'Discount', \WC_Swedbank_Pay::TEXT_DOMAIN ),
				'qty'               => 1,
				'price_with_tax'    => sprintf( "%.2f", - 1 * $discountWithTax ),
				'price_without_tax' => sprintf( "%.2f", - 1 * $discount ),
				'tax_price'         => sprintf( "%.2f", - 1 * $tax ),
				'tax_percent'       => sprintf( "%.2f", $taxPercent )
			];
		}

		return $item;
	}

	/**
	 * Get Order Info
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	private function get_order_info( $order ) {
		$amount       = 0;
		$vatAmount    = 0;
		$descriptions = [];
		$items        = $this->get_order_items( $order );
		foreach ( $items as $item ) {
			$amount         += $item['price_with_tax'];
			$vatAmount      += $item['tax_price'];
			$descriptions[] = [
				'amount'      => $item['price_with_tax'],
				'vatAmount'   => $item['tax_price'], // @todo Validate
				'itemAmount'  => sprintf( "%.2f", $item['price_with_tax'] / $item['qty'] ),
				'quantity'    => $item['qty'],
				'description' => $item['name']
			];
		}

		return [
			'amount'     => $amount,
			'vat_amount' => $vatAmount,
			'items'      => $descriptions
		];
	}

	/**
	 * Generate UUID
	 *
	 * @param $node
	 *
	 * @return string
	 */
	private function get_uuid( $node ) {
		//return \Ramsey\Uuid\Uuid::uuid5( \Ramsey\Uuid\Uuid::NAMESPACE_OID, $node )->toString();
		return apply_filters( 'swedbank_pay_generate_uuid', $node );
	}
}

