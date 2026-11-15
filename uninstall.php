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
	delete_option( 'wpac_import_lock' );
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
		$wpac_cutoff  = 0 === $wpac_years ? '1000-01-01 00:00:00' : gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( max( $wpac_minimum, $wpac_years ) + 1 ) . ' years' ) );
		$wpac_keep    = ! empty( $wpac_settings['retention_hold'] ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpac_payouts} WHERE created_at > %s OR storno_at > %s", $wpac_cutoff, $wpac_cutoff ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		// Correction credit notes start their own period too.
		$wpac_corr = $wpdb->prefix . 'wpac_payout_corrections';
		if ( ! $wpac_keep && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_corr ) ) === $wpac_corr ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpac_keep = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpac_corr} WHERE created_at > %s", $wpac_cutoff ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
	// The partners table stays with the booking records (pseudonymised below): its IDs are what
	// the kept credit notes, balances and commissions refer to. Dropping it would let a new
	// installation give a former partner's ID – and with it their documents and balance – to
	// a new partner.
	$wpac_financial = wpac_uninstall_financial_tables();

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
		$wpac_corr = $wpdb->prefix . 'wpac_payout_corrections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_corr ) ) === $wpac_corr ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_col( "SELECT file FROM {$wpac_corr} WHERE file <> ''" ) as $wpac_file ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$wpac_files[] = basename( (string) $wpac_file );
			}
		}
	}

	foreach ( wpac_uninstall_tables() as $wpac_table ) {
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
	$wpac_options = array( 'wpac_rewrite_flushed', 'wpac_report_month_sent', 'wpac_report_progress', 'wpac_customer_key', 'wpac_customer_key_constant', 'wpac_customer_key_changed', 'wpac_upgrade_lock', 'wpac_erasure_pending', 'wpac_cookie_signed_since', 'wpac_report_failed', 'wpac_schema_problems', 'wpac_schema_problems_since', 'wpac_schema_warnings', 'wpac_balance_problems', 'wpac_statement_retry', 'wpac_storno_retry', 'wpac_daily_rest', 'wpac_tier_recalc_pending', 'wpac_terms_remind', 'wpac_reencrypt_cursor', 'wpac_cron_last_run', 'wpac_cron_failures', 'wpac_migration_status', 'wpac_site_fingerprint', 'wpac_clone_detected', 'wpac_legacy_takeover', 'wpac_takeover_seen', 'wpac_takeover_notice', 'wpac_takeover_deletion_off', 'wpac_retained_notice', 'wpac_balance_check_pass', 'wpac_audit_purge_cursor', 'wpac_tier_cursor', 'wpac_tier_local_from', 'wpac_activation_redirect', 'wpac_review_alert', 'wpac_review_alert_sent', 'wpac_correction_retry', 'wpac_broadcast', 'wpac_touch_probe', 'wpac_visit_erasure_pending', 'wpac_rl_purge_backlog' );
	// Daily visit aggregate (R14-D9): backfill limit, cursor, time zone, completion, lock.
	$wpac_options = array_merge( $wpac_options, array( 'wpac_vdaily_upto', 'wpac_vdaily_cursor', 'wpac_vdaily_tz', 'wpac_vdaily_done', 'wpac_vdaily_lock' ) );
	// Keyed by partner ID: after a new installation partner 1 must not inherit a fraud flag
	// (shared IBAN) or a mail opt-out of a former partner 1 (R12-M17).
	$wpac_options = array_merge( $wpac_options, array( 'wpac_shared_iban', 'wpac_mail_optout' ) );
	// Report cache generation, template scan generation, cursor of the attribution clean-up (R13-N12).
	$wpac_options = array_merge( $wpac_options, array( 'wpac_reports_gen', 'wpac_tplscan_gen', 'wpac_attr_purge_cursor' ) );
	if ( ! $wpac_keep ) {
		// The restriction list belongs to the kept booking records; without them it is not needed.
		$wpac_options = array_merge( $wpac_options, array( 'wpac_settings', 'wpac_db_version', 'wpac_restricted', 'wpac_objections', 'wpac_clawback', 'wpac_tier_month_closed', 'wpac_tier_month_first', 'wpac_vat_rate_history', 'wpac_monitor_key', 'wpac_mail_templates' ) );
		// Stored terms texts belong to the acceptance records (agreements), which are dropped now.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_terms\\_text\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	foreach ( $wpac_options as $wpac_option ) {
		delete_option( $wpac_option );
	}
	// Markers of sales being booked (normally removed right away), pending applications/e-mail changes.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_inflight\\_%' OR option_name LIKE 'wpac\\_user\\_link\\_%' OR option_name LIKE 'wpac\\_prev\\_email\\_%' OR option_name LIKE 'wpac\\_public\\_change\\_%' OR option_name LIKE 'wpac\\_vies\\_%' OR option_name LIKE 'wpac\\_dunning\\_%' OR option_name LIKE 'wpac\\_tax\\_history\\_%' OR option_name LIKE 'wpac\\_tax\\_pending\\_%' OR option_name LIKE 'wpac\\_bank\\_pending\\_%' OR option_name LIKE 'wpac\\_access\\_changed\\_%' OR option_name LIKE 'wpac\\_reassign\\_%' OR option_name LIKE 'wpac\\_pending\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Monthly report claims (one per month).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_report\\_claim\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Rate-limit and other transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpac\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpac\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Content meta (recommended partner targets).
	delete_post_meta_by_key( '_wpac_promote' );
	delete_post_meta_by_key( '_wpac_promote_note' );
	delete_post_meta_by_key( '_wpac_form_groups' ); // Partner groups of the application form blocks (R14-A3).
	// IDs of the partner code rows (coupon meta): the partner codes table is always dropped, so a new
	// installation gives these IDs to other partners' codes (R14-A1). Coupons stay posts with HPOS.
	delete_post_meta_by_key( '_wpac_code_id' );
	if ( ! $wpac_keep ) {
		// The partners table is dropped too: a manual assignment by partner ID in an order would
		// credit the new partner with the same ID once the order is paid (R14-A1).
		foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $wpac_meta_table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_meta_table ) ) === $wpac_meta_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "DELETE FROM {$wpac_meta_table} WHERE meta_key IN ('_wpac_manual_affiliate', '_wpac_manual_own')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
	}
	// Note: WooCommerce orders belong to the shop: their notes ("attributed to partner …") and the
	// order meta (_wpac_*: partner code entered, referral snapshot) stay with the orders (R12-N18,
	// see HANDBOOK "Uninstalling"); WordPress users created for partners are kept. Only the manual
	// assignment by partner ID goes when the partners table is dropped (see above).

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
 * Tables of the plugin (without prefix; the same list as Core\Db::TABLES, kept in sync by a test).
 *
 * @return string[]
 */
