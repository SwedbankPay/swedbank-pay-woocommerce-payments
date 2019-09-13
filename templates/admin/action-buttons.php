<?php
/** @var WC_Gateway_Payex_Cc $gateway */
/** @var WC_Order $order */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>

<?php if ( $gateway->can_capture( $order ) ): ?>
	<button id="payex_capture"
			type="button" class="button button-primary"
			data-nonce="<?php echo wp_create_nonce( 'payex' ); ?>"
			data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
		<?php _e( 'Capture Payment', 'payex-woocommerce-payments' ) ?>
	</button>
<?php endif; ?>

<?php if ( $gateway->can_cancel( $order ) ): ?>
	<button id="payex_cancel"
			type="button" class="button button-primary"
			data-nonce="<?php echo wp_create_nonce( 'payex' ); ?>"
			data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
		<?php _e( 'Cancel Payment', 'payex-woocommerce-payments' ) ?>
	</button>
<?php endif; ?>

