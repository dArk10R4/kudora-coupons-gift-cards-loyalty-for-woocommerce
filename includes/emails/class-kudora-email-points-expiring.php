<?php
/**
 * "Your points expire soon".
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points expiring email.
 */
class Kudora_Email_Points_Expiring extends Kudora_Email_Loyalty_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id           = 'kudora_points_expiring';
		$this->title        = __( 'Points expiring soon', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description  = __( 'Reminds customers before their points expire (when points expiry is enabled).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->placeholders = array( '{points}' => '' );
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '{points} are about to expire', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Use your points before they expire', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int    $user_id User id.
	 * @param int    $points  Points.
	 * @param string $date    UTC datetime.
	 * @return bool
	 */
	public function trigger( $user_id, $points, $date ) {
		$this->placeholders['{points}'] = Kudora_Loyalty_Redeem::format_points( $points );
		return $this->send_to_user(
			$user_id,
			array(
				/* translators: 1: points, 2: date */
				sprintf( esc_html__( '%1$s will expire on %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( Kudora_Loyalty_Redeem::format_points( $points ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date . ' UTC' ) ) ) ),
				esc_html__( 'Use them on your next order or claim a reward before then.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
	}
}
