<?php
/**
 * Loyalty emails (plain text).
 *
 * @package Kudora
 * @var string[] $paragraphs
 * @var string   $highlight
 * @var string   $cta_url
 * @var string   $email_heading
 * @var string   $additional_content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
foreach ( array_filter( $paragraphs ) as $kudora_p ) {
	echo esc_html( wp_strip_all_tags( $kudora_p ) ) . "\n\n";
}
if ( '' !== $highlight ) {
	echo esc_html( $highlight ) . "\n\n";
}
echo esc_url_raw( $cta_url ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
