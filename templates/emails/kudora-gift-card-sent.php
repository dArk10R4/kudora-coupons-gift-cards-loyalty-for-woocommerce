<?php
/**
 * Gift card delivered email (HTML), to the buyer.
 *
 * Override by copying to yourtheme/woocommerce/emails/kudora-gift-card-sent.php.
 *
 * @package Kudora
 * @var array    $card
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p>
	<?php
	/* translators: 1: amount, 2: recipient name or email */
	printf( esc_html__( 'Your %1$s gift card was delivered to %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wp_kses_post( wc_price( $card['initial_amount'] ) ), esc_html( '' !== $card['recipient_name'] ? $card['recipient_name'] . ' <' . $card['recipient_email'] . '>' : $card['recipient_email'] ) );
	?>
</p>
<p><?php esc_html_e( 'Thank you for shopping with us.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
