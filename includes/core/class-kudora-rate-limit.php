<?php
/**
 * Atomic per-client attempt counters (stops gift card code guessing).
 *
 * Counters live in fixed 10-minute windows as non-autoloaded option rows that are
 * incremented with one atomic upsert, so parallel requests cannot all read "0"
 * and slip past the limit the way a transient read-then-write would. The client
 * is identified by REMOTE_ADDR only: forwarding headers can be forged, and a
 * site behind a trusted proxy maps the real address through `kudora_client_ip`.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter.
 */
class Kudora_Rate_Limit {

	/**
	 * Window length in seconds.
	 */
	const WINDOW = 600;

	/**
	 * Option name prefix.
	 */
	const PREFIX = 'kudora_rl_';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'kudora_daily_tasks', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * The client address used for throttling.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The client address that code-guessing limits are counted against.
		 *
		 * Defaults to REMOTE_ADDR. Behind a reverse proxy or CDN you trust, return the
		 * visitor's real address (for example from the header that proxy sets), or
		 * every visitor shares one counter.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'kudora_client_ip', $ip );
	}

	/**
	 * Counter row for a scope, the current client and the current window.
	 *
	 * @param string $scope Scope.
	 * @return string
	 */
	protected static function name( $scope ) {
		return self::PREFIX . sanitize_key( $scope ) . '_' . md5( self::client_ip() ) . '_' . (int) floor( time() / self::WINDOW );
	}

	/**
	 * Current count.
	 *
	 * @param string $scope Scope.
	 * @return int
	 */
	public static function count( $scope ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counter, must bypass the options cache.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::name( $scope ) ) );
	}

	/**
	 * Add one and return the new count (atomic).
	 *
	 * @param string $scope Scope.
	 * @return int
	 */
	public static function hit( $scope ) {
		global $wpdb;
		$name = self::name( $scope );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic increment.
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $wpdb->options, $name ) );
		return self::count( $scope );
	}

	/**
	 * Reserve an attempt before a lookup; false when the client is over the limit.
	 * A lookup that succeeds gives its attempt back with release().
	 *
	 * @param string $scope Scope.
	 * @param int    $limit Allowed failures per window.
	 * @return bool
	 */
	public static function attempt( $scope, $limit ) {
		return self::hit( $scope ) <= (int) $limit;
	}

	/**
	 * Give back an attempt (atomic, never below zero).
	 *
	 * @param string $scope Scope.
	 * @return void
	 */
	public static function release( $scope ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic decrement.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value = GREATEST(0, CAST(option_value AS SIGNED) - 1) WHERE option_name = %s", $wpdb->options, self::name( $scope ) ) );
	}

	/**
	 * Daily: drop counters of finished windows.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		$now  = (int) floor( time() / self::WINDOW );
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own counter rows.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->options, $like, '%\_' . $now, '%\_' . ( $now - 1 ) ) );
	}
}
