<?php
/**
 * WP-CLI commands: `wp wpac <command>`.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Privacy\Retention;
use WPAffiliateCaptain\Tracking\CustomerLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Operate Affiliate Captain from the command line.
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
		$last     = get_option( Cron::LAST_RUN_OPTION, array() );
		$rows     = array(
			array( 'check' => 'plugin version', 'value' => WPAC_VERSION ),
			array( 'check' => 'schema version', 'value' => get_option( Installer::DB_OPTION ) . ' / ' . WPAC_DB_VERSION ),
			array( 'check' => 'schema problems', 'value' => $problems ? implode( '; ', $problems ) : 'none' ),
			array( 'check' => 'jobs queued / running / failed', 'value' => $n( 'queued' ) . ' / ' . $n( 'running' ) . ' / ' . $n( 'failed' ) ),
			array( 'check' => 'next hourly run', 'value' => wp_next_scheduled( Cron::HOURLY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::HOURLY ) ) . ' UTC' : 'NOT SCHEDULED' ),
			array( 'check' => 'next daily run', 'value' => wp_next_scheduled( Cron::DAILY ) ? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( Cron::DAILY ) ) . ' UTC' : 'NOT SCHEDULED' ),
			array( 'check' => 'last hourly / daily run', 'value' => ( ! empty( $last['hourly'] ) ? gmdate( 'Y-m-d H:i', (int) $last['hourly'] ) : 'never' ) . ' / ' . ( ! empty( $last['daily'] ) ? gmdate( 'Y-m-d H:i', (int) $last['daily'] ) : 'never' ) . ' UTC' ),
			array( 'check' => 'retention (years, hold)', 'value' => Retention::years() . ( Retention::on_hold() ? ', on hold' : '' ) ),
		);
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'check', 'value' ) );
	}

	/**
	 * Show or remove the lifetime partner binding of a customer.
	 *
	 * ## OPTIONS
	 *
	 * <customer>
	 * : Customer e-mail address or WordPress user ID.
	 *
	 * [--remove]
	 * : Remove the binding (audited).
	 *
	 * [--reason=<reason>]
	 * : Reason stored in the audit log (with --remove).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac customer-link kunde@example.com
	 *     wp wpac customer-link 42 --remove --reason="Binding via foreign guest order"
	 *
	 * @subcommand customer-link
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function customer_link( $args, $assoc ) {
		$who     = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		$user_id = ctype_digit( $who ) ? (int) $who : 0;
		$email   = $user_id ? '' : $who;
		if ( $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
		} elseif ( is_email( $email ) ) {
			$user    = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;
		} else {
			\WP_CLI::error( 'Please give an e-mail address or a user ID.' );
		}
		$rows = CustomerLinks::for_customer( $user_id, $email );
		if ( ! $rows ) {
			\WP_CLI::success( 'No lifetime binding for this customer.' );
			return;
		}
		$items = array();
		foreach ( $rows as $r ) {
			$aff     = AffiliateRepository::find( (int) $r->affiliate_id );
			$items[] = array(
				'partner' => ( $aff ? $aff->name : '' ) . ' (#' . (int) $r->affiliate_id . ')',
				'order'   => $r->source . ' ' . $r->source_order_id,
				'state'   => (int) $r->pending ? 'pending review' : 'active',
				'since'   => $r->created_at,
				'expires' => $r->expires_at ? $r->expires_at : 'never',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'partner', 'order', 'state', 'since', 'expires' ) );
		if ( ! empty( $assoc['remove'] ) ) {
			$n = CustomerLinks::unlink_customer( $user_id, $email, isset( $assoc['reason'] ) ? (string) $assoc['reason'] : 'wp-cli' );
			\WP_CLI::success( sprintf( 'Removed %d link(s).', $n ) );
		}
	}

	/**
	 * Run the database migration now (instead of waiting for an admin or cron request).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function upgrade( $args, $assoc ) {
		// Under the same lock as the automatic migration (never two migrations in parallel).
		if ( ! Installer::upgrade() ) {
			\WP_CLI::error( 'Another process is running the migration right now. Please try again in a few minutes.' );
		}
		$problems = get_option( 'wpac_schema_problems' ); // Also an aborted migration.
		if ( ! is_array( $problems ) || ! $problems ) {
			$problems = Installer::verify_schema();
		}
		if ( $problems ) {
			\WP_CLI::error( 'Migration incomplete: ' . implode( '; ', $problems ) );
		}
		\WP_CLI::success( 'Schema version ' . get_option( Installer::DB_OPTION ) );
	}

	/**
	 * Work through the retry queue, or list it.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : "run" (default) or "list".
	 *
	 * [--retry-failed]
	 * : Put all failed jobs back into the queue first (run).
	 *
	 * [--limit=<n>]
	 * : Jobs to run or list (default 100).
	 *
	 * [--status=<status>]
	 * : Only jobs with this status: queued, running, failed, done (list).
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml (list).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac jobs list --status=failed
	 *     wp wpac jobs --retry-failed
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function jobs( $args, $assoc ) {
		if ( isset( $args[0] ) && 'list' === $args[0] ) {
			$status = isset( $assoc['status'] ) ? sanitize_key( $assoc['status'] ) : '';
			$rows   = array();
			foreach ( Jobs::query( $status, isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100 ) as $job ) {
				$job    = (object) $job;
				$rows[] = array(
					'id'           => (int) $job->id,
					'type'         => (string) $job->type,
					'key'          => (string) $job->idempotency_key,
					'status'       => (string) $job->status,
					'attempts'     => (int) $job->attempts,
					'next_attempt' => (string) $job->next_attempt_at,
					'error'        => trim( (string) $job->last_error_code . ' ' . (string) $job->last_error ),
				);
			}
			\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'type', 'key', 'status', 'attempts', 'next_attempt', 'error' ) );
			return;
		}
		if ( isset( $args[0] ) && 'run' !== $args[0] ) {
			\WP_CLI::error( 'Use: wp wpac jobs [run|list]' );
		}
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
		\WPAffiliateCaptain\Core\Settings::update( array( 'maintenance_mode' => 'on' === $state ? 1 : 0 ) );
		\WPAffiliateCaptain\Core\Audit::log( 'settings.maintenance', 'settings', 0, array( 'state' => $state ) );
		\WP_CLI::success( 'Maintenance mode ' . $state . '.' );
	}

	/**
	 * Anonymized support report: versions, environment, the relevant settings, queue and
	 * scheduler state and the health checks – no names, e-mail addresses, bank details or keys.
	 * Attach it to a support request.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : json (default) or table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac support > affiliate-captain-support.json
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function support( $args, $assoc ) {
		$data = \WPAffiliateCaptain\Core\Monitor::support_data();
		if ( isset( $assoc['format'] ) && 'table' === $assoc['format'] ) {
			$rows = array();
			foreach ( $data as $section => $values ) {
				foreach ( $values as $key => $value ) {
					$rows[] = array(
						'section' => $section,
						'key'     => $key,
						'value'   => (string) $value,
					);
				}
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'section', 'key', 'value' ) );
			return;
		}
		\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List the health checks (same as Site Health).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function health( $args, $assoc ) {
		$rows = array();
		foreach ( \WPAffiliateCaptain\Core\Monitor::checks() as $key => $c ) {
			$rows[] = array( 'check' => $key, 'status' => $c[0], 'label' => $c[1], 'details' => $c[2] );
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'check', 'status', 'label', 'details' ) );
		if ( \WPAffiliateCaptain\Core\Monitor::problems( true ) ) {
			\WP_CLI::halt( 1 ); // Non-zero exit code for scripts and monitoring.
		}
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
