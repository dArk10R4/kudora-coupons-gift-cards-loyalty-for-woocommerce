<?php
/**
 * Order screen box: gift cards used (with refund to card), store credit, points.
 *
 * @package Kudora
 * @var WC_Order $order
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_cards    = Kudora_Settings::module( 'gift_cards' ) ? Kudora_Gift_Card_Redeem::order_cards( $order ) : array();
$kudora_state    = (string) $order->get_meta( '_kudora_gc_state' );
$kudora_redeem   = Kudora_Loyalty_Redeem::order_redeem( $order );
$kudora_earned   = (int) $order->get_meta( '_kudora_points_earned' );
$kudora_reversed = (int) $order->get_meta( '_kudora_points_reversed' );
$kudora_referrer = (int) $order->get_meta( '_kudora_referrer' );
$kudora_issued   = array();
foreach ( $order->get_items() as $kudora_item ) {
	foreach ( array_filter( array_map( 'intval', (array) $kudora_item->get_meta( '_kudora_gc_ids' ) ) ) as $kudora_id ) {
		$kudora_issued[] = $kudora_id;
	}
}
?>
<div class="kudora-order-box" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
	<?php if ( $kudora_cards ) : ?>
		<h4><?php esc_html_e( 'Paid with gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h4>
		<ul>
			<?php foreach ( $kudora_cards as $kudora_code => $kudora_a ) : ?>
				<?php $kudora_back = Kudora_Gift_Card_Redeem::refunded_to_card( $order, (int) $kudora_a['id'] ); ?>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kudora-gift-cards&tab=codes&card=' . (int) $kudora_a['id'] ) ); ?>"><code><?php echo esc_html( Kudora_Gift_Card_Repo::mask( $kudora_code ) ); ?></code></a>
					<?php echo wp_kses_post( wc_price( $kudora_a['amount'], array( 'currency' => $order->get_currency() ) ) ); ?>
					<?php if ( $kudora_back > 0 ) : ?>
						<?php /* translators: %s: amount */ ?>
						<br /><small><?php echo wp_kses_post( sprintf( __( 'Refunded to card: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $kudora_back ) ) ); ?></small>
					<?php endif; ?>
					<?php if ( 'debited' === $kudora_state && $kudora_a['amount'] - $kudora_back > 0.001 ) : ?>
						<span class="kudora-inline-form">
							<label class="screen-reader-text" for="kudora-refund-<?php echo esc_attr( $kudora_a['id'] ); ?>"><?php esc_html_e( 'Amount to refund to the card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
							<input type="text" class="wc_input_price short" id="kudora-refund-<?php echo esc_attr( $kudora_a['id'] ); ?>" placeholder="<?php echo esc_attr( wc_format_localized_price( $kudora_a['amount'] - $kudora_back ) ); ?>" />
							<button type="button" class="button kudora-order-do" data-do="refund_to_card" data-card="<?php echo esc_attr( $kudora_a['id'] ); ?>"><?php esc_html_e( 'Refund to card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( 'restored' === $kudora_state ) : ?>
			<p class="description"><?php esc_html_e( 'The gift card money was returned to the cards.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $kudora_issued ) : ?>
		<h4><?php esc_html_e( 'Gift cards bought in this order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h4>
		<ul>
			<?php foreach ( $kudora_issued as $kudora_id ) : ?>
				<?php $kudora_card = Kudora_Gift_Card_Repo::get( $kudora_id ); ?>
				<?php if ( $kudora_card ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=kudora-gift-cards&tab=codes&card=' . $kudora_id ) ); ?>"><code><?php echo esc_html( $kudora_card['code'] ); ?></code></a> — <?php echo wp_kses_post( wc_price( $kudora_card['balance'] ) ); ?> <span class="kudora-badge kudora-badge-<?php echo esc_attr( $kudora_card['status'] ); ?>"><?php echo esc_html( Kudora_Gift_Card_Repo::statuses()[ $kudora_card['status'] ] ); ?></span></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( Kudora_Settings::module( 'loyalty' ) && ( $kudora_redeem['points'] || $kudora_earned || $kudora_referrer ) ) : ?>
		<h4><?php esc_html_e( 'Loyalty', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h4>
		<ul>
			<?php if ( $kudora_redeem['points'] ) : ?>
				<?php /* translators: 1: points, 2: amount */ ?>
				<li><?php echo wp_kses_post( sprintf( __( 'Used %1$s for %2$s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( Kudora_Loyalty_Redeem::format_points( $kudora_redeem['points'] ) ), wc_price( $kudora_redeem['amount'] ) ) ); ?></li>
			<?php endif; ?>
			<?php if ( $kudora_earned ) : ?>
				<?php /* translators: %s: points */ ?>
				<li><?php echo esc_html( sprintf( __( 'Earned %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $kudora_earned ) ) ); ?><?php echo $kudora_reversed ? ' (' . esc_html( sprintf( /* translators: %d: points */ __( '%d reversed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $kudora_reversed ) ) . ')' : ''; ?></li>
			<?php endif; ?>
			<?php if ( $kudora_referrer ) : ?>
				<?php $kudora_ref = get_userdata( $kudora_referrer ); ?>
				<?php /* translators: %s: referrer name */ ?>
				<li><?php echo esc_html( sprintf( __( 'Referred by %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $kudora_ref ? $kudora_ref->display_name : '#' . $kudora_referrer ) ); ?></li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>

	<?php if ( Kudora_Settings::module( 'gift_cards' ) && $order->is_paid() ) : ?>
		<h4><?php esc_html_e( 'Refund as store credit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Creates a gift card for the customer and emails it. Use it instead of a money refund.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
		<span class="kudora-inline-form">
			<label class="screen-reader-text" for="kudora-store-credit"><?php esc_html_e( 'Store credit amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<input type="text" class="wc_input_price short" id="kudora-store-credit" />
			<button type="button" class="button kudora-order-do" data-do="store_credit"><?php esc_html_e( 'Issue credit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
		</span>
	<?php endif; ?>
	<p class="kudora-order-status" role="status" aria-live="polite"></p>
</div>
