<?php
/**
 * Automatic discount rules.
 *
 * - Price rules (product adjustment, bulk/tiered) change the unit price in
 *   `woocommerce_before_calculate_totals`, always recomputed from the base price, so
 *   the sale price shows everywhere (classic cart, blocks, mini-cart, Store API).
 * - Cart rules (cart discount, Buy X Get Y, bundle) are virtual coupons
 *   `kudora-rule-{id}` of the custom type `kudora_rule`, computed per line.
 * - Free-shipping rules zero the matching shipping rates.
 * - Rule usage is counted when the order is paid (and uncounted if voided).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discount rules engine.
 */
class Kudora_Discount_Engine {

	/**
	 * Custom coupon type for rule coupons.
	 */
	const TYPE = 'kudora_rule';

	/**
	 * Custom coupon type for real Buy X Get Y coupons.
	 */
	const BXGY_TYPE = 'kudora_bxgy';

	/**
	 * Price rule types.
	 *
	 * @var string[]
	 */
	const PRICE_TYPES = array( 'product_adjustment', 'bulk' );

	/**
	 * Cart rule types (virtual coupons).
	 *
	 * @var string[]
	 */
	const CART_TYPES = array( 'cart_discount', 'bxgy', 'bundle' );

	/**
	 * Cart line product objects of this request (catalog prices skip them), each
	 * with the price this engine set on it and the price it replaced:
	 * {base:float|null, set:float|null}. Held by object, not by spl_object_id(),
	 * so a freed object's id can never pass its prices to a new one.
	 *
	 * @var SplObjectStorage|null
	 */
	protected static $lines = null;

	/**
	 * Items + WC_Discounts captured for our coupon types (code => array).
	 *
	 * @var array<string,array>
	 */
	protected static $captured = array();

	/**
	 * Re-entrancy guard for auto-added items.
	 *
	 * @var bool
	 */
	protected static $adjusting = false;

	/**
	 * Rule types with labels.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'product_adjustment' => __( 'Product discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'bulk'               => __( 'Bulk / tiered pricing', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'cart_discount'      => __( 'Cart discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'bxgy'               => __( 'Buy X get Y (BOGO)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'bundle'             => __( 'Bundle price (any N for X)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'free_shipping'      => __( 'Free shipping', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		Kudora_Virtual_Coupons::add_provider( 'rule-', array( $this, 'coupon_data' ), array( $this, 'coupon_label' ), array( $this, 'coupon_valid' ) );
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'discount_types' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( $this, 'cart_coupon_types' ) );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'capture_items' ), 10, 3 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'custom_discounts' ), 20, 2 );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_price_rules' ), 20 );
		// Also as soon as the cart is loaded, so the mini-cart and cart fragments of a
		// page that never recalculates show the rule prices too.
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'apply_price_rules' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_rule_coupons' ), 25 );
		// Before WooCommerce's own coupon check on page load, so a rule that just
		// ended is dropped quietly instead of as "coupon kudora-rule-N is invalid".
		add_action( 'woocommerce_check_cart_items', array( $this, 'sync_before_check' ), 0 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price_html' ), 20, 3 );

		add_filter( 'woocommerce_package_rates', array( $this, 'free_shipping' ), 50, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'package_signature' ) );

		add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_auto_add' ), 30 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remember_gift_removal' ), 20, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'maybe_auto_add' ), 30 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'maybe_auto_add' ), 30 );
		// Every calculation re-checks the gifts (address changes, rules ending, ...).
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_auto_add' ), 15, 0 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'line_item_rules' ), 10, 3 );
		add_action( 'kudora_order_placed', array( $this, 'record_rules' ), 5 );
		add_action( 'kudora_order_paid', array( $this, 'count_usage' ) );
		add_action( 'kudora_order_voided', array( $this, 'uncount_usage' ), 50 );

		add_filter( 'kudora_store_api_cart_data', array( $this, 'store_api_data' ) );
		add_action( 'woocommerce_before_cart', array( $this, 'classic_promos' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'classic_promos' ), 5 );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'bulk_table' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Active discount rules of given types.
	 *
	 * @param string[] $types Types.
	 * @return array[]
	 */
	public static function rules_of( array $types ) {
		return array_values(
			array_filter(
				Kudora_Rules_Repo::active( 'discount' ),
				function ( $r ) use ( $types ) {
					return in_array( $r['type'], $types, true );
				}
			)
		);
	}

