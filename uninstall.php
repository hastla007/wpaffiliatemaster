<?php
/**
 * Uninstall: only removes data when "Delete all data" was enabled in the settings.
 *
 * @package WPAffiliateCaptain
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the data of the current site (only if "Delete all data" is enabled for it).
 */
function wpac_uninstall_site() {
	global $wpdb;
	$wpac_settings = get_option( 'wpac_settings', array() );
	if ( empty( $wpac_settings['delete_on_uninstall'] ) ) {
		return;
	}

	// Credit notes and the bookings behind them must be kept for the statutory period
	// (DE §147 AO/§14b UStG, AT §132 BAO, CH Art. 958f OR): as long as a payout is younger
	// than that, the financial tables, the statement files and the audit log stay.
	$wpac_years   = isset( $wpac_settings['statement_retention_years'] ) ? (int) $wpac_settings['statement_retention_years'] : 10;
	$wpac_payouts = $wpdb->prefix . 'wpac_payouts';
	$wpac_keep    = false;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_payouts ) ) === $wpac_payouts ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Legal minimum by operator country (DE 8, AT 7, CH 10, otherwise 10 years), as in Privacy\Retention.
		$wpac_country = isset( $wpac_settings['operator_country'] ) ? strtoupper( (string) $wpac_settings['operator_country'] ) : '';
		$wpac_minimum = array( 'DE' => 8, 'AT' => 7, 'CH' => 10 );
		$wpac_minimum = isset( $wpac_minimum[ $wpac_country ] ) ? $wpac_minimum[ $wpac_country ] : 10;
		$wpac_cutoff  = 0 === $wpac_years ? '9999-12-31 23:59:59' : gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( max( $wpac_minimum, $wpac_years ) + 1 ) . ' years' ) );
		$wpac_keep   = ! empty( $wpac_settings['retention_hold'] ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpac_payouts} WHERE created_at > %s", 0 === $wpac_years ? '0000-00-00 00:00:00' : $wpac_cutoff ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
	$wpac_financial = array( 'commissions', 'commission_events', 'payouts', 'payout_items', 'sequences', 'balances', 'balance_entries', 'audit', 'agreements' );

	$wpac_tables = array( 'affiliates', 'partner_groups', 'partner_codes', 'agreements', 'payout_profiles', 'visits', 'attributions', 'rules', 'commissions', 'commission_events', 'payouts', 'payout_items', 'jobs', 'audit', 'tiers', 'customer_links', 'creatives', 'payout_requests', 'sequences', 'balances', 'balance_entries', 'rate_limits' );
	foreach ( $wpac_tables as $wpac_table ) {
		if ( $wpac_keep && in_array( $wpac_table, $wpac_financial, true ) ) {
			continue;
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpac_' . $wpac_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
	if ( $wpac_keep ) {
		update_option( 'wpac_retained_after_uninstall', gmdate( 'Y-m-d H:i:s' ), false );
	} else {
		delete_option( 'wpac_retained_after_uninstall' );
	}
	// Statements / credit notes (only when "delete all data" was explicitly enabled
	// and no statement is within the retention period anymore).
	$wpac_dir = defined( 'WPAC_STATEMENT_DIR' ) && WPAC_STATEMENT_DIR ? untrailingslashit( WPAC_STATEMENT_DIR ) : trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'wpac-statements';
	if ( ! $wpac_keep && is_dir( $wpac_dir ) ) {
		foreach ( (array) glob( $wpac_dir . '/{,.}*', GLOB_BRACE ) as $wpac_file ) {
			if ( is_file( $wpac_file ) ) {
				wp_delete_file( $wpac_file );
			}
		}
		@rmdir( $wpac_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	// Kept records keep their settings (operator data, numbering prefixes) and schema version.
	$wpac_options = array( 'wpac_rewrite_flushed', 'wpac_report_month_sent', 'wpac_report_progress', 'wpac_customer_key', 'wpac_upgrade_lock', 'wpac_erasure_pending', 'wpac_cookie_signed_since', 'wpac_report_failed', 'wpac_schema_problems', 'wpac_schema_problems_since', 'wpac_schema_warnings', 'wpac_balance_problems', 'wpac_statement_retry' );
	if ( ! $wpac_keep ) {
		// The restriction list belongs to the kept booking records; without them it is not needed.
		$wpac_options = array_merge( $wpac_options, array( 'wpac_settings', 'wpac_db_version', 'wpac_restricted', 'wpac_objections', 'wpac_clawback' ) );
		// Stored terms texts belong to the acceptance records (agreements), which are dropped now.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_terms\\_text\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	foreach ( $wpac_options as $wpac_option ) {
		delete_option( $wpac_option );
	}
	// Markers of sales being booked (normally removed right away).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_inflight\\_%' OR option_name LIKE 'wpac\\_user\\_link\\_%' OR option_name LIKE 'wpac\\_prev\\_email\\_%' OR option_name LIKE 'wpac\\_public\\_change\\_%' OR option_name LIKE 'wpac\\_vies\\_%' OR option_name LIKE 'wpac\\_dunning\\_%' OR option_name LIKE 'wpac\\_reassign\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Monthly report claims (one per month).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_report\\_claim\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Rate-limit and other transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpac\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpac\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Content meta (recommended partner targets).
	delete_post_meta_by_key( '_wpac_promote' );
	delete_post_meta_by_key( '_wpac_promote_note' );
	// Note: order meta (_wpac_*) is kept with the orders; WordPress users created for partners are kept.
	wp_clear_scheduled_hook( 'wpac_hourly' );
	wp_clear_scheduled_hook( 'wpac_daily' );
	wp_clear_scheduled_hook( 'wpac_report_retry' );
	wp_clear_scheduled_hook( 'wpac_report_batch' );
	wp_clear_scheduled_hook( 'wpac_jobs_drain' );
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'wpac_vies_check' ); // One event per partner (with arguments).
	}

	foreach ( array( 'wpac_manage_program', 'wpac_manage_affiliates', 'wpac_manage_payouts', 'wpac_view_tax_data' ) as $wpac_cap ) {
		foreach ( wp_roles()->role_objects as $wpac_role ) {
			$wpac_role->remove_cap( $wpac_cap );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpac_site_id ) {
		switch_to_blog( (int) $wpac_site_id );
		wpac_uninstall_site();
		restore_current_blog();
	}
} else {
	wpac_uninstall_site();
}
