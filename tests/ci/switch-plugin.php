<?php
/**
 * Upgrade test helper: the site runs the previous release in the old folder
 * "wp-affiliate-captain"; this version sits in "affiliate-captain" and is activated next to it
 * (as an operator would do). One plain request must hand over to the new copy: the old one is
 * deactivated (without its deactivation routine), the new one stays active.
 *
 *   php tests/ci/switch-plugin.php <wp-dir> <host>
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$_SERVER['HTTP_HOST']   = $argv[2];
$_SERVER['REQUEST_URI'] = '/';
$wp_dir                 = rtrim( $argv[1], '/' );
$old                    = 'wp-affiliate-captain/wp-affiliate-captain.php';
$new                    = 'affiliate-captain/affiliate-captain.php';

if ( empty( $argv[3] ) ) {
	// Step 1 (fresh process): add the new copy to the active plugins, the old one stays.
	define( 'SHORTINIT', true );
	require $wp_dir . '/wp-load.php';
	$active   = (array) get_option( 'active_plugins', array() );
	$active[] = $new;
	update_option( 'active_plugins', array_values( array_unique( $active ) ) );
	// Step 2 in a new process: a normal request with both copies listed.
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $wp_dir ) . ' ' . escapeshellarg( $argv[2] ) . ' request', $code );
	exit( $code );
}
if ( 'request' === $argv[3] ) {
	require $wp_dir . '/wp-load.php';
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $wp_dir ) . ' ' . escapeshellarg( $argv[2] ) . ' check', $code );
	exit( $code );
}
require $wp_dir . '/wp-load.php';
$active = (array) get_option( 'active_plugins', array() );
$ok     = in_array( $new, $active, true ) && ! in_array( $old, $active, true ) && class_exists( 'WPAffiliateCaptain\\Plugin' ) && $new === plugin_basename( WPAC_FILE );
echo ( $ok ? 'ok  ' : 'FAIL' ), " old copy deactivated, new copy active (", implode( ', ', $active ), ")\n";
// Deleting the old copy (its uninstall routine) must not remove the shared data, even with
// "Delete all data" switched on.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$settings                        = (array) get_option( 'wpac_settings', array() );
$settings['delete_on_uninstall'] = 1;
update_option( 'wpac_settings', $settings );
if ( is_file( WP_PLUGIN_DIR . '/' . $old ) ) {
	uninstall_plugin( $old );
}
$kept = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpac_affiliates' ) ) === $wpdb->prefix . 'wpac_affiliates';
$settings['delete_on_uninstall'] = 0;
update_option( 'wpac_settings', $settings );
echo ( $kept ? 'ok  ' : 'FAIL' ), " deleting the old copy keeps the data\n";
exit( $ok && $kept ? 0 : 1 );
