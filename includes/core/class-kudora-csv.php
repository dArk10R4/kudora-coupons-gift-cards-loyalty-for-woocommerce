<?php
/**
 * CSV helpers: streamed downloads with spreadsheet formula-injection protection,
 * and a small, strict reader for imports.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV helper.
 */
class Kudora_CSV {

	/**
	 * Max rows read from one file.
	 */
	const MAX_ROWS = 20000;

	/**
	 * Neutralise cells a spreadsheet would run as a formula.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Stream rows as a CSV download and exit.
	 *
	 * @param string $filename File name.
	 * @param array  $rows     Rows (first row = header).
	 * @return void
	 */
	public static function download( $filename, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet apps.
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'safe' ), (array) $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming to the response.
		exit;
	}

	/**
	 * Read a CSV file into associative rows keyed by lowercase header names.
	 *
	 * @param string $path File path.
	 * @return array{headers:string[],rows:array[]}|WP_Error
	 */
	public static function read( $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'kudora_csv', __( 'The file could not be read.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded CSV line by line.
		if ( ! $fh ) {
			return new WP_Error( 'kudora_csv', __( 'The file could not be read.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$headers = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $headers ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			return new WP_Error( 'kudora_csv', __( 'The file is empty.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$headers    = array_map(
			function ( $h ) {
				$h = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
				return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( $h ) ) ) );
			},
			$headers
		);
		$rows = array();
		while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- standard CSV loop.
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			if ( array( null ) === $line || ! array_filter( $line, 'strlen' ) ) {
				continue;
			}
			$row = array();
			foreach ( $headers as $i => $h ) {
				$cell      = isset( $line[ $i ] ) ? (string) $line[ $i ] : '';
				$row[ $h ] = sanitize_text_field( 0 === strpos( $cell, "'" ) && isset( $cell[1] ) && in_array( $cell[1], array( '=', '+', '-', '@' ), true ) ? substr( $cell, 1 ) : $cell );
			}
			$rows[] = $row;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}
}
