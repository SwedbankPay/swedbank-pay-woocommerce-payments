<?php
/** @var WC_Order $order */
/** @var int $order_id */
/** @var string $payment_id */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<strong><?php _e( 'Payment Info', WC_Payex_Psp::TEXT_DOMAIN ) ?></strong>
	<br />
	<strong><?php _e( 'Number', WC_Payex_Psp::TEXT_DOMAIN ) ?>:</strong> <?php echo esc_html( $info['payment']['number'] ); ?>
	<br />
	<strong><?php _e( 'Instrument', WC_Payex_Psp::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['instrument'] ); ?>
	<br />
	<strong><?php _e( 'Intent', WC_Payex_Psp::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['intent'] ); ?>
	<br />
	<strong><?php _e( 'State', WC_Payex_Psp::TEXT_DOMAIN ) ?>: </strong> <?php echo esc_html( $info['payment']['state'] ); ?>
	<br />
	<?php if ( isset($info['payment']['remainingCaptureAmount']) && (float) $info['payment']['remainingCaptureAmount'] > 0.1 ): ?>
		<button id="payex_capture"
				data-nonce="<?php echo wp_create_nonce( 'payex' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Capture Payment', WC_Payex_Psp::TEXT_DOMAIN ) ?>
		</button>
	<?php endif; ?>

	<?php if ( isset($info['payment']['remainingCancellationAmount']) && (float) $info['payment']['remainingCancellationAmount'] > 0.1 ): ?>
		<button id="payex_cancel"
				data-nonce="<?php echo wp_create_nonce( 'payex' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Cancel Payment', WC_Payex_Psp::TEXT_DOMAIN ) ?>
		</button>
	<?php endif; ?>
</div>
