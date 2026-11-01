<?php
/**
 * Shared front-end plumbing: asset registration and the My Account tabs
 * ("Gift cards", "Points & rewards").
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end assets and account endpoints.
 */
class Kudora_Frontend {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		// Register early: block themes render blocks (and their shortcodes) before wp_enqueue_scripts.
		add_action( 'init', array( $this, 'register_assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 5 );
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );
		foreach ( self::endpoints() as $key => $slug ) {
			add_filter( 'woocommerce_endpoint_' . $slug . '_title', array( $this, 'endpoint_title' ), 10, 2 );
		}
	}

	/**
	 * Enabled account endpoints: key => slug.
	 *
	 * @return array<string,string>
	 */
	public static function endpoints() {
		$out = array();
		if ( Kudora_Settings::module( 'gift_cards' ) ) {
			$out['gift_cards'] = 'gift-cards';
		}
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			$out['points'] = 'points-rewards';
		}
		return $out;
	}

	/**
	 * Register CSS/JS (enqueued on demand).
	 *
	 * @return void
	 */
	public function register_assets() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		wp_register_style( 'kudora-frontend', KUDORA_URL . 'assets/css/frontend.css', array(), KUDORA_VERSION );
		wp_register_script( 'kudora-gift-card-form', KUDORA_URL . 'assets/js/gift-card-form.js', array(), KUDORA_VERSION, true );
		wp_localize_script(
			'kudora-gift-card-form',
			'kudoraGcForm',
			array(
				/* translators: %d: characters left */
				'left' => __( '%d characters left', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				/* translators: %s: recipient name */
				'to'   => __( 'To: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
		wp_register_script( 'kudora-classic', KUDORA_URL . 'assets/js/classic.js', array( 'jquery' ), KUDORA_VERSION, true );
		wp_localize_script(
			'kudora-classic',
			'kudoraClassic',
			array(
				'ajaxUrl'     => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'gcNonce'     => wp_create_nonce( 'kudora-gift-card' ),
				'pointsNonce' => wp_create_nonce( 'kudora-points' ),
			)
		);
		wp_register_script( 'kudora-account', KUDORA_URL . 'assets/js/account.js', array(), KUDORA_VERSION, true );
		wp_localize_script(
			'kudora-account',
			'kudoraAccount',
			array(
				'copied' => __( 'Copied!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
		wp_register_script( 'kudora-printable', KUDORA_URL . 'assets/js/printable.js', array(), KUDORA_VERSION, true );
	}

	/**
	 * Classic cart and checkout pages carry our rows/forms.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			wp_enqueue_style( 'kudora-frontend' );
			wp_enqueue_script( 'kudora-classic' );
		}
	}

	/**
	 * Rewrite endpoints.
	 *
	 * @return void
	 */
	public function add_endpoints() {
		$rules   = get_option( 'rewrite_rules' );
		$missing = false;
		foreach ( self::endpoints() as $slug ) {
			add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
			if ( is_array( $rules ) && $rules && ! self::has_rule( $rules, $slug ) ) {
				$missing = true;
			}
		}
		// A module was switched on after the last flush: flush once (guarded).
		if ( $missing && ! get_transient( 'kudora_flushed_recently' ) ) {
			set_transient( 'kudora_flushed_recently', 1, HOUR_IN_SECONDS );
			update_option( 'kudora_flush_rewrite', 1, true );
		}
	}

	/**
	 * Whether the rewrite rules contain an endpoint.
	 *
	 * @param array  $rules Rules.
	 * @param string $slug  Endpoint.
	 * @return bool
	 */
	protected static function has_rule( array $rules, $slug ) {
		foreach ( array_keys( $rules ) as $regex ) {
			if ( false !== strpos( $regex, '/' . $slug . '(/(.*))?/?$' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register query vars with WooCommerce.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		foreach ( self::endpoints() as $slug ) {
			$vars[ $slug ] = $slug;
		}
		return $vars;
	}

	/**
	 * Menu entries before "Logout".
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function menu_items( $items ) {
		$ours = array();
		foreach ( self::endpoints() as $key => $slug ) {
			$ours[ $slug ] = self::title_for( $key );
		}
		if ( ! $ours ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $k => $label ) {
			if ( 'customer-logout' === $k ) {
				$out = array_merge( $out, $ours );
				$ours = array();
			}
			$out[ $k ] = $label;
		}
		return array_merge( $out, $ours );
	}

	/**
	 * Title for an endpoint key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function title_for( $key ) {
		if ( 'gift_cards' === $key ) {
			return __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		$label = Kudora_Settings::get( 'loyalty_label' );
		return '' !== $label ? $label : __( 'Points & rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
	}

	/**
	 * Allowed HTML for templates returned by our shortcodes and blocks: post HTML plus
	 * the form controls and ARIA attributes they print (themes may override them).
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$common = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'aria-label'       => true,
			'aria-describedby' => true,
			'data-*'           => true,
		);
		$html          = wp_kses_allowed_html( 'post' );
		$html['form']  = $common + array(
			'action'     => true,
			'method'     => true,
			'novalidate' => true,
		);
		$html['input'] = $common + array(
			'type'         => true,
			'name'         => true,
			'value'        => true,
			'placeholder'  => true,
			'autocomplete' => true,
			'spellcheck'   => true,
			'required'     => true,
			'readonly'     => true,
			'disabled'     => true,
			'maxlength'    => true,
		);
		$html['div']  += array(
			'aria-valuemin' => true,
			'aria-valuemax' => true,
			'aria-valuenow' => true,
		);
		return $html;
	}

	/**
	 * Endpoint page title.
	 *
	 * @param string $title    Title.
	 * @param string $endpoint Endpoint.
	 * @return string
	 */
	public function endpoint_title( $title, $endpoint ) {
		$key = array_search( $endpoint, self::endpoints(), true );
		return false !== $key ? self::title_for( $key ) : $title;
	}
}
