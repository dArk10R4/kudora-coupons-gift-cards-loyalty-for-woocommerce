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
	 * Discount given so far per capped coupon in the current calculation.
	 *
	 * @var array<string,float>
	 */
	protected static $given = array();

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'types' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( $this, 'cart_types' ) );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'free_product_discounts' ), 10, 2 );
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
	 * One unit of the product (cheapest matching line) is free.
	 *
	 * @param array     $discounts Cart item key => discount (cents).
	 * @param WC_Coupon $coupon    Coupon.
	 * @return array
	 */
	public function free_product_discounts( $discounts, $coupon ) {
		if ( self::FREE_PRODUCT !== $coupon->get_discount_type() ) {
			return $discounts;
		}
		$product_id = self::free_product_id( $coupon );
		$units      = max( 1, (int) $coupon->get_meta( '_kudora_free_qty' ) );
		foreach ( $discounts as $k => $v ) {
			$discounts[ $k ] = 0;
		}
		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $discounts;
		}
		$lines = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! array_key_exists( $key, $discounts ) ) {
				continue;
			}
			if ( (int) $item['product_id'] === $product_id || (int) $item['variation_id'] === $product_id ) {
				$lines[] = array(
					'key'   => $key,
					'price' => wc_add_number_precision( (float) $item['data']->get_price( 'edit' ) ),
					'qty'   => (int) $item['quantity'],
				);
			}
		}
		usort(
			$lines,
			function ( $a, $b ) {
				return $a['price'] <=> $b['price'];
			}
		);
		foreach ( $lines as $line ) {
			if ( $units <= 0 ) {
				break;
			}
			$take                        = min( $units, $line['qty'] );
			$discounts[ $line['key'] ]  += $take * $line['price'];
			$units                      -= $take;
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
