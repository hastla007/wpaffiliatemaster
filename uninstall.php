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
	// Scheduled tasks and locks are no data: they go in any case (deactivation normally removed
	// the events already; a network deactivation may not have reached every site).
	wpac_uninstall_schedules();
	// Locks (normally removed right away; "unix-time:token" values).
	delete_option( 'wpac_report_retry_lock' );
	delete_option( 'wpac_restricted_lock' );
	delete_option( 'wpac_objections_lock' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_stmt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Per-site hidden notices of the admins (user options carry the site's table prefix).
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'wpac_hidden_notices', '', true );

	$wpac_settings = get_option( 'wpac_settings', array() );
	if ( empty( $wpac_settings['delete_on_uninstall'] ) ) {
		return;
	}

	// Credit notes and the bookings behind them must be kept for the statutory period
	// (DE §147 AO/§14b UStG, AT §132 BAO, CH Art. 958f OR): as long as a payout or its
	// cancellation is younger than that, the financial tables, the statement files and the audit
	// log stay. A cancellation (storno_at) starts its own period.
	$wpac_years   = isset( $wpac_settings['statement_retention_years'] ) ? (int) $wpac_settings['statement_retention_years'] : 10;
	$wpac_payouts = $wpdb->prefix . 'wpac_payouts';
	$wpac_keep    = false;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_payouts ) ) === $wpac_payouts ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Legal minimum by operator country (DE 8, AT 7, CH 10, otherwise 10 years), as in Privacy\Retention.
		$wpac_country = isset( $wpac_settings['operator_country'] ) ? strtoupper( (string) $wpac_settings['operator_country'] ) : '';
		$wpac_minimum = array( 'DE' => 8, 'AT' => 7, 'CH' => 10 );
		$wpac_minimum = isset( $wpac_minimum[ $wpac_country ] ) ? $wpac_minimum[ $wpac_country ] : 10;
		$wpac_cutoff  = 0 === $wpac_years ? '0000-00-00 00:00:00' : gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( max( $wpac_minimum, $wpac_years ) + 1 ) . ' years' ) );
		$wpac_keep    = ! empty( $wpac_settings['retention_hold'] ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpac_payouts} WHERE created_at > %s OR storno_at > %s", $wpac_cutoff, $wpac_cutoff ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
	// The partners table stays with the booking records (pseudonymised below): its IDs are what
	// the kept credit notes, balances and commissions refer to. Dropping it would let a new
	// installation give a former partner's ID – and with it their documents and balance – to
	// a new partner.
	$wpac_financial = array( 'affiliates', 'commissions', 'commission_events', 'payouts', 'payout_items', 'sequences', 'balances', 'balance_entries', 'audit', 'agreements' );

	// Statements / credit notes of THIS site (only when "delete all data" was explicitly enabled and
	// no statement is within the retention period anymore). Only the files the site's payouts point
	// to are deleted – never the whole folder: with WPAC_STATEMENT_DIR it may be shared with other
	// sites of a network or other installations. Read before the tables are dropped.
	$wpac_files = array();
	if ( ! $wpac_keep && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_payouts ) ) === $wpac_payouts ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT statement_file, storno_file FROM {$wpac_payouts}" ) as $wpac_row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( array( $wpac_row->statement_file, $wpac_row->storno_file ) as $wpac_file ) {
				if ( '' !== (string) $wpac_file ) {
					$wpac_files[] = basename( (string) $wpac_file );
				}
			}
		}
	}

	$wpac_tables = array( 'affiliates', 'partner_groups', 'partner_codes', 'agreements', 'payout_profiles', 'visits', 'attributions', 'rules', 'commissions', 'commission_events', 'payouts', 'payout_items', 'jobs', 'audit', 'tiers', 'customer_links', 'creatives', 'payout_requests', 'sequences', 'balances', 'balance_entries', 'rate_limits' );
	foreach ( $wpac_tables as $wpac_table ) {
		if ( $wpac_keep && in_array( $wpac_table, $wpac_financial, true ) ) {
			continue;
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpac_' . $wpac_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
	if ( $wpac_keep ) {
		// Kept partners: only what the booking records need (ID, status); contact data goes.
		$wpac_affiliates = $wpdb->prefix . 'wpac_affiliates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_affiliates ) ) === $wpac_affiliates ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$wpac_affiliates} SET user_id = 0, group_id = 0, status = IF(status IN ('active','paused'), 'terminated', status), slug = CONCAT('deleted-', id), name = 'Deleted partner', email = CONCAT('deleted-', id, '@invalid.invalid'), website = '', channel = '', application_note = NULL, application_data = NULL, landing_page_id = 0, landing_message = NULL, status_reason = 'uninstalled'" );
		}
		update_option( 'wpac_retained_after_uninstall', gmdate( 'Y-m-d H:i:s' ), false );
	} else {
		delete_option( 'wpac_retained_after_uninstall' );
	}
	if ( ! $wpac_keep ) {
		// Folders: on a network with WPAC_STATEMENT_DIR the site's own subfolder (see Statement::dir())
		// and the shared folder used by older versions (files not moved yet); otherwise the folder.
		// With WPAC_STATEMENT_DIR also the uploads folder used before the constant was defined.
		$wpac_uploads = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'wpac-statements';
		$wpac_custom  = defined( 'WPAC_STATEMENT_DIR' ) && WPAC_STATEMENT_DIR;
		$wpac_root    = $wpac_custom ? untrailingslashit( (string) constant( 'WPAC_STATEMENT_DIR' ) ) : $wpac_uploads;
		$wpac_dirs    = array( $wpac_root );
		if ( is_multisite() && $wpac_custom ) {
			array_unshift( $wpac_dirs, $wpac_root . '/site-' . (int) get_current_blog_id() );
		}
		if ( $wpac_custom && untrailingslashit( $wpac_uploads ) !== $wpac_root ) {
			$wpac_dirs[] = $wpac_uploads;
		}
		foreach ( $wpac_dirs as $wpac_dir ) {
			if ( ! is_dir( $wpac_dir ) ) {
				continue;
			}
			foreach ( array_unique( $wpac_files ) as $wpac_name ) {
				if ( '' !== $wpac_name && '.' !== $wpac_name[0] && is_file( $wpac_dir . '/' . $wpac_name ) ) {
					wp_delete_file( $wpac_dir . '/' . $wpac_name );
				}
			}
			// The shared root of a network keeps its protection files; the site's own folders not.
			wpac_uninstall_remove_empty_dir( $wpac_dir, $wpac_dir !== $wpac_root || ! ( is_multisite() && $wpac_custom ) );
		}
	}

	// Pages the setup assistant created (application, partner area, landing page) would stay
	// published but empty without the plugin: they go to the trash (restorable) when they still
	// hold the plugin's block.
	foreach ( array( 'registration_page_id', 'portal_page_id', 'landing_page_id' ) as $wpac_key ) {
		$wpac_page = empty( $wpac_settings[ $wpac_key ] ) ? null : get_post( (int) $wpac_settings[ $wpac_key ] );
		if ( $wpac_page && 'trash' !== $wpac_page->post_status && false !== strpos( (string) $wpac_page->post_content, '<!-- wp:wpac/' ) ) {
			wp_trash_post( (int) $wpac_page->ID );
		}
	}

	// Kept records keep their settings (operator data, numbering prefixes) and schema version.
	$wpac_options = array( 'wpac_rewrite_flushed', 'wpac_report_month_sent', 'wpac_report_progress', 'wpac_customer_key', 'wpac_upgrade_lock', 'wpac_erasure_pending', 'wpac_cookie_signed_since', 'wpac_report_failed', 'wpac_schema_problems', 'wpac_schema_problems_since', 'wpac_schema_warnings', 'wpac_balance_problems', 'wpac_statement_retry', 'wpac_storno_retry', 'wpac_daily_rest', 'wpac_tier_recalc_pending', 'wpac_terms_remind', 'wpac_reencrypt_cursor', 'wpac_cron_last_run', 'wpac_cron_failures', 'wpac_migration_status', 'wpac_site_fingerprint', 'wpac_clone_detected', 'wpac_legacy_takeover', 'wpac_takeover_seen', 'wpac_takeover_notice', 'wpac_takeover_deletion_off', 'wpac_retained_notice' );
	if ( ! $wpac_keep ) {
		// The restriction list belongs to the kept booking records; without them it is not needed.
		$wpac_options = array_merge( $wpac_options, array( 'wpac_settings', 'wpac_db_version', 'wpac_restricted', 'wpac_objections', 'wpac_clawback', 'wpac_tier_month_closed', 'wpac_tier_month_first', 'wpac_vat_rate_history', 'wpac_monitor_key' ) );
		// Stored terms texts belong to the acceptance records (agreements), which are dropped now.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_terms\\_text\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	foreach ( $wpac_options as $wpac_option ) {
		delete_option( $wpac_option );
	}
	// Markers of sales being booked (normally removed right away), pending applications/e-mail changes.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_inflight\\_%' OR option_name LIKE 'wpac\\_user\\_link\\_%' OR option_name LIKE 'wpac\\_prev\\_email\\_%' OR option_name LIKE 'wpac\\_public\\_change\\_%' OR option_name LIKE 'wpac\\_vies\\_%' OR option_name LIKE 'wpac\\_dunning\\_%' OR option_name LIKE 'wpac\\_tax\\_history\\_%' OR option_name LIKE 'wpac\\_tax\\_pending\\_%' OR option_name LIKE 'wpac\\_reassign\\_%' OR option_name LIKE 'wpac\\_pending\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Monthly report claims (one per month).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_report\\_claim\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Rate-limit and other transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpac\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpac\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Content meta (recommended partner targets).
	delete_post_meta_by_key( '_wpac_promote' );
	delete_post_meta_by_key( '_wpac_promote_note' );
	// Note: order meta (_wpac_*) is kept with the orders; WordPress users created for partners are kept.

	if ( function_exists( 'remove_role' ) ) {
		remove_role( 'wpac_tax_advisor' ); // Read-only role of the plugin.
	}
	foreach ( array( 'wpac_manage_program', 'wpac_manage_affiliates', 'wpac_manage_payouts', 'wpac_view_tax_data' ) as $wpac_cap ) {
		foreach ( wp_roles()->role_objects as $wpac_role ) {
			$wpac_role->remove_cap( $wpac_cap );
		}
	}
}

