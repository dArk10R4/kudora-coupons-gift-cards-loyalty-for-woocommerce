<?php
/**
 * My Account → Gift cards.
 *
 * Override by copying to yourtheme/woocommerce/myaccount/gift-cards.php.
 *
 * @package Kudora
 * @var array[] $cards
 * @var string  $email Current user's email (lowercase).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_user_id  = get_current_user_id();
$kudora_statuses = Kudora_Gift_Card_Repo::statuses();
?>
<div class="kudora-account kudora-account-gift-cards">
	<form method="post" class="kudora-save-card">
		<label for="kudora_save_code"><?php esc_html_e( 'Got a gift card? Save it to your account', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		<div class="kudora-gc-apply-row">
			<input type="text" id="kudora_save_code" name="kudora_code" autocomplete="off" spellcheck="false" required />
			<button type="submit" name="kudora_save_gift_card" value="1" class="button wp-element-button"><?php esc_html_e( 'Save card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
		</div>
		<?php wp_nonce_field( 'kudora-save-gift-card', '_kudora_nonce' ); ?>
	</form>

	<?php if ( ! $cards ) : ?>
		<p class="kudora-empty"><?php esc_html_e( 'You have no gift cards yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-table shop_table shop_table_responsive kudora-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cards as $kudora_card ) : ?>
					<?php
					$kudora_full   = Kudora_Gift_Card_Frontend::can_see_code( $kudora_card, $kudora_user_id );
					$kudora_status = Kudora_Gift_Card_Repo::is_expired( $kudora_card ) ? 'expired' : $kudora_card['status'];
					// Sent to this email but not saved to the account yet: details stay hidden until the code is entered.
					$kudora_locked = ! $kudora_full && (int) $kudora_card['purchaser_id'] !== $kudora_user_id;
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<code class="kudora-code"><?php echo esc_html( $kudora_full ? $kudora_card['code'] : Kudora_Gift_Card_Repo::mask( $kudora_card['code'] ) ); ?></code>
							<?php if ( $kudora_full ) : ?>
								<button type="button" class="kudora-copy button-link" data-copy="<?php echo esc_attr( $kudora_card['code'] ); ?>"><?php esc_html_e( 'Copy', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
							<?php elseif ( $kudora_locked ) : ?>
								<br /><small><?php esc_html_e( 'Sent to your email. Enter the code from that email above to add this card.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></small>
							<?php elseif ( $kudora_card['recipient_email'] ) : ?>
								<?php /* translators: %s: recipient email */ ?>
								<br /><small><?php echo esc_html( sprintf( __( 'Sent to %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $kudora_card['recipient_email'] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<?php if ( $kudora_locked ) : ?>
								&mdash;
							<?php else : ?>
								<?php echo wp_kses_post( wc_price( $kudora_card['balance'] ) ); ?>
								<?php /* translators: %s: original amount */ ?>
								<br /><small><?php echo wp_kses_post( sprintf( __( 'of %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $kudora_card['initial_amount'] ) ) ); ?></small>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Expires', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<?php echo $kudora_card['expires_on'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $kudora_card['expires_on'] . ' 12:00:00' ) ) ) : esc_html__( 'Never', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
							<span class="kudora-badge kudora-badge-<?php echo esc_attr( $kudora_status ); ?>"><?php echo esc_html( isset( $kudora_statuses[ $kudora_status ] ) ? $kudora_statuses[ $kudora_status ] : $kudora_status ); ?></span>
						</td>
						<td class="kudora-actions">
							<?php if ( $kudora_full ) : ?>
								<a href="<?php echo esc_url( Kudora_Gift_Card_Frontend::printable_url( $kudora_card ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
								<a href="<?php echo esc_url( Kudora_Gift_Card_Frontend::printable_url( $kudora_card, 'pdf' ) ); ?>"><?php esc_html_e( 'PDF', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
							<?php endif; ?>
							<?php if ( ! $kudora_locked ) : ?>
							<details class="kudora-history">
								<summary><?php esc_html_e( 'History', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></summary>
								<ul>
									<?php foreach ( Kudora_Gift_Card_Repo::ledger( $kudora_card['id'] ) as $kudora_row ) : ?>
										<?php
										if ( 'status' === $kudora_row['type'] ) {
											continue;
										}
										?>
										<li>
											<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $kudora_row['created_at'] . ' UTC' ) ) ); ?> —
											<?php echo wp_kses_post( ( (float) $kudora_row['amount'] < 0 ? '−' : '+' ) . wc_price( abs( (float) $kudora_row['amount'] ) ) ); ?>
											<?php
											$kudora_order = $kudora_row['order_id'] ? wc_get_order( (int) $kudora_row['order_id'] ) : null;
											if ( $kudora_order && (int) $kudora_order->get_customer_id() === $kudora_user_id ) {
												/* translators: %s: order number */
												echo ' <a href="' . esc_url( $kudora_order->get_view_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $kudora_order->get_order_number() ) ) . '</a>';
											}
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
