<?php
/**
 * Paying with points (Booknetic "Redeem"), as a pre-tax discount through the
 * per-customer virtual coupon `kudora-points`.
 *
 * Booknetic formula (kept identical):
 *   maxAllowed  = percent ? subtotal * value / 100 : value
 *   finalRedeem = auto ? min(maxAllowed, subtotal) : min(maxAllowed, subtotal, input * pointValue)
 *   usedPoints  = floor(finalRedeem / pointValue), raised to minPoints when below it
 * Kudora additions: a maximum number of points per order, product/category
 * exclusions, and auto mode caps at the customer's balance instead of skipping.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points redemption.
 */
class Kudora_Loyalty_Redeem {

	/**
	 * Virtual coupon code.
	 */
	const CODE = 'kudora-points';

	/**
	 * Session: requested redemption array( mode => auto|custom|off, points => int ).
	 */
	const SESSION_REQUEST = 'kudora_points_request';

	/**
	 * Session: applied array( points => int, amount => float ).
	 */
	const SESSION_APPLIED = 'kudora_points_applied';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		Kudora_Virtual_Coupons::add_provider( 'points', array( $this, 'coupon_data' ), array( $this, 'coupon_label' ), array( $this, 'coupon_valid' ) );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_coupon' ), 30 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'record_applied' ), 30 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_removed' ) );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'record_on_order' ), 20, 4 );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic_validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'store_api_validate' ), 20, 2 );

		add_action( 'kudora_order_placed', array( $this, 'debit_order' ), 20 );
		add_action( 'kudora_order_voided', array( $this, 'restore_order' ), 20, 2 );
		add_action( 'kudora_order_reinstated', array( $this, 'reinstate_order' ), 20, 3 );
		add_action( 'kudora_order_refunded', array( $this, 'refunded' ), 20, 3 );

		add_filter( 'kudora_store_api_cart_data', array( $this, 'store_api_data' ) );
		add_filter( 'kudora_store_api_action', array( $this, 'store_api_action' ), 10, 3 );

		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'classic_box' ), 20 );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'classic_box' ), 20 );
		add_action( 'wc_ajax_kudora_apply_points', array( $this, 'ajax_apply' ) );
		add_action( 'wc_ajax_kudora_remove_points', array( $this, 'ajax_remove' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings & the Booknetic formula
	 * ------------------------------------------------------------------- */

	/**
	 * Redemption settings.
	 *
	 * @return array
	 */
	public static function settings() {
		return array(
			'enabled'    => Kudora_Settings::yes( 'loyalty_redeem_enabled' ),
			'value'      => max( 0.0001, Kudora_Settings::float( 'loyalty_point_value' ) ),
			'mode'       => 'auto' === Kudora_Settings::get( 'loyalty_redeem_mode' ) ? 'auto' : 'customer',
			'max_type'   => 'fixed' === Kudora_Settings::get( 'loyalty_max_discount_type' ) ? 'fixed' : 'percent',
			'max_value'  => Kudora_Settings::float( 'loyalty_max_discount_value' ),
			'min_points' => max( 0, Kudora_Settings::int( 'loyalty_min_points' ) ),
			'max_points' => max( 0, Kudora_Settings::int( 'loyalty_max_points_per_order' ) ),
		);
	}

	/**
	 * Booknetic's redeem calculation, plus Kudora's caps.
	 *
	 * @param float    $subtotal Eligible subtotal (excl. tax).
	 * @param int      $balance  Customer balance.
	 * @param int|null $input    Points typed by the customer (null = auto mode).
	 * @param array    $s        Settings (defaults to self::settings()).
	 * @return array{amount:float,points:int,error:string}
	 */
	public static function compute( $subtotal, $balance, $input = null, array $s = array() ) {
		$s        = $s ? $s : self::settings();
		$none     = array(
			'amount' => 0.0,
			'points' => 0,
			'error'  => '',
		);
		$subtotal = (float) $subtotal;
		if ( ! $s['enabled'] || $subtotal <= 0 ) {
			return $none;
		}
		if ( null !== $input ) {
			$input = (int) $input;
			if ( $input <= 0 ) {
				$none['error'] = __( 'Enter how many points you want to use.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
				return $none;
			}
			if ( $s['min_points'] > 0 && $input < $s['min_points'] ) {
				/* translators: %d: minimum points */
				$none['error'] = sprintf( __( 'You need to use at least %d points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $s['min_points'] );
				return $none;
			}
			if ( $input > $balance ) {
				$none['error'] = __( 'You do not have that many points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
				return $none;
			}
		}

		$max_allowed = 'percent' === $s['max_type'] ? $subtotal * $s['max_value'] / 100 : $s['max_value'];
		if ( $max_allowed <= 0 ) {
			return $none;
		}
		$final = min( $max_allowed, $subtotal );
		if ( null !== $input ) {
			$final = min( $final, $input * $s['value'] );
		} else {
			// Kudora: auto mode uses what the customer has instead of skipping.
			$final = min( $final, $balance * $s['value'] );
		}
		$final = Kudora_Money::floor( $final );

		$used = (int) floor( $final / $s['value'] + 1e-9 );
		if ( $s['max_points'] > 0 && $used > $s['max_points'] ) {
			$used  = $s['max_points'];
			$final = Kudora_Money::floor( min( $final, $used * $s['value'] ) );
		}
		// Booknetic: the minimum is a floor on points charged, not a rejection.
		if ( $s['min_points'] > 0 && $used < $s['min_points'] ) {
			$used = $s['min_points'];
		}
		if ( $used <= 0 || $final <= 0 ) {
			return $none;
		}
		if ( $used > $balance ) {
			$none['error'] = __( 'You do not have enough points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			return $none;
		}
		return array(
			'amount' => (float) $final,
			'points' => $used,
			'error'  => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Session
	 * ------------------------------------------------------------------- */

	/**
	 * Requested redemption for this session.
	 *
	 * @return array{mode:string,points:int}
	 */
	public static function request() {
		$s   = self::settings();
		$req = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_REQUEST ) : null;
		if ( ! is_array( $req ) ) {
			return array(
				'mode'   => 'auto' === $s['mode'] ? 'auto' : 'off',
				'points' => 0,
			);
		}
		return array(
			'mode'   => in_array( $req['mode'], array( 'auto', 'custom', 'off' ), true ) ? $req['mode'] : 'off',
			'points' => (int) $req['points'],
		);
	}

	/**
	 * Save a request.
	 *
	 * @param string $mode   auto|custom|off.
	 * @param int    $points Points (custom).
	 * @return void
	 */
	public static function set_request( $mode, $points = 0 ) {
		if ( WC()->session ) {
			WC()->session->set(
				self::SESSION_REQUEST,
				array(
					'mode'   => $mode,
					'points' => (int) $points,
				)
			);
		}
	}

	/**
	 * Applied points/amount (after the last calculation).
	 *
	 * @return array{points:int,amount:float}
	 */
	public static function applied() {
		$a = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_APPLIED ) : null;
		return is_array( $a ) ? array(
			'points' => (int) $a['points'],
			'amount' => (float) $a['amount'],
		) : array(
			'points' => 0,
			'amount' => 0.0,
		);
	}

	/**
	 * Eligible subtotal of the cart (excludes gift cards and excluded products).
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return float
	 */
	public static function eligible_subtotal( $cart = null ) {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return 0.0;
		}
		$scope = self::exclusion_scope();
		$sum   = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['kudora_gc'] ) || empty( $item['data'] ) ) {
				continue;
			}
			if ( ! Kudora_Scope::matches_product( $scope, $item['data'] ) ) {
				continue;
			}
			$sum += (float) $item['data']->get_price( 'edit' ) * (int) $item['quantity'];
		}
		return $sum;
	}

	/**
	 * Scope that excludes the configured products/categories.
	 *
	 * @return array
	 */
	protected static function exclusion_scope() {
		return Kudora_Scope::sanitize(
			array(
				'exclude_products'   => Kudora_Settings::arr( 'loyalty_redeem_excluded_products' ),
				'exclude_categories' => Kudora_Settings::arr( 'loyalty_redeem_excluded_categories' ),
			)
		);
	}

	/**
	 * What the current request would redeem.
	 *
	 * @return array{amount:float,points:int,error:string}
	 */
	public static function compute_for_cart() {
		$user_id = get_current_user_id();
		$req     = self::request();
		if ( ! $user_id || 'off' === $req['mode'] ) {
			return array(
				'amount' => 0.0,
				'points' => 0,
				'error'  => '',
			);
		}
		return self::compute( self::eligible_subtotal(), Kudora_Points_Repo::balance( $user_id ), 'custom' === $req['mode'] ? $req['points'] : null );
	}

	/* ---------------------------------------------------------------------
	 * Virtual coupon
	 * ------------------------------------------------------------------- */

	/**
	 * Coupon props for `kudora-points`.
	 *
	 * @param string $code Code.
	 * @return array|false
	 */
	public function coupon_data( $code ) {
		if ( self::CODE !== $code ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || is_admin() && ! wp_doing_ajax() ) {
			// Order context: the recorded amount is rebuilt by the recalculation freeze.
			return array(
				'discount_type' => 'fixed_cart',
				'amount'        => 0,
			);
		}
		$r = self::compute_for_cart();
		if ( $r['amount'] <= 0 ) {
			return false;
		}
		$scope = self::exclusion_scope();
		return array(
			'discount_type'               => 'fixed_cart',
			'amount'                      => $r['amount'],
			'excluded_product_ids'        => $scope['exclude_products'],
			'excluded_product_categories' => $scope['exclude_categories'],
		);
	}

	/**
	 * Label.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public function coupon_label( $code ) {
		$applied = self::applied();
		$points  = $applied['points'] ? $applied['points'] : self::compute_for_cart()['points'];
		/* translators: %s: number of points */
		return sprintf( __( 'Loyalty points (%s)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), self::format_points( $points ) );
	}

	/**
	 * Only logged-in customers with a live request may hold the points coupon.
	 *
	 * @param string       $code      Code.
	 * @param WC_Discounts $discounts Discounts.
	 * @return true|string
	 */
	public function coupon_valid( $code, $discounts ) {
		if ( $discounts->get_object() instanceof WC_Order ) {
			return true;
		}
		if ( ! get_current_user_id() ) {
			return __( 'Please log in to use your points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		$r = self::compute_for_cart();
		return $r['points'] > 0 ? true : ( '' !== $r['error'] ? $r['error'] : __( 'Points cannot be used for this cart.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
	}

	/**
	 * Keep the coupon in the cart exactly when a redemption applies.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function sync_coupon( $cart ) {
		if ( ! Kudora_Settings::yes( 'loyalty_redeem_enabled' ) ) {
			return;
		}
		$has     = in_array( self::CODE, $cart->get_applied_coupons(), true );
		$r       = self::compute_for_cart();
		$applied = $cart->get_applied_coupons();
		if ( $r['points'] > 0 && ! $has ) {
			$applied[] = self::CODE;
			$cart->set_applied_coupons( $applied );
		} elseif ( $r['points'] <= 0 && $has ) {
			$cart->set_applied_coupons( array_values( array_diff( $applied, array( self::CODE ) ) ) );
		}
	}

	/**
	 * After totals: charge points for the discount actually given (WooCommerce may cap
	 * a fixed cart discount below the requested amount).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function record_applied( $cart ) {
		if ( ! WC()->session ) {
			return;
		}
		if ( ! in_array( self::CODE, $cart->get_applied_coupons(), true ) ) {
			WC()->session->set( self::SESSION_APPLIED, null );
			return;
		}
		// A fixed cart coupon amount is tax-exclusive unless the store enters prices with tax.
		$given = (float) $cart->get_coupon_discount_amount( self::CODE, ! wc_prices_include_tax() );
		$r = self::compute_for_cart();
		if ( $given > 0 && $given + 0.00001 < $r['amount'] ) {
			$r = self::compute( $given, Kudora_Points_Repo::balance( get_current_user_id() ), null, array_merge( self::settings(), array( 'max_type' => 'fixed', 'max_value' => $given ) ) );
		}
		WC()->session->set(
			self::SESSION_APPLIED,
			array(
				'points' => $r['points'],
				'amount' => $given > 0 ? $given : $r['amount'],
			)
		);
	}

	/**
	 * Removing the coupon (the × in the cart) opts out for the session.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	public function on_removed( $code ) {
		if ( self::CODE === wc_format_coupon_code( $code ) && self::is_explicit_removal() ) {
			self::set_request( 'off' );
		}
	}

	/**
	 * Whether the current request is the customer removing a coupon (the × in the
	 * cart), as opposed to WooCommerce dropping it during validation.
	 *
	 * @return bool
	 */
	protected static function is_explicit_removal() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only request inspection; WooCommerce verifies its own nonces.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'cart/remove-coupon' ) ) {
			return true;
		}
		if ( isset( $_GET['wc-ajax'] ) && 'remove_coupon' === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) ) {
			return true;
		}
		return isset( $_GET['remove_coupon'] );
		// phpcs:enable
	}

	/**
	 * Record the redemption on the order when the coupon line is created.
	 *
	 * @param WC_Order_Item_Coupon $item   Line.
	 * @param string               $code   Code.
	 * @param WC_Coupon            $coupon Coupon.
	 * @param WC_Order             $order  Order.
	 * @return void
	 */
	public function record_on_order( $item, $code, $coupon, $order ) {
		if ( self::CODE !== wc_format_coupon_code( $code ) || ! $order instanceof WC_Order ) {
			return;
		}
		if ( in_array( $order->get_meta( '_kudora_points_state' ), array( 'debited' ), true ) ) {
			return;
		}
		$applied = self::applied();
		$order->update_meta_data(
			'_kudora_points_redeem',
			array(
				'points' => $applied['points'],
				'amount' => $applied['amount'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Validation & lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Classic: balance still covers the redemption.
	 *
	 * @param array    $data   Data.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public function classic_validate( $data, $errors ) {
		$applied = self::applied();
		if ( $applied['points'] > 0 && Kudora_Points_Repo::row( get_current_user_id(), true )['balance'] < $applied['points'] ) {
			$errors->add( 'kudora_points', __( 'Your points balance changed. Please review your order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
	}

	/**
	 * Store API: balance still covers the redemption.
	 *
	 * @param WC_Order $order  Order.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public function store_api_validate( $order, $errors ) {
		$r = self::order_redeem( $order );
		if ( $r['points'] > 0 && 'debited' !== $order->get_meta( '_kudora_points_state' ) && Kudora_Points_Repo::row( $order->get_customer_id(), true )['balance'] < $r['points'] ) {
			$errors->add( 'kudora_points', __( 'Your points balance changed. Please review your order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
	}

	/**
	 * Redemption recorded on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array{points:int,amount:float}
	 */
	public static function order_redeem( $order ) {
		$r = $order->get_meta( '_kudora_points_redeem' );
		if ( ! is_array( $r ) || ! in_array( self::CODE, $order->get_coupon_codes(), true ) ) {
			return array(
				'points' => 0,
				'amount' => 0.0,
			);
		}
		return array(
			'points' => (int) $r['points'],
			'amount' => (float) $r['amount'],
		);
	}

	/**
	 * Deduct the points when the order is placed.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 * @throws Exception When the balance no longer covers it.
	 */
	public function debit_order( $order ) {
		$r = self::order_redeem( $order );
		if ( $r['points'] <= 0 || 'debited' === $order->get_meta( '_kudora_points_state' ) ) {
			return;
		}
		$res = self::debit( $order, $r['points'] );
		if ( is_wp_error( $res ) ) {
			$message = __( 'Your points balance changed. Please review your order and try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kudora_points_balance', esc_html( $message ), 400 );
			}
			throw new Exception( esc_html( $message ) );
		}
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_REQUEST, null );
			WC()->session->set( self::SESSION_APPLIED, null );
		}
	}

	/**
	 * Deduct in a new round.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $points Points.
	 * @return true|WP_Error
	 */
	protected static function debit( $order, $points ) {
		$round = (int) $order->get_meta( '_kudora_points_round' ) + 1;
		$res   = Kudora_Points_Repo::deduct(
			$order->get_customer_id(),
			$points,
			'redeemed',
			array(
				'entity_type' => 'order',
				'entity_id'   => $order->get_id(),
				/* translators: %s: order number */
				'note'        => sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
				'key'         => sprintf( 'order:%d:redeem:%d', $order->get_id(), $round ),
			)
		);
		$order->update_meta_data( '_kudora_points_round', $round );
		if ( ! is_wp_error( $res ) ) {
			$order->update_meta_data( '_kudora_points_state', 'debited' );
			/* translators: %s: points */
			$order->add_order_note( sprintf( __( 'Customer used %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), self::format_points( $points ) ) );
		}
		$order->save_meta_data();
		return $res;
	}

	/**
	 * Give the points back when the order is voided.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason.
	 * @return void
	 */
	public function restore_order( $order, $reason = '' ) {
		if ( 'debited' !== $order->get_meta( '_kudora_points_state' ) ) {
			return;
		}
		$r     = self::order_redeem( $order );
		$round = (int) $order->get_meta( '_kudora_points_round' );
		Kudora_Points_Repo::add(
			$order->get_customer_id(),
			$r['points'],
			'restored',
			array(
				'entity_type' => 'order',
				'entity_id'   => $order->get_id(),
				/* translators: %s: order number */
				'note'        => sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
				'key'         => sprintf( 'order:%d:redeem-restore:%d', $order->get_id(), $round ),
				'lifetime'    => false,
			)
		);
		$order->update_meta_data( '_kudora_points_state', 'restored' );
		$order->save_meta_data();
		/* translators: %s: points */
		$order->add_order_note( sprintf( __( '%s returned to the customer.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), self::format_points( $r['points'] ) ) );
	}

	/**
	 * Reinstated order: deduct again; if the customer no longer has the points and
	 * the order is unpaid, remove the points discount so they pay in full.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $from  Old status.
	 * @param string   $to    New status.
	 * @return void
	 */
	public function reinstate_order( $order, $from, $to ) {
		$r = self::order_redeem( $order );
		if ( $r['points'] <= 0 || 'restored' !== $order->get_meta( '_kudora_points_state' ) ) {
			return;
		}
		$res = self::debit( $order, $r['points'] );
		if ( ! is_wp_error( $res ) ) {
			return;
		}
		if ( in_array( $to, array( 'pending', 'on-hold' ), true ) ) {
			$order->remove_coupon( self::CODE );
			$order->update_meta_data( '_kudora_points_state', 'dropped' );
			$order->save();
			$order->add_order_note( __( 'The customer no longer had enough points, so the points discount was removed and the order total updated.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} else {
			$order->add_order_note( __( 'Warning: the points used on this order could not be deducted again after it was reinstated.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
	}

	/**
	 * Full refund: give the redeemed points back.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $is_full Full.
	 * @return void
	 */
	public function refunded( $order, $refund, $is_full ) {
		if ( $is_full ) {
			$this->restore_order( $order, 'refund' );
		}
	}

	/* ---------------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------------- */

	/**
	 * "1,250 points".
	 *
	 * @param int $points Points.
	 * @return string
	 */
	public static function format_points( $points ) {
		/* translators: %s: number of points */
		return sprintf( _n( '%s point', '%s points', (int) $points, 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), number_format_i18n( (int) $points ) );
	}

	/**
	 * Largest number of points usable on this cart right now.
	 *
	 * @return int
	 */
	public static function max_usable() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		$r = self::compute( self::eligible_subtotal(), Kudora_Points_Repo::balance( $user_id ), null );
		return $r['points'];
	}

	/**
	 * Store API cart payload.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public function store_api_data( $data ) {
		$s       = self::settings();
		$user_id = get_current_user_id();
		$req     = self::request();
		$applied = self::applied();
		$in_cart = WC()->cart && in_array( self::CODE, WC()->cart->get_applied_coupons(), true );
		$data['points'] = array(
			'enabled'     => $s['enabled'],
			'logged_in'   => (bool) $user_id,
			'balance'     => $user_id ? Kudora_Points_Repo::balance( $user_id ) : 0,
			'mode'        => $s['mode'],
			'request'     => $req['mode'],
			'applied'     => array(
				'points' => $in_cart ? $applied['points'] : 0,
				'amount' => $in_cart ? wc_format_decimal( $applied['amount'], wc_get_price_decimals() ) : '0',
			),
			'max_points'  => self::max_usable(),
			'min_points'  => $s['min_points'],
			'point_value' => $s['value'],
			'earn'        => Kudora_Loyalty_Earn::estimate_for_cart(),
			'label'       => Kudora_Frontend::title_for( 'points' ),
			'login_url'   => wc_get_page_permalink( 'myaccount' ),
		);
		return $data;
	}

	/**
	 * Store API actions: points_apply (points), points_auto, points_remove.
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param array  $data   Data.
	 * @return mixed
	 */
	public function store_api_action( $result, $action, $data ) {
		if ( 'points_apply' === $action ) {
			return self::apply_request( isset( $data['points'] ) ? (int) $data['points'] : 0 );
		}
		if ( 'points_auto' === $action ) {
			self::set_request( 'auto' );
			return true;
		}
		if ( 'points_remove' === $action ) {
			self::set_request( 'off' );
			return true;
		}
		return $result;
	}

	/**
	 * Validate + save a customer-mode request.
	 *
	 * @param int $points Points.
	 * @return true|WP_Error
	 */
	public static function apply_request( $points ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'kudora_points_login', __( 'Please log in to use your points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$r = self::compute( self::eligible_subtotal(), Kudora_Points_Repo::balance( $user_id ), (int) $points );
		if ( '' !== $r['error'] ) {
			return new WP_Error( 'kudora_points_invalid', $r['error'] );
		}
		if ( $r['points'] <= 0 ) {
			return new WP_Error( 'kudora_points_none', __( 'Points cannot be used for this cart.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		self::set_request( 'custom', (int) $points );
		return true;
	}

	/**
	 * Classic cart/checkout box.
	 *
	 * @return void
	 */
	public function classic_box() {
		$s = self::settings();
		if ( ! $s['enabled'] || ! is_user_logged_in() ) {
			return;
		}
		$balance = Kudora_Points_Repo::balance( get_current_user_id() );
		$applied = self::applied();
		$active  = WC()->cart && in_array( self::CODE, WC()->cart->get_applied_coupons(), true );
		if ( $balance <= 0 && ! $active ) {
			return;
		}
		wc_get_template(
			'loyalty/redeem-box.php',
			array(
				'balance'  => $balance,
				'applied'  => $active ? $applied : array(
					'points' => 0,
					'amount' => 0,
				),
				'max'      => self::max_usable(),
				'settings' => $s,
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}

	/**
	 * Classic AJAX: apply.
	 *
	 * @return void
	 */
	public function ajax_apply() {
		check_ajax_referer( 'kudora-points', 'security' );
		$points = isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0;
		$res    = 'auto' === ( isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '' ) ? true : self::apply_request( $points );
		if ( isset( $_POST['mode'] ) && 'auto' === sanitize_key( $_POST['mode'] ) ) {
			self::set_request( 'auto' );
		}
		if ( is_wp_error( $res ) ) {
			wc_add_notice( $res->get_error_message(), 'error' );
		} else {
			WC()->cart->calculate_totals();
			wc_add_notice( __( 'Points applied.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		wc_print_notices();
		wp_die();
	}

	/**
	 * Classic AJAX: remove.
	 *
	 * @return void
	 */
	public function ajax_remove() {
		check_ajax_referer( 'kudora-points', 'security' );
		self::set_request( 'off' );
		WC()->cart->calculate_totals();
		wc_add_notice( __( 'Points removed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wc_print_notices();
		wp_die();
	}
}