/**
 * Remove the scheduled tasks of the current site: WP-Cron events (with any arguments) and the
 * plugin's group in WooCommerce's Action Scheduler (when it is loaded).
 */
function wpac_uninstall_schedules() {
	foreach ( array( 'wpac_hourly', 'wpac_daily', 'wpac_daily_rest', 'wpac_report_retry', 'wpac_report_batch', 'wpac_jobs_drain', 'wpac_vies_check', 'wpac_network_setup', 'wpac_reencrypt', 'wpac_terms_remind' ) as $wpac_hook ) {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( $wpac_hook ); // All events of the hook, also those with arguments.
		} else {
			wp_clear_scheduled_hook( $wpac_hook );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'wp-affiliate-captain' );
	}
}

/**
 * Remove a statement folder when nothing but the plugin's protection files is left in it.
 *
 * @param string $dir         Folder.
 * @param bool   $own_folder  Whether the folder belongs to this site alone (its protection files may go).
 */
function wpac_uninstall_remove_empty_dir( $dir, $own_folder ) {
	$entries = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
	if ( ! $own_folder || array_diff( $entries, array( '.htaccess', 'index.php' ) ) ) {
		return; // Shared, or other files are still there (other sites or installations): keep it.
	}
	foreach ( $entries as $entry ) {
		wp_delete_file( $dir . '/' . $entry );
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( is_multisite() ) {
	// Large networks: no time limit where the server allows it (the plugin code is gone after this
	// request, nothing could continue later). Sites without the plugin's data are skipped quickly.
	ignore_user_abort( true );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$wpac_limit = (int) ini_get( 'max_execution_time' );
	$wpac_start = microtime( true );
	$wpac_left  = array();
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpac_site_id ) {
		if ( $wpac_limit > 0 && microtime( true ) - $wpac_start > $wpac_limit * 0.8 ) {
			$wpac_left[] = (int) $wpac_site_id; // Time limit could not be lifted: record the rest.
			continue;
		}
		switch_to_blog( (int) $wpac_site_id );
		wpac_uninstall_site();
		restore_current_blog();
	}
	// Network-wide state: sites still waiting for the set-up after a network activation, daily sweep marker.
	delete_site_option( 'wpac_network_pending' );
	delete_site_transient( 'wpac_orphan_sweep' );
	delete_site_option( 'wpac_legacy_takeover' );
	// Hidden notices stored by older versions for all sites at once.
	delete_metadata( 'user', 0, 'wpac_hidden_notices', '', true );
	if ( $wpac_left ) {
		// Shown nowhere (the plugin is gone), but kept for the network admin and support:
		// "wp plugin uninstall affiliate-captain" on the command line has no time limit.
		update_site_option( 'wpac_uninstall_incomplete', $wpac_left );
		error_log( 'Affiliate Captain uninstall: time limit reached, sites not cleaned up: ' . implode( ',', $wpac_left ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	} else {
		delete_site_option( 'wpac_uninstall_incomplete' );
	}
} else {
	wpac_uninstall_site();
	delete_metadata( 'user', 0, 'wpac_hidden_notices', '', true );
}
