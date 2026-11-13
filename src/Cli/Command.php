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
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Privacy\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Operate Affiliate Captain from the command line.
 *
 * On a multisite network every command works on one site (the main site, or the one given with
 * --url); with --network it runs on every site of the network where the plugin is active.
 */
final class Command {

	/**
	 * Register the command.
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wpac', __CLASS__ );
			\WP_CLI::add_command( 'wpac import', ImportCommand::class );
			\WP_CLI::add_command( 'wpac export', ExportCommand::class );
		}
	}

	/**
	 * Run a callback on the current site, or with --network on every site of the network where
	 * the plugin is active. Problems of one site do not stop the others; the exit code reports them.
	 *
	 * @param array    $assoc Options.
	 * @param callable $fn    Callback; returns a list of problems (empty = fine).
	 */
	private static function each_site( $assoc, callable $fn ) {
		if ( empty( $assoc['network'] ) || ! is_multisite() ) {
			$problems = (array) $fn();
			if ( $problems ) {
				\WP_CLI::error( implode( '; ', array_map( 'strval', $problems ) ) );
			}
			return;
		}
		$failed = 0;
		foreach ( self::active_sites() as $id ) {
			switch_to_blog( $id );
			try {
				\WP_CLI::log( sprintf( '--- Site %d (%s)', $id, home_url() ) );
				$problems = (array) $fn();
			} catch ( \Throwable $e ) {
				$problems = array( $e->getMessage() );
			} finally {
				restore_current_blog();
			}
			if ( $problems ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Site %d: %s', $id, implode( '; ', array_map( 'strval', $problems ) ) ) );
			}
		}
		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d site(s) reported problems.', $failed ) );
		}
	}

	/**
	 * Sites of the current network where the plugin is active (network-wide or per site).
	 *
	 * @return int[]
	 */
	public static function active_sites() {
		$ids     = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) ) );
		$network = Installer::network_active();
		$out     = array();
		foreach ( $ids as $id ) {
			if ( $network || in_array( plugin_basename( WPAC_FILE ), (array) get_blog_option( $id, 'active_plugins', array() ), true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Show schema, queue, cron and account health.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : On every site of the network where the plugin is active.
	 *
	 * [--format=<format>]
	 * : table, json, csv …
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac status
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ) {
		self::each_site(
			$assoc,
			static function () use ( $assoc ) {
				global $wpdb;
				$jobs  = Db::table( 'jobs' );
				$count = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$jobs} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$n     = static function ( $s ) use ( $count ) {
					return isset( $count[ $s ] ) ? (int) $count[ $s ]->n : 0;
				};
				$problems = Installer::verify_schema();
				$last     = (int) get_option( Cron::LAST_RUN, 0 );
				$steps    = Cron::failures();
				$rows     = array(
					array( 'check' => 'schema version', 'value' => get_option( Installer::DB_OPTION ) . ' / ' . Installer::db_version() ),
					array( 'check' => 'schema problems', 'value' => $problems ? implode( '; ', $problems ) : 'none' ),
					array( 'check' => 'jobs queued / running / failed', 'value' => $n( 'queued' ) . ' / ' . $n( 'running' ) . ' / ' . $n( 'failed' ) ),
					array( 'check' => 'scheduled tasks running', 'value' => Monitor::cron_running() ? 'yes' : 'NO' ),
					array( 'check' => 'last hourly run', 'value' => $last ? gmdate( 'Y-m-d H:i', $last ) . ' UTC' : 'unknown' ),
					array( 'check' => 'next hourly run', 'value' => wp_next_scheduled( Cron::HOURLY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::HOURLY ) ) . ' UTC' : 'NOT SCHEDULED' ),
					array( 'check' => 'next daily run', 'value' => wp_next_scheduled( Cron::DAILY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::DAILY ) ) . ' UTC' : 'NOT SCHEDULED' ),
					array( 'check' => 'failing steps', 'value' => $steps ? implode( ', ', array_keys( $steps ) ) : 'none' ),
					array( 'check' => 'retention (years, hold)', 'value' => Retention::years() . ( Retention::on_hold() ? ', on hold' : '' ) ),
				);
				\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'check', 'value' ) );
				return array();
			}
		);
	}

	/**
	 * Run the database migration now (instead of waiting for an admin or cron request).
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : On every site of the network where the plugin is active.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function upgrade( $args, $assoc ) {
		self::each_site(
			$assoc,
			static function () {
				$newer = Installer::downgraded_from();
				if ( $newer ) {
					return array( sprintf( 'The database belongs to a newer plugin version (schema %1$d, this code knows %2$d). Nothing was changed; install the newer version again.', $newer, Installer::db_version() ) );
				}
				// Under the same lock as the automatic migration (never two migrations in parallel).
				if ( ! Installer::upgrade() ) {
					return array( 'Another process is running the migration right now. Please try again in a few minutes.' );
				}
				$problems = get_option( 'wpac_schema_problems' ); // Also an aborted migration.
				if ( ! is_array( $problems ) || ! $problems ) {
					$problems = Installer::verify_schema();
				}
				if ( $problems ) {
					return array( 'Migration incomplete: ' . implode( '; ', $problems ) );
				}
				\WP_CLI::success( 'Schema version ' . get_option( Installer::DB_OPTION ) );
				return array();
			}
		);
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
	 * [--network]
	 * : On every site of the network where the plugin is active.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function jobs( $args, $assoc ) {
		self::each_site(
			$assoc,
			static function () use ( $assoc ) {
				if ( isset( $assoc['retry-failed'] ) ) {
					\WP_CLI::log( Jobs::retry_all_failed() . ' failed jobs queued again.' );
				}
				Jobs::run_due( isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100, 0 );
				\WP_CLI::success( 'Queue processed.' );
				return array();
			}
		);
	}

	/**
	 * Run the hourly or daily maintenance now.
	 *
	 * ## OPTIONS
	 *
	 * <which>
	 * : hourly|daily
	 *
	 * [--network]
	 * : On every site of the network where the plugin is active. A system cron of a network must
	 * reach every site, e.g. "wp wpac cron hourly --network".
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function cron( $args, $assoc ) {
		$which = isset( $args[0] ) ? $args[0] : '';
		if ( ! in_array( $which, array( 'hourly', 'daily' ), true ) ) {
			\WP_CLI::error( 'Use: wp wpac cron hourly|daily' );
		}
		self::each_site(
			$assoc,
			static function () use ( $which ) {
				call_user_func( array( Cron::class, $which ) );
				\WP_CLI::success( ucfirst( $which ) . ' maintenance done.' );
				$failed = Cron::failures();
				return $failed ? array( 'failing steps: ' . implode( ', ', array_keys( $failed ) ) ) : array();
			}
		);
	}

	/**
	 * Switch the maintenance mode (sales are still recorded; approvals, payouts, mails and
	 * partner area changes wait).
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on|off
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function maintenance( $args, $assoc ) {
		$state = isset( $args[0] ) ? $args[0] : '';
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			\WP_CLI::error( 'Use: wp wpac maintenance on|off' );
		}
		if ( ! \WPAffiliateCaptain\Core\Settings::update( array( 'maintenance_mode' => 'on' === $state ? 1 : 0 ) ) ) {
			\WP_CLI::error( 'The setting could not be saved (database error).' );
		}
		\WPAffiliateCaptain\Core\Audit::log( 'settings.maintenance', 'settings', 0, array( 'state' => $state ) );
		\WP_CLI::success( 'Maintenance mode ' . $state . '.' );
	}

	/**
	 * List the health checks (same as Site Health).
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : On every site of the network where the plugin is active.
	 *
	 * [--format=<format>]
	 * : table, json, csv …
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function health( $args, $assoc ) {
		self::each_site(
			$assoc,
			static function () use ( $assoc ) {
				$rows = array();
				foreach ( Monitor::checks() as $key => $c ) {
					$rows[] = array( 'check' => $key, 'status' => $c[0], 'label' => $c[1], 'details' => $c[2] );
				}
				\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'check', 'status', 'label', 'details' ) );
				// Critical problems give a non-zero exit code for scripts and monitoring.
				return array_keys( Monitor::problems( true ) );
			}
		);
	}

	/**
	 * Check that every partner account matches its bookings (hash chain and sums) – all accounts,
	 * in pages of 500.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Check at most this many accounts (default: all).
	 *
	 * [--network]
	 * : On every site of the network where the plugin is active.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function verify( $args, $assoc ) {
		self::each_site(
			$assoc,
			static function () use ( $assoc ) {
				$checked  = 0;
				$problems = Balance::verify_all( isset( $assoc['limit'] ) ? max( 0, (int) $assoc['limit'] ) : 0, $checked );
				if ( $problems ) {
					foreach ( $problems as $account => $p ) {
						\WP_CLI::warning( $account . ': ' . ( is_array( $p ) ? wp_json_encode( $p ) : (string) $p ) );
					}
					return array( count( $problems ) . ' of ' . $checked . ' accounts differ.' );
				}
				\WP_CLI::success( 'All ' . $checked . ' partner accounts are consistent.' );
				return array();
			}
		);
	}
}
