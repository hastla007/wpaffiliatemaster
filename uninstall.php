<?php
/**
 * Uninstall: only removes data when "Delete all data" was enabled in the settings.
 *
 * @package WPAffiliateCaptain
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpac_settings = get_option( 'wpac_settings', array() );
if ( empty( $wpac_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpac_tables = array( 'affiliates', 'partner_groups', 'partner_codes', 'agreements', 'payout_profiles', 'visits', 'attributions', 'rules', 'commissions', 'commission_events', 'payouts', 'payout_items', 'jobs', 'audit', 'tiers', 'customer_links', 'creatives', 'payout_requests', 'sequences', 'balances', 'balance_entries' );
foreach ( $wpac_tables as $wpac_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpac_' . $wpac_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
// Statements / credit notes (only reached when "delete all data" was explicitly enabled).
$wpac_dir = defined( 'WPAC_STATEMENT_DIR' ) && WPAC_STATEMENT_DIR ? untrailingslashit( WPAC_STATEMENT_DIR ) : trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'wpac-statements';
if ( is_dir( $wpac_dir ) ) {
	foreach ( (array) glob( $wpac_dir . '/{,.}*', GLOB_BRACE ) as $wpac_file ) {
		if ( is_file( $wpac_file ) ) {
			wp_delete_file( $wpac_file );
		}
	}
	@rmdir( $wpac_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

foreach ( array( 'wpac_settings', 'wpac_db_version', 'wpac_rewrite_flushed', 'wpac_report_month_sent', 'wpac_report_progress', 'wpac_customer_key' ) as $wpac_option ) {
	delete_option( $wpac_option );
}
// Rate-limit and other transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpac\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpac\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// Content meta (recommended partner targets).
delete_post_meta_by_key( '_wpac_promote' );
delete_post_meta_by_key( '_wpac_promote_note' );
// Note: order meta (_wpac_*) is kept with the orders; WordPress users created for partners are kept.
wp_clear_scheduled_hook( 'wpac_hourly' );
wp_clear_scheduled_hook( 'wpac_daily' );

foreach ( array( 'wpac_manage_program', 'wpac_manage_affiliates', 'wpac_manage_payouts', 'wpac_view_tax_data' ) as $wpac_cap ) {
	foreach ( wp_roles()->role_objects as $wpac_role ) {
		$wpac_role->remove_cap( $wpac_cap );
	}
}
