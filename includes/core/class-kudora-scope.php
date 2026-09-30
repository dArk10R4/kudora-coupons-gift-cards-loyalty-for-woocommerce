<?php
/**
 * Product scope: which cart lines a rule, reward or gift card applies to.
 * Includes are OR-ed (any listed product, category or tag); excludes always win.
 * An empty include list means "all products".
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product scope helper.
 */
class Kudora_Scope {

	/**
	 * Keys and their shape.
	 *
	 * @var string[]
	 */
	const ID_KEYS = array( 'products', 'categories', 'tags', 'exclude_products', 'exclude_categories' );

	/**
	 * Sanitise a posted scope.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$scope = array();
		foreach ( self::ID_KEYS as $key ) {
			$scope[ $key ] = isset( $raw[ $key ] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $raw[ $key ] ) ) ) ) : array();
		}
		$scope['exclude_sale'] = ! empty( $raw['exclude_sale'] ) && 'no' !== $raw['exclude_sale'];
		return $scope;
	}

	/**
	 * Whether the scope has no includes and no excludes.
	 *
	 * @param array $scope Scope.
	 * @return bool
	 */
	public static function is_all( $scope ) {
		foreach ( self::ID_KEYS as $key ) {
			if ( ! empty( $scope[ $key ] ) ) {
				return false;
			}
		}
		return empty( $scope['exclude_sale'] );
	}

	/**
	 * Whether a normalised context item is in scope.
	 *
	 * @param array $scope              Scope.
	 * @param array $item               Item from Kudora_Context::$items.
	 * @param bool  $exclude_gift_cards Always exclude gift card lines.
	 * @return bool
	 */
	public static function matches_item( $scope, array $item, $exclude_gift_cards = true ) {
		if ( $exclude_gift_cards && ! empty( $item['is_gift_card'] ) ) {
			return false;
		}
		return self::matches_ids( $scope, (int) $item['product_id'], (int) $item['variation_id'], $item['cat_ids'], $item['tag_ids'], ! empty( $item['on_sale'] ) );
	}

	/**
	 * Whether a product is in scope.
	 *
	 * @param array      $scope   Scope.
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public static function matches_product( $scope, $product ) {
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return self::matches_ids(
			$scope,
			$parent,
			$product->get_parent_id() ? $product->get_id() : 0,
			array_map( 'intval', wc_get_product_cat_ids( $parent ) ),
			array_map( 'intval', wc_get_product_term_ids( $parent, 'product_tag' ) ),
			$product->is_on_sale( 'edit' )
		);
	}

	/**
	 * Core matcher.
	 *
	 * @param array $scope        Scope.
	 * @param int   $product_id   Parent product id.
	 * @param int   $variation_id Variation id (0 for none).
	 * @param int[] $cat_ids      Category ids.
	 * @param int[] $tag_ids      Tag ids.
	 * @param bool  $on_sale      On sale.
	 * @return bool
	 */
	protected static function matches_ids( $scope, $product_id, $variation_id, $cat_ids, $tag_ids, $on_sale ) {
		$scope = wp_parse_args( (array) $scope, array_fill_keys( self::ID_KEYS, array() ) );
		$ids   = array_filter( array( $product_id, $variation_id ) );

		if ( array_intersect( $ids, (array) $scope['exclude_products'] ) ) {
			return false;
		}
		if ( array_intersect( $cat_ids, (array) $scope['exclude_categories'] ) ) {
			return false;
		}
		if ( ! empty( $scope['exclude_sale'] ) && $on_sale ) {
			return false;
		}
		$has_includes = ! empty( $scope['products'] ) || ! empty( $scope['categories'] ) || ! empty( $scope['tags'] );
		if ( ! $has_includes ) {
			return true;
		}
		return (bool) ( array_intersect( $ids, (array) $scope['products'] )
			|| array_intersect( $cat_ids, (array) $scope['categories'] )
			|| array_intersect( $tag_ids, (array) $scope['tags'] ) );
	}
}
