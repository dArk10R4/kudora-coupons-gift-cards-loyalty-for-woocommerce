<?php
/**
 * Customer-facing loyalty UI: My Account "Points & rewards" (Booknetic loyalty card
 * with tier progress, history, rewards, referral link), "earn X points" messages,
 * the [kudora_points] / [kudora_referral_link] shortcodes and the loyalty card block.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loyalty front end.
 */
class Kudora_Loyalty_Frontend {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_account_points-rewards_endpoint', array( $this, 'account_tab' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_message' ), 11 );
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( $this, 'product_message_block' ), 10, 2 );
		add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( $this, 'product_message_block' ), 20, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_earn_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'cart_earn_row' ) );
		add_shortcode( 'kudora_points', array( $this, 'points_shortcode' ) );
		add_shortcode( 'kudora_referral_link', array( $this, 'referral_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Data for the loyalty card (Booknetic customer panel card).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function card_data( $user_id ) {
		$row  = Kudora_Points_Repo::row( $user_id );
		$tier = Kudora_Points_Repo::tier_of( $user_id );
		$next = Kudora_Points_Repo::next_tier( $user_id );
		$from = $tier ? $tier['min_points'] : 0;
		$to   = $next['tier'] ? $next['tier']['min_points'] : 0;
		$pct  = $next['tier'] ? ( $to > $from ? min( 100, max( 0, ( $next['current_points'] - $from ) / ( $to - $from ) * 100 ) ) : 0 ) : 100;
		return array(
			'balance'     => $row['balance'],
			'lifetime'    => $row['lifetime_earned'],
			'tier'        => $tier,
			'next'        => $next,
			'progress'    => round( $pct, 1 ),
			'point_value' => Kudora_Loyalty_Redeem::settings()['value'],
			'expiry'      => Kudora_Points_Repo::next_expiry( $user_id ),
		);
	}

	/**
	 * My Account tab.
	 *
	 * @return void
	 */
	public function account_tab() {
		wp_enqueue_style( 'kudora-frontend' );
		wp_enqueue_script( 'kudora-account' );
		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination only.
		$page = isset( $_GET['kpage'] ) ? max( 1, absint( $_GET['kpage'] ) ) : 1;
		$logs = Kudora_Points_Repo::logs(
			array(
				'customer' => $user_id,
				'per_page' => 15,
				'page'     => $page,
			)
		);
		$rewards = array();
		foreach ( Kudora_Rewards::all( true ) as $r ) {
			$r['why_not'] = Kudora_Rewards::why_not( $r, $user_id );
			$rewards[]    = $r;
		}
		wc_get_template(
			'myaccount/points-rewards.php',
			array(
				'card'      => self::card_data( $user_id ),
				'logs'      => $logs,
				'page'      => $page,
				'rewards'   => $rewards,
				'coupons'   => Kudora_Rewards::coupons_of( $user_id ),
				'referral'  => Kudora_Settings::yes( 'referral_enabled' ) ? array(
					'link'  => Kudora_Referrals::link_for( $user_id ),
					'stats' => Kudora_Referrals::stats_for( $user_id ),
				) : null,
				'labels'    => Kudora_Points_Repo::action_labels(),
				'birthday'  => Kudora_Settings::int( 'loyalty_birthday_points' ) > 0 && '' === (string) get_user_meta( $user_id, '_kudora_birthday', true ),
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}

	/**
	 * "Earn X points" under the price (classic templates).
	 *
	 * @return void
	 */
	public function product_message() {
		global $product;
		echo wp_kses_post( self::product_message_html( $product ) );
	}

	/**
	 * Same message appended to the add-to-cart block on block themes.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function product_message_block( $content, $block ) {
		if ( ! is_product() || did_action( 'woocommerce_single_product_summary' ) ) {
			return $content;
		}
		$product = wc_get_product( get_the_ID() );
		return self::product_message_html( $product ) . $content;
	}

	/**
	 * Message HTML for a product.
	 *
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	public static function product_message_html( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$points = Kudora_Loyalty_Earn::estimate_for_product( $product );
		if ( $points <= 0 ) {
			return '';
		}
		wp_enqueue_style( 'kudora-frontend' );
		if ( is_user_logged_in() ) {
			/* translators: %s: points */
			$text = sprintf( __( 'Earn %s with this purchase.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( Kudora_Loyalty_Redeem::format_points( $points ) ) . '</strong>' );
		} else {
			/* translators: 1: points, 2: account URL */
			$text = sprintf( __( 'Earn %1$s with this purchase — <a href="%2$s">sign in or create an account</a>.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( Kudora_Loyalty_Redeem::format_points( $points ) ) . '</strong>', esc_url( wc_get_page_permalink( 'myaccount' ) ) );
		}
		return '<p class="kudora-earn-message">' . $text . '</p>';
	}

	/**
	 * "You will earn X points" row in classic cart/checkout totals.
	 *
	 * @return void
	 */
	public function cart_earn_row() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$points = Kudora_Loyalty_Earn::estimate_for_cart();
		if ( $points <= 0 ) {
			return;
		}
		printf(
			'<tr class="kudora-earn-row"><th>%1$s</th><td data-title="%1$s">%2$s</td></tr>',
			esc_html( Kudora_Frontend::title_for( 'points' ) ),
			/* translators: %s: points */
			esc_html( sprintf( __( 'You will earn %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $points ) ) )
		);
	}

	/**
	 * [kudora_points] — the loyalty card.
	 *
	 * @return string
	 */
	public function points_shortcode() {
		wp_enqueue_style( 'kudora-frontend' );
		if ( ! is_user_logged_in() ) {
			/* translators: %s: account URL */
			return '<p class="kudora-points-login">' . wp_kses_post( sprintf( __( '<a href="%s">Sign in</a> to see your points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_url( wc_get_page_permalink( 'myaccount' ) ) ) ) . '</p>';
		}
		return wc_get_template_html( 'loyalty/card.php', array( 'card' => self::card_data( get_current_user_id() ) ), '', KUDORA_DIR . 'templates/' );
	}

	/**
	 * [kudora_referral_link].
	 *
	 * @return string
	 */
	public function referral_shortcode() {
		if ( ! is_user_logged_in() || ! Kudora_Settings::yes( 'referral_enabled' ) ) {
			return '';
		}
		wp_enqueue_style( 'kudora-frontend' );
		wp_enqueue_script( 'kudora-account' );
		return wc_get_template_html( 'loyalty/referral.php', array( 'link' => Kudora_Referrals::link_for( get_current_user_id() ) ), '', KUDORA_DIR . 'templates/' );
	}

	/**
	 * Server-rendered "Loyalty Card" block.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type( KUDORA_DIR . 'blocks/loyalty-card', array( 'render_callback' => array( $this, 'points_shortcode' ) ) );
		}
	}
}
