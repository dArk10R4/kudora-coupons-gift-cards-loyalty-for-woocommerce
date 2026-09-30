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
 * @return bool Whether its data was deleted.
 */
function kudora_uninstall_site() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'kudora' );
	}
	wp_clear_scheduled_hook( 'kudora_daily' );
	if ( 'yes' !== get_option( 'kudora_delete_data_on_uninstall', 'no' ) ) {
		return false;
	}
	Kudora_Install::drop_everything();
	return true;
}

if ( is_multisite() ) {
	// User meta is shared by the whole network: remove it only if every site
	// asked for its data to be deleted.
	$kudora_all = true;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $kudora_site_id ) {
		switch_to_blog( $kudora_site_id );
		$kudora_all = kudora_uninstall_site() && $kudora_all;
		restore_current_blog();
	}
	if ( $kudora_all ) {
		Kudora_Install::drop_user_meta();
	}
} elseif ( kudora_uninstall_site() ) {
	Kudora_Install::drop_user_meta();
}
