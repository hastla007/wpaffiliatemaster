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
	 * Whether the hook is already waiting to run. The run that is executing right now does NOT
	 * count: a task that schedules its own continuation (report batches, queue drain, network
	 * set-up) must be able to do so from inside its own run. as_next_scheduled_action() and
	 * as_has_scheduled_action() also report the running action, so only pending actions are
	 * looked up. WP-Cron removes a single event before it runs it.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Arguments.
	 * @return bool
	 */
	public static function pending( $hook, array $args = array() ) {
		if ( self::uses_action_scheduler() ) {
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$ids = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'args'     => $args,
						'group'    => self::GROUP,
						'status'   => class_exists( 'ActionScheduler_Store' ) ? \ActionScheduler_Store::STATUS_PENDING : 'pending',
						'per_page' => 1,
						'orderby'  => 'none',
					),
					'ids'
				);
				if ( $ids ) {
					return true;
				}
			} else {
				// Older Action Scheduler: true means "running" (or no date), a timestamp means pending.
				$next = as_next_scheduled_action( $hook, $args, self::GROUP );
				if ( is_int( $next ) ) {
					return true;
				}
			}
		}
		return (bool) wp_next_scheduled( $hook, $args );
	}

	/**
	 * Remove all pending runs of a hook, whatever their arguments (both schedulers).
	 * as_unschedule_all_actions( $hook, array(), GROUP ) would only match runs without arguments
	 * (e.g. not the per-partner VIES checks); by hook alone it cancels every run of the hook.
	 *
	 * @param string $hook Hook.
	 */
	public static function clear( $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( $hook ); // All events of the hook, with any arguments.
		} else {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Remove every pending action of this plugin from the Action Scheduler (deactivation).
	 */
	public static function clear_group() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
