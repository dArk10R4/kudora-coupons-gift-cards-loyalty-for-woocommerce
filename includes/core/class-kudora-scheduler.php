<?php
/**
 * Background work through WooCommerce's bundled Action Scheduler: one daily
 * housekeeping action (points expiry, expiry warnings, birthdays, gift card expiry)
 * plus single actions such as scheduled gift card delivery.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduler wrapper.
 */
class Kudora_Scheduler {

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'kudora';

	/**
	 * Daily housekeeping hook.
	 */
	const DAILY = 'kudora_daily';

	/**
	 * Recurring hooks cleared on deactivation.
	 *
	 * @return string[]
	 */
	public static function recurring_hooks() {
		return array( self::DAILY );
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'ensure_daily' ) );
		add_action( self::DAILY, array( $this, 'run_daily' ) );
	}

	/**
	 * Make sure the daily action exists (runs at ~03:00 site time).
	 *
	 * @return void
	 */
	public function ensure_daily() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || get_transient( 'kudora_daily_checked' ) ) {
			return;
		}
		set_transient( 'kudora_daily_checked', 1, HOUR_IN_SECONDS );
		if ( false === as_next_scheduled_action( self::DAILY, array(), self::GROUP ) ) {
			$next = new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::DAILY, array(), self::GROUP );
		}
	}

	/**
	 * Fan the daily run out to modules.
	 *
	 * @return void
	 */
	public function run_daily() {
		/**
		 * Daily housekeeping. Modules hook their jobs here.
		 */
		do_action( 'kudora_daily_tasks' );
	}

	/**
	 * Schedule a one-off action (deduplicated on hook + args).
	 *
	 * @param int    $timestamp UTC timestamp.
	 * @param string $hook      Hook.
	 * @param array  $args      Args.
	 * @return void
	 */
	public static function single( $timestamp, $hook, array $args = array() ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( false !== as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
	}

	/**
	 * Cancel a pending one-off action.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return void
	 */
	public static function cancel( $hook, array $args = array() ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		}
	}

	/**
	 * Run something as soon as possible in the background.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return void
	 */
	public static function async( $hook, array $args = array() ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		} else {
			do_action_ref_array( $hook, $args );
		}
	}
}
