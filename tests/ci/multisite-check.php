<?php
// Step 2 of multisite.sh: network activation, new sites, uninstall clean-up per site.
$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1], '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
global $wpdb;
$ok   = 0;
$fail = 0;
function chk( $name, $cond ) {
	global $ok, $fail;
	echo ( $cond ? '  ok   ' : '  FAIL ' ), $name, "\n";
	$cond ? ++$ok : ++$fail;
}
wp_set_current_user( 1 );
grant_super_admin( 1 );
$plugin = 'wp-affiliate-captain/wp-affiliate-captain.php';
deactivate_plugins( $plugin );
$r = activate_plugin( $plugin, '', true );
chk( 'network activation', ! is_wp_error( $r ) && is_plugin_active_for_network( $plugin ) );
$has = static function ( $blog_id ) use ( $wpdb ) {
	$t = $wpdb->get_blog_prefix( $blog_id ) . 'wpac_affiliates';
	return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
};
chk( 'main site tables', $has( 1 ) );
$site = wpmu_create_blog( 'example.test', '/site2/', 'Site 2', 1 );
chk( 'new site created', ! is_wp_error( $site ) );
$site = is_wp_error( $site ) ? 0 : (int) $site;
chk( 'new site gets its own tables (network active)', $site && $has( $site ) );
switch_to_blog( $site );
chk( 'new site schema is current', (int) get_option( 'wpac_db_version' ) === WPAC_DB_VERSION );
chk( 'new site has its own capabilities', get_role( 'administrator' ) && get_role( 'administrator' )->has_cap( 'wpac_manage_program' ) );
chk( 'new site has its scheduled tasks', (bool) wp_next_scheduled( 'wpac_hourly' ) );
restore_current_blog();
// Settings and data stay per site.
switch_to_blog( $site );
WPAffiliateCaptain\Core\Settings::update( array( 'referral_param' => 'site2ref' ) );
restore_current_blog();
WPAffiliateCaptain\Core\Settings::flush_cache();
chk( 'settings are per site', 'site2ref' !== WPAffiliateCaptain\Core\Settings::get( 'referral_param' ) );
wpmu_delete_blog( $site, true );
chk( 'deleting a site drops its plugin tables', ! $has( $site ) );
echo "\n$ok ok, $fail failures\n";
exit( $fail ? 1 : 0 );
