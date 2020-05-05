<?php
/** @var WC_Gateway_Swedbank_Pay_Cc $gateway */
/** @var WC_Order $order */
/** @var int $order_id */
/** @var string $payment_id */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<strong><?php _e( 'Payment Info', WC_Swedbank_Pay::TEXT_DOMAIN ) ?></strong>
	<br />
	<strong><?php _e( 'Number', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>:</strong> <?php echo esc_html( $info['payment']['number'] ); ?>
	<br />
	<strong><?php _e( 'Instrument', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['instrument'] ); ?>
	<br />
	<strong><?php _e( 'Intent', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['intent'] ); ?>
	<br />
	<strong><?php _e( 'State', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['state'] ); ?>
	<br />
    <?php if ( $gateway->core->canCapture( $order->get_id() ) ): ?>
		<button id="swedbank_pay_capture"
				data-nonce="<?php echo wp_create_nonce( 'swedbank_pay' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Capture Payment', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>
		</button>
	<?php endif; ?>

    <?php if ( $gateway->core->canCancel( $order->get_id() ) ): ?>
		<button id="swedbank_pay_cancel"
				data-nonce="<?php echo wp_create_nonce( 'swedbank_pay' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Cancel Payment', WC_Swedbank_Pay::TEXT_DOMAIN ) ?>
		</button>
	<?php endif; ?>
</div>
