<?php
/**
 * Base for the loyalty emails: one shared template with a few paragraphs, an
 * optional highlighted box and a call-to-action link to My Account.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loyalty email base.
 */
abstract class Kudora_Email_Loyalty_Base extends Kudora_Email_Base {

	/**
	 * Shared setup.
	 */
	public function __construct() {
		$this->template_html  = 'emails/kudora-loyalty.php';
		$this->template_plain = 'emails/plain/kudora-loyalty.php';
		if ( ! isset( $this->placeholders['{site_title}'] ) ) {
			$this->placeholders['{site_title}'] = $this->get_blogname();
		}
		parent::__construct();
	}

	/**
	 * Send to a customer.
	 *
	 * @param int    $user_id   User id.
	 * @param array  $paragraphs Paragraphs (may contain inline HTML).
	 * @param string $highlight  Highlighted text.
	 * @return bool
	 */
	protected function send_to_user( $user_id, array $paragraphs, $highlight = '' ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$this->placeholders['{customer_name}'] = $user->first_name ? $user->first_name : $user->display_name;
		return $this->send_to(
			$user->user_email,
			array(
				'user'       => $user,
				'paragraphs' => $paragraphs,
				'highlight'  => $highlight,
				'cta_url'    => wc_get_account_endpoint_url( 'points-rewards' ),
				'cta_label'  => __( 'View your points and rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
	}
}
