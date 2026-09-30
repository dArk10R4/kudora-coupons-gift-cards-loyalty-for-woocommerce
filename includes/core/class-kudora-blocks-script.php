<?php
/**
 * Block integration that enqueues Kudora's cart/checkout script. Loaded only when
 * WooCommerce's IntegrationInterface exists.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart/Checkout block integration.
 */
class Kudora_Blocks_Script implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'kudora';
	}

	/**
	 * Register the script and style.
	 *
	 * @return void
	 */
	public function initialize() {
		wp_register_script(
			'kudora-blocks-checkout',
			KUDORA_URL . 'assets/js/blocks-checkout.js',
			array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-data', 'wp-i18n', 'wp-html-entities' ),
			KUDORA_VERSION,
			true
		);
		wp_set_script_translations( 'kudora-blocks-checkout', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		wp_register_style( 'kudora-frontend', KUDORA_URL . 'assets/css/frontend.css', array(), KUDORA_VERSION );
		wp_enqueue_style( 'kudora-frontend' );
	}

	/**
	 * Front-end script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'kudora-blocks-checkout' );
	}

	/**
	 * Editor script handles.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data available as `wc.wcSettings.getSetting( 'kudora_data' )`.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'giftCards' => Kudora_Settings::module( 'gift_cards' ),
			'loyalty'   => Kudora_Settings::module( 'loyalty' ) && Kudora_Settings::yes( 'loyalty_redeem_enabled' ),
			'loggedIn'  => is_user_logged_in(),
		);
	}
}
