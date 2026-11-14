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

	/** Default number of attempts before a job fails for good (filter wpac_job_max_attempts). */
	const MAX_ATTEMPTS = 8;

	/**
	 * Attempts before a job fails for good.
	 *
	 * @return int
	 */
	public static function max_attempts() {
		/**
		 * Attempts before a sale or refund job fails for good and needs a manual retry.
		 *
		 * @param int $attempts Default 8 (backoff 10 min … 24 h, about 2 days in total).
		 */
		return max( 4, (int) apply_filters( 'wpac_job_max_attempts', self::MAX_ATTEMPTS ) );
	}

	/**
	 * Queue (or re-queue) a job. The idempotency key prevents duplicates.
	 *
	 * @param string $type    Job type (sale|refund|woo_order|woo_refund|statement …).
	 * @param string $key     Idempotency key.
	 * @param array  $payload Payload.
	 * @param string $code    Error code of the first failure.
	 * @param string $message Error message of the first failure.
	 * @param int    $delay   Seconds until the first run (default: 5 minutes after a failure).
	 * @param string $status  queued, or parked (runs only when woken, see wake_refunds()).
	 * @return bool Whether the job is stored (only then may callers report "queued").
	 */
	public static function enqueue( $type, $key, array $payload, $code = '', $message = '', $delay = 300, $status = 'queued' ) {
		$status = 'parked' === $status ? 'parked' : 'queued';
		global $wpdb;
		$table = Db::table( 'jobs' );
		$key   = Db::cut( $type . ':' . $key, 191 );
		$now   = Db::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, last_error_code FROM {$table} WHERE idempotency_key = %s", $key ) );
		$exists = $row ? (int) $row->id : 0;
		if ( $row && 'done' === $row->status && 'superseded' === $row->last_error_code ) {
			// This refund is already part of the booked sale (supersede_refunds()): never run it again.
			return true;
		}
		if ( $exists ) {
			// The attempts are kept: a job that keeps failing must still end as "failed" for the
			// admin instead of restarting its count with every new failure report.
			$ok = $wpdb->update(
				$table,
				array(
					'status'          => $status,
					'payload'         => wp_json_encode( $payload ),
					'next_attempt_at' => Db::now( time() + max( 0, (int) $delay ) ),
					'last_error_code' => Db::cut( (string) $code, 64 ),
					'last_error'      => Db::cut( (string) $message, 255 ),
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
				'status'          => $status,
				'attempts'        => 'parked' === $status ? 0 : 1,
				'next_attempt_at' => Db::now( time() + max( 0, (int) $delay ) ),
				'last_error_code' => Db::cut( (string) $code, 64 ),
				'last_error'      => Db::cut( (string) $message, 255 ),
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
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = IF(attempts >= %d, 'failed', 'queued'), last_error_code = 'aborted', last_error = 'The previous run did not finish (timeout or fatal error).', updated_at = %s WHERE status = 'running' AND next_attempt_at <= %s", self::max_attempts(), Db::now(), Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'queued' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d", Db::now(), $limit ) );
		foreach ( $ids as $id ) {
			// Time budget (seconds, 0 = none): a slow job must not push the rest of the cron run
			// (approvals, payouts, statements) past the PHP time limit. The rest waits 5 minutes.
			if ( $budget > 0 && microtime( true ) - $start > $budget ) {
				Scheduler::once( self::DRAIN_HOOK, 300 );
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
		// Like the hourly run: nothing is booked during maintenance or before a migration has
		// finished (the hourly run picks the queue up again afterwards).
		if ( ! Installer::schema_current() || Settings::get( 'maintenance_mode' ) ) {
			return;
		}
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
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', attempts = LEAST(attempts, %d), next_attempt_at = %s, updated_at = %s WHERE status = 'failed'", self::max_attempts() - 3, Db::now(), Db::now() ) );
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
			$max      = self::max_attempts();
			$delay    = min( DAY_IN_SECONDS, 300 * ( 2 ** min( $attempts, 10 ) ) );
			$code     = $e instanceof IntegrationException ? $e->error_code : 'exception';
			$context  = array(
				'job'      => (int) $job->id,
				'type'     => (string) $job->type,
				'attempts' => $attempts,
			);
			if ( 'sale_pending' === $code || 'no_handler' === $code ) {
				// Waiting for the sale, or no handler right now (e.g. WooCommerce briefly
				// deactivated during an update), is not a failure of the job: it must not use up
				// the attempts. The sale job wakes a waiting refund when it is booked (wake_refunds()).
				$attempts = max( 0, $attempts - 1 );
				$delay    = HOUR_IN_SECONDS;
				Log::debug( 'Job postponed: ' . $code, $context );
			} elseif ( $attempts >= $max ) {
				Log::exception( 'Job failed for good', $e, $context );
			} elseif ( 1 === $attempts ) {
				// The first failure is logged as well: the cause is often only visible there.
				Log::exception( 'Job failed, will be retried', $e, $context, 'warning' );
			} else {
				Log::exception( 'Job failed again, will be retried', $e, $context, 'debug' );
			}
			$wpdb->update(
				$table,
				array(
					'status'          => $attempts >= $max ? 'failed' : 'queued',
					'attempts'        => $attempts,
					'next_attempt_at' => Db::now( time() + $delay ),
					'last_error_code' => Db::cut( $code, 64 ),
					'last_error'      => Db::cut( Log::plain( $e->getMessage() ), 255 ),
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
		// Being booked right now by another request (see ContractApi::record_sale()): a MySQL
		// user lock held by that request's connection (nothing is written for it).
		$inflight = self::inflight_key( $source, $order_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::inflight_lock( $inflight ) ) ) ) {
			return true;
		}
		// Marker row of older versions (and of a request still running during an update).
		wp_cache_delete( $inflight, 'options' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $inflight ) );
		if ( null !== $since && time() - (int) $since < 10 * MINUTE_IN_SECONDS ) {
			return true;
		}
		$table = Db::table( 'jobs' );
		$keys  = array( Db::cut( 'sale:' . $source . ':' . $order_id, 191 ), Db::cut( 'woo_order:' . $source . ':' . $order_id, 191 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE idempotency_key IN (%s, %s) AND status IN ('queued','running','failed') LIMIT 1", $keys[0], $keys[1] ) );
	}

	/**
	 * Whether refund jobs of an order are still waiting (queued, running, failed or parked).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 * @return bool
	 */
	public static function refunds_pending( $source, $order_id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE type = 'refund' AND idempotency_key LIKE %s AND status IN ('queued','running','failed','parked') LIMIT 1", $wpdb->esc_like( Db::cut( 'refund:' . $source . ':' . $order_id . ':', 191 ) ) . '%' ) );
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
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', next_attempt_at = %s, updated_at = %s WHERE type = 'refund' AND idempotency_key LIKE %s AND status IN ('queued','failed','parked')", Db::now(), Db::now(), $wpdb->esc_like( 'refund:' . $order_key . ':' ) . '%' ) );
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
		foreach ( array_unique( array_map( 'strval', $refund_ids ) ) as $rid ) {
			if ( '' === $rid ) {
				continue;
			}
			$key = Db::cut( 'refund:' . $source . ':' . $order_id . ':' . $rid, 191 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', last_error_code = 'superseded', last_error = 'Already deducted in the retried sale.', updated_at = %s WHERE idempotency_key = %s AND status IN ('queued','failed','parked','running')", Db::now(), $key ) );
			// A refund reported later (or still on its way, parallel request) must not be deducted
			// again: leave a closed marker that enqueue() and is_superseded() recognize.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (type, idempotency_key, payload, status, attempts, next_attempt_at, last_error_code, last_error, created_at, updated_at) VALUES ('refund', %s, '{}', 'done', 0, %s, 'superseded', 'Already deducted in the retried sale.', %s, %s)", $key, Db::now(), Db::now(), Db::now() ) );
		}
	}

	/**
	 * Close the parked refunds of an order (reported before the sale; the sale of a source
	 * without `netted_refund_ids` already deducted them, see ContractApi::after_booking()).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 */
	public static function supersede_parked( $source, $order_id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', last_error_code = 'superseded', last_error = 'Already deducted in the reported sale.', updated_at = %s WHERE type = 'refund' AND idempotency_key LIKE %s AND status = 'parked'", Db::now(), $wpdb->esc_like( Db::cut( 'refund:' . $source . ':' . $order_id . ':', 191 ) ) . '%' ) );
	}

	/**
	 * Whether a refund is already part of the booked sale of its order (supersede_refunds()).
	 *
	 * @param string $source    Source.
	 * @param string $order_id  Order ID.
	 * @param string $refund_id Refund ID.
	 * @return bool
	 */
	public static function is_superseded( $source, $order_id, $refund_id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$key   = Db::cut( 'refund:' . $source . ':' . $order_id . ':' . $refund_id, 191 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE idempotency_key = %s AND status = 'done' AND last_error_code = 'superseded'", $key ) );
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
	 * Name of the MySQL user lock for an in-flight marker. User locks are server-wide, so the
	 * database and table prefix are part of the name (several sites on one server). Max. 64 characters.
	 *
	 * @param string $inflight Marker name (inflight_key()).
	 * @return string
	 */
	public static function inflight_lock( $inflight ) {
		global $wpdb;
		return 'wpac_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . $inflight );
	}

	/**
	 * Mark a sale as being booked by this request (no database write; released at the latest
	 * when the connection ends, so a request that dies leaves nothing behind).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 * @return bool Whether this request holds the marker (false: a parallel request books it).
	 */
	public static function begin_inflight( $source, $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::inflight_lock( self::inflight_key( $source, $order_id ) ) ) );
	}

	/**
	 * Release the marker of begin_inflight().
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order ID.
	 */
	public static function end_inflight( $source, $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::inflight_lock( self::inflight_key( $source, $order_id ) ) ) );
	}

	/**
	 * Run a failed or waiting job now ("retry now"). Jobs that are running or done are left
	 * alone: a second run next to a running one would book in parallel.
	 *
	 * @param int $id Job ID.
	 * @return bool Whether the job ran successfully.
	 */
	public static function retry( $id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', next_attempt_at = %s, updated_at = %s WHERE id = %d AND status IN ('queued','failed','parked')", Db::now(), Db::now(), (int) $id ) );
		if ( 1 !== $n ) {
			return false;
		}
		Audit::log( 'job.retry', 'job', (int) $id );
		return self::run( (int) $id );
	}

	/**
	 * One job.
	 *
	 * @param int $id Job ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Dismiss a failed or parked job on purpose (it is not retried any more). Audited.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function dismiss( $id ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$job   = self::find( $id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', last_error_code = 'dismissed', last_error = 'Dismissed in the admin.', updated_at = %s WHERE id = %d AND status IN ('failed','parked')", Db::now(), (int) $id ) );
		if ( 1 !== $n ) {
			return false;
		}
		Audit::log(
			'job.dismissed',
			'job',
			(int) $id,
			array(
				'type' => $job ? $job->type : '',
				'key'  => $job ? $job->idempotency_key : '',
			)
		);
		return true;
	}

	/**
	 * Number of jobs with a status (the list itself is limited).
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( $status ) {
		global $wpdb;
		$table = Db::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", (string) $status ) );
	}

	/**
	 * Order a job is about ([ source, order ID ]) from its key type:source:order[:refund].
	 *
	 * @param object $job Job row.
	 * @return array|null
	 */
	public static function order_of( $job ) {
		$parts = explode( ':', (string) $job->idempotency_key );
		if ( count( $parts ) < 3 || '' === $parts[1] || '' === $parts[2] || ! in_array( (string) $job->type, array( 'sale', 'refund', 'reinstate', 'woo_reinstate', 'woo_increase' ), true ) ) {
			return null;
		}
		return array( $parts[1], $parts[2] );
	}

	/**
	 * Jobs (newest first).
	 *
	 * @param string $status Status ('' = all open and failed).
	 * @param int    $limit  Maximum.
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

	/**
	 * Jobs for the admin list (retry queue).
	 *
	 * @param array $args status ('' = all open and failed), search (key, type, error), limit, offset,
	 *                    orderby (id, type, created_at, status, attempts, next_attempt_at), order.
	 * @return array [ rows, total ]
	 */
	public static function query_paged( array $args = array() ) {
		global $wpdb;
		$where  = "status <> 'done'";
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where    = 'status = %s';
			$params[] = (string) $args['status'];
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( Db::cut( $search, 100 ) ) . '%';
			$where   .= ' AND ( idempotency_key LIKE %s OR type LIKE %s OR last_error_code LIKE %s OR last_error LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		return Paged::fetch( Db::table( 'jobs' ), $where, $params, $args, array( 'id', 'type', 'created_at', 'status', 'attempts', 'next_attempt_at' ) );
	}
}
