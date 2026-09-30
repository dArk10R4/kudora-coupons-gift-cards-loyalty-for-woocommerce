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
