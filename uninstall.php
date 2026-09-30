<?php
/**
 * Uninstall: data is kept unless "Delete data on uninstall" was switched on in
 * WooCommerce → Settings → Kudora (gift card balances are customer money).
 *
 * @package Kudora
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-kudora-autoloader.php';
if ( ! defined( 'KUDORA_DIR' ) ) {
	define( 'KUDORA_DIR', plugin_dir_path( __FILE__ ) );
}
Kudora_Autoloader::register();

/**
 * Uninstall one site.
 *
 * @return void
 */
function kudora_uninstall_site() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'kudora' );
	}
	wp_clear_scheduled_hook( 'kudora_daily' );
	if ( 'yes' !== get_option( 'kudora_delete_data_on_uninstall', 'no' ) ) {
		return;
	}
	Kudora_Install::drop_everything();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $kudora_site_id ) {
		switch_to_blog( $kudora_site_id );
		kudora_uninstall_site();
		restore_current_blog();
	}
} else {
	kudora_uninstall_site();
}
