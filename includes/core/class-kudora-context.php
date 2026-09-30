<?php
/**
 * A normalised snapshot of "what is being bought, by whom, when" — built from the
 * live cart or from a saved order — so discount rules, coupon conditions and loyalty
 * earning rules evaluate identically in both situations.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluation context.
 */
class Kudora_Context {

	/**
	 * Line items: key, product_id, variation_id, qty, price (unit, current), subtotal
	 * (before coupons, excl. tax), total (after coupons, excl. tax), cat_ids, tag_ids,
	 * is_gift_card, on_sale, product.
	 *
	 * @var array[]
	 */
	public $items = array();

	/**
	 * Items subtotal excl. tax, before coupons.
	 *
	 * @var float
	 */
	public $subtotal = 0.0;

	/**
	 * Total item quantity.
	 *
	 * @var int
	 */
	public $quantity = 0;

	/**
	 * Customer user id (0 = guest).
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Customer email (lowercase), billing email for guests.
	 *
	 * @var string
	 */
	public $email = '';

	/**
	 * Billing country code.
	 *
	 * @var string
	 */
	public $billing_country = '';

	/**
	 * Shipping country code.
	 *
	 * @var string
	 */
	public $shipping_country = '';

	/**
	 * Applied coupon codes (lowercase), excluding Kudora's own virtual codes.
	 *
	 * @var string[]
	 */
	public $coupons = array();

	/**
	 * The order when built from one.
	 *
	 * @var WC_Order|null
	 */
	public $order = null;

	/**
	 * "Now" in the site timezone (the order's paid/created date for orders).
	 *
	 * @var DateTimeImmutable
	 */
	public $now;

	/**
	 * Per-context memo for expensive customer-history lookups.
	 *
	 * @var array
	 */
	public $memo = array();

	/**
	 * Build from the cart.
	 *
	 * @param WC_Cart|null $cart       Cart (defaults to WC()->cart).
	 * @param callable     $price_of   Optional unit-price resolver fn( array $cart_item ): float.
	 * @return Kudora_Context
	 */
	public static function from_cart( $cart = null, $price_of = null ) {
		$ctx  = new self();
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$ctx->now = new DateTimeImmutable( 'now', wp_timezone() );
		if ( ! $cart ) {
			return $ctx;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$product = $item['data'];
			$qty     = (int) $item['quantity'];
			$price   = $price_of ? (float) call_user_func( $price_of, $item ) : (float) $product->get_price( 'edit' );
			$sub     = $price * $qty;
			$ctx->add_item(
				$key,
				$product,
				(int) $item['product_id'],
				(int) $item['variation_id'],
				$qty,
				$price,
				$sub,
				isset( $item['line_total'] ) ? (float) $item['line_total'] : $sub,
				! empty( $item['kudora_gc'] )
			);
		}
		$customer = $cart->get_customer();
		$ctx->user_id = get_current_user_id();
		$email        = $customer ? $customer->get_billing_email() : '';
		if ( ! $email && $ctx->user_id ) {
			$user  = get_userdata( $ctx->user_id );
			$email = $user ? $user->user_email : '';
		}
		$ctx->email            = strtolower( (string) $email );
		$ctx->billing_country  = $customer ? (string) $customer->get_billing_country() : '';
		$ctx->shipping_country = $customer ? (string) $customer->get_shipping_country() : '';
		$ctx->coupons          = self::user_coupons( $cart->get_applied_coupons() );
		return $ctx;
	}

