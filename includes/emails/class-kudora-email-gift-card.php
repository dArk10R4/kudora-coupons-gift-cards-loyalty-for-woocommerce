<?php
/**
 * "You received a gift card" — sent to the recipient (or the buyer for themselves).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card email.
 */
class Kudora_Email_Gift_Card extends Kudora_Email_Base {

	/**
	 * Card being sent.
	 *
	 * @var array|null
	 */
	protected $card = null;

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'kudora_gift_card';
		$this->title          = __( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->description    = __( 'Sent to the gift card recipient when the purchase is paid, or on the chosen delivery date. Includes the code and an optional PDF.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		$this->template_html  = 'emails/kudora-gift-card.php';
		$this->template_plain = 'emails/plain/kudora-gift-card.php';
		$this->placeholders   = array(
			'{amount}'      => '',
			'{sender_name}' => '',
			'{site_title}'  => $this->get_blogname(),
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'You received a {amount} gift card from {sender_name}', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'A gift card for you', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Enjoy your gift!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
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
		$this->card                          = $card;
		$this->placeholders['{amount}']      = Kudora_Money::plain( $card['initial_amount'] );
		$this->placeholders['{sender_name}'] = '' !== $card['sender_name'] ? $card['sender_name'] : $this->get_blogname();
		$design                              = Kudora_Gift_Card_Designs::get( $card['design_id'] );
		return $this->send_to(
			$card['recipient_email'],
			array(
				'card'      => $card,
				'design'    => $design ? $design : Kudora_Gift_Card_Designs::fallback(),
				'print_url' => Kudora_Gift_Card_Frontend::printable_url( $card ),
				'shop_url'  => wc_get_page_permalink( 'shop' ),
			)
		);
	}

	/**
	 * Attach the PDF (setting).
	 *
	 * @return array
	 */
	public function get_attachments() {
		$attachments = parent::get_attachments();
		if ( $this->card && Kudora_Settings::yes( 'gc_pdf' ) ) {
			$path = Kudora_Gift_Card_PDF::temp_file( $this->card );
			if ( $path ) {
				$attachments[] = $path;
			}
		}
		return $attachments;
	}
}
