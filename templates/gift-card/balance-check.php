<?php
/**
 * Gift card balance check form ([kudora_gift_card_balance] and the block).
 *
 * Override by copying to yourtheme/woocommerce/gift-card/balance-check.php.
 *
 * @package Kudora
 * @var string $uid Unique id prefix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form class="kudora-balance-check" data-kudora-balance novalidate>
	<label for="<?php echo esc_attr( $uid ); ?>-code"><?php esc_html_e( 'Check your gift card balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
	<div class="kudora-gc-apply-row">
		<input type="text" id="<?php echo esc_attr( $uid ); ?>-code" name="code" autocomplete="off" spellcheck="false" required placeholder="<?php esc_attr_e( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
		<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Check balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
	</div>
	<p class="kudora-balance-result" role="status" aria-live="polite"></p>
</form>
