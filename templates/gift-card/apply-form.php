<?php
/**
 * Classic cart/checkout gift card form.
 *
 * Override by copying to yourtheme/woocommerce/gift-card/apply-form.php.
 *
 * @package Kudora
 * @var string $context cart|checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
	return;
}
wp_enqueue_script( 'kudora-classic' );
wp_enqueue_style( 'kudora-frontend' );
$kudora_field_id = 'kudora_gift_card_code_' . $context;
?>
<?php if ( 'checkout' === $context ) : ?>
	<div class="woocommerce-form-coupon-toggle kudora-gc-toggle">
		<div class="woocommerce-info">
			<?php esc_html_e( 'Have a gift card?', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
			<a href="#" class="kudora-show-gift-card" aria-controls="kudora-gc-apply-checkout" aria-expanded="false"><?php esc_html_e( 'Click here to enter your code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
		</div>
	</div>
<?php endif; ?>
<div class="kudora-gc-apply kudora-gc-apply-<?php echo esc_attr( $context ); ?>" id="kudora-gc-apply-<?php echo esc_attr( $context ); ?>" <?php echo 'checkout' === $context ? 'hidden' : ''; ?> data-context="<?php echo esc_attr( $context ); ?>">
	<label for="<?php echo esc_attr( $kudora_field_id ); ?>"><?php esc_html_e( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
	<div class="kudora-gc-apply-row">
		<input type="text" class="input-text kudora-gc-code" id="<?php echo esc_attr( $kudora_field_id ); ?>" autocomplete="off" spellcheck="false" />
		<button type="button" class="button kudora-gc-apply-button"><?php esc_html_e( 'Apply gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
	</div>
	<div class="kudora-gc-apply-notices" role="status" aria-live="polite"></div>
</div>