function wpac_uninstall_tables() {
	return array( 'affiliates', 'partner_groups', 'partner_codes', 'agreements', 'payout_profiles', 'visits', 'visits_daily', 'attributions', 'rules', 'commissions', 'commission_events', 'payouts', 'payout_items', 'payout_corrections', 'jobs', 'audit', 'tiers', 'customer_links', 'creatives', 'payout_requests', 'sequences', 'balances', 'balance_entries', 'rate_limits' );
}

/**
 * Tables with booking records kept for the statutory retention period (as Core\Installer::FINANCIAL_TABLES).
 *
 * @return string[]
 */
function wpac_uninstall_financial_tables() {
	return array( 'affiliates', 'commissions', 'commission_events', 'payouts', 'payout_items', 'sequences', 'balances', 'balance_entries', 'audit', 'agreements', 'payout_corrections' );
}

/**
 * Network: tables and kept credit notes of DELETED sites (R14-A2). While the plugin is active the
 * daily sweep (Core\Installer::drop_orphaned_tables()) removes them once the retention period
 * ended; after the plugin is deleted nothing would. Same rules: a site counts as deleted when it
 * is not in the network and its WordPress options table is gone; the booking records stay while
 * they are within their period (or a legal hold was set) – then the network option
 * wpac_retained_sites stays too, and tools/purge-retained.php can be run again later.
 */
