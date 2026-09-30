<?php
/**
 * Gift card designs (Booknetic "templates"): a background colour or image, a text
 * colour and a name. Customers pick one on the product page; it styles the email,
 * the printable card and the PDF.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design repository.
 */
class Kudora_Gift_Card_Designs {

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	protected static $all = null;

	/**
	 * All designs, sorted.
	 *
	 * @param bool $include_inactive Include disabled designs.
	 * @return array[]
	 */
	public static function all( $include_inactive = false ) {
		global $wpdb;
		if ( null === self::$all ) {
			$table = Kudora_DB::table( 'gift_card_designs' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, per-request cache.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY sort_order ASC, id ASC", $table ), ARRAY_A );
			foreach ( $rows as &$r ) {
				$r['id']         = (int) $r['id'];
				$r['image_id']   = (int) $r['image_id'];
				$r['sort_order'] = (int) $r['sort_order'];
				$r['status']     = (int) $r['status'];
				$r['image_url']  = $r['image_id'] ? (string) wp_get_attachment_image_url( $r['image_id'], 'large' ) : '';
			}
			self::$all = $rows;
		}
		if ( $include_inactive ) {
			return self::$all;
		}
		return array_values(
			array_filter(
				self::$all,
				function ( $d ) {
					return 1 === $d['status'];
				}
			)
		);
	}

	/**
	 * One design.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all( true ) as $d ) {
			if ( $d['id'] === (int) $id ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * Active designs allowed for a product (empty list = all active).
	 *
	 * @param int[] $allowed Allowed ids.
	 * @return array[]
	 */
	public static function for_product( $allowed ) {
		$active  = self::all();
		$allowed = array_filter( array_map( 'intval', (array) $allowed ) );
		if ( ! $allowed ) {
			return $active;
		}
		return array_values(
			array_filter(
				$active,
				function ( $d ) use ( $allowed ) {
					return in_array( $d['id'], $allowed, true );
				}
			)
		);
	}

	/**
	 * Fallback design used when a card has none.
	 *
	 * @return array
	 */
	public static function fallback() {
		$active = self::all();
		return $active ? $active[0] : array(
			'id'         => 0,
			'name'       => '',
			'color'      => '#1f2a44',
			'text_color' => '#ffffff',
			'image_id'   => 0,
			'image_url'  => '',
		);
	}

	/**
	 * Create or update.
	 *
	 * @param array $data Fields; `id` updates.
	 * @return int Id.
	 */
	public static function save( array $data ) {
		global $wpdb;
		$row = array(
			'name'        => substr( sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ), 0, 200 ),
			'description' => substr( sanitize_text_field( isset( $data['description'] ) ? $data['description'] : '' ), 0, 100 ),
			'color'       => sanitize_hex_color( isset( $data['color'] ) ? $data['color'] : '' ) ? sanitize_hex_color( $data['color'] ) : '#1f2a44',
			'text_color'  => sanitize_hex_color( isset( $data['text_color'] ) ? $data['text_color'] : '' ) ? sanitize_hex_color( $data['text_color'] ) : '#ffffff',
			'image_id'    => isset( $data['image_id'] ) ? absint( $data['image_id'] ) : 0,
			'sort_order'  => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'status'      => ! empty( $data['status'] ) ? 1 : 0,
		);
		$table = Kudora_DB::table( 'gift_card_designs' );
		self::$all = null;
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
	 * Delete (cards keep working and fall back to the first design).
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::$all = null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->delete( Kudora_DB::table( 'gift_card_designs' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Inline style for a card surface.
	 *
	 * @param array $design Design.
	 * @return string
	 */
	public static function style( $design ) {
		$bg    = ! empty( $design['color'] ) ? $design['color'] : '#1f2a44';
		$fg    = ! empty( $design['text_color'] ) ? $design['text_color'] : '#ffffff';
		$style = 'background-color:' . $bg . ';color:' . $fg . ';';
		if ( ! empty( $design['image_url'] ) ) {
			$style .= 'background-image:url(' . esc_url( $design['image_url'] ) . ');background-size:cover;background-position:center;';
		}
		return $style;
	}
}
