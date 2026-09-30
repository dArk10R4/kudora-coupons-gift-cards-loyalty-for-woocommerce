<?php
/**
 * QR code helper over the bundled (MIT) encoder: a module matrix, an inline SVG for
 * web pages, and rectangles for the PDF — no GD required.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QR helper.
 */
class Kudora_QR {

	/**
	 * Load the encoder.
	 *
	 * @return bool
	 */
	protected static function load() {
		if ( ! class_exists( 'Kudora_Vendor_QRCode', false ) ) {
			require_once KUDORA_DIR . 'includes/vendor/qrcode/qrcode.php';
		}
		return class_exists( 'Kudora_Vendor_QRCode', false );
	}

	/**
	 * Boolean module matrix (true = dark), or empty on failure.
	 *
	 * @param string $text Text to encode.
	 * @return bool[][]
	 */
	public static function matrix( $text ) {
		if ( '' === (string) $text || ! self::load() ) {
			return array();
		}
		try {
			$qr = Kudora_Vendor_QRCode::getMinimumQRCode( (string) $text, KUDORA_QR_ERROR_CORRECT_LEVEL_M );
		} catch ( Throwable $e ) {
			return array();
		}
		$n   = $qr->getModuleCount();
		$out = array();
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				$out[ $r ][ $c ] = (bool) $qr->isDark( $r, $c );
			}
		}
		return $out;
	}

	/**
	 * Inline SVG (one path, crisp edges, 4-module quiet zone).
	 *
	 * @param string $text  Text.
	 * @param int    $size  Rendered size in px.
	 * @param string $label Accessible label.
	 * @return string
	 */
	public static function svg( $text, $size = 132, $label = '' ) {
		$m = self::matrix( $text );
		if ( ! $m ) {
			return '';
		}
		$n    = count( $m );
		$full = $n + 8;
		$d    = '';
		foreach ( $m as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					$d .= 'M' . ( $c + 4 ) . ' ' . ( $r + 4 ) . 'h1v1h-1z';
				}
			}
		}
		$label = '' !== $label ? $label : __( 'QR code with the gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s"><rect width="%1$d" height="%1$d" fill="#fff"/><path d="%4$s" fill="#000"/></svg>',
			(int) $full,
			(int) $size,
			esc_attr( $label ),
			esc_attr( $d )
		);
	}
}
