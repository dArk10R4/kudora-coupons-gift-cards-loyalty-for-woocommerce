<?php
/**
 * Installation: custom tables (dbDelta), schema upgrades, first-run defaults and
 * multisite handling.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installer.
 */
class Kudora_Install {

	/**
	 * Table names (without the WordPress prefix).
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'kudora_rules',
		'kudora_gift_cards',
		'kudora_gift_card_ledger',
		'kudora_gift_card_designs',
		'kudora_points',
		'kudora_points_ledger',
		'kudora_referrals',
	);

	/**
	 * Activation hook: install on this site, or on every site for a network activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Deactivation: clear our scheduled actions. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( Kudora_Scheduler::recurring_hooks() as $hook ) {
				as_unschedule_all_actions( $hook, array(), Kudora_Scheduler::GROUP );
			}
		}
	}

	/**
	 * Install a newly created multisite site when the plugin is network-active.
	 *
	 * @param WP_Site $site New site.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( KUDORA_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}

	/**
	 * Run the installer when the stored schema version is behind the code.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'kudora_db_version' ) !== KUDORA_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create/upgrade tables and seed first-run defaults. Idempotent.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );

		$first_run = false === get_option( 'kudora_db_version', false );
		update_option( 'kudora_db_version', KUDORA_DB_VERSION );
		update_option( 'kudora_version', KUDORA_VERSION );

		if ( $first_run ) {
			self::seed_defaults();
		}

		// Rewrite endpoints for My Account tabs are added on init; flush once on the next request.
		update_option( 'kudora_flush_rewrite', 1, true );
		Kudora_Scheduler::ensure_daily_action();
	}

	/**
	 * The CREATE TABLE statements, in dbDelta format.
	 *
	 * @return string
	 */
	public static function schema() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		return "CREATE TABLE {$p}kudora_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  module varchar(20) NOT NULL DEFAULT 'discount',
  type varchar(40) NOT NULL DEFAULT '',
  name varchar(200) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  priority int(11) NOT NULL DEFAULT 10,
  exclusive tinyint(1) NOT NULL DEFAULT 0,
  conditions longtext NULL,
  config longtext NULL,
  starts_at datetime NULL DEFAULT NULL,
  ends_at datetime NULL DEFAULT NULL,
  usage_limit int(11) unsigned NULL DEFAULT NULL,
  usage_count int(11) unsigned NOT NULL DEFAULT 0,
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY module_status (module,status,priority)
) $collate;
CREATE TABLE {$p}kudora_gift_cards (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(64) NOT NULL,
  initial_amount decimal(19,4) NOT NULL DEFAULT 0,
  balance decimal(19,4) NOT NULL DEFAULT 0,
  currency varchar(8) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  expires_on date NULL DEFAULT NULL,
  design_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  purchaser_id bigint(20) unsigned NOT NULL DEFAULT 0,
  purchaser_email varchar(200) NOT NULL DEFAULT '',
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recipient_name varchar(200) NOT NULL DEFAULT '',
  recipient_email varchar(200) NOT NULL DEFAULT '',
  sender_name varchar(200) NOT NULL DEFAULT '',
  message text NULL,
  deliver_at datetime NULL DEFAULT NULL,
  delivered_at datetime NULL DEFAULT NULL,
  is_physical tinyint(1) NOT NULL DEFAULT 0,
  restrictions longtext NULL,
  source varchar(20) NOT NULL DEFAULT 'purchase',
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY order_id (order_id),
  KEY order_item_id (order_item_id),
  KEY owner_id (owner_id),
  KEY purchaser_id (purchaser_id),
  KEY recipient_email (recipient_email(100)),
  KEY status (status)
) $collate;
CREATE TABLE {$p}kudora_gift_card_ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  gift_card_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  amount decimal(19,4) NOT NULL DEFAULT 0,
  balance_after decimal(19,4) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  idempotency_key varchar(120) NULL DEFAULT NULL,
  created_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY gift_card_id (gift_card_id),
  KEY order_id (order_id),
  KEY type_created (type,created_at)
) $collate;
CREATE TABLE {$p}kudora_gift_card_designs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  color varchar(20) NOT NULL DEFAULT '',
  text_color varchar(20) NOT NULL DEFAULT '',
  image_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  status tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id)
) $collate;
CREATE TABLE {$p}kudora_points (
  user_id bigint(20) unsigned NOT NULL,
  balance bigint(20) NOT NULL DEFAULT 0,
  lifetime_earned bigint(20) NOT NULL DEFAULT 0,
  tier_id varchar(40) NOT NULL DEFAULT '',
  referral_code varchar(32) NULL DEFAULT NULL,
  last_activity_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (user_id),
  UNIQUE KEY referral_code (referral_code),
  KEY balance (balance)
) $collate;
CREATE TABLE {$p}kudora_points_ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  points bigint(20) NOT NULL DEFAULT 0,
  remaining bigint(20) NOT NULL DEFAULT 0,
  action varchar(20) NOT NULL,
  entity_type varchar(20) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  expires_at datetime NULL DEFAULT NULL,
  warned tinyint(1) NOT NULL DEFAULT 0,
  idempotency_key varchar(120) NULL DEFAULT NULL,
  lots text NULL,
  created_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY user_created (user_id,created_at),
  KEY lots (user_id,remaining,expires_at),
  KEY entity (entity_type,entity_id),
  KEY action_created (action,created_at)
) $collate;
CREATE TABLE {$p}kudora_referrals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  referrer_id bigint(20) unsigned NOT NULL,
  referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  referred_email varchar(200) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  reason varchar(100) NOT NULL DEFAULT '',
  reward varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY referrer_created (referrer_id,created_at),
  KEY status (status)
) $collate;";
	}

	/**
	 * First-run content: three gift card designs and a Booknetic-style tier ladder.
	 *
	 * @return void
	 */
	protected static function seed_defaults() {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . 'kudora_gift_card_designs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off install check on our own table.
		$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) );
		if ( 0 === $has ) {
			$designs = array(
				array( __( 'Classic', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '#1f2a44', '#ffffff' ),
				array( __( 'Celebration', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '#c2410c', '#ffffff' ),
				array( __( 'Minimal', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '#f4f4f5', '#18181b' ),
			);
			foreach ( $designs as $i => $d ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- inserting into our own table.
				$wpdb->insert(
					$table,
					array(
						'name'       => $d[0],
						'color'      => $d[1],
						'text_color' => $d[2],
						'sort_order' => $i,
						'status'     => 1,
						'created_at' => $now,
					)
				);
			}
		}

		if ( false === get_option( 'kudora_loyalty_tiers', false ) ) {
			update_option(
				'kudora_loyalty_tiers',
				array(
					array( 'id' => 'bronze', 'name' => __( 'Bronze', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'color' => '#b45309', 'min_points' => 0, 'multiplier' => 1 ),
					array( 'id' => 'silver', 'name' => __( 'Silver', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'color' => '#64748b', 'min_points' => 500, 'multiplier' => 1.25 ),
					array( 'id' => 'gold', 'name' => __( 'Gold', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'color' => '#ca8a04', 'min_points' => 1500, 'multiplier' => 1.5 ),
				),
				false
			);
		}
		if ( false === get_option( 'kudora_loyalty_rewards', false ) ) {
			update_option( 'kudora_loyalty_rewards', array(), false );
		}
	}

	/**
	 * Drop every table and option. Only called from uninstall.php when the admin
	 * opted in to data deletion.
	 *
	 * @return void
	 */
	public static function drop_everything() {
		global $wpdb;
		foreach ( self::TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall drops our own tables.
			$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $wpdb->prefix . $table ) );
		}
		// Our options, our email settings, and cached history / counters (transients).
		foreach ( array( 'kudora_', 'woocommerce_kudora_', '_transient_kudora_', '_transient_timeout_kudora_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes our own options.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) );
		}
		// Our meta on products, coupons and orders (both order storages).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes our own post meta.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like( '_kudora_' ) . '%' ) );
		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking for WooCommerce's order meta table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) === $hpos_meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes our own order meta.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $hpos_meta, $wpdb->esc_like( '_kudora_' ) . '%' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes our own order item meta.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->esc_like( '_kudora_' ) . '%' ) );
	}

	/**
	 * Remove our user meta (shared by every site of a network: only once all
	 * sites are uninstalled with "delete data").
	 *
	 * @return void
	 */
	public static function drop_user_meta() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes our own user meta.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( '_kudora_' ) . '%' ) );
	}
}
