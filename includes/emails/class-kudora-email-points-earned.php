<?php
/**
 * "You earned points" (off by default).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points earned email.
 */
class Kudora_Email_Points_Earned extends Kudora_Email_Loyalty_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id           = 'kudora_points_earned';
		$this->title        = __( 'Points earned', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description  = __( 'Sent to customers when they earn points for an order. Off by default.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->placeholders = array( '{points}' => '' );
		parent::__construct();
	}

	/**
	 * Off by default.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['enabled']['default'] = 'no';
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'You earned {points} at {site_title}', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thanks for your order!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int $user_id  User id.
	 * @param int $points   Points.
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function trigger( $user_id, $points, $order_id = 0 ) {
		$this->placeholders['{points}'] = Kudora_Loyalty_Redeem::format_points( $points );
		$order                          = $order_id ? wc_get_order( $order_id ) : null;
		return $this->send_to_user(
			$user_id,
			array(
				/* translators: 1: points, 2: order number */
				$order ? sprintf( esc_html__( 'You earned %1$s for order #%2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( Kudora_Loyalty_Redeem::format_points( $points ) ) . '</strong>', esc_html( $order->get_order_number() ) ) : '',
				/* translators: %s: balance */
				sprintf( esc_html__( 'Your balance is now %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_html( Kudora_Loyalty_Redeem::format_points( Kudora_Points_Repo::balance( $user_id ) ) ) ),
			)
		);
	}
}
