<?php
/**
 * Small database helpers: table names, nested-safe transactions, UTC timestamps and
 * idempotent inserts. All money and points movements go through transactions here.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database helper.
 */
class Kudora_DB {

	/**
	 * Current transaction nesting depth. MySQL has no nested transactions, so only
	 * the outermost call issues START TRANSACTION / COMMIT / ROLLBACK.
	 *
	 * @var int
	 */
	protected static $depth = 0;

	/**
	 * Full table name.
	 *
	 * @param string $name Short name, e.g. `gift_cards`.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'kudora_' . $name;
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Run a callback inside a transaction. Any exception rolls the whole (outermost)
	 * transaction back and is re-thrown.
	 *
	 * @param callable $callback Work to do.
	 * @return mixed Callback result.
	 * @throws Throwable Re-thrown from the callback.
	 */
	public static function transaction( $callback ) {
		global $wpdb;
		$outer = ( 0 === self::$depth );
		if ( $outer ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'START TRANSACTION' );
		}
		++self::$depth;
		try {
			$result = call_user_func( $callback );
			--self::$depth;
			if ( $outer ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
				$wpdb->query( 'COMMIT' );
			}
			return $result;
		} catch ( Throwable $e ) {
			--self::$depth;
			if ( $outer ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
				$wpdb->query( 'ROLLBACK' );
			}
			throw $e;
		}
	}

	/**
	 * Insert a row, silently skipping it when a UNIQUE key (e.g. an idempotency key)
	 * already exists.
	 *
	 * @param string $table Full table name.
	 * @param array  $data  Column => value (null values become SQL NULL).
	 * @return int Insert id, or 0 when the row already existed.
	 */
	public static function insert_ignore( $table, array $data ) {
		global $wpdb;
		$cols   = array();
		$places = array();
		$values = array();
		foreach ( $data as $col => $value ) {
			$cols[] = '`' . preg_replace( '/[^a-z0-9_]/', '', $col ) . '`';
			if ( null === $value ) {
				$places[] = 'NULL';
				continue;
			}
			if ( is_int( $value ) ) {
				$places[] = '%d';
			} elseif ( is_float( $value ) ) {
				$places[] = '%s';
				$value    = wc_format_decimal( $value, 4 );
			} else {
				$places[] = '%s';
			}
			$values[] = $value;
		}
		$sql = 'INSERT IGNORE INTO `' . preg_replace( '/[^A-Za-z0-9_]/', '', $table ) . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $places ) . ')';
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			$sql = $wpdb->prepare( $sql, $values );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above; own table.
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0 ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Take a MySQL named lock (serialises e.g. two concurrent "order paid" events).
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait.
	 * @return bool
	 */
	public static function lock( $name, $timeout = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', substr( $wpdb->prefix . 'kudora_' . $name, 0, 64 ), $timeout ) );
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function unlock( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $wpdb->prefix . 'kudora_' . $name, 0, 64 ) ) );
	}

	/**
	 * Throw when the last query failed, so a transaction rolls back.
	 *
	 * @param mixed  $result  wpdb return value.
	 * @param string $context Context for the message.
	 * @return void
	 * @throws RuntimeException On a database error.
	 */
	public static function check( $result, $context = '' ) {
		global $wpdb;
		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Kudora DB error ' . $context . ': ' . $wpdb->last_error );
		}
	}
}
