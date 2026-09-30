<?php
/**
 * Smart Coupons & Discount Rules module facade.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the discount services.
 */
class Kudora_Discounts {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		( new Kudora_Discount_Engine() )->register();
		( new Kudora_Catalog_Prices() )->register();
		( new Kudora_Smart_Coupons() )->register();
	}
}
