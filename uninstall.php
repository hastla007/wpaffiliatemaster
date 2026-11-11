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
$wpac_tables = array( 'affiliates', 'partner_groups', 'partner_codes', 'agreements', 'payout_profiles', 'visits', 'attributions', 'rules', 'commissions', 'commission_events', 'payouts', 'payout_items', 'jobs', 'audit', 'tiers', 'customer_links', 'creatives', 'payout_requests' );
foreach ( $wpac_tables as $wpac_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpac_' . $wpac_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
delete_option( 'wpac_settings' );
delete_option( 'wpac_db_version' );
wp_clear_scheduled_hook( 'wpac_hourly' );
wp_clear_scheduled_hook( 'wpac_daily' );

foreach ( array( 'wpac_manage_program', 'wpac_manage_affiliates', 'wpac_manage_payouts', 'wpac_view_tax_data' ) as $wpac_cap ) {
	foreach ( wp_roles()->role_objects as $wpac_role ) {
		$wpac_role->remove_cap( $wpac_cap );
	}
}
