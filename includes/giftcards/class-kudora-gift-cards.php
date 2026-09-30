<?php
/**
 * Gift Cards module facade.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the gift card services.
 */
class Kudora_Gift_Cards {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		( new Kudora_Gift_Card_Redeem() )->register();
		( new Kudora_Gift_Card_Product() )->register();
		( new Kudora_Gift_Card_Issuer() )->register();
		( new Kudora_Gift_Card_Frontend() )->register();
	}
}
