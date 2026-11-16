<?php
/**
 * Daily aggregation of the link visits (R14-D9): visits per partner, local day, campaign and
 * sub-ID, so the statistics of the partner area and the admin reports read a few thousand rows
 * instead of millions of raw visits.
 *
 * - Written on each recorded visit (one upsert, +1 per logged visit: the aggregate counts exactly
 *   the visits the sampling setting "visit_log_percent" writes to the log, like the raw reports).
 * - Existing visits are added by a resumable background job with a time budget (cursor on
 *   visits.id); only then the aggregate counts as complete and is read – before, the reports fall
 *   back to the raw visits.
 * - Days are the site's local days at the time of aggregation; when the time zone changes, the
 *   aggregate is rebuilt (the reports use the raw visits meanwhile).
 * - Live writes and the backfill never count a visit twice: the backfill covers the visit IDs up
 *   to a limit stored in the options table ("armed" in one statement with MAX(id)); a live write
 *   only happens for visits above that limit, checked by the database in the same statement.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Reports;

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Scheduler;

defined( 'ABSPATH' ) || exit;

final class VisitsDaily {

	/** Table (short name). */
	const TABLE = 'visits_daily';

	/** Schema version that has the table. */
	const SCHEMA = 15;

	/** Option (written with SQL, never cached): highest visit ID the backfill covers; live writes are for IDs above. */
	const UPTO = 'wpac_vdaily_upto';

	/** Option: last visit ID the backfill has added. */
	const CURSOR = 'wpac_vdaily_cursor';

	/** Option: time zone the aggregate was built in (its days are local days of this zone). */
	const TZ = 'wpac_vdaily_tz';

	/** Option: time zone of a completed backfill (the aggregate is read only when it matches). */
	const DONE = 'wpac_vdaily_done';

	/** Lock against two backfill runs at the same time. */
	const LOCK = 'wpac_vdaily_lock';

	/** Single event that continues the backfill. */
	const HOOK = 'wpac_visits_daily';

	/** All options of the aggregate (uninstall, reset). */
	const OPTIONS = array( self::UPTO, self::CURSOR, self::TZ, self::DONE, self::LOCK );

	/**
	 * Hooks: the background job; a changed time zone rebuilds the aggregate.
	 */
	public static function register_hooks() {
		add_action(
			self::HOOK,
			static function () {
				self::backfill();
			},
			10,
			0
		);
		foreach ( array( 'update_option_timezone_string', 'update_option_gmt_offset' ) as $hook ) {
			add_action(
				$hook,
				static function () {
					Scheduler::once( self::HOOK, 60 );
				},
				10,
				0
			);
		}
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Db::table( self::TABLE );
	}

	/**
	 * Whether the stored schema has the table (also during a later migration).
	 *
	 * @return bool
	 */
	public static function exists() {
		return (int) get_option( Installer::DB_OPTION, 0 ) >= self::SCHEMA;
	}

	/**
	 * Name of the site's time zone (e.g. "Europe/Berlin" or "+05:30").
	 *
	 * @return string
	 */
	public static function tz() {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return $tz->getName();
	}

	/**
	 * Whether the reports may read the aggregate: backfill complete in the current time zone.
	 *
	 * @return bool
	 */
	public static function ready() {
		$ready = self::exists() && self::tz() === (string) get_option( self::DONE, '' );
		/**
		 * Whether the reports read visit counts from the daily aggregate (default: once the
		 * backfill is complete in the current time zone; false = always the raw visits).
		 *
		 * @since 0.14.0
		 * @param bool $ready Decision.
		 */
		return (bool) apply_filters( 'wpac_visits_daily_ready', $ready );
	}

	/**
	 * Whether a period can be read from the aggregate (complete, valid local days).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return bool
	 */
	public static function usable( $from, $to ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) && self::ready();
	}

	/**
	 * Count a visit that was just written to the visit log (live part of the aggregate).
	 *
	 * @param int    $visit_id     ID of the visit row.
	 * @param int    $affiliate_id Partner.
	 * @param string $campaign     Campaign (cut to the column).
	 * @param string $sub_id       Sub-ID (cut to the column).
	 * @param string $created_at   UTC time of the visit (Y-m-d H:i:s).
	 */
	public static function record( $visit_id, $affiliate_id, $campaign, $sub_id, $created_at ) {
		global $wpdb;
		if ( (int) $visit_id <= 0 || ! Installer::schema_current() ) {
			return; // Table not there yet: the backfill adds the visit later.
		}
		$t = self::table();
		// Only above the limit of the backfill, decided by the database in this statement (no row
		// before the backfill was started: no write).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (affiliate_id, day, campaign, sub_id, visits) SELECT %d, %s, %s, %s, 1 FROM DUAL WHERE %d > (SELECT CAST(option_value AS UNSIGNED) FROM {$wpdb->options} WHERE option_name = %s) ON DUPLICATE KEY UPDATE visits = visits + 1", (int) $affiliate_id, Reports::local_time( (string) $created_at, 'Y-m-d' ), (string) $campaign, (string) $sub_id, (int) $visit_id, self::UPTO ) );
	}

	/**
	 * Limit of the backfill (highest visit ID it covers), -1 when not armed.
	 *
	 * @return int
	 */
	private static function upto() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::UPTO ) );
		return null === $v ? -1 : (int) $v;
	}

	/**
	 * Start over: empty the aggregate and arm the backfill for the current time zone.
	 *
	 * @param string $tz Time zone.
	 */
	private static function reset( $tz ) {
		global $wpdb;
		// Live writes stop first, then the rows go, then the limit is set together with MAX(id).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::UPTO ) );
		delete_option( self::DONE );
		delete_option( self::CURSOR );
		if ( false === $wpdb->query( 'TRUNCATE TABLE ' . self::table() ) ) {
			// No DROP privilege for TRUNCATE: delete the rows instead.
			\WPAffiliateCaptain\Core\Cron::delete_in_batches( 'DELETE FROM ' . self::table(), 5000, 0 );
		}
		$v = Db::table( 'visits' );
		// The new value comes from a derived table (src.upto), not from VALUES(): that is deprecated
		// since MySQL 8.0.20 (a warning on 9.7), and the row alias of MySQL does not exist on MariaDB.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, src.upto, 'off' FROM ( SELECT COALESCE(MAX(id), 0) AS upto FROM {$v} ) AS src ON DUPLICATE KEY UPDATE option_value = src.upto", self::UPTO ) );
		// phpcs:enable
		wp_cache_delete( self::UPTO, 'options' );
		update_option( self::TZ, $tz, false );
		update_option( self::CURSOR, 0, false );
		Log::debug( 'Daily visit aggregation: backfill started', array( 'upto' => self::upto(), 'timezone' => $tz ) );
	}

	/**
	 * Hourly check: start or continue the backfill when needed (cheap when complete).
	 */
	public static function maintain() {
		if ( self::exists() && self::tz() === (string) get_option( self::DONE, '' ) && self::upto() >= 0 ) {
			return;
		}
		self::backfill();
	}

	/**
	 * Add the existing visits to the aggregate within a time budget; the rest continues in a single
	 * event a minute later. Resumable: the cursor moves in the same transaction as the counts.
	 *
	 * @param float|null $budget Seconds (null: filter wpac_visits_daily_budget, default 20; 0 = until done).
	 * @return bool Whether the aggregate is complete.
	 */
	public static function backfill( $budget = null ) {
		global $wpdb;
		if ( ! Installer::schema_current() ) {
			return false;
		}
		if ( null === $budget ) {
			/**
			 * Seconds one run of the visit aggregation backfill may take.
			 *
			 * @since 0.14.0
			 * @param float $budget Seconds (default 20, 0 = until done).
			 */
			$budget = (float) apply_filters( 'wpac_visits_daily_budget', 20 );
		}
		/**
		 * Visit IDs the backfill reads per step (one short transaction each).
		 *
		 * @since 0.14.0
		 * @param int $step IDs (default 20000).
		 */
		$step  = max( 1, (int) apply_filters( 'wpac_visits_daily_batch', 20000 ) );
		$end   = $budget > 0 ? microtime( true ) + (float) $budget : 0.0;
		$owner = Db::lock( self::LOCK, 300 );
		if ( '' === $owner ) {
			return false; // Another run is busy.
		}
		try {
			$tz = self::tz();
			if ( (string) get_option( self::TZ, '' ) !== $tz || self::upto() < 0 ) {
				self::reset( $tz );
			}
			if ( $tz === (string) get_option( self::DONE, '' ) ) {
				return true;
			}
			$upto  = self::upto();
			$v     = Db::table( 'visits' );
			$first = true;
			// Partners whose visits are being erased are left out (their raw rows are going).
			$erasing = array_filter( array_map( 'intval', (array) get_option( \WPAffiliateCaptain\Privacy\Privacy::VISITS_ERASE_OPTION, array() ) ) );
			$skip    = $erasing ? ' AND affiliate_id NOT IN (' . implode( ',', $erasing ) . ')' : '';
			while ( true ) {
				$cursor = (int) get_option( self::CURSOR, 0 );
				if ( $cursor >= $upto ) {
					update_option( self::DONE, $tz, false );
					Scheduler::clear( self::HOOK );
					Log::debug( 'Daily visit aggregation: backfill complete', array( 'upto' => $upto ) );
					return true;
				}
				if ( ! $first && $end > 0 && microtime( true ) > $end ) {
					break;
				}
				$first = false;
				$hi    = min( $upto, $cursor + $step );
				// Sums per UTC minute; PHP puts them into local days (offsets change with DST, some are :30/:45).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, LEFT(created_at, 16) m, campaign, sub_id, COUNT(*) n FROM {$v} WHERE id > %d AND id <= %d{$skip} GROUP BY affiliate_id, m, campaign, sub_id", $cursor, $hi ) );
				if ( '' !== (string) $wpdb->last_error ) {
					Log::warning( 'Daily visit aggregation: backfill step failed', array( 'error' => Db::cut( $wpdb->last_error, 200 ) ) );
					break;
				}
				$next = $hi;
				if ( ! $rows ) {
					// Gap (deleted visits): jump to the next existing ID.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$following = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$v} WHERE id > %d", $hi ) );
					$next      = null === $following ? $upto : min( $upto, (int) $following - 1 );
				}
				$sums  = self::sum_by_day( $rows );
				$moved = Db::transaction(
					static function () use ( $sums, $next ) {
						self::add( $sums );
						return update_option( self::CURSOR, max( 0, (int) $next ), false );
					}
				);
				if ( ! $moved ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			Log::exception( 'Daily visit aggregation: backfill failed', $e );
		} finally {
			Db::unlock( self::LOCK, $owner );
		}
		Scheduler::once( self::HOOK, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Group raw sums per UTC minute into local days.
	 *
	 * @param object[] $rows affiliate_id, m (Y-m-d H:i, UTC), campaign, sub_id, n.
	 * @return array[] [ affiliate_id, day, campaign, sub_id, visits ]
	 */
	private static function sum_by_day( array $rows ) {
		$out  = array();
		$days = array();
		foreach ( $rows as $r ) {
			$m = (string) $r->m;
			if ( ! isset( $days[ $m ] ) ) {
				$days[ $m ] = Reports::local_time( $m . ':00', 'Y-m-d' );
			}
			$key = (int) $r->affiliate_id . "\n" . $days[ $m ] . "\n" . $r->campaign . "\n" . $r->sub_id;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( (int) $r->affiliate_id, $days[ $m ], (string) $r->campaign, (string) $r->sub_id, 0 );
			}
			$out[ $key ][4] += (int) $r->n;
		}
		return array_values( $out );
	}

	/**
	 * Add counts to the aggregate (multi-row upserts).
	 *
	 * @param array[] $sums [ affiliate_id, day, campaign, sub_id, visits ].
	 * @throws \WPAffiliateCaptain\Core\IntegrationException When a write fails (the transaction is rolled back).
	 */
	private static function add( array $sums ) {
		global $wpdb;
		$t = self::table();
		foreach ( array_chunk( $sums, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $s ) {
				$values[] = $wpdb->prepare( 'SELECT %d AS a, %s AS d, %s AS c, %s AS s, %d AS n', $s[0], $s[1], $s[2], $s[3], $s[4] );
			}
			// The rows come from a derived table, so the update adds src.n: VALUES(col) in ON DUPLICATE
			// KEY UPDATE is deprecated since MySQL 8.0.20 (a warning on 9.7), and the row alias
			// "AS new" of MySQL does not exist on MariaDB. This form works on both without a warning.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- values prepared above.
			$wpdb->query( "INSERT INTO {$t} (affiliate_id, day, campaign, sub_id, visits) SELECT src.a, src.d, src.c, src.s, src.n FROM ( " . implode( ' UNION ALL ', $values ) . ' ) AS src ON DUPLICATE KEY UPDATE visits = visits + src.n' );
			Db::check( 'Daily visit aggregation' );
		}
	}

	/**
	 * Delete the aggregate rows of a partner (erasure), in short statements.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param int   $batch        Rows per statement.
	 * @param float $deadline     microtime() limit (0 = until done).
	 * @return bool Whether nothing is left.
	 */
	public static function delete_partner( $affiliate_id, $batch = 5000, $deadline = 0.0 ) {
		global $wpdb;
		if ( ! self::exists() ) {
			return true;
		}
		$done = false;
		$t    = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		\WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$t} WHERE affiliate_id = %d", (int) $affiliate_id ), max( 1, (int) $batch ), 0, (float) $deadline, $done );
		return $done;
	}

	/**
	 * Apply the visit retention: delete the days before the local day of the cutoff (the day of
	 * the cutoff itself stays until it is completely past the retention).
	 *
	 * @param string $cutoff   UTC time (Y-m-d H:i:s): raw visits before it are deleted.
	 * @param int    $batch    Rows per statement.
	 * @param float  $deadline microtime() limit (0 = until done).
	 * @return bool Whether nothing is left.
	 */
	public static function purge( $cutoff, $batch = 5000, $deadline = 0.0 ) {
		global $wpdb;
		if ( ! self::exists() ) {
			return true;
		}
		$done = false;
		$t    = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		\WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", Reports::local_time( (string) $cutoff, 'Y-m-d' ) ), max( 1, (int) $batch ), 0, (float) $deadline, $done );
		return $done;
	}
}
