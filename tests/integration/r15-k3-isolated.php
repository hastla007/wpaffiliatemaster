<?php
/**
 * K3 (R6-CC5) checks that need WPAC_S3_* constants or run uninstall.php. Started by r15-k3.php in
 * its own process with its own table prefix; mode "full" (complete configuration) or "partial"
 * (incomplete configuration: fallback to the local folder with a notice). Prints the results as
 * JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$k3_mode = isset( $argv[1] ) ? (string) $argv[1] : 'full';
if ( 'full' === $k3_mode ) {
	define( 'WPAC_S3_ENDPOINT', 'https://objects.example.test' );
	define( 'WPAC_S3_REGION', 'eu-central-1' );
	define( 'WPAC_S3_BUCKET', 'k3-const' );
	define( 'WPAC_S3_KEY', 'AKIDCONSTKEY' );
	define( 'WPAC_S3_SECRET', 'const-secret-xyz' );
	define( 'WPAC_S3_PREFIX', 'wpac' );
	define( 'WPAC_S3_PATH_STYLE', true );
} else {
	define( 'WPAC_S3_BUCKET', 'k3-const' );
	define( 'WPAC_S3_KEY', 'AKIDCONSTKEY' );
}
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/r15-k3-fake-s3.php';

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\Storage\S3Store;

if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $k ) { return true; } }
if ( ! function_exists( 'wp_roles' ) ) { function wp_roles() { return (object) array( 'role_objects' => array() ); } }
if ( ! function_exists( 'get_site_option' ) ) { function get_site_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'delete_site_option' ) ) { function delete_site_option( $k ) { return true; } }
if ( ! function_exists( 'delete_site_transient' ) ) { function delete_site_transient( $k ) { return true; } }
if ( ! function_exists( 'get_locale' ) ) { function get_locale() { return 'de_DE'; } }
if ( ! function_exists( 'delete_metadata' ) ) { function delete_metadata( $t, $id, $k, $v = '', $all = false ) { return true; } }
if ( ! function_exists( 'wp_trash_post' ) ) { function wp_trash_post( $id ) { return true; } }

$k3 = array();
function k3( $name, $expected, $actual ) {
	global $k3;
	$k3[ $name ] = array( $expected, $actual );
}

global $wpdb;
$uploads = sys_get_temp_dir() . '/wpac-uploads/wpac-statements';
$base    = 'https://objects.example.test/k3-const/wpac/';
$doc     = static function ( $number, $file, $bytes ) use ( $wpdb ) {
	$wpdb->insert( Db::table( 'payouts' ), array( 'number' => $number, 'affiliate_id' => 1, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'statement_file' => $file, 'statement_hash' => hash( 'sha256', $bytes ), 'created_at' => '2000-01-31 00:00:00' ) );
	return (int) $wpdb->insert_id;
};

if ( 'full' === $k3_mode ) {
	$store = Statement::store();
	k3(
		'R6-CC5 complete WPAC_S3_* constants activate the object storage (label without secret)',
		array( 's3', array(), 's3://k3-const/wpac/ (objects.example.test, eu-central-1)', false ),
		array( $store->id(), S3Store::config_problems(), $store->label(), false !== strpos( $store->label(), 'const-secret' ) )
	);
	$info = Monitor::support_data();
	k3( 'R6-CC5 Site Health: active store named, no storage warning', array( 's3', false ), array( $info['environment']['statement_store'] ?? '', isset( Monitor::checks()['statement_store'] ) ) );

	// Existing local documents: dry run, then migration (verified, local copy removed).
	@mkdir( $uploads, 0777, true );
	file_put_contents( $uploads . '/GS-K3-const.pdf', '%PDF-const' );
	$doc( 'GS-K3-const', 'GS-K3-const.pdf', '%PDF-const' );
	$GLOBALS['k3_s3_log'] = array();
	$dry = WPAffiliateCaptain\Cli\Command::migrate_statements( 's3', true );
	k3( 'R6-CC5 wp wpac statements migrate --to=s3 --dry-run: counts, sends nothing, keeps the file', array( 1, array( 'GS-K3-const.pdf' ), 0, true ), array( $dry['found'], $dry['files'], count( $GLOBALS['k3_s3_log'] ), is_file( $uploads . '/GS-K3-const.pdf' ) ) );
	k3( 'R6-CC5 not migrated documents are reported (Integrations, Site Health)', array( 1, 'GS-K3-const.pdf' ), array( count( Statement::misplaced() ), Statement::misplaced()[0][1] ?? '' ) );
	$res = WPAffiliateCaptain\Cli\Command::migrate_statements( 's3', false );
	k3(
		'R6-CC5 wp wpac statements migrate --to=s3: uploaded, read back, verified, local file removed',
		array( 1, 0, '%PDF-const', false, array( 'PUT', 'GET' ) ),
		array( $res['copied'], $res['failed'], $GLOBALS['k3_s3_objects'][ $base . 'GS-K3-const.pdf' ][0] ?? '', is_file( $uploads . '/GS-K3-const.pdf' ), array_column( $GLOBALS['k3_s3_log'], 0 ) )
	);
	k3( 'R6-CC5 migrated document downloadable from the store', hash( 'sha256', '%PDF-const' ), hash( 'sha256', (string) Statement::stored_bytes( WPAffiliateCaptain\Payouts\PayoutService::find( 1 ) ) ) );

	// Uninstall ("delete all data", nothing within the retention period): objects of this site's
	// payouts are deleted in the store; other objects in the bucket stay.
	$GLOBALS['k3_s3_objects'][ $base . 'GS-K3-two.pdf' ]                          = array( 'two', 0 );
	$GLOBALS['k3_s3_objects']['https://objects.example.test/k3-const/other/GS-K3-two.pdf'] = array( 'foreign', 0 );
	$GLOBALS['k3_s3_objects'][ $base . 'GS-not-ours.pdf' ]                        = array( 'x', 0 );
	$doc( 'GS-K3-two', 'GS-K3-two.pdf', 'two' );
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE' ) ) );
	define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
	include WPAC_DIR . 'uninstall.php';
	k3(
		'R6-CC5 uninstall deletes the site\'s documents through the store, nothing else',
		array( false, false, true, true, null ),
		array( isset( $GLOBALS['k3_s3_objects'][ $base . 'GS-K3-const.pdf' ] ), isset( $GLOBALS['k3_s3_objects'][ $base . 'GS-K3-two.pdf' ] ), isset( $GLOBALS['k3_s3_objects']['https://objects.example.test/k3-const/other/GS-K3-two.pdf'] ), isset( $GLOBALS['k3_s3_objects'][ $base . 'GS-not-ours.pdf' ] ), $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "wpac\\_payouts'" ) )
	);
} else {
	$store = Statement::store();
	k3(
		'R6-CC5 incomplete WPAC_S3_* constants: fallback to the local folder',
		array( 'local', array( 'WPAC_S3_SECRET', 'WPAC_S3_ENDPOINT / WPAC_S3_REGION' ), false ),
		array( $store->id(), S3Store::config_problems(), S3Store::configured() )
	);
	$checks = Monitor::checks();
	k3(
		'R6-CC5 incomplete configuration: Site Health critical, names the missing constants, no values',
		array( 'critical', true, false ),
		array( $checks['statement_store'][0] ?? '', false !== strpos( $checks['statement_store'][2] ?? '', 'WPAC_S3_SECRET' ), false !== strpos( wp_json_encode( $checks ), 'AKIDCONSTKEY' ) )
	);
	$t = Statement::test_store();
	k3( 'R6-CC5 fallback store works (local connection test)', array( true, 'local' ), array( $t['ok'], $t['store'] ) );
	$cli = WPAffiliateCaptain\Cli\Command::migrate_statements( 's3', true );
	k3( 'R6-CC5 migrate refuses an incomplete configuration and names what is missing', array( true, true ), array( is_wp_error( $cli ), is_wp_error( $cli ) && false !== strpos( $cli->get_error_message(), 'WPAC_S3_SECRET' ) ) );
	k3( 'R6-CC5 no request to the object storage with an incomplete configuration', array(), $GLOBALS['k3_s3_log'] );
}

echo "\n" . json_encode( $k3 ) . "\n";
