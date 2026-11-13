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

	/** Option: time of the last hourly/daily run (diagnostics). */
	const LAST_RUN_OPTION = 'wpac_cron_last_run';

	/**
	 * Remember when a scheduled run started (support data, `wp wpac status`).
	 *
	 * @param string $which hourly|daily.
	 */
	private static function mark_run( $which ) {
		$last           = get_option( self::LAST_RUN_OPTION, array() );
		$last           = is_array( $last ) ? $last : array();
		$last[ $which ] = time();
		update_option( self::LAST_RUN_OPTION, $last, false );
	}

	public static function register_hooks() {
		add_action( self::HOURLY, array( __CLASS__, 'hourly' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( self::DAILY_REST, array( __CLASS__, 'daily_rest' ) );
		add_action( \WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, array( \WPAffiliateCaptain\Reports\MonthlyMail::class, 'batch' ) );
		add_action( \WPAffiliateCaptain\Reports\MonthlyMail::RETRY_HOOK, array( \WPAffiliateCaptain\Reports\MonthlyMail::class, 'retry_failed' ) );
		add_action(
			\WPAffiliateCaptain\Payouts\Vies::HOOK,
			static function ( $affiliate_id ) {
				\WPAffiliateCaptain\Payouts\Vies::check( $affiliate_id );
			}
		);
		add_action( Jobs::DRAIN_HOOK, array( Jobs::class, 'drain' ) );
		add_action(
			Crypto::REENCRYPT_HOOK,
			static function () {
				Crypto::reencrypt_batch();
			},
			10,
			0
		);
		add_action( \WPAffiliateCaptain\Affiliates\Agreements::REMIND_HOOK, array( \WPAffiliateCaptain\Affiliates\Agreements::class, 'remind_batch' ), 10, 0 );
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
		foreach ( array( \WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, \WPAffiliateCaptain\Reports\MonthlyMail::RETRY_HOOK, Jobs::DRAIN_HOOK, \WPAffiliateCaptain\Payouts\Vies::HOOK, Installer::NETWORK_HOOK, self::DAILY_REST ) as $hook ) {
			Scheduler::clear( $hook );
		}
		Scheduler::clear_group(); // Anything else of this plugin still waiting in the Action Scheduler.
	}

	public static function hourly() {
		self::mark_run( 'hourly' );
		if ( ! Installer::schema_current() ) {
			Installer::alert_if_stuck();
			return; // A migration is running or failed: no bookings against the old schema.
		}
		if ( Settings::get( 'maintenance_mode' ) ) {
			return; // Maintenance: approvals, documents and retries wait (sales are still recorded).
		}
		// Each step on its own (an error in one must not stop the others), the queue first:
		// sales and refunds waiting for a retry are the most urgent, approvals and documents follow.
		foreach ( array(
			array( Jobs::class, 'run_due' ),
			array( Ledger::class, 'approve_due' ),
			array( '\WPAffiliateCaptain\Payouts\PayoutService', 'generate_missing_statements' ),
		) as $step ) {
			try {
				call_user_func( $step );
			} catch ( \Throwable $e ) {
				Audit::log( 'cron.step_failed', 'cron', 0, array( 'step' => $step[1], 'error' => substr( $e->getMessage(), 0, 190 ) ) );
				Log::exception( 'Scheduled step failed: ' . $step[1], $e );
			}
		}
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

	/** Single event that continues a daily run that used up its time budget. */
	const DAILY_REST = 'wpac_daily_rest';
	const DAILY_REST_OPTION = 'wpac_daily_rest';

	/**
	 * Steps of the daily maintenance in their order: privacy and retention clean-ups first
	 * (deleting personal data must never wait for a migration, a slow mail server or VIES), then
	 * bookings, documents and mails, the alarm mail last.
	 *
	 * @return array Key => [ callable, needs current schema and no maintenance ].
	 */
	public static function daily_steps() {
		return array(
			'visits'                => array( array( __CLASS__, 'purge_visits' ), false ),
			'customer_links'        => array( array( '\WPAffiliateCaptain\Tracking\CustomerLinks', 'purge_expired' ), false ),
			'pending_erasures'      => array( array( '\WPAffiliateCaptain\Privacy\Privacy', 'finish_pending_erasures' ), false ),
			'rejected_applications' => array( array( '\WPAffiliateCaptain\Privacy\Privacy', 'purge_rejected_applications' ), false ),
			'expired_options'       => array( array( '\WPAffiliateCaptain\Privacy\Privacy', 'purge_expired_options' ), false ),
			'erased'                => array( array( '\WPAffiliateCaptain\Privacy\Retention', 'purge_erased' ), false ),
			'agreements'            => array( array( '\WPAffiliateCaptain\Privacy\Retention', 'purge_agreements' ), false ),
			'restrictions'          => array( array( '\WPAffiliateCaptain\Privacy\Restriction', 'purge' ), false ),
			'objections'            => array( array( '\WPAffiliateCaptain\Payouts\Objections', 'purge' ), false ),
			'audit'                 => array( array( __CLASS__, 'purge_audit' ), false ),
			'jobs'                  => array( array( __CLASS__, 'purge_jobs' ), false ),
			'inflight'              => array( array( __CLASS__, 'purge_inflight' ), false ),
			'rate_limits'           => array( array( '\WPAffiliateCaptain\Tracking\RateLimiter', 'purge' ), false ),
			'orphaned_tables'       => array( array( Installer::class, 'drop_orphaned_tables' ), false ),
			'tier_close'            => array( array( '\WPAffiliateCaptain\Commissions\TierService', 'close_due_months' ), true ),
			'tier_recalculate'      => array( array( '\WPAffiliateCaptain\Commissions\TierService', 'recalculate_closed_months' ), true ),
			'statements'            => array( array( '\WPAffiliateCaptain\Payouts\Statement', 'purge_expired' ), true ),
			'monthly_mail'          => array( array( '\WPAffiliateCaptain\Reports\MonthlyMail', 'send_due' ), true ),
			'vies'                  => array( array( '\WPAffiliateCaptain\Payouts\Vies', 'recheck_stale' ), true ), // Only schedules background checks.
			'balances'              => array( array( '\WPAffiliateCaptain\Commissions\Balance', 'scheduled_check' ), true ),
			'alert'                 => array( array( Monitor::class, 'daily_alert' ), false ),
		);
	}

	public static function daily() {
		self::mark_run( 'daily' );
		delete_option( self::DAILY_REST_OPTION ); // A new day's run replaces the rest of an older one.
		if ( ! Installer::schema_current() ) {
			Installer::alert_if_stuck();
		}
		self::run_daily( array_keys( self::daily_steps() ) );
	}

	/**
	 * Continue a daily run that used up its time budget (single event).
	 */
	public static function daily_rest() {
		$rest = get_option( self::DAILY_REST_OPTION );
		delete_option( self::DAILY_REST_OPTION );
		if ( is_array( $rest ) && $rest ) {
			self::run_daily( array_map( 'strval', $rest ) );
		}
	}

	/**
	 * Run daily steps, each on its own (an error in one must not stop the others) and within a time
	 * budget like the hourly queue: what does not fit continues in a separate run a few minutes later.
	 *
	 * @param string[] $keys Step keys.
	 */
	private static function run_daily( array $keys ) {
		$steps    = self::daily_steps();
		$bookings = Installer::schema_current() && ! Settings::get( 'maintenance_mode' );
		/**
		 * Seconds the daily maintenance may run in one request before the rest continues separately.
		 *
		 * @param float $budget Seconds (0 = no limit).
		 */
		$budget = (float) apply_filters( 'wpac_daily_budget', 60 );
		$start  = microtime( true );
		$keys   = array_values( array_intersect( $keys, array_keys( $steps ) ) );
		foreach ( $keys as $i => $key ) {
			if ( $budget > 0 && $i > 0 && microtime( true ) - $start > $budget ) {
				update_option( self::DAILY_REST_OPTION, array_slice( $keys, $i ), false );
				Scheduler::once( self::DAILY_REST, 120 );
				return;
			}
			list( $step, $needs_bookings ) = $steps[ $key ];
			if ( $needs_bookings && ! $bookings ) {
				continue; // Bookings, documents and mails only on the current schema and outside maintenance.
			}
			try {
				call_user_func( $step );
			} catch ( \Throwable $e ) {
				Audit::log( 'cron.step_failed', 'cron', 0, array( 'step' => $step[1], 'error' => substr( $e->getMessage(), 0, 190 ) ) );
				Log::exception( 'Scheduled step failed: ' . $step[1], $e );
			}
		}
	}

	/**
	 * Delete link visits after their retention.
	 */
	public static function purge_visits() {
		global $wpdb;
		$days = (int) Settings::get( 'visit_retention_days' );
		if ( $days > 0 ) {
			$table = Db::table( 'visits' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", Db::now( time() - $days * DAY_IN_SECONDS ) ) );
		}
	}

	/**
	 * Delete old audit entries (booking-relevant ones only after the retention period).
	 */
	public static function purge_audit() {
		global $wpdb;
		$audit_days = (int) Settings::get( 'audit_retention_days' );
		if ( $audit_days <= 0 ) {
			return;
		}
		$audit = Db::table( 'audit' );
		// Acceptance records stay as proof (deleted with the partner's agreements, see Retention);
		// the change log of booking-relevant data (commissions, payouts, statements, bank details)
		// is kept as long as the statements (GoBD), and not at all deleted during a tax audit or
		// legal hold. "Keep forever" keeps it forever.
		$cutoff    = \WPAffiliateCaptain\Privacy\Retention::cutoff();
		$financial = null === $cutoff ? '1970-01-01 00:00:00' : $cutoff;
		// Table name and the LIKE patterns are constants; the dates are placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$audit} WHERE created_at < %s AND action NOT LIKE 'agreement.%%' AND ( ( action NOT LIKE 'payout.%%' AND action NOT LIKE 'statement.%%' AND action NOT LIKE 'payout_profile.%%' AND action NOT LIKE 'payout_request.%%' AND action NOT LIKE 'commission.%%' AND action NOT LIKE 'balance.%%' AND action NOT LIKE 'tier.%%' ) OR created_at < %s )", Db::now( time() - $audit_days * DAY_IN_SECONDS ), $financial ) );
	}

	/**
	 * Delete finished jobs (failed after 180 days for analysis, done after 30; payloads may contain order data).
	 */
	public static function purge_jobs() {
		global $wpdb;
		$jobs = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status = 'failed' AND updated_at < %s", Db::now( time() - 180 * DAY_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::delete_in_batches( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status = 'done' AND updated_at < %s", Db::now( time() - 30 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Remove "sale being booked" markers and other named locks left behind by requests that died
	 * (normally each is removed right away; a stale one would hold refunds back as "pending").
	 */
	public static function purge_inflight() {
		global $wpdb;
		// Values are "unix-time:token"; anything older than a day is certainly stale.
		$stale = time() - DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", $wpdb->esc_like( 'wpac_inflight_' ) . '%', $wpdb->esc_like( 'wpac_stmt_' ) . '%', $stale ) );
	}
}
