<?php
/**
 * Referral program: each customer shares `?kref=CODE`; the friend gets a first-order
 * discount (virtual coupon `kudora-referral`); the referrer gets points or a coupon
 * once the friend's order reaches the earn status — reversed on refund/cancel.
 *
 * Fraud rules: no self-referral (same user, email or billing email; optionally same
 * IP), new customers' first paid order only, minimum order, monthly cap.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Referrals.
 */
class Kudora_Referrals {

	/**
	 * Cookie name.
	 */
	const COOKIE = 'kudora_ref';

	/**
	 * Virtual coupon code.
	 */
	const CODE = 'kudora-referral';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! Kudora_Settings::yes( 'referral_enabled' ) ) {
			return;
		}
		add_action( 'wp_loaded', array( $this, 'capture' ), 20 );
		Kudora_Virtual_Coupons::add_provider( 'referral', array( $this, 'coupon_data' ), array( $this, 'coupon_label' ), array( $this, 'coupon_valid' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_coupon' ), 35 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 30 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'tag_order' ), 30 );
		add_action( 'kudora_order_placed', array( $this, 'record_pending' ), 30 );
		add_action( 'kudora_order_paid', array( $this, 'maybe_reward' ), 30 );
		add_action( 'kudora_order_completed', array( $this, 'maybe_reward' ), 30 );
		add_action( 'kudora_order_voided', array( $this, 'reverse' ), 40 );
		add_action( 'kudora_order_refunded', array( $this, 'reverse_on_full_refund' ), 40, 3 );
	}

	/**
	 * Referral link of a customer.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function link_for( $user_id ) {
		return add_query_arg( 'kref', Kudora_Points_Repo::referral_code( $user_id ), home_url( '/' ) );
	}

	/**
	 * Store `?kref=CODE` in a cookie and the session.
	 *
	 * @return void
	 */
	public function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public referral link.
		if ( empty( $_GET['kref'] ) || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public referral link.
		$code     = strtoupper( sanitize_key( wp_unslash( $_GET['kref'] ) ) );
		$referrer = Kudora_Points_Repo::user_by_referral_code( $code );
		if ( ! $referrer || get_current_user_id() === $referrer ) {
			return;
		}
		$days = max( 1, Kudora_Settings::int( 'referral_cookie_days' ) );
		wc_setcookie( self::COOKIE, $code, time() + $days * DAY_IN_SECONDS, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $code;
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( self::COOKIE, $code );
		}
	}

	/**
	 * Referrer id for the current visitor (0 = none).
	 *
	 * @return int
	 */
	public static function current_referrer() {
		$code = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$code = (string) WC()->session->get( self::COOKIE, '' );
		}
		if ( '' === $code && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$code = strtoupper( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		}
		if ( '' === $code ) {
			return 0;
		}
		$referrer = Kudora_Points_Repo::user_by_referral_code( $code );
		return ( $referrer && get_current_user_id() !== $referrer ) ? $referrer : 0;
	}

	/**
	 * Whether a buyer (user id / email) is a brand-new customer.
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Email.
	 * @param int    $exclude Order id to ignore.
	 * @return bool
	 */
	public static function is_new_customer( $user_id, $email, $exclude = 0 ) {
		$ctx          = new Kudora_Context();
		$ctx->user_id = (int) $user_id;
		$ctx->email   = strtolower( (string) $email );
		$ids          = $ctx->previous_paid_order_ids();
		if ( $user_id && $email ) {
			$ctx2          = new Kudora_Context();
			$ctx2->email   = strtolower( (string) $email );
			$ids           = array_merge( $ids, $ctx2->previous_paid_order_ids() );
		}
		return ! array_diff( array_unique( $ids ), array( (int) $exclude ) );
	}

	/**
	 * Self-referral / fraud check for a (referrer, buyer) pair.
	 *
	 * @param int      $referrer Referrer id.
	 * @param int      $user_id  Buyer user id.
	 * @param string   $email    Buyer billing email.
	 * @param WC_Order $order    Order (optional).
	 * @return string Reason, or '' when fine.
	 */
	public static function fraud_reason( $referrer, $user_id, $email, $order = null ) {
		$ref_user = get_userdata( $referrer );
		if ( ! $ref_user ) {
			return 'unknown_referrer';
		}
		if ( $user_id && $user_id === $referrer ) {
			return 'self';
		}
		$email = strtolower( (string) $email );
		if ( '' !== $email && ( strtolower( $ref_user->user_email ) === $email || strtolower( (string) get_user_meta( $referrer, 'billing_email', true ) ) === $email ) ) {
			return 'same_email';
		}
		if ( $order && Kudora_Settings::yes( 'referral_block_same_ip' ) ) {
			$ip = $order->get_customer_ip_address();
			if ( $ip ) {
				$their = wc_get_orders(
					array(
						'customer_id' => $referrer,
						'limit'       => 20,
						'return'      => 'objects',
					)
				);
				foreach ( $their as $o ) {
					if ( $o->get_customer_ip_address() === $ip ) {
						return 'same_ip';
					}
				}
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Friend discount (virtual coupon)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the friend discount applies to the current cart.
	 *
	 * @return bool
	 */
	protected static function friend_eligible() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || (float) Kudora_Settings::float( 'referral_friend_value' ) <= 0 ) {
			return false;
		}
		$referrer = self::current_referrer();
		if ( ! $referrer ) {
			return false;
		}
		$customer = WC()->cart->get_customer();
		$email    = $customer ? $customer->get_billing_email() : '';
		$user_id  = get_current_user_id();
		if ( ! $email && $user_id ) {
			$u     = get_userdata( $user_id );
			$email = $u ? $u->user_email : '';
		}
		if ( '' !== self::fraud_reason( $referrer, $user_id, $email ) ) {
			return false;
		}
		return self::is_new_customer( $user_id, $email );
	}

	/**
	 * Coupon props.
	 *
	 * @param string $code Code.
	 * @return array|false
	 */
	public function coupon_data( $code ) {
		if ( self::CODE !== $code ) {
			return false;
		}
		$percent = 'percent' === Kudora_Settings::get( 'referral_friend_type' );
		return array(
			'discount_type'  => $percent ? 'percent' : 'fixed_cart',
			'amount'         => $percent ? min( 100, Kudora_Settings::float( 'referral_friend_value' ) ) : Kudora_Settings::float( 'referral_friend_value' ),
			'minimum_amount' => Kudora_Settings::float( 'referral_min_order' ) > 0 ? Kudora_Settings::float( 'referral_min_order' ) : '',
		);
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function coupon_label() {
		return __( 'Referral welcome discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Validity (cart only; frozen on orders).
	 *
	 * @param string       $code      Code.
	 * @param WC_Discounts $discounts Discounts.
	 * @return true|string
	 */
	public function coupon_valid( $code, $discounts ) {
		if ( $discounts->get_object() instanceof WC_Order ) {
			return true;
		}
		return self::friend_eligible() ? true : __( 'The referral discount is only for a new customer’s first order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Keep the friend coupon applied exactly when eligible.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function sync_coupon( $cart ) {
		$applied  = $cart->get_applied_coupons();
		$has      = in_array( self::CODE, $applied, true );
		$eligible = self::friend_eligible();
		if ( $eligible && ! $has ) {
			$applied[] = self::CODE;
			$cart->set_applied_coupons( $applied );
		} elseif ( ! $eligible && $has ) {
			$cart->set_applied_coupons( array_values( array_diff( $applied, array( self::CODE ) ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Referrer reward
	 * ------------------------------------------------------------------- */

	/**
	 * Save the referrer on the order and open a referral record.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function tag_order( $order ) {
		if ( $order->get_meta( '_kudora_referrer' ) ) {
			return;
		}
		$referrer = self::current_referrer();
		if ( ! $referrer ) {
			return;
		}
		$order->update_meta_data( '_kudora_referrer', $referrer );
	}

	/**
	 * Open a pending referral record once the order exists.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function record_pending( $order ) {
		$referrer = (int) $order->get_meta( '_kudora_referrer' );
		if ( $referrer && ! self::row_for_order( $order->get_id() ) ) {
			self::record( $order, $referrer, 'pending' );
		}
	}

	/**
	 * Referral table row for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function row_for_order( $order_id ) {
		global $wpdb;
		$table = Kudora_DB::table( 'referrals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );
	}

	/**
	 * Record the outcome for an order (insert once, then update).
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $referrer Referrer.
	 * @param string   $status   pending|rewarded|rejected|reversed.
	 * @param string   $reason   Reason.
	 * @param string   $reward   Reward description.
	 * @return void
	 */
	protected static function record( $order, $referrer, $status, $reason = '', $reward = '' ) {
		global $wpdb;
		$table = Kudora_DB::table( 'referrals' );
		$now   = Kudora_DB::now();
		Kudora_DB::insert_ignore(
			$table,
			array(
				'referrer_id'      => (int) $referrer,
				'referred_user_id' => (int) $order->get_customer_id(),
				'referred_email'   => strtolower( (string) $order->get_billing_email() ),
				'order_id'         => (int) $order->get_id(),
				'status'           => $status,
				'reason'           => $reason,
				'reward'           => $reward,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'reason'     => $reason,
				'reward'     => $reward,
				'updated_at' => $now,
			),
			array( 'order_id' => (int) $order->get_id() )
		);
	}

	/**
	 * Reward the referrer when the referred order reaches the earn status.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function maybe_reward( $order ) {
		$referrer = (int) $order->get_meta( '_kudora_referrer' );
		if ( ! $referrer ) {
			return;
		}
		$want = Kudora_Settings::get( 'loyalty_earn_status' );
		if ( 'completed' === $want && 'completed' !== $order->get_status() ) {
			return;
		}
		$row = self::row_for_order( $order->get_id() );
		if ( $row && in_array( $row['status'], array( 'rewarded', 'rejected', 'reversed' ), true ) ) {
			return;
		}
		$reason = self::fraud_reason( $referrer, (int) $order->get_customer_id(), $order->get_billing_email(), $order );
		if ( '' === $reason && ! self::is_new_customer( (int) $order->get_customer_id(), $order->get_billing_email(), $order->get_id() ) ) {
			$reason = 'not_first_order';
		}
		$min = Kudora_Settings::float( 'referral_min_order' );
		if ( '' === $reason && $min > 0 && (float) $order->get_subtotal() + 0.00001 < $min ) {
			$reason = 'below_minimum';
		}
		$cap = Kudora_Settings::int( 'referral_monthly_cap' );
		if ( '' === $reason && $cap > 0 && self::rewarded_this_month( $referrer ) >= $cap ) {
			$reason = 'monthly_cap';
		}
		if ( '' !== $reason ) {
			self::record( $order, $referrer, 'rejected', $reason );
			return;
		}

		if ( 'coupon' === Kudora_Settings::get( 'referral_reward_type' ) ) {
			$coupon_id = self::create_reward_coupon( $referrer, $order );
			$label     = $coupon_id ? ( new WC_Coupon( $coupon_id ) )->get_code() : '';
		} else {
			$points = Kudora_Settings::int( 'referral_reward_points' );
			Kudora_Points_Repo::add(
				$referrer,
				$points,
				'referral',
				array(
					'entity_type' => 'order',
					'entity_id'   => $order->get_id(),
					/* translators: %s: order number */
					'note'        => sprintf( __( 'Referral order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
					'key'         => 'referral:' . $order->get_id(),
				)
			);
			$label = (string) $points;
		}
		self::record( $order, $referrer, 'rewarded', '', $label );
		Kudora_Emails::send( 'Kudora_Email_Referral_Reward', $referrer, $order->get_id(), $label );
	}

	/**
	 * Rewarded referrals of a referrer this calendar month.
	 *
	 * @param int $referrer Referrer.
	 * @return int
	 */
	public static function rewarded_this_month( $referrer ) {
		global $wpdb;
		$table = Kudora_DB::table( 'referrals' );
		$start = get_gmt_from_date( wp_date( 'Y-m-01 00:00:00' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d AND status = 'rewarded' AND updated_at >= %s", $referrer, $start ) );
	}

	/**
	 * Coupon reward for the referrer.
	 *
	 * @param int      $referrer Referrer.
	 * @param WC_Order $order    Order.
	 * @return int Coupon id (0 on failure).
	 */
	protected static function create_reward_coupon( $referrer, $order ) {
		$user = get_userdata( $referrer );
		if ( ! $user ) {
			return 0;
		}
		try {
			$coupon = new WC_Coupon();
			$coupon->set_code(
				Kudora_Code_Generator::unique(
					array(
						'prefix'  => 'REF-',
						'charset' => 'alnum',
						'length'  => 8,
						'group'   => 4,
					),
					function ( $c ) {
						return (bool) wc_get_coupon_id_by_code( $c );
					}
				)
			);
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( Kudora_Settings::float( 'referral_reward_amount' ) );
			$coupon->set_usage_limit( 1 );
			$coupon->set_email_restrictions( array( $user->user_email ) );
			$coupon->set_date_expires( time() + 90 * DAY_IN_SECONDS );
			$coupon->update_meta_data( '_kudora_referral_order', $order->get_id() );
			$coupon->update_meta_data( '_kudora_reward_user', $referrer );
			return (int) $coupon->save();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Take back a referral reward when the referred order is voided.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function reverse( $order ) {
		$row = self::row_for_order( $order->get_id() );
		if ( ! $row || 'rewarded' !== $row['status'] ) {
			return;
		}
		if ( 'coupon' === Kudora_Settings::get( 'referral_reward_type' ) ) {
			$id = wc_get_coupon_id_by_code( $row['reward'] );
			if ( $id ) {
				$coupon = new WC_Coupon( $id );
				if ( 0 === $coupon->get_usage_count() ) {
					wp_trash_post( $id );
				}
			}
		} else {
			Kudora_Points_Repo::deduct_up_to(
				(int) $row['referrer_id'],
				(int) $row['reward'],
				'reversed',
				array(
					'entity_type' => 'order',
					'entity_id'   => $order->get_id(),
					/* translators: %s: order number */
					'note'        => sprintf( __( 'Referral order #%s cancelled', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
					'key'         => 'referral-reverse:' . $order->get_id(),
					'lifetime'    => true,
				)
			);
		}
		self::record( $order, (int) $row['referrer_id'], 'reversed', 'order_voided', $row['reward'] );
	}

	/**
	 * Full refund reverses too.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $is_full Full.
	 * @return void
	 */
	public function reverse_on_full_refund( $order, $refund, $is_full ) {
		if ( $is_full ) {
			$this->reverse( $order );
		}
	}

	/**
	 * Stats for a referrer (My Account).
	 *
	 * @param int $referrer Referrer.
	 * @return array{rewarded:int,pending:int}
	 */
	public static function stats_for( $referrer ) {
		global $wpdb;
		$table = Kudora_DB::table( 'referrals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE referrer_id = %d GROUP BY status", $referrer ), ARRAY_A );
		$out  = array(
			'rewarded' => 0,
			'pending'  => 0,
		);
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r['status'] ] ) ) {
				$out[ $r['status'] ] = (int) $r['n'];
			}
		}
		return $out;
	}
}
