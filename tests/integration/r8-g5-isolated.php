<?php
/**
 * R8 package G5 checks that change process-wide state (uninstall, reinstall, multisite stand-ins,
 * WPAC_STATEMENT_DIR). Started by r8-g5.php in its own process with its own table prefix; prints
 * the results as JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
define( 'WPAC_STATEMENT_DIR', sys_get_temp_dir() . '/wpac-g5-dir' );
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Lifecycle;

$g5 = array();
function g5( $name, $expected, $actual ) {
	global $g5;
	$g5[ $name ] = array( $expected, $actual );
}
function g5_table( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
}

// Stand-ins (multisite and uninstall).
$GLOBALS['g5_site_options'] = array();
$GLOBALS['g5_sites']        = array( 1, 3 );
$GLOBALS['g5_blog_options'] = array();
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['g5_site_options'] ) ? $GLOBALS['g5_site_options'][ $k ] : $d; }
function update_site_option( $k, $v ) { $GLOBALS['g5_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['g5_site_options'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_site_option( '_t_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['g5_site_options'][ '_t_' . $k ] = $v; return true; }
function delete_site_transient( $k ) { return delete_site_option( '_t_' . $k ); }
function get_site( $id ) { return null; }
function esc_sql( $s ) { return $GLOBALS['wpdb']->db->real_escape_string( (string) $s ); }
function get_sites( $a = array() ) { return $GLOBALS['g5_sites']; }
function get_current_network_id() { return 1; }
function get_main_site_id() { return 1; }
function switch_to_blog( $id ) { $GLOBALS['test_blog_id'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['test_blog_id'] = 1; return true; }
function get_blog_option( $id, $k, $d = false ) { return $GLOBALS['g5_blog_options'][ (int) $id ][ $k ] ?? $d; }
function get_blogs_of_user( $u, $all = false ) { return array( 1 => (object) array( 'userblog_id' => 1 ) ); }
function is_plugin_active_for_network( $p ) { return false; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function delete_post_meta_by_key( $k ) { return true; }
function wp_roles() { return (object) array( 'role_objects' => array() ); }
function delete_metadata( $t, $id, $k, $v = '', $all = false ) { $GLOBALS['g5_deleted_meta'][] = $k; return true; }
function wp_trash_post( $id ) { $GLOBALS['g5_trashed'][] = (int) $id; return true; }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['test_scheduled'][] = $hook; return true; } }

global $wpdb;
$root    = untrailingslashit( WPAC_STATEMENT_DIR );
$uploads = sys_get_temp_dir() . '/wpac-uploads/wpac-statements';
@mkdir( $root, 0777, true );
@mkdir( $uploads, 0777, true );

// R5-W8: expired credit notes are also deleted in the earlier folder (uploads, before
// WPAC_STATEMENT_DIR) – only the document with this payout's hash.
file_put_contents( $uploads . '/G5-W8-own.pdf', 'pdf-own' );
file_put_contents( $uploads . '/G5-W8-other.pdf', 'pdf-of-someone-else' );
$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'G5-W8-1', 'affiliate_id' => 1, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'statement_file' => 'G5-W8-own.pdf', 'statement_hash' => hash( 'sha256', 'pdf-own' ), 'created_at' => '2000-01-31 00:00:00' ) );
$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'G5-W8-2', 'affiliate_id' => 1, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'statement_file' => 'G5-W8-other.pdf', 'statement_hash' => hash( 'sha256', 'different' ), 'created_at' => '2000-01-31 00:00:00' ) );
WPAffiliateCaptain\Payouts\Statement::purge_expired();
g5( 'R5-W8 expired credit note deleted in the earlier folder, a foreign file of the same name kept', array( false, true ), array( is_file( $uploads . '/G5-W8-own.pdf' ), is_file( $uploads . '/G5-W8-other.pdf' ) ) );
@unlink( $uploads . '/G5-W8-other.pdf' );
$wpdb->query( 'DELETE FROM ' . Db::table( 'payouts' ) );

// R6-LC1/LC5: uninstall with "delete all data" while a cancellation is within the retention
// period keeps the booking records – and the partners table, pseudonymised.
$old = AffiliateRepository::create( array( 'name' => 'Alt Partner', 'email' => 'alt@example.de', 'status' => 'active' ) );
$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'G5-1', 'affiliate_id' => $old, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'created_at' => '2000-01-31 00:00:00', 'storno_number' => 'ST-G5-1', 'storno_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::table( 'balances' ) . " (affiliate_id, currency, balance_minor, entries, last_hash, updated_at) VALUES (%d, 'EUR', 4200, 1, 'h', NOW())", $old ) );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE', 'registration_page_id' => 900 ) ) );
update_option( 'wpac_terms_remind', array( 'sent' => 1 ) );
update_option( 'wpac_reencrypt_cursor', 5 );
$pre = $wpdb->prefix;
define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
include WPAC_DIR . 'uninstall.php';
$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'affiliates' ) . ' WHERE id = ' . (int) $old );
g5( 'R6-LC5 a recent cancellation keeps the records (storno_at counts)', array( $pre . 'wpac_payouts', true ), array( g5_table( $pre . 'wpac_payouts' ), (bool) get_option( 'wpac_retained_after_uninstall' ) ) );
g5( 'R6-LC1 partners table kept with the records, pseudonymised', array( $pre . 'wpac_affiliates', 'deleted-' . $old . '@invalid.invalid', 'Deleted partner', 'terminated', null ), array( g5_table( $pre . 'wpac_affiliates' ), $row ? $row->email : null, $row ? $row->name : null, $row ? $row->status : null, g5_table( $pre . 'wpac_partner_codes' ) ) );
g5( 'R5-O5 uninstall removes terms reminder / re-encryption state and the per-site hidden notices', array( false, false, true, true ), array( get_option( 'wpac_terms_remind' ), get_option( 'wpac_reencrypt_cursor' ), in_array( $pre . 'wpac_hidden_notices', (array) ( $GLOBALS['g5_deleted_meta'] ?? array() ), true ), in_array( 'wpac_hidden_notices', (array) ( $GLOBALS['g5_deleted_meta'] ?? array() ), true ) ) );
g5( 'R6-LC9 pages without the plugin block are not touched', array(), (array) ( $GLOBALS['g5_trashed'] ?? array() ) );

// Reinstall: the old partner number stays reserved.
Installer::install_schema();
Installer::adopt_retained();
AffiliateRepository::clear_cache();
$new = AffiliateRepository::create( array( 'name' => 'Neu Partner', 'email' => 'neu@example.de', 'status' => 'active' ) );
g5( 'R6-LC1 reinstall: a new partner never gets the old ID', true, (int) $new > (int) $old );
g5( 'R6-LC1 reinstall: marker cleared, "delete all data" off', array( false, 0 ), array( get_option( 'wpac_retained_after_uninstall' ), (int) get_option( 'wpac_settings' )['delete_on_uninstall'] ) );
// An uninstall of an older version dropped the partners table: IDs are reserved by placeholders.
$wpdb->query( 'DROP TABLE ' . Db::table( 'affiliates' ) );
Installer::install_schema();
update_option( 'wpac_retained_after_uninstall', '2026-01-01 00:00:00' );
$reserved = Installer::adopt_retained();
AffiliateRepository::clear_cache();
$new2 = AffiliateRepository::create( array( 'name' => 'Neu Partner 2', 'email' => 'neu2@example.de', 'status' => 'active' ) );
g5( 'R6-LC1 older uninstall: referenced IDs get placeholders, new partner gets a fresh ID', array( 1, 'deleted-' . $old . '@invalid.invalid', true ), array( $reserved, (string) $wpdb->get_var( 'SELECT email FROM ' . Db::table( 'affiliates' ) . ' WHERE id = ' . (int) $old ), (int) $new2 > (int) $old ) );

// R6-LC6: with WPAC_STATEMENT_DIR the earlier uploads folder is cleaned up as well.
$wpdb->query( 'UPDATE ' . Db::table( 'payouts' ) . " SET storno_at = '2000-02-01 00:00:00', statement_file = 'G5-LC6.pdf'" );
file_put_contents( $uploads . '/G5-LC6.pdf', 'pdf' );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1 ) ) );
wpac_uninstall_site();
g5( 'R6-LC6 uninstall deletes the site\'s files in the earlier uploads folder too', array( false, null ), array( is_file( $uploads . '/G5-LC6.pdf' ), g5_table( $pre . 'wpac_affiliates' ) ) );
foreach ( WPAffiliateCaptain\Core\Db::TABLES as $tb ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Db::table( $tb ) );
}
Installer::install_schema();

// ---------------------------------------------------------------- multisite
$GLOBALS['test_multisite'] = 1;

// R5-W1: the old copy is network-active, the new one only on this site → the new copy takes over
// the network activation (the program keeps running on every other site).
$GLOBALS['g5_site_options']['active_sitewide_plugins'] = array( 'wp-affiliate-captain/wp-affiliate-captain.php' => 123 );
update_option( 'active_plugins', array( 'affiliate-captain/affiliate-captain.php' ) );
Lifecycle::take_over( 'affiliate-captain/affiliate-captain.php' );
g5( 'R5-W1 network: new copy takes the old one\'s network activation', array( array( 'affiliate-captain/affiliate-captain.php' => 123 ), array() ), array( get_site_option( 'active_sitewide_plugins' ), get_option( 'active_plugins' ) ) );
// The new copy is network-active, the old one active on a site → only removed there.
$GLOBALS['g5_site_options']['active_sitewide_plugins'] = array( 'affiliate-captain/affiliate-captain.php' => 1 );
update_option( 'active_plugins', array( 'other/other.php', 'wp-affiliate-captain/wp-affiliate-captain.php' ) );
Lifecycle::take_over( 'affiliate-captain/affiliate-captain.php' );
g5( 'R5-W1 network-active new copy: old copy removed on the site, network list unchanged', array( array( 'affiliate-captain/affiliate-captain.php' => 1 ), array( 'other/other.php' ) ), array( get_site_option( 'active_sitewide_plugins' ), get_option( 'active_plugins' ) ) );
// Follow-up: every site of the network gets "delete all data" switched off.
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1 ) ) );
$GLOBALS['g5_site_options'][ Lifecycle::TAKEOVER ] = array( 'plugins' => array( 'wp-affiliate-captain/wp-affiliate-captain.php' ), 'time' => time() + 5, 'swept' => 0 );
if ( ! function_exists( 'get_role' ) ) { function get_role( $r ) { return null; } }
Lifecycle::followup();
g5( 'R5-W2 network takeover: deletion switched off on the sites, sweep done, notice prepared', array( 0, 1, true ), array( (int) get_option( 'wpac_settings' )['delete_on_uninstall'], (int) get_site_option( Lifecycle::TAKEOVER )['swept'], is_array( get_option( 'wpac_takeover_notice' ) ) ) );

// R6-MS2: deleting a site keeps booking records within the retention period.
$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'G5-MS2', 'affiliate_id' => 1, 'period_end' => gmdate( 'Y-m-d' ), 'currency' => 'EUR', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$GLOBALS['test_blog_id'] = 3;
$drop = Installer::drop_tables( array(), 3 );
Installer::archive_statements( 3 );
$GLOBALS['test_blog_id'] = 1;
$kept = get_site_option( Installer::RETAINED_SITES );
g5( 'R6-MS2 site deletion keeps financial tables, drops the rest, remembers the site', array( false, false, true, true, $root . '/site-3' ), array( in_array( $pre . 'wpac_payouts', $drop, true ), in_array( $pre . 'wpac_affiliates', $drop, true ), in_array( $pre . 'wpac_visits', $drop, true ), isset( $kept[3] ), $kept[3]['folder'] ?? '' ) );
// Daily sweep of a deleted site (7): retained tables stay until the period has ended.
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}7_wpac_payouts ( id int, created_at datetime NULL, storno_at datetime NULL )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}7_wpac_visits ( id int )" );
$wpdb->query( "INSERT INTO {$pre}7_wpac_payouts VALUES ( 1, NOW(), NULL )" );
@mkdir( $root . '/site-7', 0777, true );
file_put_contents( $root . '/site-7/GS-7.pdf', 'pdf' );
$GLOBALS['g5_site_options'][ Installer::RETAINED_SITES ] = array( 7 => array( 'years' => 10, 'hold' => 0, 'folder' => $root . '/site-7', 'time' => time() ) );
Installer::drop_orphaned_tables();
g5( 'R6-MS2 sweep keeps the records of a deleted site within the period', array( $pre . '7_wpac_payouts', null, true ), array( g5_table( $pre . '7_wpac_payouts' ), g5_table( $pre . '7_wpac_visits' ), is_file( $root . '/site-7/GS-7.pdf' ) ) );
$wpdb->query( "UPDATE {$pre}7_wpac_payouts SET created_at = '2000-01-01 00:00:00'" );
delete_site_transient( 'wpac_orphan_sweep' );
Installer::drop_orphaned_tables();
g5( 'R6-MS2 after the period: tables, kept credit notes and the record go', array( null, false, false ), array( g5_table( $pre . '7_wpac_payouts' ), is_dir( $root . '/site-7' ), isset( get_site_option( Installer::RETAINED_SITES )[7] ) ) );

// R6-MS6: WP-CLI --network reaches every site where the plugin is active.
$GLOBALS['g5_blog_options'] = array( 3 => array( 'active_plugins' => array( plugin_basename( WPAC_FILE ) ) ) );
g5( 'R6-MS6 --network: sites with the plugin active', array( 3 ), WPAffiliateCaptain\Cli\Command::active_sites() );

// R6-MS5: deleting a user from the whole network unlinks the partner on its sites.
$linked = AffiliateRepository::create( array( 'name' => 'Linked', 'email' => 'linked@example.de', 'status' => 'active', 'user_id' => 777 ) );
AffiliateRepository::on_network_user_deleted( 777 );
AffiliateRepository::clear_cache();
g5( 'R6-MS5 network user deletion unlinks the partner', 0, (int) AffiliateRepository::find( $linked )->user_id );

foreach ( array_merge( (array) glob( $root . '/*/*' ), (array) glob( $root . '/*' ) ) as $f ) {
	is_dir( $f ) ? @rmdir( $f ) : @unlink( $f );
}
echo "\n" . json_encode( $g5 ) . "\n";
