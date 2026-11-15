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
 * The sweep runs from the plugin's hourly cron on the server that runs it and from WP-CLI
 * (`wp wpac statements sweep`, on every server, e.g. from its system cron), throttled by a marker
 * file in the local statement folder (never an option or transient: those are shared by all
 * servers) and within a time budget that includes listing the folder. It never runs in a visitor's
 * request unless the filter wpac_statement_sweep_on_requests turns that on (R12-M20). The marker
 * holds only hashes of the files remembered as unknown, never paths or file names (R12-K5).
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

	/** File names per database query. */
	const CHUNK = 200;

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
	 * @return array|null Stats, null when not due (or failed).
	 */
	private static function due( $context ) {
		if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return null;
		}
		$interval = self::interval();
		if ( $interval <= 0 ) {
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
			self::write_marker( $handle, array( 'last' => time(), 'unknown' => $unknown ) );

			$deadline = $budget > 0 ? microtime( true ) + $budget : 0.0;
			$listed   = true;
			$files    = self::list_files( array_merge( array( $dir ), Statement::legacy_dirs() ), $deadline, $listed );
			$stats['files']    = count( $files );
			$stats['complete'] = $listed;
			$keep_unknown   = array();
			foreach ( array_chunk( array_keys( $files ), self::CHUNK ) as $names ) {
				if ( $deadline > 0 && microtime( true ) > $deadline ) {
					$stats['complete'] = false;
					break;
				}
				$live   = self::referenced( $names );
				$hashes = array(); // SHA-256 => [ name, path ].
				foreach ( $names as $name ) {
					if ( isset( $live[ $name ] ) ) {
						continue;
					}
					foreach ( $files[ $name ] as $path ) {
						$sig = self::signature( $path );
						$key = self::key( $path );
						if ( ! $full && isset( $unknown[ $key ]['sig'], $unknown[ $key ]['at'] ) && $unknown[ $key ]['sig'] === $sig && time() - (int) $unknown[ $key ]['at'] < self::UNKNOWN_RECHECK ) {
							$keep_unknown[ $key ] = $unknown[ $key ];
							$stats['unknown'][]   = $path;
							continue;
						}
						if ( $deadline > 0 && microtime( true ) > $deadline ) {
							$stats['complete'] = false;
							break 2;
						}
						++$stats['candidates'];
						$hash = (string) @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( '' !== $hash ) {
							$hashes[ $hash ][] = array( $name, $path, $sig );
						}
					}
				}
				if ( ! $hashes ) {
					continue;
				}
				$deleted = self::deleted_hashes( array_keys( $hashes ) );
				foreach ( $hashes as $hash => $list ) {
					foreach ( $list as $entry ) {
						if ( isset( $deleted[ $hash ] ) ) {
							wp_delete_file( $entry[1] );
							if ( ! file_exists( $entry[1] ) ) {
								++$stats['deleted'];
							}
							continue;
						}
						$keep_unknown[ self::key( $entry[1] ) ] = array(
							'sig' => $entry[2],
							'at'  => time(),
						);
						$stats['unknown'][] = $entry[1];
					}
				}
			}
			if ( ! $stats['complete'] ) {
				$keep_unknown += $unknown; // Not reached this time: keep what is known.
			}
			self::write_marker( $handle, array( 'last' => time(), 'unknown' => array_slice( $keep_unknown, 0, 2000, true ) ) );
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
				if ( '' !== $name && '.' !== $name[0] && preg_match( '/\.pdf$/i', $name ) && is_file( $dir . '/' . $name ) ) {
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
	 * File names a payout, cancellation or correction credit note of this site still refers to
	 * (live documents). One query per column with an IN list (no OR across columns).
	 *
	 * @param string[] $names File names.
	 * @return array Name => true.
	 */
	private static function referenced( array $names ) {
		if ( ! $names ) {
			return array();
		}
		$out = array();
		foreach ( array( array( 'payouts', 'statement_file' ), array( 'payouts', 'storno_file' ), array( 'payout_corrections', 'file' ) ) as $col ) {
			foreach ( self::column_in( $col[0], $col[1], $names, '' ) as $file ) {
				if ( '' !== (string) $file ) {
					$out[ basename( (string) $file ) ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Hashes of documents deleted after the retention period (this site): credit notes,
	 * cancellations and correction credit notes.
	 *
	 * @param string[] $hashes SHA-256 values.
	 * @return array Hash => true.
	 */
	private static function deleted_hashes( array $hashes ) {
		$out = array();
		foreach ( array( array( 'payouts', 'statement_hash', 'statement_deleted_at IS NOT NULL' ), array( 'payouts', 'storno_hash', 'statement_deleted_at IS NOT NULL' ), array( 'payout_corrections', 'hash', 'file_deleted_at IS NOT NULL' ) ) as $col ) {
			foreach ( self::column_in( $col[0], $col[1], $hashes, $col[2] ) as $hash ) {
				if ( '' !== (string) $hash ) {
					$out[ (string) $hash ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Values of one column that are in a list (fixed table/column names, values as placeholders).
	 *
	 * @param string   $table  Table key.
	 * @param string   $column Column.
	 * @param string[] $values Values.
	 * @param string   $extra  Fixed extra condition ('' = none).
	 * @return string[]
	 * @throws \RuntimeException When the query fails (never treat a failed query as "not referenced").
	 */
	private static function column_in( $table, $column, array $values, $extra ) {
		global $wpdb;
		$t     = Db::table( $table );
		$marks = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$where = '' !== $extra ? ' AND ' . $extra : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed table/column names and %s placeholders built above.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$t} WHERE {$column} IN ({$marks}){$where}", $values ) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Statement sweep: ' . esc_html( $wpdb->last_error ) );
		}
		return array_map( 'strval', $rows );
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