	/**
	 * Tracked cart line objects.
	 *
	 * @return SplObjectStorage
	 */
	protected static function lines() {
		if ( null === self::$lines ) {
			self::$lines = new SplObjectStorage();
		}
		return self::$lines;
	}

	/**
	 * Mark a product object as a cart line (its price is managed here).
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public static function mark_cart_object( $product ) {
		$lines = self::lines();
		if ( ! isset( $lines[ $product ] ) ) {
			$lines[ $product ] = array(
				'base' => null,
				'set'  => null,
			);
		}
	}

	/**
	 * Whether a product object is a cart line (its price is managed here).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_cart_object( $product ) {
		return isset( self::lines()[ $product ] );
	}

	/**
	 * Unit price of a cart line before this engine's rules: the price it carries
	 * now (the product's own price, or whatever another plugin or the gift card
	 * amount set), unless that is the price this engine set, then the one it replaced.
	 *
	 * @param array $item Cart item.
	 * @return float
	 */
	public static function base_price( array $item ) {
		$product = $item['data'];
		self::mark_cart_object( $product );
		$mark    = self::lines()[ $product ];
		$current = (float) $product->get_price( 'edit' );
		if ( null !== $mark['set'] && abs( $current - $mark['set'] ) < 0.000001 ) {
			return (float) $mark['base'];
		}
		return $current;
	}

