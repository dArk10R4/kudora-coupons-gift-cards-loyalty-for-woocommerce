<?php
/**
 * Gift card delivered email (plain text).
 *
 * @package Kudora
 * @var array  $card
 * @var string $email_heading
 * @var string $additional_content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: 1: amount, 2: recipient */
echo esc_html( sprintf( __( 'Your %1$s gift card was delivered to %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $card['initial_amount'] ), $card['recipient_email'] ) ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
