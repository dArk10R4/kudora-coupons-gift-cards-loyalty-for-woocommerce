<?php
/**
 * Gift card storage and every balance movement. The ledger is the audit trail; the
 * `balance` column is a cache updated in the same transaction. Debits are
 * conditional atomic updates, so two orders can never spend the same money.
 *
 * Balance = initial amount − SUM(redeemed) + SUM(restored/refunded) ± adjustments
 * (Booknetic: `amount − SUM(used)`).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift card repository.
 */
class Kudora_Gift_Card_Repo {

	/**
	 * Per-request cache of rows keyed by code.
	 *
	 * @var array<string,array|null>
	 */
	protected static $cache = array();

	/**
	 * Card statuses.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'active'   => __( 'Active', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'pending'  => __( 'Pending delivery', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'disabled' => __( 'Disabled', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'expired'  => __( 'Expired', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Cards table.
	 *
	 * @return string
	 */
	public static function table() {
		return Kudora_DB::table( 'gift_cards' );
	}

	/**
	 * Ledger table.
	 *
	 * @return string
	 */
	public static function ledger_table() {
		return Kudora_DB::table( 'gift_card_ledger' );
	}

	/**
	 * Drop the per-request cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Normalise a DB row.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	protected static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'design_id', 'product_id', 'order_id', 'order_item_id', 'purchaser_id', 'owner_id', 'is_physical' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['initial_amount'] = (float) $row['initial_amount'];
		$row['balance']        = (float) $row['balance'];
		$row['restrictions']   = $row['restrictions'] ? json_decode( $row['restrictions'], true ) : array();
		if ( ! is_array( $row['restrictions'] ) ) {
			$row['restrictions'] = array();
		}
		return $row;
	}

	/**
	 * Get by id.
	 *
	 * @param int $id Card id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; always fresh for money.
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) );
	}

	/**
	 * Get by code (case-insensitive; codes are stored uppercase).
	 *
	 * @param string $code  Code.
	 * @param bool   $fresh Skip the per-request cache.
	 * @return array|null
	 */
	public static function get_by_code( $code, $fresh = false ) {
		global $wpdb;
		$code = Kudora_Code_Generator::normalize_input( $code );
		if ( '' === $code ) {
			return null;
		}
		if ( ! $fresh && array_key_exists( $code, self::$cache ) ) {
			return self::$cache[ $code ];
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, per-request cache above.
		$row                  = self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A ) );
		self::$cache[ $code ] = $row;
		return $row;
	}

	/**
	 * Whether a code is taken.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function code_exists( $code ) {
		return null !== self::get_by_code( $code, true );
	}

	/**
	 * Today's date in the site timezone.
	 *
	 * @return string Y-m-d.
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Whether a card can be redeemed now. Sets $error to a customer-facing message.
	 *
	 * @param array|null $card  Card.
	 * @param string     $error Out: reason.
	 * @return bool
	 */
	public static function is_usable( $card, &$error = '' ) {
		if ( ! $card ) {
			$error = __( 'This gift card code is not valid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			return false;
		}
		if ( 'active' !== $card['status'] && 'pending' !== $card['status'] ) {
			$error = 'expired' === $card['status']
				? __( 'This gift card has expired.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' )
				: __( 'This gift card is not active.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			return false;
		}
		if ( self::is_expired( $card ) ) {
			$error = __( 'This gift card has expired.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			return false;
		}
		if ( $card['balance'] <= 0 ) {
			$error = __( 'This gift card has no balance left.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			return false;
		}
		return true;
	}

	/**
	 * A card with an expiry date stays valid through the whole of that day
	 * (Booknetic rule) and is rejected from the following midnight.
	 *
	 * @param array $card Card.
	 * @return bool
	 */
	public static function is_expired( $card ) {
		return ! empty( $card['expires_on'] ) && $card['expires_on'] < self::today();
	}

	/**
	 * Create a card and its `issue` ledger row.
	 *
	 * @param array  $data Card fields (code optional: generated when empty).
	 * @param string $key  Optional idempotency key for the issue row.
	 * @return int Card id.
	 * @throws RuntimeException On failure.
	 */
	public static function create( array $data, $key = null ) {
		global $wpdb;
		$amount = Kudora_Money::round( isset( $data['initial_amount'] ) ? $data['initial_amount'] : 0 );
		if ( $amount <= 0 ) {
			throw new RuntimeException( 'Gift card amount must be greater than zero.' );
		}
		$code = isset( $data['code'] ) ? Kudora_Code_Generator::normalize_input( $data['code'] ) : '';
		if ( '' === $code ) {
			$code = Kudora_Code_Generator::unique( self::code_format(), array( __CLASS__, 'code_exists' ) );
		} elseif ( self::code_exists( $code ) ) {
			throw new RuntimeException( 'Gift card code already exists.' );
		}
		$now = Kudora_DB::now();
		$row = array(
			'code'            => $code,
			'initial_amount'  => $amount,
			'balance'         => $amount,
			'currency'        => get_woocommerce_currency(),
			'status'          => isset( $data['status'] ) && isset( self::statuses()[ $data['status'] ] ) ? $data['status'] : 'active',
			'expires_on'      => ! empty( $data['expires_on'] ) ? $data['expires_on'] : null,
			'design_id'       => isset( $data['design_id'] ) ? (int) $data['design_id'] : 0,
			'product_id'      => isset( $data['product_id'] ) ? (int) $data['product_id'] : 0,
			'order_id'        => isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
			'order_item_id'   => isset( $data['order_item_id'] ) ? (int) $data['order_item_id'] : 0,
			'purchaser_id'    => isset( $data['purchaser_id'] ) ? (int) $data['purchaser_id'] : 0,
			'purchaser_email' => isset( $data['purchaser_email'] ) ? sanitize_email( $data['purchaser_email'] ) : '',
			'owner_id'        => isset( $data['owner_id'] ) ? (int) $data['owner_id'] : 0,
			'recipient_name'  => isset( $data['recipient_name'] ) ? sanitize_text_field( $data['recipient_name'] ) : '',
			'recipient_email' => isset( $data['recipient_email'] ) ? strtolower( sanitize_email( $data['recipient_email'] ) ) : '',
			'sender_name'     => isset( $data['sender_name'] ) ? sanitize_text_field( $data['sender_name'] ) : '',
			'message'         => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '',
			'deliver_at'      => ! empty( $data['deliver_at'] ) ? $data['deliver_at'] : null,
			'is_physical'     => ! empty( $data['is_physical'] ) ? 1 : 0,
			'restrictions'    => ! empty( $data['restrictions'] ) ? wp_json_encode( Kudora_Scope::sanitize( $data['restrictions'] ) ) : null,
			'source'          => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'manual',
			'note'            => isset( $data['note'] ) ? substr( sanitize_text_field( $data['note'] ), 0, 255 ) : '',
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		return Kudora_DB::transaction(
			function () use ( $wpdb, $row, $amount, $key, $data ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
				$ok = $wpdb->insert( self::table(), $row );
				Kudora_DB::check( $ok, 'insert gift card' );
				$id = (int) $wpdb->insert_id;
				self::write_ledger( $id, 'issue', $amount, $amount, isset( $data['order_id'] ) ? (int) $data['order_id'] : 0, $key, isset( $data['ledger_note'] ) ? $data['ledger_note'] : '' );
				self::flush_cache();
				/**
				 * Fires after a gift card is created.
				 *
				 * @param int   $id  Card id.
				 * @param array $row Row data.
				 */
				do_action( 'kudora_gift_card_created', $id, $row );
				return $id;
			}
		);
	}

	/**
	 * Update non-balance fields.
	 *
	 * @param int   $id   Card id.
	 * @param array $data Fields.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array( 'status', 'expires_on', 'design_id', 'owner_id', 'recipient_name', 'recipient_email', 'sender_name', 'message', 'deliver_at', 'delivered_at', 'is_physical', 'restrictions', 'note', 'purchaser_email' );
		$row     = array_intersect_key( $data, array_flip( $allowed ) );
		if ( array_key_exists( 'restrictions', $row ) ) {
			$row['restrictions'] = $row['restrictions'] ? wp_json_encode( Kudora_Scope::sanitize( $row['restrictions'] ) ) : null;
		}
		if ( array_key_exists( 'expires_on', $row ) && ! $row['expires_on'] ) {
			$row['expires_on'] = null;
		}
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = Kudora_DB::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		self::flush_cache();
	}

	/**
	 * Spend from a card. Atomic: succeeds only if the card is active, unexpired and
	 * still holds at least $amount. Replaying the same key is a no-op success.
	 *
	 * @param int    $id       Card id.
	 * @param float  $amount   Positive amount.
	 * @param string $key      Idempotency key.
	 * @param int    $order_id Order id.
	 * @param string $note     Note.
	 * @return true|WP_Error
	 */
	public static function debit( $id, $amount, $key, $order_id = 0, $note = '' ) {
		global $wpdb;
		$amount = Kudora_Money::round( $amount );
		if ( $amount <= 0 ) {
			return true;
		}
		try {
			return Kudora_DB::transaction(
				function () use ( $wpdb, $id, $amount, $key, $order_id, $note ) {
					$ledger_id = self::write_ledger( $id, 'redeem', -$amount, 0, $order_id, $key, $note );
					if ( 0 === $ledger_id ) {
						return true; // Already applied.
					}
					$table = self::table();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, conditional atomic debit.
					$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET balance = balance - %s, updated_at = %s WHERE id = %d AND status IN ('active','pending') AND balance >= %s AND (expires_on IS NULL OR expires_on >= %s)", wc_format_decimal( $amount, 4 ), Kudora_DB::now(), $id, wc_format_decimal( $amount, 4 ), self::today() ) );
					if ( 1 !== (int) $affected ) {
						throw new DomainException( 'insufficient' );
					}
					self::finish_ledger( $ledger_id, $id );
					return true;
				}
			);
		} catch ( DomainException $e ) {
			return new WP_Error( 'kudora_gc_insufficient', __( 'The gift card balance is no longer sufficient.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'kudora_gc_db', $e->getMessage() );
		} finally {
			self::flush_cache();
		}
	}

	/**
	 * Put money back on a card (restore after a void, refund to card, admin top-up).
	 *
	 * @param int    $id       Card id.
	 * @param float  $amount   Positive amount.
	 * @param string $type     restore|refund|adjust|import.
	 * @param string $key      Idempotency key (null for none).
	 * @param int    $order_id Order id.
	 * @param string $note     Note.
	 * @return bool True when applied (false when replayed or failed).
	 */
	public static function credit( $id, $amount, $type, $key, $order_id = 0, $note = '' ) {
		global $wpdb;
		$amount = Kudora_Money::round( $amount );
		if ( $amount <= 0 ) {
			return false;
		}
		try {
			$done = Kudora_DB::transaction(
				function () use ( $wpdb, $id, $amount, $type, $key, $order_id, $note ) {
					$ledger_id = self::write_ledger( $id, $type, $amount, 0, $order_id, $key, $note );
					if ( 0 === $ledger_id ) {
						return false;
					}
					$table = self::table();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
					$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET balance = balance + %s, updated_at = %s WHERE id = %d", wc_format_decimal( $amount, 4 ), Kudora_DB::now(), $id ) );
					if ( 1 !== (int) $affected ) {
						throw new RuntimeException( 'card missing' );
					}
					self::finish_ledger( $ledger_id, $id );
					return true;
				}
			);
		} catch ( RuntimeException $e ) {
			$done = false;
		}
		self::flush_cache();
		return (bool) $done;
	}

	/**
	 * Admin: set a new balance (never below zero), logged as an `adjust` row.
	 *
	 * @param int    $id      Card id.
	 * @param float  $balance New balance.
	 * @param string $note    Note.
	 * @return bool
	 */
	public static function set_balance( $id, $balance, $note = '' ) {
		global $wpdb;
		$balance = max( 0.0, Kudora_Money::round( $balance ) );
		try {
			Kudora_DB::transaction(
				function () use ( $wpdb, $id, $balance, $note ) {
					$table = self::table();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- lock the row.
					$current = $wpdb->get_var( $wpdb->prepare( "SELECT balance FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
					if ( null === $current ) {
						throw new RuntimeException( 'card missing' );
					}
					$diff = Kudora_Money::round( $balance - (float) $current );
					if ( abs( $diff ) < 0.00001 ) {
						return;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
					$wpdb->update( $table, array( 'balance' => $balance, 'updated_at' => Kudora_DB::now() ), array( 'id' => (int) $id ) );
					self::write_ledger( $id, 'adjust', $diff, $balance, 0, null, $note );
				}
			);
		} catch ( RuntimeException $e ) {
			return false;
		}
		self::flush_cache();
		return true;
	}

	/**
	 * Change status, with a zero-amount ledger row for the audit trail.
	 *
	 * @param int    $id     Card id.
	 * @param string $status New status.
	 * @param string $note   Note.
	 * @return void
	 */
	public static function set_status( $id, $status, $note = '' ) {
		$card = self::get( $id );
		if ( ! $card || $card['status'] === $status || ! isset( self::statuses()[ $status ] ) ) {
			return;
		}
		self::update( $id, array( 'status' => $status ) );
		self::write_ledger( $id, 'status', 0, $card['balance'], 0, null, trim( $status . ' ' . $note ) );
	}

	/**
	 * Insert a ledger row. Returns 0 when the idempotency key already exists.
	 *
	 * @param int         $card_id       Card id.
	 * @param string      $type          Type.
	 * @param float       $amount        Signed amount.
	 * @param float       $balance_after Balance after (finalised later for movements).
	 * @param int         $order_id      Order id.
	 * @param string|null $key           Idempotency key.
	 * @param string      $note          Note.
	 * @return int Ledger row id or 0.
	 */
	protected static function write_ledger( $card_id, $type, $amount, $balance_after, $order_id, $key, $note ) {
		return Kudora_DB::insert_ignore(
			self::ledger_table(),
			array(
				'gift_card_id'    => (int) $card_id,
				'type'            => $type,
				'amount'          => (float) $amount,
				'balance_after'   => (float) $balance_after,
				'order_id'        => (int) $order_id,
				'user_id'         => (int) get_current_user_id(),
				'note'            => substr( (string) $note, 0, 255 ),
				'idempotency_key' => $key ? substr( (string) $key, 0, 120 ) : null,
				'created_at'      => Kudora_DB::now(),
			)
		);
	}

	/**
	 * Stamp the resulting balance on a ledger row.
	 *
	 * @param int $ledger_id Ledger id.
	 * @param int $card_id   Card id.
	 * @return void
	 */
	protected static function finish_ledger( $ledger_id, $card_id ) {
		global $wpdb;
		$cards  = self::table();
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$wpdb->query( $wpdb->prepare( "UPDATE {$ledger} l JOIN {$cards} c ON c.id = l.gift_card_id SET l.balance_after = c.balance WHERE l.id = %d AND c.id = %d", $ledger_id, $card_id ) );
	}

	/**
	 * Ledger rows for a card, newest first.
	 *
	 * @param int $id Card id.
	 * @return array[]
	 */
	public static function ledger( $id ) {
		global $wpdb;
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ledger} WHERE gift_card_id = %d ORDER BY id DESC", $id ), ARRAY_A );
	}

	/**
	 * Amount spent on orders (redeemed minus restored).
	 *
	 * @param int $id Card id.
	 * @return float
	 */
	public static function spent( $id ) {
		global $wpdb;
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$sum = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$ledger} WHERE gift_card_id = %d AND type IN ('redeem','restore')", $id ) );
		return Kudora_Money::round( -$sum );
	}

	/**
	 * Admin list query.
	 *
	 * @param array $args search, status, source, order_id, owner_id, email, per_page, page, orderby, order.
	 * @return array{rows:array[],total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(code LIKE %s OR recipient_email LIKE %s OR purchaser_email LIKE %s OR recipient_name LIKE %s)';
			array_push( $vals, $like, $like, $like, $like );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'source = %s';
			$vals[]  = $args['source'];
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where[] = 'order_id = %d';
			$vals[]  = (int) $args['order_id'];
		}
		if ( ! empty( $args['design_id'] ) ) {
			$where[] = 'design_id = %d';
			$vals[]  = (int) $args['design_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $args['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $args['customer'] ) ) {
			$user    = get_userdata( (int) $args['customer'] );
			$where[] = '(owner_id = %d OR purchaser_id = %d OR recipient_email = %s)';
			array_push( $vals, (int) $args['customer'], (int) $args['customer'], $user ? strtolower( $user->user_email ) : '-' );
		}
		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'id', 'code', 'balance', 'initial_amount', 'expires_on', 'created_at' ), true ) ? $args['orderby'] : 'id';
		$order    = isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table; placeholders built above.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Cards visible to a customer: owned, bought, or sent to their email.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function for_customer( $user_id ) {
		$res = self::query(
			array(
				'customer' => $user_id,
				'per_page' => 200,
			)
		);
		return $res['rows'];
	}

	/**
	 * Outstanding liability: balance on active, unexpired cards.
	 *
	 * @return float
	 */
	public static function liability() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(balance),0) FROM {$table} WHERE status IN ('active','pending') AND (expires_on IS NULL OR expires_on >= %s)", self::today() ) );
	}

	/**
	 * Gift card code format from settings.
	 *
	 * @return array
	 */
	public static function code_format() {
		return array(
			'prefix'  => Kudora_Settings::get( 'gc_code_prefix' ),
			'suffix'  => Kudora_Settings::get( 'gc_code_suffix' ),
			'charset' => Kudora_Settings::get( 'gc_code_charset' ),
			'length'  => Kudora_Settings::int( 'gc_code_length' ),
			'group'   => Kudora_Settings::int( 'gc_code_group' ),
		);
	}

	/**
	 * Masked code for display: last 4 characters only.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function mask( $code ) {
		$code = (string) $code;
		return strlen( $code ) > 4 ? '••••' . substr( $code, -4 ) : $code;
	}

	/**
	 * Mark cards whose expiry date has passed as expired (daily job).
	 *
	 * @return int Number of cards expired.
	 */
	public static function expire_due() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'active' AND expires_on IS NOT NULL AND expires_on < %s LIMIT 500", self::today() ) );
		foreach ( $ids as $id ) {
			self::set_status( (int) $id, 'expired', __( 'Expiry date passed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		return count( $ids );
	}
}
