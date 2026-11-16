<?php
/**
 * Delete the booking records that were kept when the plugin was deleted, once their statutory
 * retention period has ended (the uninstall keeps them while any credit note is younger).
 *
 * The plugin folder is removed on uninstall, so: upload the plugin again (do NOT activate it),
 * then run
 *
 *     wp eval-file wp-content/plugins/affiliate-captain/tools/purge-retained.php
 *
 * and delete the plugin again. It runs the normal uninstall, which deletes everything that is
 * past the retention period and keeps the rest (the run can be repeated later). On a network it
 * checks every site (no --url needed) and the kept records of deleted sites. Documents the
 * uninstall could not delete from the object storage within its time budget (or because the
 * storage failed) are recorded and deleted by this run as well (R15-P2, R15-P3).
 *
 * Several app servers without shared statement storage: the files are deleted on the server this
 * runs on. While the plugin is active each server removes its own copies of deleted credit notes
 * (`wp wpac statements sweep`); once the plugin is deleted nothing runs on the other servers any
 * more, so remove the statement folder there yourself after this reported success (it only holds
 * copies of the deleted documents).
 *
 * @package WPAffiliateCaptain
 */

defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never directly.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; // Command line only.
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$wpac_plugin = 'affiliate-captain/affiliate-captain.php';
if ( is_plugin_active( $wpac_plugin ) || ( is_multisite() && is_plugin_active_for_network( $wpac_plugin ) ) ) {
	WP_CLI::error( 'Affiliate Captain is active: its data is in use. Nothing was deleted.' );
}
if ( ! function_exists( 'wpac_purge_retained_sites' ) ) {
	/**
	 * Sites (or 0 for a single site) that kept booking records after the uninstall, plus whether a
	 * network still keeps records of deleted sites. On a network the flag is set per site – the
	 * shop is often not the main site, and "wp eval-file" runs on the main site (R14-A2).
	 *
	 * @return array{0: int[], 1: bool}
	 */
	function wpac_purge_retained_sites() {
		$sites = array();
		if ( ! is_multisite() ) {
			wp_cache_delete( 'wpac_retained_after_uninstall', 'options' );
			wp_cache_delete( 'wpac_uninstall_store_left', 'options' );
			return array( get_option( 'wpac_retained_after_uninstall' ) || get_option( 'wpac_uninstall_store_left' ) ? array( 0 ) : array(), false );
		}
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpac_site_id ) {
			switch_to_blog( (int) $wpac_site_id );
			wp_cache_delete( 'wpac_retained_after_uninstall', 'options' );
			wp_cache_delete( 'wpac_uninstall_store_left', 'options' );
			// Kept booking records, or documents the uninstall could not delete from the document
			// store in time (R15-P2, option wpac_uninstall_store_left).
			if ( get_option( 'wpac_retained_after_uninstall' ) || get_option( 'wpac_uninstall_store_left' ) ) {
				$sites[] = (int) $wpac_site_id;
			}
			restore_current_blog();
		}
		wp_cache_delete( 'wpac_retained_sites', 'site-options' );
		return array( $sites, (bool) get_site_option( 'wpac_retained_sites' ) );
	}
}
list( $wpac_sites, $wpac_deleted ) = wpac_purge_retained_sites();
if ( ! $wpac_sites && ! $wpac_deleted ) {
	WP_CLI::success( 'No retained data from an earlier uninstall.' );
	return;
}
if ( $wpac_sites && $wpac_sites !== array( 0 ) ) {
	WP_CLI::log( 'Sites with retained data: ' . implode( ', ', $wpac_sites ) );
}
if ( $wpac_deleted ) {
	WP_CLI::log( 'Retained data of deleted sites of the network is checked as well.' );
}
define( 'WP_UNINSTALL_PLUGIN', $wpac_plugin );
require dirname( __DIR__ ) . '/uninstall.php';
list( $wpac_sites, $wpac_deleted ) = wpac_purge_retained_sites();
if ( $wpac_sites || $wpac_deleted ) {
	WP_CLI::warning( 'Some credit notes are still within the retention period (or a legal hold is set), or could not be deleted from the document storage; their records are kept. Run this again later.' );
} else {
	WP_CLI::success( 'All retained data was deleted.' );
}
