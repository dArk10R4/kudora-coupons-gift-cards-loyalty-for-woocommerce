<?php
/**
 * Registry of Kudora's virtual coupons (discount rules, loyalty points, referral
 * discount). They are real WooCommerce coupons that exist only in code, so their
 * discounts flow through WC_Discounts identically in the classic cart, the Cart and
 * Checkout blocks, the Store API and admin order recalculation.
 *
 * All Kudora virtual codes start with `kudora-`.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual coupon registry.
 */
class Kudora_Virtual_Coupons {

	/**
	 * Code prefix reserved for Kudora.
	 */
	const PREFIX = 'kudora-';

	/**
	 * Providers keyed by code prefix (after `kudora-`), e.g. `rule-`, `points`.
	 * Each: array( 'data' => callable( string $code ): array|false, 'label' => callable( string $code ): string, 'valid' => callable|null ).
	 *
	 * @var array<string,array>
	 */
	protected static $providers = array();

	/**
	 * Register a provider.
	 *
	 * @param string        $prefix Code prefix after `kudora-`.
	 * @param callable      $data   Returns coupon props (discount_type, amount, …) or false.
	 * @param callable      $label  Returns the customer-facing label.
	 * @param callable|null $valid  Optional extra validation fn( string $code, WC_Discounts $discounts ): true|string (error).
	 * @return void
	 */
	public static function add_provider( $prefix, $data, $label, $valid = null ) {
		self::$providers[ $prefix ] = array(
			'data'  => $data,
			'label' => $label,
			'valid' => $valid,
		);
	}

	/**
	 * Whether a code belongs to Kudora.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_ours( $code ) {
		return is_string( $code ) && 0 === strpos( wc_format_coupon_code( $code ), self::PREFIX );
	}

	/**
	 * Find the provider for a code.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	protected static function provider_for( $code ) {
		$rest = substr( wc_format_coupon_code( $code ), strlen( self::PREFIX ) );
		foreach ( self::$providers as $prefix => $p ) {
			if ( 0 === strpos( $rest, $prefix ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label' ), 10, 2 );
		add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'coexist_with_individual' ), 10, 2 );
		add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'keep_ours_on_individual' ), 10, 3 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'freeze_on_recalculate' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'store_label' ), 10, 3 );
		add_filter( 'woocommerce_coupon_message', array( $this, 'silence_message' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'silence_removed' ), 10, 3 );
	}

	/**
	 * An automatic discount that stopped applying is removed quietly: no "coupon
	 * kudora-... is invalid and was removed" notice with an internal code.
	 *
	 * @param string         $err      Error message.
	 * @param int            $err_code Error code.
	 * @param WC_Coupon|null $coupon   Coupon.
	 * @return string
	 */
	public function silence_removed( $err, $err_code, $coupon = null ) {
		if ( WC_Coupon::E_WC_COUPON_INVALID_REMOVED === (int) $err_code && $coupon instanceof WC_Coupon && self::is_ours( $coupon->get_code() ) ) {
			return '';
		}
		return $err;
	}

	/**
	 * Supply props for our codes.
	 *
	 * @param mixed $data Existing data (false).
	 * @param mixed $code Code (string) or other lookup value.
	 * @return mixed
	 */
	public function coupon_data( $data, $code ) {
		if ( false !== $data || ! is_string( $code ) || '' === $code || ! self::is_ours( $code ) ) {
			return $data;
		}
		$p = self::provider_for( $code );
		if ( ! $p ) {
			return $data;
		}
		$props = call_user_func( $p['data'], wc_format_coupon_code( $code ) );
		if ( ! is_array( $props ) ) {
			return $data;
		}
		return array_merge(
			array(
				'individual_use' => false,
				'usage_limit'    => 0,
			),
			$props
		);
	}

