<?php
/**
 * WP-CLI commands: `wp wpac <command>`.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Privacy\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Operate WP Affiliate Captain from the command line.
 */
final class Command {

	/**
	 * Register the command.
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wpac', __CLASS__ );
		}
	}

	/**
	 * Show schema, queue, cron and account health.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac status
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ) {
		global $wpdb;
		$jobs  = Db::table( 'jobs' );
		$count = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$jobs} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n     = static function ( $s ) use ( $count ) {
			return isset( $count[ $s ] ) ? (int) $count[ $s ]->n : 0;
		};
		$problems = Installer::verify_schema();
		$rows     = array(
			array( 'check' => 'schema version', 'value' => get_option( Installer::DB_OPTION ) . ' / ' . WPAC_DB_VERSION ),
			array( 'check' => 'schema problems', 'value' => $problems ? implode( '; ', $problems ) : 'none' ),
			array( 'check' => 'jobs queued / running / failed', 'value' => $n( 'queued' ) . ' / ' . $n( 'running' ) . ' / ' . $n( 'failed' ) ),
			array( 'check' => 'next hourly run', 'value' => wp_next_scheduled( Cron::HOURLY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::HOURLY ) ) . ' UTC' : 'NOT SCHEDULED' ),
			array( 'check' => 'next daily run', 'value' => wp_next_scheduled( Cron::DAILY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::DAILY ) ) . ' UTC' : 'NOT SCHEDULED' ),
			array( 'check' => 'retention (years, hold)', 'value' => Retention::years() . ( Retention::on_hold() ? ', on hold' : '' ) ),
		);
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'check', 'value' ) );
	}

	/**
	 * Run the database migration now (instead of waiting for an admin or cron request).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function upgrade( $args, $assoc ) {
		Installer::install_schema();
		$problems = Installer::verify_schema();
		if ( $problems ) {
			\WP_CLI::error( 'Migration incomplete: ' . implode( '; ', $problems ) );
		}
		\WP_CLI::success( 'Schema version ' . get_option( Installer::DB_OPTION ) );
	}

	/**
	 * Work through the retry queue.
	 *
	 * ## OPTIONS
	 *
	 * [--retry-failed]
	 * : Put all failed jobs back into the queue first.
	 *
	 * [--limit=<n>]
	 * : Jobs to run (default 100).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function jobs( $args, $assoc ) {
		if ( isset( $assoc['retry-failed'] ) ) {
			\WP_CLI::log( Jobs::retry_all_failed() . ' failed jobs queued again.' );
		}
		Jobs::run_due( isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100, 0 );
		\WP_CLI::success( 'Queue processed.' );
	}

	/**
	 * Run the hourly or daily maintenance now.
	 *
	 * ## OPTIONS
	 *
	 * <which>
	 * : hourly|daily
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function cron( $args, $assoc ) {
		$which = isset( $args[0] ) ? $args[0] : '';
		if ( ! in_array( $which, array( 'hourly', 'daily' ), true ) ) {
			\WP_CLI::error( 'Use: wp wpac cron hourly|daily' );
		}
		call_user_func( array( Cron::class, $which ) );
		\WP_CLI::success( ucfirst( $which ) . ' maintenance done.' );
	}

	/**
	 * Check that every partner account matches its bookings (hash chain and sums).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function verify( $args, $assoc ) {
		$problems = Balance::verify_all();
		if ( $problems ) {
			foreach ( $problems as $p ) {
				\WP_CLI::warning( is_array( $p ) ? wp_json_encode( $p ) : (string) $p );
			}
			\WP_CLI::error( count( $problems ) . ' accounts differ.' );
		}
		\WP_CLI::success( 'All partner accounts are consistent.' );
	}
}
