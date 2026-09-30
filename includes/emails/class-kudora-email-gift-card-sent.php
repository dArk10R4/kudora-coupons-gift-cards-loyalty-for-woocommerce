<?php
/**
 * "Your gift card was delivered" — tells the buyer that the card reached the recipient.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card delivered (to purchaser).
 */
class Kudora_Email_Gift_Card_Sent extends Kudora_Email_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'kudora_gift_card_sent';
		$this->title          = __( 'Gift card delivered', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description    = __( 'Sent to the buyer when a gift card they bought for someone else has been delivered.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->template_html  = 'emails/kudora-gift-card-sent.php';
		$this->template_plain = 'emails/plain/kudora-gift-card-sent.php';
		$this->placeholders   = array(
			'{recipient_name}' => '',
			'{site_title}'     => $this->get_blogname(),
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your gift card for {recipient_name} was delivered', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your gift is on its way', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param int $card_id Card id.
	 * @return bool
	 */
	public function trigger( $card_id ) {
		$card = Kudora_Gift_Card_Repo::get( (int) $card_id );
		if ( ! $card ) {
			return false;
		}
		$this->placeholders['{recipient_name}'] = '' !== $card['recipient_name'] ? $card['recipient_name'] : $card['recipient_email'];
		return $this->send_to( $card['purchaser_email'], array( 'card' => $card ) );
	}
}
