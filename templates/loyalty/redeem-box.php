<?php
/**
 * Classic cart/checkout "use your points" row.
 *
 * Override by copying to yourtheme/woocommerce/loyalty/redeem-box.php.
 *
 * @package Kudora
 * @var int   $balance
 * @var array $applied
 * @var int   $max
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr class="kudora-points-row">
	<th><?php echo esc_html( Kudora_Frontend::title_for( 'points' ) ); ?></th>
	<td data-title="<?php echo esc_attr( Kudora_Frontend::title_for( 'points' ) ); ?>">
		<?php if ( $applied['points'] > 0 ) : ?>
			<?php
			/* translators: 1: points, 2: discount */
			echo wp_kses_post( sprintf( __( '%1$s used (−%2$s)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( Kudora_Loyalty_Redeem::format_points( $applied['points'] ) ), wc_price( $applied['amount'] ) ) );
			?>
			<a href="#" class="kudora-remove-points"><?php esc_html_e( '[Remove]', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
		<?php elseif ( $max > 0 ) : ?>
			<div class="kudora-points-form">
				<?php /* translators: %s: points balance */ ?>
				<small><?php echo esc_html( sprintf( __( 'You have %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $balance ) ) ); ?></small>
				<?php if ( 'auto' === $settings['mode'] ) : ?>
					<button type="button" class="button kudora-apply-points" data-mode="auto"><?php esc_html_e( 'Use my points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
				<?php else : ?>
					<label class="screen-reader-text" for="kudora_points_input"><?php esc_html_e( 'Points to use', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					<input type="number" id="kudora_points_input" class="input-text kudora-points-input" min="<?php echo esc_attr( max( 1, $settings['min_points'] ) ); ?>" max="<?php echo esc_attr( $max ); ?>" step="1" value="<?php echo esc_attr( $max ); ?>" />
					<button type="button" class="button kudora-apply-points" data-mode="custom"><?php esc_html_e( 'Use points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
					<?php /* translators: %s: max points */ ?>
					<small><?php echo esc_html( sprintf( __( 'Up to %s on this order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $max ) ) ); ?></small>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<?php /* translators: %s: points balance */ ?>
			<small><?php echo esc_html( sprintf( __( 'You have %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $balance ) ) ); ?></small>
		<?php endif; ?>
	</td>
</tr>
