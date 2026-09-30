<?php
/**
 * Points balances and ledger — the single choke point for every points movement
 * (Booknetic `CustomerPointService::makeAdjustment`).
 *
 * - Every credit is a "lot" with `remaining` and an optional `expires_at`; debits
 *   consume lots first-in-first-out (soonest-expiring first).
 * - Debits are conditional atomic updates: a balance can never go negative.
 * - Idempotency keys (UNIQUE) make replays no-ops (Booknetic entity/id/action triple).
 * - The tier is recalculated on every change (highest tier whose minimum ≤ points).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points repository.
 */
class Kudora_Points_Repo {

	/**
	 * Actions that count as "earned" for lifetime totals.
	 *
	 * @var string[]
	 */
	const EARN_ACTIONS = array( 'earned', 'signup', 'review', 'birthday', 'referral', 'bonus', 'admin', 'import' );

	/**
	 * Per-request cache of rows.
	 *
	 * @var array<int,array>
	 */
	protected static $rows = array();

	/**
	 * Tier changes to announce after the transaction commits.
	 *
	 * @var array[]
	 */
	protected static $tier_events = array();

	/**
	 * Balances table.
	 *
	 * @return string
	 */
	public static function table() {
		return Kudora_DB::table( 'points' );
	}

	/**
	 * Ledger table.
	 *
	 * @return string
	 */
	public static function ledger_table() {
		return Kudora_DB::table( 'points_ledger' );
	}