function wpac_uninstall_deleted_sites() {
	global $wpdb;
	$base  = $wpdb->base_prefix;
	$found = array_merge(
		(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( '_wpac_affiliates' ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( '_wpac_payouts' ) ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	);
	$retained = (array) get_site_option( 'wpac_retained_sites', array() );
	$seen     = array();
	foreach ( $found as $wpac_table ) {
		if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)_wpac_(affiliates|payouts)$/i', (string) $wpac_table, $m ) ) {
			continue;
		}
		$blog_id = (int) $m[1];
		if ( $blog_id < 2 || isset( $seen[ $blog_id ] ) || get_site( $blog_id ) ) {
			continue;
		}
		$seen[ $blog_id ] = true;
		$prefix           = $base . $blog_id . '_';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'options' ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			continue; // Not a deleted site (or not one of this network).
		}
		$keep = wpac_uninstall_deleted_site_retained( $prefix, isset( $retained[ $blog_id ] ) ? (array) $retained[ $blog_id ] : null );
		foreach ( wpac_uninstall_tables() as $name ) {
			if ( $keep && in_array( $name, wpac_uninstall_financial_tables(), true ) ) {
				continue;
			}
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $prefix . 'wpac_' . $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		if ( ! $keep && isset( $retained[ $blog_id ] ) ) {
			wpac_uninstall_delete_retained_folder( isset( $retained[ $blog_id ]['folder'] ) ? (string) $retained[ $blog_id ]['folder'] : '' );
			unset( $retained[ $blog_id ] );
		}
	}
	// Kept folders of deleted sites whose tables are already gone.
	foreach ( $retained as $blog_id => $entry ) {
		if ( isset( $seen[ (int) $blog_id ] ) || get_site( (int) $blog_id ) ) {
			continue;
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base . (int) $blog_id . '_wpac_payouts' ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wpac_uninstall_delete_retained_folder( isset( $entry['folder'] ) ? (string) $entry['folder'] : '' );
			unset( $retained[ $blog_id ] );
		}
	}
	if ( $retained ) {
		update_site_option( 'wpac_retained_sites', $retained );
	} else {
		delete_site_option( 'wpac_retained_sites' );
	}
}

/**
 * Whether the kept booking records of a deleted site are still within their retention period
 * (as Core\Installer::deleted_site_retained()).
 *
 * @param string     $prefix Table prefix of the deleted site.
 * @param array|null $entry  Retention data remembered when the site was deleted (null: 10 years).
 * @return bool
 */
function wpac_uninstall_deleted_site_retained( $prefix, $entry ) {
	global $wpdb;
	$t = $prefix . 'wpac_payouts';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) || ! $wpdb->get_var( 'SHOW COLUMNS FROM `' . esc_sql( $t ) . "` LIKE 'storno_at'" ) ) {
		return false;
	}
	$years = null === $entry || ! isset( $entry['years'] ) ? 10 : (int) $entry['years'];
	if ( ! empty( $entry['hold'] ) || $years <= 0 ) {
		return true; // Keep forever / legal hold: the network admin deletes the tables by hand.
	}
	$cutoff = gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( $years + 1 ) . ' years' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . esc_sql( $t ) . '` WHERE created_at > %s OR storno_at > %s LIMIT 1', $cutoff, $cutoff ) ) ) {
		return true;
	}
	$k = $prefix . 'wpac_payout_corrections';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $k ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . esc_sql( $k ) . '` WHERE created_at > %s LIMIT 1', $cutoff ) );
}

/**
 * Delete the kept credit note folder of a deleted site ("…/site-N" only).
 *
 * @param string $folder Folder.
 */
