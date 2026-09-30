<?php
/**
 * Printable gift card page (standalone document).
 *
 * Override by copying to yourtheme/woocommerce/gift-card/printable.php.
 *
 * @package Kudora
 * @var array  $card
 * @var array  $design
 * @var string $qr_svg
 * @var string $pdf_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_style = Kudora_Gift_Card_Designs::style( $design );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' – ' . __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></title>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone printable document outside the theme. ?>
	<link rel="stylesheet" href="<?php echo esc_url( KUDORA_URL . 'assets/css/frontend.css?ver=' . KUDORA_VERSION ); ?>" />
</head>
<body class="kudora-printable">
	<main class="kudora-print-sheet">
		<div class="kudora-card kudora-card-large" style="<?php echo esc_attr( $kudora_style ); ?>">
			<div class="kudora-card-top">
				<span class="kudora-card-store"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
				<span class="kudora-card-label"><?php esc_html_e( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
			</div>
			<div class="kudora-card-amount"><?php echo wp_kses_post( wc_price( $card['initial_amount'] ) ); ?></div>
			<?php if ( '' !== $card['recipient_name'] ) : ?>
				<?php /* translators: %s: recipient name */ ?>
				<div class="kudora-card-to"><?php echo esc_html( sprintf( __( 'To: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['recipient_name'] ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $card['sender_name'] ) : ?>
				<?php /* translators: %s: sender name */ ?>
				<div class="kudora-card-from"><?php echo esc_html( sprintf( __( 'From: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['sender_name'] ) ); ?></div>
			<?php endif; ?>
		</div>

		<?php if ( '' !== trim( (string) $card['message'] ) ) : ?>
			<blockquote class="kudora-print-message"><?php echo nl2br( esc_html( $card['message'] ) ); ?></blockquote>
		<?php endif; ?>

		<div class="kudora-print-code">
			<div>
				<p class="kudora-print-label"><?php esc_html_e( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
				<p class="kudora-print-value"><code><?php echo esc_html( $card['code'] ); ?></code></p>
				<?php /* translators: %s: current balance */ ?>
				<p><?php echo wp_kses_post( sprintf( __( 'Current balance: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $card['balance'] ) ) ); ?></p>
				<?php if ( ! empty( $card['expires_on'] ) ) : ?>
					<?php /* translators: %s: date */ ?>
					<p><?php echo esc_html( sprintf( __( 'Valid until %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $qr_svg ) : ?>
				<div class="kudora-print-qr">
					<?php
					echo wp_kses(
						$qr_svg,
						array(
							'svg'  => array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'shape-rendering' => true, 'role' => true, 'aria-label' => true ),
							'rect' => array( 'width' => true, 'height' => true, 'fill' => true ),
							'path' => array( 'd' => true, 'fill' => true ),
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<?php /* translators: %s: shop URL */ ?>
		<p class="kudora-print-help"><?php echo esc_html( sprintf( __( 'Enter this code in the gift card or coupon field at checkout on %s. Any remaining balance stays on the card.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?></p>

		<p class="kudora-print-actions">
			<button type="button" class="kudora-print-button" onclick="window.print()"><?php esc_html_e( 'Print', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
			<a href="<?php echo esc_url( $pdf_url ); ?>"><?php esc_html_e( 'Download PDF', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
		</p>
	</main>
</body>
</html>
