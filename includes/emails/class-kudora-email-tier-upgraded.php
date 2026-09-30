<?php
/**
 * "You reached a new tier" (Booknetic `bkntc_loyalty_tier_upgraded`).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tier upgraded email.
 */
class Kudora_Email_Tier_Upgraded extends Kudora_Email_Loyalty_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id           = 'kudora_tier_upgraded';
		$this->title        = __( 'Loyalty tier upgraded', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description  = __( 'Sent to customers when they reach a higher loyalty tier.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->placeholders = array(
			'{new_tier}' => '',
			'{old_tier}' => '',
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Welcome to {new_tier}!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'You reached {new_tier}', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int        $user_id User id.
	 * @param array|null $old     Old tier.
	 * @param array      $new     New tier.
	 * @return bool
	 */
	public function trigger( $user_id, $old, $new ) {
		$this->placeholders['{new_tier}'] = $new['name'];
		$this->placeholders['{old_tier}'] = $old ? $old['name'] : '';
		$lines                            = array(
			/* translators: %s: tier name */
			sprintf( esc_html__( 'Congratulations — you are now a %s member.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( $new['name'] ) . '</strong>' ),
		);
		if ( $new['multiplier'] > 1 ) {
			/* translators: %s: multiplier like 1.5 */
			$lines[] = sprintf( esc_html__( 'From now on you earn %s× points on every order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( wc_format_localized_decimal( $new['multiplier'] ) ) );
		}
		return $this->send_to_user( $user_id, $lines, $new['name'] );
	}
}
