<?php
/**
 * One-off background tasks: WooCommerce's Action Scheduler when available (persistent queue,
 * retries, visible under Tools → Scheduled Actions, runs also on busy sites), WP-Cron otherwise.
 * The hourly/daily maintenance stays on WP-Cron (recurring events, also without WooCommerce).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const GROUP = 'wp-affiliate-captain';

	/**
	 * Whether the Action Scheduler can be used (it is ready after WooCommerce initialised it).
	 *
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		$use = function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) && ( ! class_exists( 'ActionScheduler' ) || \ActionScheduler::is_initialized() );
		/**
		 * Use WooCommerce's Action Scheduler for one-off tasks (default: when available).
		 *
		 * @param bool $use Use it.
		 */
		return (bool) apply_filters( 'wpac_use_action_scheduler', $use );
	}

	/**
	 * Schedule a hook once (no duplicate when the same hook with the same arguments is pending).
	 *
	 * @param string $hook  Hook.
	 * @param int    $delay Seconds from now.
	 * @param array  $args  Arguments.
	 */
	public static function once( $hook, $delay = 0, array $args = array() ) {
		if ( self::pending( $hook, $args ) ) {
			return;
		}
		if ( self::uses_action_scheduler() ) {
			as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + max( 0, (int) $delay ), $hook, $args );
	}

	/**
	 * Whether the hook is already pending.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Arguments.
	 * @return bool
	 */
	public static function pending( $hook, array $args = array() ) {
		if ( self::uses_action_scheduler() && as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
			return true;
		}
		return (bool) wp_next_scheduled( $hook, $args );
	}

	/**
	 * Remove all pending runs of a hook (both schedulers).
	 *
	 * @param string $hook Hook.
	 */
	public static function clear( $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( $hook );
	}
}
