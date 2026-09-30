<?php
/**
 * Smart coupons: extends native WooCommerce coupons with a start date, a weekday
 * and time-of-day schedule, cart/customer conditions, auto-apply, URL coupons,
 * a Buy X Get Y type, list columns (status, times used) and a usage-history box
 * (Booknetic coupon features, extended).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart coupons.
 */
class Kudora_Smart_Coupons {

	/**
	 * Session key: codes the customer removed (auto-apply must not bring them back).
	 */
	const SESSION_OPTOUT = 'kudora_coupon_optout';

	/**
	 * Session key: coupon from a URL waiting for a non-empty cart.
	 */
	const SESSION_PENDING = 'kudora_pending_coupon';

	/**
	 * Option caching the ids of auto-apply coupons.
	 */
	const AUTO_OPTION = 'kudora_auto_coupons';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		// Admin fields.
		add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_coupon_data_panels', array( $this, 'panel' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save' ), 20, 2 );
		add_filter( 'manage_edit-shop_coupon_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'column' ), 20, 2 );
		add_action( 'add_meta_boxes_shop_coupon', array( $this, 'usage_box' ) );

		// Validation.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 20, 3 );

		// Auto-apply + URL coupons.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'auto_apply' ), 26 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'remember_removal' ) );
		add_action( 'wp_loaded', array( $this, 'url_coupon' ), 30 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply_pending' ), 40 );
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	/**
	 * "Schedule & conditions" and "Buy X get Y" tabs.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['kudora_smart'] = array(
			'label'  => __( 'Schedule & conditions', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'target' => 'kudora_smart_coupon_data',
			'class'  => '',
		);
		$tabs['kudora_bxgy']  = array(
			'label'  => __( 'Buy X get Y', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'target' => 'kudora_bxgy_coupon_data',
			'class'  => 'kudora-show-bxgy',
		);
		return $tabs;
	}

	/**
	 * Panels.
	 *
	 * @param int       $coupon_id Coupon id.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return void
	 */
	public function panel( $coupon_id, $coupon = null ) {
		$coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( $coupon_id );
		wp_enqueue_script( 'kudora-admin-rules' );
		wp_enqueue_style( 'kudora-admin' );
		include KUDORA_DIR . 'includes/admin/views/coupon-panels.php';
	}

	/**
	 * Save.
	 *
	 * @param int       $coupon_id Coupon id.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return void
	 */
	public function save( $coupon_id, $coupon ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the coupon save nonce.
		$start    = isset( $_POST['_kudora_start'] ) ? sanitize_text_field( wp_unslash( $_POST['_kudora_start'] ) ) : '';
		$weekdays = isset( $_POST['_kudora_weekdays'] ) ? array_values( array_intersect( array_map( 'intval', (array) wp_unslash( $_POST['_kudora_weekdays'] ) ), range( 1, 7 ) ) ) : array();
		$from     = isset( $_POST['_kudora_time_from'] ) ? sanitize_text_field( wp_unslash( $_POST['_kudora_time_from'] ) ) : '';
		$to       = isset( $_POST['_kudora_time_to'] ) ? sanitize_text_field( wp_unslash( $_POST['_kudora_time_to'] ) ) : '';
		$auto     = isset( $_POST['_kudora_auto_apply'] ) ? 'yes' : 'no';
		$conds    = isset( $_POST['kudora_conditions'] ) ? Kudora_Conditions::sanitize( json_decode( wp_unslash( $_POST['kudora_conditions'] ), true ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON sanitised by Kudora_Conditions::sanitize().
		$bxgy     = isset( $_POST['kudora_bxgy'] ) && is_array( $_POST['kudora_bxgy'] ) ? Kudora_Rule_Calc::sanitize_bxgy( map_deep( wp_unslash( $_POST['kudora_bxgy'] ), 'sanitize_text_field' ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by map_deep + sanitize_bxgy().
		// phpcs:enable
		$coupon->update_meta_data( '_kudora_start', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ? $start : '' );
		$coupon->update_meta_data( '_kudora_weekdays', $weekdays );
		$coupon->update_meta_data( '_kudora_time_from', preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $from ) ? $from : '' );
		$coupon->update_meta_data( '_kudora_time_to', preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $to ) ? $to : '' );
		$coupon->update_meta_data( '_kudora_auto_apply', $auto );
		$coupon->update_meta_data( '_kudora_conditions', $conds );
		if ( null !== $bxgy ) {
			$coupon->update_meta_data( '_kudora_bxgy', $bxgy );
		}
		$coupon->save();
		self::refresh_auto_list();
	}

	/**
	 * Rebuild the cached list of auto-apply coupons.
	 *
	 * @return void
	 */
	public static function refresh_auto_list() {
		$q = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only on coupon save.
					array(
						'key'   => '_kudora_auto_apply',
						'value' => 'yes',
					),
				),
			)
		);
		update_option( self::AUTO_OPTION, array_map( 'intval', $q->posts ), true );
	}

	/**
	 * Status + times-used columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'coupon_code' === $k ) {
				$out['kudora_status'] = __( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		$out['kudora_used'] = __( 'Times used', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $out;
	}

	/**
	 * Coupon status (Booknetic: Inactive / Active / Expired, plus Limit reached).
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array{0:string,1:string} slug, label.
	 */
	public static function status( $coupon ) {
		$start = (string) $coupon->get_meta( '_kudora_start' );
		if ( '' !== $start && $start > wp_date( 'Y-m-d' ) ) {
			return array( 'inactive', __( 'Scheduled', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$exp = $coupon->get_date_expires();
		if ( $exp && $exp->getTimestamp() < time() ) {
			return array( 'expired', __( 'Expired', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return array( 'expired', __( 'Limit reached', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		return array( 'active', __( 'Active', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
	}

	/**
	 * Column output.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function column( $column, $post_id ) {
		if ( 'kudora_status' === $column ) {
			$status = self::status( new WC_Coupon( $post_id ) );
			printf( '<span class="kudora-badge kudora-badge-%1$s">%2$s</span>', esc_attr( $status[0] ), esc_html( $status[1] ) );
		} elseif ( 'kudora_used' === $column ) {
			$c = new WC_Coupon( $post_id );
			echo esc_html( $c->get_usage_limit() ? $c->get_usage_count() . ' / ' . $c->get_usage_limit() : (string) $c->get_usage_count() );
		}
	}

	/**
	 * Usage history meta box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function usage_box( $post ) {
		add_meta_box( 'kudora-coupon-usage', __( 'Usage history', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), array( $this, 'render_usage_box' ), 'shop_coupon', 'normal', 'low' );
	}

	/**
	 * Orders that used a code (works with HPOS and legacy storage: the order items
	 * table is shared).
	 *
	 * @param string $code  Code.
	 * @param int    $limit Limit.
	 * @return array[] order_id, discount.
	 */
	public static function usage( $code, $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- report query on core order item tables.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_id, m.meta_value AS discount FROM {$wpdb->prefix}woocommerce_order_items i LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id AND m.meta_key = 'discount_amount' WHERE i.order_item_type = 'coupon' AND LOWER(i.order_item_name) = %s ORDER BY i.order_id DESC LIMIT %d",
				wc_format_coupon_code( $code ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Render the usage box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_usage_box( $post ) {
		$rows = self::usage( $post->post_title );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'This coupon has not been used yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</th><th>' . esc_html__( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</th><th>' . esc_html__( 'Discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</th><th>' . esc_html__( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				continue;
			}
			printf(
				'<tr><td><a href="%1$s">#%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( trim( $order->get_formatted_billing_full_name() . ' ' . $order->get_billing_email() ) ),
				wp_kses_post( wc_price( (float) $row['discount'], array( 'currency' => $order->get_currency() ) ) ),
				esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ),
				esc_html( wc_get_order_status_name( $order->get_status() ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Start date, schedule and conditions.
	 *
	 * @param bool         $valid     Valid.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Discounts.
	 * @return bool
	 * @throws Exception With a customer message.
	 */
	public function validate( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! $coupon->get_id() ) {
			return $valid;
		}
		$object = $discounts->get_object();
		$now    = new DateTimeImmutable( 'now', wp_timezone() );
		if ( $object instanceof WC_Order ) {
			return $valid; // Keep admin order edits working.
		}
		$start = (string) $coupon->get_meta( '_kudora_start' );
		if ( '' !== $start && $now->format( 'Y-m-d' ) < $start ) {
			/* translators: %s: date */
			throw new Exception( esc_html( sprintf( __( 'This coupon can be used from %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $start . ' 12:00:00' ) ) ) ), 100 );
		}
		$days = array_filter( array_map( 'intval', (array) $coupon->get_meta( '_kudora_weekdays' ) ) );
		if ( $days && ! in_array( (int) $now->format( 'N' ), $days, true ) ) {
			throw new Exception( esc_html__( 'This coupon is not valid today.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 100 );
		}
		$from = (string) $coupon->get_meta( '_kudora_time_from' );
		$to   = (string) $coupon->get_meta( '_kudora_time_to' );
		if ( '' !== $from && '' !== $to && ! Kudora_Conditions::in_time_range( $now->format( 'H:i' ), array( $from, $to ) ) ) {
			/* translators: 1: from time, 2: to time */
			throw new Exception( esc_html( sprintf( __( 'This coupon is valid between %1$s and %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $from, $to ) ), 100 );
		}
		$conds = $coupon->get_meta( '_kudora_conditions' );
		$conds = is_array( $conds ) ? $conds : array();
		if ( $conds && $object instanceof WC_Cart && ! Kudora_Conditions::matches( $conds, Kudora_Context::from_cart( $object ) ) ) {
			throw new Exception( esc_html__( 'Your cart does not meet the conditions of this coupon.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 100 );
		}
		return $valid;
	}

	/* ---------------------------------------------------------------------
	 * Auto-apply & URL coupons
	 * ------------------------------------------------------------------- */

	/**
	 * Apply valid auto-apply coupons silently (unless the customer removed them).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function auto_apply( $cart ) {
		$ids = (array) get_option( self::AUTO_OPTION, array() );
		if ( ! $ids || $cart->is_empty() ) {
			return;
		}
		$optout  = WC()->session ? (array) WC()->session->get( self::SESSION_OPTOUT, array() ) : array();
		$applied = $cart->get_applied_coupons();
		$changed = false;
		foreach ( $ids as $id ) {
			$coupon = new WC_Coupon( (int) $id );
			$code   = wc_format_coupon_code( $coupon->get_code() );
			if ( ! $coupon->get_id() || in_array( $code, $applied, true ) || in_array( $code, $optout, true ) ) {
				continue;
			}
			// Respect individual-use coupons already in the cart.
			foreach ( $applied as $a ) {
				$other = new WC_Coupon( $a );
				if ( $other->get_individual_use() && ! Kudora_Virtual_Coupons::is_ours( $a ) ) {
					continue 2;
				}
			}
			$discounts = new WC_Discounts( $cart );
			if ( true !== $discounts->is_coupon_valid( $coupon ) ) {
				continue;
			}
			if ( $coupon->get_individual_use() ) {
				$applied = array_values( array_filter( $applied, array( 'Kudora_Virtual_Coupons', 'is_ours' ) ) );
			}
			$applied[] = $code;
			$changed   = true;
		}
		if ( $changed ) {
			$cart->set_applied_coupons( $applied );
		}
	}

	/**
	 * A removed auto-apply coupon stays removed for the session.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	public function remember_removal( $code ) {
		if ( ! WC()->session || Kudora_Virtual_Coupons::is_ours( $code ) || ! Kudora_Virtual_Coupons::is_explicit_removal() ) {
			return;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id || ! in_array( (int) $id, (array) get_option( self::AUTO_OPTION, array() ), true ) ) {
			return;
		}
		$optout   = (array) WC()->session->get( self::SESSION_OPTOUT, array() );
		$optout[] = wc_format_coupon_code( $code );
		WC()->session->set( self::SESSION_OPTOUT, array_values( array_unique( $optout ) ) );
	}

	/**
	 * `?coupon=CODE` on any page: apply now, or when the first product is added.
	 *
	 * @return void
	 */
	public function url_coupon() {
		$param = sanitize_key( (string) Kudora_Settings::get( 'discounts_url_param' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shareable coupon links.
		if ( '' === $param || empty( $_GET[ $param ] ) || is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shareable coupon links.
		$code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) );
		if ( '' === $code || Kudora_Virtual_Coupons::is_ours( $code ) ) {
			return;
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		if ( WC()->cart->is_empty() ) {
			WC()->session->set( self::SESSION_PENDING, $code );
			/* translators: %s: coupon code */
			wc_add_notice( sprintf( __( 'Coupon %s will be applied when you add a product to your cart.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( strtoupper( $code ) ) . '</strong>' ) );
			return;
		}
		if ( ! WC()->cart->has_discount( $code ) ) {
			WC()->cart->apply_coupon( $code );
		}
	}

	/**
	 * Apply a pending URL coupon once the cart has something in it.
	 *
	 * @return void
	 */
	public function apply_pending() {
		if ( ! WC()->session ) {
			return;
		}
		$code = (string) WC()->session->get( self::SESSION_PENDING, '' );
		if ( '' === $code ) {
			return;
		}
		WC()->session->set( self::SESSION_PENDING, '' );
		if ( ! WC()->cart->has_discount( $code ) ) {
			WC()->cart->apply_coupon( $code );
		}
	}
}
