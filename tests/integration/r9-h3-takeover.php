<?php
/**
 * R9 package H3: the main file takes over the old copy ("wp-affiliate-captain", up to 0.10) on a
 * network, and its pre_uninstall_plugin guard keeps the shared data when the old copy is deleted.
 * Loads affiliate-captain.php itself (no test bootstrap, no database: the old copy's uninstall
 * routine runs against a recording $wpdb). Started by r9-h3.php in its own process.
 *
 * Argument "fresh": this copy is the first one loaded in the request.
 * Argument "preloaded": the old copy was loaded first in this request (WPAC_VERSION is defined).
 *
 * Prints the results as JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$h3_mode = isset( $argv[1] ) ? (string) $argv[1] : 'fresh';
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
if ( 'preloaded' === $h3_mode ) {
	define( 'WPAC_VERSION', '0.10.0' ); // Set by the old copy.
}

$h3 = array();
function h3( $name, $expected, $actual ) {
	global $h3;
	$h3[ $name ] = array( $expected, $actual );
}

// WordPress stand-ins: options per site of the network, network options, hooks.
$GLOBALS['h3_blog']    = 1;
$GLOBALS['h3_options'] = array( 1 => array(), 2 => array() );
$GLOBALS['h3_network'] = array();
$GLOBALS['h3_hooks']   = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['h3_hooks'][ $tag ][] = array( $cb, $args ); return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function apply_filters( $tag, $value, ...$rest ) {
	foreach ( $GLOBALS['h3_hooks'][ $tag ] ?? array() as $cb ) {
		$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, $cb[1] ) ) );
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	foreach ( $GLOBALS['h3_hooks'][ $tag ] ?? array() as $cb ) {
		call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
	}
}
function get_option( $k, $d = false ) {
	$o = $GLOBALS['h3_options'][ $GLOBALS['h3_blog'] ];
	return array_key_exists( $k, $o ) ? apply_filters( 'option_' . $k, $o[ $k ], $k ) : $d;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['h3_options'][ $GLOBALS['h3_blog'] ][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['h3_options'][ $GLOBALS['h3_blog'] ][ $k ] ); return true; }
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['h3_network'] ) ? $GLOBALS['h3_network'][ $k ] : $d; }
function update_site_option( $k, $v ) { $GLOBALS['h3_network'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['h3_network'][ $k ] ); return true; }
function delete_site_transient( $k ) { return true; }
function is_multisite() { return true; }
function get_sites( $a = array() ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { $GLOBALS['h3_blog'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['h3_blog'] = 1; return true; }
function get_current_blog_id() { return $GLOBALS['h3_blog']; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function register_activation_hook( $f, $cb ) { $GLOBALS['h3_activation'] = $cb; }
function register_deactivation_hook( $f, $cb ) {}
function esc_html__( $t, $d = null ) { return $t; }
function wp_unschedule_hook( $h ) { return 0; }
function delete_metadata( $t, $id, $k, $v = '', $all = false ) { return true; }
function delete_post_meta_by_key( $k ) { return true; }
function wp_roles() { return (object) array( 'role_objects' => array() ); }
function wp_trash_post( $id ) { $GLOBALS['h3_trashed'][] = (int) $id; return true; }
function get_post( $id ) { return null; }
function wp_upload_dir( $t = null, $c = false ) { return array( 'basedir' => sys_get_temp_dir() . '/wpac-h3-uploads' ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function untrailingslashit( $p ) { return rtrim( $p, '/' ); }
function wp_delete_file( $f ) {}

/** Records the queries of the old copy's uninstall routine. */
class H3_Recording_WPDB {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $queries = array();
	public function get_blog_prefix( $id = null ) { return 'wp_'; }
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $a ); }
	public function query( $q ) { $this->queries[] = $q; return 0; }
	public function get_var( $q ) { $this->queries[] = $q; return 0 === strpos( $q, 'SHOW TABLES' ) ? 'wp_wpac_payouts' : 0; }
	public function get_results( $q, $o = null ) { $this->queries[] = $q; return array(); }
}
$GLOBALS['wpdb'] = new H3_Recording_WPDB();

$h3_self   = plugin_basename( dirname( __DIR__, 2 ) . '/affiliate-captain.php' );
$h3_legacy = 'wp-affiliate-captain/wp-affiliate-captain.php';

// The old copy is network-active; the new copy was activated on site 1 only. Both sites have
// "Delete all data" on.
$GLOBALS['h3_network']['active_sitewide_plugins'] = array( $h3_legacy => 1700000000 );
$GLOBALS['h3_options'][1] = array( 'active_plugins' => array( 'other/other.php', $h3_self ), 'wpac_settings' => array( 'delete_on_uninstall' => 1 ) );
$GLOBALS['h3_options'][2] = array( 'active_plugins' => array(), 'wpac_settings' => array( 'delete_on_uninstall' => 1 ) );

