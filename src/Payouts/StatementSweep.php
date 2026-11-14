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
 * The sweep runs on the server's own requests, throttled by a marker file in the local statement
 * folder (never an option or transient: those are shared by all servers), within a small time
 * budget; operators can run it on every server with `wp wpac statements sweep`.
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
	 * Register the sweep on this server's requests.
	 */
	public static function register_hooks() {
		add_action( 'shutdown', array( __CLASS__, 'maybe_run' ), 100 );
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
		 * @param int $seconds Default 6 hours; 0 = no sweep on requests (run `wp wpac statements sweep`).
		 */
		return max( 0, (int) apply_filters( 'wpac_statement_sweep_interval', 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * End of a request: sweep when this server's last sweep is older than the interval. Costs one
	 * file status lookup otherwise.
	 */
	public static function maybe_run() {
		if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}
		$interval = self::interval();
		if ( $interval <= 0 ) {
			return;
		}
		try {
			$dir = Statement::configured_dir();
			if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
				return; // No folder on this server: no document to delete.
			}
			$last = @filemtime( $dir . '/' . self::MARKER ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $last && time() - (int) $last < $interval ) {
				return;
			}
			/**
			 * Seconds a sweep at the end of a request may take (the rest follows next time).
			 *
			 * @param float $budget Seconds.
			 */
			self::run( (float) apply_filters( 'wpac_statement_sweep_budget', 2.0 ) );
		} catch ( \Throwable $e ) {
			\WPAffiliateCaptain\Core\Log::exception( 'Statement sweep failed', $e );
		}
	}

	/**
	 * Sweep the local statement folder (and the earlier folders) of the current site.
	 *
	 * @param float $budget Seconds (0 = until done).
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
			self::write_marker( $handle, array( 'last' => time(), 'unknown' => $unknown ) );

			$deadline = $budget > 0 ? microtime( true ) + $budget : 0.0;
			$files    = self::list_files( array_merge( array( $dir ), Statement::legacy_dirs() ) );
			$stats['files'] = count( $files );
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
						if ( ! $full && isset( $unknown[ $path ] ) && $unknown[ $path ]['sig'] === $sig && time() - (int) $unknown[ $path ]['at'] < self::UNKNOWN_RECHECK ) {
							$keep_unknown[ $path ] = $unknown[ $path ];
							$stats['unknown'][]    = $path;
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
						$keep_unknown[ $entry[1] ] = array(
							'sig' => $entry[2],
							'at'  => time(),
						);
						$stats['unknown'][]        = $entry[1];
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
	 * PDF files of the folders: name => paths.
	 *
	 * @param string[] $dirs Folders.
	 * @return array
	 */
	private static function list_files( array $dirs ) {
		$out = array();
		foreach ( array_unique( $dirs ) as $dir ) {
			$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( '.' === $name[0] || ! preg_match( '/\.pdf$/i', $name ) || ! is_file( $dir . '/' . $name ) ) {
					continue;
				}
				$out[ $name ][] = $dir . '/' . $name;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * File names a payout of this site still refers to (live documents).
	 *
	 * @param string[] $names File names.
	 * @return array Name => true.
	 */
	private static function referenced( array $names ) {
		global $wpdb;
		if ( ! $names ) {
			return array();
		}
		$t     = Db::table( 'payouts' );
		$marks = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholders only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT statement_file, storno_file FROM {$t} WHERE statement_file IN ({$marks}) OR storno_file IN ({$marks})", array_merge( $names, $names ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Statement sweep: ' . esc_html( $wpdb->last_error ) ); // Never treat a failed query as "not referenced".
		}
		$out = array();
		foreach ( $rows as $r ) {
			foreach ( array( $r->statement_file, $r->storno_file ) as $file ) {
				if ( '' !== (string) $file ) {
					$out[ basename( (string) $file ) ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Hashes of documents deleted after the retention period (this site).
	 *
	 * @param string[] $hashes SHA-256 values.
	 * @return array Hash => true.
	 */
	private static function deleted_hashes( array $hashes ) {
		global $wpdb;
		$t     = Db::table( 'payouts' );
		$marks = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholders only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT statement_hash, storno_hash FROM {$t} WHERE statement_deleted_at IS NOT NULL AND ( statement_hash IN ({$marks}) OR storno_hash IN ({$marks}) )", array_merge( $hashes, $hashes ) ) );
		$out  = array();
		foreach ( $rows as $r ) {
			foreach ( array( $r->statement_hash, $r->storno_hash ) as $hash ) {
				if ( '' !== (string) $hash ) {
					$out[ (string) $hash ] = true;
				}
			}
		}
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
