<?php
/**
 * Loyalty card: points, tier badge and progress to the next tier (Booknetic customer
 * panel card).
 *
 * Override by copying to yourtheme/woocommerce/loyalty/card.php.
 *
 * @package Kudora
 * @var array $card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_tier = $card['tier'];
$kudora_next = $card['next'];
?>
<section class="kudora-loyalty-card" aria-label="<?php esc_attr_e( 'Your loyalty points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
	<div class="kudora-loyalty-head">
		<div>
			<p class="kudora-loyalty-points"><?php echo esc_html( number_format_i18n( $card['balance'] ) ); ?> <span><?php echo esc_html( _n( 'point', 'points', (int) $card['balance'], 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></span></p>
			<?php /* translators: %s: value of one point */ ?>
			<p class="kudora-loyalty-value"><?php echo wp_kses_post( sprintf( __( '1 point = %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $card['point_value'], array( 'decimals' => max( wc_get_price_decimals(), strlen( substr( strrchr( (string) $card['point_value'], '.' ), 1 ) ) ) ) ) ) ); ?></p>
		</div>
		<?php if ( $kudora_tier ) : ?>
			<span class="kudora-tier-badge" style="--kudora-tier: <?php echo esc_attr( $kudora_tier['color'] ); ?>"><?php echo esc_html( $kudora_tier['name'] ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( $kudora_next['tier'] ) : ?>
		<div class="kudora-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (int) $card['progress'] ); ?>" aria-label="<?php esc_attr_e( 'Progress to the next tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
			<span style="width: <?php echo esc_attr( $card['progress'] ); ?>%"></span>
		</div>
		<p class="kudora-progress-text">
			<?php
			/* translators: 1: points needed, 2: next tier name */
			echo esc_html( sprintf( __( 'Collect %1$s more points to become %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), number_format_i18n( $kudora_next['needed'] ), $kudora_next['tier']['name'] ) );
			?>
			<span class="kudora-progress-count"><?php echo esc_html( number_format_i18n( $kudora_next['current_points'] ) . ' / ' . number_format_i18n( $kudora_next['tier']['min_points'] ) ); ?></span>
		</p>
	<?php elseif ( $kudora_tier ) : ?>
		<p class="kudora-progress-text"><?php echo esc_html( number_format_i18n( $kudora_next['current_points'] ) ); ?> / ∞ — <?php esc_html_e( 'You reached the highest tier.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $card['expiry']['date'] ) && $card['expiry']['points'] > 0 ) : ?>
		<p class="kudora-expiry-note">
			<?php
			/* translators: 1: points, 2: date */
			echo esc_html( sprintf( __( '%1$s expire on %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $card['expiry']['points'] ), date_i18n( get_option( 'date_format' ), strtotime( $card['expiry']['date'] . ' UTC' ) ) ) );
			?>
		</p>
	<?php endif; ?>
</section>
