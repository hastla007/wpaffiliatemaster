<?php
/**
 * Installs and prepares the CI test site (see install-wp.sh): WordPress, WooCommerce with a
 * Blocks checkout and bank transfer, WP Affiliate Captain with application and partner pages,
 * a partner account with a code, a rule and a commission. Optionally Voucher + Pass Captain.
 *
 *   php tests/ci/setup.php <wp-dir> <url> [--siblings]
 *
 * Prints KEY=value lines for the browser tests (append them to $GITHUB_ENV).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$wp_dir   = rtrim( $argv[1], '/' );
$url      = $argv[2];
$siblings = in_array( '--siblings', $argv, true );
$parts    = wp_parse_url_light( $url );
$_SERVER['HTTP_HOST']   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_INSTALLING', true );
require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function wp_parse_url_light( $url ) {
	return parse_url( $url );
}
function out( $k, $v ) {
	echo $k, '=', $v, "\n";
}
function fail( $msg ) {
	fwrite( STDERR, "setup failed: $msg\n" );
	exit( 1 );
}

if ( ! is_blog_installed() ) {
	wp_install( 'Segelschule CI', 'admin', 'admin@example.test', false, '', 'admin-pass-123', 'de_DE' );
}
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blog_public', 0 );

$plugins = array( 'woocommerce/woocommerce.php', 'wp-affiliate-captain/wp-affiliate-captain.php' );
if ( $siblings ) {
	$plugins[] = 'voucher-captain/voucher-captain.php';
	$plugins[] = 'wp-pass-captain/wp-pass-captain.php';
}
foreach ( $plugins as $plugin ) {
	$r = activate_plugin( $plugin, '', false, false );
	if ( is_wp_error( $r ) ) {
		fail( $plugin . ': ' . $r->get_error_message() );
	}
}
fwrite( STDERR, "plugins active\n" );
// Data in a fresh process, where all plugins boot normally.
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/data.php' ) . ' ' . escapeshellarg( $wp_dir ) . ' ' . escapeshellarg( $url ) . ( $siblings ? ' --siblings' : '' ), $code );
exit( $code );
