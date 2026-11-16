<?php
/**
 * Retention deletion of credit notes on every app server.
 *
 * The database is the source of truth: Statement::purge_expired() deletes the PDFs it finds on the
 * server that runs the daily cron and marks the payout as deleted (statement_deleted_at). Without
 * shared storage, copies stay on the other servers (e.g. rebuilt there for a download). Each server
 * therefore sweeps its own statement folder: a PDF that no live payout refers to and whose SHA-256
 * belongs to a credit note or cancellation deleted after the retention period is removed. Nothing
 * else is ever deleted (files of other sites in a shared folder, unknown files, temporary files).
 *
 * Only needed for the local folder: with object storage (Statement::store(), WPAC_S3_*) the
 * retention deletion removes the one shared copy and the automatic sweep is skipped.
 *
 * The sweep runs from the plugin's hourly cron on the server that runs it and from WP-CLI
 * (`wp wpac statements sweep`, on every server, e.g. from its system cron), throttled by a marker
 * file in the local statement folder (never an option or transient: those are shared by all
 * servers) and within a time budget that includes listing the folder. It never runs in a visitor's
 * request unless the filter wpac_statement_sweep_on_requests turns that on (R12-M20). The marker
 * holds only hashes of the files remembered as unknown, never paths or file names (R12-K5), and
 * the hash of the first file name a sweep that ran out of time did not reach: the next sweep
 * continues there (R13-M10). Referenced file names and deleted hashes are read once per sweep
 * into hash maps (no query per file on unindexed columns).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;

defined( 'ABSPATH' ) || exit;

final class StatementSweep {

	/** Marker file in the statement folder: time of the last sweep on this server, unknown files. */
	const MARKER = '.wpac-sweep';

	/** Unknown files are hashed again after this time (seconds). */
	const UNKNOWN_RECHECK = 7 * DAY_IN_SECONDS;

	/** Rows per primary key batch when reading referenced names and hashes. */
	const BATCH = 5000;

	/**
	 * Register the sweep at the end of requests - only when the filter turns it on (default off:
	 * the sweep runs from the hourly cron and WP-CLI, never in a visitor's request).
	 */
	public static function register_hooks() {
		/**
		 * Whether this server also sweeps at the end of its own requests (after the response where
		 * the server supports fastcgi_finish_request()). Default false: hourly cron and WP-CLI only.
		 *
		 * @param bool $on Sweep on requests.
		 */
		if ( apply_filters( 'wpac_statement_sweep_on_requests', false ) ) {
			add_action( 'shutdown', array( __CLASS__, 'maybe_run' ), 100 );
		}
	}

	/**
	 * Hourly cron: sweep this server's folder when its last sweep is older than the interval.
	 *
	 * @return array|null Stats of the sweep, null when not due.
	 */
	public static function scheduled() {
		return self::due( 'cron' );
	}

	/**
	 * Seconds between two sweeps on one server (filter wpac_statement_sweep_interval, 0 = only by
	 * WP-CLI).
	 *
	 * @return int
	 */
	public static function interval() {
		/**
		 * Seconds between two sweeps of the local statement folder on one app server.
		 *
		 * @param int $seconds Default 6 hours; 0 = no automatic sweep (run `wp wpac statements sweep`).
		 */
		return max( 0, (int) apply_filters( 'wpac_statement_sweep_interval', 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * End of a request (only with the filter wpac_statement_sweep_on_requests): sweep when this
	 * server's last sweep is older than the interval, after the response has been sent where the
	 * server supports it. Costs one file status lookup otherwise.
	 */
	public static function maybe_run() {
		self::due( 'request' );
	}

	/**
	 * Sweep when this server's last sweep is older than the interval.
	 *
	 * @param string $context cron|request.
	 * @return array|null Stats, null when not due (or failed at the end of a request).
	 * @throws \Throwable In the cron, when the sweep fails (recorded as a failed step).
	 */
	private static function due( $context ) {
		if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return null;
		}
		$interval = self::interval();
		// Object storage (or a custom store): every server deletes the one copy in the store, so the
		// automatic sweep is not needed (R6-CC5). Local copies left from before the migration are
		// moved by "wp wpac statements migrate --to=s3"; "wp wpac statements sweep" still works.
		if ( $interval <= 0 || ! Statement::local_store() ) {
			return null;
		}
		try {
			$dir = Statement::configured_dir();
			if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
				return null; // No folder on this server: no document to delete.
			}
			$last = @filemtime( $dir . '/' . self::MARKER ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $last && time() - (int) $last < $interval ) {
				return null;
			}
			if ( 'request' === $context && function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request(); // The visitor does not wait for the sweep.
			}
			/**
			 * Seconds one automatic sweep may take, listing the folder included (the rest follows
			 * next time).
			 *
			 * @param float  $budget  Seconds (default 20 in the cron, 2 at the end of a request).
			 * @param string $context cron|request.
			 */
			return self::run( (float) apply_filters( 'wpac_statement_sweep_budget', 'cron' === $context ? 20.0 : 2.0, $context ) );
		} catch ( \Throwable $e ) {
			if ( 'cron' === $context ) {
				throw $e; // Cron::run_step() records it: cron monitor, Site Health, alarm mail (R13-N11).
			}
			\WPAffiliateCaptain\Core\Log::exception( 'Statement sweep failed', $e );
			return null;
		}
	}

	/**
	 * Sweep the local statement folder (and the earlier folders) of the current site.
	 *
	 * @param float $budget Seconds (0 = until done), listing the folder included.
	 * @param bool  $full   Hash every candidate again, also files remembered as unknown.
	 * @return array{files:int,candidates:int,deleted:int,unknown:string[],complete:bool,locked:bool}
	 */
	public static function run( $budget = 2.0, $full = false ) {
		$stats = array(
			'files'      => 0,
			'candidates' => 0,
			'deleted'    => 0,
			'unknown'    => array(),
			'complete'   => true,
			'locked'     => false,
		);
		$dir   = Statement::configured_dir();
		if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $stats;
		}
		// One sweep per server and folder at a time; the marker is written first, so a sweep that
		// dies (time limit) does not start again on every request.
		$handle = @fopen( $dir . '/' . self::MARKER, 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return $stats;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$stats['locked'] = true;
			return $stats;
		}
		try {
			$state   = json_decode( (string) stream_get_contents( $handle ), true );
			$state   = is_array( $state ) ? $state : array();
			$unknown = isset( $state['unknown'] ) && is_array( $state['unknown'] ) ? $state['unknown'] : array();
			// Only hashed keys: entries of older versions keyed by path are dropped (hashed again).
			$unknown = array_filter(
				$unknown,
				static function ( $key ) {
					return (bool) preg_match( '/^[0-9a-f]{64}$/', (string) $key );
				},
				ARRAY_FILTER_USE_KEY
			);
			// Where the last sweep stopped: the hash of the first file name it did not reach (the
			// marker never holds names). The next sweep starts there and wraps around (R13-M10).
			$cursor = isset( $state['cursor'] ) && is_string( $state['cursor'] ) && preg_match( '/^[0-9a-f]{64}$/', $state['cursor'] ) ? $state['cursor'] : '';
			self::write_marker(
				$handle,
				array(
					'last'    => time(),
					'unknown' => $unknown,
					'cursor'  => $cursor,
				)
			);

			$deadline          = $budget > 0 ? microtime( true ) + $budget : 0.0;
			$listed            = true;
			$files             = self::list_files( array_merge( array( $dir ), Statement::legacy_dirs() ), $deadline, $listed );
			$stats['files']    = count( $files );
			$stats['complete'] = $listed;
			$names             = self::from_cursor( array_keys( $files ), $cursor );
			$cursor            = '';
			$keep_unknown      = array();
			// Referenced names and deleted hashes: loaded once per sweep into hash maps (primary
			// key batches) instead of one IN() query per 200 names on unindexed columns (R13-M10).
			$live    = $names ? self::referenced() : array();
			$deleted = null;
			foreach ( $names as $name ) {
				if ( isset( $live[ $name ] ) ) {
					continue;
				}
				foreach ( $files[ $name ] as $path ) {
					if ( ! is_file( $path ) ) {
						continue;
					}
					$sig = self::signature( $path );
					$key = self::key( $path );
					if ( ! $full && isset( $unknown[ $key ]['sig'], $unknown[ $key ]['at'] ) && $unknown[ $key ]['sig'] === $sig && time() - (int) $unknown[ $key ]['at'] < self::UNKNOWN_RECHECK ) {
						$keep_unknown[ $key ] = $unknown[ $key ];
						$stats['unknown'][]   = $path;
						continue;
					}
					// At least one file per sweep: the cursor moves on even with a tiny budget.
					if ( $deadline > 0 && $stats['candidates'] > 0 && microtime( true ) > $deadline ) {
						$stats['complete'] = false;
						$cursor            = self::key( $name );
						break 2;
					}
					++$stats['candidates'];
					$hash = (string) @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( '' === $hash ) {
						continue;
					}
					if ( null === $deleted ) {
						$deleted = self::deleted_hashes();
					}
					if ( isset( $deleted[ $hash ] ) ) {
						wp_delete_file( $path );
						if ( ! file_exists( $path ) ) {
							++$stats['deleted'];
						}
						continue;
					}
					$keep_unknown[ $key ] = array(
						'sig' => $sig,
						'at'  => time(),
					);
					$stats['unknown'][]   = $path;
				}
			}
			if ( ! $stats['complete'] ) {
				$keep_unknown += $unknown; // Not reached this time: keep what is known.
			}
			self::write_marker(
				$handle,
				array(
					'last'    => time(),
					'unknown' => array_slice( $keep_unknown, 0, 2000, true ),
					'cursor'  => $cursor,
				)
			);
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $stats['deleted'] ) {
			Audit::log(
				'statement.retention_swept',
				'payout',
				0,
				array(
					'files'  => $stats['deleted'],
					'server' => Db::cut( (string) gethostname(), 64 ),
				)
			);
		}
		return $stats;
	}

	/**
	 * Delete a local copy of a document deleted after the retention period (download path). Only
	 * the file with the document's hash goes.
	 *
	 * @param string $path Local path.
	 * @param string $hash Stored SHA-256 ('' = older document without hash).
	 * @return bool Whether a file was deleted.
	 */
	public static function forget( $path, $hash ) {
		$path = (string) $path;
		if ( '' === basename( $path ) || '.' === basename( $path )[0] || ! is_file( $path ) ) {
			return false;
		}
		if ( '' !== (string) $hash && ! hash_equals( (string) $hash, (string) hash_file( 'sha256', $path ) ) ) {
			return false;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Key of a file in the marker: a hash of its path (the marker never holds paths or names).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function key( $path ) {
		return hash( 'sha256', (string) $path );
	}

	/**
	 * PDF files of the folders: name => paths. Stops at the deadline (the listing counts toward
	 * the time budget); $complete tells whether every folder was listed completely.
	 *
	 * @param string[] $dirs     Folders.
	 * @param float    $deadline microtime() deadline (0 = none).
	 * @param bool     $complete Set to false when the deadline stopped the listing.
	 * @return array
	 */
	private static function list_files( array $dirs, $deadline = 0.0, &$complete = true ) {
		$out      = array();
		$complete = true;
		$n        = 0;
		foreach ( array_unique( $dirs ) as $dir ) {
			$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				continue;
			}
			$name = readdir( $handle );
			while ( false !== $name ) {
				if ( $deadline > 0 && 0 === ( ++$n % 200 ) && microtime( true ) > $deadline ) {
					$complete = false;
					break;
				}
				// No file status lookup per entry (slow on network storage): only for candidates.
				if ( '' !== $name && '.' !== $name[0] && preg_match( '/\.pdf$/i', $name ) ) {
					$out[ $name ][] = $dir . '/' . $name;
				}
				$name = readdir( $handle );
			}
			closedir( $handle );
			if ( ! $complete ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * File names in the order of this sweep: from the name the last sweep did not reach (by its
	 * hash), wrapping around to the start.
	 *
	 * @param string[] $names  Sorted file names.
	 * @param string   $cursor Hash of the first name not reached ('' = from the start).
	 * @return string[]
	 */
	private static function from_cursor( array $names, $cursor ) {
		if ( '' === $cursor ) {
			return $names;
		}
		foreach ( $names as $i => $name ) {
			if ( hash_equals( $cursor, self::key( (string) $name ) ) ) {
				return array_merge( array_slice( $names, $i ), array_slice( $names, 0, $i ) );
			}
		}
		return $names; // The file is gone: from the start.
	}

	/**
	 * File names a payout, cancellation or correction credit note of this site still refers to
	 * (live documents), read once per sweep in primary key batches.
	 *
	 * @return array Name => true.
	 */
	private static function referenced() {
		$out = array();
		foreach ( self::column_values( 'payouts', array( 'statement_file', 'storno_file' ), "statement_file <> '' OR storno_file <> ''" ) as $file ) {
			$out[ basename( $file ) ] = true;
		}
		foreach ( self::column_values( 'payout_corrections', array( 'file' ), "file <> ''" ) as $file ) {
			$out[ basename( $file ) ] = true;
		}
		return $out;
	}

	/**
	 * Hashes of documents deleted after the retention period (this site): credit notes,
	 * cancellations and correction credit notes, read once per sweep.
	 *
	 * @return array Hash => true.
	 */
	private static function deleted_hashes() {
		$out = array();
		foreach ( self::column_values( 'payouts', array( 'statement_hash', 'storno_hash' ), 'statement_deleted_at IS NOT NULL' ) as $hash ) {
			$out[ $hash ] = true;
		}
		foreach ( self::column_values( 'payout_corrections', array( 'hash' ), 'file_deleted_at IS NOT NULL' ) as $hash ) {
			$out[ $hash ] = true;
		}
		return $out;
	}

	/**
	 * Non-empty values of some columns of the rows matching a fixed condition, read along the
	 * primary key in batches (fixed table/column names).
	 *
	 * @param string   $table   Table key.
	 * @param string[] $columns Columns.
	 * @param string   $where   Fixed condition.
	 * @return string[]
	 * @throws \RuntimeException When a query fails (never treat a failed query as "not referenced").
	 */
	private static function column_values( $table, array $columns, $where ) {
		global $wpdb;
		$t    = Db::table( $table );
		$cols = implode( ', ', $columns );
		$out  = array();
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table/column names and condition.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, {$cols} FROM {$t} WHERE id > %d AND ( {$where} ) ORDER BY id ASC LIMIT %d", $last, self::BATCH ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'Statement sweep: ' . esc_html( $wpdb->last_error ) );
			}
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				foreach ( $columns as $column ) {
					if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
						$out[] = (string) $row[ $column ];
					}
				}
			}
		} while ( count( $rows ) === self::BATCH );
		return $out;
	}

	/**
	 * Size and modification time of a file (an unknown file is hashed again when it changed).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function signature( $path ) {
		return (int) @filesize( $path ) . '-' . (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Replace the marker's content (the handle stays locked).
	 *
	 * @param resource $handle Open marker.
	 * @param array    $state  State.
	 */
	private static function write_marker( $handle, array $state ) {
		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, (string) wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fflush( $handle );
	}

	/**
	 * Time of the last sweep on this server (0 = never), for `wp wpac statements sweep --status`.
	 *
	 * @return int
	 */
	public static function last_run() {
		$path = Statement::configured_dir() . '/' . self::MARKER;
		$time = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $time ? 0 : (int) $time;
	}
}
