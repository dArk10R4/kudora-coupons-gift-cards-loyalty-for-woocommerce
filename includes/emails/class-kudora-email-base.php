<?php
/**
 * Shared base for Kudora emails: template lookup in the plugin, common args.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base email.
 */
abstract class Kudora_Email_Base extends WC_Email {

	/**
	 * Extra template args set by trigger().
	 *
	 * @var array
	 */
	protected $args = array();

	/**
	 * Common setup.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = KUDORA_DIR . 'templates/';
		$this->email_group    = 'kudora';
		parent::__construct();
	}

	/**
	 * Send to a recipient with our args.
	 *
	 * @param string $to   Recipient.
	 * @param array  $args Template args.
	 * @return bool
	 */
	protected function send_to( $to, array $args ) {
		if ( ! $this->is_enabled() || ! is_email( $to ) ) {
			return false;
		}
		$this->recipient = $to;
		$this->args      = $args;
		$this->setup_locale();
		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();
		return (bool) $sent;
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array_merge(
				array(
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				),
				$this->args
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array_merge(
				array(
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				),
				$this->args
			),
			'',
			$this->template_base
		);
	}
}
