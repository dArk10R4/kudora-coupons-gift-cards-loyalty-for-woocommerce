<?php
/**
 * Plugin Name:          Kudora – Coupons, Gift Cards & Loyalty for WooCommerce
 * Plugin URI:           https://github.com/dArk10R4/kudora-coupons-gift-cards-loyalty-for-woocommerce
 * Description:          Smart coupons and discount rules (BOGO, tiered pricing, auto-apply, URL coupons), gift cards (custom amounts, scheduled delivery, PDF) and a loyalty program (points, tiers, rewards, referrals) for WooCommerce. Every feature is free.
 * Version:              1.0.0
 * Requires at least:    6.7
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 * Author:               nihad1122
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          kudora-coupons-gift-cards-loyalty-for-woocommerce
 *
 * Kudora is free software: you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, version 2 or later, as published by the Free
 * Software Foundation.
 *
 * This plugin is independent and is NOT affiliated with, endorsed by, or sponsored by
 * WooCommerce or Automattic. "WooCommerce" is used only to describe compatibility.
 * Bundled libraries: tFPDF (LGPL), DejaVu fonts (Bitstream Vera / DejaVu license) and
 * the QR Code generator by Kazuhiko Arase (MIT). See includes/vendor/.
 *
 * @package Kudora
 */

// Block direct access — reviewers open plugin files in a browser.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KUDORA_VERSION', '1.0.0' );
define( 'KUDORA_DB_VERSION', '1' );
define( 'KUDORA_FILE', __FILE__ );
define( 'KUDORA_DIR', plugin_dir_path( __FILE__ ) );
define( 'KUDORA_URL', plugin_dir_url( __FILE__ ) );

require_once KUDORA_DIR . 'includes/class-kudora-autoloader.php';
Kudora_Autoloader::register();

/**
 * Declare compatibility with WooCommerce's custom order tables (HPOS) and the
 * Cart and Checkout blocks. Must run on `before_woocommerce_init`.
 */
function kudora_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'kudora_declare_wc_compatibility' );

/**
 * Boot the plugin once all plugins are loaded, so WooCommerce is known.
 */
function kudora_bootstrap() {
	Kudora_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'kudora_bootstrap', 20 );

register_activation_hook( __FILE__, array( 'Kudora_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kudora_Install', 'deactivate' ) );
