<?php
/**
 * Gift card email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/kudora-gift-card.php.
 *
 * @package Kudora
 * @var array    $card
 * @var string   $print_url
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

if ( '' !== $card['sender_name'] ) {
	/* translators: 1: sender name, 2: store name */
	echo esc_html( sprintf( __( '%1$s sent you a gift card for %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['sender_name'], get_bloginfo( 'name' ) ) ) . "\n\n";
}
/* translators: %s: amount */
echo esc_html( sprintf( __( 'Amount: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $card['initial_amount'] ) ) ) . "\n";
/* translators: %s: code */
echo esc_html( sprintf( __( 'Code: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['code'] ) ) . "\n";
if ( ! empty( $card['expires_on'] ) ) {
	/* translators: %s: date */
	echo esc_html( sprintf( __( 'Valid until %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) ) ) . "\n";
}
if ( '' !== trim( (string) $card['message'] ) ) {
	echo "\n" . esc_html( $card['message'] ) . "\n";
}
echo "\n" . esc_html__( 'Enter the code in the gift card or coupon field at checkout. Any remaining balance stays on the card for your next order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . "\n";
echo esc_url( $print_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
