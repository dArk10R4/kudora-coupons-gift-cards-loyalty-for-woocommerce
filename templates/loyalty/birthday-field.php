<?php
/**
 * Birthday field on the My Account details form.
 *
 * Override by copying to yourtheme/woocommerce/loyalty/birthday-field.php.
 *
 * @package Kudora
 * @var int  $month
 * @var int  $day
 * @var bool $locked
 * @var int  $points
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<fieldset class="kudora-birthday">
	<legend><?php esc_html_e( 'Birthday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
	<?php /* translators: %s: points */ ?>
	<p class="description"><?php echo esc_html( sprintf( __( 'Get %s on your birthday.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $points ) ) ); ?></p>
	<p class="woocommerce-form-row form-row form-row-first">
		<label for="kudora_birthday_month"><?php esc_html_e( 'Month', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		<select name="kudora_birthday_month" id="kudora_birthday_month" <?php disabled( $locked ); ?>>
			<option value="0">—</option>
			<?php for ( $kudora_m = 1; $kudora_m <= 12; $kudora_m++ ) : ?>
				<option value="<?php echo esc_attr( $kudora_m ); ?>" <?php selected( $month, $kudora_m ); ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $kudora_m, 1, 2024 ) ) ); ?></option>
			<?php endfor; ?>
		</select>
	</p>
	<p class="woocommerce-form-row form-row form-row-last">
		<label for="kudora_birthday_day"><?php esc_html_e( 'Day', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		<select name="kudora_birthday_day" id="kudora_birthday_day" <?php disabled( $locked ); ?>>
			<option value="0">—</option>
			<?php for ( $kudora_d = 1; $kudora_d <= 31; $kudora_d++ ) : ?>
				<option value="<?php echo esc_attr( $kudora_d ); ?>" <?php selected( $day, $kudora_d ); ?>><?php echo esc_html( $kudora_d ); ?></option>
			<?php endfor; ?>
		</select>
	</p>
	<?php if ( $locked ) : ?>
		<p class="description"><?php esc_html_e( 'Your birthday can be changed once a year.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<div class="clear"></div>
</fieldset>
