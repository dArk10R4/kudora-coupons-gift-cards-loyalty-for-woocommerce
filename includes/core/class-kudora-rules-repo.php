<?php
/**
 * Storage for discount rules (module `discount`) and loyalty earning rules
 * (module `earn`). Conditions and type-specific config are JSON columns.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules repository.
 */
class Kudora_Rules_Repo {

	/**
	 * Per-request cache of active rules by module.
	 *
	 * @var array<string,array[]>
	 */
	protected static $active = array();

	/**
	 * Per-request cache of rules by id (null = not found).
	 *
	 * @var array<int,array|null>
	 */
	protected static $by_id = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Kudora_DB::table( 'rules' );
	}

	/**
	 * Decode a row.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	protected static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row['id']          = (int) $row['id'];
		$row['status']      = (int) $row['status'];
		$row['priority']    = (int) $row['priority'];
		$row['exclusive']   = (int) $row['exclusive'];
		$row['usage_count'] = (int) $row['usage_count'];
		$row['usage_limit'] = null === $row['usage_limit'] ? null : (int) $row['usage_limit'];
		$row['conditions']  = $row['conditions'] ? json_decode( $row['conditions'], true ) : array();
		$row['config']      = $row['config'] ? json_decode( $row['config'], true ) : array();
		$row['conditions']  = is_array( $row['conditions'] ) ? $row['conditions'] : array();
		$row['config']      = is_array( $row['config'] ) ? $row['config'] : array();
		return $row;
	}

	/**
	 * Active rules of a module, by priority (lower first). Cached per request.
	 *
	 * @param string $module discount|earn.
	 * @return array[]
	 */
	public static function active( $module ) {
		global $wpdb;
		if ( isset( self::$active[ $module ] ) ) {
			return self::$active[ $module ];
		}
		$table = self::table();
		$now   = Kudora_DB::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, per-request cache.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE module = %s AND status = 1 AND (starts_at IS NULL OR starts_at <= %s) AND (ends_at IS NULL OR ends_at >= %s) AND (usage_limit IS NULL OR usage_count < usage_limit) ORDER BY priority ASC, id ASC", $table, $module, $now, $now ), ARRAY_A );
		self::$active[ $module ] = array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
		return self::$active[ $module ];
	}

	/**
	 * All rules of a module (admin list).
	 *
	 * @param string $module Module.
	 * @return array[]
	 */
	public static function all( $module ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE module = %s ORDER BY priority ASC, id ASC", $table, $module ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * One rule.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( array_key_exists( $id, self::$by_id ) ) {
			return self::$by_id[ $id ];
		}
		foreach ( self::$active as $rules ) {
			foreach ( $rules as $rule ) {
				if ( $rule['id'] === $id ) {
					self::$by_id[ $id ] = $rule;
					return $rule;
				}
			}
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, cached per request.
		self::$by_id[ $id ] = self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $table, $id ), ARRAY_A ) );
		return self::$by_id[ $id ];
	}

	/**
	 * Insert or update (already-sanitised data).
	 *
	 * @param array $data Fields; `id` updates.
	 * @return int Id.
	 */
	public static function save( array $data ) {
		global $wpdb;
		$row = array(
			'module'      => isset( $data['module'] ) ? sanitize_key( $data['module'] ) : 'discount',
			'type'        => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '',
			'name'        => isset( $data['name'] ) ? substr( sanitize_text_field( $data['name'] ), 0, 200 ) : '',
			'status'      => ! empty( $data['status'] ) ? 1 : 0,
			'priority'    => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
			'exclusive'   => ! empty( $data['exclusive'] ) ? 1 : 0,
			'conditions'  => wp_json_encode( isset( $data['conditions'] ) ? array_values( (array) $data['conditions'] ) : array() ),
			'config'      => wp_json_encode( isset( $data['config'] ) ? (array) $data['config'] : array() ),
			'starts_at'   => ! empty( $data['starts_at'] ) ? $data['starts_at'] : null,
			'ends_at'     => ! empty( $data['ends_at'] ) ? $data['ends_at'] : null,
			'usage_limit' => isset( $data['usage_limit'] ) && '' !== $data['usage_limit'] && null !== $data['usage_limit'] ? max( 0, (int) $data['usage_limit'] ) : null,
			'updated_at'  => Kudora_DB::now(),
		);
		self::flush();
		$table = self::table();
		if ( ! empty( $data['id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$wpdb->update( $table, $row, array( 'id' => (int) $data['id'] ) );
			return (int) $data['id'];
		}
		$row['created_at'] = Kudora_DB::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::flush();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Set status.
	 *
	 * @param int $id     Id.
	 * @param int $status 0/1.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		self::flush();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->update( self::table(), array( 'status' => $status ? 1 : 0 ), array( 'id' => (int) $id ) );
	}

	/**
	 * Count one use (atomic).
	 *
	 * @param int $id    Id.
	 * @param int $delta +1 / -1.
	 * @return void
	 */
	public static function count_use( $id, $delta = 1 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET usage_count = GREATEST(0, CAST(usage_count AS SIGNED) + %d) WHERE id = %d", $table, (int) $delta, $id ) );
		self::flush();
	}

	/**
	 * Drop the per-request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$active = array();
		self::$by_id  = array();
	}
}
