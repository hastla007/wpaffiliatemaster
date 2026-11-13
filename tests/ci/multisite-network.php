<?php
// Step 1 of multisite.sh: create the network tables for the installed single site.
$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_INSTALLING_NETWORK', true );
require rtrim( $argv[1], '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/network.php';
// The plugin stays active on the main site; it is activated network-wide in step 2.
if ( ! get_option( 'wpac_db_version' ) ) {
	fwrite( STDERR, "plugin not installed on the main site\n" );
	exit( 1 );
}
// As wp-admin/network.php does: the network table names are only set on multisite.
global $wpdb;
foreach ( $wpdb->tables( 'ms_global' ) as $table => $prefixed ) {
	$wpdb->$table = $prefixed;
}
install_network();
$r = populate_network( 1, 'example.test', 'admin@example.test', 'CI Network', '/', false );
if ( is_wp_error( $r ) && 'siteid_exists' !== $r->get_error_code() ) {
	fwrite( STDERR, 'network: ' . $r->get_error_message() . "\n" );
	exit( 1 );
}
echo "network created\n";
