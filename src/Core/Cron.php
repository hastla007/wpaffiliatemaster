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
		add_action( \WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, array( \WPAffiliateCaptain\Reports\MonthlyMail::class, 'batch' ) );
		add_action( \WPAffiliateCaptain\Reports\MonthlyMail::RETRY_HOOK, array( \WPAffiliateCaptain\Reports\MonthlyMail::class, 'retry_failed' ) );
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
		wp_clear_scheduled_hook( \WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK );
		wp_clear_scheduled_hook( \WPAffiliateCaptain\Reports\MonthlyMail::RETRY_HOOK );
	}

	public static function hourly() {
		if ( ! Installer::schema_current() ) {
			return; // A migration is running or failed: no bookings against the old schema.
		}
		\WPAffiliateCaptain\Payouts\PayoutService::generate_missing_statements();
		Ledger::approve_due();
		Jobs::run_due();
	}

	/**
	 * Run a retention DELETE in small transactions (LIMIT batches) so millions of old rows never become
	 * one huge transaction with long locks and a large undo log.
	 *
	 * @param string $delete_sql Prepared DELETE statement without LIMIT.
	 * @param int    $batch      Rows per batch.
	 * @param int    $max        Upper bound of batches per run (the rest follows next day).
	 * @return int Deleted rows.
	 */
	public static function delete_in_batches( $delete_sql, $batch = 5000, $max = 400 ) {
		global $wpdb;
		$total = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller.
			$n = (int) $wpdb->query( $delete_sql . ' LIMIT ' . (int) $batch );
			$total += max( 0, $n );
			if ( $n < $batch ) {
				break;
			}
		}
		return $total;
	}

	public static function daily() {
		global $wpdb;
		if ( ! Installer::schema_current() ) {
			return;
		}
		// Each step runs on its own: an error or timeout in one (e.g. a large tier
		// recalculation) must not skip retention and the other tasks every day.
		foreach ( array(
			array( '\WPAffiliateCaptain\Commissions\TierService', 'close_due_months' ),
			array( '\WPAffiliateCaptain\Commissions\TierService', 'recalculate_closed_months' ),
			array( '\WPAffiliateCaptain\Payouts\Statement', 'purge_expired' ),
			array( '\WPAffiliateCaptain\Reports\MonthlyMail', 'send_due' ),
		) as $step ) {
			try {
				call_user_func( $step );
			} catch ( \Throwable $e ) {
				Audit::log( 'cron.step_failed', 'cron', 0, array( 'step' => $step[1], 'error' => substr( $e->getMessage(), 0, 190 ) ) );
			}
		}
		$days = (int) Settings::get( 'visit_retention_days' );
		if ( $days > 0 ) {
			$table = Db::table( 'visits' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", Db::now( time() - $days * DAY_IN_SECONDS ) ) );
		}
		\WPAffiliateCaptain\Tracking\CustomerLinks::purge_expired();
		\WPAffiliateCaptain\Privacy\Privacy::finish_pending_erasures();
		\WPAffiliateCaptain\Privacy\Privacy::purge_rejected_applications();
		$audit_days = (int) Settings::get( 'audit_retention_days' );
		if ( $audit_days > 0 ) {
			$audit = Db::table( 'audit' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Acceptance records stay as proof; the change log of booking-relevant data (payouts,
			// statements, bank details) is kept as long as the statements (GoBD).
			$years     = max( 10, (int) Settings::get( 'statement_retention_years' ) );
			$financial = Db::now( strtotime( gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( $years + 1 ) . ' years' ) ) . ' UTC' ) );
			self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$audit} WHERE created_at < %s AND action NOT LIKE 'agreement.%%' AND ( ( action NOT LIKE 'payout.%%' AND action NOT LIKE 'statement.%%' AND action NOT LIKE 'payout_profile.%%' AND action NOT LIKE 'payout_request.%%' ) OR created_at < %s )", Db::now( time() - $audit_days * DAY_IN_SECONDS ), $financial ) );
		}
		$jobs = Db::table( 'jobs' );
		// Failed jobs are kept 180 days for analysis, then removed (payloads may contain order data).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status = 'failed' AND updated_at < %s", Db::now( time() - 180 * DAY_IN_SECONDS ) ) );
		// Done jobs are only kept for 30 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status = 'done' AND updated_at < %s", Db::now( time() - 30 * DAY_IN_SECONDS ) ) );
	}
}