require dirname( __DIR__, 2 ) . '/affiliate-captain.php';

$h3_label = 'preloaded' === $h3_mode ? 'old copy loaded first' : 'new copy loaded';
h3( "R5-O55 multisite takeover ({$h3_label}): network activation moves to the new copy, not also per site", array( array( $h3_self => 1700000000 ), array( 'other/other.php' ) ), array( get_site_option( 'active_sitewide_plugins' ), $GLOBALS['h3_options'][1]['active_plugins'] ) );
h3( "R5-O55 multisite takeover ({$h3_label}): takeover recorded for the network sweep, \"delete all data\" off on this site", array( array( $h3_legacy ), 0, 0, 1 ), array( get_site_option( 'wpac_legacy_takeover' )['plugins'], get_site_option( 'wpac_legacy_takeover' )['swept'], $GLOBALS['h3_options'][1]['wpac_settings']['delete_on_uninstall'], $GLOBALS['h3_options'][1]['wpac_takeover_deletion_off'] ) );
h3( "R5-O55 multisite takeover ({$h3_label}): the other site keeps its setting until the sweep", 1, $GLOBALS['h3_options'][2]['wpac_settings']['delete_on_uninstall'] );

if ( 'preloaded' === $h3_mode ) {
	// The old copy serves this request: this copy registers nothing (no second boot, no hooks).
	h3( 'R5-O55 old copy loaded first: the new copy returns before its hooks', array( false, false, false ), array( isset( $GLOBALS['h3_hooks']['pre_uninstall_plugin'] ), isset( $GLOBALS['h3_hooks']['plugins_loaded'] ), defined( 'WPAC_FILE' ) ) );
} else {
	h3( 'R5-O55 new copy loaded: boot and uninstall guard registered, version of this copy', array( true, true, true ), array( isset( $GLOBALS['h3_hooks']['pre_uninstall_plugin'] ), isset( $GLOBALS['h3_hooks']['plugins_loaded'] ), WPAC_VERSION !== '0.10.0' ) );

	// Site 1 was switched back on (e.g. by hand) after the takeover; site 2 not swept yet.
	$GLOBALS['h3_options'][1]['wpac_settings']['delete_on_uninstall'] = 1;
	// Deleting some other plugin: the guard does nothing.
	do_action( 'pre_uninstall_plugin', 'other/other.php', array() );
	do_action( 'pre_uninstall_plugin', $h3_self, array() );
	h3( 'R5-O55 uninstall guard: not active for other plugins or this copy itself', array( false, 1 ), array( isset( $GLOBALS['h3_hooks']['option_wpac_settings'] ), get_option( 'wpac_settings' )['delete_on_uninstall'] ?? null ) );
	do_action( 'pre_uninstall_plugin', $h3_legacy, array() );
	switch_to_blog( 2 );
	$h3_site2 = get_option( 'wpac_settings' )['delete_on_uninstall'];
	restore_current_blog();
	h3( 'R5-O55 uninstall guard: deleting the old copy reads "delete all data" as off on every site (stored value unchanged)', array( 0, 0, 1, 1 ), array( get_option( 'wpac_settings' )['delete_on_uninstall'], $h3_site2, $GLOBALS['h3_options'][1]['wpac_settings']['delete_on_uninstall'], $GLOBALS['h3_options'][2]['wpac_settings']['delete_on_uninstall'] ) );

	// The old copy's uninstall routine (the same file as this copy's) on the network.
	$GLOBALS['h3_options'][1]['wpac_import_lock'] = '1700000000:abc';
	define( 'WP_UNINSTALL_PLUGIN', $h3_legacy );
	include dirname( __DIR__, 2 ) . '/uninstall.php';
	$h3_drops = preg_grep( '/^DROP TABLE/', $GLOBALS['wpdb']->queries );
	h3( 'R5-O55 deleting the old copy on a network keeps the shared data on every site', array( array(), true, true ), array( array_values( $h3_drops ), isset( $GLOBALS['h3_options'][1]['wpac_settings'] ), isset( $GLOBALS['h3_options'][2]['wpac_settings'] ) ) );
	h3( 'R5-O55 deleting the old copy: only schedules and locks go; no partial uninstall recorded', array( false, false ), array( isset( $GLOBALS['h3_options'][1]['wpac_import_lock'] ), get_site_option( 'wpac_uninstall_incomplete' ) ) );
}

echo "\n" . json_encode( $h3 ) . "\n";
