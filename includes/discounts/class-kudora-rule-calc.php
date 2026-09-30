<?php
/**
 * Pure discount maths for cart-level rules and Kudora coupon types:
 * cart discount (percent/fixed with cap), Buy X Get Y, bundle ("any N for X").
 *
 * Works on normalised lines: key, qty, unit (price in cents), product, is_gc.
 * Returns key => discount in cents, never more than a line's available price.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule calculator.
 */
class Kudora_Rule_Calc {

	/**
	 * Lines from WC_Discounts items (cents).
	 *
	 * @param array             $items     WC_Discounts items.
	 * @param WC_Discounts|null $discounts Discounts (for the remaining price per line).
	 * @return array[]
	 */
	public static function lines_from_discount_items( array $items, $discounts = null ) {
		$lines = array();
		foreach ( $items as $item ) {
			if ( empty( $item->product ) || ! $item->quantity ) {
				continue;
			}
			$available = $discounts && method_exists( $discounts, 'get_discounted_price_in_cents' ) ? (float) $discounts->get_discounted_price_in_cents( $item ) : (float) $item->price;
			$lines[]   = array(
				'key'       => $item->key,
				'qty'       => (int) $item->quantity,
				'unit'      => (float) $item->price / (int) $item->quantity,
				'available' => $available,
				'product'   => $item->product,
				'is_gc'     => is_array( $item->object ) ? ! empty( $item->object['kudora_gc'] ) : ( $item->object instanceof WC_Order_Item_Product && is_array( $item->object->get_meta( '_kudora_gc_data' ) ) ),
			);
		}
		return $lines;
	}

