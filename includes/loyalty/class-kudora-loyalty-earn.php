<?php
/**
 * Earning points: per-currency base rate, Booknetic-style earning rules (fixed
 * award or multiplier, AND conditions, optional product scope), tier multiplier,
 * first-order bonus; action rewards (sign-up, product review, birthday); and
 * reversal on refunds and cancellations.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points earning.
 */
class Kudora_Loyalty_Earn {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'kudora_order_paid', array( $this, 'on_paid' ) );
		add_action( 'kudora_order_completed', array( $this, 'on_completed' ) );
		add_action( 'kudora_order_voided', array( $this, 'on_voided' ), 30, 2 );
		add_action( 'kudora_order_refunded', array( $this, 'on_refunded' ), 30, 3 );
		add_action( 'kudora_order_refunded_to_card', array( $this, 'on_card_refund' ), 30, 2 );

		add_action( 'user_register', array( $this, 'signup' ), 20 );
		add_action( 'comment_post', array( $this, 'review_posted' ), 20, 3 );
		add_action( 'transition_comment_status', array( $this, 'review_status' ), 20, 3 );
		add_action( 'kudora_daily_tasks', array( $this, 'birthdays' ) );

		add_action( 'woocommerce_edit_account_form', array( $this, 'birthday_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_birthday' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_birthday' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Calculation
	 * ------------------------------------------------------------------- */

	/**
	 * Round points per the setting.
	 *
	 * @param float $points Raw points.
	 * @return int
	 */
	public static function round_points( $points ) {
		switch ( Kudora_Settings::get( 'loyalty_rounding' ) ) {
			case 'ceil':
				return (int) ceil( $points - 1e-9 );
			case 'round':
				return (int) round( $points );
			default:
				return (int) floor( $points + 1e-9 );
		}
	}

	/**
	 * Money basis of one context item.
	 *
	 * @param array $item Item.
	 * @return float
	 */
	protected static function item_basis( array $item ) {
		return 'subtotal' === Kudora_Settings::get( 'loyalty_earn_basis' ) ? (float) $item['subtotal'] : (float) $item['total'];
	}

	/**
	 * Points a context earns, with a breakdown.
	 *
	 * @param Kudora_Context $ctx        Context.
	 * @param float|null     $order_total For the `total` basis: the order total.
	 * @return array{points:int,breakdown:array}
	 */
	public static function calculate( Kudora_Context $ctx, $order_total = null ) {
		$none = array(
			'points'    => 0,
			'breakdown' => array(),
		);
		if ( $ctx->user_id <= 0 ) {
			return $none;
		}
		$rate         = max( 0.0, Kudora_Settings::float( 'loyalty_earn_rate' ) );
		$exclude_gc   = Kudora_Settings::yes( 'loyalty_exclude_gift_cards' );
		$eligible     = array();
		$basis        = 0.0;
		foreach ( $ctx->items as $item ) {
			if ( $exclude_gc && $item['is_gift_card'] ) {
				continue;
			}
			$eligible[] = $item;
			$basis     += max( 0.0, self::item_basis( $item ) );
		}
		if ( 'total' === Kudora_Settings::get( 'loyalty_earn_basis' ) && null !== $order_total ) {
			$gc_total = 0.0;
			foreach ( $ctx->items as $item ) {
				if ( $exclude_gc && $item['is_gift_card'] ) {
					$gc_total += (float) $item['total'];
				}
			}
			$basis = max( 0.0, (float) $order_total - $gc_total );
		}

		$base      = $basis * $rate;
		$extra     = 0.0;
		$fixed     = 0;
		$breakdown = array(
			array(
				'label'  => __( 'Points on your purchase', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'points' => $base,
			),
		);

		foreach ( Kudora_Rules_Repo::active( 'earn' ) as $rule ) {
			if ( ! Kudora_Conditions::matches( $rule['conditions'], $ctx ) ) {
				continue;
			}
			$cfg  = $rule['config'];
			$mode = isset( $cfg['mode'] ) ? $cfg['mode'] : 'fixed';
			if ( 'multiplier' === $mode ) {
				$mult  = max( 0.0, (float) ( isset( $cfg['multiplier'] ) ? $cfg['multiplier'] : 1 ) );
				$scope = isset( $cfg['scope'] ) ? (array) $cfg['scope'] : array();
				$part  = 0.0;
				foreach ( $eligible as $item ) {
					if ( Kudora_Scope::matches_item( $scope, $item, $exclude_gc ) ) {
						$part += max( 0.0, self::item_basis( $item ) );
					}
				}
				$add    = ( $mult - 1 ) * $part * $rate;
				$extra += $add;
				$breakdown[] = array(
					'label'  => $rule['name'],
					'points' => $add,
				);
			} else {
				$award  = max( 0, (int) ( isset( $cfg['award'] ) ? $cfg['award'] : 0 ) );
				$fixed += $award;
				$breakdown[] = array(
					'label'  => $rule['name'],
					'points' => $award,
				);
			}
			if ( $rule['exclusive'] ) {
				break;
			}
		}

		$tier = Kudora_Points_Repo::tier_of( $ctx->user_id );
		if ( $tier && $tier['multiplier'] > 0 && abs( $tier['multiplier'] - 1 ) > 0.0001 ) {
			$add         = ( $tier['multiplier'] - 1 ) * $base;
			$extra      += $add;
			$breakdown[] = array(
				/* translators: %s: tier name */
				'label'  => sprintf( __( '%s tier bonus', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $tier['name'] ),
				'points' => $add,
			);
		}

		$first = Kudora_Settings::int( 'loyalty_first_order_points' );
		if ( $first > 0 && 0 === $ctx->order_count() ) {
			$fixed      += $first;
			$breakdown[] = array(
				'label'  => __( 'First order bonus', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'points' => $first,
			);
		}

		return array(
			'points'    => max( 0, self::round_points( $base + $extra ) + $fixed ),
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Estimate for the current cart ("You'll earn X points").
	 *
	 * @return int
	 */
	public static function estimate_for_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! get_current_user_id() ) {
			return 0;
		}
		$applied = Kudora_Loyalty_Redeem::applied();
		if ( $applied['points'] > 0 && ! Kudora_Settings::yes( 'loyalty_earn_on_redeem' ) && in_array( Kudora_Loyalty_Redeem::CODE, WC()->cart->get_applied_coupons(), true ) ) {
			return 0;
		}
		$ctx = Kudora_Context::from_cart( WC()->cart );
		// After-coupon line totals from the last calculation.
		$totals = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$totals[ $key ] = isset( $item['line_total'] ) ? (float) $item['line_total'] : null;
		}
		foreach ( $ctx->items as &$i ) {
			if ( isset( $totals[ $i['key'] ] ) ) {
				$i['total'] = $totals[ $i['key'] ];
			}
		}
		unset( $i );
		$r = self::calculate( $ctx, (float) WC()->cart->get_total( 'edit' ) );
		return $r['points'];
	}

	/**
	 * Estimate for one product unit (product page).
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function estimate_for_product( $product ) {
		if ( Kudora_Context::is_gift_card_product( $product ) && Kudora_Settings::yes( 'loyalty_exclude_gift_cards' ) ) {
			return 0;
		}
		$price = (float) wc_get_price_excluding_tax( $product );
		$pts   = $price * max( 0.0, Kudora_Settings::float( 'loyalty_earn_rate' ) );
		$tier  = Kudora_Points_Repo::tier_of( get_current_user_id() );
		if ( $tier && $tier['multiplier'] > 0 ) {
			$pts *= $tier['multiplier'];
		}
		return self::round_points( $pts );
	}

	/* ---------------------------------------------------------------------
	 * Orders
	 * ------------------------------------------------------------------- */

	/**
	 * Paid: award when the earn status is "processing" (any paid status).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function on_paid( $order ) {
		if ( 'completed' !== Kudora_Settings::get( 'loyalty_earn_status' ) ) {
			$this->award( $order );
		}
	}

	/**
	 * Completed: award when the earn status is "completed".
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function on_completed( $order ) {
		if ( 'completed' === Kudora_Settings::get( 'loyalty_earn_status' ) ) {
			$this->award( $order );
		}
	}

	/**
	 * Award points for an order (once per round).
	 *
	 * @param WC_Order $order Order.
	 * @return int Points awarded.
	 */
	public function award( $order ) {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 || 'earned' === $order->get_meta( '_kudora_earn_state' ) ) {
			return 0;
		}
		// Booknetic: redeeming and earning do not happen together (unless allowed).
		if ( ! Kudora_Settings::yes( 'loyalty_earn_on_redeem' ) && Kudora_Loyalty_Redeem::order_redeem( $order )['points'] > 0 ) {
			return 0;
		}
		$r = self::calculate( Kudora_Context::from_order( $order ), (float) $order->get_total() );
		/**
		 * Filter points awarded for an order.
		 *
		 * @param int      $points Points.
		 * @param WC_Order $order  Order.
		 * @param array    $r      Calculation with breakdown.
		 */
		$points = (int) apply_filters( 'kudora_order_points', $r['points'], $order, $r );
		if ( $points <= 0 ) {
			return 0;
		}
		$round = (int) $order->get_meta( '_kudora_earn_round' ) + 1;
		$id    = Kudora_Points_Repo::add(
			$user_id,
			$points,
			'earned',
			array(
				'entity_type' => 'order',
				'entity_id'   => $order->get_id(),
				/* translators: %s: order number */
				'note'        => sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
				'key'         => sprintf( 'order:%d:earn:%d', $order->get_id(), $round ),
			)
		);
		if ( ! $id ) {
			return 0;
		}
		$order->update_meta_data( '_kudora_earn_round', $round );
		$order->update_meta_data( '_kudora_earn_state', 'earned' );
		$order->update_meta_data( '_kudora_points_earned', $points );
		$order->update_meta_data( '_kudora_points_reversed', 0 );
		$order->save_meta_data();
		/* translators: %s: points */
		$order->add_order_note( sprintf( __( 'Customer earned %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $points ) ) );
		Kudora_Emails::send( 'Kudora_Email_Points_Earned', $user_id, $points, $order->get_id() );
		return $points;
	}

	/**
	 * Cancelled/failed after earning: take everything back.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Status.
	 * @return void
	 */
	public function on_voided( $order, $status = '' ) {
		$this->reverse( $order, null, 'void' );
	}

	/**
	 * Refunds: take back the refunded share of the earned points.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $is_full Full.
	 * @return void
	 */
	public function on_refunded( $order, $refund, $is_full ) {
		$this->reverse( $order, $is_full ? null : $refund, $is_full ? 'full-refund' : 'refund-' . $refund->get_id() );
	}

	/**
	 * Money refunded onto a gift card counts as a refund too.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 * @return void
	 */
	public function on_card_refund( $order, $amount ) {
		$this->reverse( $order, true, 'card-refund-' . md5( (string) $order->get_meta( '_kudora_gc_refunded_log' ) ) );
	}

	/**
	 * Reverse all (null refund) or a pro-rata share of earned points.
	 *
	 * @param WC_Order                  $order  Order.
	 * @param WC_Order_Refund|bool|null $refund Partial refund (truthy), or null for everything left.
	 * @param string               $tag    Idempotency tag.
	 * @return int Points taken back.
	 */
	protected function reverse( $order, $refund, $tag ) {
		if ( 'earned' !== $order->get_meta( '_kudora_earn_state' ) ) {
			return 0;
		}
		$earned   = (int) $order->get_meta( '_kudora_points_earned' );
		$reversed = (int) $order->get_meta( '_kudora_points_reversed' );
		if ( $refund ) {
			// Share of everything the customer paid (money + gift cards) that was refunded.
			$gc       = Kudora_Gift_Card_Redeem::order_amount( $order );
			$gc_back  = array_sum( array_map( 'floatval', (array) $order->get_meta( '_kudora_gc_refunded' ) ) );
			$paid     = (float) $order->get_total() + $gc;
			$refunded = (float) $order->get_total_refunded() + $gc_back;
			$share    = $paid > 0 ? min( 1.0, $refunded / $paid ) : 1.0;
			$target = (int) round( $earned * $share );
			$delta  = max( 0, min( $earned, $target ) - $reversed );
		} else {
			$delta = max( 0, $earned - $reversed );
		}
		if ( $delta <= 0 ) {
			if ( ! $refund ) {
				$order->update_meta_data( '_kudora_earn_state', 'reversed' );
				$order->save_meta_data();
			}
			return 0;
		}
		$round = (int) $order->get_meta( '_kudora_earn_round' );
		$taken = Kudora_Points_Repo::deduct_up_to(
			$order->get_customer_id(),
			$delta,
			'reversed',
			array(
				'entity_type' => 'order',
				'entity_id'   => $order->get_id(),
				/* translators: %s: order number */
				'note'        => sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
				'key'         => sprintf( 'order:%d:earn-%s:%d', $order->get_id(), $tag, $round ),
				'lifetime'    => true,
			)
		);
		$order->update_meta_data( '_kudora_points_reversed', $reversed + $delta );
		if ( ! $refund || $reversed + $delta >= $earned ) {
			$order->update_meta_data( '_kudora_earn_state', 'reversed' );
		}
		$order->save_meta_data();
		/* translators: 1: points, 2: points actually taken */
		$order->add_order_note( sprintf( __( '%1$s reversed (%2$d taken from the balance).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Loyalty_Redeem::format_points( $delta ), $taken ) );
		return $delta;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	/**
	 * Sign-up bonus for new customers.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function signup( $user_id ) {
		$points = Kudora_Settings::int( 'loyalty_signup_points' );
		if ( $points <= 0 ) {
			return;
		}
		$user = get_userdata( $user_id );
		/**
		 * Whether a newly registered user gets the sign-up bonus (customers by default).
		 *
		 * @param bool    $eligible Eligible.
		 * @param WP_User $user     User.
		 */
		if ( ! $user || ! apply_filters( 'kudora_signup_eligible', in_array( 'customer', (array) $user->roles, true ), $user ) ) {
			return;
		}
		Kudora_Points_Repo::add(
			$user_id,
			$points,
			'signup',
			array(
				'entity_type' => 'user',
				'entity_id'   => $user_id,
				'key'         => 'signup:' . $user_id,
			)
		);
	}

	/**
	 * A review was posted (approved immediately or later).
	 *
	 * @param int        $comment_id Comment id.
	 * @param int|string $approved   1 when approved.
	 * @param array      $data       Comment data.
	 * @return void
	 */
	public function review_posted( $comment_id, $approved, $data = array() ) {
		if ( 1 === (int) $approved ) {
			$this->maybe_reward_review( get_comment( $comment_id ) );
		}
	}

	/**
	 * A review became approved.
	 *
	 * @param string     $new     New status.
	 * @param string     $old     Old status.
	 * @param WP_Comment $comment Comment.
	 * @return void
	 */
	public function review_status( $new, $old, $comment ) {
		if ( 'approved' === $new && 'approved' !== $old ) {
			$this->maybe_reward_review( $comment );
		}
	}

	/**
	 * Reward a product review once per customer and product.
	 *
	 * @param WP_Comment|null $comment Comment.
	 * @return void
	 */
	protected function maybe_reward_review( $comment ) {
		$points = Kudora_Settings::int( 'loyalty_review_points' );
		if ( $points <= 0 || ! $comment instanceof WP_Comment || ! $comment->user_id ) {
			return;
		}
		if ( 'product' !== get_post_type( $comment->comment_post_ID ) || ! in_array( $comment->comment_type, array( 'review', 'comment', '' ), true ) || $comment->comment_parent ) {
			return;
		}
		$user_id    = (int) $comment->user_id;
		$product_id = (int) $comment->comment_post_ID;
		if ( Kudora_Settings::yes( 'loyalty_review_verified_only' ) ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! wc_customer_bought_product( $user->user_email, $user_id, $product_id ) ) {
				return;
			}
		}
		Kudora_Points_Repo::add(
			$user_id,
			$points,
			'review',
			array(
				'entity_type' => 'product',
				'entity_id'   => $product_id,
				'note'        => get_the_title( $product_id ),
				'key'         => sprintf( 'review:%d:%d', $user_id, $product_id ),
			)
		);
	}

	/**
	 * Daily: birthday points (once per year) + expiry and expiry warnings.
	 *
	 * @return void
	 */
	public function birthdays() {
		Kudora_Points_Repo::expire_due();
		$warn = Kudora_Settings::int( 'loyalty_expiry_warning_days' );
		if ( $warn > 0 && Kudora_Settings::int( 'loyalty_expiry_days' ) > 0 ) {
			foreach ( Kudora_Points_Repo::collect_expiry_warnings( $warn ) as $user_id => $info ) {
				Kudora_Emails::send( 'Kudora_Email_Points_Expiring', $user_id, $info['points'], $info['date'] );
			}
		}

		$points = Kudora_Settings::int( 'loyalty_birthday_points' );
		if ( $points <= 0 ) {
			return;
		}
		$today = wp_date( 'm-d' );
		$year  = wp_date( 'Y' );
		$users = get_users(
			array(
				'meta_key'   => '_kudora_birthday', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily background job.
				'meta_value' => $today, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- daily background job.
				'fields'     => 'ID',
				'number'     => 2000,
			)
		);
		foreach ( $users as $user_id ) {
			Kudora_Points_Repo::add(
				(int) $user_id,
				$points,
				'birthday',
				array(
					'entity_type' => 'user',
					'entity_id'   => (int) $user_id,
					/* translators: %s: year */
					'note'        => sprintf( __( 'Happy birthday %s!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $year ),
					'key'         => sprintf( 'birthday:%d:%s', $user_id, $year ),
				)
			);
		}
	}

	/**
	 * Birthday field on the account form.
	 *
	 * @return void
	 */
	public function birthday_field() {
		if ( Kudora_Settings::int( 'loyalty_birthday_points' ) <= 0 ) {
			return;
		}
		$value  = (string) get_user_meta( get_current_user_id(), '_kudora_birthday', true );
		$locked = '' !== $value && (int) get_user_meta( get_current_user_id(), '_kudora_birthday_set', true ) > time() - YEAR_IN_SECONDS;
		$month  = $value ? (int) substr( $value, 0, 2 ) : 0;
		$day    = $value ? (int) substr( $value, 3, 2 ) : 0;
		wc_get_template(
			'loyalty/birthday-field.php',
			array(
				'month'  => $month,
				'day'    => $day,
				'locked' => $locked,
				'points' => Kudora_Settings::int( 'loyalty_birthday_points' ),
			),
			'',
			KUDORA_DIR . 'templates/'
		);
	}

	/**
	 * Validate the birthday.
	 *
	 * @param WP_Error $errors Errors.
	 * @param WP_User  $user   User.
	 * @return void
	 */
	public function validate_birthday( $errors, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form nonce.
		$m = isset( $_POST['kudora_birthday_month'] ) ? absint( $_POST['kudora_birthday_month'] ) : 0;
		$d = isset( $_POST['kudora_birthday_day'] ) ? absint( $_POST['kudora_birthday_day'] ) : 0;
		// phpcs:enable
		if ( ( $m || $d ) && ! checkdate( max( 1, $m ), max( 1, $d ), 2024 ) ) {
			$errors->add( 'kudora_birthday', __( 'Please enter a valid birthday.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
	}

	/**
	 * Save the birthday (changeable once a year, to prevent repeat bonuses).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_birthday( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form nonce.
		$m = isset( $_POST['kudora_birthday_month'] ) ? absint( $_POST['kudora_birthday_month'] ) : 0;
		$d = isset( $_POST['kudora_birthday_day'] ) ? absint( $_POST['kudora_birthday_day'] ) : 0;
		// phpcs:enable
		if ( ! $m || ! $d || ! checkdate( $m, $d, 2024 ) ) {
			return;
		}
		$new = sprintf( '%02d-%02d', $m, $d );
		$old = (string) get_user_meta( $user_id, '_kudora_birthday', true );
		if ( $new === $old ) {
			return;
		}
		if ( '' !== $old && (int) get_user_meta( $user_id, '_kudora_birthday_set', true ) > time() - YEAR_IN_SECONDS ) {
			return;
		}
		update_user_meta( $user_id, '_kudora_birthday', $new );
		update_user_meta( $user_id, '_kudora_birthday_set', time() );
	}
}
