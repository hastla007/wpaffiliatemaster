<?php
/**
 * Delete the booking records that were kept when the plugin was deleted, once their statutory
 * retention period has ended (the uninstall keeps them while any credit note is younger).
 *
 * The plugin folder is removed on uninstall, so: upload the plugin again (do NOT activate it),
 * then run
 *
 *     wp eval-file wp-content/plugins/wp-affiliate-captain/tools/purge-retained.php
 *
 * and delete the plugin again. It runs the normal uninstall, which deletes everything that is
 * past the retention period and keeps the rest (the run can be repeated later).
 *
 * @package WPAffiliateCaptain
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; // Command line only.
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$wpac_plugin = 'wp-affiliate-captain/wp-affiliate-captain.php';
if ( is_plugin_active( $wpac_plugin ) || ( is_multisite() && is_plugin_active_for_network( $wpac_plugin ) ) ) {
	WP_CLI::error( 'WP Affiliate Captain is active: its data is in use. Nothing was deleted.' );
}
$wpac_before = get_option( 'wpac_retained_after_uninstall' );
if ( ! $wpac_before ) {
	WP_CLI::success( 'No retained data from an earlier uninstall.' );
	return;
}
define( 'WP_UNINSTALL_PLUGIN', $wpac_plugin );
require dirname( __DIR__ ) . '/uninstall.php';
wp_cache_delete( 'wpac_retained_after_uninstall', 'options' );
if ( get_option( 'wpac_retained_after_uninstall' ) ) {
	WP_CLI::warning( 'Some credit notes are still within the retention period (or a legal hold is set); their records are kept. Run this again later.' );
} else {
	WP_CLI::success( 'All retained data was deleted.' );
}
