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
			? array( 'good', __( 'Database schema is current', 'affiliate-captain' ), '' )
			: array( 'critical', __( 'Database update incomplete', 'affiliate-captain' ), implode( '; ', (array) $problems ) );
		$newer = Installer::downgraded_from();
		if ( $newer ) {
			$out['schema'] = array(
				'critical',
				__( 'The database belongs to a newer version of the plugin', 'affiliate-captain' ),
				/* translators: 1: stored schema version, 2: schema version of the installed plugin */
				sprintf( __( 'The database was updated to schema version %1$d, but the installed plugin only knows version %2$d (the plugin was downgraded). Older code may write incomplete records. Please install the newer version again or restore the database backup that belongs to this version.', 'affiliate-captain' ), $newer, WPAC_DB_VERSION ),
			);
		}

		$warnings = get_option( 'wpac_schema_warnings' );
		if ( $warnings ) {
			$out['engine'] = array( 'recommended', __( 'Some tables do not use InnoDB', 'affiliate-captain' ), implode( '; ', (array) $warnings ) );
		}

		$next          = wp_next_scheduled( Cron::HOURLY );
		$out['cron']   = $next && $next > time() - 2 * HOUR_IN_SECONDS
			? array( 'good', __( 'Scheduled tasks are running', 'affiliate-captain' ), '' )
			: array( 'critical', __( 'Scheduled tasks have not run for over two hours', 'affiliate-captain' ), __( 'Commissions are not released and failed sales are not retried. Set up a system cron ("wp cron event run --due-now").', 'affiliate-captain' ) );

		$jobs   = Db::table( 'jobs' );
		$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['jobs'] = $failed
			/* translators: %d: number */
			? array( 'critical', sprintf( _n( '%d sale or refund could not be booked', '%d sales or refunds could not be booked', $failed, 'affiliate-captain' ), $failed ), __( 'Affiliate Captain → Integrations → Retry queue.', 'affiliate-captain' ) )
			: array( 'good', __( 'No failed sales or refunds', 'affiliate-captain' ), '' );

		$balance = get_option( 'wpac_balance_problems' );
		$out['balances'] = is_array( $balance ) && ! empty( $balance['accounts'] )
			/* translators: %d: number */
			? array( 'critical', sprintf( __( '%d partner accounts differ from their bookings', 'affiliate-captain' ), count( $balance['accounts'] ) ), implode( ', ', array_slice( $balance['accounts'], 0, 20 ) ) )
			: array( 'good', __( 'Partner accounts match their bookings', 'affiliate-captain' ), '' );

		$stmt = \WPAffiliateCaptain\Payouts\Statement::waiting();
		if ( $stmt ) {
			/* translators: %d: number */
			$out['statements'] = array( 'critical', sprintf( __( '%d credit notes could not be written', 'affiliate-captain' ), count( $stmt ) ), __( 'Check that the statement folder is writable.', 'affiliate-captain' ) );
		}
		$public = \WPAffiliateCaptain\Payouts\Statement::publicly_accessible();
		if ( true === $public ) {
			$out['statement_dir'] = array( 'critical', __( 'Credit notes are publicly reachable', 'affiliate-captain' ), __( 'The statement folder can be opened from the web. Define WPAC_STATEMENT_DIR outside the web root or block the folder in the server configuration.', 'affiliate-captain' ) );
		}

		$out['key'] = Crypto::has_dedicated_key()
			? array( 'good', __( 'Own encryption key for bank details', 'affiliate-captain' ), '' )
			: array( 'recommended', __( 'No own encryption key for bank details', 'affiliate-captain' ), __( 'Add WPAC_ENCRYPTION_KEY to wp-config.php so bank details do not depend on the WordPress salts.', 'affiliate-captain' ) );

		if ( Settings::get( 'maintenance_mode' ) ) {
			$out['maintenance'] = array( 'recommended', __( 'Partner program is in maintenance mode', 'affiliate-captain' ), __( 'Sales are still recorded, but approvals, payouts, mails and changes in the partner area wait.', 'affiliate-captain' ) );
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
			'label' => __( 'Partner program (Affiliate Captain)', 'affiliate-captain' ),
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
			'label'       => $problems ? __( 'The partner program needs attention', 'affiliate-captain' ) : __( 'The partner program is working', 'affiliate-captain' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Partner program', 'affiliate-captain' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ),
			),
			'description' => $list ? '<ul>' . $list . '</ul>' : '<p>' . esc_html__( 'Schema, scheduled tasks, retry queue, account balances and credit notes are fine.', 'affiliate-captain' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpac-integrations' ) ) . '">' . esc_html__( 'Open the integrations page', 'affiliate-captain' ) . '</a></p>',
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
		$yes_no  = static function ( $on ) {
			return $on ? __( 'Yes', 'affiliate-captain' ) : __( 'No', 'affiliate-captain' );
		};
		$cookies = array(
			'off'     => __( 'Never set', 'affiliate-captain' ),
			'consent' => __( 'Only with marketing consent', 'affiliate-captain' ),
			'always'  => __( 'Always, without consent', 'affiliate-captain' ),
		);
		$states  = array(
			'good'        => __( 'OK', 'affiliate-captain' ),
			'recommended' => __( 'Recommendation', 'affiliate-captain' ),
			'critical'    => __( 'Problem', 'affiliate-captain' ),
		);
		$mode    = (string) Settings::get( 'cookie_mode' );
		$years   = \WPAffiliateCaptain\Privacy\Retention::years();
		$fields  = array(
			'version'      => array(
				'label' => __( 'Plugin version', 'affiliate-captain' ),
				'value' => WPAC_VERSION,
			),
			'db_version'   => array(
				'label' => __( 'Database schema (installed / expected)', 'affiliate-captain' ),
				'value' => get_option( Installer::DB_OPTION ) . ' / ' . WPAC_DB_VERSION,
				'debug' => get_option( Installer::DB_OPTION ) . ' / ' . WPAC_DB_VERSION,
			),
			'contract'     => array(
				'label' => __( 'Integration contract version', 'affiliate-captain' ),
				'value' => WPAC_CONTRACT_VERSION,
			),
			'scheduler'    => array(
				'label' => __( 'Background tasks run with', 'affiliate-captain' ),
				'value' => Scheduler::uses_action_scheduler() ? 'Action Scheduler (WooCommerce)' : 'WP-Cron',
			),
			'object_cache' => array(
				'label' => __( 'Persistent object cache', 'affiliate-captain' ),
				'value' => $yes_no( wp_using_ext_object_cache() ),
				'debug' => wp_using_ext_object_cache() ? 'external' : 'none',
			),
			'cookie_mode'  => array(
				'label' => __( 'Affiliate cookie', 'affiliate-captain' ),
				'value' => isset( $cookies[ $mode ] ) ? $cookies[ $mode ] : $mode,
				'debug' => $mode,
			),
			'self_billing' => array(
				'label' => __( 'Credit notes (self-billing)', 'affiliate-captain' ),
				'value' => $yes_no( Settings::get( 'self_billing' ) ),
				'debug' => Settings::get( 'self_billing' ) ? 'yes' : 'no',
			),
			'retention'    => array(
				'label' => __( 'Statement retention', 'affiliate-captain' ),
				/* translators: %d: years */
				'value' => ( $years > 0 ? sprintf( _n( '%d year', '%d years', $years, 'affiliate-captain' ), $years ) : __( 'forever', 'affiliate-captain' ) ) . ( \WPAffiliateCaptain\Privacy\Retention::on_hold() ? ' (' . __( 'deletion paused: legal hold', 'affiliate-captain' ) . ')' : '' ),
				'debug' => (string) $years . ( \WPAffiliateCaptain\Privacy\Retention::on_hold() ? ' (hold)' : '' ),
			),
			'maintenance'  => array(
				'label' => __( 'Maintenance mode', 'affiliate-captain' ),
				'value' => $yes_no( Settings::get( 'maintenance_mode' ) ),
				'debug' => Settings::get( 'maintenance_mode' ) ? 'on' : 'off',
			),
		);
		foreach ( self::checks() as $key => $c ) {
			$fields[ 'check_' . $key ] = array(
				'label' => $c[1],
				'value' => isset( $states[ $c[0] ] ) ? $states[ $c[0] ] : $c[0],
				'debug' => $c[0], // Copied reports stay comparable across languages.
			);
		}
		$info['affiliate-captain'] = array(
			'label'  => 'Affiliate Captain',
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
			sprintf( __( '[%s] Partner program needs attention', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: problems, 2: URL */
			sprintf( __( "The daily check of the partner program found problems:\n\n%1\$s\n\nDetails: Tools → Site Health, or %2\$s", 'affiliate-captain' ), implode( "\n", $lines ), admin_url( 'admin.php?page=wpac-integrations' ) )
		);
		Log::warning( 'Daily check found problems', array( 'checks' => array_keys( $problems ) ) );
	}
}
