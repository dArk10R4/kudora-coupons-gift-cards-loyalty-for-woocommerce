<?php
/**
 * "Gift card" products: a checkbox on simple and variable products (variations act
 * as denominations), preset and custom amounts, recipient/message/delivery date,
 * design picker, and the data carried from cart to order line.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card product integration.
 */
class Kudora_Gift_Card_Product {

	/**
	 * Max message length.
	 */
	const MESSAGE_MAX = 300;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		// Admin.
		add_filter( 'product_type_options', array( $this, 'type_option' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'data_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );

		// Catalog.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'no_ajax_add' ), 10, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'loop_url' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'loop_text' ), 20, 2 );

		// Single product form (classic templates and the legacy add-to-cart-form block).
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'form' ), 5 );
		add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( $this, 'replace_with_options_block' ), 10, 2 );

		// Cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_prices' ), 5 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_validate' ), 10, 2 );

		// Order.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line' ), 10, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'order_line_display' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Product settings
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a product (or its parent) is a gift card product.
	 *
	 * @param WC_Product|int $product Product.
	 * @return bool
	 */
	public static function is_gift_card( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		return $product instanceof WC_Product && Kudora_Context::is_gift_card_product( $product );
	}

	/**
	 * Settings of a gift card product (parent for variations).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function settings( $product ) {
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$p  = $product->get_parent_id() ? wc_get_product( $id ) : $product;
		$amounts = array();
		foreach ( explode( ',', (string) $p->get_meta( '_kudora_gc_amounts' ) ) as $a ) {
			$a = Kudora_Money::parse( trim( $a ) );
			if ( $a > 0 ) {
				$amounts[] = Kudora_Money::round( $a );
			}
		}
		$amounts = array_values( array_unique( $amounts ) );
		sort( $amounts );
		$expiry = $p->get_meta( '_kudora_gc_expiry_days' );
		return array(
			'amounts'      => $amounts,
			'custom'       => 'yes' === $p->get_meta( '_kudora_gc_custom' ),
			'min'          => Kudora_Money::parse( $p->get_meta( '_kudora_gc_min' ) ),
			'max'          => Kudora_Money::parse( $p->get_meta( '_kudora_gc_max' ) ),
			'expiry_days'  => '' === $expiry ? Kudora_Settings::int( 'gc_default_expiry_days' ) : (int) $expiry,
			'designs'      => array_filter( array_map( 'absint', (array) $p->get_meta( '_kudora_gc_designs' ) ) ),
			'physical'     => 'yes' === $p->get_meta( '_kudora_gc_physical' ),
			'restrictions' => (array) $p->get_meta( '_kudora_gc_restrictions' ),
			'variable'     => $p->is_type( 'variable' ),
		);
	}

	/**
	 * "Gift card" checkbox next to Virtual/Downloadable.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	public function type_option( $options ) {
		$options['kudora_gift_card'] = array(
			'id'            => '_kudora_gift_card',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'description'   => __( 'Sell this product as a gift card. A unique code is created and emailed when the order is paid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'default'       => 'no',
		);
		return $options;
	}

	/**
	 * "Gift card" product data tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function data_tab( $tabs ) {
		$tabs['kudora_gift_card'] = array(
			'label'    => __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'target'   => 'kudora_gift_card_data',
			'class'    => array( 'show_if_kudora_gift_card' ),
			'priority' => 15,
		);
		return $tabs;
	}

	/**
	 * Tab panel.
	 *
	 * @return void
	 */
	public function data_panel() {
		global $product_object;
		$product = $product_object instanceof WC_Product ? $product_object : null;
		$designs = Kudora_Gift_Card_Designs::all( true );
		include KUDORA_DIR . 'includes/admin/views/product-gift-card-panel.php';
	}

	/**
	 * Save the panel.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public function save( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce before this hook.
		$was     = 'yes' === $product->get_meta( '_kudora_gift_card' );
		$enabled = isset( $_POST['_kudora_gift_card'] ) && ! $product->is_type( array( 'grouped', 'external' ) );
		$product->update_meta_data( '_kudora_gift_card', $enabled ? 'yes' : 'no' );
		if ( ! $enabled ) {
			return;
		}
		$amounts = isset( $_POST['_kudora_gc_amounts'] ) ? sanitize_text_field( wp_unslash( $_POST['_kudora_gc_amounts'] ) ) : '';
		$clean   = array();
		foreach ( explode( ',', $amounts ) as $a ) {
			$v = Kudora_Money::parse( trim( $a ) );
			if ( $v > 0 ) {
				$clean[] = wc_format_decimal( $v, wc_get_price_decimals() );
			}
		}
		$product->update_meta_data( '_kudora_gc_amounts', implode( ',', array_unique( $clean ) ) );
		$product->update_meta_data( '_kudora_gc_custom', isset( $_POST['_kudora_gc_custom'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_kudora_gc_min', isset( $_POST['_kudora_gc_min'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_kudora_gc_min'] ) ) ) : '' );
		$product->update_meta_data( '_kudora_gc_max', isset( $_POST['_kudora_gc_max'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_kudora_gc_max'] ) ) ) : '' );
		$expiry = isset( $_POST['_kudora_gc_expiry_days'] ) ? sanitize_text_field( wp_unslash( $_POST['_kudora_gc_expiry_days'] ) ) : '';
		$product->update_meta_data( '_kudora_gc_expiry_days', '' === $expiry ? '' : (string) absint( $expiry ) );
		$product->update_meta_data( '_kudora_gc_designs', isset( $_POST['_kudora_gc_designs'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['_kudora_gc_designs'] ) ) : array() );
		$physical = isset( $_POST['_kudora_gc_physical'] );
		$product->update_meta_data( '_kudora_gc_physical', $physical ? 'yes' : 'no' );
		$product->update_meta_data(
			'_kudora_gc_restrictions',
			Kudora_Scope::sanitize(
				array(
					'products'           => isset( $_POST['_kudora_gc_r_products'] ) ? (array) wp_unslash( $_POST['_kudora_gc_r_products'] ) : array(),
					'categories'         => isset( $_POST['_kudora_gc_r_categories'] ) ? (array) wp_unslash( $_POST['_kudora_gc_r_categories'] ) : array(),
					'exclude_products'   => isset( $_POST['_kudora_gc_r_exclude_products'] ) ? (array) wp_unslash( $_POST['_kudora_gc_r_exclude_products'] ) : array(),
					'exclude_categories' => isset( $_POST['_kudora_gc_r_exclude_categories'] ) ? (array) wp_unslash( $_POST['_kudora_gc_r_exclude_categories'] ) : array(),
				)
			)
		);
		// phpcs:enable

		// First time a product becomes a gift card: sensible defaults. Gift cards are
		// not taxed at purchase — tax is charged on what the card is spent on.
		if ( ! $was ) {
			$product->set_tax_status( 'none' );
			if ( ! $physical && $product->is_type( 'simple' ) ) {
				$product->set_virtual( true );
			}
			$product->set_sold_individually( false );
		}
		// A simple gift card needs a price for WooCommerce; use the smallest amount.
		if ( $product->is_type( 'simple' ) && '' === (string) $product->get_regular_price( 'edit' ) ) {
			$first = $clean ? min( array_map( 'floatval', $clean ) ) : Kudora_Money::parse( $product->get_meta( '_kudora_gc_min' ) );
			if ( $first > 0 ) {
				$product->set_regular_price( wc_format_decimal( $first ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Catalog
	 * ------------------------------------------------------------------- */

	/**
	 * Gift cards with presets/custom amounts are purchasable even without a price.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public function purchasable( $purchasable, $product ) {
		if ( $purchasable || ! $product->is_type( 'simple' ) || ! self::is_gift_card( $product ) ) {
			return $purchasable;
		}
		$s = self::settings( $product );
		return ( $s['amounts'] || $s['custom'] ) && $product->exists() && 'publish' === $product->get_status();
	}

	/**
	 * Price range for simple gift cards.
	 *
	 * @param string     $html    HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function price_html( $html, $product ) {
		if ( ! $product->is_type( 'simple' ) || ! self::is_gift_card( $product ) ) {
			return $html;
		}
		$s    = self::settings( $product );
		$list = $s['amounts'];
		if ( $s['custom'] ) {
			if ( $s['min'] > 0 ) {
				$list[] = $s['min'];
			}
			if ( $s['max'] > 0 ) {
				$list[] = $s['max'];
			}
		}
		if ( ! $list ) {
			return $html;
		}
		$min = min( $list );
		$max = max( $list );
		if ( $s['custom'] && $s['max'] <= 0 ) {
			/* translators: %s: minimum price */
			return sprintf( esc_html__( 'From %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $min ) );
		}
		return $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
	}

	/**
	 * No AJAX add-to-cart from listings: the customer must fill in the form.
	 *
	 * @param bool       $supports Supports.
	 * @param string     $feature  Feature.
	 * @param WC_Product $product  Product.
	 * @return bool
	 */
	public function no_ajax_add( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && self::is_gift_card( $product ) ) {
			return false;
		}
		return $supports;
	}

	/**
	 * Listing button goes to the product page.
	 *
	 * @param string     $url     URL.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function loop_url( $url, $product ) {
		return ( $product instanceof WC_Product && $product->is_type( 'simple' ) && self::is_gift_card( $product ) ) ? $product->get_permalink() : $url;
	}

	/**
	 * Listing button text.
	 *
	 * @param string     $text    Text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function loop_text( $text, $product ) {
		return ( $product instanceof WC_Product && $product->is_type( 'simple' ) && self::is_gift_card( $product ) && $product->is_purchasable() ) ? __( 'Choose gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : $text;
	}

	/* ---------------------------------------------------------------------
	 * Single product form
	 * ------------------------------------------------------------------- */

	/**
	 * Output the gift card fields inside the add-to-cart form.
	 *
	 * @return void
	 */
	public function form() {
		global $product;
		if ( ! $product instanceof WC_Product || ! self::is_gift_card( $product ) ) {
			return;
		}
		$settings = self::settings( $product );
		$designs  = Kudora_Gift_Card_Designs::for_product( $settings['designs'] );
		$user     = wp_get_current_user();
		wp_enqueue_style( 'kudora-frontend' );
		wp_enqueue_script( 'kudora-gift-card-form' );
		wc_get_template(
			'gift-card/purchase-form.php',
			array(
				'product'  => $product,
				'settings' => $settings,
				'designs'  => $designs,
				'sender'   => $user && $user->exists() ? $user->display_name : '',
				'today'    => wp_date( 'Y-m-d' ),
				'max_date' => wp_date( 'Y-m-d', time() + YEAR_IN_SECONDS ),
				'posted'   => self::posted(),
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}

	/**
	 * Block themes using the newer "Add to Cart with Options" block drop custom form
	 * fields (they add to cart through the Store API). For gift card products render
	 * the classic form instead, which posts to WooCommerce's form handler.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function replace_with_options_block( $content, $block ) {
		global $product;
		$p = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
		if ( ! $p || ! self::is_gift_card( $p ) || ! function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
			return $content;
		}
		$previous = $product;
		$product  = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the classic template reads the global.
		if ( $p->is_type( 'variable' ) ) {
			wp_enqueue_script( 'wc-add-to-cart-variation' );
		}
		ob_start();
		echo '<div class="wp-block-woocommerce-add-to-cart-with-options kudora-classic-add-to-cart">';
		woocommerce_template_single_add_to_cart();
		echo '</div>';
		$html    = ob_get_clean();
		$product = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $html;
	}

	/**
	 * Read posted form values (sanitised; used for validation and re-filling).
	 *
	 * @return array
	 */
	public static function posted() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- add-to-cart forms carry no nonce in WooCommerce; values are only sanitised input for the cart line.
		$get = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};
		$message = isset( $_POST['kudora_gc_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kudora_gc_message'] ) ) : '';
		// phpcs:enable
		return array(
			'amount'          => $get( 'kudora_gc_amount' ),
			'custom_amount'   => $get( 'kudora_gc_custom_amount' ),
			'send_to'         => 'other' === $get( 'kudora_gc_send_to' ) ? 'other' : 'self',
			'recipient_name'  => $get( 'kudora_gc_recipient_name' ),
			'recipient_email' => sanitize_email( $get( 'kudora_gc_recipient_email' ) ),
			'sender_name'     => $get( 'kudora_gc_sender_name' ),
			'message'         => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, self::MESSAGE_MAX ) : substr( $message, 0, self::MESSAGE_MAX ),
			'deliver_date'    => $get( 'kudora_gc_deliver_date' ),
			'design_id'       => absint( $get( 'kudora_gc_design' ) ),
			'has_form'        => '' !== $get( 'kudora_gc_form' ),
		);
	}

	/**
	 * Resolve the unit amount a customer chose.
	 *
	 * @param WC_Product $product      Product (simple).
	 * @param array      $posted       Posted values.
	 * @return float 0 when nothing valid was chosen.
	 */
	public static function chosen_amount( $product, array $posted ) {
		$s = self::settings( $product );
		if ( 'custom' === $posted['amount'] || ( '' === $posted['amount'] && '' !== $posted['custom_amount'] ) ) {
			return $s['custom'] ? Kudora_Money::round( Kudora_Money::parse( $posted['custom_amount'] ) ) : 0.0;
		}
		if ( '' !== $posted['amount'] ) {
			$a = Kudora_Money::round( Kudora_Money::parse( $posted['amount'] ) );
			foreach ( $s['amounts'] as $preset ) {
				if ( abs( $preset - $a ) < 0.001 ) {
					return $preset;
				}
			}
			return 0.0;
		}
		// No choice posted: a single preset or the product price.
		if ( 1 === count( $s['amounts'] ) ) {
			return $s['amounts'][0];
		}
		if ( ! $s['amounts'] && ! $s['custom'] ) {
			return Kudora_Money::round( (float) $product->get_price( 'edit' ) );
		}
		return 0.0;
	}

	/**
	 * Validate the form on add to cart.
	 *
	 * @param bool $passed       Passed.
	 * @param int  $product_id   Product id.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation id.
	 * @param array $variations  Variation attributes.
	 * @return bool
	 */
	public function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! self::is_gift_card( $product_id ) ) {
			return $passed;
		}
		$product = wc_get_product( $product_id );
		$posted  = self::posted();
		$errors  = self::errors_for( $product, $posted, (int) $variation_id );
		foreach ( $errors as $e ) {
			wc_add_notice( $e, 'error' );
		}
		return empty( $errors );
	}

	/**
	 * Validation messages for a posted form.
	 *
	 * @param WC_Product $product      Product (parent).
	 * @param array      $posted       Posted.
	 * @param int        $variation_id Variation id.
	 * @return string[]
	 */
	public static function errors_for( $product, array $posted, $variation_id = 0 ) {
		$errors = array();
		$s      = self::settings( $product );
		if ( ! $product->is_type( 'variable' ) ) {
			$amount = self::chosen_amount( $product, $posted );
			if ( $amount <= 0 ) {
				$errors[] = __( 'Please choose a gift card amount.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			} elseif ( 'custom' === $posted['amount'] || ( '' === $posted['amount'] && '' !== $posted['custom_amount'] ) ) {
				if ( $s['min'] > 0 && $amount < $s['min'] ) {
					/* translators: %s: minimum amount */
					$errors[] = sprintf( __( 'The minimum gift card amount is %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $s['min'] ) );
				}
				if ( $s['max'] > 0 && $amount > $s['max'] ) {
					/* translators: %s: maximum amount */
					$errors[] = sprintf( __( 'The maximum gift card amount is %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $s['max'] ) );
				}
			}
		}
		if ( 'other' === $posted['send_to'] && ! $s['physical'] ) {
			if ( ! is_email( $posted['recipient_email'] ) ) {
				$errors[] = __( 'Please enter a valid recipient email address.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		if ( '' !== $posted['deliver_date'] ) {
			$today = wp_date( 'Y-m-d' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $posted['deliver_date'] ) || $posted['deliver_date'] < $today || $posted['deliver_date'] > wp_date( 'Y-m-d', time() + YEAR_IN_SECONDS ) ) {
				$errors[] = __( 'Please choose a delivery date between today and one year from now.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		if ( $posted['design_id'] ) {
			$allowed = wp_list_pluck( Kudora_Gift_Card_Designs::for_product( $s['designs'] ), 'id' );
			if ( ! in_array( $posted['design_id'], array_map( 'intval', $allowed ), true ) ) {
				$errors[] = __( 'Please choose one of the available designs.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			}
		}
		return $errors;
	}

	/**
	 * Store API add-to-cart (e.g. a block product button) cannot carry the form:
	 * only allow it when the product needs no choices.
	 *
	 * @param WC_Product      $product Product.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When choices are needed.
	 */
	public function store_api_validate( $product, $request ) {
		if ( ! self::is_gift_card( $product ) ) {
			return;
		}
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent->is_type( 'variable' ) && self::chosen_amount( $parent, self::posted() ) <= 0 ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kudora_gift_card_options', esc_html__( 'Please choose the gift card amount on the product page.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 400 );
		}
	}

	/**
	 * Attach the gift card data to the cart line.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product id.
	 * @param int   $variation_id Variation id.
	 * @return array
	 */
	public function cart_item_data( $data, $product_id, $variation_id ) {
		if ( ! self::is_gift_card( $product_id ) ) {
			return $data;
		}
		$product = wc_get_product( $product_id );
		$posted  = self::posted();
		$s       = self::settings( $product );
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$amount    = $variation ? Kudora_Money::round( (float) $variation->get_price( 'edit' ) ) : 0.0;
		} else {
			$amount = self::chosen_amount( $product, $posted );
		}
		$designs   = Kudora_Gift_Card_Designs::for_product( $s['designs'] );
		$design_id = $posted['design_id'] ? $posted['design_id'] : ( $designs ? (int) $designs[0]['id'] : 0 );
		$user      = wp_get_current_user();

		$data['kudora_gc'] = array(
			'amount'          => $amount,
			'send_to'         => $s['physical'] ? 'self' : $posted['send_to'],
			'recipient_name'  => 'other' === $posted['send_to'] ? $posted['recipient_name'] : '',
			'recipient_email' => 'other' === $posted['send_to'] ? strtolower( $posted['recipient_email'] ) : '',
			'sender_name'     => '' !== $posted['sender_name'] ? $posted['sender_name'] : ( $user && $user->exists() ? $user->display_name : '' ),
			'message'         => $posted['message'],
			'deliver_date'    => $posted['deliver_date'] > wp_date( 'Y-m-d' ) ? $posted['deliver_date'] : '',
			'design_id'       => $design_id,
			'physical'        => $s['physical'],
		);
		return $data;
	}

	/**
	 * Unit price = chosen amount (simple gift cards).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['kudora_gc']['amount'] ) && isset( $item['data'] ) && ! $item['variation_id'] ) {
				$item['data']->set_price( (float) $item['kudora_gc']['amount'] );
			}
		}
	}

	/**
	 * Show the gift card details under the cart line (classic and blocks).
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['kudora_gc'] ) ) {
			return $item_data;
		}
		foreach ( self::display_rows( $cart_item['kudora_gc'] ) as $row ) {
			// `display` is printed as-is (after kses), so escape here: "Name <email>" must not be read as a tag.
			$row['display'] = esc_html( $row['value'] );
			$item_data[]    = $row;
		}
		return $item_data;
	}

	/**
	 * Human rows for a gift card data array.
	 *
	 * @param array $gc Data.
	 * @return array[]
	 */
	public static function display_rows( array $gc ) {
		$rows = array();
		if ( ! empty( $gc['amount'] ) ) {
			$rows[] = array(
				'key'   => __( 'Gift card value', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value' => Kudora_Money::plain( $gc['amount'] ),
			);
		}
		if ( 'other' === ( isset( $gc['send_to'] ) ? $gc['send_to'] : '' ) ) {
			$rows[] = array(
				'key'   => __( 'To', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value' => trim( $gc['recipient_name'] . ' <' . $gc['recipient_email'] . '>' ),
			);
		}
		if ( ! empty( $gc['sender_name'] ) ) {
			$rows[] = array(
				'key'   => __( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value' => $gc['sender_name'],
			);
		}
		if ( ! empty( $gc['message'] ) ) {
			$rows[] = array(
				'key'   => __( 'Message', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value' => $gc['message'],
			);
		}
		if ( ! empty( $gc['deliver_date'] ) ) {
			$rows[] = array(
				'key'   => __( 'Delivery date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value' => date_i18n( get_option( 'date_format' ), strtotime( $gc['deliver_date'] . ' 12:00:00' ) ),
			);
		}
		if ( ! empty( $gc['design_id'] ) ) {
			$design = Kudora_Gift_Card_Designs::get( (int) $gc['design_id'] );
			if ( $design ) {
				$rows[] = array(
					'key'   => __( 'Design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'value' => $design['name'],
				);
			}
		}
		return $rows;
	}

	/**
	 * Copy the data to the order line (hidden meta).
	 *
	 * @param WC_Order_Item_Product $item   Line.
	 * @param string                $key    Cart item key.
	 * @param array                 $values Cart item.
	 * @return void
	 */
	public function order_line( $item, $key, $values ) {
		if ( ! empty( $values['kudora_gc'] ) ) {
			$item->add_meta_data( '_kudora_gc_data', $values['kudora_gc'], true );
		}
	}

	/**
	 * Display the hidden data (and issued codes) on order screens and emails.
	 *
	 * @param array         $meta Formatted meta.
	 * @param WC_Order_Item $item Line.
	 * @return array
	 */
	public function order_line_display( $meta, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $meta;
		}
		$gc = $item->get_meta( '_kudora_gc_data' );
		if ( ! is_array( $gc ) ) {
			return $meta;
		}
		$i = 0;
		foreach ( self::display_rows( $gc ) as $row ) {
			if ( __( 'Gift card value', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) === $row['key'] ) {
				continue;
			}
			$meta[ 'kudora_gc_' . ( $i++ ) ] = (object) array(
				'key'           => $row['key'],
				'value'         => $row['value'],
				'display_key'   => $row['key'],
				'display_value' => wpautop( esc_html( $row['value'] ) ),
			);
		}
		$ids = (array) $item->get_meta( '_kudora_gc_ids' );
		foreach ( array_filter( $ids ) as $card_id ) {
			$card = Kudora_Gift_Card_Repo::get( (int) $card_id );
			if ( $card ) {
				$meta[ 'kudora_gc_code_' . $card_id ] = (object) array(
					'key'           => __( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'value'         => $card['code'],
					'display_key'   => __( 'Gift card code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'display_value' => '<code>' . esc_html( $card['code'] ) . '</code>',
				);
			}
		}
		return $meta;
	}
}
