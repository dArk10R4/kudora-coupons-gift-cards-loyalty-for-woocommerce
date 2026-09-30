<?php
/**
 * My Account → Points & rewards.
 *
 * Override by copying to yourtheme/woocommerce/myaccount/points-rewards.php.
 *
 * @package Kudora
 * @var array       $card
 * @var array       $logs
 * @var int         $page
 * @var array[]     $rewards
 * @var WC_Coupon[] $coupons
 * @var array|null  $referral
 * @var array       $labels
 * @var bool        $birthday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_types = Kudora_Rewards::types();
?>
<div class="kudora-account kudora-account-points">
	<?php wc_get_template( 'loyalty/card.php', array( 'card' => $card ), '', KUDORA_DIR . 'templates/' ); ?>

	<?php if ( $birthday ) : ?>
		<p class="kudora-hint">
			<?php
			/* translators: %s: account details URL */
			echo wp_kses_post( sprintf( __( 'Add your birthday in <a href="%s">account details</a> to get birthday points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $rewards ) : ?>
		<h3><?php esc_html_e( 'Rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h3>
		<ul class="kudora-rewards">
			<?php foreach ( $rewards as $kudora_r ) : ?>
				<li class="kudora-reward">
					<div>
						<strong><?php echo esc_html( $kudora_r['name'] ); ?></strong>
						<?php if ( $kudora_r['description'] ) : ?>
							<span class="kudora-reward-desc"><?php echo esc_html( $kudora_r['description'] ); ?></span>
						<?php endif; ?>
						<span class="kudora-reward-cost"><?php echo esc_html( Kudora_Loyalty_Redeem::format_points( $kudora_r['cost'] ) ); ?> · <?php echo esc_html( $kudora_types[ $kudora_r['type'] ] ); ?></span>
					</div>
					<form method="post">
						<?php wp_nonce_field( 'kudora-claim-reward', '_kudora_nonce' ); ?>
						<input type="hidden" name="kudora_request" value="<?php echo esc_attr( wp_generate_password( 12, false, false ) ); ?>" />
						<button type="submit" class="button wp-element-button" name="kudora_claim_reward" value="<?php echo esc_attr( $kudora_r['id'] ); ?>" <?php disabled( '' !== $kudora_r['why_not'] ); ?> <?php echo '' !== $kudora_r['why_not'] ? 'title="' . esc_attr( $kudora_r['why_not'] ) . '"' : ''; ?>><?php esc_html_e( 'Claim', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
						<?php if ( '' !== $kudora_r['why_not'] ) : ?>
							<small class="kudora-reward-why"><?php echo esc_html( $kudora_r['why_not'] ); ?></small>
						<?php endif; ?>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $coupons ) : ?>
		<h3><?php esc_html_e( 'Your reward coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h3>
		<table class="woocommerce-table shop_table shop_table_responsive kudora-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expires', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $coupons as $kudora_c ) : ?>
					<?php
					$kudora_exp  = $kudora_c->get_date_expires();
					$kudora_used = $kudora_c->get_usage_limit() && $kudora_c->get_usage_count() >= $kudora_c->get_usage_limit();
					$kudora_gone = $kudora_exp && $kudora_exp->getTimestamp() < time();
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><code><?php echo esc_html( strtoupper( $kudora_c->get_code() ) ); ?></code> <button type="button" class="kudora-copy button-link" data-copy="<?php echo esc_attr( strtoupper( $kudora_c->get_code() ) ); ?>"><?php esc_html_e( 'Copy', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button></td>
						<td data-title="<?php esc_attr_e( 'Expires', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php echo $kudora_exp ? esc_html( wc_format_datetime( $kudora_exp ) ) : esc_html__( 'Never', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td>
						<td data-title="<?php esc_attr_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<?php
							if ( $kudora_used ) {
								esc_html_e( 'Used', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
							} elseif ( $kudora_gone ) {
								esc_html_e( 'Expired', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
							} else {
								esc_html_e( 'Ready to use', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $referral ) : ?>
		<h3><?php esc_html_e( 'Invite friends', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h3>
		<p>
			<?php
			if ( 'coupon' === Kudora_Settings::get( 'referral_reward_type' ) ) {
				/* translators: %s: reward amount */
				echo wp_kses_post( sprintf( __( 'Share your link. When a friend places their first order you get a %s coupon.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( Kudora_Settings::float( 'referral_reward_amount' ) ) ) );
			} else {
				/* translators: %s: points */
				echo esc_html( sprintf( __( 'Share your link. When a friend places their first order you get %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( Kudora_Settings::int( 'referral_reward_points' ) ) ) );
			}
			?>
		</p>
		<?php wc_get_template( 'loyalty/referral.php', array( 'link' => $referral['link'] ), '', KUDORA_DIR . 'templates/' ); ?>
		<?php /* translators: 1: successful referrals, 2: pending referrals */ ?>
		<p class="kudora-referral-stats"><?php echo esc_html( sprintf( __( 'Successful referrals: %1$d · Pending: %2$d', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $referral['stats']['rewarded'], $referral['stats']['pending'] ) ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'History', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h3>
	<?php if ( ! $logs['rows'] ) : ?>
		<p class="kudora-empty"><?php esc_html_e( 'No points activity yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-table shop_table shop_table_responsive kudora-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Activity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $logs['rows'] as $kudora_row ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $kudora_row['created_at'] . ' UTC' ) ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Activity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<?php echo esc_html( isset( $labels[ $kudora_row['action'] ] ) ? $labels[ $kudora_row['action'] ] : $kudora_row['action'] ); ?>
							<?php if ( $kudora_row['note'] ) : ?>
								<small>— <?php echo esc_html( $kudora_row['note'] ); ?></small>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" class="<?php echo (int) $kudora_row['points'] < 0 ? 'kudora-minus' : 'kudora-plus'; ?>"><?php echo esc_html( ( (int) $kudora_row['points'] > 0 ? '+' : '' ) . number_format_i18n( (int) $kudora_row['points'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $kudora_pages = (int) ceil( $logs['total'] / 15 ); ?>
		<?php if ( $kudora_pages > 1 ) : ?>
			<nav class="woocommerce-pagination kudora-pagination" aria-label="<?php esc_attr_e( 'Points history pages', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'kpage', $page - 1 ) ); ?>"><?php esc_html_e( 'Newer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
				<?php if ( $page < $kudora_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'kpage', $page + 1 ) ); ?>"><?php esc_html_e( 'Older', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