	/**
	 * Provider-level validation (throws with a message so the customer sees why).
	 *
	 * @param bool         $valid     Current validity.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Discounts.
	 * @return bool
	 * @throws Exception With the provider's message.
	 */
	public function validate( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! self::is_ours( $coupon->get_code() ) ) {
			return $valid;
		}
		$p = self::provider_for( $coupon->get_code() );
		if ( ! $p ) {
			return false;
		}
		if ( $p['valid'] ) {
			$result = call_user_func( $p['valid'], wc_format_coupon_code( $coupon->get_code() ), $discounts );
			if ( true !== $result ) {
				throw new Exception( esc_html( is_string( $result ) ? $result : __( 'This discount is not available.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), 100 );
			}
		}
		return $valid;
	}

	/**
	 * Customer-facing label for a code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function label_for( $code ) {
		$p = self::provider_for( $code );
		return $p ? (string) call_user_func( $p['label'], wc_format_coupon_code( $code ) ) : '';
	}

	/**
	 * Classic cart/checkout label.
	 *
	 * @param string    $label  Default label.
	 * @param WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public function label( $label, $coupon ) {
		if ( $coupon instanceof WC_Coupon && self::is_ours( $coupon->get_code() ) ) {
			$ours = self::label_for( $coupon->get_code() );
			return '' !== $ours ? esc_html( $ours ) : $label;
		}
		return $label;
	}

	/**
	 * Our codes may join a cart holding an individual-use coupon.
	 *
	 * @param bool      $allow  Default.
	 * @param WC_Coupon $coupon Coupon being applied.
	 * @return bool
	 */
	public function coexist_with_individual( $allow, $coupon ) {
		return ( $coupon instanceof WC_Coupon && self::is_ours( $coupon->get_code() ) ) ? true : $allow;
	}

	/**
	 * When an individual-use coupon is applied, keep our codes in the cart.
	 *
	 * @param array     $keep    Codes to keep.
	 * @param WC_Coupon $coupon  Individual-use coupon.
	 * @param array     $applied Applied codes.
	 * @return array
	 */
	public function keep_ours_on_individual( $keep, $coupon, $applied ) {
		foreach ( (array) $applied as $code ) {
			if ( self::is_ours( $code ) ) {
				$keep[] = $code;
			}
		}
		return array_values( array_unique( $keep ) );
	}

	/**
	 * After checkout our discounts are frozen at the recorded amount: admin
	 * recalculation re-applies them as a fixed cart discount of what the customer got.
	 *
	 * @param WC_Coupon|false      $coupon Coupon.
	 * @param string               $code   Code.
	 * @param WC_Order_Item_Coupon $item   Order coupon line.
	 * @return WC_Coupon|false
	 */
	public function freeze_on_recalculate( $coupon, $code, $item ) {
		if ( ! self::is_ours( $code ) || ! $item instanceof WC_Order_Item_Coupon ) {
			return $coupon;
		}
		$order  = $item->get_order();
		$amount = (float) $item->get_discount();
		if ( $order && $order->get_prices_include_tax() ) {
			$amount += (float) $item->get_discount_tax();
		}
		$frozen = new WC_Coupon();
		$frozen->set_code( $code );
		$frozen->set_virtual( true );
		$frozen->set_discount_type( Kudora_Coupon_Types::FROZEN );
		$frozen->set_amount( $amount );
		// Spread over the lines it could apply to (the rule's scope), never over gift
		// card purchases; see Kudora_Coupon_Types::frozen_discounts().
		$scope = array();
		if ( 0 === strpos( $code, 'kudora-rule-' ) ) {
			$rule  = Kudora_Rules_Repo::get( (int) substr( $code, strlen( 'kudora-rule-' ) ) );
			$scope = $rule && isset( $rule['config']['scope'] ) ? (array) $rule['config']['scope'] : array();
		}
		$frozen->update_meta_data( '_kudora_frozen_scope', Kudora_Scope::sanitize( $scope ) );
		return $frozen;
	}

	/**
	 * Store the label on the order coupon line so it survives rule edits/deletion.
	 *
	 * @param WC_Order_Item_Coupon $item   Coupon line.
	 * @param string               $code   Code.
	 * @param WC_Coupon            $coupon Coupon.
	 * @return void
	 */
	public function store_label( $item, $code, $coupon ) {
		if ( self::is_ours( $code ) ) {
			$label = self::label_for( $code );
			if ( '' !== $label ) {
				$item->add_meta_data( '_kudora_label', $label, true );
			}
		}
	}

	/**
	 * Silence "Coupon code applied successfully." for our automatic codes.
	 *
	 * @param string    $msg      Message.
	 * @param int       $msg_code Message code.
	 * @param WC_Coupon $coupon   Coupon.
	 * @return string
	 */
	public function silence_message( $msg, $msg_code, $coupon ) {
		if ( $coupon instanceof WC_Coupon && self::is_ours( $coupon->get_code() ) ) {
			return '';
		}
		return $msg;
	}

	/**
	 * Whether the current request is the customer removing a coupon (the × in the
	 * cart), as opposed to WooCommerce dropping it during validation.
	 *
	 * @return bool
	 */
	public static function is_explicit_removal() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only request inspection; WooCommerce verifies its own nonces.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'cart/remove-coupon' ) ) {
			return true;
		}
		if ( isset( $_GET['wc-ajax'] ) && 'remove_coupon' === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) ) {
			return true;
		}
		return isset( $_GET['remove_coupon'] );
		// phpcs:enable
	}
}
