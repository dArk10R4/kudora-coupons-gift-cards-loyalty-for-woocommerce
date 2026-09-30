<?php
/**
 * Registers Kudora's WooCommerce emails (they appear under WooCommerce → Settings →
 * Emails, where subject/heading/content and on/off are editable, and templates can
 * be overridden in the theme at woocommerce/emails/).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email registry.
 */
class Kudora_Emails {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_email_classes', array( $this, 'classes' ) );
		add_filter( 'woocommerce_email_groups', array( $this, 'group' ) );
	}

	/**
	 * Group label in the email settings list.
	 *
	 * @param array $groups Groups.
	 * @return array
	 */
	public function group( $groups ) {
		$groups['kudora'] = __( 'Gift cards & loyalty', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $groups;
	}

	/**
	 * Add our email classes.
	 *
	 * @param array $emails Emails.
	 * @return array
	 */
	public function classes( $emails ) {
		if ( Kudora_Settings::module( 'gift_cards' ) ) {
			$emails['Kudora_Email_Gift_Card']      = new Kudora_Email_Gift_Card();
			$emails['Kudora_Email_Gift_Card_Sent'] = new Kudora_Email_Gift_Card_Sent();
		}
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			$emails['Kudora_Email_Points_Earned']   = new Kudora_Email_Points_Earned();
			$emails['Kudora_Email_Tier_Upgraded']   = new Kudora_Email_Tier_Upgraded();
			$emails['Kudora_Email_Points_Expiring'] = new Kudora_Email_Points_Expiring();
			$emails['Kudora_Email_Reward_Coupon']   = new Kudora_Email_Reward_Coupon();
			$emails['Kudora_Email_Referral_Reward'] = new Kudora_Email_Referral_Reward();
		}
		return $emails;
	}

	/**
	 * Send one of our emails by class name.
	 *
	 * @param string $class_name Email class.
	 * @param mixed  ...$args    Trigger args.
	 * @return bool
	 */
	public static function send( $class_name, ...$args ) {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		if ( ! isset( $emails[ $class_name ] ) ) {
			return false;
		}
		return (bool) call_user_func_array( array( $emails[ $class_name ], 'trigger' ), $args );
	}
}
