<?php
/**
 * Central settings accessor. Every setting is stored as its own `kudora_{key}` option
 * (the WooCommerce settings API convention) and read through here so defaults live
 * in exactly one place.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings defaults and typed getters.
 */
class Kudora_Settings {

	/**
	 * Default values, keyed without the `kudora_` prefix.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Modules.
			'module_discounts'             => 'yes',
			'module_gift_cards'            => 'yes',
			'module_loyalty'               => 'yes',
			'delete_data_on_uninstall'     => 'no',

			// Discounts & smart coupons.
			'discounts_catalog_prices'     => 'yes',
			'discounts_exclude_gift_cards' => 'yes',
			'discounts_promo_notices'      => 'yes',
			'discounts_url_param'          => 'coupon',

			// Gift cards.
			'gc_code_prefix'               => 'GIFT-',
			'gc_code_suffix'               => '',
			'gc_code_charset'              => 'alnum',
			'gc_code_length'               => 12,
			'gc_code_group'                => 4,
			'gc_default_expiry_days'       => 0,
			'gc_allowed_gateways'          => array(),
			'gc_coupon_field'              => 'yes',
			'gc_restore_on_refund'         => 'yes',
			'gc_pdf'                       => 'yes',
			'gc_balance_rate_limit'        => 10,

			// Loyalty — earning.
			'loyalty_earn_rate'            => 1,
			'loyalty_earn_basis'           => 'subtotal_after_discounts',
			'loyalty_rounding'             => 'floor',
			'loyalty_earn_status'          => 'processing',
			'loyalty_earn_on_redeem'       => 'no',
			'loyalty_exclude_gift_cards'   => 'yes',
			'loyalty_expiry_days'          => 0,
			'loyalty_expiry_warning_days'  => 14,
			'loyalty_tier_basis'           => 'lifetime',
			'loyalty_label'                => '',

			// Loyalty — actions.
			'loyalty_signup_points'        => 0,
			'loyalty_review_points'        => 0,
			'loyalty_review_verified_only' => 'yes',
			'loyalty_birthday_points'      => 0,
			'loyalty_first_order_points'   => 0,

			// Loyalty — redemption (Booknetic parity).
			'loyalty_redeem_enabled'       => 'yes',
			'loyalty_point_value'          => 0.01,
			'loyalty_redeem_mode'          => 'customer',
			'loyalty_max_discount_type'    => 'percent',
			'loyalty_max_discount_value'   => 50,
			'loyalty_min_points'           => 0,
			'loyalty_max_points_per_order' => 0,
			'loyalty_redeem_excluded_products'   => array(),
			'loyalty_redeem_excluded_categories' => array(),

			// Referrals.
			'referral_enabled'             => 'no',
			'referral_cookie_days'         => 30,
			'referral_friend_type'         => 'percent',
			'referral_friend_value'        => 10,
			'referral_reward_type'         => 'points',
			'referral_reward_points'       => 100,
			'referral_reward_amount'       => 10,
			'referral_min_order'           => 0,
			'referral_monthly_cap'         => 0,
			'referral_block_same_ip'       => 'no',
		);
	}

	/**
	 * Raw getter with the central default.
	 *
	 * @param string $key Setting key without the `kudora_` prefix.
	 * @return mixed
	 */
	public static function get( $key ) {
		$defaults = self::defaults();
		$default  = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
		return get_option( 'kudora_' . $key, $default );
	}

	/**
	 * Numeric value, falling back to the default when the stored value is blank.
	 *
	 * @param string $key Setting key.
	 * @return float
	 */
	protected static function numeric( $key ) {
		$value = self::get( $key );
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			$defaults = self::defaults();
			$value    = isset( $defaults[ $key ] ) ? $defaults[ $key ] : 0;
		}
		return (float) $value;
	}

	/**
	 * Boolean ("yes"/"no") getter.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function yes( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Integer getter.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function int( $key ) {
		return (int) self::numeric( $key );
	}

	/**
	 * Float getter.
	 *
	 * @param string $key Setting key.
	 * @return float
	 */
	public static function float( $key ) {
		return self::numeric( $key );
	}

	/**
	 * Array getter (always an array of scalars).
	 *
	 * @param string $key Setting key.
	 * @return array
	 */
	public static function arr( $key ) {
		$value = self::get( $key );
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : array_map( 'trim', explode( ',', $value ) );
		}
		return is_array( $value ) ? array_values( array_filter( $value, 'strlen' ) ) : array();
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module discounts|gift_cards|loyalty.
	 * @return bool
	 */
	public static function module( $module ) {
		return self::yes( 'module_' . $module );
	}

	/**
	 * Update a setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( $key, $value ) {
		update_option( 'kudora_' . $key, $value );
	}
}
