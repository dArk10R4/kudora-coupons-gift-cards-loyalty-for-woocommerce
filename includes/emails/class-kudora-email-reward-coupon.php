<?php
/**
 * "Your reward coupon".
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reward coupon email.
 */
class Kudora_Email_Reward_Coupon extends Kudora_Email_Loyalty_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id           = 'kudora_reward_coupon';
		$this->title        = __( 'Reward claimed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description  = __( 'Sends the coupon code when a customer claims a reward with points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->placeholders = array( '{reward}' => '' );
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your reward: {reward}', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Enjoy your reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int $user_id   User id.
	 * @param int $coupon_id Coupon id.
	 * @return bool
	 */
	public function trigger( $user_id, $coupon_id ) {
		$coupon = new WC_Coupon( $coupon_id );
		$reward = Kudora_Rewards::get( (string) $coupon->get_meta( '_kudora_reward' ) );
		$this->placeholders['{reward}'] = $reward ? $reward['name'] : strtoupper( $coupon->get_code() );
		$exp                            = $coupon->get_date_expires();
		$lines                          = array( esc_html__( 'Use this code at checkout:', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		if ( $exp ) {
			/* translators: %s: date */
			$lines[] = sprintf( esc_html__( 'Valid until %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( wc_format_datetime( $exp ) ) );
		}
		return $this->send_to_user( $user_id, $lines, strtoupper( $coupon->get_code() ) );
	}
}
