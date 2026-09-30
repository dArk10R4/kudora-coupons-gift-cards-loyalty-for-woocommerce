<?php
/**
 * Money helpers. Amounts are floats in the store currency, rounded to the store's
 * price decimals at every boundary.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money helper.
 */
class Kudora_Money {

	/**
	 * Store price decimals.
	 *
	 * @return int
	 */
	public static function decimals() {
		return (int) wc_get_price_decimals();
	}

	/**
	 * Round half-up to store decimals.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	public static function round( $amount ) {
		return round( (float) $amount, self::decimals() );
	}

	/**
	 * Round down to store decimals (Booknetic's `Math::floor` for percent discounts).
	 * A tiny epsilon absorbs float noise such as 19.999999999.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	public static function floor( $amount ) {
		$factor = pow( 10, self::decimals() );
		return floor( (float) $amount * $factor + 1e-7 ) / $factor;
	}

	/**
	 * Parse a user-entered amount (respecting the store decimal separator).
	 *
	 * @param mixed $raw Raw input.
	 * @return float
	 */
	public static function parse( $raw ) {
		return (float) wc_format_decimal( is_scalar( $raw ) ? (string) $raw : '0' );
	}

	/**
	 * Plain-text price (no HTML), for emails, notes and CSV.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function plain( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
