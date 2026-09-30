<?php
/**
 * Cart & Checkout blocks / Store API integration.
 *
 * - Exposes Kudora state on the Store API cart under `extensions.kudora`.
 * - One `/cart/extensions` update callback (namespace `kudora`) whose `action` is
 *   dispatched to the modules through the `kudora_store_api_action` filter.
 * - Registers the front-end script that renders the gift card field, points
 *   redemption, earn preview and promotion notices in the block slots.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks integration.
 */
class Kudora_Blocks_Integration {

	/**
	 * Store API namespace.
	 */
	const NS = 'kudora';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		// WooCommerce fires woocommerce_blocks_loaded on plugins_loaded, possibly before us.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'store_api' ) );
		}
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
	}

	/**
	 * Register Store API data + update callback.
	 *
	 * @return void
	 */
	public function store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::NS,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NS,
				'callback'  => array( $this, 'update' ),
			)
		);
	}

	/**
	 * Data under extensions.kudora on the cart.
	 *
	 * @return array
	 */
	public function cart_data() {
		/**
		 * Build the cart extension payload. Modules add their own keys.
		 *
		 * @param array $data Data.
		 */
		$data = apply_filters( 'kudora_store_api_cart_data', array( 'labels' => array() ) );
		// Friendly labels for Kudora's virtual coupons.
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_applied_coupons() as $code ) {
				if ( Kudora_Virtual_Coupons::is_ours( $code ) ) {
					$data['labels'][ wc_format_coupon_code( $code ) ] = Kudora_Virtual_Coupons::label_for( $code );
				}
			}
		}
		return $data;
	}

	/**
	 * Loose schema (object of module payloads).
	 *
	 * @return array
	 */
	public function cart_schema() {
		return array(
			'labels'     => array(
				'description' => __( 'Labels of Kudora discounts, keyed by coupon code.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'gift_cards' => array(
				'description' => __( 'Applied gift cards.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'points'     => array(
				'description' => __( 'Loyalty points state.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'promos'     => array(
				'description' => __( 'Promotion notices.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * `/cart/extensions` callback: dispatch `action`.
	 *
	 * @param array $data Posted data.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException On a module error.
	 */
	public function update( $data ) {
		$action = isset( $data['action'] ) ? sanitize_key( $data['action'] ) : '';
		/**
		 * Handle a Kudora Store API action. Return true when handled, a WP_Error on failure.
		 *
		 * @param bool|WP_Error $result Default false (unhandled).
		 * @param string        $action Action.
		 * @param array         $data   Posted data.
		 */
		$result = apply_filters( 'kudora_store_api_action', false, $action, is_array( $data ) ? $data : array() );
		if ( is_wp_error( $result ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $result->get_error_code() ), esc_html( $result->get_error_message() ), 400 );
		}
		if ( false === $result ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kudora_unknown_action', esc_html__( 'Unknown action.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 400 );
		}
	}

	/**
	 * Register the block integration (script).
	 *
	 * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry Registry.
	 * @return void
	 */
	public function register_integration( $registry ) {
		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			return;
		}
		require_once KUDORA_DIR . 'includes/core/class-kudora-blocks-script.php';
		if ( ! $registry->is_registered( 'kudora' ) ) {
			$registry->register( new Kudora_Blocks_Script() );
		}
	}
}
