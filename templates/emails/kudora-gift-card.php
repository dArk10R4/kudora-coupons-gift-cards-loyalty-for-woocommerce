<?php
/**
 * Gift card email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/kudora-gift-card.php.
 *
 * @package Kudora
 * @var array    $card
 * @var array    $design
 * @var string   $print_url
 * @var string   $shop_url
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

$kudora_bg = ! empty( $design['color'] ) ? $design['color'] : '#1f2a44';
$kudora_fg = ! empty( $design['text_color'] ) ? $design['text_color'] : '#ffffff';
?>
<p>
	<?php
	if ( '' !== $card['sender_name'] ) {
		/* translators: 1: sender name, 2: store name */
		printf( esc_html__( '%1$s sent you a gift card for %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( $card['sender_name'] ), esc_html( get_bloginfo( 'name' ) ) );
	} else {
		/* translators: %s: store name */
		printf( esc_html__( 'Here is your gift card for %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( get_bloginfo( 'name' ) ) );
	}
	?>
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 24px;">
	<tr>
		<td style="background-color:<?php echo esc_attr( $kudora_bg ); ?>;color:<?php echo esc_attr( $kudora_fg ); ?>;border-radius:12px;padding:24px;<?php echo ! empty( $design['image_url'] ) ? 'background-image:url(' . esc_url( $design['image_url'] ) . ');background-size:cover;' : ''; ?>">
			<p style="margin:0 0 4px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:<?php echo esc_attr( $kudora_fg ); ?>;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php esc_html_e( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
			<p style="margin:0;font-size:36px;font-weight:700;line-height:1.2;color:<?php echo esc_attr( $kudora_fg ); ?>;"><?php echo wp_kses_post( wc_price( $card['initial_amount'] ) ); ?></p>
			<?php if ( '' !== $card['recipient_name'] ) : ?>
				<?php /* translators: %s: recipient name */ ?>
				<p style="margin:12px 0 0;color:<?php echo esc_attr( $kudora_fg ); ?>;"><?php echo esc_html( sprintf( __( 'To: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['recipient_name'] ) ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( '' !== trim( (string) $card['message'] ) ) : ?>
	<blockquote style="margin:0 0 24px;padding:12px 16px;border-left:4px solid <?php echo esc_attr( $kudora_bg ); ?>;font-style:italic;"><?php echo nl2br( esc_html( $card['message'] ) ); ?></blockquote>
<?php endif; ?>

<p style="margin:0 0 4px;"><?php esc_html_e( 'Your gift card code:', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
<p style="margin:0 0 16px;font-size:22px;font-weight:700;letter-spacing:.06em;font-family:Menlo,Consolas,monospace;"><?php echo esc_html( $card['code'] ); ?></p>

<?php if ( ! empty( $card['expires_on'] ) ) : ?>
	<?php /* translators: %s: date */ ?>
	<p><?php echo esc_html( sprintf( __( 'Valid until %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) ) ); ?></p>
<?php endif; ?>

<p><?php esc_html_e( 'Enter the code in the gift card or coupon field at checkout. Any remaining balance stays on the card for your next order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>

<p>
	<a href="<?php echo esc_url( $shop_url ? $shop_url : home_url( '/' ) ); ?>" style="font-weight:600;"><?php esc_html_e( 'Start shopping', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
	&nbsp;·&nbsp;
	<a href="<?php echo esc_url( $print_url ); ?>"><?php esc_html_e( 'View or print your gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
