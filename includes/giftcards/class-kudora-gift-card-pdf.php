<?php
/**
 * PDF gift card (bundled tFPDF, UTF-8 via DejaVu Sans): the card face in the chosen
 * design, the message, the code with a QR code, the expiry date and how to redeem.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF renderer.
 */
class Kudora_Gift_Card_PDF {

	/**
	 * Temp files to remove at shutdown.
	 *
	 * @var string[]
	 */
	protected static $temp = array();

	/**
	 * Load tFPDF.
	 *
	 * @return bool
	 */
	protected static function load() {
		if ( ! class_exists( 'Kudora_Vendor_tFPDF', false ) ) {
			require_once KUDORA_DIR . 'includes/vendor/tfpdf/tfpdf.php';
		}
		return class_exists( 'Kudora_Vendor_tFPDF', false );
	}

	/**
	 * Hex colour to RGB.
	 *
	 * @param string $hex     Hex colour.
	 * @param array  $default Fallback.
	 * @return int[]
	 */
	protected static function rgb( $hex, $default ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return $default;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Plain text for the PDF (no HTML entities).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	protected static function txt( $s ) {
		return html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Render a card to a PDF string ('' on failure).
	 *
	 * @param array $card Card.
	 * @return string
	 */
	public static function render( array $card ) {
		if ( ! self::load() ) {
			return '';
		}
		try {
			$design = Kudora_Gift_Card_Designs::get( $card['design_id'] );
			$design = $design ? $design : Kudora_Gift_Card_Designs::fallback();
			$bg     = self::rgb( $design['color'], array( 31, 42, 68 ) );
			$fg     = self::rgb( $design['text_color'], array( 255, 255, 255 ) );

			$pdf = new Kudora_Vendor_tFPDF( 'L', 'mm', 'A5' );
			$pdf->SetTitle( self::txt( get_bloginfo( 'name' ) . ' – ' . __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), true );
			$pdf->SetAutoPageBreak( false );
			$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
			$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );
			$pdf->AddPage();

			// Card face: 120 x 72 mm.
			$x = 15;
			$y = 14;
			$w = 120;
			$h = 72;
			$pdf->SetFillColor( $bg[0], $bg[1], $bg[2] );
			$pdf->Rect( $x, $y, $w, $h, 'F' );
			$image = self::image_path( $design );
			if ( $image ) {
				$pdf->Image( $image, $x, $y, $w, $h );
			}
			$pdf->SetTextColor( $fg[0], $fg[1], $fg[2] );
			$pdf->SetFont( 'DejaVu', 'B', 11 );
			$pdf->SetXY( $x + 6, $y + 6 );
			$pdf->Cell( $w - 12, 6, self::txt( get_bloginfo( 'name' ) ), 0, 0, 'L' );
			$pdf->SetFont( 'DejaVu', '', 9 );
			$pdf->SetXY( $x + 6, $y + 6 );
			$pdf->Cell( $w - 12, 6, self::txt( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) : strtoupper( __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ), 0, 0, 'R' );
			$pdf->SetFont( 'DejaVu', 'B', 30 );
			$pdf->SetXY( $x + 6, $y + 24 );
			$pdf->Cell( $w - 12, 14, self::txt( Kudora_Money::plain( $card['initial_amount'] ) ), 0, 0, 'L' );
			$pdf->SetFont( 'DejaVu', '', 10 );
			if ( '' !== $card['recipient_name'] ) {
				$pdf->SetXY( $x + 6, $y + $h - 18 );
				/* translators: %s: recipient name */
				$pdf->Cell( $w - 12, 5, self::txt( sprintf( __( 'To: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['recipient_name'] ) ), 0, 0, 'L' );
			}
			if ( '' !== $card['sender_name'] ) {
				$pdf->SetXY( $x + 6, $y + $h - 12 );
				/* translators: %s: sender name */
				$pdf->Cell( $w - 12, 5, self::txt( sprintf( __( 'From: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['sender_name'] ) ), 0, 0, 'L' );
			}

			// QR code to the right of the card.
			$m = Kudora_QR::matrix( $card['code'] );
			if ( $m ) {
				$n    = count( $m );
				$size = 48;
				$cell = $size / $n;
				$qx   = 145;
				$qy   = $y + 12;
				$pdf->SetFillColor( 255, 255, 255 );
				$pdf->Rect( $qx - 2, $qy - 2, $size + 4, $size + 4, 'F' );
				$pdf->SetFillColor( 0, 0, 0 );
				foreach ( $m as $r => $row ) {
					foreach ( $row as $c => $dark ) {
						if ( $dark ) {
							$pdf->Rect( $qx + $c * $cell, $qy + $r * $cell, $cell + 0.01, $cell + 0.01, 'F' );
						}
					}
				}
			}

			// Details below.
			$pdf->SetTextColor( 24, 24, 27 );
			$pdf->SetFont( 'DejaVu', '', 9 );
			$pdf->SetXY( 15, 92 );
			$pdf->Cell( 120, 5, self::txt( __( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), 0, 2, 'L' );
			$pdf->SetFont( 'DejaVu', 'B', 16 );
			$pdf->Cell( 120, 9, self::txt( $card['code'] ), 0, 2, 'L' );
			$pdf->SetFont( 'DejaVu', '', 9 );
			if ( ! empty( $card['expires_on'] ) ) {
				/* translators: %s: date */
				$pdf->Cell( 120, 5, self::txt( sprintf( __( 'Valid until %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) ) ), 0, 2, 'L' );
			}
			if ( '' !== trim( (string) $card['message'] ) ) {
				$pdf->SetXY( 145, 70 );
				$pdf->SetFont( 'DejaVu', '', 9 );
				$pdf->MultiCell( 50, 4.5, self::txt( '“' . $card['message'] . '”' ), 0, 'L' );
			}
			$pdf->SetXY( 15, 128 );
			$pdf->SetFont( 'DejaVu', '', 8 );
			$pdf->SetTextColor( 82, 82, 91 );
			/* translators: %s: shop URL */
			$pdf->MultiCell( 180, 4, self::txt( sprintf( __( 'Enter this code in the gift card or coupon field at checkout on %s. Any remaining balance stays on the card.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ), 0, 'L' );

			return (string) $pdf->Output( 'S' );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Local JPEG/PNG path of a design image (other formats are skipped).
	 *
	 * @param array $design Design.
	 * @return string
	 */
	protected static function image_path( $design ) {
		if ( empty( $design['image_id'] ) ) {
			return '';
		}
		$path = get_attached_file( (int) $design['image_id'] );
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ? $path : '';
	}

	/**
	 * Write a PDF to a temp file for an email attachment (removed at shutdown).
	 *
	 * @param array $card Card.
	 * @return string Path or ''.
	 */
	public static function temp_file( array $card ) {
		$pdf = self::render( $card );
		if ( '' === $pdf ) {
			return '';
		}
		$dir  = trailingslashit( get_temp_dir() ) . 'kudora-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$path = $dir . '/gift-card-' . sanitize_file_name( substr( $card['code'], -4 ) ) . '.pdf';
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $path, $pdf, FS_CHMOD_FILE ) ) {
			return '';
		}
		if ( ! self::$temp ) {
			add_action( 'shutdown', array( __CLASS__, 'cleanup' ) );
		}
		self::$temp[] = $path;
		return $path;
	}

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	public static function cleanup() {
		foreach ( self::$temp as $path ) {
			wp_delete_file( $path );
			@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort temp cleanup.
		}
		self::$temp = array();
	}
}
