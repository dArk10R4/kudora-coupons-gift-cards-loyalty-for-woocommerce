<?php
/**
 * The shared condition engine (Booknetic `RuleService::conditionsMatch`, extended).
 *
 * A condition is `array( 'type' => ..., 'operator' => ..., 'value' => ... )`. A rule's
 * conditions are combined with AND; an empty list always matches. Used by discount
 * rules, smart-coupon conditions and loyalty earning rules.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Condition schema, sanitisation and evaluation.
 */
class Kudora_Conditions {

	/**
	 * Operators per input kind.
	 *
	 * @return array<string,string[]>
	 */
	public static function operators_by_kind() {
		return array(
			'number' => array( 'above', 'at_least', 'below', 'at_most', 'between' ),
			'set'    => array( 'any', 'all', 'none' ),
			'list'   => array( 'in', 'not_in' ),
			'yesno'  => array( 'is' ),
			'range'  => array( 'between' ),
		);
	}

	/**
	 * Human labels for operators.
	 *
	 * @return array<string,string>
	 */
	public static function operator_labels() {
		return array(
			'above'    => __( 'is more than', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'at_least' => __( 'is at least', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'below'    => __( 'is less than', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'at_most'  => __( 'is at most', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'between'  => __( 'is between', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'any'      => __( 'contains any of', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'all'      => __( 'contains all of', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'none'     => __( 'contains none of', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'in'       => __( 'is one of', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'not_in'   => __( 'is not one of', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'is'       => __( 'is', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Condition types. `kind` drives operators; `input` drives the admin control.
	 *
	 * @return array<string,array>
	 */
	public static function types() {
		$types = array(
			'cart_subtotal'        => array( 'group' => 'cart', 'kind' => 'number', 'input' => 'money', 'label' => __( 'Cart subtotal', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'cart_quantity'        => array( 'group' => 'cart', 'kind' => 'number', 'input' => 'number', 'label' => __( 'Cart item quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'cart_products'        => array( 'group' => 'cart', 'kind' => 'set', 'input' => 'products', 'label' => __( 'Cart products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'cart_categories'      => array( 'group' => 'cart', 'kind' => 'set', 'input' => 'categories', 'label' => __( 'Cart categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'cart_tags'            => array( 'group' => 'cart', 'kind' => 'set', 'input' => 'tags', 'label' => __( 'Cart tags', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'coupons_applied'      => array( 'group' => 'cart', 'kind' => 'list', 'input' => 'text_list', 'label' => __( 'Applied coupon codes', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'logged_in'            => array( 'group' => 'customer', 'kind' => 'yesno', 'input' => 'yesno', 'label' => __( 'Customer is logged in', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'user_role'            => array( 'group' => 'customer', 'kind' => 'list', 'input' => 'roles', 'label' => __( 'User role', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'customer_email'       => array( 'group' => 'customer', 'kind' => 'list', 'input' => 'text_list', 'label' => __( 'Customer email or @domain', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'first_order'          => array( 'group' => 'customer', 'kind' => 'yesno', 'input' => 'yesno', 'label' => __( 'First order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'order_count'          => array( 'group' => 'customer', 'kind' => 'number', 'input' => 'number', 'label' => __( 'Number of previous orders', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'total_spent'          => array( 'group' => 'customer', 'kind' => 'number', 'input' => 'money', 'label' => __( 'Total spent', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'purchased_products'   => array( 'group' => 'customer', 'kind' => 'set', 'input' => 'products', 'label' => __( 'Previously bought products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'purchased_categories' => array( 'group' => 'customer', 'kind' => 'set', 'input' => 'categories', 'label' => __( 'Previously bought categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'billing_country'      => array( 'group' => 'customer', 'kind' => 'list', 'input' => 'countries', 'label' => __( 'Billing country', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'shipping_country'     => array( 'group' => 'customer', 'kind' => 'list', 'input' => 'countries', 'label' => __( 'Shipping country', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'weekday'              => array( 'group' => 'date', 'kind' => 'list', 'input' => 'weekdays', 'label' => __( 'Day of the week', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'time_of_day'          => array( 'group' => 'date', 'kind' => 'range', 'input' => 'time_range', 'label' => __( 'Time of day', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'date'                 => array( 'group' => 'date', 'kind' => 'range', 'input' => 'date_range', 'label' => __( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
		);
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			$types['loyalty_tier']   = array( 'group' => 'loyalty', 'kind' => 'list', 'input' => 'tiers', 'label' => __( 'Loyalty tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			$types['points_balance'] = array( 'group' => 'loyalty', 'kind' => 'number', 'input' => 'number', 'label' => __( 'Points balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		/**
		 * Filter the available condition types.
		 *
		 * @param array $types Types.
		 */
		return apply_filters( 'kudora_condition_types', $types );
	}

	/**
	 * Sanitise a list of posted conditions. Unknown types/operators are dropped.
	 *
	 * @param mixed $raw Raw list.
	 * @return array[]
	 */
	public static function sanitize( $raw ) {
		$out   = array();
		$types = self::types();
		$ops   = self::operators_by_kind();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $c ) {
			if ( ! is_array( $c ) || empty( $c['type'] ) || ! isset( $types[ $c['type'] ] ) ) {
				continue;
			}
			$type = $c['type'];
			$kind = $types[ $type ]['kind'];
			$op   = isset( $c['operator'] ) ? (string) $c['operator'] : '';
			if ( ! in_array( $op, $ops[ $kind ], true ) ) {
				$op = $ops[ $kind ][0];
			}
			$value = self::sanitize_value( $types[ $type ]['input'], $op, isset( $c['value'] ) ? $c['value'] : '' );
			if ( null === $value ) {
				continue;
			}
			$out[] = array(
				'type'     => $type,
				'operator' => $op,
				'value'    => $value,
			);
		}
		return $out;
	}

	/**
	 * Sanitise one value for its input kind.
	 *
	 * @param string $input Input kind.
	 * @param string $op    Operator.
	 * @param mixed  $value Raw value.
	 * @return mixed|null Null drops the condition.
	 */
	protected static function sanitize_value( $input, $op, $value ) {
		switch ( $input ) {
			case 'money':
			case 'number':
				if ( 'between' === $op ) {
					$v = is_array( $value ) ? array_values( $value ) : explode( ',', (string) $value );
					$a = isset( $v[0] ) && '' !== $v[0] ? (float) wc_format_decimal( $v[0] ) : 0.0;
					$b = isset( $v[1] ) && '' !== $v[1] ? (float) wc_format_decimal( $v[1] ) : 0.0;
					return array( min( $a, $b ), max( $a, $b ) );
				}
				return is_scalar( $value ) && '' !== $value ? (float) wc_format_decimal( $value ) : null;
			case 'products':
			case 'categories':
			case 'tags':
				$ids = array_values( array_filter( array_map( 'absint', (array) $value ) ) );
				return $ids ? $ids : null;
			case 'yesno':
				return in_array( $value, array( 'yes', 'no' ), true ) ? $value : 'yes';
			case 'roles':
			case 'countries':
				$list = array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
				if ( 'countries' === $input ) {
					$list = array_map( 'strtoupper', $list );
				}
				return $list ? $list : null;
			case 'tiers':
				$list = array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
				return $list ? $list : null;
			case 'weekdays':
				$list = array_values( array_intersect( array_map( 'intval', (array) $value ), range( 1, 7 ) ) );
				return $list ? $list : null;
			case 'text_list':
				$parts = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
				$list  = array_values( array_filter( array_map( 'strtolower', array_map( 'sanitize_text_field', $parts ) ) ) );
				return $list ? $list : null;
			case 'time_range':
				$v    = is_array( $value ) ? array_values( $value ) : explode( '-', (string) $value );
				$from = isset( $v[0] ) ? self::clean_time( $v[0] ) : '';
				$to   = isset( $v[1] ) ? self::clean_time( $v[1] ) : '';
				return ( $from && $to ) ? array( $from, $to ) : null;
			case 'date_range':
				$v    = is_array( $value ) ? array_values( $value ) : explode( ',', (string) $value );
				$from = isset( $v[0] ) ? self::clean_date( $v[0] ) : '';
				$to   = isset( $v[1] ) ? self::clean_date( $v[1] ) : '';
				return ( $from || $to ) ? array( $from, $to ) : null;
		}
		return null;
	}

	/**
	 * `HH:MM` or empty.
	 *
	 * @param string $t Raw.
	 * @return string
	 */
	protected static function clean_time( $t ) {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', trim( (string) $t ) ) ? trim( (string) $t ) : '';
	}

	/**
	 * `Y-m-d` or empty.
	 *
	 * @param string $d Raw.
	 * @return string
	 */
	protected static function clean_date( $d ) {
		$d = trim( (string) $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}

	/**
	 * Whether all conditions match (AND). Empty list matches.
	 *
	 * @param array          $conditions Conditions.
	 * @param Kudora_Context $ctx        Context.
	 * @return bool
	 */
	public static function matches( $conditions, Kudora_Context $ctx ) {
		foreach ( (array) $conditions as $c ) {
			if ( ! is_array( $c ) || ! self::match_one( $c, $ctx ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Evaluate one condition.
	 *
	 * @param array          $c   Condition.
	 * @param Kudora_Context $ctx Context.
	 * @return bool
	 */
	public static function match_one( array $c, Kudora_Context $ctx ) {
		$type  = isset( $c['type'] ) ? $c['type'] : '';
		$op    = isset( $c['operator'] ) ? $c['operator'] : '';
		$value = isset( $c['value'] ) ? $c['value'] : null;

		switch ( $type ) {
			case 'cart_subtotal':
				return self::compare_number( $ctx->subtotal, $op, $value );
			case 'cart_quantity':
				return self::compare_number( $ctx->quantity, $op, $value );
			case 'cart_products':
				$ids = array();
				foreach ( $ctx->items as $item ) {
					$ids[] = $item['product_id'];
					if ( $item['variation_id'] ) {
						$ids[] = $item['variation_id'];
					}
				}
				return self::compare_set( $ids, $op, $value );
			case 'cart_categories':
				return self::compare_set( self::item_terms( $ctx, 'cat_ids' ), $op, $value );
			case 'cart_tags':
				return self::compare_set( self::item_terms( $ctx, 'tag_ids' ), $op, $value );
			case 'coupons_applied':
				return self::compare_list( $ctx->coupons, $op, $value, true );
			case 'logged_in':
				return ( $ctx->user_id > 0 ) === ( 'yes' === $value );
			case 'user_role':
				$roles = array();
				if ( $ctx->user_id ) {
					$user  = get_userdata( $ctx->user_id );
					$roles = $user ? (array) $user->roles : array();
				} else {
					$roles = array( 'guest' );
				}
				return self::compare_list( $roles, $op, $value, true );
			case 'customer_email':
				$hit = false;
				foreach ( (array) $value as $pattern ) {
					if ( '' !== $ctx->email && ( $pattern === $ctx->email || ( 0 === strpos( $pattern, '@' ) && substr( $ctx->email, -strlen( $pattern ) ) === $pattern ) ) ) {
						$hit = true;
						break;
					}
				}
				return 'not_in' === $op ? ! $hit : $hit;
			case 'first_order':
				return ( 0 === $ctx->order_count() ) === ( 'yes' === $value );
			case 'order_count':
				return self::compare_number( $ctx->order_count(), $op, $value );
			case 'total_spent':
				return self::compare_number( $ctx->total_spent(), $op, $value );
			case 'purchased_products':
				$bought = $ctx->purchased();
				return self::compare_set( $bought['products'], $op, $value );
			case 'purchased_categories':
				$bought = $ctx->purchased();
				return self::compare_set( $bought['categories'], $op, $value );
			case 'billing_country':
				return self::compare_list( array( $ctx->billing_country ), $op, $value, true );
			case 'shipping_country':
				return self::compare_list( array( $ctx->shipping_country ? $ctx->shipping_country : $ctx->billing_country ), $op, $value, true );
			case 'weekday':
				return self::compare_list( array( (int) $ctx->now->format( 'N' ) ), $op, $value, false );
			case 'time_of_day':
				return self::in_time_range( $ctx->now->format( 'H:i' ), (array) $value );
			case 'date':
				$today = $ctx->now->format( 'Y-m-d' );
				$from  = isset( $value[0] ) ? $value[0] : '';
				$to    = isset( $value[1] ) ? $value[1] : '';
				return ( '' === $from || $today >= $from ) && ( '' === $to || $today <= $to );
			case 'loyalty_tier':
				$tier = $ctx->user_id ? Kudora_Points_Repo::tier_of( $ctx->user_id ) : null;
				return self::compare_list( array( $tier ? $tier['id'] : '' ), $op, $value, true );
			case 'points_balance':
				return self::compare_number( $ctx->user_id ? Kudora_Points_Repo::balance( $ctx->user_id ) : 0, $op, $value );
		}

		/**
		 * Evaluate a custom condition type added via `kudora_condition_types`.
		 *
		 * @param bool           $result Default false.
		 * @param array          $c      Condition.
		 * @param Kudora_Context $ctx    Context.
		 */
		return (bool) apply_filters( 'kudora_condition_match', false, $c, $ctx );
	}

	/**
	 * Numeric comparison (Booknetic `above`/`below` are strict).
	 *
	 * @param float  $actual Actual value.
	 * @param string $op     Operator.
	 * @param mixed  $value  Threshold or [min,max].
	 * @return bool
	 */
	public static function compare_number( $actual, $op, $value ) {
		$actual = (float) $actual;
		$eps    = 0.000001;
		switch ( $op ) {
			case 'above':
				return $actual > (float) $value + $eps;
			case 'at_least':
				return $actual >= (float) $value - $eps;
			case 'below':
				return $actual < (float) $value - $eps;
			case 'at_most':
				return $actual <= (float) $value + $eps;
			case 'between':
				$v = (array) $value;
				return $actual >= (float) ( isset( $v[0] ) ? $v[0] : 0 ) - $eps && $actual <= (float) ( isset( $v[1] ) ? $v[1] : 0 ) + $eps;
		}
		return false;
	}

	/**
	 * Set comparison: any / all / none of the expected ids present.
	 *
	 * @param int[]  $actual   Present ids.
	 * @param string $op       Operator.
	 * @param int[]  $expected Expected ids.
	 * @return bool
	 */
	public static function compare_set( $actual, $op, $expected ) {
		$actual   = array_map( 'intval', (array) $actual );
		$expected = array_map( 'intval', (array) $expected );
		$hits     = array_intersect( $expected, $actual );
		switch ( $op ) {
			case 'all':
				return count( array_unique( $hits ) ) === count( array_unique( $expected ) );
			case 'none':
				return empty( $hits );
			default:
				return ! empty( $hits );
		}
	}

	/**
	 * List membership (Booknetic `is`/`in`, plus `not_in`).
	 *
	 * @param array  $actual   Actual values (any hit counts).
	 * @param string $op       in|not_in.
	 * @param array  $expected Expected values.
	 * @param bool   $strings  Compare as lowercase strings.
	 * @return bool
	 */
	public static function compare_list( $actual, $op, $expected, $strings ) {
		$actual   = (array) $actual;
		$expected = (array) $expected;
		if ( $strings ) {
			$actual   = array_map( 'strtolower', array_map( 'strval', $actual ) );
			$expected = array_map( 'strtolower', array_map( 'strval', $expected ) );
		} else {
			$actual   = array_map( 'intval', $actual );
			$expected = array_map( 'intval', $expected );
		}
		$hit = (bool) array_intersect( $actual, $expected );
		return 'not_in' === $op ? ! $hit : $hit;
	}

	/**
	 * Whether `H:i` falls in a range; supports overnight ranges like 22:00-02:00.
	 *
	 * @param string $now   Current `H:i`.
	 * @param array  $range [from, to].
	 * @return bool
	 */
	public static function in_time_range( $now, array $range ) {
		$from = isset( $range[0] ) ? $range[0] : '00:00';
		$to   = isset( $range[1] ) ? $range[1] : '23:59';
		if ( $from <= $to ) {
			return $now >= $from && $now <= $to;
		}
		return $now >= $from || $now <= $to;
	}

	/**
	 * All term ids of a kind across items.
	 *
	 * @param Kudora_Context $ctx Context.
	 * @param string         $key cat_ids|tag_ids.
	 * @return int[]
	 */
	protected static function item_terms( Kudora_Context $ctx, $key ) {
		$ids = array();
		foreach ( $ctx->items as $item ) {
			$ids = array_merge( $ids, $item[ $key ] );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Admin-facing schema for the JS condition builder.
	 *
	 * @return array
	 */
	public static function js_schema() {
		$types = array();
		foreach ( self::types() as $key => $t ) {
			$types[ $key ] = array(
				'label'     => $t['label'],
				'group'     => $t['group'],
				'input'     => $t['input'],
				'operators' => self::operators_by_kind()[ $t['kind'] ],
			);
		}
		return array(
			'types'     => $types,
			'operators' => self::operator_labels(),
		);
	}
}
