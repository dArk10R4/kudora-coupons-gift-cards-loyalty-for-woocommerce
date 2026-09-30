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
	 * Memo: product id => discounted price|null.
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
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'price' ), 50, 2 );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'on_sale' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'variation_price' ), 50, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'variation_price' ), 50, 3 );
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
			Kudora_Discount_Engine::base_price( $item );
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
	 * Discounted catalog price of a product (null = no rule).
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	public static function catalog_price( $product ) {
		if ( ! $product instanceof WC_Product || Kudora_Discount_Engine::is_cart_object( $product ) || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
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
		foreach ( Kudora_Discount_Engine::rules_of( array( 'product_adjustment' ) ) as $rule ) {
			if ( ! empty( $rule['config']['skip_with_coupons'] ) ) {
				continue;
			}
			foreach ( $rule['conditions'] as $c ) {
				if ( ! in_array( $c['type'], self::CATALOG_SAFE, true ) ) {
					continue 2;
				}
			}
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
		self::$memo[ $id ] = $hit && $price < $base ? round( $price, wc_get_price_decimals() ) : null;
		return self::$memo[ $id ];
	}

	/**
	 * Price / sale price filter.
	 *
	 * @param mixed      $value   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public function price( $value, $product ) {
		$p = self::catalog_price( $product );
		return null === $p ? $value : $p;
	}

	/**
	 * On sale when a rule lowers the price.
	 *
	 * @param bool       $on_sale On sale.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function on_sale( $on_sale, $product ) {
		return $on_sale || null !== self::catalog_price( $product );
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
		$p = self::catalog_price( $variation );
		return null === $p ? $price : $p;
	}

	/**
	 * Include rules and the customer in the variation price cache key.
	 *
	 * @param array      $hash       Hash parts.
	 * @param WC_Product $product    Product.
	 * @param bool       $for_display For display.
	 * @return array
	 */
	public function hash( $hash, $product, $for_display ) {
		$rules  = Kudora_Discount_Engine::rules_of( array( 'product_adjustment' ) );
		$hash[] = md5( wp_json_encode( array( wp_list_pluck( $rules, 'updated_at' ), wp_list_pluck( $rules, 'id' ), get_current_user_id(), wp_date( 'Y-m-d H' ) ) ) );
		return $hash;
	}
}