function wpac_uninstall_delete_retained_folder( $folder ) {
	if ( '' === $folder || ! is_dir( $folder ) || ! preg_match( '#/site-\d+$#', untrailingslashit( $folder ) ) ) {
		return;
	}
	foreach ( (array) scandir( $folder ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry && is_file( $folder . '/' . $entry ) ) {
			wp_delete_file( $folder . '/' . $entry );
		}
	}
	@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Hooks of the plugin's scheduled tasks (kept in sync with Core\Cron::unschedule() by a test).
 *
 * @return string[]
 */
function wpac_uninstall_hooks() {
	return array( 'wpac_hourly', 'wpac_daily', 'wpac_daily_rest', 'wpac_report_retry', 'wpac_report_batch', 'wpac_jobs_drain', 'wpac_vies_check', 'wpac_network_setup', 'wpac_reencrypt', 'wpac_terms_remind', 'wpac_purge_visits', 'wpac_purge_rate_limits', 'wpac_erase_visits', 'wpac_visits_daily', 'wpac_balance_check', 'wpac_tier_continue', 'wpac_broadcast_batch' );
}

/**
 * Remove the user preferences of the admin list screens (rows per page).
 */
function wpac_uninstall_user_meta() {
	foreach ( array( 'wpac_audit_per_page', 'wpac_commissions_per_page', 'wpac_creatives_per_page', 'wpac_partners_per_page', 'wpac_payouts_per_page' ) as $wpac_meta ) {
		delete_metadata( 'user', 0, $wpac_meta, '', true );
	}
}

/**
 * Remove the scheduled tasks of the current site: WP-Cron events (with any arguments) and the
 * plugin's group in WooCommerce's Action Scheduler (when it is loaded).
 */
function wpac_uninstall_schedules() {
	// Same hooks as Core\Cron::unschedule() (the plugin's classes are not loaded here; a test keeps
	// both lists in sync).
	foreach ( wpac_uninstall_hooks() as $wpac_hook ) {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( $wpac_hook ); // All events of the hook, also those with arguments.
		} else {
			wp_clear_scheduled_hook( $wpac_hook );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'wp-affiliate-captain' );
		return;
	}
	// WooCommerce (with its Action Scheduler) not loaded, e.g. deactivated before this plugin was
	// deleted: its tables may still hold pending actions of the plugin's group, which would fail
	// ("no callbacks registered") once WooCommerce is active again (R14-A3).
	global $wpdb;
	$wpac_actions = $wpdb->prefix . 'actionscheduler_actions';
	$wpac_groups  = $wpdb->prefix . 'actionscheduler_groups';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_actions ) ) === $wpac_actions && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpac_groups ) ) === $wpac_groups ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE a FROM {$wpac_actions} a INNER JOIN {$wpac_groups} g ON g.group_id = a.group_id WHERE g.slug = %s AND a.status = %s", 'wp-affiliate-captain', 'pending' ) );
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
	if ( ! $own_folder || array_diff( $entries, array( '.htaccess', 'index.php', '.wpac-sweep' ) ) ) {
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
	/**
	 * Seconds the uninstall may spend on the sites of a network (0 = no limit). Sites not
	 * reached are recorded in the network option wpac_uninstall_incomplete.
	 *
	 * @param float $budget Default: 80 % of max_execution_time when it could not be lifted, else 0.
	 */
	$wpac_budget = (float) apply_filters( 'wpac_uninstall_time_budget', $wpac_limit > 0 ? $wpac_limit * 0.8 : 0 );
	$wpac_start  = microtime( true );
	$wpac_left   = array();
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpac_site_id ) {
		if ( $wpac_budget > 0 && microtime( true ) - $wpac_start > $wpac_budget ) {
			$wpac_left[] = (int) $wpac_site_id; // Time limit could not be lifted: record the rest.
			continue;
		}
		switch_to_blog( (int) $wpac_site_id );
		wpac_uninstall_site();
		restore_current_blog();
	}
	// Tables and kept credit notes of deleted sites whose retention period ended (R14-A2).
	wpac_uninstall_deleted_sites();
	// Network-wide state: sites still waiting for the set-up after a network activation, daily sweep marker.
	delete_site_option( 'wpac_network_pending' );
	delete_site_transient( 'wpac_orphan_sweep' );
	delete_site_option( 'wpac_legacy_takeover' );
	// Hidden notices stored by older versions for all sites at once; list screen preferences.
	delete_metadata( 'user', 0, 'wpac_hidden_notices', '', true );
	wpac_uninstall_user_meta();
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
	wpac_uninstall_user_meta();
}
