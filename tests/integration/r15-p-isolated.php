<?php
/**
 * Round 15, fix agent p: checks that need WPAC_S3_* constants, multisite stand-ins, WooCommerce
 * stand-ins or run uninstall.php. Started by r15-p.php in its own process with its own table
 * prefix; mode "delete" (uninstall with "delete all data", object storage), "keep" (uninstall
 * with records in the retention period), "network" (kept credit notes of deleted sites) or
 * "coupon" (automatic coupon after a withdrawn consent). Prints the results as JSON on the last
 * line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$p_mode = isset( $argv[1] ) ? (string) $argv[1] : 'delete';
if ( in_array( $p_mode, array( 'delete', 'network' ), true ) ) {
	define( 'WPAC_S3_ENDPOINT', 'https://objects.example.test' );
	define( 'WPAC_S3_REGION', 'eu-central-1' );
	define( 'WPAC_S3_BUCKET', 'p15' );
	define( 'WPAC_S3_KEY', 'AKIDP15KEY' );
	define( 'WPAC_S3_SECRET', 'p15-secret' );
	define( 'WPAC_S3_PREFIX', 'wpac' );
	define( 'WPAC_S3_PATH_STYLE', true );
}
require __DIR__ . '/bootstrap.php';
if ( in_array( $p_mode, array( 'delete', 'network' ), true ) ) {
	require __DIR__ . '/r15-k3-fake-s3.php';
}

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Payouts\Storage\S3Store;
use WPAffiliateCaptain\Payouts\Storage\StoreCleanup;

$p = array();
function p15( $name, $expected, $actual ) {
	global $p;
	$p[ $name ] = array( $expected, $actual );
}

// Stand-ins (uninstall, multisite).
$GLOBALS['p_site_options'] = array();
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['p_site_options'] ) ? $GLOBALS['p_site_options'][ $k ] : $d; }
function update_site_option( $k, $v ) { $GLOBALS['p_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['p_site_options'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_site_option( '_t_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['p_site_options'][ '_t_' . $k ] = $v; return true; }
function delete_site_transient( $k ) { return delete_site_option( '_t_' . $k ); }
function get_site( $id ) { return null; }
if ( ! function_exists( 'esc_sql' ) ) { function esc_sql( $s ) { return $GLOBALS['wpdb']->db->real_escape_string( (string) $s ); } }
function get_sites( $a = array() ) { return array(); }
function get_main_site_id() { return 1; }
function switch_to_blog( $id ) { $GLOBALS['test_blog_id'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['test_blog_id'] = 1; return true; }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $k ) { return true; } }
if ( ! function_exists( 'wp_roles' ) ) { function wp_roles() { return (object) array( 'role_objects' => array() ); } }
if ( ! function_exists( 'delete_metadata' ) ) { function delete_metadata( $t, $id, $k, $v = '', $all = false ) { return true; } }
if ( ! function_exists( 'wp_trash_post' ) ) { function wp_trash_post( $id ) { return true; } }
if ( ! function_exists( 'get_locale' ) ) { function get_locale() { return 'de_DE'; } }

global $wpdb;
$p_table = static function ( $name ) use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
};
$p_opt = static function ( $name ) {
	wp_cache_delete( $name, 'options' );
	return get_option( $name, null );
};
// Options written to the options table too (uninstall.php deletes by LIKE with SQL).
$p_db_opts = static function () use ( $wpdb ) {
	return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ('wpac_main_code_5', 'wpac_code_requests_5') ORDER BY option_name" );
};

// S3 DeleteObjects stand-in in front of the K3 fake (which has no POST): deletes the listed keys,
// reports the keys in $GLOBALS['p_deny'] as AccessDenied and records whether the payouts table
// still existed when the request came. $GLOBALS['p_batch'] = false: refused like a provider
// without DeleteObjects (501).
$GLOBALS['p_posts'] = array();
$GLOBALS['p_deny']  = array();
$GLOBALS['p_batch'] = true;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $wpdb ) {
		if ( 'POST' !== ( $args['method'] ?? '' ) || false === strpos( (string) $url, '?delete=' ) ) {
			return $pre;
		}
		// Runs after the K3 fake (priority 10), which answers every POST with 400.
		$body    = (string) ( $args['body'] ?? '' );
		$headers = (array) ( $args['headers'] ?? array() );
		$GLOBALS['p_posts'][] = array(
			'md5'    => ( $headers['content-md5'] ?? '' ) === base64_encode( md5( $body, true ) ),
			'signed' => 0 === strpos( (string) ( $headers['authorization'] ?? '' ), 'AWS4-HMAC-SHA256 Credential=' ) && ( $headers['x-amz-content-sha256'] ?? '' ) === hash( 'sha256', $body ),
			'table'  => (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "wpac\\_payouts'" ),
		);
		if ( ! $GLOBALS['p_batch'] ) {
			return k3_s3_reply( 501, '<Error><Code>NotImplemented</Code></Error>' );
		}
		$parts = parse_url( (string) $url );
		$base  = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
		preg_match_all( '#<Key>(.*?)</Key>#', $body, $m );
		$errors = '';
		$GLOBALS['p_posts'][ count( $GLOBALS['p_posts'] ) - 1 ]['keys'] = count( $m[1] );
		foreach ( $m[1] as $key ) {
			$key = html_entity_decode( $key, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$name = substr( $key, strrpos( $key, '/' ) + 1 );
			if ( in_array( $name, $GLOBALS['p_deny'], true ) ) {
				$errors .= '<Error><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';
				continue;
			}
			unset( $GLOBALS['k3_s3_objects'][ $base . WPAffiliateCaptain\Payouts\Storage\S3Store::encode_path( $key ) ] );
		}
		return k3_s3_reply( 200, '<?xml version="1.0" encoding="UTF-8"?><DeleteResult>' . $errors . '</DeleteResult>' );
	},
	20,
	3
);
$p_put = static function ( $prefix, $name ) {
	$GLOBALS['k3_s3_objects'][ 'https://objects.example.test/p15/' . $prefix . $name ] = array( 'pdf', time() - 3600 );
};
$p_has = static function ( $prefix, $name ) {
	return isset( $GLOBALS['k3_s3_objects'][ 'https://objects.example.test/p15/' . $prefix . $name ] );
};

if ( 'delete' === $p_mode ) {
	// --- R15-P2: batch deletion (DeleteObjects), fallback, errors. -----------------------------
	$store = S3Store::from_constants();
	$names = array();
	for ( $i = 1; $i <= 1500; $i++ ) {
		$names[] = 'GS-B-' . $i . '.pdf';
		$p_put( 'wpac/', 'GS-B-' . $i . '.pdf' );
	}
	$GLOBALS['p_deny'] = array( 'GS-B-7.pdf' );
	$res               = StoreCleanup::delete_names( $store, $names );
	$GLOBALS['p_deny'] = array();
	p15(
		'R15-P2 object storage deletes in batches (S3 DeleteObjects, 1000 keys per request, Content-MD5, signed), a refused key is returned',
		array( 2, array( 1000, 500 ), true, true, array( 'GS-B-7.pdf' ), false, true, 'S3 DeleteObjects: AccessDenied' ),
		array( count( $GLOBALS['p_posts'] ), array_column( $GLOBALS['p_posts'], 'keys' ), ! in_array( false, array_column( $GLOBALS['p_posts'], 'md5' ), true ), ! in_array( false, array_column( $GLOBALS['p_posts'], 'signed' ), true ), $res['left'], $p_has( 'wpac/', 'GS-B-1.pdf' ), $p_has( 'wpac/', 'GS-B-7.pdf' ), $res['error'] )
	);
	$GLOBALS['p_batch'] = false;
	$GLOBALS['p_posts'] = array();
	$GLOBALS['k3_s3_log'] = array();
	$res = StoreCleanup::delete_names( $store, array( 'GS-B-7.pdf' ) );
	$GLOBALS['p_batch'] = true;
	p15( 'R15-P2 provider without DeleteObjects (501): single DELETE requests instead', array( array(), false, 1 ), array( $res['left'], $p_has( 'wpac/', 'GS-B-7.pdf' ), count( array_filter( $GLOBALS['k3_s3_log'], static function ( $l ) { return 'DELETE' === $l[0]; } ) ) ) );
	$p_put( 'wpac/', 'GS-B-8.pdf' );
	$res = StoreCleanup::delete_names( $store, array( 'GS-B-8.pdf', 'GS-B-9.pdf' ), microtime( true ) - 1 );
	p15( 'R15-P2 time budget used up: no request, every name returned', array( array( 'GS-B-8.pdf', 'GS-B-9.pdf' ), true, 'time budget used up' ), array( $res['left'], $p_has( 'wpac/', 'GS-B-8.pdf' ), $res['error'] ) );
	$GLOBALS['k3_s3_fail'] = 0;
	$GLOBALS['k3_s3_objects'] = array();

	// --- Uninstall with "delete all data": objects before DROP TABLE, remainder recorded. -------
	foreach ( array( 'GS-P-1', 'GS-P-2', 'GS-P-3' ) as $n ) {
		$wpdb->insert( Db::table( 'payouts' ), array( 'number' => $n, 'affiliate_id' => 1, 'period_end' => '2000-01-31', 'currency' => 'EUR', 'statement_file' => $n . '.pdf', 'statement_hash' => hash( 'sha256', $n ), 'created_at' => '2000-01-31 00:00:00' ) );
		$p_put( 'wpac/', $n . '.pdf' );
	}
	$p_put( 'wpac/', 'GS-foreign.pdf' );
	// R15-P7: main code choice and code request history go only with "delete all data".
	add_option( 'wpac_main_code_5', 12 );
	add_option( 'wpac_code_requests_5', array( array( 'code' => 'X', 'time' => time() ) ) );
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE' ) ) );
	$GLOBALS['p_posts'] = array();
	$GLOBALS['p_deny']  = array( 'GS-P-3.pdf' ); // The storage refuses one object.
	define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
	include WPAC_DIR . 'uninstall.php';
	$GLOBALS['p_deny'] = array();
	$left              = $p_opt( 'wpac_uninstall_store_left' );
	p15(
		'R15-P2 uninstall deletes the objects in one batch while the tables still exist, then drops the tables',
		array( 1, true, false, false, true, null ),
		array( count( $GLOBALS['p_posts'] ), $GLOBALS['p_posts'][0]['table'] ?? null, $p_has( 'wpac/', 'GS-P-1.pdf' ), $p_has( 'wpac/', 'GS-P-2.pdf' ), $p_has( 'wpac/', 'GS-foreign.pdf' ), $p_table( $wpdb->prefix . 'wpac_payouts' ) )
	);
	p15(
		'R15-P2 an object the storage refused stays recorded (store, name, error) after the tables are gone',
		array( 's3', array( 'GS-P-3.pdf' ), 1, 'S3 DeleteObjects: AccessDenied', true ),
		array( $left['store'] ?? '', $left['names'] ?? null, $left['failures'] ?? null, $left['error'] ?? '', $p_has( 'wpac/', 'GS-P-3.pdf' ) )
	);
	p15( 'R15-P7 "delete all data" removes the main code choice and the code request history', array(), $p_db_opts() );
	// A later run (tools/purge-retained.php or a new uninstall) deletes the rest.
	wpac_uninstall_retry_store_left();
	p15( 'R15-P2 the next run deletes the recorded object and forgets the record', array( false, null ), array( $p_has( 'wpac/', 'GS-P-3.pdf' ), $p_opt( 'wpac_uninstall_store_left' ) ) );
	// Time budget: nothing deleted in time → everything recorded; never deleted while a new
	// installation refers to the name again.
	$p_put( 'wpac/', 'GS-T-1.pdf' );
	$p_put( 'wpac/', 'GS-T-2.pdf' );
	add_filter( 'wpac_uninstall_store_budget', static function () { return 0.000001; } );
	usleep( 10 );
	$t_left = wpac_uninstall_store_delete( S3Store::from_constants(), array( 'GS-T-1.pdf', 'GS-T-2.pdf' ) );
	remove_all_filters( 'wpac_uninstall_store_budget' );
	p15( 'R15-P2 time budget of the uninstall (filter wpac_uninstall_store_budget): what is not reached is recorded', array( array( 'GS-T-1.pdf', 'GS-T-2.pdf' ), array( 'GS-T-1.pdf', 'GS-T-2.pdf' ), true ), array( $t_left, $p_opt( 'wpac_uninstall_store_left' )['names'] ?? null, $p_has( 'wpac/', 'GS-T-1.pdf' ) ) );
	Installer::install_schema();
	$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'GS-T-2', 'affiliate_id' => 1, 'period_end' => '2026-01-31', 'currency' => 'EUR', 'statement_file' => 'GS-T-2.pdf', 'statement_hash' => 'x', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$n = Installer::retry_uninstall_leftovers();
	p15( 'R15-P2 daily task of a new installation deletes the rest, but never a name its own payouts use again', array( 1, false, true, null, true ), array( $n, $p_has( 'wpac/', 'GS-T-1.pdf' ), $p_has( 'wpac/', 'GS-T-2.pdf' ), $p_opt( 'wpac_uninstall_store_left' ), isset( WPAffiliateCaptain\Core\Cron::daily_steps()['uninstall_leftovers'] ) ) );
}

if ( 'keep' === $p_mode ) {
	// R15-P7: "keep" (records within the retention period) keeps the main code choice and the
	// history of the code requests with the kept partner records.
	$wpdb->insert( Db::table( 'payouts' ), array( 'number' => 'GS-K-1', 'affiliate_id' => 1, 'period_end' => gmdate( 'Y-m-d' ), 'currency' => 'EUR', 'statement_file' => '', 'statement_hash' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	add_option( 'wpac_main_code_5', 12 );
	add_option( 'wpac_code_requests_5', array( array( 'code' => 'X', 'time' => time() ) ) );
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE' ) ) );
	define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
	include WPAC_DIR . 'uninstall.php';
	p15(
		'R15-P7 uninstall with kept records keeps the main code choice and the code request history',
		array( true, array( 'wpac_code_requests_5', 'wpac_main_code_5' ) ),
		array( (bool) $p_table( $wpdb->prefix . 'wpac_payouts' ), $p_db_opts() )
	);
}

if ( 'network' === $p_mode ) {
	$GLOBALS['test_multisite'] = 1;
	// --- R15-P3: kept credit notes of a deleted site after the retention period. ---------------
	$p_put( 'wpac/site-7/', 'GS-7-1.pdf' );
	$p_put( 'wpac/site-7/', 'GS-7-2.pdf' );
	$p_put( 'wpac/site-1/', 'GS-1-1.pdf' );
	$retained = array( 7 => array( 'years' => 10, 'hold' => 0, 'folder' => '', 'time' => time(), 'store' => 's3' ) );
	$GLOBALS['k3_s3_fail'] = 503; // Storage not available.
	$retained = Installer::purge_retained_documents( 7, $retained );
	$GLOBALS['k3_s3_fail'] = 0;
	update_site_option( Installer::RETAINED_SITES, $retained );
	$checks = WPAffiliateCaptain\Core\Monitor::checks();
	p15(
		'R15-P3 storage failure: the record of the deleted site stays (failures, last error), objects kept, Site Health critical',
		array( true, 1, 'S3 LIST: HTTP 503 InternalError', true, true, 'critical' ),
		array( isset( $retained[7] ), $retained[7]['failures'] ?? null, $retained[7]['last_error'] ?? '', $p_has( 'wpac/site-7/', 'GS-7-1.pdf' ), $p_has( 'wpac/site-7/', 'GS-7-2.pdf' ), $checks['retained_objects'][0] ?? '' )
	);
	$retained = Installer::purge_retained_documents( 7, $retained );
	p15( 'R15-P3 next attempt works: objects of the site deleted (others kept), record removed', array( false, false, false, true ), array( isset( $retained[7] ), $p_has( 'wpac/site-7/', 'GS-7-1.pdf' ), $p_has( 'wpac/site-7/', 'GS-7-2.pdf' ), $p_has( 'wpac/site-1/', 'GS-1-1.pdf' ) ) );

	// Store of the deleted site no longer configured (another id): kept, not forgotten.
	$retained = Installer::purge_retained_documents( 8, array( 8 => array( 'years' => 10, 'store' => 'mem' ) ) );
	p15( 'R15-P3 the remembered store is not the configured one: record kept with the reason', array( true, 'document store "mem" not configured' ), array( isset( $retained[8] ), $retained[8]['last_error'] ?? '' ) );

	// Custom store (filter wpac_statement_store): deleted through the abstraction, the filter runs
	// while switched to the deleted site.
	$GLOBALS['p_mem'] = array( 8 => array( 'GS-8-1.pdf' => 1, 'GS-8-2.pdf' => 1 ), 1 => array( 'GS-own.pdf' => 1 ) );
	$GLOBALS['p_mem_blog'] = array();
	add_filter(
		'wpac_statement_store',
		static function ( $default ) {
			$GLOBALS['p_mem_blog'][] = get_current_blog_id();
			return new class( get_current_blog_id() ) implements WPAffiliateCaptain\Payouts\Storage\StatementStore {
				private $blog;
				public function __construct( $blog ) { $this->blog = (int) $blog; }
				public function id() { return 'mem'; }
				public function label() { return 'memory'; }
				public function put( $name, $bytes ) { $GLOBALS['p_mem'][ $this->blog ][ $name ] = 1; return true; }
				public function get( $name ) { return isset( $GLOBALS['p_mem'][ $this->blog ][ $name ] ) ? 'x' : null; }
				public function exists( $name ) { return isset( $GLOBALS['p_mem'][ $this->blog ][ $name ] ); }
				public function delete( $name, $hash = '' ) { unset( $GLOBALS['p_mem'][ $this->blog ][ $name ] ); return true; }
				public function list_older_than( $timestamp ) { return array_keys( (array) ( $GLOBALS['p_mem'][ $this->blog ] ?? array() ) ); }
				public function last_error() { return ''; }
			};
		}
	);
	$retained = Installer::purge_retained_documents( 8, $retained );
	p15( 'R15-P3 custom store of the deleted site: its documents deleted through the store abstraction (switched to the site), record removed', array( false, array(), array( 'GS-own.pdf' => 1 ), array( 8 ) ), array( isset( $retained[8] ), $GLOBALS['p_mem'][8], $GLOBALS['p_mem'][1], array_values( array_unique( $GLOBALS['p_mem_blog'] ) ) ) );
	remove_all_filters( 'wpac_statement_store' );

	// The same rules in uninstall.php (the plugin is deleted; tools/purge-retained.php runs it again).
	$GLOBALS['p_site_options'] = array();
	define( 'WP_UNINSTALL_PLUGIN', 'affiliate-captain/affiliate-captain.php' );
	include WPAC_DIR . 'uninstall.php';
	$p_put( 'wpac/site-9/', 'GS-9-1.pdf' );
	$GLOBALS['k3_s3_fail'] = 500;
	$r = wpac_uninstall_purge_retained( 9, array( 9 => array( 'years' => 10, 'store' => 's3' ) ), 0 );
	$GLOBALS['k3_s3_fail'] = 0;
	$kept = $p_has( 'wpac/site-9/', 'GS-9-1.pdf' );
	$r2   = wpac_uninstall_purge_retained( 9, $r, 0 );
	p15( 'R15-P3 uninstall: record of a deleted site kept after a storage failure, removed after the deletion worked', array( true, 1, true, false, false ), array( isset( $r[9] ), $r[9]['failures'] ?? null, $kept, isset( $r2[9] ), $p_has( 'wpac/site-9/', 'GS-9-1.pdf' ) ) );
}

if ( 'coupon' === $p_mode ) {
	// --- R15-P6: the order is not marked after the consent was withdrawn. ---------------------
	class P15_Session {
		public $data = array();
		public function has_session() { return true; }
		public function get( $k, $d = null ) { return array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d; }
		public function set( $k, $v ) { $this->data[ $k ] = $v; }
	}
	class P15_Order {
		public $codes = array( 'anna10' );
		public $meta  = array();
		public function get_coupon_codes() { return $this->codes; }
		public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	}
	$GLOBALS['p_wc'] = (object) array( 'session' => new P15_Session(), 'cart' => null );
	function WC() { return $GLOBALS['p_wc']; }
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'cookie_mode' => 'consent', 'auto_apply_coupon' => 1 ) ) );
	WPAffiliateCaptain\Core\Settings::flush_cache();
	$anna = WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'Anna P15', 'email' => 'anna-p15@example.de', 'status' => 'active' ) );
	$code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $anna );
	$mark = static function ( $via_consent, $consent ) use ( $anna, $code ) {
		remove_all_filters( 'wpac_has_marketing_consent' );
		add_filter( 'wpac_has_marketing_consent', static function () use ( $consent ) { return $consent; } );
		$s       = new P15_Session();
		$s->data = array(
			WPAffiliateCaptain\Tracking\Tracker::SESSION_KEY => array( 'code' => $code, 'campaign' => '', 'content_id' => 0, 'ts' => time(), 'via_consent' => $via_consent ),
			WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::SESSION_KEY => array( 'coupon' => 'anna10', 'affiliate_id' => $anna, 'applied' => 1, 'removed' => 0 ),
		);
		$GLOBALS['p_wc']->session = $s;
		$o = new P15_Order();
		WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::mark_order( $o );
		return $o->meta[ WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::META_ORDER ] ?? null;
	};
	p15(
		'R15-P6 automatic coupon recorded on the order only while the referral is valid: not after the consent was withdrawn',
		array( array( 'coupon' => 'anna10', 'affiliate_id' => $anna ), null, array( 'coupon' => 'anna10', 'affiliate_id' => $anna ) ),
		array( $mark( 1, true ), $mark( 1, false ), $mark( 0, false ) )
	);
	// Without any referral in the session (expired) nothing is recorded either.
	remove_all_filters( 'wpac_has_marketing_consent' );
	$s       = new P15_Session();
	$s->data = array( WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::SESSION_KEY => array( 'coupon' => 'anna10', 'affiliate_id' => $anna, 'applied' => 1, 'removed' => 0 ) );
	$GLOBALS['p_wc']->session = $s;
	$o = new P15_Order();
	WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::mark_order( $o );
	p15( 'R15-P6 no valid referral in the session: order not marked', array(), $o->meta );
}

echo "\n" . json_encode( $p ) . "\n";
