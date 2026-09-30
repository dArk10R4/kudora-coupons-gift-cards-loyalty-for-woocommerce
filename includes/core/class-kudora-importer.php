<?php
/**
 * CSV imports for gift cards, customer points and coupons. Every run can be a dry
 * run (preview); large files are processed in background batches. Balance and
 * points changes go through the ledgers (`import` rows), never raw updates.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importer.
 */
class Kudora_Importer {

	/**
	 * Background batch hook.
	 */
	const BATCH_HOOK = 'kudora_import_batch';

	/**
	 * Rows per batch.
	 */
	const BATCH = 200;

	/**
	 * How long an uploaded preview waits for "Run import".
	 */
	const PREVIEW_TTL = 3600;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ), 10, 2 );
		add_action( 'kudora_daily_tasks', array( __CLASS__, 'cleanup' ) );
	}

	/* ---------------------------------------------------------------------
	 * Previews: the parsed rows of an upload, kept in a non-autoloaded option
	 * (never as a file, and not a transient an object cache could drop).
	 * ------------------------------------------------------------------- */

	/**
	 * Store a user's preview.
	 *
	 * @param int   $user_id User id.
	 * @param array $preview type, opts, rows, total, result.
	 * @return void
	 */
	public static function save_preview( $user_id, array $preview ) {
		$preview['expires'] = time() + self::PREVIEW_TTL;
		update_option( 'kudora_import_preview_' . (int) $user_id, $preview, false );
	}

	/**
	 * A user's pending preview.
	 *
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public static function get_preview( $user_id ) {
		$preview = get_option( 'kudora_import_preview_' . (int) $user_id );
		if ( ! is_array( $preview ) ) {
			return null;
		}
		if ( empty( $preview['expires'] ) || $preview['expires'] < time() ) {
			self::clear_preview( $user_id );
			return null;
		}
		return $preview;
	}

	/**
	 * Drop a user's preview.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function clear_preview( $user_id ) {
		delete_option( 'kudora_import_preview_' . (int) $user_id );
	}

	/**
	 * Daily: drop expired previews (their rows hold codes, balances and emails).
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding our own options by prefix.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'kudora_import_preview_' ) . '%' ) );
		foreach ( (array) $names as $name ) {
			self::get_preview( (int) substr( $name, strlen( 'kudora_import_preview_' ) ) );
		}
	}

	/**
	 * Import types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'gift_cards' => __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'points'     => __( 'Customer points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'coupons'    => __( 'Coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Process rows.
	 *
	 * @param string $type Type.
	 * @param array  $rows Rows.
	 * @param array  $opts Options (mode, note).
	 * @param bool   $dry  Dry run.
	 * @param int    $offset Row number offset for messages.
	 * @return array{created:int,updated:int,skipped:int,errors:string[]}
	 */
	public static function run( $type, array $rows, array $opts, $dry, $offset = 0 ) {
		$out = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		foreach ( $rows as $i => $row ) {
			$line = $offset + $i + 2; // +1 header, +1 human numbering.
			try {
				switch ( $type ) {
					case 'gift_cards':
						$r = self::gift_card_row( $row, $opts, $dry );
						break;
					case 'points':
						$r = self::points_row( $row, $opts, $dry );
						break;
					case 'coupons':
						$r = self::coupon_row( $row, $opts, $dry );
						break;
					default:
						$r = 'skipped';
				}
			} catch ( Exception $e ) {
				/* translators: 1: line number, 2: error */
				$out['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $line, $e->getMessage() );
				++$out['skipped'];
				continue;
			}
			if ( isset( $out[ $r ] ) ) {
				++$out[ $r ];
			}
		}
		return $out;
	}

	/**
	 * One gift card row.
	 *
	 * Columns: code, amount, balance, expires_on, status, recipient_email, recipient_name, sender_name, message.
	 *
	 * @param array $row  Row.
	 * @param array $opts mode: create|update.
	 * @param bool  $dry  Dry run.
	 * @return string created|updated|skipped
	 * @throws Exception On invalid data.
	 */
	protected static function gift_card_row( array $row, array $opts, $dry ) {
		$code    = Kudora_Code_Generator::normalize_input( isset( $row['code'] ) ? $row['code'] : '' );
		$amount  = Kudora_Money::parse( isset( $row['amount'] ) ? $row['amount'] : 0 );
		$balance = isset( $row['balance'] ) && '' !== $row['balance'] ? Kudora_Money::parse( $row['balance'] ) : null;
		$exp     = isset( $row['expires_on'] ) ? trim( $row['expires_on'] ) : '';
		$status  = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
		if ( '' !== $exp && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ) {
			throw new Exception( esc_html__( 'expires_on must be YYYY-MM-DD.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( '' !== $status && ! isset( Kudora_Gift_Card_Repo::statuses()[ $status ] ) ) {
			throw new Exception( esc_html__( 'Unknown status.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$existing = '' !== $code ? Kudora_Gift_Card_Repo::get_by_code( $code, true ) : null;
		if ( $existing ) {
			if ( 'update' !== ( isset( $opts['mode'] ) ? $opts['mode'] : 'create' ) ) {
				return 'skipped';
			}
			if ( $dry ) {
				return 'updated';
			}
			$fields = array();
			if ( '' !== $exp ) {
				$fields['expires_on'] = $exp;
			}
			if ( '' !== $status ) {
				$fields['status'] = $status;
			}
			foreach ( array( 'recipient_email', 'recipient_name', 'sender_name', 'message' ) as $k ) {
				if ( isset( $row[ $k ] ) && '' !== $row[ $k ] ) {
					$fields[ $k ] = 'recipient_email' === $k ? strtolower( sanitize_email( $row[ $k ] ) ) : $row[ $k ];
				}
			}
			Kudora_Gift_Card_Repo::update( $existing['id'], $fields );
			if ( null !== $balance ) {
				Kudora_Gift_Card_Repo::set_balance( $existing['id'], $balance, __( 'CSV import', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			}
			return 'updated';
		}
		if ( $amount <= 0 ) {
			throw new Exception( esc_html__( 'amount must be greater than zero.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( null !== $balance && ( $balance < 0 || $balance > $amount + 0.00001 ) ) {
			throw new Exception( esc_html__( 'balance must be between 0 and amount.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( $dry ) {
			return 'created';
		}
		$id = Kudora_Gift_Card_Repo::create(
			array(
				'code'            => $code,
				'initial_amount'  => $amount,
				'expires_on'      => '' !== $exp ? $exp : null,
				'status'          => '' !== $status ? $status : 'active',
				'recipient_email' => isset( $row['recipient_email'] ) ? $row['recipient_email'] : '',
				'recipient_name'  => isset( $row['recipient_name'] ) ? $row['recipient_name'] : '',
				'sender_name'     => isset( $row['sender_name'] ) ? $row['sender_name'] : '',
				'message'         => isset( $row['message'] ) ? $row['message'] : '',
				'source'          => 'import',
				'ledger_note'     => __( 'CSV import', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
		if ( null !== $balance && abs( $balance - $amount ) > 0.00001 ) {
			Kudora_Gift_Card_Repo::set_balance( $id, $balance, __( 'CSV import (balance)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		return 'created';
	}

	/**
	 * One points row. Columns: email or user_id, points, note.
	 *
	 * @param array $row  Row.
	 * @param array $opts mode: add|set, lifetime.
	 * @param bool  $dry  Dry run.
	 * @return string
	 * @throws Exception On invalid data.
	 */
	protected static function points_row( array $row, array $opts, $dry ) {
		$user = null;
		if ( ! empty( $row['user_id'] ) ) {
			$user = get_userdata( absint( $row['user_id'] ) );
		}
		if ( ! $user && ! empty( $row['email'] ) ) {
			$user = get_user_by( 'email', sanitize_email( $row['email'] ) );
		}
		if ( ! $user ) {
			throw new Exception( esc_html__( 'No customer with this email or user_id.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( ! isset( $row['points'] ) || ! is_numeric( $row['points'] ) ) {
			throw new Exception( esc_html__( 'points must be a number.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$points = (int) $row['points'];
		$mode   = isset( $opts['mode'] ) && 'set' === $opts['mode'] ? 'set' : 'add';
		$delta  = 'set' === $mode ? $points - Kudora_Points_Repo::balance( $user->ID ) : $points;
		if ( 0 === $delta ) {
			return 'skipped';
		}
		if ( $dry ) {
			return 'updated';
		}
		$args = array(
			'note'     => isset( $row['note'] ) && '' !== $row['note'] ? $row['note'] : __( 'CSV import', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'key'      => 'import:' . wp_generate_uuid4(),
			'lifetime' => ! empty( $opts['lifetime'] ),
		);
		if ( $delta > 0 ) {
			Kudora_Points_Repo::add( $user->ID, $delta, 'import', $args );
		} else {
			$res = Kudora_Points_Repo::deduct( $user->ID, -$delta, 'import', $args );
			if ( is_wp_error( $res ) ) {
				throw new Exception( esc_html( $res->get_error_message() ) );
			}
		}
		return 'updated';
	}

	/**
	 * One coupon row.
	 *
	 * @param array $row  Row.
	 * @param array $opts mode: create|update.
	 * @param bool  $dry  Dry run.
	 * @return string
	 * @throws Exception On invalid data.
	 */
	protected static function coupon_row( array $row, array $opts, $dry ) {
		$code = wc_format_coupon_code( isset( $row['code'] ) ? $row['code'] : '' );
		if ( '' === $code || Kudora_Virtual_Coupons::is_ours( $code ) ) {
			throw new Exception( esc_html__( 'code is required.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$type  = isset( $row['discount_type'] ) && '' !== $row['discount_type'] ? sanitize_key( $row['discount_type'] ) : 'fixed_cart';
		$types = wc_get_coupon_types();
		if ( ! isset( $types[ $type ] ) ) {
			throw new Exception( esc_html__( 'Unknown discount_type.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( $id && 'update' !== ( isset( $opts['mode'] ) ? $opts['mode'] : 'create' ) ) {
			return 'skipped';
		}
		if ( $dry ) {
			return $id ? 'updated' : 'created';
		}
		$c     = new WC_Coupon( $id ? $id : 0 );
		$split = function ( $v ) {
			return array_values( array_filter( array_map( 'trim', explode( '|', (string) $v ) ), 'strlen' ) );
		};
		$c->set_code( $code );
		$c->set_discount_type( $type );
		$map = array(
			'amount'               => 'set_amount',
			'usage_limit'          => 'set_usage_limit',
			'usage_limit_per_user' => 'set_usage_limit_per_user',
			'minimum_amount'       => 'set_minimum_amount',
			'maximum_amount'       => 'set_maximum_amount',
			'description'          => 'set_description',
		);
		foreach ( $map as $col => $setter ) {
			if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
				$c->$setter( in_array( $col, array( 'amount', 'minimum_amount', 'maximum_amount' ), true ) ? wc_format_decimal( $row[ $col ] ) : ( 'description' === $col ? $row[ $col ] : absint( $row[ $col ] ) ) );
			}
		}
		if ( isset( $row['expiry_date'] ) && '' !== $row['expiry_date'] ) {
			$c->set_date_expires( strtotime( $row['expiry_date'] . ' 23:59:59' ) );
		}
		foreach ( array( 'individual_use', 'free_shipping', 'exclude_sale_items' ) as $flag ) {
			if ( isset( $row[ $flag ] ) && '' !== $row[ $flag ] ) {
				$c->{"set_$flag"}( in_array( strtolower( $row[ $flag ] ), array( '1', 'yes', 'true' ), true ) );
			}
		}
		if ( isset( $row['email_restrictions'] ) ) {
			$c->set_email_restrictions( array_map( 'sanitize_email', $split( $row['email_restrictions'] ) ) );
		}
		foreach ( array( 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories' ) as $list ) {
			if ( isset( $row[ $list ] ) ) {
				$c->{"set_$list"}( array_map( 'absint', $split( $row[ $list ] ) ) );
			}
		}
		if ( isset( $row['start_date'] ) ) {
			$c->update_meta_data( '_kudora_start', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['start_date'] ) ? $row['start_date'] : '' );
		}
		if ( isset( $row['weekdays'] ) ) {
			$c->update_meta_data( '_kudora_weekdays', array_values( array_intersect( array_map( 'intval', $split( $row['weekdays'] ) ), range( 1, 7 ) ) ) );
		}
		if ( isset( $row['auto_apply'] ) ) {
			$c->update_meta_data( '_kudora_auto_apply', in_array( strtolower( $row['auto_apply'] ), array( '1', 'yes', 'true' ), true ) ? 'yes' : 'no' );
		}
		if ( isset( $row['max_discount'] ) ) {
			$c->update_meta_data( '_kudora_max_discount', wc_format_decimal( $row['max_discount'] ) );
		}
		$c->save();
		Kudora_Smart_Coupons::refresh_auto_list();
		return $id ? 'updated' : 'created';
	}

	/* ---------------------------------------------------------------------
	 * Background jobs
	 * ------------------------------------------------------------------- */

	/**
	 * Queue a large import. The rows are stored once, apart from the job state that
	 * every batch rewrites, and deleted when the job ends.
	 *
	 * @param array  $rows Parsed rows.
	 * @param string $type Type.
	 * @param array  $opts Options.
	 * @return string Job id.
	 */
	public static function queue( array $rows, $type, array $opts ) {
		$job = 'k' . strtolower( wp_generate_password( 10, false, false ) );
		update_option( 'kudora_import_' . $job . '_rows', array_values( $rows ), false );
		update_option(
			'kudora_import_' . $job,
			array(
				'type'    => $type,
				'opts'    => $opts,
				'total'   => count( $rows ),
				'done'    => 0,
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'errors'  => array(),
				'status'  => 'running',
				'user'    => get_current_user_id(),
			),
			false
		);
		Kudora_Scheduler::async( self::BATCH_HOOK, array( $job, 0 ) );
		return $job;
	}

	/**
	 * Process one batch and queue the next.
	 *
	 * @param string $job    Job id.
	 * @param int    $offset Offset.
	 * @return void
	 */
	public static function run_batch( $job, $offset ) {
		$job   = sanitize_key( $job );
		$state = get_option( 'kudora_import_' . $job );
		if ( ! is_array( $state ) || 'running' !== $state['status'] ) {
			return;
		}
		$all = get_option( 'kudora_import_' . $job . '_rows' );
		if ( ! is_array( $all ) ) {
			$state['status']   = 'failed';
			$state['errors'][] = __( 'The import data is missing. Upload the file again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
			update_option( 'kudora_import_' . $job, $state, false );
			return;
		}
		$rows = array_slice( $all, (int) $offset, self::BATCH );
		$res  = self::run( $state['type'], $rows, $state['opts'], false, (int) $offset );
		foreach ( array( 'created', 'updated', 'skipped' ) as $k ) {
			$state[ $k ] += $res[ $k ];
		}
		$state['errors'] = array_slice( array_merge( $state['errors'], $res['errors'] ), 0, 200 );
		$state['done']   = min( $state['total'], (int) $offset + count( $rows ) );
		if ( $state['done'] >= $state['total'] || ! $rows ) {
			$state['status'] = 'done';
			delete_option( 'kudora_import_' . $job . '_rows' );
		} else {
			Kudora_Scheduler::async( self::BATCH_HOOK, array( $job, (int) $offset + self::BATCH ) );
		}
		update_option( 'kudora_import_' . $job, $state, false );
	}
}
