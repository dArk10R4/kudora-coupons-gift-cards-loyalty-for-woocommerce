<?php
/**
 * Gift card redemption as an after-tax payment.
 *
 * Applied codes live in the WooCommerce session. The cart total is reduced through
 * `woocommerce_calculated_total` (feeds the classic cart, the Store API total and
 * `needs_payment`). Classic checkout copies that total to the order; the Checkout
 * block rebuilds the order total from its items, so the order total is reduced
 * again in `woocommerce_order_after_calculate_totals` (fires before save). Once an
 * order records its gift card amounts they never grow again.
 *
 * Balances are debited when the order is placed and restored if it is voided.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card redemption.
 */
class Kudora_Gift_Card_Redeem {

	/**
	 * Session key holding applied codes (ordered list).
	 */
	const SESSION_CODES = 'kudora_gift_cards';

	/**
	 * Session key holding the last computed code => amount map.
	 */
	const SESSION_APPLIED = 'kudora_gift_cards_applied';

	/**
	 * Session key holding messages about cards dropped during calculation.
	 */
	const SESSION_NOTICES = 'kudora_gift_card_notices';

	/**
	 * Discount type used for the coupon-field hand-off.
	 */
	const HANDOFF_TYPE = 'kudora_gift_card';

	/**
	 * Messages for cards dropped during this request (checkout must stop: the
	 * customer confirmed a lower total).
	 *
	 * @var string[]
	 */
	protected static $dropped_now = array();

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_calculated_total', array( $this, 'cart_total' ), 999, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'classic_record' ), 20, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'order_totals' ), 20, 2 );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic_validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'store_api_validate' ), 20, 2 );

