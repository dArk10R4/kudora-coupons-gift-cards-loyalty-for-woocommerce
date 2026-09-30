<?php
/**
 * "A friend you referred placed an order".
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Referral reward email.
 */
class Kudora_Email_Referral_Reward extends Kudora_Email_Loyalty_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id          = 'kudora_referral_reward';
		$this->title       = __( 'Referral reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description = __( 'Sent to the referrer when a referred friend’s first order earns them a reward.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your friend shopped at {site_title} — here is your reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thanks for spreading the word', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int    $user_id  Referrer.
	 * @param int    $order_id Referred order.
	 * @param string $label    Points number or coupon code.
	 * @return bool
	 */
	public function trigger( $user_id, $order_id, $label ) {
		if ( 'coupon' === Kudora_Settings::get( 'referral_reward_type' ) ) {
			return $this->send_to_user( $user_id, array( esc_html__( 'A friend you referred placed their first order. Here is your coupon:', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), strtoupper( $label ) );
		}
		return $this->send_to_user(
			$user_id,
			array(
				/* translators: %s: points */
				sprintf( esc_html__( 'A friend you referred placed their first order, so you earned %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( Kudora_Loyalty_Redeem::format_points( (int) $label ) ) . '</strong>' ),
			)
		);
	}
}
