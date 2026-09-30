<?php
/**
 * Plugin bootstrap: wires the core services and the enabled modules together.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that boots Kudora.
 */
class Kudora_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Kudora_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Kudora_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot. Translations are loaded automatically by WordPress for wp.org plugins.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_wc_missing' ) );
			return;
		}

		add_action( 'init', array( 'Kudora_Install', 'maybe_upgrade' ), 1 );
		add_action( 'wp_initialize_site', array( 'Kudora_Install', 'on_new_site' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );

		( new Kudora_Scheduler() )->register();
		( new Kudora_Order_Lifecycle() )->register();
		( new Kudora_Virtual_Coupons() )->register();
		( new Kudora_Coupon_Types() )->register();

		if ( Kudora_Settings::module( 'discounts' ) ) {
			( new Kudora_Discounts() )->register();
		}
		if ( Kudora_Settings::module( 'gift_cards' ) ) {
			( new Kudora_Gift_Cards() )->register();
		}
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			( new Kudora_Loyalty() )->register();
		}

		( new Kudora_Blocks_Integration() )->register();
		( new Kudora_Emails() )->register();
		( new Kudora_Frontend() )->register();
		( new Kudora_Privacy() )->register();

		if ( is_admin() ) {
			( new Kudora_Admin() )->register();
		}
	}

	/**
	 * Flush rewrite rules once after install (My Account endpoints).
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite() {
		if ( get_option( 'kudora_flush_rewrite' ) ) {
			delete_option( 'kudora_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Admin notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function notice_wc_missing() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Kudora – Coupons, Gift Cards & Loyalty needs WooCommerce to be installed and active.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		echo '</p></div>';
	}
}
