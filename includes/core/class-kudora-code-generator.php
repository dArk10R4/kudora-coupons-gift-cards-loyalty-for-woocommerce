<?php
/**
 * Code generator (Booknetic "Code Format"): prefix + random body + suffix, with an
 * optional dash every N characters. Used for gift cards, bulk coupons, reward
 * coupons and referral codes.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Random code generator.
 */
class Kudora_Code_Generator {

	/**
	 * Alphabets. Letters I and O and digits 0 and 1 are left out of the mixed set so
	 * codes read aloud or copied from paper are unambiguous.
	 *
	 * @var array<string,string>
	 */
	const CHARSETS = array(
		'alnum'   => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
		'digits'  => '0123456789',
		'letters' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
	);

	/**
	 * Normalise a format array.
	 *
	 * @param array $format prefix, suffix, charset, length, group.
	 * @return array
	 */
	public static function normalize_format( array $format ) {
		$charset = isset( $format['charset'] ) && isset( self::CHARSETS[ $format['charset'] ] ) ? $format['charset'] : 'alnum';
		$length  = isset( $format['length'] ) ? (int) $format['length'] : 12;
		$group   = isset( $format['group'] ) ? (int) $format['group'] : 0;
		return array(
			'prefix'  => self::clean_affix( isset( $format['prefix'] ) ? $format['prefix'] : '' ),
			'suffix'  => self::clean_affix( isset( $format['suffix'] ) ? $format['suffix'] : '' ),
			'charset' => $charset,
			'length'  => max( 4, min( 32, $length ) ),
			'group'   => max( 0, min( 16, $group ) ),
		);
	}

	/**
	 * Keep affixes to safe code characters, max 20 (Booknetic limit).
	 *
	 * @param string $affix Raw prefix/suffix.
	 * @return string
	 */
	public static function clean_affix( $affix ) {
		$affix = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $affix ) );
		return substr( $affix, 0, 20 );
	}

	/**
	 * Generate one code (not checked for uniqueness).
	 *
	 * @param array $format Format.
	 * @return string
	 */
	public static function generate( array $format ) {
		$f        = self::normalize_format( $format );
		$alphabet = self::CHARSETS[ $f['charset'] ];
		$max      = strlen( $alphabet ) - 1;
		$body     = '';
		for ( $i = 0; $i < $f['length']; $i++ ) {
			$body .= $alphabet[ random_int( 0, $max ) ];
		}
		if ( $f['group'] > 0 && $f['group'] < $f['length'] ) {
			$body = implode( '-', str_split( $body, $f['group'] ) );
		}
		return $f['prefix'] . $body . $f['suffix'];
	}

	/**
	 * Generate a code that does not exist yet.
	 *
	 * @param array    $format Format.
	 * @param callable $exists Returns true when a code is already taken.
	 * @return string
	 * @throws RuntimeException When no free code is found (format far too small).
	 */
	public static function unique( array $format, $exists ) {
		for ( $attempt = 0; $attempt < 25; $attempt++ ) {
			$code = self::generate( $format );
			if ( ! call_user_func( $exists, $code ) ) {
				return $code;
			}
		}
		throw new RuntimeException( 'Could not generate a unique code; increase the code length.' );
	}

	/**
	 * Example code for the settings preview.
	 *
	 * @param array $format Format.
	 * @return string
	 */
	public static function preview( array $format ) {
		return self::generate( $format );
	}

	/**
	 * Normalise user-typed code for lookup: trim, drop inner spaces, uppercase.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function normalize_input( $code ) {
		$code = preg_replace( '/\s+/', '', (string) $code );
		return strtoupper( substr( $code, 0, 64 ) );
	}
}
