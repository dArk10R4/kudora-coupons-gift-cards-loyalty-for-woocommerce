<?php
/**
 * Referral link box.
 *
 * Override by copying to yourtheme/woocommerce/loyalty/referral.php.
 *
 * @package Kudora
 * @var string $link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_uid = wp_unique_id( 'kudora-ref-' );
?>
<div class="kudora-referral">
	<label for="<?php echo esc_attr( $kudora_uid ); ?>"><?php esc_html_e( 'Your referral link', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
	<div class="kudora-gc-apply-row">
		<input type="text" id="<?php echo esc_attr( $kudora_uid ); ?>" value="<?php echo esc_url( $link ); ?>" readonly />
		<button type="button" class="button wp-element-button kudora-copy" data-copy="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Copy link', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
	</div>
</div>
