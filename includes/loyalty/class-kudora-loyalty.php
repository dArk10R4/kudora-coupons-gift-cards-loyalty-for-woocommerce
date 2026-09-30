<?php
/**
 * Loyalty module facade.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the loyalty services.
 */
class Kudora_Loyalty {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		( new Kudora_Loyalty_Earn() )->register();
		( new Kudora_Loyalty_Redeem() )->register();
		( new Kudora_Rewards() )->register();
		( new Kudora_Referrals() )->register();
		( new Kudora_Loyalty_Frontend() )->register();
		add_action( 'kudora_tier_changed', array( $this, 'tier_changed' ), 10, 3 );
	}

	/**
	 * Email on tier upgrades (not on downgrades).
	 *
	 * @param int        $user_id User id.
	 * @param array|null $old     Old tier.
	 * @param array|null $new     New tier.
	 * @return void
	 */
	public function tier_changed( $user_id, $old, $new ) {
		// Not for landing in the entry tier (minimum 0) with the first points.
		if ( $new && $new['min_points'] > 0 && ( ! $old || $new['min_points'] > $old['min_points'] ) ) {
			Kudora_Emails::send( 'Kudora_Email_Tier_Upgraded', $user_id, $old, $new );
		}
	}
}