	/**
	 * Whether the cart carries a customer-entered coupon.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return bool
	 */
	protected static function has_user_coupon( $cart ) {
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( ! Kudora_Virtual_Coupons::is_ours( $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a rule's conditions hold for a context (plus the "not with coupons" switch).
	 *
	 * @param array          $rule Rule.
	 * @param Kudora_Context $ctx  Context.
	 * @param WC_Cart|null   $cart Cart.
	 * @return bool
	 */
	public static function rule_matches( array $rule, Kudora_Context $ctx, $cart = null ) {
		if ( ! empty( $rule['config']['skip_with_coupons'] ) && $cart && self::has_user_coupon( $cart ) ) {
			return false;
		}
		return Kudora_Conditions::matches( $rule['conditions'], $ctx );
	}

	/**
	 * Apply an adjustment to a unit price.
	 *
	 * @param float  $price  Price.
	 * @param string $adjust percent|fixed|fixed_price.
	 * @param float  $value  Value.
	 * @return float
	 */
	public static function adjust( $price, $adjust, $value ) {
		switch ( $adjust ) {
			case 'fixed':
				$new = $price - $value;
				break;
			case 'fixed_price':
				$new = min( $price, $value );
				break;
			default:
				$new = $price * ( 1 - min( 100, max( 0, $value ) ) / 100 );
		}
		return max( 0.0, round( $new, wc_get_price_decimals() + 2 ) );
	}

	/* ---------------------------------------------------------------------
	 * Price rules
	 * ------------------------------------------------------------------- */

	/**
	 * Apply product adjustment and bulk rules to cart prices.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function apply_price_rules( $cart ) {
		$rules = self::rules_of( self::PRICE_TYPES );
		$ctx   = null;
		$excl  = Kudora_Settings::yes( 'discounts_exclude_gift_cards' );
		$price = array();
		$lock  = array();
		$hits  = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['data'] ) ) {
				continue;
			}
			$price[ $key ] = self::base_price( $item );
			$hits[ $key ]  = array();
		}
		$base = $price;
		if ( $rules ) {
			$ctx = Kudora_Context::from_cart(
				$cart,
				function ( $item ) {
					return Kudora_Discount_Engine::base_price( $item );
				}
			);
		}

		foreach ( $rules as $rule ) {
			if ( ! self::rule_matches( $rule, $ctx, $cart ) ) {
				continue;
			}
			$cfg   = $rule['config'];
			$scope = isset( $cfg['scope'] ) ? $cfg['scope'] : array();
			$in    = array();
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( ! isset( $price[ $key ] ) || isset( $lock[ $key ] ) ) {
					continue;
				}
				if ( ( $excl && ( ! empty( $item['kudora_gc'] ) || Kudora_Context::is_gift_card_product( $item['data'] ) ) ) || ! Kudora_Scope::matches_product( $scope, $item['data'] ) ) {
					continue;
				}
				$in[ $key ] = $item;
			}
			if ( ! $in ) {
				continue;
			}

			if ( 'bulk' === $rule['type'] ) {
				$applied = $this->apply_bulk( $rule, $in, $price );
			} else {
				$applied = array();
				foreach ( array_keys( $in ) as $key ) {
					$price[ $key ] = self::adjust( $price[ $key ], isset( $cfg['adjust'] ) ? $cfg['adjust'] : 'percent', (float) ( isset( $cfg['value'] ) ? $cfg['value'] : 0 ) );
					$applied[]     = $key;
				}
			}
			foreach ( $applied as $key ) {
				$hits[ $key ][] = $rule['id'];
				if ( $rule['exclusive'] ) {
					$lock[ $key ] = true;
				}
			}
		}

		$lines = self::lines();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! isset( $price[ $key ] ) ) {
				continue;
			}
			$product = $item['data'];
			if ( $hits[ $key ] ) {
				$product->set_price( $price[ $key ] );
				$lines[ $product ] = array(
					'base' => $base[ $key ],
					'set'  => (float) $product->get_price( 'edit' ),
				);
			} else {
				$mark = $lines[ $product ];
				if ( null !== $mark['set'] && abs( (float) $product->get_price( 'edit' ) - $mark['set'] ) < 0.000001 ) {
					// A rule stopped applying: give back the price it replaced.
					$product->set_price( $mark['base'] );
				}
				$lines[ $product ] = array(
					'base' => null,
					'set'  => null,
				);
			}
			$cart->cart_contents[ $key ]['kudora_rules'] = $hits[ $key ];
		}
	}

	/**
	 * Bulk pricing: find the quantity range and adjust.
	 *
	 * @param array $rule  Rule.
	 * @param array $in    In-scope cart items key => item.
	 * @param array $price Prices (by reference).
	 * @return string[] Keys adjusted.
	 */
	protected function apply_bulk( array $rule, array $in, array &$price ) {
		$cfg     = $rule['config'];
		$count   = isset( $cfg['count_by'] ) ? $cfg['count_by'] : 'line';
		$ranges  = isset( $cfg['ranges'] ) ? (array) $cfg['ranges'] : array();
		$applied = array();
		$by_prod = array();
		$total   = 0;
		foreach ( $in as $key => $item ) {
			$pid             = (int) $item['product_id'];
			$by_prod[ $pid ] = ( isset( $by_prod[ $pid ] ) ? $by_prod[ $pid ] : 0 ) + (int) $item['quantity'];
			$total          += (int) $item['quantity'];
		}
		foreach ( $in as $key => $item ) {
			if ( 'scope' === $count ) {
				$qty = $total;
			} elseif ( 'product' === $count ) {
				$qty = $by_prod[ (int) $item['product_id'] ];
			} else {
				$qty = (int) $item['quantity'];
			}
			$range = self::range_for( $ranges, $qty );
			if ( ! $range ) {
				continue;
			}
			$price[ $key ] = self::adjust( $price[ $key ], $range['adjust'], (float) $range['value'] );
			$applied[]     = $key;
		}
		return $applied;
	}

	/**
	 * Matching bulk range for a quantity.
	 *
	 * @param array $ranges Ranges [min, max, adjust, value].
	 * @param int   $qty    Quantity.
	 * @return array|null
	 */
	public static function range_for( array $ranges, $qty ) {
		foreach ( $ranges as $r ) {
			$min = isset( $r['min'] ) ? (int) $r['min'] : 0;
			$max = isset( $r['max'] ) && '' !== $r['max'] && (int) $r['max'] > 0 ? (int) $r['max'] : PHP_INT_MAX;
			if ( $qty >= $min && $qty <= $max && isset( $r['value'] ) && (float) $r['value'] > 0 ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Classic cart: show the pre-rule price struck through.
	 *
	 * @param string $html Price HTML.
	 * @param array  $item Cart item.
	 * @param string $key  Key.
	 * @return string
	 */
	public function cart_item_price_html( $html, $item, $key ) {
		if ( empty( $item['kudora_rules'] ) || empty( $item['data'] ) ) {
			return $html;
		}
		$base = self::base_price( $item );
		$now  = (float) $item['data']->get_price( 'edit' );
		if ( $base <= $now + 0.00001 ) {
			return $html;
		}
		$from = wc_get_price_to_display( $item['data'], array( 'price' => $base ) );
		$to   = wc_get_price_to_display( $item['data'], array( 'price' => $now ) );
		return wc_format_sale_price( $from, $to );
	}

	/* ---------------------------------------------------------------------
	 * Cart rules (virtual coupons)
	 * ------------------------------------------------------------------- */

	/**
	 * Register types.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function discount_types( $types ) {
		$types[ self::TYPE ]      = __( 'Kudora discount rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$types[ self::BXGY_TYPE ] = __( 'Buy X get Y (BOGO)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $types;
	}

	/**
	 * Cart-level types.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function cart_coupon_types( $types ) {
		$types[] = self::TYPE;
		$types[] = self::BXGY_TYPE;
		return $types;
	}

	/**
	 * Rule id from a virtual code.
	 *
	 * @param string $code Code.
	 * @return int
	 */
	public static function rule_id( $code ) {
		return (int) substr( wc_format_coupon_code( $code ), strlen( 'kudora-rule-' ) );
	}

	/**
	 * Coupon props for a rule code.
	 *
	 * @param string $code Code.
	 * @return array|false
	 */
	public function coupon_data( $code ) {
		$rule = Kudora_Rules_Repo::get( self::rule_id( $code ) );
		if ( ! $rule || 'discount' !== $rule['module'] || ! in_array( $rule['type'], self::CART_TYPES, true ) ) {
			return false;
		}
		return array(
			'discount_type' => self::TYPE,
			'amount'        => 0,
		);
	}

	/**
	 * Label.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public function coupon_label( $code ) {
		$rule = Kudora_Rules_Repo::get( self::rule_id( $code ) );
		if ( ! $rule ) {
			return __( 'Discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		return ! empty( $rule['config']['label'] ) ? $rule['config']['label'] : $rule['name'];
	}

	/**
	 * Valid only while the rule is active and matches the cart.
	 *
	 * @param string       $code      Code.
	 * @param WC_Discounts $discounts Discounts.
	 * @return true|string
	 */
	public function coupon_valid( $code, $discounts ) {
		if ( $discounts->get_object() instanceof WC_Order ) {
			return true;
		}
		$id = self::rule_id( $code );
		foreach ( self::rules_of( self::CART_TYPES ) as $rule ) {
			if ( $rule['id'] === $id ) {
				$cart = WC()->cart;
				return self::rule_matches( $rule, Kudora_Context::from_cart( $cart ), $cart ) ? true : __( 'This discount does not apply to your cart.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		return __( 'This discount is not available.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Cart check (classic cart and checkout pages).
	 *
	 * @return void
	 */
	public function sync_before_check() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$this->sync_rule_coupons( WC()->cart );
		}
	}

	/**
	 * Keep exactly the matching cart rules applied (priority order, exclusive stops).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function sync_rule_coupons( $cart ) {
		$rules   = self::rules_of( self::CART_TYPES );
		$applied = $cart->get_applied_coupons();
		$want    = array();
		if ( $rules ) {
			$ctx   = Kudora_Context::from_cart( $cart );
			$lines = Kudora_Rule_Calc::lines_from_cart( $cart );
			foreach ( $rules as $rule ) {
				if ( ! self::rule_matches( $rule, $ctx, $cart ) ) {
					continue;
				}
				$d = Kudora_Rule_Calc::compute( $rule['type'], $rule['config'], $lines );
				if ( array_sum( $d ) <= 0 ) {
					continue;
				}
				$want[] = 'kudora-rule-' . $rule['id'];
				if ( $rule['exclusive'] ) {
					break;
				}
			}
		}
		$current = array_values(
			array_filter(
				$applied,
				function ( $c ) {
					return 0 === strpos( wc_format_coupon_code( $c ), 'kudora-rule-' );
				}
			)
		);
		if ( $current === $want ) {
			return;
		}
		$others = array_values( array_diff( $applied, $current ) );
		$cart->set_applied_coupons( array_merge( $want, $others ) );
	}

	/**
	 * Capture WC_Discounts + items for our custom types (needed for remaining prices).
	 *
	 * @param array        $items     Items to apply.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Discounts.
	 * @return array
	 */
	public function capture_items( $items, $coupon, $discounts ) {
		if ( in_array( $coupon->get_discount_type(), array( self::TYPE, self::BXGY_TYPE ), true ) ) {
			self::$captured[ $coupon->get_code() ] = array(
				'items'     => $items,
				'discounts' => $discounts,
			);
		}
		return $items;
	}

	/**
	 * Per-line discounts for rule coupons and BXGY coupons.
	 *
	 * @param array     $discounts Key => cents.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return array
	 */
	public function custom_discounts( $discounts, $coupon ) {
		$type = $coupon->get_discount_type();
		if ( ! in_array( $type, array( self::TYPE, self::BXGY_TYPE ), true ) ) {
			return $discounts;
		}
		$code = $coupon->get_code();
		if ( ! isset( self::$captured[ $code ] ) ) {
			return array_map( '__return_zero', $discounts );
		}
		$lines = Kudora_Rule_Calc::lines_from_discount_items( self::$captured[ $code ]['items'], self::$captured[ $code ]['discounts'] );
		if ( self::TYPE === $type ) {
			$rule = Kudora_Rules_Repo::get( self::rule_id( $code ) );
			$calc = $rule ? Kudora_Rule_Calc::compute( $rule['type'], $rule['config'], $lines ) : array();
		} else {
			$calc = Kudora_Rule_Calc::compute( 'bxgy', Kudora_Rule_Calc::sanitize_bxgy( $coupon->get_meta( '_kudora_bxgy' ) ), $lines );
		}
		$out = array();
		foreach ( $discounts as $key => $v ) {
			$out[ $key ] = isset( $calc[ $key ] ) ? $calc[ $key ] : 0;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Buy X Get Y auto-add
	 * ------------------------------------------------------------------- */

	/**
	 * Add (or remove) the free "Y" product of BXGY rules with auto-add.
	 *
	 * @return void
	 */
	public function maybe_auto_add() {
		if ( self::$adjusting || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		self::$adjusting = true;
		try {
			$cart = WC()->cart;
			foreach ( self::rules_of( array( 'bxgy' ) ) as $rule ) {
				$cfg        = Kudora_Rule_Calc::sanitize_bxgy( $rule['config'] );
				$product_id = $cfg['auto_add'];
				if ( ! $product_id ) {
					continue;
				}
				$ctx       = Kudora_Context::from_cart( $cart );
				$buy_units = 0;
				$added_key = null;
				foreach ( $cart->get_cart() as $key => $item ) {
					if ( isset( $item['kudora_auto_added'] ) && (int) $item['kudora_auto_added'] === $rule['id'] ) {
						$added_key = $key;
						continue;
					}
					if ( Kudora_Scope::matches_product( $cfg['buy_scope'], $item['data'] ) ) {
						$buy_units += (int) $item['quantity'];
					}
				}
				$qualifies = $buy_units >= $cfg['buy_qty'] && self::rule_matches( $rule, $ctx, $cart );
				if ( $qualifies && ! $added_key && ! in_array( (int) $rule['id'], self::gift_optouts(), true ) ) {
					$cart->add_to_cart( $product_id, $cfg['get_qty'], 0, array(), array( 'kudora_auto_added' => $rule['id'] ) );
				} elseif ( ! $qualifies && $added_key ) {
					$cart->remove_cart_item( $added_key );
				}
			}
		} finally {
			self::$adjusting = false;
		}
	}

	/**
	 * Session key: rules whose free gift the customer removed.
	 */
	const GIFT_OPTOUT = 'kudora_gift_optout';

	/**
	 * Rules whose gift the customer removed this session.
	 *
	 * @return int[]
	 */
	protected static function gift_optouts() {
		return WC()->session ? array_map( 'intval', (array) WC()->session->get( self::GIFT_OPTOUT, array() ) ) : array();
	}

	/**
	 * The customer removed an auto-added gift: do not add it again this session.
	 *
	 * @param string  $key  Cart item key.
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function remember_gift_removal( $key, $cart ) {
		if ( self::$adjusting || ! WC()->session || ! $cart instanceof WC_Cart || empty( $cart->removed_cart_contents[ $key ]['kudora_auto_added'] ) ) {
			return;
		}
		$ids   = self::gift_optouts();
		$ids[] = (int) $cart->removed_cart_contents[ $key ]['kudora_auto_added'];
		WC()->session->set( self::GIFT_OPTOUT, array_values( array_unique( $ids ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Free shipping
	 * ------------------------------------------------------------------- */

	/**
	 * Matching free-shipping rules for the cart.
	 *
	 * @return array[]
	 */
	public static function free_shipping_rules() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}
		$ctx = Kudora_Context::from_cart( WC()->cart );
		$out = array();
		foreach ( self::rules_of( array( 'free_shipping' ) ) as $rule ) {
			if ( self::rule_matches( $rule, $ctx, WC()->cart ) ) {
				$out[] = $rule;
				if ( $rule['exclusive'] ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Zero matching rates.
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates.
	 * @param array              $package Package.
	 * @return array
	 */
	public function free_shipping( $rates, $package ) {
		$rules = self::free_shipping_rules();
		if ( ! $rules ) {
			return $rates;
		}
		$methods = array();
		$all     = false;
		foreach ( $rules as $rule ) {
			$m = isset( $rule['config']['methods'] ) ? array_filter( (array) $rule['config']['methods'] ) : array();
			if ( ! $m ) {
				$all = true;
			}
			$methods = array_merge( $methods, $m );
		}
		foreach ( $rates as $rate ) {
			if ( ! $all && ! in_array( $rate->get_method_id(), $methods, true ) ) {
				continue;
			}
			$rate->set_cost( 0 );
			$rate->set_taxes( array() );
			// Which rules made it free; copied to the order's shipping line, so only a
			// rule whose free shipping was actually chosen counts as used.
			$ids = array();
			foreach ( $rules as $rule ) {
				$m = isset( $rule['config']['methods'] ) ? array_filter( (array) $rule['config']['methods'] ) : array();
				if ( ! $m || in_array( $rate->get_method_id(), $m, true ) ) {
					$ids[] = (int) $rule['id'];
				}
			}
			$rate->add_meta_data( '_kudora_rules', implode( ',', $ids ) );
		}
		return $rates;
	}

	/**
	 * Make the shipping package hash change when free-shipping rules change.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function package_signature( $packages ) {
		if ( ! self::rules_of( array( 'free_shipping' ) ) ) {
			return $packages;
		}
		$sig = implode( ',', wp_list_pluck( self::free_shipping_rules(), 'id' ) );
		foreach ( $packages as &$p ) {
			$p['kudora_free_shipping'] = $sig;
		}
		return $packages;
	}

	/* ---------------------------------------------------------------------
	 * Usage counting
	 * ------------------------------------------------------------------- */

	/**
	 * Copy price-rule ids to the order line.
	 *
	 * @param WC_Order_Item_Product $item   Line.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart item.
	 * @return void
	 */
	public function line_item_rules( $item, $key, $values ) {
		if ( ! empty( $values['kudora_rules'] ) ) {
			$item->add_meta_data( '_kudora_rules', array_map( 'intval', (array) $values['kudora_rules'] ), true );
		}
	}

	/**
	 * Record every rule used by an order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function record_rules( $order ) {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $item->get_meta( '_kudora_rules' ) ) );
		}
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( 0 === strpos( wc_format_coupon_code( $code ), 'kudora-rule-' ) ) {
				$ids[] = self::rule_id( $code );
			}
		}
		foreach ( $order->get_shipping_methods() as $ship ) {
			if ( (float) $ship->get_total() <= 0 ) {
				$ids = array_merge( $ids, array_map( 'intval', array_filter( explode( ',', (string) $ship->get_meta( '_kudora_rules' ) ) ) ) );
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids ) {
			$order->update_meta_data( '_kudora_rules_applied', $ids );
			$order->save_meta_data();
		}
	}

	/**
	 * Count uses once per order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function count_usage( $order ) {
		if ( 'yes' === $order->get_meta( '_kudora_rules_counted' ) ) {
			return;
		}
		$ids = array_filter( array_map( 'intval', (array) $order->get_meta( '_kudora_rules_applied' ) ) );
		foreach ( $ids as $id ) {
			Kudora_Rules_Repo::count_use( $id, 1 );
		}
		if ( $ids ) {
			$order->update_meta_data( '_kudora_rules_counted', 'yes' );
			$order->save_meta_data();
		}
	}

	/**
	 * Uncount when a counted order is voided.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function uncount_usage( $order ) {
		if ( 'yes' !== $order->get_meta( '_kudora_rules_counted' ) ) {
			return;
		}
		foreach ( array_filter( array_map( 'intval', (array) $order->get_meta( '_kudora_rules_applied' ) ) ) as $id ) {
			Kudora_Rules_Repo::count_use( $id, -1 );
		}
		$order->update_meta_data( '_kudora_rules_counted', 'no' );
		$order->save_meta_data();
	}

	/* ---------------------------------------------------------------------
	 * Promotion notices & bulk table
	 * ------------------------------------------------------------------- */

	/**
	 * "Add X more to get …" messages for rules held back only by a subtotal threshold.
	 *
	 * @return string[]
	 */
	public static function promos() {
		if ( ! Kudora_Settings::yes( 'discounts_promo_notices' ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$cart    = WC()->cart;
		$ctx     = Kudora_Context::from_cart( $cart );
		$applied = $cart->get_applied_coupons();
		$active  = wp_list_pluck( self::free_shipping_rules(), 'id' );
		$out     = array();
		foreach ( self::rules_of( array( 'cart_discount', 'bxgy', 'bundle', 'free_shipping' ) ) as $rule ) {
			if ( in_array( 'kudora-rule-' . $rule['id'], $applied, true ) || in_array( $rule['id'], $active, true ) ) {
				continue;
			}
			$need  = null;
			$other = array();
			foreach ( $rule['conditions'] as $c ) {
				if ( 'cart_subtotal' === $c['type'] && in_array( $c['operator'], array( 'at_least', 'above' ), true ) && ! Kudora_Conditions::match_one( $c, $ctx ) ) {
					$need = (float) $c['value'] - $ctx->subtotal + ( 'above' === $c['operator'] ? pow( 10, -wc_get_price_decimals() ) : 0 );
				} else {
					$other[] = $c;
				}
			}
			if ( null === $need || $need <= 0 || ! Kudora_Conditions::matches( $other, $ctx ) ) {
				continue;
			}
			$label = ! empty( $rule['config']['label'] ) ? $rule['config']['label'] : $rule['name'];
			$tpl   = ! empty( $rule['config']['promo'] ) ? $rule['config']['promo'] : __( 'Add {amount} more to get {rule}!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			$out[] = str_replace( array( '{amount}', '{rule}' ), array( Kudora_Money::plain( $need ), $label ), $tpl );
		}
		return $out;
	}

	/**
	 * Store API: promos.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public function store_api_data( $data ) {
		$data['promos'] = self::promos();
		return $data;
	}

	/**
	 * Classic cart/checkout promo notices.
	 *
	 * @return void
	 */
	public function classic_promos() {
		foreach ( self::promos() as $msg ) {
			wc_print_notice( esc_html( $msg ), 'notice' );
		}
	}

	/**
	 * Bulk pricing table on the product page.
	 *
	 * @return void
	 */
	public function bulk_table() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$tables = array();
		foreach ( self::rules_of( array( 'bulk' ) ) as $rule ) {
			if ( empty( $rule['config']['show_table'] ) || ! Kudora_Scope::matches_product( isset( $rule['config']['scope'] ) ? $rule['config']['scope'] : array(), $product ) ) {
				continue;
			}
			$tables[] = $rule;
		}
		if ( ! $tables ) {
			return;
		}
		wp_enqueue_style( 'kudora-frontend' );
		wc_get_template(
			'discounts/bulk-table.php',
			array(
				'rules'   => $tables,
				'product' => $product,
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}
}
