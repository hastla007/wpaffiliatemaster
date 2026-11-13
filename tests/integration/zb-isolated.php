<?php
/**
 * ZB integration checks that change process-wide state (Action Scheduler functions, multisite,
 * WPAC_STATEMENT_DIR, uninstall). Started by run.php in its own process with its own table prefix;
 * prints the results as JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
define( 'WPAC_STATEMENT_DIR', sys_get_temp_dir() . '/wpac-zb-shared' );
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Scheduler;

$zb = array();
function zb( $name, $expected, $actual ) {
	global $zb;
	$zb[ $name ] = array( $expected, $actual );
}

// Action Scheduler stand-in with the real matching rules.
$GLOBALS['test_as'] = array();
function as_schedule_single_action( $ts, $hook, $args = array(), $group = '' ) {
	$GLOBALS['test_as'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending' );
	return count( $GLOBALS['test_as'] );
}
function zb_as_find( $hook, $args, $group, $status ) {
	$ids = array();
	foreach ( $GLOBALS['test_as'] as $i => $a ) {
		if ( ( '' === $hook || $a['hook'] === $hook ) && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) && $a['status'] === $status ) {
			$ids[] = $i + 1;
		}
	}
	return $ids;
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	if ( zb_as_find( $hook, $args, $group, 'in-progress' ) ) {
		return true; // Like Action Scheduler: a running action counts.
	}
	return zb_as_find( $hook, $args, $group, 'pending' ) ? time() + 60 : false;
}
function as_get_scheduled_actions( $q = array(), $format = 'ids' ) {
	return zb_as_find( isset( $q['hook'] ) ? $q['hook'] : '', array_key_exists( 'args', $q ) ? $q['args'] : null, isset( $q['group'] ) ? $q['group'] : '', isset( $q['status'] ) ? $q['status'] : 'pending' );
}
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	if ( empty( $args ) && '' !== $hook && '' === $group ) {
		$ids = zb_as_find( $hook, null, '', 'pending' ); // By hook: every run, whatever its arguments.
	} elseif ( empty( $args ) && '' === $hook && '' !== $group ) {
		$ids = zb_as_find( '', null, $group, 'pending' ); // By group.
	} else {
		$ids = zb_as_find( $hook, $args, $group, 'pending' ); // Exact arguments only.
	}
	foreach ( $ids as $id ) {
		$GLOBALS['test_as'][ $id - 1 ]['status'] = 'canceled';
	}
}
function zb_pending_count( $hook ) {
	return count( zb_as_find( $hook, null, Scheduler::GROUP, 'pending' ) );
}

// Multisite stand-ins.
$GLOBALS['zb_site_options'] = array( 'wpac_network_pending' => array( 9, 10 ) );
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['zb_site_options'] ) ? $GLOBALS['zb_site_options'][ $k ] : $d; }
function delete_site_option( $k ) { unset( $GLOBALS['zb_site_options'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_site_option( '_t_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['zb_site_options'][ '_t_' . $k ] = $v; return true; }
function delete_site_transient( $k ) { return delete_site_option( '_t_' . $k ); }
function get_site( $id ) { return null; }
function esc_sql( $s ) { return $GLOBALS['wpdb']->db->real_escape_string( (string) $s ); }
function get_sites( $a = array() ) { return array( 3 ); }
function switch_to_blog( $id ) { $GLOBALS['test_blog_id'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['test_blog_id'] = 1; return true; }
function delete_post_meta_by_key( $k ) { return true; }
function wp_roles() { return (object) array( 'role_objects' => array() ); }
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['test_scheduled'][] = $hook; return true; }
}

// W1: the running action does not count as pending, so a task can schedule its own continuation.
$batch                = WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK;
$GLOBALS['test_as'][] = array( 'hook' => $batch, 'args' => array(), 'group' => Scheduler::GROUP, 'status' => 'in-progress' );
zb( 'ZB-W1 Action Scheduler is used', true, Scheduler::uses_action_scheduler() );
zb( 'ZB-W1 the running batch is not "pending"', false, Scheduler::pending( $batch ) );
Scheduler::once( $batch, 60 );
Scheduler::once( $batch, 60 );
zb( 'ZB-W1 the batch schedules its continuation once (no duplicate)', array( true, 1 ), array( Scheduler::pending( $batch ), zb_pending_count( $batch ) ) );
$GLOBALS['test_as'][] = array( 'hook' => WPAffiliateCaptain\Core\Jobs::DRAIN_HOOK, 'args' => array(), 'group' => Scheduler::GROUP, 'status' => 'in-progress' );
Scheduler::once( WPAffiliateCaptain\Core\Jobs::DRAIN_HOOK, 300 );
zb( 'ZB-W1 queue drain continues from inside its own run', 1, zb_pending_count( WPAffiliateCaptain\Core\Jobs::DRAIN_HOOK ) );

// W16: per-partner actions (with arguments) are removed; deactivation clears the whole group.
WPAffiliateCaptain\Core\Settings::update( array( 'vies_check' => 1 ) );
WPAffiliateCaptain\Payouts\Vies::schedule( 5 );
WPAffiliateCaptain\Payouts\Vies::schedule( 6 );
zb( 'ZB-W16 two VIES checks with arguments are queued', 2, zb_pending_count( WPAffiliateCaptain\Payouts\Vies::HOOK ) );
Scheduler::clear( WPAffiliateCaptain\Payouts\Vies::HOOK );
zb( 'ZB-W16 clear() removes actions with arguments', 0, zb_pending_count( WPAffiliateCaptain\Payouts\Vies::HOOK ) );
as_schedule_single_action( time(), 'wpac_some_future_hook', array( 1 ), Scheduler::GROUP );
WPAffiliateCaptain\Core\Installer::deactivate_site();
zb( 'ZB-W16 deactivation leaves nothing of the group pending', array(), zb_as_find( '', null, Scheduler::GROUP, 'pending' ) );

// W12: tables of a deleted subsite are dropped later by the daily sweep (only when its WordPress tables are gone).
$GLOBALS['test_multisite'] = 1;
$pre                       = $wpdb->base_prefix;
foreach ( array( '7_wpac_affiliates', '7_wpac_payouts', '8_wpac_affiliates', '8_options' ) as $tb ) {
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}{$tb} ( id int )" );
}
$n = WPAffiliateCaptain\Core\Installer::drop_orphaned_tables();
zb(
	'ZB-W12 deleted site cleaned up, a site with its own options table kept',
	array( 1, null, null, $pre . '8_wpac_affiliates' ),
	array( $n, $wpdb->get_var( "SHOW TABLES LIKE '{$pre}7\\_wpac\\_affiliates'" ), $wpdb->get_var( "SHOW TABLES LIKE '{$pre}7\\_wpac\\_payouts'" ), $wpdb->get_var( "SHOW TABLES LIKE '{$pre}8\\_wpac\\_affiliates'" ) )
);
zb( 'ZB-W12 sweep runs at most once a day', 0, WPAffiliateCaptain\Core\Installer::drop_orphaned_tables() );
foreach ( array( '8_wpac_affiliates', '8_options' ) as $tb ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$pre}{$tb}" );
}

// K1: per-site statement folder on a network with WPAC_STATEMENT_DIR; existing files are moved.
$root = untrailingslashit( WPAC_STATEMENT_DIR );
foreach ( array_merge( (array) glob( $root . '/*/*' ), (array) glob( $root . '/*' ) ) as $f ) {
	is_dir( $f ) ? @rmdir( $f ) : @unlink( $f );
}
$GLOBALS['test_blog_id'] = 3;
zb( 'ZB-K1 own statement folder per site', $root . '/site-3', WPAffiliateCaptain\Payouts\Statement::dir() );
@mkdir( $root . '/site-4', 0777, true );
foreach ( array( $root . '/GS-zb-own.pdf', $root . '/ST-zb-own.pdf', $root . '/GS-foreign.pdf', $root . '/site-4/GS-other.pdf' ) as $f ) {
	file_put_contents( $f, 'pdf' );
}
$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'GS-ZB-1', 'affiliate_id' => 1, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'statement_file' => 'GS-zb-own.pdf', 'storno_file' => 'ST-zb-own.pdf', 'created_at' => '2000-01-31 00:00:00' ) );
update_option( 'wpac_db_version', 8 );
WPAffiliateCaptain\Core\Installer::install_schema();
zb(
	'ZB-K1 v9 migration moves the site\'s own documents into its folder',
	array( true, true, false, true ),
	array( is_file( $root . '/site-3/GS-zb-own.pdf' ), is_file( $root . '/site-3/ST-zb-own.pdf' ), is_file( $root . '/GS-zb-own.pdf' ), is_file( $root . '/GS-foreign.pdf' ) )
);

// K1/W16/O15: uninstall deletes only this site's documents and removes schedules, locks and network state.
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE' ) ) );
update_option( 'wpac_report_retry_lock', time() . ':x' );
$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('wpac_stmt_77', '1:x', 'off')" );
as_schedule_single_action( time(), 'wpac_report_retry', array(), Scheduler::GROUP );
define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
include WPAC_DIR . 'uninstall.php';
zb(
	'ZB-K1 uninstall deletes only the files of this site\'s payouts',
	array( false, true, true ),
	array( is_dir( $root . '/site-3' ), is_file( $root . '/GS-foreign.pdf' ), is_file( $root . '/site-4/GS-other.pdf' ) )
);
zb(
	'ZB-O15 uninstall removes locks, network state and queued actions',
	array( false, null, false, array() ),
	array( get_option( 'wpac_report_retry_lock' ), $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpac_stmt_77'" ), get_site_option( 'wpac_network_pending' ), zb_as_find( '', null, Scheduler::GROUP, 'pending' ) )
);
zb( 'ZB-K1 uninstall dropped the site\'s tables', null, $wpdb->get_var( "SHOW TABLES LIKE '{$pre}wpac\\_payouts'" ) );
foreach ( array_merge( (array) glob( $root . '/*/*' ), (array) glob( $root . '/*' ) ) as $f ) {
	is_dir( $f ) ? @rmdir( $f ) : @unlink( $f );
}

echo "\n" . json_encode( $zb ) . "\n";
