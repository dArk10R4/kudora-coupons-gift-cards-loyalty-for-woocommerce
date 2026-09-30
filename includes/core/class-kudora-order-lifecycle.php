<?php
/**
 * One dispatcher for every order event the modules care about, so each module
 * reacts identically to the classic checkout, the Checkout block / Store API and
 * admin status changes.
 *
 * Events fired (modules must be idempotent — each can fire more than once):
 *  - kudora_order_placed( $order )                 checkout finished, before payment
 *  - kudora_order_paid( $order )                   reached a paid status
 *  - kudora_order_completed( $order )              reached completed
 *  - kudora_order_voided( $order, $status )        cancelled / failed / checkout exception / trashed
 *  - kudora_order_reinstated( $order, $from, $to ) left cancelled/failed for a live status
 *  - kudora_order_refunded( $order, $refund, $is_full )
 *  - kudora_order_release( $order, $reason )       give back what an unpaid order holds
 *                                                  (superseded by a newer checkout, or stale)
 *  - kudora_order_before_payment( $order, $errors ) an existing unpaid order is about to be
 *                                                  paid (order-pay page): take back what
 *                                                  was released, or drop it (error)
 *
 * Holdings (gift card money, points) are taken when an order is placed. The
 * customer's session remembers that order ("held order") until it is paid, so a
 * retried payment of the same checkout sees the held money as still available.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order event dispatcher.
 */
class Kudora_Order_Lifecycle {

