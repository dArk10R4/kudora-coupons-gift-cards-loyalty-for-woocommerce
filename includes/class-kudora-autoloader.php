<?php
/**
 * Class autoloader: maps `Kudora_Foo_Bar` to `class-kudora-foo-bar.php` in one of
 * the plugin's include folders.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-0-style autoloader for the plugin's own classes.
 */
class Kudora_Autoloader {

	/**
	 * Folders (relative to includes/) searched, in order.
	 *
	 * @var string[]
	 */
	const DIRS = array( '', 'core/', 'discounts/', 'giftcards/', 'loyalty/', 'emails/', 'admin/' );

	/**
	 * Register with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file if the class belongs to this plugin.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Kudora_' ) ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		foreach ( self::DIRS as $dir ) {
			$path = KUDORA_DIR . 'includes/' . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