	/**
	 * Build from an order.
	 *
	 * @param WC_Order $order Order.
	 * @return Kudora_Context
	 */
	public static function from_order( WC_Order $order ) {
		$ctx        = new self();
		$ctx->order = $order;
		$created    = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$ctx->now   = $created ? new DateTimeImmutable( '@' . $created->getTimestamp() ) : new DateTimeImmutable( 'now' );
		$ctx->now   = $ctx->now->setTimezone( wp_timezone() );
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$qty = max( 1, (int) $item->get_quantity() );
			$ctx->add_item(
				(string) $item_id,
				$product,
				(int) $item->get_product_id(),
				(int) $item->get_variation_id(),
				$qty,
				(float) $item->get_subtotal() / $qty,
				(float) $item->get_subtotal(),
				(float) $item->get_total(),
				'' !== (string) $item->get_meta( '_kudora_gc_data' )
			);
		}
		$ctx->user_id          = (int) $order->get_customer_id();
		$ctx->email            = strtolower( (string) $order->get_billing_email() );
		$ctx->billing_country  = (string) $order->get_billing_country();
		$ctx->shipping_country = (string) $order->get_shipping_country();
		$ctx->coupons          = self::user_coupons( $order->get_coupon_codes() );
		return $ctx;
	}

	/**
	 * Add one normalised line.
	 *
	 * @param string     $key          Line key.
	 * @param WC_Product $product      Product (variation for variable lines).
	 * @param int        $product_id   Parent product id.
	 * @param int        $variation_id Variation id.
	 * @param int        $qty          Quantity.
	 * @param float      $price        Unit price.
	 * @param float      $subtotal     Line subtotal before coupons.
	 * @param float      $total        Line total after coupons.
	 * @param bool       $is_gc        Whether this line is a Kudora gift card.
	 * @return void
	 */
	protected function add_item( $key, $product, $product_id, $variation_id, $qty, $price, $subtotal, $total, $is_gc ) {
		$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$this->items[] = array(
			'key'          => (string) $key,
			'product'      => $product,
			'product_id'   => $product_id ? $product_id : $parent_id,
			'variation_id' => $variation_id,
			'qty'          => $qty,
			'price'        => $price,
			'subtotal'     => $subtotal,
			'total'        => $total,
			'cat_ids'      => array_map( 'intval', wc_get_product_cat_ids( $parent_id ) ),
			'tag_ids'      => array_map( 'intval', wc_get_product_term_ids( $parent_id, 'product_tag' ) ),
			'on_sale'      => $product->is_on_sale( 'edit' ),
			'is_gift_card' => $is_gc || Kudora_Context::is_gift_card_product( $product ),
		);
		$this->subtotal += $subtotal;
		$this->quantity += $qty;
	}

	/**
	 * Whether a product (or its parent) is a Kudora gift card product.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_gift_card_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, '_kudora_gift_card', true );
	}

	/**
	 * Drop Kudora's own virtual coupon codes from a code list.
	 *
	 * @param string[] $codes Codes.
	 * @return string[]
	 */
	protected static function user_coupons( $codes ) {
		$out = array();
		foreach ( (array) $codes as $code ) {
			$code = wc_format_coupon_code( $code );
			if ( 0 !== strpos( $code, 'kudora-' ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Paid-order ids of this customer (user id, or billing email for guests),
	 * excluding the context's own order. Memoised.
	 *
	 * @return int[]
	 */
	public function previous_paid_order_ids() {
		if ( isset( $this->memo['paid_ids'] ) ) {
			return $this->memo['paid_ids'];
		}
		$ids = array();
		if ( $this->user_id || $this->email ) {
			$args = array(
				'status' => wc_get_is_paid_statuses(),
				'limit'  => 500,
				'return' => 'ids',
				'type'   => 'shop_order',
			);
			if ( $this->user_id ) {
				$args['customer_id'] = $this->user_id;
			} else {
				$args['billing_email'] = $this->email;
			}
			$ids = array_map( 'intval', wc_get_orders( $args ) );
			if ( $this->order ) {
				$ids = array_values( array_diff( $ids, array( (int) $this->order->get_id() ) ) );
			}
		}
		$this->memo['paid_ids'] = $ids;
		return $ids;
	}

	/**
	 * Number of previous paid orders.
	 *
	 * @return int
	 */
	public function order_count() {
		return count( $this->previous_paid_order_ids() );
	}

	/**
	 * Money spent on previous paid orders.
	 *
	 * @return float
	 */
	public function total_spent() {
		if ( isset( $this->memo['spent'] ) ) {
			return $this->memo['spent'];
		}
		$sum = 0.0;
		foreach ( $this->previous_paid_order_ids() as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$sum += (float) $o->get_total() - (float) $o->get_total_refunded();
			}
		}
		$this->memo['spent'] = $sum;
		return $sum;
	}

	/**
	 * Product and category ids bought in previous paid orders.
	 *
	 * @return array{products:int[],categories:int[]}
	 */
	public function purchased() {
		if ( isset( $this->memo['purchased'] ) ) {
			return $this->memo['purchased'];
		}
		$products = array();
		$cats     = array();
		foreach ( $this->previous_paid_order_ids() as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			foreach ( $o->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					$products[] = (int) $item->get_product_id();
					if ( $item->get_variation_id() ) {
						$products[] = (int) $item->get_variation_id();
					}
				}
			}
		}
		$products = array_values( array_unique( $products ) );
		foreach ( $products as $pid ) {
			$cats = array_merge( $cats, array_map( 'intval', wc_get_product_cat_ids( $pid ) ) );
		}
		$this->memo['purchased'] = array(
			'products'   => $products,
			'categories' => array_values( array_unique( $cats ) ),
		);
		return $this->memo['purchased'];
	}
}
