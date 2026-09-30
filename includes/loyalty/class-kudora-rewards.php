<?php
/**
 * Rewards catalog: customers turn points into a single-use, email-restricted
 * coupon — fixed amount, percentage (with a cap), free shipping or a free product.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewards.
 */
class Kudora_Rewards {

	/**
	 * Reward types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'fixed'         => __( 'Fixed discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'percent'       => __( 'Percentage discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'free_shipping' => __( 'Free shipping', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'free_product'  => __( 'Free product', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle_redeem' ) );
		add_filter( 'kudora_store_api_action', array( $this, 'store_api_action' ), 10, 3 );
	}

	/**
	 * All rewards (sanitised).
	 *
	 * @param bool $active_only Only active rewards.
	 * @return array[]
	 */
	public static function all( $active_only = false ) {
		$list = get_option( 'kudora_loyalty_rewards', array() );
		$out  = array();
		foreach ( (array) $list as $r ) {
			$r = self::sanitize( $r );
			if ( $active_only && ! $r['status'] ) {
				continue;
			}
			$out[] = $r;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['cost'] <=> $b['cost'];
			}
		);
		return $out;
	}

	/**
	 * One reward.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $r ) {
			if ( $r['id'] === (string) $id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Sanitise a reward definition.
	 *
	 * @param mixed $r Raw.
	 * @return array
	 */
	public static function sanitize( $r ) {
		$r    = is_array( $r ) ? $r : array();
		$type = isset( $r['type'] ) && isset( self::types()[ $r['type'] ] ) ? $r['type'] : 'fixed';
		return array(
			'id'           => isset( $r['id'] ) && '' !== $r['id'] ? sanitize_key( $r['id'] ) : 'r' . wp_generate_password( 8, false, false ),
			'name'         => isset( $r['name'] ) ? sanitize_text_field( $r['name'] ) : '',
			'description'  => isset( $r['description'] ) ? sanitize_text_field( $r['description'] ) : '',
			'type'         => $type,
			'cost'         => isset( $r['cost'] ) ? max( 1, absint( $r['cost'] ) ) : 100,
			'value'        => isset( $r['value'] ) ? (float) wc_format_decimal( $r['value'] ) : 0.0,
			'max_discount' => isset( $r['max_discount'] ) ? (float) wc_format_decimal( $r['max_discount'] ) : 0.0,
			'product_id'   => isset( $r['product_id'] ) ? absint( $r['product_id'] ) : 0,
			'min_order'    => isset( $r['min_order'] ) ? (float) wc_format_decimal( $r['min_order'] ) : 0.0,
			'expiry_days'  => isset( $r['expiry_days'] ) ? absint( $r['expiry_days'] ) : 30,
			'tier'         => isset( $r['tier'] ) ? sanitize_key( $r['tier'] ) : '',
			'limit'        => isset( $r['limit'] ) ? absint( $r['limit'] ) : 0,
			'status'       => ! empty( $r['status'] ) ? 1 : 0,
		);
	}

	/**
	 * Save the whole catalog.
	 *
	 * @param array $list Rewards.
	 * @return void
	 */
	public static function save_all( array $list ) {
		update_option( 'kudora_loyalty_rewards', array_values( array_map( array( __CLASS__, 'sanitize' ), $list ) ), false );
	}

	/**
	 * How many times a customer claimed a reward.
	 *
	 * @param int    $user_id   User id.
	 * @param string $reward_id Reward id.
	 * @return int
	 */
	public static function claims( $user_id, $reward_id ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- rare customer action.
					array(
						'key'   => '_kudora_reward_user',
						'value' => (int) $user_id,
					),
					array(
						'key'   => '_kudora_reward',
						'value' => (string) $reward_id,
					),
				),
			)
		);
		return count( $q->posts );
	}

	/**
	 * Whether a customer can claim a reward now; returns an error message or ''.
	 *
	 * @param array $reward  Reward.
	 * @param int   $user_id User id.
	 * @return string
	 */
	public static function why_not( array $reward, $user_id ) {
		if ( ! $reward['status'] ) {
			return __( 'This reward is not available.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		if ( Kudora_Points_Repo::balance( $user_id ) < $reward['cost'] ) {
			return __( 'You do not have enough points for this reward yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		if ( '' !== $reward['tier'] ) {
			$tier = Kudora_Points_Repo::tier_of( $user_id );
			$need = null;
			foreach ( Kudora_Points_Repo::tiers() as $t ) {
				if ( $t['id'] === $reward['tier'] ) {
					$need = $t;
				}
			}
			if ( $need && ( ! $tier || $tier['min_points'] < $need['min_points'] ) ) {
				/* translators: %s: tier name */
				return sprintf( __( 'Reach the %s tier to unlock this reward.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $need['name'] );
			}
		}
		if ( $reward['limit'] > 0 && self::claims( $user_id, $reward['id'] ) >= $reward['limit'] ) {
			return __( 'You have already claimed this reward the maximum number of times.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		if ( 'free_product' === $reward['type'] && ( ! $reward['product_id'] || ! wc_get_product( $reward['product_id'] ) ) ) {
			return __( 'This reward is not available.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Claim: atomic points debit, then create the coupon (points go back if that fails).
	 *
	 * @param string $reward_id Reward id.
	 * @param int    $user_id   User id.
	 * @param string $request   Client request id (double-click protection).
	 * @return int|WP_Error Coupon id.
	 */
	public static function claim( $reward_id, $user_id, $request = '' ) {
		$reward = self::get( $reward_id );
		if ( ! $reward ) {
			return new WP_Error( 'kudora_reward', __( 'This reward is not available.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$why = self::why_not( $reward, $user_id );
		if ( '' !== $why ) {
			return new WP_Error( 'kudora_reward', $why );
		}
		$lock = 'reward_' . $user_id;
		if ( ! Kudora_DB::lock( $lock, 5 ) ) {
			return new WP_Error( 'kudora_reward_busy', __( 'Please try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		try {
			$key = 'reward:' . $user_id . ':' . ( '' !== $request ? substr( sanitize_key( $request ), 0, 40 ) : wp_generate_uuid4() );
			$res = Kudora_Points_Repo::deduct(
				$user_id,
				$reward['cost'],
				'reward',
				array(
					'entity_type' => 'reward',
					'note'        => $reward['name'],
					'key'         => $key,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$coupon_id = self::create_coupon( $reward, $user_id );
			if ( is_wp_error( $coupon_id ) ) {
				Kudora_Points_Repo::add(
					$user_id,
					$reward['cost'],
					'restored',
					array(
						'entity_type' => 'reward',
						'note'        => $reward['name'],
						'key'         => $key . ':undo',
						'lifetime'    => false,
					)
				);
				return $coupon_id;
			}
		} finally {
			Kudora_DB::unlock( $lock );
		}
		Kudora_Emails::send( 'Kudora_Email_Reward_Coupon', $user_id, $coupon_id );
		return $coupon_id;
	}

	/**
	 * Create the reward coupon.
	 *
	 * @param array $reward  Reward.
	 * @param int   $user_id User id.
	 * @return int|WP_Error
	 */
	protected static function create_coupon( array $reward, $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'kudora_user', 'No user.' );
		}
		try {
			$code   = Kudora_Code_Generator::unique(
				array(
					'prefix'  => 'RWD-',
					'charset' => 'alnum',
					'length'  => 8,
					'group'   => 4,
				),
				function ( $c ) {
					return (bool) wc_get_coupon_id_by_code( $c );
				}
			);
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_description( sprintf( 'Kudora reward: %s', $reward['name'] ) );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_email_restrictions( array( $user->user_email ) );
			$coupon->set_individual_use( false );
			if ( $reward['min_order'] > 0 ) {
				$coupon->set_minimum_amount( $reward['min_order'] );
			}
			if ( $reward['expiry_days'] > 0 ) {
				$coupon->set_date_expires( time() + $reward['expiry_days'] * DAY_IN_SECONDS );
			}
			switch ( $reward['type'] ) {
				case 'percent':
					$coupon->set_discount_type( 'percent' );
					$coupon->set_amount( min( 100, $reward['value'] ) );
					if ( $reward['max_discount'] > 0 ) {
						$coupon->update_meta_data( '_kudora_max_discount', $reward['max_discount'] );
					}
					break;
				case 'free_shipping':
					$coupon->set_discount_type( 'fixed_cart' );
					$coupon->set_amount( 0 );
					$coupon->set_free_shipping( true );
					break;
				case 'free_product':
					$coupon->set_discount_type( Kudora_Coupon_Types::FREE_PRODUCT );
					$coupon->set_amount( 0 );
					$coupon->update_meta_data( '_kudora_free_product', $reward['product_id'] );
					break;
				default:
					$coupon->set_discount_type( 'fixed_cart' );
					$coupon->set_amount( $reward['value'] );
			}
			$coupon->update_meta_data( '_kudora_reward', $reward['id'] );
			$coupon->update_meta_data( '_kudora_reward_user', $user_id );
			$coupon->update_meta_data( '_kudora_reward_cost', $reward['cost'] );
			$id = $coupon->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'kudora_coupon', $e->getMessage() );
		}
		return $id ? (int) $id : new WP_Error( 'kudora_coupon', 'Coupon not saved.' );
	}

	/**
	 * Reward coupons of a customer.
	 *
	 * @param int $user_id User id.
	 * @return WC_Coupon[]
	 */
	public static function coupons_of( $user_id ) {
		$q   = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- account page listing.
					array(
						'key'   => '_kudora_reward_user',
						'value' => (int) $user_id,
					),
				),
			)
		);
		return array_map(
			function ( $id ) {
				return new WC_Coupon( $id );
			},
			$q->posts
		);
	}

	/**
	 * My Account form post: claim a reward.
	 *
	 * @return void
	 */
	public function handle_redeem() {
		if ( ! isset( $_POST['kudora_claim_reward'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['_kudora_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_kudora_nonce'] ) ), 'kudora-claim-reward' ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			return;
		}
		$reward_id = sanitize_key( wp_unslash( $_POST['kudora_claim_reward'] ) );
		$request   = isset( $_POST['kudora_request'] ) ? sanitize_key( wp_unslash( $_POST['kudora_request'] ) ) : '';
		$res       = self::claim( $reward_id, get_current_user_id(), $request );
		if ( is_wp_error( $res ) ) {
			wc_add_notice( $res->get_error_message(), 'error' );
		} else {
			$coupon = new WC_Coupon( $res );
			/* translators: %s: coupon code */
			wc_add_notice( sprintf( __( 'Reward claimed! Your coupon code is %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . esc_html( $coupon->get_code() ) . '</strong>' ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'points-rewards' ) );
			exit;
		}
	}

	/**
	 * Store API: claim a reward (e.g. from a block UI).
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param array  $data   Data.
	 * @return mixed
	 */
	public function store_api_action( $result, $action, $data ) {
		if ( 'reward_claim' !== $action ) {
			return $result;
		}
		$res = self::claim( isset( $data['reward'] ) ? sanitize_key( $data['reward'] ) : '', get_current_user_id(), isset( $data['request'] ) ? sanitize_key( $data['request'] ) : '' );
		return is_wp_error( $res ) ? $res : true;
	}
}
