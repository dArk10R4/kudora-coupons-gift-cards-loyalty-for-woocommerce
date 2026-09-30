<?php
/**
 * Loyalty emails (HTML): points earned, tier upgraded, points expiring, reward
 * coupon, referral reward.
 *
 * Override by copying to yourtheme/woocommerce/emails/kudora-loyalty.php.
 *
 * @package Kudora
 * @var string[] $paragraphs Already-escaped paragraphs.
 * @var string   $highlight
 * @var string   $cta_url
 * @var string   $cta_label
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( array_filter( $paragraphs ) as $kudora_p ) {
	echo '<p>' . wp_kses_post( $kudora_p ) . '</p>';
}
if ( '' !== $highlight ) {
	echo '<p style="margin:16px 0;padding:14px 18px;border:2px dashed #999;border-radius:8px;font-size:20px;font-weight:700;letter-spacing:.04em;text-align:center;">' . esc_html( $highlight ) . '</p>';
}
echo '<p><a href="' . esc_url( $cta_url ) . '" style="font-weight:600;">' . esc_html( $cta_label ) . '</a></p>';

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
