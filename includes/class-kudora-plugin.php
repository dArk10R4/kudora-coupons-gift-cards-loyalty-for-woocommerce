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
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, KUDORA_MIN_WC, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_wc_old' ) );
			return;
		}

		add_action( 'init', array( 'Kudora_Install', 'maybe_upgrade' ), 1 );
		add_action( 'wp_initialize_site', array( 'Kudora_Install', 'on_new_site' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );

		( new Kudora_Scheduler() )->register();
		( new Kudora_Order_Lifecycle() )->register();
		( new Kudora_Virtual_Coupons() )->register();
		( new Kudora_Coupon_Types() )->register();
		( new Kudora_Importer() )->register();
		( new Kudora_Rate_Limit() )->register();
		( new Kudora_Customer_History() )->register();

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
		// Kept as an autoloaded 0/1 flag (never deleted), so checking it costs no query.
		if ( get_option( 'kudora_flush_rewrite' ) ) {
			update_option( 'kudora_flush_rewrite', 0, true );
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

	/**
	 * Admin notice: WooCommerce is older than supported.
	 *
	 * @return void
	 */
	public function notice_wc_old() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		/* translators: %s: required WooCommerce version */
		echo esc_html( sprintf( __( 'Kudora – Coupons, Gift Cards & Loyalty needs WooCommerce %s or newer. Please update WooCommerce; until then Kudora stays inactive.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), KUDORA_MIN_WC ) );
		echo '</p></div>';
	}
}
