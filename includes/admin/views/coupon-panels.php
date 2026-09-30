<?php
/**
 * Coupon data panels: schedule & conditions, Buy X get Y.
 *
 * @package Kudora
 * @var WC_Coupon $coupon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_days  = array_map( 'intval', (array) $coupon->get_meta( '_kudora_weekdays' ) );
$kudora_names = array( 1 => __( 'Mon', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 2 => __( 'Tue', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 3 => __( 'Wed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 4 => __( 'Thu', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 5 => __( 'Fri', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 6 => __( 'Sat', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 7 => __( 'Sun', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
$kudora_bxgy  = Kudora_Rule_Calc::sanitize_bxgy( $coupon->get_meta( '_kudora_bxgy' ) );
?>
<div id="kudora_smart_coupon_data" class="panel woocommerce_options_panel hidden">
	<div class="options_group">
		<?php
		woocommerce_wp_text_input(
			array(
				'id'          => '_kudora_start',
				'label'       => __( 'Start date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'        => 'date',
				'value'       => (string) $coupon->get_meta( '_kudora_start' ),
				'description' => __( 'The coupon cannot be used before this date. Leave empty to start now.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'desc_tip'    => true,
			)
		);
		?>
		<fieldset class="form-field">
			<legend><?php esc_html_e( 'Valid on', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
			<span class="kudora-weekdays">
				<?php foreach ( $kudora_names as $kudora_n => $kudora_label ) : ?>
					<label><input type="checkbox" name="_kudora_weekdays[]" value="<?php echo esc_attr( $kudora_n ); ?>" <?php checked( in_array( $kudora_n, $kudora_days, true ) ); ?> /> <?php echo esc_html( $kudora_label ); ?></label>
				<?php endforeach; ?>
			</span>
			<?php echo wc_help_tip( __( 'Leave all unticked to allow every day.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes. ?>
		</fieldset>
		<p class="form-field">
			<label for="_kudora_time_from"><?php esc_html_e( 'Between', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<input type="time" id="_kudora_time_from" name="_kudora_time_from" value="<?php echo esc_attr( (string) $coupon->get_meta( '_kudora_time_from' ) ); ?>" style="width:auto" />
			<span><?php esc_html_e( 'and', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
			<input type="time" id="_kudora_time_to" name="_kudora_time_to" value="<?php echo esc_attr( (string) $coupon->get_meta( '_kudora_time_to' ) ); ?>" style="width:auto" aria-label="<?php esc_attr_e( 'End time', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
		</p>
		<?php
		woocommerce_wp_checkbox(
			array(
				'id'          => '_kudora_auto_apply',
				'label'       => __( 'Apply automatically', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'description' => __( 'Add this coupon to the cart by itself whenever it is valid. Customers can still remove it.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value'       => 'yes' === $coupon->get_meta( '_kudora_auto_apply' ) ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
		?>
		<p class="form-field kudora-url-coupon">
			<label><?php esc_html_e( 'Shareable link', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<?php $kudora_param = sanitize_key( (string) Kudora_Settings::get( 'discounts_url_param' ) ); ?>
			<code><?php echo esc_html( $coupon->get_code() ? add_query_arg( $kudora_param, rawurlencode( $coupon->get_code() ), home_url( '/' ) ) : __( 'Save the coupon to get its link.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></code>
		</p>
	</div>
	<div class="options_group kudora-panel-pad">
		<p><strong><?php esc_html_e( 'Conditions', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></strong> <span class="description"><?php esc_html_e( 'All must be true for the coupon to work.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span></p>
		<?php Kudora_Admin_Fields::conditions( 'kudora_conditions', is_array( $coupon->get_meta( '_kudora_conditions' ) ) ? $coupon->get_meta( '_kudora_conditions' ) : array() ); ?>
	</div>
</div>
<div id="kudora_bxgy_coupon_data" class="panel woocommerce_options_panel hidden">
	<div class="options_group kudora-panel-pad">
		<p class="kudora-panel-intro"><?php esc_html_e( 'Used when the discount type is "Buy X get Y (BOGO)".', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
		<?php Kudora_Admin_Fields::bxgy( 'kudora_bxgy', $kudora_bxgy, false ); ?>
	</div>
</div>