	/**
	 * Session key of the order holding this customer's gift card money / points.
	 */
	const HELD = 'kudora_held_order';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'classic_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'placed' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'refunded' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_exception', array( $this, 'exception' ), 20 );
		add_action( 'woocommerce_trash_order', array( $this, 'trashed' ), 20 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'trashed' ), 20 );
		add_action( 'wp_trash_post', array( $this, 'trashed_post' ), 20 );

		add_action( 'kudora_order_placed', array( $this, 'release_superseded' ), 1 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'held_order' ), 1, 0 );
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou' ), 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'before_pay' ), 1 );
		add_action( 'before_woocommerce_pay_form', array( $this, 'pay_form' ), 1 );
		add_action( 'kudora_daily_tasks', array( __CLASS__, 'release_stale' ) );
	}

	/* ---------------------------------------------------------------------
	 * Held order (session)
	 * ------------------------------------------------------------------- */

	/**
	 * Hours an unpaid (pending) order may hold gift card money and points.
	 *
	 * @return int
	 */
	public static function stale_hours() {
		/**
		 * Hours after which a pending (unpaid) order gives its gift card money and
		 * points back. Paying it later takes them again, or drops them if they are gone.
		 *
		 * @param int $hours Hours (default 24).
		 */
		return max( 1, (int) apply_filters( 'kudora_release_unpaid_after_hours', 24 ) );
	}

	/**
	 * Remember that this session's checkout placed an order holding money or points.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function remember_held( $order ) {
		if ( function_exists( 'WC' ) && WC()->session && ! is_admin() ) {
			WC()->session->set( self::HELD, (int) $order->get_id() );
		}
	}

	/**
	 * The unpaid order this session's checkout placed, if it is still awaiting
	 * payment. A paid one ends the checkout: the session's gift cards and points
	 * request are cleared.
	 *
	 * @return WC_Order|null
	 */
	public static function held_order() {
		static $cache = array();
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$id = (int) WC()->session->get( self::HELD );
		if ( ! $id ) {
			return null;
		}
		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}
		$order = wc_get_order( $id );
		if ( ! $order || 'shop_order' !== $order->get_type() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			WC()->session->set( self::HELD, null );
			$cache[ $id ] = null;
			return null;
		}
		if ( ! $order->needs_payment() ) {
			self::end_checkout( $order );
			$cache[ $id ] = null;
			return null;
		}
		$cache[ $id ] = $order;
		return $order;
	}

	/**
	 * The session's checkout is over (its order was paid, or is dead).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	protected static function end_checkout( $order ) {
		WC()->session->set( self::HELD, null );
		if ( ! $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) {
			/**
			 * The customer's checkout went through (paid, or on-hold awaiting a bank
			 * transfer): clear what it applied (gift cards, points request) so the
			 * next cart starts clean.
			 *
			 * @param WC_Order $order Order.
			 */
			do_action( 'kudora_checkout_done', $order );
		}
	}

	/**
	 * A new checkout order replaces the session's earlier unpaid one: that one
	 * gives back what it holds (it stays payable; paying it takes them again).
	 *
	 * @param WC_Order $order Order being placed.
	 * @return void
	 */
	public function release_superseded( $order ) {
		$held = self::held_order();
		if ( $held && $held->get_id() !== $order->get_id() ) {
			WC()->session->set( self::HELD, null );
			do_action( 'kudora_order_release', $held, 'superseded' );
		}
	}

	/**
	 * No longer awaiting payment: when it is the session's held order, the checkout
	 * is over.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function forget_if_held( $order ) {
		if ( function_exists( 'WC' ) && WC()->session && (int) WC()->session->get( self::HELD ) === (int) $order->get_id() ) {
			self::end_checkout( $order );
		}
	}

	/**
	 * Thank-you page (the customer's own session, also after an off-site payment).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->needs_payment() ) {
			$this->forget_if_held( $order );
		}
	}

	/**
	 * Order-pay form submitted: secure the holdings before the gateway charges.
	 * Errors stop the payment (WooCommerce checks the error notice count).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function before_pay( $order ) {
		foreach ( self::before_payment( $order ) as $message ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Order-pay page: show the true total (holdings taken back, or dropped).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function pay_form( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) {
			return;
		}
		foreach ( self::before_payment( $order ) as $message ) {
			wc_print_notice( $message, 'notice' );
		}
	}

	/**
	 * Ask the modules to secure an unpaid order's holdings.
	 *
	 * @param WC_Order $order Order.
	 * @return string[] Messages about holdings that had to be dropped.
	 */
	public static function before_payment( $order ) {
		$errors = new WP_Error();
		do_action( 'kudora_order_before_payment', $order, $errors );
		return $errors->get_error_messages();
	}

	/**
	 * Daily: pending orders unpaid for too long give their holdings back.
	 *
	 * @return void
	 */
	public static function release_stale() {
		$before = gmdate( 'Y-m-d H:i:s', time() - self::stale_hours() * HOUR_IN_SECONDS );
		foreach ( array( '_kudora_gc_state', '_kudora_points_state' ) as $meta ) {
			$ids = wc_get_orders(
				array(
					'status'       => array( 'pending' ),
					'type'         => 'shop_order',
					'limit'        => 200,
					'return'       => 'ids',
					'date_created' => '<' . strtotime( $before . ' UTC' ),
					'meta_key'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily job, bounded.
					'meta_value'   => 'debited', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- daily job, bounded.
				)
			);
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( $order && $order->needs_payment() ) {
					do_action( 'kudora_order_release', $order, 'stale' );
				}
			}
		}
	}

	/**
	 * Classic checkout hook adapter.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function classic_processed( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			$this->placed( $order );
		}
	}

	/**
	 * Checkout finished (both flows).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function placed( $order ) {
		if ( $order instanceof WC_Order ) {
			do_action( 'kudora_order_placed', $order );
		}
	}

	/**
	 * Status transitions.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function status_changed( $order_id, $from, $to, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return;
		}
		$dead = array( 'cancelled', 'failed' );
		$live = array( 'pending', 'on-hold', 'processing', 'completed' );

		if ( in_array( $from, $dead, true ) && in_array( $to, $live, true ) ) {
			do_action( 'kudora_order_reinstated', $order, $from, $to );
		}
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			do_action( 'kudora_order_paid', $order );
		}
		if ( 'completed' === $to ) {
			do_action( 'kudora_order_completed', $order );
		}
		if ( in_array( $to, $dead, true ) ) {
			do_action( 'kudora_order_voided', $order, $to );
		}
		if ( ! $order->needs_payment() ) {
			$this->forget_if_held( $order );
		}
	}

	/**
	 * Refund created.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public function refunded( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		// An order fully paid by gift card has a zero total; such refunds are never "full".
		$is_full = (float) $order->get_total() > 0 && (float) $order->get_remaining_refund_amount() <= 0.0001;
		do_action( 'kudora_order_refunded', $order, $refund, $is_full );
	}

	/**
	 * Classic checkout threw after the order was created.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function exception( $order ) {
		if ( $order instanceof WC_Order ) {
			do_action( 'kudora_order_voided', $order, 'exception' );
		}
	}

	/**
	 * An unpaid order was trashed or deleted: release what it was holding.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function trashed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'shop_order' === $order->get_type() && ! $order->is_paid() ) {
			do_action( 'kudora_order_voided', $order, 'trash' );
		}
	}

	/**
	 * Legacy (posts) storage trash adapter.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function trashed_post( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$this->trashed( $post_id );
		}
	}
}
