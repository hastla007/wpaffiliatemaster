<?php
/**
 * Scheduled tasks: release commissions after hold period, retry jobs, retention.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

use WPAffiliateCaptain\Commissions\Ledger;

defined( 'ABSPATH' ) || exit;

final class Cron {

	const HOURLY = 'wpac_hourly';
	const DAILY  = 'wpac_daily';

	public static function register_hooks() {
		add_action( self::HOURLY, array( __CLASS__, 'hourly' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 600, 'daily', self::DAILY );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::DAILY );
	}

	public static function hourly() {
		Ledger::approve_due();
		Jobs::run_due();
	}

	public static function daily() {
		global $wpdb;
		\WPAffiliateCaptain\Commissions\TierService::close_due_months();
		\WPAffiliateCaptain\Payouts\Statement::purge_expired();
		\WPAffiliateCaptain\Reports\MonthlyMail::send_due();
		$days = (int) Settings::get( 'visit_retention_days' );
		if ( $days > 0 ) {
			$table = Db::table( 'visits' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", Db::now( time() - $days * DAY_IN_SECONDS ) ) );
		}
		$jobs = Db::table( 'jobs' );
		// Done jobs are only kept for 30 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status = 'done' AND updated_at < %s", Db::now( time() - 30 * DAY_IN_SECONDS ) ) );
	}
}
