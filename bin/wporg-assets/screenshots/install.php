<?php
// phpcs:disable
$wp_dir = rtrim( $argv[1], '/' );
$_SERVER['HTTP_HOST']   = 'harbor-sailing.example.com';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_INSTALLING', true );
require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
	wp_install( 'Harbor Sailing School', 'admin', 'admin@example.com', false, '', 'admin-pass-123' );
}
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blog_public', 0 );
update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'date_format', 'F j, Y' );
$r = activate_plugin( 'affiliate-captain/affiliate-captain.php', '', false, false );
if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() ); exit( 1 ); }
echo "ok\n";