		add_action( 'kudora_order_placed', array( $this, 'debit_order' ), 10 );
		add_action( 'kudora_order_voided', array( $this, 'restore_order' ), 10, 2 );
		add_action( 'kudora_order_reinstated', array( $this, 'reinstate_order' ), 10, 3 );
		add_action( 'kudora_order_refunded', array( $this, 'refunded' ), 10, 3 );

		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'order_item_totals' ), 20, 2 );

		// Payment gateways allowed for gift card purchases, and no paying for gift cards with gift cards.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_gateways' ) );

		// Coupon-field hand-off.
		if ( Kudora_Settings::yes( 'gc_coupon_field' ) ) {
			add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'handoff_coupon_data' ), 20, 2 );
			add_filter( 'woocommerce_coupon_discount_types', array( $this, 'handoff_type' ) );
			add_filter( 'woocommerce_cart_coupon_types', array( $this, 'handoff_cart_type' ) );
			add_filter( 'woocommerce_coupon_is_valid', array( $this, 'handoff_validate' ), 20, 2 );
			add_action( 'woocommerce_applied_coupon', array( $this, 'handoff_applied' ), 5 );
			add_filter( 'woocommerce_coupon_message', array( $this, 'handoff_message' ), 20, 3 );
		}

		// Classic cart/checkout UI.
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'classic_rows' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'classic_rows' ) );
		add_action( 'wc_ajax_kudora_apply_gift_card', array( $this, 'ajax_apply' ) );
		add_action( 'wc_ajax_kudora_remove_gift_card', array( $this, 'ajax_remove' ) );
		add_action( 'woocommerce_after_cart_table', array( $this, 'classic_cart_form' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'classic_checkout_form' ), 12 );

		// Blocks / Store API.
		add_filter( 'kudora_store_api_cart_data', array( $this, 'store_api_data' ) );
		add_filter( 'kudora_store_api_action', array( $this, 'store_api_action' ), 10, 3 );
	}

	/**
	 * Gift card state for the Store API cart.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public function store_api_data( $data ) {
		$applied = array();
		foreach ( self::session_applied() as $code => $amount ) {
			$applied[] = array(
				'code'   => $code,
				'masked' => Kudora_Gift_Card_Repo::mask( $code ),
				'amount' => wc_format_decimal( $amount, wc_get_price_decimals() ),
				'minor'  => (int) round( $amount * pow( 10, wc_get_price_decimals() ) ),
			);
		}
		$data['gift_cards'] = array(
			'applied' => $applied,
			'codes'   => self::session_codes(),
			'notices' => self::pop_notices(),
		);
		return $data;
	}

	/**
	 * Store API actions: gc_apply / gc_remove.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Action.
	 * @param array  $data   Data.
	 * @return mixed
	 */
	public function store_api_action( $result, $action, $data ) {
		if ( 'gc_apply' === $action ) {
			return self::apply_code( isset( $data['code'] ) ? sanitize_text_field( (string) $data['code'] ) : '' );
		}
		if ( 'gc_remove' === $action ) {
			self::remove_code( isset( $data['code'] ) ? sanitize_text_field( (string) $data['code'] ) : '' );
			return true;
		}
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Session
	 * ------------------------------------------------------------------- */

	/**
	 * Applied codes, in application order.
	 *
	 * @return string[]
	 */
	public static function session_codes() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) WC()->session->get( self::SESSION_CODES, array() ) ) ) );
	}

	/**
	 * Save applied codes.
	 *
	 * @param string[] $codes Codes.
	 * @return void
	 */
	protected static function save_codes( array $codes ) {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_CODES, array_values( array_unique( $codes ) ) );
		}
	}

	/**
	 * Last computed code => amount map.
	 *
	 * @return array<string,float>
	 */
	public static function session_applied() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		return (array) WC()->session->get( self::SESSION_APPLIED, array() );
	}

	/**
	 * Apply a code to the current session (validates first).
	 *
	 * @param string $raw Raw code.
	 * @return true|WP_Error
	 */
	public static function apply_code( $raw ) {
		$code = Kudora_Code_Generator::normalize_input( $raw );
		if ( '' === $code ) {
			return new WP_Error( 'kudora_gc_empty', __( 'Please enter a gift card code.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( ! self::rate_limit_ok() ) {
			return new WP_Error( 'kudora_gc_rate', __( 'Too many attempts. Please wait a few minutes and try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$card  = Kudora_Gift_Card_Repo::get_by_code( $code, true );
		$error = '';
		if ( ! Kudora_Gift_Card_Repo::is_usable( $card, $error ) ) {
			self::count_failure();
			return new WP_Error( 'kudora_gc_invalid', $error );
		}
		if ( WC()->cart && self::cart_has_gift_cards( WC()->cart ) && self::cart_only_gift_cards( WC()->cart ) ) {
			return new WP_Error( 'kudora_gc_for_gc', __( 'Gift cards cannot be used to buy gift cards.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$codes = self::session_codes();
		if ( in_array( $card['code'], $codes, true ) ) {
			return new WP_Error( 'kudora_gc_dupe', __( 'This gift card is already applied.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$codes[] = $card['code'];
		self::save_codes( $codes );
		return true;
	}

	/**
	 * Remove a code from the session.
	 *
	 * @param string $raw Code.
	 * @return void
	 */
	public static function remove_code( $raw ) {
		$code = Kudora_Code_Generator::normalize_input( $raw );
		self::save_codes( array_diff( self::session_codes(), array( $code ) ) );
	}

	/**
	 * Clear everything (after an order is placed).
	 *
	 * @return void
	 */
	public static function clear_session() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_CODES, array() );
			WC()->session->set( self::SESSION_APPLIED, array() );
		}
	}

	/**
	 * Brute-force guard for code guessing: 10 failed lookups per 10 minutes per client.
	 *
	 * @return bool
	 */
	public static function rate_limit_ok() {
		$limit = max( 3, Kudora_Settings::int( 'gc_balance_rate_limit' ) );
		return (int) get_transient( self::rate_key() ) < $limit;
	}

	/**
	 * Record a failed lookup.
	 *
	 * @return void
	 */
	public static function count_failure() {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Per-client throttle key.
	 *
	 * @return string
	 */
	protected static function rate_key() {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
		return 'kudora_gc_rl_' . md5( $ip . '|' . get_current_user_id() );
	}

	/* ---------------------------------------------------------------------
	 * Totals
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the cart holds a gift card product.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return bool
	 */
	public static function cart_has_gift_cards( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['kudora_gc'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the cart holds only gift card products.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return bool
	 */
	public static function cart_only_gift_cards( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['kudora_gc'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Allocate cards against an amount.
	 *
	 * @param string[] $codes     Codes in order.
	 * @param float    $total     Grand total the cards may pay.
	 * @param array    $lines     Normalised lines [ [ 'product' => WC_Product, 'amount' => incl-tax line total, 'is_gc' => bool ] ].
	 * @param array    $dropped   Out: code => reason for cards that cannot be used.
	 * @param array    $caps      Optional code => max amount (recorded amounts on an order).
	 * @return array<string,array{id:int,amount:float}>
	 */
	public static function allocate( array $codes, $total, array $lines, &$dropped = array(), array $caps = array() ) {
		$gc_lines  = 0.0;
		foreach ( $lines as $line ) {
			if ( $line['is_gc'] ) {
				$gc_lines += $line['amount'];
			}
		}
		// Gift cards never pay for gift cards (Booknetic rule).
		$remaining = max( 0.0, Kudora_Money::round( $total - $gc_lines ) );
		$applied   = array();
		foreach ( $codes as $code ) {
			$card  = Kudora_Gift_Card_Repo::get_by_code( $code );
			$error = '';
			if ( ! $caps && ! Kudora_Gift_Card_Repo::is_usable( $card, $error ) ) {
				$dropped[ $code ] = $error;
				continue;
			}
			if ( ! $card ) {
				continue;
			}
			$limit = isset( $caps[ $code ] ) ? (float) $caps[ $code ] : $card['balance'];
			if ( ! empty( $card['restrictions'] ) && ! Kudora_Scope::is_all( $card['restrictions'] ) ) {
				$eligible = 0.0;
				foreach ( $lines as $line ) {
					if ( ! $line['is_gc'] && $line['product'] && Kudora_Scope::matches_product( $card['restrictions'], $line['product'] ) ) {
						$eligible += $line['amount'];
					}
				}
				$limit = min( $limit, $eligible );
				if ( $eligible <= 0 && ! $caps ) {
					$dropped[ $code ] = __( 'This gift card cannot be used for the products in your cart.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
					continue;
				}
			}
			$use = Kudora_Money::round( min( $limit, $remaining ) );
			if ( $use <= 0 ) {
				continue;
			}
			$applied[ $card['code'] ] = array(
				'id'     => $card['id'],
				'amount' => $use,
			);
			$remaining = Kudora_Money::round( $remaining - $use );
		}
		return $applied;
	}

	/**
	 * Cart lines for allocation (incl. tax).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array[]
	 */
	protected static function cart_lines( $cart ) {
		$lines = array();
		foreach ( $cart->get_cart() as $item ) {
			$lines[] = array(
				'product' => isset( $item['data'] ) ? $item['data'] : null,
				'amount'  => (float) ( isset( $item['line_total'] ) ? $item['line_total'] : 0 ) + (float) ( isset( $item['line_tax'] ) ? $item['line_tax'] : 0 ),
				'is_gc'   => ! empty( $item['kudora_gc'] ),
			);
		}
		return $lines;
	}

	/**
	 * Order lines for allocation (incl. tax).
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	protected static function order_lines( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$lines[] = array(
				'product' => $item->get_product(),
				'amount'  => (float) $item->get_total() + (float) $item->get_total_tax(),
				'is_gc'   => '' !== (string) $item->get_meta( '_kudora_gc_data' ),
			);
		}
		return $lines;
	}

	/**
	 * Reduce the cart total by the applied cards.
	 *
	 * @param float   $total Cart total.
	 * @param WC_Cart $cart  Cart.
	 * @return float
	 */
	public function cart_total( $total, $cart ) {
		$codes = self::session_codes();
		if ( ! $codes || ! WC()->session ) {
			if ( WC()->session && WC()->session->get( self::SESSION_APPLIED ) ) {
				WC()->session->set( self::SESSION_APPLIED, array() );
			}
			return $total;
		}
		$dropped = array();
		$applied = self::allocate( $codes, (float) $total, self::cart_lines( $cart ), $dropped );
		if ( $dropped ) {
			self::save_codes( array_diff( $codes, array_keys( $dropped ) ) );
			$notices = (array) WC()->session->get( self::SESSION_NOTICES, array() );
			foreach ( $dropped as $code => $reason ) {
				/* translators: 1: masked gift card code, 2: reason */
				$notices[ $code ] = sprintf( __( 'Gift card %1$s was removed: %2$s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Gift_Card_Repo::mask( $code ), $reason );
			}
			WC()->session->set( self::SESSION_NOTICES, $notices );
			foreach ( $dropped as $code => $reason ) {
				self::$dropped_now[ $code ] = $notices[ $code ];
			}
			if ( ! self::is_rest() && ! wp_doing_ajax() && function_exists( 'wc_add_notice' ) ) {
				foreach ( $dropped as $code => $reason ) {
					wc_add_notice( $notices[ $code ], 'notice' );
				}
				WC()->session->set( self::SESSION_NOTICES, array() );
			}
		}
		$amounts = array();
		$sum     = 0.0;
		foreach ( $applied as $code => $a ) {
			$amounts[ $code ] = $a['amount'];
			$sum             += $a['amount'];
		}
		WC()->session->set( self::SESSION_APPLIED, $amounts );
		return max( 0.0, Kudora_Money::round( (float) $total - $sum ) );
	}

	/**
	 * Pop notices about dropped cards (for the Store API response).
	 *
	 * @return string[]
	 */
	public static function pop_notices() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$n = array_values( (array) WC()->session->get( self::SESSION_NOTICES, array() ) );
		WC()->session->set( self::SESSION_NOTICES, array() );
		return $n;
	}

	/**
	 * Whether this is a REST request.
	 *
	 * @return bool
	 */
	protected static function is_rest() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Classic checkout: the order total was copied from the reduced cart total;
	 * record which cards pay how much.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 * @return void
	 */
	public function classic_record( $order, $data = array() ) {
		$codes = self::session_codes();
		if ( ! $codes || ! WC()->cart ) {
			return;
		}
		if ( 'debited' === $order->get_meta( '_kudora_gc_state' ) ) {
			return; // Reused pending order that already paid with its cards.
		}
		// Record exactly the amounts the cart total was reduced by; the debit then
		// enforces that each card still holds them.
		$applied = array();
		foreach ( self::session_applied() as $code => $amount ) {
			$card = Kudora_Gift_Card_Repo::get_by_code( $code );
			if ( $card && $amount > 0 ) {
				$applied[ $card['code'] ] = array(
					'id'     => $card['id'],
					'amount' => Kudora_Money::round( $amount ),
				);
			}
		}
		$gross = (float) WC()->cart->get_total( 'edit' ) + array_sum( wp_list_pluck( $applied, 'amount' ) );
		self::write_order_meta( $order, $applied, $gross );
	}

	/**
	 * Order totals: rebuild the reduction whenever WooCommerce recalculates an order.
	 *
	 * @param bool     $and_taxes Whether taxes were recalculated.
	 * @param WC_Order $order     Order.
	 * @return void
	 */
	public function order_totals( $and_taxes, $order ) {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}
		$gross    = (float) $order->get_total();
		$recorded = self::order_cards( $order );
		$state    = (string) $order->get_meta( '_kudora_gc_state' );

		$building = 'store-api' === $order->get_created_via() && 'checkout-draft' === $order->get_status() && 'debited' !== $state && function_exists( 'WC' ) && WC()->session && ! is_admin();

		if ( $building ) {
			$codes = self::session_codes();
			if ( ! $codes && ! $recorded ) {
				return;
			}
			$applied = self::allocate( $codes, $gross, self::order_lines( $order ) );
			self::write_order_meta( $order, $applied, $gross );
		} else {
			if ( ! $recorded ) {
				return;
			}
			$caps = array();
			foreach ( $recorded as $code => $a ) {
				$caps[ $code ] = $a['amount'];
			}
			$applied = self::allocate( array_keys( $caps ), $gross, self::order_lines( $order ), $dropped, $caps );
			// Keep the recorded amounts (what was debited); only the payable total moves.
			$sum = array_sum( wp_list_pluck( $applied, 'amount' ) );
			$order->update_meta_data( '_kudora_gc_gross', Kudora_Money::round( $gross ) );
			$order->set_total( max( 0.0, Kudora_Money::round( $gross - $sum ) ) );
			return;
		}
		$sum = array_sum( wp_list_pluck( $applied, 'amount' ) );
		$order->set_total( max( 0.0, Kudora_Money::round( $gross - $sum ) ) );
	}

	/**
	 * Save card allocation on the order.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $applied Allocation.
	 * @param float    $gross   Gross total before cards.
	 * @return void
	 */
	protected static function write_order_meta( $order, array $applied, $gross ) {
		if ( ! $applied ) {
			$order->delete_meta_data( '_kudora_gift_cards' );
			$order->delete_meta_data( '_kudora_gc_gross' );
			return;
		}
		$order->update_meta_data( '_kudora_gift_cards', $applied );
		$order->update_meta_data( '_kudora_gc_gross', Kudora_Money::round( $gross ) );
	}

	/**
	 * Cards recorded on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,array{id:int,amount:float}>
	 */
	public static function order_cards( $order ) {
		$cards = $order->get_meta( '_kudora_gift_cards' );
		return is_array( $cards ) ? $cards : array();
	}

	/**
	 * Total paid by gift cards on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function order_amount( $order ) {
		return Kudora_Money::round( array_sum( wp_list_pluck( self::order_cards( $order ), 'amount' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Re-check applied cards before the classic checkout creates the order.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public function classic_validate( $data, $errors ) {
		foreach ( self::$dropped_now as $message ) {
			$errors->add( 'kudora_gift_card', $message . ' ' . __( 'Please review your order total.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		foreach ( $this->validation_errors() as $message ) {
			$errors->add( 'kudora_gift_card', $message );
		}
	}

	/**
	 * Re-check applied cards before the Store API takes payment.
	 *
	 * @param WC_Order $order  Order.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public function store_api_validate( $order, $errors ) {
		foreach ( self::$dropped_now as $message ) {
			$errors->add( 'kudora_gift_card', $message . ' ' . __( 'Please review your order total.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( 'debited' === $order->get_meta( '_kudora_gc_state' ) ) {
			return;
		}
		foreach ( self::order_cards( $order ) as $code => $a ) {
			$card  = Kudora_Gift_Card_Repo::get_by_code( $code, true );
			$error = '';
			if ( ! Kudora_Gift_Card_Repo::is_usable( $card, $error ) || $card['balance'] + 0.00001 < (float) $a['amount'] ) {
				$errors->add( 'kudora_gift_card', $error ? $error : __( 'The gift card balance changed. Please review your order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			}
		}
	}

	/**
	 * Problems with the session's applied cards.
	 *
	 * @return string[]
	 */
	protected function validation_errors() {
		$out = array();
		foreach ( self::session_applied() as $code => $amount ) {
			$card  = Kudora_Gift_Card_Repo::get_by_code( $code, true );
			$error = '';
			if ( ! Kudora_Gift_Card_Repo::is_usable( $card, $error ) ) {
				$out[] = $error;
			} elseif ( $card['balance'] + 0.00001 < (float) $amount ) {
				$out[] = __( 'The gift card balance changed. Please review your order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Order lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Debit the order's cards (once per round).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 * @throws Exception When a card can no longer cover its amount (checkout shows the message).
	 */
	public function debit_order( $order ) {
		$cards = self::order_cards( $order );
		if ( ! $cards || 'debited' === $order->get_meta( '_kudora_gc_state' ) ) {
			if ( $cards ) {
				self::clear_session();
			}
			return;
		}
		$result = self::debit_cards( $order, $cards );
		if ( is_wp_error( $result ) ) {
			$message = __( 'The balance of an applied gift card changed. Please review your order and try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			if ( self::is_rest() && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kudora_gift_card_balance', esc_html( $message ), 400 );
			}
			throw new Exception( esc_html( $message ) );
		}
		self::clear_session();
	}

	/**
	 * Debit all cards of an order in a new round; roll back the round on failure.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $cards Cards.
	 * @return true|WP_Error
	 */
	protected static function debit_cards( $order, array $cards ) {
		$round = (int) $order->get_meta( '_kudora_gc_round' ) + 1;
		$done  = array();
		foreach ( $cards as $code => $a ) {
			$key = sprintf( 'order:%d:gc:%d:debit:%d', $order->get_id(), $a['id'], $round );
			/* translators: %s: order number */
			$res = Kudora_Gift_Card_Repo::debit( $a['id'], $a['amount'], $key, $order->get_id(), sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ) );
			if ( is_wp_error( $res ) ) {
				foreach ( $done as $d ) {
					Kudora_Gift_Card_Repo::credit( $d['id'], $d['amount'], 'restore', sprintf( 'order:%d:gc:%d:rollback:%d', $order->get_id(), $d['id'], $round ), $order->get_id(), 'rollback' );
				}
				$order->update_meta_data( '_kudora_gc_round', $round );
				$order->save_meta_data();
				return $res;
			}
			$done[] = $a;
		}
		$order->update_meta_data( '_kudora_gc_round', $round );
		$order->update_meta_data( '_kudora_gc_state', 'debited' );
		$order->save_meta_data();

		$lines = array();
		foreach ( $cards as $code => $a ) {
			$lines[] = Kudora_Gift_Card_Repo::mask( $code ) . ' ' . Kudora_Money::plain( $a['amount'] );
		}
		/* translators: %s: list of masked gift card codes and amounts */
		$order->add_order_note( sprintf( __( 'Paid with gift card(s): %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), implode( ', ', $lines ) ) );
		return true;
	}

	/**
	 * Restore every debited card of an order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Why (status or event).
	 * @return void
	 */
	public function restore_order( $order, $reason = '' ) {
		if ( 'debited' !== $order->get_meta( '_kudora_gc_state' ) ) {
			return;
		}
		$round = (int) $order->get_meta( '_kudora_gc_round' );
		foreach ( self::order_cards( $order ) as $code => $a ) {
			$already = self::refunded_to_card( $order, (int) $a['id'] );
			$amount  = Kudora_Money::round( $a['amount'] - $already );
			if ( $amount > 0 ) {
				Kudora_Gift_Card_Repo::credit( $a['id'], $amount, 'restore', sprintf( 'order:%d:gc:%d:restore:%d', $order->get_id(), $a['id'], $round ), $order->get_id(), $reason );
			}
		}
		$order->update_meta_data( '_kudora_gc_state', 'restored' );
		$order->update_meta_data( '_kudora_gc_refunded', array() );
		$order->save_meta_data();
		$order->add_order_note( __( 'Gift card balance returned to the card(s).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
	}

	/**
	 * An order came back from cancelled/failed: spend the cards again. If a card no
	 * longer covers its amount and the order is unpaid, drop the cards so the
	 * customer pays the full total instead.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $from  Old status.
	 * @param string   $to    New status.
	 * @return void
	 */
	public function reinstate_order( $order, $from, $to ) {
		$cards = self::order_cards( $order );
		if ( ! $cards || 'restored' !== $order->get_meta( '_kudora_gc_state' ) ) {
			return;
		}
		$res = self::debit_cards( $order, $cards );
		if ( ! is_wp_error( $res ) ) {
			return;
		}
		if ( in_array( $to, array( 'pending', 'on-hold' ), true ) ) {
			$gross = (float) $order->get_meta( '_kudora_gc_gross' );
			$order->delete_meta_data( '_kudora_gift_cards' );
			$order->update_meta_data( '_kudora_gc_state', 'dropped' );
			if ( $gross > 0 ) {
				$order->set_total( $gross );
			}
			$order->save();
			$order->add_order_note( __( 'Gift card(s) could not be charged again (balance changed). They were removed and the order total now includes the full amount.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} else {
			$order->add_order_note( __( 'Warning: gift card(s) could not be charged again after this order was reinstated. Check the card balances.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
	}

	/**
	 * Full refund: return the card money too (setting).
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $is_full Whether the order is now fully refunded.
	 * @return void
	 */
	public function refunded( $order, $refund, $is_full ) {
		if ( ! $is_full || ! Kudora_Settings::yes( 'gc_restore_on_refund' ) ) {
			return;
		}
		$this->restore_order( $order, 'refund' );
	}

	/**
	 * Amount already refunded to one card from an order (admin "refund to card").
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $card_id Card id.
	 * @return float
	 */
	public static function refunded_to_card( $order, $card_id ) {
		$map = $order->get_meta( '_kudora_gc_refunded' );
		return is_array( $map ) && isset( $map[ $card_id ] ) ? (float) $map[ $card_id ] : 0.0;
	}

	/**
	 * Admin: refund part of what a card paid back onto that card.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $card_id Card id.
	 * @param float    $amount  Amount.
	 * @param string   $note    Note.
	 * @return true|WP_Error
	 */
	public static function refund_to_card( $order, $card_id, $amount, $note = '' ) {
		if ( 'debited' !== $order->get_meta( '_kudora_gc_state' ) ) {
			return new WP_Error( 'kudora_state', __( 'This order has no charged gift card to refund to.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$paid = 0.0;
		foreach ( self::order_cards( $order ) as $a ) {
			if ( (int) $a['id'] === (int) $card_id ) {
				$paid = (float) $a['amount'];
			}
		}
		$amount    = Kudora_Money::round( $amount );
		$available = Kudora_Money::round( $paid - self::refunded_to_card( $order, $card_id ) );
		if ( $amount <= 0 || $amount > $available + 0.00001 ) {
			/* translators: %s: amount */
			return new WP_Error( 'kudora_amount', sprintf( __( 'You can refund at most %s to this card.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $available ) ) );
		}
		$ok = Kudora_Gift_Card_Repo::credit( $card_id, $amount, 'refund', sprintf( 'order:%d:gc:%d:refund:%s', $order->get_id(), $card_id, wp_generate_uuid4() ), $order->get_id(), $note );
		if ( ! $ok ) {
			return new WP_Error( 'kudora_failed', __( 'The refund could not be saved.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$map             = $order->get_meta( '_kudora_gc_refunded' );
		$map             = is_array( $map ) ? $map : array();
		$map[ $card_id ] = Kudora_Money::round( ( isset( $map[ $card_id ] ) ? $map[ $card_id ] : 0 ) + $amount );
		$order->update_meta_data( '_kudora_gc_refunded', $map );
		$order->save_meta_data();
		$card = Kudora_Gift_Card_Repo::get( $card_id );
		/* translators: 1: amount, 2: masked card code */
		$order->add_order_note( sprintf( __( 'Refunded %1$s to gift card %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $amount ), $card ? Kudora_Gift_Card_Repo::mask( $card['code'] ) : '#' . $card_id ) );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * Add "Gift card" rows to order totals (thank-you page, My Account, emails).
	 *
	 * @param array    $rows  Rows.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function order_item_totals( $rows, $order ) {
		$cards = self::order_cards( $order );
		if ( ! $cards || 'dropped' === $order->get_meta( '_kudora_gc_state' ) ) {
			return $rows;
		}
		$new = array();
		foreach ( $rows as $key => $row ) {
			if ( 'order_total' === $key ) {
				foreach ( $cards as $code => $a ) {
					$new[ 'kudora_gc_' . $a['id'] ] = array(
						/* translators: %s: masked gift card code */
						'label' => sprintf( __( 'Gift card %s:', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Gift_Card_Repo::mask( $code ) ),
						'value' => '-' . wc_price( $a['amount'], array( 'currency' => $order->get_currency() ) ),
					);
				}
			}
			$new[ $key ] = $row;
		}
		return $new;
	}

	/**
	 * Classic cart/checkout rows.
	 *
	 * @return void
	 */
	public function classic_rows() {
		foreach ( self::session_applied() as $code => $amount ) {
			printf(
				'<tr class="kudora-gift-card-row"><th>%1$s</th><td data-title="%2$s">-%3$s <a href="#" class="kudora-remove-gift-card" data-code="%4$s">%5$s</a></td></tr>',
				/* translators: %s: masked gift card code */
				esc_html( sprintf( __( 'Gift card %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Gift_Card_Repo::mask( $code ) ) ),
				esc_attr__( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				wp_kses_post( wc_price( $amount ) ),
				esc_attr( $code ),
				esc_html__( '[Remove]', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' )
			);
		}
	}

	/**
	 * Gift card form below the classic cart table.
	 *
	 * @return void
	 */
	public function classic_cart_form() {
		wc_get_template( 'gift-card/apply-form.php', array( 'context' => 'cart' ), '', KUDORA_DIR . 'templates/' );
	}

	/**
	 * Gift card toggle + form above the classic checkout.
	 *
	 * @return void
	 */
	public function classic_checkout_form() {
		wc_get_template( 'gift-card/apply-form.php', array( 'context' => 'checkout' ), '', KUDORA_DIR . 'templates/' );
	}

	/**
	 * Classic AJAX: apply.
	 *
	 * @return void
	 */
	public function ajax_apply() {
		check_ajax_referer( 'kudora-gift-card', 'security' );
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$res  = self::apply_code( $code );
		if ( is_wp_error( $res ) ) {
			wc_add_notice( $res->get_error_message(), 'error' );
		} else {
			wc_add_notice( __( 'Gift card applied.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			WC()->cart->calculate_totals();
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
		check_ajax_referer( 'kudora-gift-card', 'security' );
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		self::remove_code( $code );
		WC()->cart->calculate_totals();
		wc_add_notice( __( 'Gift card removed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wc_print_notices();
		wp_die();
	}

	/* ---------------------------------------------------------------------
	 * Payment gateways
	 * ------------------------------------------------------------------- */

	/**
	 * Limit gateways when the cart holds gift card products (Booknetic setting).
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function restrict_gateways( $gateways ) {
		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart || ! self::cart_has_gift_cards( WC()->cart ) ) {
			return $gateways;
		}
		$allowed = Kudora_Settings::arr( 'gc_allowed_gateways' );
		if ( ! $allowed ) {
			return $gateways;
		}
		return array_intersect_key( $gateways, array_flip( $allowed ) );
	}

	/* ---------------------------------------------------------------------
	 * Coupon-field hand-off
	 * ------------------------------------------------------------------- */

	/**
	 * A gift card code typed into the coupon field becomes a zero-value coupon for a
	 * moment, then moves into the gift card slot.
	 *
	 * @param mixed $data Existing coupon data.
	 * @param mixed $code Code.
	 * @return mixed
	 */
	public function handoff_coupon_data( $data, $code ) {
		if ( false !== $data || ! is_string( $code ) || '' === $code || Kudora_Virtual_Coupons::is_ours( $code ) || is_admin() ) {
			return $data;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $data;
		}
		$card = Kudora_Gift_Card_Repo::get_by_code( $code );
		if ( ! $card ) {
			return $data;
		}
		// Never shadow a real coupon with the same code.
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return $data;
		}
		return array(
			'discount_type' => self::HANDOFF_TYPE,
			'amount'        => 0,
		);
	}

	/**
	 * Register the hand-off discount type.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function handoff_type( $types ) {
		$types[ self::HANDOFF_TYPE ] = __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $types;
	}

	/**
	 * Mark the hand-off type as a cart-level type.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function handoff_cart_type( $types ) {
		$types[] = self::HANDOFF_TYPE;
		return $types;
	}

	/**
	 * Validate the card behind a hand-off coupon.
	 *
	 * @param bool      $valid  Validity.
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 * @throws Exception With the card's problem.
	 */
	public function handoff_validate( $valid, $coupon ) {
		if ( ! $valid || self::HANDOFF_TYPE !== $coupon->get_discount_type() ) {
			return $valid;
		}
		$card  = Kudora_Gift_Card_Repo::get_by_code( $coupon->get_code(), true );
		$error = '';
		if ( ! Kudora_Gift_Card_Repo::is_usable( $card, $error ) ) {
			throw new Exception( esc_html( $error ), 100 );
		}
		return $valid;
	}

	/**
	 * Move the code from the coupon list into the gift card slot.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	public function handoff_applied( $code ) {
		if ( ! WC()->cart ) {
			return;
		}
		$coupon = new WC_Coupon( $code );
		if ( self::HANDOFF_TYPE !== $coupon->get_discount_type() ) {
			return;
		}
		$applied = array_values(
			array_filter(
				WC()->cart->get_applied_coupons(),
				function ( $c ) use ( $code ) {
					return ! wc_is_same_coupon( $c, $code );
				}
			)
		);
		WC()->cart->set_applied_coupons( $applied );
		$res = self::apply_code( $code );
		if ( is_wp_error( $res ) && 'kudora_gc_dupe' !== $res->get_error_code() && ! self::is_rest() ) {
			wc_add_notice( $res->get_error_message(), 'error' );
		}
	}

	/**
	 * Friendly success message for the hand-off.
	 *
	 * @param string    $msg      Message.
	 * @param int       $msg_code Code.
	 * @param WC_Coupon $coupon   Coupon.
	 * @return string
	 */
	public function handoff_message( $msg, $msg_code, $coupon ) {
		if ( $coupon instanceof WC_Coupon && self::HANDOFF_TYPE === $coupon->get_discount_type() ) {
			return __( 'Gift card applied.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		return $msg;
	}
}
