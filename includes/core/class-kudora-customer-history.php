<?php
/**
 * A customer's purchase history for conditions ("order count", "total spent",
 * "bought product/category before"): three aggregate queries instead of loading
 * every order, for both order storages (HPOS tables and legacy posts), with no
 * cap on the number of orders. Cached per customer and cleared whenever one of
 * their orders changes status or is refunded.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer history.
 */
class Kudora_Customer_History {

	/**
	 * Per-request memo: cache key => history.
	 *
	 * @var array<string,array>
	 */
	protected static $memo = array();

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_changed' ), 5, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'order_refunded' ), 5 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'order_refunded' ), 5, 2 );
		add_action( 'woocommerce_delete_order', array( $this, 'order_deleted' ), 5 );
		add_action( 'woocommerce_trash_order', array( $this, 'order_deleted' ), 5 );
	}

	/**
	 * History of a customer (user id, or billing email for guests).
	 *
	 * @param int           $user_id User id (0 for a guest).
	 * @param string        $email   Billing email (guests).
	 * @param WC_Order|null $exclude Order to leave out (a paid order being evaluated).
	 * @return array{count:int,spent:float,products:int[],categories:int[]}
	 */
	public static function get( $user_id, $email, $exclude = null ) {
		$user_id = (int) $user_id;
		$email   = strtolower( trim( (string) $email ) );
		$empty   = array(
			'count'      => 0,
			'spent'      => 0.0,
			'products'   => array(),
			'categories' => array(),
		);
		if ( $user_id <= 0 && '' === $email ) {
			return $empty;
		}
		// Leaving out an order only changes the numbers when it is itself paid.
		$skip = $exclude instanceof WC_Order && $exclude->get_id() && in_array( $exclude->get_status(), wc_get_is_paid_statuses(), true ) ? (int) $exclude->get_id() : 0;
		$key  = self::key( $user_id, $email );
		if ( ! $skip ) {
			if ( isset( self::$memo[ $key ] ) ) {
				return self::$memo[ $key ];
			}
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['count'], $cached['spent'], $cached['products'], $cached['categories'] ) ) {
				self::$memo[ $key ] = $cached;
				return $cached;
			}
		}
		$history = self::query( $user_id, $email, $skip );
		if ( ! $skip ) {
			self::$memo[ $key ] = $history;
			set_transient( $key, $history, DAY_IN_SECONDS );
		}
		return $history;
	}

	/**
	 * Cache key.
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Email.
	 * @return string
	 */
	protected static function key( $user_id, $email ) {
		return 'kudora_hist_' . ( $user_id > 0 ? 'u' . $user_id : md5( $email ) );
	}

	/**
	 * Forget a customer's cached history.
	 *
	 * @param WC_Order $order An order of theirs.
	 * @return void
	 */
	public static function flush_for( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$keys = array( self::key( 0, strtolower( (string) $order->get_billing_email() ) ) );
		if ( $order->get_customer_id() ) {
			$keys[] = self::key( (int) $order->get_customer_id(), '' );
		}
		foreach ( $keys as $key ) {
			unset( self::$memo[ $key ] );
			delete_transient( $key );
		}
	}

	/**
	 * Status changed.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function order_changed( $order_id, $from, $to, $order = null ) {
		self::flush_for( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/**
	 * Refund created or deleted.
	 *
	 * @param int $order_id  Order id (or refund id).
	 * @param int $parent_id Parent order id (refund deleted).
	 * @return void
	 */
	public function order_refunded( $order_id, $parent_id = 0 ) {
		self::flush_for( wc_get_order( $parent_id ? $parent_id : $order_id ) );
	}

	/**
	 * Order trashed or deleted.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function order_deleted( $order_id ) {
		self::flush_for( wc_get_order( $order_id ) );
	}

	/**
	 * Run the aggregate queries.
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Email.
	 * @param int    $skip    Order id to leave out.
	 * @return array
	 */
	protected static function query( $user_id, $email, $skip ) {
		global $wpdb;
		$hpos     = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$statuses = array_map(
			function ( $s ) {
				return 'wc-' . $s;
			},
			wc_get_is_paid_statuses()
		);
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$by_user  = $user_id > 0 ? 1 : 0;
		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- cached aggregate over core order tables; $in is a list of %s placeholders.
		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type = 'shop_order' AND status IN ($in) AND id <> %d AND ( ( %d = 1 AND customer_id = %d ) OR ( %d = 0 AND LOWER(billing_email) = %s ) )", array_merge( array( $orders ), $statuses, array( $skip, $by_user, $user_id, $by_user, $email ) ) ) );
			$ids    = array_map( 'intval', (array) $ids );
			if ( ! $ids ) {
				return self::shape( 0, 0.0, array() );
			}
			$list  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM %i WHERE id IN ($list)", array_merge( array( $orders ), $ids ) ) );
			// Refund rows carry negative totals.
			$back = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(-SUM(total_amount),0) FROM %i WHERE type = 'shop_order_refund' AND parent_order_id IN ($list)", array_merge( array( $orders ), $ids ) ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'shop_order' AND p.post_status IN ($in) AND p.ID <> %d AND ( ( %d = 1 AND m.meta_value = %s ) OR ( %d = 0 AND LOWER(m.meta_value) = %s ) )", array_merge( array( $wpdb->posts, $wpdb->postmeta, $by_user ? '_customer_user' : '_billing_email' ), $statuses, array( $skip, $by_user, (string) $user_id, $by_user, $email ) ) ) );
			$ids = array_map( 'intval', (array) $ids );
			if ( ! $ids ) {
				return self::shape( 0, 0.0, array() );
			}
			$list  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(meta_value),0) FROM %i WHERE meta_key = '_order_total' AND post_id IN ($list)", array_merge( array( $wpdb->postmeta ), $ids ) ) );
			$back  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(m.meta_value),0) FROM %i r INNER JOIN %i m ON m.post_id = r.ID AND m.meta_key = '_refund_amount' WHERE r.post_type = 'shop_order_refund' AND r.post_parent IN ($list)", array_merge( array( $wpdb->posts, $wpdb->postmeta ), $ids ) ) );
		}
		$list     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$products = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT im.meta_value FROM %i i INNER JOIN %i im ON im.order_item_id = i.order_item_id AND im.meta_key IN ('_product_id','_variation_id') WHERE i.order_item_type = 'line_item' AND i.order_id IN ($list) AND im.meta_value > 0", array_merge( array( $items, $itemmeta ), $ids ) ) );
		// phpcs:enable
		return self::shape( count( $ids ), $total - $back, array_map( 'intval', (array) $products ) );
	}

	/**
	 * Build the result, with the categories of the bought products (one query).
	 *
	 * @param int   $count    Orders.
	 * @param float $spent    Money spent.
	 * @param int[] $products Product and variation ids.
	 * @return array
	 */
	protected static function shape( $count, $spent, array $products ) {
		global $wpdb;
		$products = array_values( array_unique( array_filter( $products ) ) );
		$cats     = array();
		if ( $products ) {
			// Variations carry no categories: include their parents.
			$list = implode( ',', array_fill( 0, count( $products ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- cached; $list is a list of %d placeholders.
			$parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM %i WHERE ID IN ($list) AND post_parent > 0", array_merge( array( $wpdb->posts ), $products ) ) );
			$objects = array_values( array_unique( array_merge( $products, array_map( 'intval', (array) $parents ) ) ) );
			$list    = implode( ',', array_fill( 0, count( $objects ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- cached; $list is a list of %d placeholders.
			$cats = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.term_id FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' WHERE tr.object_id IN ($list)", array_merge( array( $wpdb->term_relationships, $wpdb->term_taxonomy ), $objects ) ) );
		}
		return array(
			'count'      => (int) $count,
			'spent'      => Kudora_Money::round( $spent ),
			'products'   => $products,
			'categories' => array_values( array_unique( array_map( 'intval', (array) $cats ) ) ),
		);
	}
}