	/**
	 * Human labels for ledger actions.
	 *
	 * @return array<string,string>
	 */
	public static function action_labels() {
		return array(
			'earned'   => __( 'Earned', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'redeemed' => __( 'Redeemed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'admin'    => __( 'Adjusted by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'expired'  => __( 'Expired', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'reversed' => __( 'Reversed (refund/cancel)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'restored' => __( 'Returned (order cancelled)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'signup'   => __( 'Sign-up bonus', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'review'   => __( 'Product review', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'birthday' => __( 'Birthday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'referral' => __( 'Referral', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'reward'   => __( 'Reward claimed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'bonus'    => __( 'Bonus', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'import'   => __( 'Imported', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	/**
	 * Balance row (defaults when the customer has none yet).
	 *
	 * @param int  $user_id User id.
	 * @param bool $fresh   Skip cache.
	 * @return array{user_id:int,balance:int,lifetime_earned:int,tier_id:string,referral_code:?string}
	 */
	public static function row( $user_id, $fresh = false ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $fresh && isset( self::$rows[ $user_id ] ) ) {
			return self::$rows[ $user_id ];
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, per-request cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d", $table, $user_id ), ARRAY_A );
		$row = $row ? $row : array(
			'user_id'          => $user_id,
			'balance'          => 0,
			'lifetime_earned'  => 0,
			'tier_id'          => '',
			'referral_code'    => null,
			'last_activity_at' => null,
		);
		$row['user_id']         = (int) $row['user_id'];
		$row['balance']         = (int) $row['balance'];
		$row['lifetime_earned'] = (int) $row['lifetime_earned'];
		self::$rows[ $user_id ] = $row;
		return $row;
	}

	/**
	 * Current balance.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function balance( $user_id ) {
		return $user_id ? self::row( $user_id )['balance'] : 0;
	}

	/**
	 * Tiers sorted by minimum points (ascending).
	 *
	 * @return array[]
	 */
	public static function tiers() {
		$tiers = get_option( 'kudora_loyalty_tiers', array() );
		$tiers = is_array( $tiers ) ? $tiers : array();
		foreach ( $tiers as &$t ) {
			$t['min_points'] = (int) ( isset( $t['min_points'] ) ? $t['min_points'] : 0 );
			$t['multiplier'] = (float) ( isset( $t['multiplier'] ) && '' !== $t['multiplier'] ? $t['multiplier'] : 1 );
			$t['id']         = (string) $t['id'];
		}
		unset( $t );
		usort(
			$tiers,
			function ( $a, $b ) {
				return $a['min_points'] <=> $b['min_points'];
			}
		);
		return $tiers;
	}

	/**
	 * Tier for a points figure (Booknetic `calculateTierId`): highest minimum ≤ points.
	 *
	 * @param int $points Points.
	 * @return array|null
	 */
	public static function tier_for_points( $points ) {
		$found = null;
		foreach ( self::tiers() as $t ) {
			if ( $t['min_points'] <= (int) $points ) {
				$found = $t;
			}
		}
		return $found;
	}

	/**
	 * The figure tiers are based on (lifetime earned by default, or the balance).
	 *
	 * @param array $row Balance row.
	 * @return int
	 */
	public static function tier_points( $row ) {
		return 'balance' === Kudora_Settings::get( 'loyalty_tier_basis' ) ? (int) $row['balance'] : (int) $row['lifetime_earned'];
	}

	/**
	 * A customer's tier.
	 *
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public static function tier_of( $user_id ) {
		return $user_id ? self::tier_for_points( self::tier_points( self::row( $user_id ) ) ) : null;
	}

	/**
	 * Next tier and points still needed.
	 *
	 * @param int $user_id User id.
	 * @return array{tier:?array,needed:int,current_points:int}
	 */
	public static function next_tier( $user_id ) {
		$points = self::tier_points( self::row( $user_id ) );
		foreach ( self::tiers() as $t ) {
			if ( $t['min_points'] > $points ) {
				return array(
					'tier'           => $t,
					'needed'         => $t['min_points'] - $points,
					'current_points' => $points,
				);
			}
		}
		return array(
			'tier'           => null,
			'needed'         => 0,
			'current_points' => $points,
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * Credit points (a new lot).
	 *
	 * @param int    $user_id User id.
	 * @param int    $points  Positive points.
	 * @param string $action  Ledger action.
	 * @param array  $opts    entity_type, entity_id, note, key, expires (bool, default true), lifetime (bool, default: action is an earn action).
	 * @return int Ledger id, 0 when replayed or nothing to do.
	 */
	public static function add( $user_id, $points, $action, array $opts = array() ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( $user_id <= 0 || $points <= 0 ) {
			return 0;
		}
		$opts     = wp_parse_args(
			$opts,
			array(
				'entity_type' => '',
				'entity_id'   => 0,
				'note'        => '',
				'key'         => null,
				'expires'     => true,
				'lifetime'    => in_array( $action, self::EARN_ACTIONS, true ),
			)
		);
		$days     = Kudora_Settings::int( 'loyalty_expiry_days' );
		$expires  = $opts['expires'] && $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;
		$lifetime = $opts['lifetime'] ? $points : 0;

		$before = self::row( $user_id, true );
		try {
			$id = Kudora_DB::transaction(
				function () use ( $wpdb, $user_id, $points, $action, $opts, $expires, $lifetime ) {
					$id = self::write_ledger( $user_id, $points, $points, $action, $opts, $expires );
					if ( 0 === $id ) {
						return 0;
					}
					$table = self::table();
					$now   = Kudora_DB::now();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, atomic upsert.
					$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (user_id, balance, lifetime_earned, tier_id, last_activity_at, updated_at) VALUES (%d, %d, %d, '', %s, %s) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), lifetime_earned = lifetime_earned + VALUES(lifetime_earned), last_activity_at = VALUES(last_activity_at), updated_at = VALUES(updated_at)", $table, $user_id, $points, $lifetime, $now, $now ) );
					Kudora_DB::check( $ok, 'points add' );
					self::sync_tier( $user_id );
					return $id;
				}
			);
		} catch ( RuntimeException $e ) {
			$id = 0;
		}
		self::after_change( $user_id, $before );
		return (int) $id;
	}

	/**
	 * Debit points (consumes lots FIFO). Fails when the balance is too low.
	 *
	 * @param int    $user_id User id.
	 * @param int    $points  Positive points.
	 * @param string $action  Ledger action.
	 * @param array  $opts    entity_type, entity_id, note, key, lifetime (bool: also lower lifetime earned).
	 * @return true|WP_Error True also when replayed.
	 */
	public static function deduct( $user_id, $points, $action, array $opts = array() ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( $points <= 0 ) {
			return true;
		}
		$opts   = wp_parse_args(
			$opts,
			array(
				'entity_type' => '',
				'entity_id'   => 0,
				'note'        => '',
				'key'         => null,
				'lifetime'    => false,
			)
		);
		$before = self::row( $user_id, true );
		try {
			Kudora_DB::transaction(
				function () use ( $wpdb, $user_id, $points, $action, $opts ) {
					$id = self::write_ledger( $user_id, -$points, 0, $action, $opts, null );
					if ( 0 === $id ) {
						return;
					}
					$table = self::table();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, conditional atomic debit.
					$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET balance = balance - %d, lifetime_earned = GREATEST(0, lifetime_earned - %d), last_activity_at = %s, updated_at = %s WHERE user_id = %d AND balance >= %d", $table, $points, $opts['lifetime'] ? $points : 0, Kudora_DB::now(), Kudora_DB::now(), $user_id, $points ) );
					if ( 1 !== (int) $affected ) {
						throw new DomainException( 'insufficient' );
					}
					self::consume_lots( $user_id, $points );
					self::sync_tier( $user_id );
				}
			);
		} catch ( DomainException $e ) {
			self::$rows = array();
			return new WP_Error( 'kudora_points_insufficient', __( 'Not enough points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} catch ( RuntimeException $e ) {
			self::$rows = array();
			return new WP_Error( 'kudora_points_db', $e->getMessage() );
		}
		self::after_change( $user_id, $before );
		return true;
	}

	/**
	 * Take back points, but never below zero (refund/cancel reversals). The part
	 * that could not be taken (already spent) is recorded in the note.
	 *
	 * @param int    $user_id  User id.
	 * @param int    $points   Points to take back.
	 * @param string $action   Ledger action.
	 * @param array  $opts     As deduct().
	 * @return int Points actually deducted.
	 */
	public static function deduct_up_to( $user_id, $points, $action, array $opts = array() ) {
		$points  = (int) $points;
		$balance = self::row( $user_id, true )['balance'];
		$take    = min( $points, max( 0, $balance ) );
		if ( $take < $points ) {
			/* translators: %d: points that could not be taken back */
			$opts['note'] = trim( ( isset( $opts['note'] ) ? $opts['note'] . ' ' : '' ) . sprintf( __( '(%d points already spent)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $points - $take ) );
		}
		if ( $take <= 0 ) {
			if ( ! empty( $opts['lifetime'] ) && $points > 0 ) {
				self::lower_lifetime( $user_id, $points );
			}
			return 0;
		}
		$res = self::deduct( $user_id, $take, $action, $opts );
		if ( ! is_wp_error( $res ) && ! empty( $opts['lifetime'] ) && $points > $take ) {
			self::lower_lifetime( $user_id, $points - $take );
		}
		return is_wp_error( $res ) ? 0 : $take;
	}

	/**
	 * Lower lifetime earned without touching the balance.
	 *
	 * @param int $user_id User id.
	 * @param int $points  Points.
	 * @return void
	 */
	protected static function lower_lifetime( $user_id, $points ) {
		global $wpdb;
		$before = self::row( $user_id, true );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET lifetime_earned = GREATEST(0, lifetime_earned - %d) WHERE user_id = %d", $table, $points, $user_id ) );
		self::sync_tier( $user_id );
		self::after_change( $user_id, $before );
	}

	/**
	 * Consume `remaining` on lots, soonest-expiring first, non-expiring last.
	 *
	 * @param int $user_id User id.
	 * @param int $points  Points to consume.
	 * @return void
	 */
	protected static function consume_lots( $user_id, $points ) {
		global $wpdb;
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, locked rows.
		$lots = $wpdb->get_results( $wpdb->prepare( "SELECT id, remaining FROM %i WHERE user_id = %d AND remaining > 0 ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC FOR UPDATE", $ledger, $user_id ), ARRAY_A );
		$left = $points;
		foreach ( (array) $lots as $lot ) {
			if ( $left <= 0 ) {
				break;
			}
			$use   = min( $left, (int) $lot['remaining'] );
			$left -= $use;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining = remaining - %d WHERE id = %d", $ledger, $use, $lot['id'] ) );
		}
	}

	/**
	 * Insert a ledger row; 0 when the idempotency key exists.
	 *
	 * @param int         $user_id   User id.
	 * @param int         $points    Signed points.
	 * @param int         $remaining Remaining (lots).
	 * @param string      $action    Action.
	 * @param array       $opts      Options.
	 * @param string|null $expires   Expiry (UTC) or null.
	 * @return int
	 */
	protected static function write_ledger( $user_id, $points, $remaining, $action, array $opts, $expires ) {
		return Kudora_DB::insert_ignore(
			self::ledger_table(),
			array(
				'user_id'         => (int) $user_id,
				'points'          => (int) $points,
				'remaining'       => (int) $remaining,
				'action'          => substr( sanitize_key( $action ), 0, 20 ),
				'entity_type'     => substr( (string) $opts['entity_type'], 0, 20 ),
				'entity_id'       => (int) $opts['entity_id'],
				'note'            => substr( (string) $opts['note'], 0, 255 ),
				'expires_at'      => $expires,
				'idempotency_key' => $opts['key'] ? substr( (string) $opts['key'], 0, 120 ) : null,
				'created_at'      => Kudora_DB::now(),
			)
		);
	}

	/**
	 * Recalculate and store the tier.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	protected static function sync_tier( $user_id ) {
		global $wpdb;
		self::$rows = array();
		$row        = self::row( $user_id, true );
		$tier       = self::tier_for_points( self::tier_points( $row ) );
		$tier_id    = $tier ? $tier['id'] : '';
		if ( $tier_id !== (string) $row['tier_id'] ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET tier_id = %s WHERE user_id = %d", $table, $tier_id, $user_id ) );
		}
		self::$rows = array();
	}

	/**
	 * After a change: announce tier changes (outside the transaction).
	 *
	 * @param int   $user_id User id.
	 * @param array $before  Row before.
	 * @return void
	 */
	protected static function after_change( $user_id, array $before ) {
		self::$rows = array();
		$after      = self::row( $user_id, true );
		if ( (string) $before['tier_id'] !== (string) $after['tier_id'] ) {
			$old = null;
			$new = null;
			foreach ( self::tiers() as $t ) {
				if ( $t['id'] === (string) $before['tier_id'] ) {
					$old = $t;
				}
				if ( $t['id'] === (string) $after['tier_id'] ) {
					$new = $t;
				}
			}
			/**
			 * A customer's tier changed.
			 *
			 * @param int        $user_id User id.
			 * @param array|null $old     Old tier.
			 * @param array|null $new     New tier.
			 */
			do_action( 'kudora_tier_changed', $user_id, $old, $new );
		}
	}

	/**
	 * Recompute every customer's stored tier (after tiers are edited).
	 *
	 * @return void
	 */
	public static function resync_all_tiers() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i", $table ) );
		foreach ( $ids as $id ) {
			self::sync_tier( (int) $id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Expiry
	 * ------------------------------------------------------------------- */

	/**
	 * Expire due lots (daily job). Each lot in its own transaction.
	 *
	 * @param int $limit Max lots per run.
	 * @return int Lots expired.
	 */
	public static function expire_due( $limit = 500 ) {
		global $wpdb;
		$ledger = self::ledger_table();
		$now    = Kudora_DB::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$lots  = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id FROM %i WHERE remaining > 0 AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY expires_at ASC LIMIT %d", $ledger, $now, $limit ), ARRAY_A );
		$count = 0;
		foreach ( (array) $lots as $lot ) {
			$user_id = (int) $lot['user_id'];
			$before  = self::row( $user_id, true );
			try {
				$did = Kudora_DB::transaction(
					function () use ( $wpdb, $ledger, $lot, $user_id ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- locked row.
						$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT remaining FROM %i WHERE id = %d FOR UPDATE", $ledger, $lot['id'] ) );
						if ( $remaining <= 0 ) {
							return false;
						}
						$id = self::write_ledger(
							$user_id,
							-$remaining,
							0,
							'expired',
							array(
								'entity_type' => 'lot',
								'entity_id'   => (int) $lot['id'],
								'note'        => '',
								'key'         => 'expire:lot:' . (int) $lot['id'],
							),
							null
						);
						if ( 0 === $id ) {
							return false;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
						$wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining = 0 WHERE id = %d", $ledger, $lot['id'] ) );
						$table = self::table();
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
						$wpdb->query( $wpdb->prepare( "UPDATE %i SET balance = GREATEST(0, balance - %d), updated_at = %s WHERE user_id = %d", $table, $remaining, Kudora_DB::now(), $user_id ) );
						self::sync_tier( $user_id );
						return true;
					}
				);
			} catch ( RuntimeException $e ) {
				$did = false;
			}
			if ( $did ) {
				++$count;
				self::after_change( $user_id, $before );
			}
		}
		return $count;
	}

	/**
	 * Customers with points expiring within N days that were not warned yet.
	 * Marks the lots as warned.
	 *
	 * @param int $days Window in days.
	 * @return array<int,array{points:int,date:string}> user_id => info.
	 */
	public static function collect_expiry_warnings( $days ) {
		global $wpdb;
		$ledger = self::ledger_table();
		$now    = Kudora_DB::now();
		$until  = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(remaining) AS pts, MIN(expires_at) AS first_at FROM %i WHERE remaining > 0 AND warned = 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s GROUP BY user_id LIMIT 500", $ledger, $now, $until ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['user_id'] ] = array(
				'points' => (int) $r['pts'],
				'date'   => $r['first_at'],
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET warned = 1 WHERE user_id = %d AND remaining > 0 AND warned = 0 AND expires_at IS NOT NULL AND expires_at <= %s", $ledger, (int) $r['user_id'], $until ) );
		}
		return $out;
	}

	/**
	 * Points expiring soonest for a customer (for My Account).
	 *
	 * @param int $user_id User id.
	 * @return array{points:int,date:?string}
	 */
	public static function next_expiry( $user_id ) {
		global $wpdb;
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT expires_at, SUM(remaining) AS pts FROM %i WHERE user_id = %d AND remaining > 0 AND expires_at IS NOT NULL GROUP BY expires_at ORDER BY expires_at ASC LIMIT 1", $ledger, $user_id ), ARRAY_A );
		return $row ? array(
			'points' => (int) $row['pts'],
			'date'   => $row['expires_at'],
		) : array(
			'points' => 0,
			'date'   => null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------------- */

	/**
	 * Ledger rows for a customer, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Limit.
	 * @return array[]
	 */
	public static function history( $user_id, $limit = 50 ) {
		global $wpdb;
		$ledger = self::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d", $ledger, $user_id, $limit ), ARRAY_A );
	}

	/**
	 * Admin logs query with Booknetic's filters.
	 *
	 * @param array $args customer, action, date_from, date_to, per_page, page.
	 * @return array{rows:array[],total:int}
	 */
	public static function logs( array $args ) {
		global $wpdb;
		$f        = self::log_filters( $args );
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, static SQL; every filter is bound (replacements passed as one list).
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %d = 0 OR user_id = %d ) AND ( %s = '' OR action = %s ) AND created_at BETWEEN %s AND %s",
				...$f
			)
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %d = 0 OR user_id = %d ) AND ( %s = '' OR action = %s ) AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT %d OFFSET %d",
				...array_merge( $f, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Booknetic stat cards for a filter: earned, redeemed, rate, active customers.
	 *
	 * @param array $args Filters.
	 * @return array{earned:int,redeemed:int,rate:float,active:int}
	 */
	public static function stats( array $args ) {
		global $wpdb;
		$f = self::log_filters( $args );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, static SQL; every filter is bound (replacements passed as one list).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN action IN ('earned','signup','review','birthday','referral','bonus','admin','import') AND points > 0 THEN points ELSE 0 END),0) AS earned,
					COALESCE(SUM(CASE WHEN action IN ('redeemed','reward') THEN -points ELSE 0 END),0) - COALESCE(SUM(CASE WHEN action = 'restored' THEN points ELSE 0 END),0) AS redeemed,
					COUNT(DISTINCT user_id) AS active
				FROM %i WHERE ( %d = 0 OR user_id = %d ) AND ( %s = '' OR action = %s ) AND created_at BETWEEN %s AND %s",
				...$f
			),
			ARRAY_A
		);
		// phpcs:enable
		$earned   = (int) $row['earned'];
		$redeemed = max( 0, (int) $row['redeemed'] );
		return array(
			'earned'   => $earned,
			'redeemed' => $redeemed,
			'rate'     => $earned > 0 ? round( $redeemed / $earned * 100, 1 ) : 0.0,
			'active'   => (int) $row['active'],
		);
	}

	/**
	 * Bound values for the log filters (table first).
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	protected static function log_filters( array $args ) {
		$customer = ! empty( $args['customer'] ) ? (int) $args['customer'] : 0;
		$action   = ! empty( $args['action'] ) ? sanitize_key( $args['action'] ) : '';
		// Open-ended date bounds use sentinel dates (MySQL strict mode rejects comparing a DATETIME with '').
		$from     = ! empty( $args['date_from'] ) ? get_gmt_from_date( $args['date_from'] . ' 00:00:00' ) : '1000-01-01 00:00:00';
		$to       = ! empty( $args['date_to'] ) ? get_gmt_from_date( $args['date_to'] . ' 23:59:59' ) : '9999-12-31 23:59:59';
		return array( self::ledger_table(), $customer, $customer, $action, $action, $from, $to );
	}

	/**
	 * Customers list (admin "Customer Points").
	 *
	 * @param array $args search, tier, per_page, page, orderby.
	 * @return array{rows:array[],total:int}
	 */
	public static function customers( array $args ) {
		global $wpdb;
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$like     = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		$tier     = ! empty( $args['tier'] ) ? sanitize_key( $args['tier'] ) : '';
		$orderby  = isset( $args['orderby'] ) && 'lifetime' === $args['orderby'] ? 'lifetime_earned' : 'balance';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$f        = array( self::table(), $wpdb->users, $like, $like, $like, $like, $tier, $tier );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table joined with users, static SQL; every filter is bound (replacements passed as one list).
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p JOIN %i u ON u.ID = p.user_id WHERE ( %s = '' OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s ) AND ( %s = '' OR p.tier_id = %s )",
				...$f
			)
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, u.user_email, u.display_name FROM %i p JOIN %i u ON u.ID = p.user_id WHERE ( %s = '' OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s ) AND ( %s = '' OR p.tier_id = %s ) ORDER BY %i DESC LIMIT %d OFFSET %d",
				...array_merge( $f, array( $orderby, $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/* ---------------------------------------------------------------------
	 * Referral codes
	 * ------------------------------------------------------------------- */

	/**
	 * A customer's referral code (created on first use).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function referral_code( $user_id ) {
		global $wpdb;
		$row = self::row( $user_id, true );
		if ( ! empty( $row['referral_code'] ) ) {
			return (string) $row['referral_code'];
		}
		$table = self::table();
		$code  = Kudora_Code_Generator::unique(
			array(
				'charset' => 'alnum',
				'length'  => 8,
			),
			function ( $c ) {
				return (bool) self::user_by_referral_code( $c );
			}
		);
		$now = Kudora_DB::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (user_id, balance, lifetime_earned, tier_id, referral_code, updated_at) VALUES (%d, 0, 0, '', %s, %s) ON DUPLICATE KEY UPDATE referral_code = IF(referral_code IS NULL OR referral_code = '', VALUES(referral_code), referral_code)", $table, $user_id, $code, $now ) );
		self::$rows = array();
		return (string) self::row( $user_id, true )['referral_code'];
	}

	/**
	 * User id owning a referral code.
	 *
	 * @param string $code Code.
	 * @return int
	 */
	public static function user_by_referral_code( $code ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM %i WHERE referral_code = %s", $table, strtoupper( (string) $code ) ) );
	}
}
