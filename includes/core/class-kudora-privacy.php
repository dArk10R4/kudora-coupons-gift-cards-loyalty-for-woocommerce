<?php
/**
 * Personal data exporter and eraser (Tools → Export/Erase Personal Data), plus a
 * privacy-policy suggestion. Erasing keeps balances and ledgers (they are
 * financial records) but removes names, emails and messages.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy integration.
 */
class Kudora_Privacy {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/**
	 * Register exporters.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function exporters( $exporters ) {
		$exporters['kudora'] = array(
			'exporter_friendly_name' => __( 'Kudora gift cards, points and referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register erasers.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function erasers( $erasers ) {
		$erasers['kudora'] = array(
			'eraser_friendly_name' => __( 'Kudora gift cards, points and referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Gift cards linked to an email (and its user).
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	protected function cards_for( $email ) {
		global $wpdb;
		$user  = get_user_by( 'email', $email );
		$uid   = $user ? (int) $user->ID : -1;
		$table = Kudora_DB::table( 'gift_cards' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE recipient_email = %s OR purchaser_email = %s OR owner_id = %d OR purchaser_id = %d LIMIT 500", $table, strtolower( $email ), $email, $uid, $uid ), ARRAY_A );
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$data = array();
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : -1;
		foreach ( $this->cards_for( $email ) as $c ) {
			// Export requests are confirmed from the mailbox, so cards sent to it are the
			// requester's; a card they bought for someone else is exported masked.
			$mine   = strtolower( $email ) === strtolower( (string) $c['recipient_email'] ) || $uid === (int) $c['owner_id'] || Kudora_Gift_Card_Frontend::is_buyers_own( $c );
			$data[] = array(
				'group_id'    => 'kudora-gift-cards',
				'group_label' => __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'item_id'     => 'kudora-gc-' . $c['id'],
				'data'        => array(
					array( 'name' => __( 'Code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $mine ? $c['code'] : Kudora_Gift_Card_Repo::mask( $c['code'] ) ),
					array( 'name' => __( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => Kudora_Money::plain( $c['balance'] ) ),
					array( 'name' => __( 'Recipient', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => trim( $c['recipient_name'] . ' ' . $c['recipient_email'] ) ),
					array( 'name' => __( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $c['sender_name'] ),
					array( 'name' => __( 'Message', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $c['message'] ),
				),
			);
		}
		if ( $user ) {
			$row    = Kudora_Points_Repo::row( $user->ID );
			$data[] = array(
				'group_id'    => 'kudora-points',
				'group_label' => __( 'Loyalty points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'item_id'     => 'kudora-points-' . $user->ID,
				'data'        => array(
					array( 'name' => __( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $row['balance'] ),
					array( 'name' => __( 'Lifetime earned', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $row['lifetime_earned'] ),
					array( 'name' => __( 'Referral code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => (string) $row['referral_code'] ),
					array( 'name' => __( 'Birthday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => (string) get_user_meta( $user->ID, '_kudora_birthday', true ) ),
				),
			);
			foreach ( Kudora_Points_Repo::history( $user->ID, 500 ) as $h ) {
				$data[] = array(
					'group_id'    => 'kudora-points-history',
					'group_label' => __( 'Loyalty points history', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'item_id'     => 'kudora-points-log-' . $h['id'],
					'data'        => array(
						array( 'name' => __( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $h['created_at'] ),
						array( 'name' => __( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $h['points'] ),
						array( 'name' => __( 'Action', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $h['action'] ),
						array( 'name' => __( 'Note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'value' => $h['note'] ),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase: anonymise gift card personal fields, birthday and referral emails.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;
		$removed = false;
		foreach ( $this->cards_for( $email ) as $c ) {
			$fields = array();
			if ( strtolower( $c['recipient_email'] ) === strtolower( $email ) ) {
				$fields['recipient_email'] = '';
				$fields['recipient_name']  = '';
				$fields['message']         = '';
			}
			if ( strtolower( $c['purchaser_email'] ) === strtolower( $email ) ) {
				$fields['purchaser_email'] = '';
				$fields['sender_name']     = '';
				$fields['message']         = '';
			}
			if ( $fields ) {
				Kudora_Gift_Card_Repo::update( (int) $c['id'], $fields );
				$removed = true;
			}
		}
		$user = get_user_by( 'email', $email );
		if ( $user && get_user_meta( $user->ID, '_kudora_birthday', true ) ) {
			delete_user_meta( $user->ID, '_kudora_birthday' );
			delete_user_meta( $user->ID, '_kudora_birthday_set' );
			$removed = true;
		}
		$table = Kudora_DB::table( 'referrals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$n = $wpdb->update( $table, array( 'referred_email' => '' ), array( 'referred_email' => strtolower( $email ) ) );
		if ( $n ) {
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( __( 'Gift card balances and points history are kept as financial records; names, emails, messages and birthdays were removed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ),
			'done'           => true,
		);
	}

	/**
	 * Privacy policy suggestion.
	 *
	 * @return void
	 */
	public function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Kudora – Coupons, Gift Cards & Loyalty',
			wp_kses_post(
				'<p>' . __( 'When you buy a gift card we store the recipient’s name and email, your name and your message so we can deliver the card. We keep a history of gift card balances and loyalty points for as long as they can be used. If you add your birthday we store the day and month to give you birthday points. If you follow a friend’s referral link, a cookie remembers it for a limited time so your friend can be rewarded for your first order.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</p>'
			)
		);
	}
}