	/**
	 * Lines from the live cart (cents), for "would this rule apply" checks.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array[]
	 */
	public static function lines_from_cart( $cart ) {
		$lines = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['data'] ) || empty( $item['quantity'] ) ) {
				continue;
			}
			$unit    = wc_add_number_precision( (float) $item['data']->get_price( 'edit' ) );
			$lines[] = array(
				'key'       => $key,
				'qty'       => (int) $item['quantity'],
				'unit'      => $unit,
				'available' => $unit * (int) $item['quantity'],
				'product'   => $item['data'],
				'is_gc'     => ! empty( $item['kudora_gc'] ),
			);
		}
		return $lines;
	}

	/**
	 * Whether a line is in a scope (gift cards never are).
	 *
	 * @param array $line  Line.
	 * @param array $scope Scope.
	 * @return bool
	 */
	protected static function in_scope( array $line, $scope ) {
		if ( $line['is_gc'] && Kudora_Settings::yes( 'discounts_exclude_gift_cards' ) ) {
			return false;
		}
		return Kudora_Scope::matches_product( (array) $scope, $line['product'] );
	}

	/**
	 * Expand lines into units.
	 *
	 * @param array[] $lines Lines.
	 * @param array   $scope Scope.
	 * @return array[] Units: key, price (cents), uid.
	 */
	protected static function units( array $lines, $scope ) {
		$units = array();
		foreach ( $lines as $line ) {
			if ( ! self::in_scope( $line, $scope ) ) {
				continue;
			}
			for ( $i = 0; $i < $line['qty']; $i++ ) {
				$units[] = array(
					'key'   => $line['key'],
					'price' => $line['unit'],
					'uid'   => $line['key'] . '#' . $i,
				);
			}
		}
		return $units;
	}

	/**
	 * Discount for a rule/coupon config by type.
	 *
	 * @param string  $type  cart_discount|bxgy|bundle.
	 * @param array   $cfg   Config.
	 * @param array[] $lines Lines.
	 * @return array<string,float> key => cents.
	 */
	public static function compute( $type, array $cfg, array $lines ) {
		switch ( $type ) {
			case 'bxgy':
				$out = self::bxgy( $cfg, $lines );
				break;
			case 'bundle':
				$out = self::bundle( $cfg, $lines );
				break;
			default:
				$out = self::cart_discount( $cfg, $lines );
		}
		// Never discount more than what is left on a line.
		foreach ( $lines as $line ) {
			if ( isset( $out[ $line['key'] ] ) ) {
				$out[ $line['key'] ] = max( 0, min( floor( $out[ $line['key'] ] ), floor( $line['available'] ) ) );
			}
		}
		return $out;
	}

	/**
	 * Percent or fixed off the in-scope lines, optional cap.
	 *
	 * @param array   $cfg   adjust (percent|fixed), value, max, scope.
	 * @param array[] $lines Lines.
	 * @return array
	 */
	public static function cart_discount( array $cfg, array $lines ) {
		$scope = isset( $cfg['scope'] ) ? $cfg['scope'] : array();
		$value = isset( $cfg['value'] ) ? (float) $cfg['value'] : 0.0;
		$out   = array();
		$base  = array();
		foreach ( $lines as $line ) {
			if ( self::in_scope( $line, $scope ) ) {
				$base[ $line['key'] ] = min( $line['unit'] * $line['qty'], $line['available'] );
			}
		}
		$sum = array_sum( $base );
		if ( $sum <= 0 || $value <= 0 ) {
			return array();
		}
		if ( isset( $cfg['adjust'] ) && 'fixed' === $cfg['adjust'] ) {
			$total = min( wc_add_number_precision( $value ), $sum );
		} else {
			$total = floor( $sum * min( 100, $value ) / 100 );
		}
		if ( ! empty( $cfg['max'] ) && (float) $cfg['max'] > 0 ) {
			$total = min( $total, wc_add_number_precision( (float) $cfg['max'] ) );
		}
		return self::distribute( $base, $total );
	}

	/**
	 * Split an amount (cents) over lines proportionally (largest remainder).
	 *
	 * @param array<string,float> $weights Key => weight.
	 * @param float               $total   Cents.
	 * @return array<string,float>
	 */
	public static function distribute( array $weights, $total ) {
		$sum   = array_sum( $weights );
		$out   = array();
		$given = 0;
		$rems  = array();
		foreach ( $weights as $key => $w ) {
			$exact        = $sum > 0 ? $total * $w / $sum : 0;
			$out[ $key ]  = floor( $exact );
			$rems[ $key ] = $exact - $out[ $key ];
			$given       += $out[ $key ];
		}
		arsort( $rems );
		$left = (int) round( $total - $given );
		foreach ( array_keys( $rems ) as $key ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( $out[ $key ] + 1 <= $weights[ $key ] ) {
				++$out[ $key ];
				--$left;
			}
		}
		return $out;
	}

	/**
	 * Buy X Get Y.
	 *
	 * Config: buy_scope, buy_qty, get_same (bool), get_scope, get_qty, reward
	 * (free|percent|fixed), reward_value, repeat (bool), pick (cheapest|expensive),
	 * max_sets (0 = unlimited).
	 *
	 * @param array   $cfg   Config.
	 * @param array[] $lines Lines.
	 * @return array
	 */
	public static function bxgy( array $cfg, array $lines ) {
		$buy_qty = max( 1, (int) ( isset( $cfg['buy_qty'] ) ? $cfg['buy_qty'] : 1 ) );
		$get_qty = max( 1, (int) ( isset( $cfg['get_qty'] ) ? $cfg['get_qty'] : 1 ) );
		$same    = ! isset( $cfg['get_same'] ) || ! empty( $cfg['get_same'] );
		$buy     = self::units( $lines, isset( $cfg['buy_scope'] ) ? $cfg['buy_scope'] : array() );
		$repeat  = ! empty( $cfg['repeat'] );
		$max     = isset( $cfg['max_sets'] ) ? (int) $cfg['max_sets'] : 0;
		$pick    = isset( $cfg['pick'] ) && 'expensive' === $cfg['pick'] ? 'expensive' : 'cheapest';

		$sets = $same ? intdiv( count( $buy ), $buy_qty + $get_qty ) : intdiv( count( $buy ), $buy_qty );
		if ( ! $repeat ) {
			$sets = min( 1, $sets );
		}
		if ( $max > 0 ) {
			$sets = min( $sets, $max );
		}
		if ( $same ) {
			$pool = $buy;
		} else {
			$get = self::units( $lines, isset( $cfg['get_scope'] ) ? $cfg['get_scope'] : array() );
			// Units bought to qualify cannot also be the free ones: qualify with the most expensive.
			usort(
				$buy,
				function ( $a, $b ) {
					return $b['price'] <=> $a['price'];
				}
			);
			$consumed = array_flip( array_column( array_slice( $buy, 0, $sets * $buy_qty ), 'uid' ) );
			$pool     = array_values(
				array_filter(
					$get,
					function ( $u ) use ( $consumed ) {
						return ! isset( $consumed[ $u['uid'] ] );
					}
				)
			);
		}
		$rewarded = min( $sets * $get_qty, count( $pool ) );
		if ( $rewarded <= 0 ) {
			return array();
		}
		usort(
			$pool,
			function ( $a, $b ) use ( $pick ) {
				return 'expensive' === $pick ? $b['price'] <=> $a['price'] : $a['price'] <=> $b['price'];
			}
		);
		$out    = array();
		$reward = isset( $cfg['reward'] ) ? $cfg['reward'] : 'free';
		$value  = isset( $cfg['reward_value'] ) ? (float) $cfg['reward_value'] : 0.0;
		for ( $i = 0; $i < $rewarded; $i++ ) {
			$u = $pool[ $i ];
			if ( 'percent' === $reward ) {
				$d = floor( $u['price'] * min( 100, $value ) / 100 );
			} elseif ( 'fixed' === $reward ) {
				$d = min( $u['price'], wc_add_number_precision( $value ) );
			} else {
				$d = $u['price'];
			}
			$out[ $u['key'] ] = ( isset( $out[ $u['key'] ] ) ? $out[ $u['key'] ] : 0 ) + $d;
		}
		return $out;
	}

	/**
	 * Bundle: any N units from the scope for a fixed price per set. Sets are formed
	 * from the most expensive units so the customer saves the most.
	 *
	 * @param array   $cfg   scope, qty, price, repeat.
	 * @param array[] $lines Lines.
	 * @return array
	 */
	public static function bundle( array $cfg, array $lines ) {
		$qty   = max( 2, (int) ( isset( $cfg['qty'] ) ? $cfg['qty'] : 2 ) );
		$price = wc_add_number_precision( (float) ( isset( $cfg['price'] ) ? $cfg['price'] : 0 ) );
		$units = self::units( $lines, isset( $cfg['scope'] ) ? $cfg['scope'] : array() );
		usort(
			$units,
			function ( $a, $b ) {
				return $b['price'] <=> $a['price'];
			}
		);
		$sets = intdiv( count( $units ), $qty );
		if ( empty( $cfg['repeat'] ) ) {
			$sets = min( 1, $sets );
		}
		$out = array();
		for ( $s = 0; $s < $sets; $s++ ) {
			$set   = array_slice( $units, $s * $qty, $qty );
			$sum   = array_sum( array_column( $set, 'price' ) );
			$saved = $sum - $price;
			if ( $saved <= 0 ) {
				continue;
			}
			$weights = array();
			foreach ( $set as $i => $u ) {
				$weights[ $u['uid'] ] = $u['price'];
			}
			foreach ( self::distribute( $weights, $saved ) as $uid => $d ) {
				$key         = substr( $uid, 0, strrpos( $uid, '#' ) );
				$out[ $key ] = ( isset( $out[ $key ] ) ? $out[ $key ] : 0 ) + $d;
			}
		}
		return $out;
	}

	/**
	 * Sanitise a BXGY config (shared by rules and coupons).
	 *
	 * @param array $raw Raw.
	 * @return array
	 */
	public static function sanitize_bxgy( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'buy_scope'    => Kudora_Scope::sanitize( isset( $raw['buy_scope'] ) ? $raw['buy_scope'] : array() ),
			'buy_qty'      => max( 1, absint( isset( $raw['buy_qty'] ) ? $raw['buy_qty'] : 1 ) ),
			'get_same'     => ! empty( $raw['get_same'] ) && 'no' !== $raw['get_same'],
			'get_scope'    => Kudora_Scope::sanitize( isset( $raw['get_scope'] ) ? $raw['get_scope'] : array() ),
			'get_qty'      => max( 1, absint( isset( $raw['get_qty'] ) ? $raw['get_qty'] : 1 ) ),
			'reward'       => isset( $raw['reward'] ) && in_array( $raw['reward'], array( 'free', 'percent', 'fixed' ), true ) ? $raw['reward'] : 'free',
			'reward_value' => isset( $raw['reward_value'] ) ? (float) wc_format_decimal( $raw['reward_value'] ) : 0.0,
			'repeat'       => ! empty( $raw['repeat'] ) && 'no' !== $raw['repeat'],
			'pick'         => isset( $raw['pick'] ) && 'expensive' === $raw['pick'] ? 'expensive' : 'cheapest',
			'max_sets'     => absint( isset( $raw['max_sets'] ) ? $raw['max_sets'] : 0 ),
			'auto_add'     => absint( isset( $raw['auto_add'] ) ? $raw['auto_add'] : 0 ),
		);
	}
}
