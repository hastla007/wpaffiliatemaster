<?php
/**
 * Retryable integration jobs. When processing a sale/refund fails (e.g. a DB
 * error or an unavailable integration), the event is queued here and retried
 * with exponential backoff. Failed jobs are visible and retryable in the admin.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Jobs {

	const MAX_ATTEMPTS = 8;

	/**
	 * Queue (or re-queue) a job. The idempotency key prevents duplicates.
	 *
	 * @param string $type    Job type (sale|refund|woo_order|woo_refund).
	 * @param string $key     Idempotency key.
	 * @param array  $payload Payload.
	 * @param string $code    Error code of the first failure.
	 * @param string $message Error message of the first failure.
	 * @return bool Whether the job is stored (only then may callers report "queued").
	 */
	public static function enqueue( $type, $key, array $payload, $code = '', $message = '' ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$key   = substr( $type . ':' . $key, 0, 191 );
		$now   = Db::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s", $key ) );
		if ( $exists ) {
			// The attempts are kept: a job that keeps failing must still end as "failed" for the
			// admin instead of restarting its count with every new failure report.
			$ok = $wpdb->update(
				$table,
				array(
					'status'          => 'queued',
					'payload'         => wp_json_encode( $payload ),
					'next_attempt_at' => Db::now( time() + 300 ),
					'last_error_code' => substr( (string) $code, 0, 64 ),
					'last_error'      => substr( (string) $message, 0, 255 ),
					'updated_at'      => $now,
				),
				array( 'id' => (int) $exists )
			);
			return false !== $ok;
		}
		$ok = $wpdb->insert(
			$table,
			array(
				'type'            => $type,
				'idempotency_key' => $key,
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'queued',
				'attempts'        => 1,
				'next_attempt_at' => Db::now( time() + 300 ),
				'last_error_code' => substr( (string) $code, 0, 64 ),
				'last_error'      => substr( (string) $message, 0, 255 ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return (bool) $ok;
	}

	/**
	 * Process due jobs.
	 *
	 * @param int $limit Max jobs per run.
	 */
	public static function run_due( $limit = 25, $budget = 20 ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$start = microtime( true );
		// Jobs whose lease expired (the run died, e.g. timeout or fatal error) count as a
		// failed attempt: back to the queue, or failed after the last attempt.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = IF(attempts >= %d, 'failed', 'queued'), last_error_code = 'aborted', last_error = 'The previous run did not finish (timeout or fatal error).', updated_at = %s WHERE status = 'running' AND next_attempt_at <= %s", self::MAX_ATTEMPTS, Db::now(), Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'queued' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d", Db::now(), $limit ) );
		foreach ( $ids as $id ) {
			// Time budget (seconds, 0 = none): a slow job must not push the rest of the cron run
			// (approvals, payouts, statements) past the PHP time limit. The rest waits 5 minutes.
			if ( $budget > 0 && microtime( true ) - $start > $budget ) {
				if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
					wp_schedule_single_event( time() + 300, self::DRAIN_HOOK );
				}
				break;
			}
			self::run( (int) $id );
		}
	}

	/** Single event that continues a queue run that hit its time budget. */
	const DRAIN_HOOK = 'wpac_jobs_drain';

	/**
	 * Continue the queue (single cron event).
	 */
	public static function drain() {
		self::run_due( 50 );
	}

	/**
	 * Put all failed jobs back into the queue (admin "retry all", WP-CLI).
	 *
	 * @return int Number of jobs queued again.
	 */
	public static function retry_all_failed() {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// A manual retry starts a short new series (3 more attempts) instead of failing at once.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', attempts = LEAST(attempts, %d), next_attempt_at = %s, updated_at = %s WHERE status = 'failed'", self::MAX_ATTEMPTS - 3, Db::now(), Db::now() ) );
		if ( $n ) {
			Audit::log( 'job.retry_all', 'job', 0, array( 'count' => $n ) );
		}
		return $n;
	}

	/**
	 * Run one job now.
	 *
	 * @param int $id Job ID.
	 * @return bool Success.
	 */
	public static function run( $id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $job || ! in_array( $job->status, array( 'queued', 'failed' ), true ) ) {
			return false;
		}
		// Claim atomically (a parallel runner gets 0 rows) and count the attempt before
		// running, so a run that dies is not retried forever with the same count.
		// The lease (next_attempt_at) lets run_due() requeue jobs of a dead run.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', attempts = attempts + 1, next_attempt_at = %s, updated_at = %s WHERE id = %d AND status = %s", Db::now( time() + 15 * MINUTE_IN_SECONDS ), Db::now(), (int) $job->id, $job->status ) );
		if ( 1 !== (int) $claimed ) {
			return false;
		}
		$job->attempts = (int) $job->attempts + 1;
		$payload = json_decode( (string) $job->payload, true );
		$payload = is_array( $payload ) ? $payload : array();

		try {
			/**
			 * Run a job. Handlers must be idempotent and throw on failure.
			 *
			 * @param bool   $handled Whether a handler processed the job.
			 * @param string $type    Job type.
			 * @param array  $payload Payload.
			 */
			$handled = apply_filters( 'wpac_run_job', false, $job->type, $payload );
			if ( ! $handled ) {
				throw new IntegrationException( 'no_handler', 'No handler available for job type ' . $job->type );
			}
			$wpdb->update(
				$table,
				array(
					'status'     => 'done',
					'updated_at' => Db::now(),
				),
				array( 'id' => (int) $job->id )
			);
			if ( in_array( $job->type, array( 'sale', 'woo_order' ), true ) ) {
				// The sale is booked: refunds that waited for it run now (also those that failed meanwhile).
				$parts = explode( ':', (string) $job->idempotency_key, 2 );
				self::wake_refunds( isset( $parts[1] ) ? $parts[1] : '' );
			}
			return true;
		} catch ( \Throwable $e ) {
			$attempts = (int) $job->attempts;
			$delay    = min( DAY_IN_SECONDS, 300 * ( 2 ** min( $attempts, 10 ) ) );
			if ( $e instanceof IntegrationException && 'sale_pending' === $e->error_code ) {
				// Waiting for the sale is not a failure: it must not use up the attempts
				// (the sale job wakes this job when it is booked, see wake_refunds()).
				$attempts = max( 0, $attempts - 1 );
				$delay    = HOUR_IN_SECONDS;
			}
			$wpdb->update(
				$table,
				array(
					'status'          => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued',
					'attempts'        => $attempts,
					'next_attempt_at' => Db::now( time() + $delay ),
					'last_error_code' => substr( $e instanceof IntegrationException ? $e->error_code : 'exception', 0, 64 ),
					'last_error'      => substr( $e->getMessage(), 0, 255 ),
					'updated_at'      => Db::now(),
				),
				array( 'id' => (int) $job->id )
			);
			return false;
		}
	}

	/**
	 * Whether a sale of this order is still waiting in the queue (or failed and
	 * awaits a manual retry). Refunds that arrive meanwhile must wait for it.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 * @return bool
	 */
	public static function sale_pending( $source, $order_id ) {
		global $wpdb;
		// Being booked right now by another request (see ContractApi::record_sale()).
		$inflight = self::inflight_key( $source, $order_id );
		wp_cache_delete( $inflight, 'options' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $inflight ) );
		if ( null !== $since && time() - (int) $since < 10 * MINUTE_IN_SECONDS ) {
			return true;
		}
		$table = Db::table( 'jobs' );
		$keys  = array( substr( 'sale:' . $source . ':' . $order_id, 0, 191 ), substr( 'woo_order:' . $source . ':' . $order_id, 0, 191 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE idempotency_key IN (%s, %s) AND status IN ('queued','running','failed') LIMIT 1", $keys[0], $keys[1] ) );
	}

	/**
	 * Run the refund jobs of an order at the next opportunity (after its sale was booked).
	 *
	 * @param string $order_key "source:order_id".
	 */
	public static function wake_refunds( $order_key ) {
		global $wpdb;
		if ( '' === $order_key ) {
			return;
		}
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', next_attempt_at = %s, updated_at = %s WHERE type = 'refund' AND idempotency_key LIKE %s AND status IN ('queued','failed')", Db::now(), Db::now(), $wpdb->esc_like( 'refund:' . $order_key . ':' ) . '%' ) );
	}

	/**
	 * Close queued refund jobs whose refunds are already part of a (rebuilt) sale.
	 *
	 * @param string   $source     Source.
	 * @param string   $order_id   Order ID.
	 * @param string[] $refund_ids Refund IDs already deducted.
	 */
	public static function supersede_refunds( $source, $order_id, array $refund_ids ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		foreach ( $refund_ids as $rid ) {
			$key = substr( 'refund:' . $source . ':' . $order_id . ':' . $rid, 0, 191 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', last_error_code = 'superseded', last_error = 'Already deducted in the retried sale.', updated_at = %s WHERE idempotency_key = %s AND status IN ('queued','failed')", Db::now(), $key ) );
		}
	}

	/**
	 * Option name that marks a sale as being booked.
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 * @return string
	 */
	public static function inflight_key( $source, $order_id ) {
		return 'wpac_inflight_' . md5( $source . ':' . $order_id );
	}

	/**
	 * Reset a failed job so it runs at the next opportunity.
	 *
	 * @param int $id Job ID.
	 */
	public static function retry( $id ) {
		global $wpdb;
		$wpdb->update(
			Db::table( 'jobs' ),
			array(
				'status'          => 'queued',
				'next_attempt_at' => Db::now(),
				'updated_at'      => Db::now(),
			),
			array( 'id' => (int) $id )
		);
		Audit::log( 'job.retry', 'job', (int) $id );
		return self::run( (int) $id );
	}

	/**
	 * Jobs list for the admin.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function query( $status = '', $limit = 100 ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		if ( $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status <> 'done' ORDER BY id DESC LIMIT %d", $limit ) );
	}
}
