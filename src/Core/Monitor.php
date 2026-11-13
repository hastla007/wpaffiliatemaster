<?php
/**
 * Health of the partner program in one place: used by Site Health, the admin notices, WP-CLI,
 * the monitoring endpoint (REST) and the daily alarm mail.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Monitor {

	/**
	 * All checks: key => [ status good|recommended|critical, label, description ].
	 *
	 * @return array
	 */
	public static function checks() {
		global $wpdb;
		$out = array();

		$problems     = get_option( 'wpac_schema_problems' );
		$out['schema'] = Installer::schema_current() && ! $problems
			? array( 'good', __( 'Database schema is current', 'wp-affiliate-captain' ), '' )
			: array( 'critical', __( 'Database update incomplete', 'wp-affiliate-captain' ), implode( '; ', (array) $problems ) );

		$warnings = get_option( 'wpac_schema_warnings' );
		if ( $warnings ) {
			$out['engine'] = array( 'recommended', __( 'Some tables do not use InnoDB', 'wp-affiliate-captain' ), implode( '; ', (array) $warnings ) );
		}

		$next          = wp_next_scheduled( Cron::HOURLY );
		$out['cron']   = $next && $next > time() - 2 * HOUR_IN_SECONDS
			? array( 'good', __( 'Scheduled tasks are running', 'wp-affiliate-captain' ), '' )
			: array( 'critical', __( 'Scheduled tasks have not run for over two hours', 'wp-affiliate-captain' ), __( 'Commissions are not released and failed sales are not retried. Set up a system cron ("wp cron event run --due-now").', 'wp-affiliate-captain' ) );

		$jobs   = Db::table( 'jobs' );
		$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['jobs'] = $failed
			/* translators: %d: number */
			? array( 'critical', sprintf( _n( '%d sale or refund could not be booked', '%d sales or refunds could not be booked', $failed, 'wp-affiliate-captain' ), $failed ), __( 'Partner program → Integrations → Retry queue.', 'wp-affiliate-captain' ) )
			: array( 'good', __( 'No failed sales or refunds', 'wp-affiliate-captain' ), '' );

		$balance = get_option( 'wpac_balance_problems' );
		$out['balances'] = is_array( $balance ) && ! empty( $balance['accounts'] )
			/* translators: %d: number */
			? array( 'critical', sprintf( __( '%d partner accounts differ from their bookings', 'wp-affiliate-captain' ), count( $balance['accounts'] ) ), implode( ', ', array_slice( $balance['accounts'], 0, 20 ) ) )
			: array( 'good', __( 'Partner accounts match their bookings', 'wp-affiliate-captain' ), '' );

		$stmt = \WPAffiliateCaptain\Payouts\Statement::waiting();
		if ( $stmt ) {
			/* translators: %d: number */
			$out['statements'] = array( 'critical', sprintf( __( '%d credit notes could not be written', 'wp-affiliate-captain' ), count( $stmt ) ), __( 'Check that the statement folder is writable.', 'wp-affiliate-captain' ) );
		}
		$public = \WPAffiliateCaptain\Payouts\Statement::publicly_accessible();
		if ( true === $public ) {
			$out['statement_dir'] = array( 'critical', __( 'Credit notes are publicly reachable', 'wp-affiliate-captain' ), __( 'The statement folder can be opened from the web. Define WPAC_STATEMENT_DIR outside the web root or block the folder in the server configuration.', 'wp-affiliate-captain' ) );
		}

		$out['key'] = Crypto::has_dedicated_key()
			? array( 'good', __( 'Own encryption key for bank details', 'wp-affiliate-captain' ), '' )
			: array( 'recommended', __( 'No own encryption key for bank details', 'wp-affiliate-captain' ), __( 'Add WPAC_ENCRYPTION_KEY to wp-config.php so bank details do not depend on the WordPress salts.', 'wp-affiliate-captain' ) );

		if ( Settings::get( 'maintenance_mode' ) ) {
			$out['maintenance'] = array( 'recommended', __( 'Partner program is in maintenance mode', 'wp-affiliate-captain' ), __( 'Sales are still recorded, but approvals, payouts, mails and changes in the partner area wait.', 'wp-affiliate-captain' ) );
		}
		return $out;
	}

	/**
	 * Only the problems (critical first).
	 *
	 * @param bool $critical_only Only critical ones.
	 * @return array
	 */
	public static function problems( $critical_only = false ) {
		$out = array();
		foreach ( self::checks() as $key => $c ) {
			if ( 'critical' === $c[0] || ( ! $critical_only && 'recommended' === $c[0] ) ) {
				$out[ $key ] = $c;
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] ); // "critical" before "recommended".
			}
		);
		return $out;
	}

	/**
	 * Site Health: one test per check.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_status_tests( $tests ) {
		$tests['direct']['wpac_health'] = array(
			'label' => __( 'Partner program (WP Affiliate Captain)', 'wp-affiliate-captain' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	/**
	 * Site Health result: the most severe check decides.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$problems = self::problems();
		$status   = 'good';
		$list     = '';
		foreach ( $problems as $c ) {
			$status = 'critical' === $c[0] ? 'critical' : ( 'good' === $status ? 'recommended' : $status );
			$list  .= '<li><strong>' . esc_html( $c[1] ) . '</strong>' . ( $c[2] ? ' – ' . esc_html( $c[2] ) : '' ) . '</li>';
		}
		return array(
			'label'       => $problems ? __( 'The partner program needs attention', 'wp-affiliate-captain' ) : __( 'The partner program is working', 'wp-affiliate-captain' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Partner program', 'wp-affiliate-captain' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ),
			),
			'description' => $list ? '<ul>' . $list . '</ul>' : '<p>' . esc_html__( 'Schema, scheduled tasks, retry queue, account balances and credit notes are fine.', 'wp-affiliate-captain' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-integrations' ) ) . '">' . esc_html__( 'Open the integrations page', 'wp-affiliate-captain' ) . '</a></p>',
			'test'        => 'wpac_health',
		);
	}

	/**
	 * Site Health → Info: versions and key settings (no personal data).
	 *
	 * @param array $info Debug info.
	 * @return array
	 */
	public static function debug_information( $info ) {
		$fields = array(
			'version'        => array( 'label' => 'Plugin', 'value' => WPAC_VERSION ),
			'db_version'     => array( 'label' => 'Schema', 'value' => get_option( Installer::DB_OPTION ) . ' / ' . WPAC_DB_VERSION ),
			'contract'       => array( 'label' => 'Contract', 'value' => WPAC_CONTRACT_VERSION ),
			'scheduler'      => array( 'label' => 'Scheduler', 'value' => Scheduler::uses_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ),
			'object_cache'   => array( 'label' => 'Object cache', 'value' => wp_using_ext_object_cache() ? 'external' : 'none' ),
			'cookie_mode'    => array( 'label' => 'Cookie mode', 'value' => (string) Settings::get( 'cookie_mode' ) ),
			'self_billing'   => array( 'label' => 'Self-billing', 'value' => Settings::get( 'self_billing' ) ? 'yes' : 'no' ),
			'retention'      => array( 'label' => 'Retention (years)', 'value' => (string) \WPAffiliateCaptain\Privacy\Retention::years() . ( \WPAffiliateCaptain\Privacy\Retention::on_hold() ? ' (hold)' : '' ) ),
			'maintenance'    => array( 'label' => 'Maintenance mode', 'value' => Settings::get( 'maintenance_mode' ) ? 'on' : 'off' ),
		);
		foreach ( self::checks() as $key => $c ) {
			$fields[ 'check_' . $key ] = array(
				'label' => $c[1],
				'value' => $c[0],
			);
		}
		$info['wp-affiliate-captain'] = array(
			'label'  => 'WP Affiliate Captain',
			'fields' => $fields,
		);
		return $info;
	}

	/**
	 * Daily alarm: one mail with all critical problems (at most once a day, only when something
	 * is wrong). Recipient: the program's admin e-mail.
	 */
	public static function daily_alert() {
		$problems = self::problems( true );
		if ( ! $problems || get_transient( 'wpac_monitor_alerted' ) ) {
			return;
		}
		set_transient( 'wpac_monitor_alerted', 1, DAY_IN_SECONDS - 300 );
		$lines = array();
		foreach ( $problems as $c ) {
			$lines[] = '- ' . $c[1] . ( $c[2] ? ': ' . $c[2] : '' );
		}
		$admin = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $admin ) ? $admin : (string) get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner program needs attention', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: problems, 2: URL */
			sprintf( __( "The daily check of the partner program found problems:\n\n%1\$s\n\nDetails: Tools → Site Health, or %2\$s", 'wp-affiliate-captain' ), implode( "\n", $lines ), admin_url( 'admin.php?page=wpac-integrations' ) )
		);
		Log::warning( 'Daily check found problems', array( 'checks' => array_keys( $problems ) ) );
	}
}
