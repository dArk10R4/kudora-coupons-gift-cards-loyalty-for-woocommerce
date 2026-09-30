<?php
/**
 * Coupon behaviour that must work for real coupons regardless of which module is on:
 *  - `kudora_free_product` discount type: one unit of a chosen product free; the
 *    product is added to the cart when the coupon is applied (reward coupons).
 *  - A maximum discount cap for percentage coupons (`_kudora_max_discount` meta).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extra coupon types and caps.
 */
class Kudora_Coupon_Types {

	/**
	 * Free product type.
	 */
	const FREE_PRODUCT = 'kudora_free_product';

	/**
	 * A Kudora discount frozen at its recorded amount when an order is recalculated.
	 */
	const FROZEN = 'kudora_frozen';

	/**
	 * Discount given so far per capped coupon in the current calculation.
	 *
	 * @var array<string,float>
	 */
	protected static $given = array();

	/**
	 * Items (and the WC_Discounts object) captured for free-product coupons.
	 *
	 * @var array<string,array>
	 */
	protected static $captured = array();

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'types' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( $this, 'cart_types' ) );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'capture_items' ), 10, 3 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'free_product_discounts' ), 10, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'cap_on_order' ), 20, 4 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'add_free_product' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reset_caps' ), 1 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'cap' ), 100, 5 );
		add_action( 'woocommerce_coupon_options', array( $this, 'coupon_options' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_options' ), 10, 2 );
	}

	/**
	 * Register types.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function types( $types ) {
		$types[ self::FREE_PRODUCT ] = __( 'Free product', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$types[ self::FROZEN ]       = __( 'Recorded discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $types;
	}

	/**
	 * Cart-level types (so every line is passed to our calculation).
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function cart_types( $types ) {
		$types[] = self::FREE_PRODUCT;
		$types[] = self::FROZEN;
		return $types;
	}

	/**
	 * Product given by a free-product coupon.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return int
	 */
	public static function free_product_id( $coupon ) {
		$id = (int) $coupon->get_meta( '_kudora_free_product' );
		return $id ? $id : (int) $coupon->get_meta( '_kudora_auto_add_product' );
	}

	/**
	 * Remember the items a free-product coupon is applied to (cart or order).
	 *
	 * @param array        $items     Items.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Discounts.
	 * @return array
	 */
	public function capture_items( $items, $coupon, $discounts ) {
		if ( in_array( $coupon->get_discount_type(), array( self::FREE_PRODUCT, self::FROZEN ), true ) ) {
			self::$captured[ $coupon->get_code() ] = array(
				'items'     => $items,
				'discounts' => $discounts,
			);
		}
		return $items;
	}

	/**
	 * Units of the product (cheapest matching lines first) are free, never more
	 * than what is left of each line after earlier coupons. Works for carts and
	 * for orders (admin recalculation, REST).
	 *
	 * @param array     $discounts Cart item key => discount (cents).
	 * @param WC_Coupon $coupon    Coupon.
	 * @return array
	 */
	public function free_product_discounts( $discounts, $coupon ) {
		if ( self::FROZEN === $coupon->get_discount_type() ) {
			return self::frozen_discounts( $discounts, $coupon );
		}
		if ( self::FREE_PRODUCT !== $coupon->get_discount_type() ) {
			return $discounts;
		}
		$product_id = self::free_product_id( $coupon );
		$units      = max( 1, (int) $coupon->get_meta( '_kudora_free_qty' ) );
		foreach ( $discounts as $k => $v ) {
			$discounts[ $k ] = 0;
		}
		$code = $coupon->get_code();
		if ( ! $product_id || ! isset( self::$captured[ $code ] ) ) {
			return $discounts;
		}
		$lines = array();
		foreach ( Kudora_Rule_Calc::lines_from_discount_items( self::$captured[ $code ]['items'], self::$captured[ $code ]['discounts'] ) as $line ) {
			$product = $line['product'];
			if ( ! array_key_exists( $line['key'], $discounts ) || ! $product instanceof WC_Product ) {
				continue;
			}
			if ( $product->get_id() === $product_id || $product->get_parent_id() === $product_id ) {
				$lines[] = $line;
			}
		}
		usort(
			$lines,
			function ( $a, $b ) {
				return $a['unit'] <=> $b['unit'];
			}
		);
		foreach ( $lines as $line ) {
			if ( $units <= 0 ) {
				break;
			}
			$take                      = min( $units, $line['qty'] );
			$discounts[ $line['key'] ] = min( $line['available'], $take * $line['unit'] );
			$units                    -= $take;
		}
		return $discounts;
	}

	/**
	 * A frozen Kudora discount: its recorded amount spread over the lines in its
	 * scope in proportion to what is left of them, skipping gift card purchases.
	 *
	 * @param array     $discounts Line key => discount (cents).
	 * @param WC_Coupon $coupon    Coupon.
	 * @return array
	 */
	protected static function frozen_discounts( $discounts, $coupon ) {
		foreach ( $discounts as $k => $v ) {
			$discounts[ $k ] = 0;
		}
		$code = $coupon->get_code();
		if ( ! isset( self::$captured[ $code ] ) ) {
			return $discounts;
		}
		$scope   = (array) $coupon->get_meta( '_kudora_frozen_scope' );
		$weights = array();
		foreach ( Kudora_Rule_Calc::lines_from_discount_items( self::$captured[ $code ]['items'], self::$captured[ $code ]['discounts'] ) as $line ) {
			if ( $line['is_gc'] || ! array_key_exists( $line['key'], $discounts ) || $line['available'] <= 0 ) {
				continue;
			}
			if ( ! Kudora_Scope::is_all( $scope ) && ( ! $line['product'] instanceof WC_Product || ! Kudora_Scope::matches_product( $scope, $line['product'] ) ) ) {
				continue;
			}
			$weights[ $line['key'] ] = $line['available'];
		}
		if ( ! $weights ) {
			return $discounts;
		}
		$total = min( array_sum( $weights ), wc_add_number_precision( (float) $coupon->get_amount() ) );
		foreach ( Kudora_Rule_Calc::distribute( $weights, $total ) as $key => $cents ) {
			$discounts[ $key ] = $cents;
		}
		return $discounts;
	}

	/**
	 * Add the free product when its coupon is applied.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	public function add_free_product( $code ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$coupon = new WC_Coupon( $code );
		if ( self::FREE_PRODUCT !== $coupon->get_discount_type() ) {
			return;
		}
		$product_id = self::free_product_id( $coupon );
		if ( ! $product_id ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === $product_id || (int) $item['variation_id'] === $product_id ) {
				return;
			}
		}
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
			if ( $product->is_type( 'variation' ) ) {
				WC()->cart->add_to_cart( $product->get_parent_id(), 1, $product_id, $product->get_variation_attributes() );
			} else {
				WC()->cart->add_to_cart( $product_id, 1 );
			}
		}
	}

	/**
	 * New calculation: reset cap counters.
	 *
	 * @return void
	 */
	public function reset_caps() {
		self::$given = array();
	}

	/**
	 * Cap a coupon's total discount across the cart.
	 *
	 * @param float     $discount           Discount for this line.
	 * @param float     $discounting_amount Amount being discounted.
	 * @param mixed     $cart_item          Cart item.
	 * @param bool      $single             Single unit.
	 * @param WC_Coupon $coupon             Coupon.
	 * @return float
	 */
	public function cap( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->get_id() ) {
			return $discount;
		}
		$max = (float) $coupon->get_meta( '_kudora_max_discount' );
		if ( $max <= 0 ) {
			return $discount;
		}
		$code  = $coupon->get_code();
		$given = isset( self::$given[ $code ] ) ? self::$given[ $code ] : 0.0;
		$room  = max( 0.0, $max - $given );
		$final = min( (float) $discount, $room );
		self::$given[ $code ] = $given + $final;
		return $final;
	}

	/**
	 * Orders (admin recalculation, "Apply coupon" on an order, REST): WooCommerce
	 * only runs the legacy per-line filter for carts, so a capped percentage coupon
	 * is applied as the fixed amount it is worth, never above its cap.
	 *
	 * @param WC_Coupon            $coupon Coupon object used for the recalculation.
	 * @param string               $code   Code.
	 * @param WC_Order_Item_Coupon $item   Coupon line.
	 * @param WC_Order             $order  Order.
	 * @return WC_Coupon
	 */
	public function cap_on_order( $coupon, $code, $item = null, $order = null ) {
		if ( ! $coupon instanceof WC_Coupon || 'percent' !== $coupon->get_discount_type() || ! $order instanceof WC_Order ) {
			return $coupon;
		}
		$stored = $coupon->get_id() ? $coupon : new WC_Coupon( $code );
		$max    = (float) $stored->get_meta( '_kudora_max_discount' );
		if ( $max <= 0 ) {
			return $coupon;
		}
		$base = 0.0;
		foreach ( $order->get_items() as $line ) {
			$product = $line instanceof WC_Order_Item_Product ? $line->get_product() : null;
			if ( $product && $coupon->is_valid_for_product( $product, array() ) ) {
				$base += (float) $line->get_subtotal();
			}
		}
		$worth = Kudora_Money::round( $base * min( 100, (float) $coupon->get_amount() ) / 100 );
		if ( $worth <= $max ) {
			return $coupon;
		}
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $max );
		return $coupon;
	}

	/**
	 * Coupon admin: max discount + free product fields.
	 *
	 * @param int       $coupon_id Coupon id.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return void
	 */
	public function coupon_options( $coupon_id, $coupon ) {
		woocommerce_wp_text_input(
			array(
				'id'                => '_kudora_max_discount',
				'label'             => __( 'Maximum discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'placeholder'       => __( 'No limit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'description'       => __( 'Caps the total discount of a percentage coupon, e.g. 20% off up to 50.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'desc_tip'          => true,
				'data_type'         => 'price',
				'value'             => $coupon->get_meta( '_kudora_max_discount' ),
				'wrapper_class'     => 'kudora-show-percent',
			)
		);
		$pid = self::free_product_id( $coupon );
		?>
		<p class="form-field kudora-show-free-product">
			<label for="_kudora_free_product"><?php esc_html_e( 'Free product', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select class="wc-product-search" style="width:50%;" id="_kudora_free_product" name="_kudora_free_product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
				<?php
				$p = $pid ? wc_get_product( $pid ) : null;
				if ( $p ) {
					echo '<option value="' . esc_attr( $pid ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
				}
				?>
			</select>
			<?php echo wc_help_tip( __( 'For the "Free product" type: one unit of this product becomes free and is added to the cart when the coupon is applied.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes. ?>
		</p>
		<?php
	}

	/**
	 * Save coupon fields.
	 *
	 * @param int       $coupon_id Coupon id.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return void
	 */
	public function save_coupon_options( $coupon_id, $coupon ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the coupon save nonce.
		$max = isset( $_POST['_kudora_max_discount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_kudora_max_discount'] ) ) ) : '';
		$pid = isset( $_POST['_kudora_free_product'] ) ? absint( $_POST['_kudora_free_product'] ) : 0;
		// phpcs:enable
		$coupon->update_meta_data( '_kudora_max_discount', $max );
		if ( $pid || self::FREE_PRODUCT === $coupon->get_discount_type() ) {
			$coupon->update_meta_data( '_kudora_free_product', $pid );
		}
		$coupon->save();
	}
}
