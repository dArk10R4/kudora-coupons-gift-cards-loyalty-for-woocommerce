<?php
/**
 * Show product-discount rules as sale prices in the catalog (shop, product page,
 * Store API product data). Only rules whose conditions do not depend on the cart
 * (customer, date and loyalty conditions) are shown; cart lines keep being priced
 * by the engine, which skips these filters.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog price display.
 */
class Kudora_Catalog_Prices {

	/**
	 * Condition types that can be evaluated without a cart.
	 *
	 * @var string[]
	 */
	const CATALOG_SAFE = array( 'logged_in', 'user_role', 'customer_email', 'first_order', 'order_count', 'total_spent', 'purchased_products', 'purchased_categories', 'billing_country', 'shipping_country', 'weekday', 'time_of_day', 'date', 'loyalty_tier', 'points_balance' );

	/**
	 * Memo: product id => price factor|null.
	 *
	 * @var array<int,float|null>
	 */
	protected static $memo = array();

	/**
	 * Shared customer-only context.
	 *
	 * @var Kudora_Context|null
	 */
	protected static $ctx = null;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! Kudora_Settings::yes( 'discounts_catalog_prices' ) ) {
			return;
		}
		add_filter( 'woocommerce_product_get_price', array( $this, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'sale_price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'sale_price' ), 50, 2 );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'on_sale' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'variation_price' ), 50, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'variation_sale_price' ), 50, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'hash' ), 50, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'mark_cart_item' ), 1 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'mark_cart_item' ), 1 );
	}

	/**
	 * Remember cart product objects so their price stays with the engine.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public function mark_cart_item( $item ) {
		if ( ! empty( $item['data'] ) && $item['data'] instanceof WC_Product ) {
			Kudora_Discount_Engine::mark_cart_object( $item['data'] );
		}
		return $item;
	}

	/**
	 * Customer-only context.
	 *
	 * @return Kudora_Context
	 */
	protected static function context() {
		if ( null === self::$ctx ) {
			$ctx          = new Kudora_Context();
			$ctx->now     = new DateTimeImmutable( 'now', wp_timezone() );
			$ctx->user_id = get_current_user_id();
			$user         = $ctx->user_id ? get_userdata( $ctx->user_id ) : null;
			$ctx->email   = $user ? strtolower( $user->user_email ) : '';
			if ( function_exists( 'WC' ) && WC()->customer ) {
				$ctx->billing_country  = (string) WC()->customer->get_billing_country();
				$ctx->shipping_country = (string) WC()->customer->get_shipping_country();
			}
			self::$ctx = $ctx;
		}
		return self::$ctx;
	}

	/**
	 * Whether catalog prices apply to this request: the storefront and the Store
	 * API, not the admin (an order being edited or created there) or other REST
	 * routes (orders created through the REST API), where the conditions would be
	 * checked against the shop manager.
	 *
	 * @return bool
	 */
	public static function storefront() {
		if ( is_admin() ) {
			if ( ! wp_doing_ajax() ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, nothing is changed.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( 0 === strpos( $action, 'woocommerce_' ) ) {
				return false; // Admin order screen actions (add item, recalculate, ...).
			}
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			return 0 === strpos( ltrim( $route, '/' ), 'wc/store' );
		}
		return true;
	}

	/**
	 * Ratio between a product's rule price and its own price (null = no rule).
	 * Applied to whatever price reaches the filter, so currency switchers and
	 * role-based prices from other plugins are kept.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	public static function factor( $product ) {
		if ( ! $product instanceof WC_Product || Kudora_Discount_Engine::is_cart_object( $product ) || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || ! self::storefront() ) {
			return null;
		}
		$id = $product->get_id();
		if ( array_key_exists( $id, self::$memo ) ) {
			return self::$memo[ $id ];
		}
		self::$memo[ $id ] = null;
		if ( Kudora_Settings::yes( 'discounts_exclude_gift_cards' ) && Kudora_Context::is_gift_card_product( $product ) ) {
			return null;
		}
		$base = (float) $product->get_price( 'edit' );
		if ( '' === $product->get_price( 'edit' ) || $base <= 0 ) {
			return null;
		}
		$price = $base;
		$hit   = false;
		foreach ( self::rules() as $rule ) {
			if ( ! Kudora_Scope::matches_product( isset( $rule['config']['scope'] ) ? $rule['config']['scope'] : array(), $product ) ) {
				continue;
			}
			if ( ! Kudora_Conditions::matches( $rule['conditions'], self::context() ) ) {
				continue;
			}
			$price = Kudora_Discount_Engine::adjust( $price, isset( $rule['config']['adjust'] ) ? $rule['config']['adjust'] : 'percent', (float) ( isset( $rule['config']['value'] ) ? $rule['config']['value'] : 0 ) );
			$hit   = true;
			if ( $rule['exclusive'] ) {
				break;
			}
		}
		self::$memo[ $id ] = $hit && $price < $base ? $price / $base : null;
		return self::$memo[ $id ];
	}

	/**
	 * Product rules that can be shown in the catalog (no cart conditions, not
	 * limited to orders without coupons).
	 *
	 * @return array[]
	 */
	protected static function rules() {
		$out = array();
		foreach ( Kudora_Discount_Engine::rules_of( array( 'product_adjustment' ) ) as $rule ) {
			if ( ! empty( $rule['config']['skip_with_coupons'] ) ) {
				continue;
			}
			foreach ( $rule['conditions'] as $c ) {
				if ( ! in_array( $c['type'], self::CATALOG_SAFE, true ) ) {
					continue 2;
				}
			}
			$out[] = $rule;
		}
		return $out;
	}

	/**
	 * Scale a price by the factor.
	 *
	 * @param mixed $value Price.
	 * @param float $f     Factor.
	 * @return mixed
	 */
	protected static function scale( $value, $f ) {
		return '' === $value || null === $value ? $value : round( (float) $value * $f, wc_get_price_decimals() );
	}

	/**
	 * Price filter.
	 *
	 * @param mixed      $value   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public function price( $value, $product ) {
		$f = self::factor( $product );
		return null === $f ? $value : self::scale( $value, $f );
	}

	/**
	 * Sale price filter: the rule price, from the sale price when the product has
	 * one, otherwise from the regular price.
	 *
	 * @param mixed      $value   Sale price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public function sale_price( $value, $product ) {
		$f = self::factor( $product );
		if ( null === $f ) {
			return $value;
		}
		return self::scale( '' === $value || null === $value ? $product->get_regular_price() : $value, $f );
	}

	/**
	 * On sale when a rule lowers the price.
	 *
	 * @param bool       $on_sale On sale.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function on_sale( $on_sale, $product ) {
		return $on_sale || null !== self::factor( $product );
	}

	/**
	 * Variation price arrays (variable product price ranges).
	 *
	 * @param mixed      $price     Price.
	 * @param WC_Product $variation Variation.
	 * @param WC_Product $parent    Parent.
	 * @return mixed
	 */
	public function variation_price( $price, $variation, $parent ) {
		$f = self::factor( $variation );
		return null === $f ? $price : self::scale( $price, $f );
	}

	/**
	 * Variation sale price arrays.
	 *
	 * @param mixed      $price     Sale price.
	 * @param WC_Product $variation Variation.
	 * @param WC_Product $parent    Parent.
	 * @return mixed
	 */
	public function variation_sale_price( $price, $variation, $parent ) {
		$f = self::factor( $variation );
		if ( null === $f ) {
			return $price;
		}
		return self::scale( '' === $price || null === $price ? $variation->get_regular_price( 'edit' ) : $price, $f );
	}

	/**
	 * Variation price cache key: only what the catalog rules depend on, so the
	 * cached ranges are right for every visitor without one entry per user and hour.
	 *
	 * @param array      $hash        Hash parts.
	 * @param WC_Product $product     Product.
	 * @param bool       $for_display For display.
	 * @return array
	 */
	public function hash( $hash, $product, $for_display ) {
		$rules = self::rules();
		if ( ! $rules || ! self::storefront() ) {
			return $hash;
		}
		$types = array();
		foreach ( $rules as $rule ) {
			foreach ( $rule['conditions'] as $c ) {
				$types[ $c['type'] ] = true;
			}
		}
		$ctx   = self::context();
		$parts = array( wp_list_pluck( $rules, 'id' ), wp_list_pluck( $rules, 'updated_at' ) );
		foreach ( array( 'logged_in', 'user_role', 'customer_email', 'first_order', 'order_count', 'total_spent', 'purchased_products', 'purchased_categories', 'loyalty_tier', 'points_balance' ) as $t ) {
			if ( isset( $types[ $t ] ) ) {
				$parts[] = 'u' . $ctx->user_id;
				break;
			}
		}
		if ( isset( $types['billing_country'] ) ) {
			$parts[] = 'b' . $ctx->billing_country;
		}
		if ( isset( $types['shipping_country'] ) ) {
			$parts[] = 's' . $ctx->shipping_country;
		}
		if ( isset( $types['weekday'] ) || isset( $types['time_of_day'] ) || isset( $types['date'] ) ) {
			$parts[] = wp_date( 'Y-m-d H' );
		}
		$hash[] = md5( wp_json_encode( $parts ) );
		return $hash;
	}
}
