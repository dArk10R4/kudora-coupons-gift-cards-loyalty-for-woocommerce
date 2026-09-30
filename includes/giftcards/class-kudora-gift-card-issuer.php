<?php
/**
 * Creates gift cards when an order that bought them is paid (codes are revealed only
 * after payment — Booknetic rule), delivers them now or on the chosen date, and
 * disables unused cards when the purchase is cancelled or refunded.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card issuing and delivery.
 */
class Kudora_Gift_Card_Issuer {

	/**
	 * Scheduled delivery hook.
	 */
	const DELIVER_HOOK = 'kudora_deliver_gift_card';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'kudora_order_paid', array( $this, 'issue_for_order' ), 10 );
		add_action( 'kudora_order_voided', array( $this, 'void_for_order' ), 20, 2 );
		add_action( 'kudora_order_refunded', array( $this, 'refunded' ), 20, 3 );
		add_action( self::DELIVER_HOOK, array( $this, 'deliver' ) );
		add_action( 'kudora_daily_tasks', array( 'Kudora_Gift_Card_Repo', 'expire_due' ) );
	}

	/**
	 * Issue the cards bought in an order (idempotent per line and unit).
	 *
	 * @param WC_Order $order Order.
	 * @return int[] Ids of newly issued cards.
	 */
	public function issue_for_order( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof WC_Order_Item_Product && is_array( $item->get_meta( '_kudora_gc_data' ) ) ) {
				$lines[ $item_id ] = $item;
			}
		}
		if ( ! $lines ) {
			return array();
		}
		$lock = 'issue_' . $order->get_id();
		if ( ! Kudora_DB::lock( $lock ) ) {
			return array();
		}
		$new = array();
		try {
			$this->reenable_voided( $order );
			foreach ( $lines as $item_id => $item ) {
				$new = array_merge( $new, $this->issue_for_line( $order, $item ) );
			}
		} finally {
			Kudora_DB::unlock( $lock );
		}
		if ( $new ) {
			$labels = array();
			foreach ( $new as $id ) {
				$card     = Kudora_Gift_Card_Repo::get( $id );
				$labels[] = Kudora_Gift_Card_Repo::mask( $card['code'] ) . ' (' . Kudora_Money::plain( $card['initial_amount'] ) . ')';
			}
			/* translators: %s: list of issued gift cards */
			$order->add_order_note( sprintf( __( 'Gift card(s) issued: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), implode( ', ', $labels ) ) );
			foreach ( $new as $id ) {
				$this->schedule_or_deliver( $id );
			}
		}
		return $new;
	}

	/**
	 * Issue the missing cards of one line.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Line.
	 * @return int[]
	 */
	protected function issue_for_line( $order, $item ) {
		$gc      = (array) $item->get_meta( '_kudora_gc_data' );
		$ids     = array();
		$qty     = max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
		$product = $item->get_product();
		$parent  = $product && $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$s       = $parent ? Kudora_Gift_Card_Product::settings( $parent ) : array(
			'expiry_days'  => Kudora_Settings::int( 'gc_default_expiry_days' ),
			'restrictions' => array(),
		);
		$amount = isset( $gc['amount'] ) ? (float) $gc['amount'] : 0.0;
		if ( $amount <= 0 && $qty > 0 ) {
			$amount = Kudora_Money::round( (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() ) );
		}
		$new = array();
		for ( $unit = 0; $unit < $qty; $unit++ ) {
			// The issue ledger key, not the line meta (which a parallel "paid" event may
			// not see yet), says whether this unit already has its card.
			$key    = sprintf( 'issue:item:%d:%d', $item->get_id(), $unit );
			$issued = Kudora_Gift_Card_Repo::card_for_key( $key );
			if ( $issued ) {
				$ids[] = $issued;
				continue;
			}
			$deliver_at = null;
			if ( ! empty( $gc['deliver_date'] ) && $gc['deliver_date'] > wp_date( 'Y-m-d' ) ) {
				$local      = new DateTimeImmutable( $gc['deliver_date'] . ' 08:00:00', wp_timezone() );
				$deliver_at = $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			$send_to_other = isset( $gc['send_to'] ) && 'other' === $gc['send_to'];
			try {
				$id = Kudora_Gift_Card_Repo::create(
					array(
						'initial_amount'  => $amount,
						'expires_on'      => $s['expiry_days'] > 0 ? wp_date( 'Y-m-d', time() + $s['expiry_days'] * DAY_IN_SECONDS ) : null,
						'design_id'       => isset( $gc['design_id'] ) ? (int) $gc['design_id'] : 0,
						'product_id'      => $parent ? $parent->get_id() : 0,
						'order_id'        => $order->get_id(),
						'order_item_id'   => $item->get_id(),
						'purchaser_id'    => $order->get_customer_id(),
						'purchaser_email' => $order->get_billing_email(),
						'owner_id'        => $send_to_other ? 0 : $order->get_customer_id(),
						'recipient_name'  => $send_to_other ? $gc['recipient_name'] : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'recipient_email' => $send_to_other ? $gc['recipient_email'] : $order->get_billing_email(),
						'sender_name'     => isset( $gc['sender_name'] ) ? $gc['sender_name'] : '',
						'message'         => isset( $gc['message'] ) ? $gc['message'] : '',
						'deliver_at'      => $deliver_at,
						'is_physical'     => ! empty( $gc['physical'] ),
						'restrictions'    => $s['restrictions'],
						'source'          => 'purchase',
						/* translators: %s: order number */
						'ledger_note'     => sprintf( __( 'Bought in order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
					),
					$key
				);
			} catch ( RuntimeException $e ) {
				$order->add_order_note( 'Kudora: ' . esc_html( $e->getMessage() ) );
				break;
			}
			if ( ! $id ) {
				break;
			}
			$ids[] = $id;
			$new[] = $id;
		}
		$known = array_filter( array_map( 'intval', (array) $item->get_meta( '_kudora_gc_ids' ) ) );
		$ids   = array_values( array_unique( array_merge( $known, $ids ) ) );
		if ( $ids !== $known ) {
			$item->update_meta_data( '_kudora_gc_ids', $ids );
			$item->save_meta_data();
		}
		return $new;
	}

	/**
	 * Email now, or schedule for the delivery date.
	 *
	 * @param int $card_id Card id.
	 * @return void
	 */
	public function schedule_or_deliver( $card_id ) {
		$card = Kudora_Gift_Card_Repo::get( $card_id );
		if ( ! $card || $card['is_physical'] ) {
			return;
		}
		if ( ! empty( $card['deliver_at'] ) && strtotime( $card['deliver_at'] . ' UTC' ) > time() ) {
			Kudora_Scheduler::single( strtotime( $card['deliver_at'] . ' UTC' ), self::DELIVER_HOOK, array( (int) $card_id ) );
			return;
		}
		$this->deliver( $card_id );
	}

	/**
	 * Send the gift card email (and tell the purchaser when it went to someone else).
	 *
	 * @param int  $card_id Card id.
	 * @param bool $resend  Admin resend: ignore the delivered flag.
	 * @return bool
	 */
	public function deliver( $card_id, $resend = false ) {
		$card = Kudora_Gift_Card_Repo::get( (int) $card_id );
		if ( ! $card || ! in_array( $card['status'], array( 'active', 'pending' ), true ) || ! is_email( $card['recipient_email'] ) ) {
			return false;
		}
		if ( ! $resend && ! empty( $card['delivered_at'] ) ) {
			return false;
		}
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$sent   = false;
		if ( isset( $emails['Kudora_Email_Gift_Card'] ) ) {
			$sent = $emails['Kudora_Email_Gift_Card']->trigger( $card['id'] );
		}
		Kudora_Gift_Card_Repo::update( $card['id'], array( 'delivered_at' => Kudora_DB::now() ) );
		if ( ! $resend && $card['purchaser_email'] && strtolower( $card['purchaser_email'] ) !== strtolower( $card['recipient_email'] ) && isset( $emails['Kudora_Email_Gift_Card_Sent'] ) ) {
			$emails['Kudora_Email_Gift_Card_Sent']->trigger( $card['id'] );
		}
		return (bool) $sent;
	}

	/**
	 * Order cancelled/failed after issuing: disable cards that were never used.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Why.
	 * @return void
	 */
	public function void_for_order( $order, $status = '' ) {
		$disabled = $this->disable_unused( $order, null );
		if ( $disabled ) {
			$order->update_meta_data( '_kudora_gc_voided', array_values( array_unique( array_merge( (array) $order->get_meta( '_kudora_gc_voided' ), $disabled ) ) ) );
			$order->save_meta_data();
		}
	}

	/**
	 * Re-enable cards that were disabled only because the order was voided, once the
	 * order is paid again.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	protected function reenable_voided( $order ) {
		$ids = array_filter( array_map( 'intval', (array) $order->get_meta( '_kudora_gc_voided' ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $card_id ) {
			$card = Kudora_Gift_Card_Repo::get( $card_id );
			if ( $card && 'disabled' === $card['status'] ) {
				Kudora_Gift_Card_Repo::set_status( $card_id, 'active', __( 'Purchase order paid again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				$this->schedule_or_deliver( $card_id );
			}
		}
		$order->delete_meta_data( '_kudora_gc_voided' );
		$order->save_meta_data();
	}

	/**
	 * Refunds: full refund disables all unused cards; a partial refund of gift card
	 * lines disables that many unused cards of the line.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $is_full Full refund.
	 * @return void
	 */
	public function refunded( $order, $refund, $is_full ) {
		if ( $is_full ) {
			$this->disable_unused( $order, null );
			return;
		}
		$per_line = array();
		foreach ( $refund->get_items() as $refund_item ) {
			$orig = (int) $refund_item->get_meta( '_refunded_item_id' );
			if ( $orig && $refund_item->get_quantity() ) {
				$per_line[ $orig ] = ( isset( $per_line[ $orig ] ) ? $per_line[ $orig ] : 0 ) + abs( (int) $refund_item->get_quantity() );
			}
		}
		if ( $per_line ) {
			$this->disable_unused( $order, $per_line );
		}
	}

	/**
	 * Disable unused cards of an order.
	 *
	 * @param WC_Order       $order    Order.
	 * @param array|null     $per_line item_id => how many to disable (null = all).
	 * @return int[] Disabled card ids.
	 */
	protected function disable_unused( $order, $per_line ) {
		$disabled = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$ids = array_filter( array_map( 'intval', (array) $item->get_meta( '_kudora_gc_ids' ) ) );
			if ( ! $ids ) {
				continue;
			}
			$limit = null === $per_line ? count( $ids ) : ( isset( $per_line[ $item_id ] ) ? (int) $per_line[ $item_id ] : 0 );
			foreach ( array_reverse( $ids ) as $card_id ) {
				if ( $limit <= 0 ) {
					break;
				}
				$card = Kudora_Gift_Card_Repo::get( $card_id );
				if ( ! $card || 'disabled' === $card['status'] ) {
					continue;
				}
				if ( abs( $card['balance'] - $card['initial_amount'] ) < 0.00001 ) {
					/* translators: %s: order number */
					Kudora_Gift_Card_Repo::set_status( $card_id, 'disabled', sprintf( __( 'Purchase order #%s cancelled or refunded.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ) );
					Kudora_Scheduler::cancel( self::DELIVER_HOOK, array( (int) $card_id ) );
					$disabled[] = (int) $card_id;
					/* translators: %s: masked code */
					$order->add_order_note( sprintf( __( 'Gift card %s disabled.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Gift_Card_Repo::mask( $card['code'] ) ) );
				} else {
					/* translators: %s: masked code */
					$order->add_order_note( sprintf( __( 'Gift card %s was already used, so it was left active. Adjust its balance manually if needed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Gift_Card_Repo::mask( $card['code'] ) ) );
				}
				--$limit;
			}
		}
		return $disabled;
	}
}
