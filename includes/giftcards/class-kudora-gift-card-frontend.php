<?php
/**
 * Customer-facing gift card pages: the My Account "Gift cards" tab (with "save a
 * card to my account"), the public balance check (shortcode, block and REST route,
 * rate-limited) and the printable card / PDF download.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card front end.
 */
class Kudora_Gift_Card_Frontend {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_account_gift-cards_endpoint', array( $this, 'account_tab' ) );
		add_action( 'template_redirect', array( $this, 'handle_save_to_account' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_printable' ), 5 );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_shortcode( 'kudora_gift_card_balance', array( $this, 'balance_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Tamper-proof token for the printable card link.
	 *
	 * @param array $card Card.
	 * @return string
	 */
	public static function token( $card ) {
		return substr( hash_hmac( 'sha256', $card['id'] . '|' . $card['code'], wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Printable card URL.
	 *
	 * @param array  $card   Card.
	 * @param string $format html|pdf.
	 * @return string
	 */
	public static function printable_url( $card, $format = 'html' ) {
		$args = array(
			'kudora-gift-card' => (int) $card['id'],
			'key'              => self::token( $card ),
		);
		if ( 'pdf' === $format ) {
			$args['format'] = 'pdf';
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Serve the printable card or PDF.
	 *
	 * @return void
	 */
	public function maybe_render_printable() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- access is authorised by the HMAC key, not a nonce (link is emailed).
		if ( empty( $_GET['kudora-gift-card'] ) || empty( $_GET['key'] ) ) {
			return;
		}
		$id     = absint( $_GET['kudora-gift-card'] );
		$key    = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$format = isset( $_GET['format'] ) && 'pdf' === $_GET['format'] ? 'pdf' : 'html';
		// phpcs:enable
		$card = Kudora_Gift_Card_Repo::get( $id );
		if ( ! $card || ! hash_equals( self::token( $card ), $key ) ) {
			wp_die( esc_html__( 'This gift card link is not valid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( 'pdf' === $format ) {
			$pdf = Kudora_Gift_Card_PDF::render( $card );
			if ( '' !== $pdf ) {
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="gift-card-' . sanitize_file_name( substr( $card['code'], -4 ) ) . '.pdf"' );
				header( 'Content-Length: ' . strlen( $pdf ) );
				echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
				exit;
			}
		}
		$design = Kudora_Gift_Card_Designs::get( $card['design_id'] );
		wc_get_template(
			'gift-card/printable.php',
			array(
				'card'    => $card,
				'design'  => $design ? $design : Kudora_Gift_Card_Designs::fallback(),
				'qr_svg'  => Kudora_QR::svg( $card['code'] ),
				'pdf_url' => self::printable_url( $card, 'pdf' ),
			),
			'',
			KUDORA_DIR . 'templates/'
		);
		exit;
	}

	/**
	 * My Account → Gift cards.
	 *
	 * @return void
	 */
	public function account_tab() {
		wp_enqueue_style( 'kudora-frontend' );
		wp_enqueue_script( 'kudora-account' );
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$cards   = Kudora_Gift_Card_Repo::for_customer( $user_id );
		wc_get_template(
			'myaccount/gift-cards.php',
			array(
				'cards' => $cards,
				'email' => strtolower( $user->user_email ),
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}

	/**
	 * Whether the current user may see a card's full code.
	 *
	 * @param array  $card    Card.
	 * @param int    $user_id User id.
	 * @param string $email   User email (lowercase).
	 * @return bool
	 */
	public static function can_see_code( $card, $user_id, $email ) {
		if ( (int) $card['owner_id'] === (int) $user_id ) {
			return true;
		}
		if ( '' !== $email && $card['recipient_email'] === $email ) {
			return true;
		}
		// Purchasers keep seeing codes they bought for themselves or physical cards.
		return (int) $card['purchaser_id'] === (int) $user_id && ( $card['is_physical'] || '' === $card['recipient_email'] || strtolower( $card['purchaser_email'] ) === $card['recipient_email'] );
	}

	/**
	 * "Save a gift card to my account".
	 *
	 * @return void
	 */
	public function handle_save_to_account() {
		if ( ! isset( $_POST['kudora_save_gift_card'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['_kudora_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_kudora_nonce'] ) ), 'kudora-save-gift-card' ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			return;
		}
		$code = isset( $_POST['kudora_code'] ) ? sanitize_text_field( wp_unslash( $_POST['kudora_code'] ) ) : '';
		if ( ! Kudora_Gift_Card_Redeem::rate_limit_ok() ) {
			wc_add_notice( __( 'Too many attempts. Please wait a few minutes and try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			return;
		}
		$card = Kudora_Gift_Card_Repo::get_by_code( $code, true );
		if ( ! $card || 'disabled' === $card['status'] ) {
			Kudora_Gift_Card_Redeem::count_failure();
			wc_add_notice( __( 'This gift card code is not valid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			return;
		}
		$user_id = get_current_user_id();
		if ( $card['owner_id'] && $card['owner_id'] !== $user_id ) {
			wc_add_notice( __( 'This gift card is already saved to another account.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			return;
		}
		Kudora_Gift_Card_Repo::update( $card['id'], array( 'owner_id' => $user_id ) );
		wc_add_notice( __( 'The gift card was saved to your account.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( wc_get_account_endpoint_url( 'gift-cards' ) );
		exit;
	}

	/**
	 * REST: public balance check.
	 *
	 * @return void
	 */
	public function rest_routes() {
		register_rest_route(
			'kudora/v1',
			'/gift-cards/balance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_balance' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Balance lookup (rate-limited per client to stop code guessing).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_balance( $request ) {
		if ( ! Kudora_Gift_Card_Redeem::rate_limit_ok() ) {
			return new WP_Error( 'kudora_rate', __( 'Too many attempts. Please wait a few minutes and try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), array( 'status' => 429 ) );
		}
		$card = Kudora_Gift_Card_Repo::get_by_code( (string) $request['code'], true );
		if ( ! $card || 'disabled' === $card['status'] ) {
			Kudora_Gift_Card_Redeem::count_failure();
			return new WP_Error( 'kudora_not_found', __( 'This gift card code is not valid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$expired = Kudora_Gift_Card_Repo::is_expired( $card ) || 'expired' === $card['status'];
		return rest_ensure_response(
			array(
				'code'         => Kudora_Gift_Card_Repo::mask( $card['code'] ),
				'balance'      => $card['balance'],
				'balance_html' => wc_price( $card['balance'] ),
				'expires_on'   => $card['expires_on'] ? date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) : '',
				'expired'      => $expired,
				'message'      => $expired
					? __( 'This gift card has expired.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' )
					/* translators: %s: balance */
					: sprintf( __( 'Balance: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $card['balance'] ) ),
			)
		);
	}

	/**
	 * [kudora_gift_card_balance] shortcode.
	 *
	 * @return string
	 */
	public function balance_shortcode() {
		wp_enqueue_style( 'kudora-frontend' );
		wp_enqueue_script( 'kudora-account' );
		wp_localize_script(
			'kudora-account',
			'kudoraBalance',
			array(
				'url'   => esc_url_raw( rest_url( 'kudora/v1/gift-cards/balance' ) ),
				'error' => __( 'Something went wrong. Please try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
		return wc_get_template_html( 'gift-card/balance-check.php', array( 'uid' => wp_unique_id( 'kudora-balance-' ) ), '', KUDORA_DIR . 'templates/' );
	}

	/**
	 * Server-rendered block "Gift card balance".
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			KUDORA_DIR . 'blocks/gift-card-balance',
			array(
				'render_callback' => array( $this, 'balance_shortcode' ),
			)
		);
	}
}
