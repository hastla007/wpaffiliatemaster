<?php
/**
 * Round 14, fix agent g1 – checks that change process-wide state: a stale coupon meta after a
 * reinstallation (R14-A1, arch/repro/coupon_stale_meta.php), the account link confirmed after an
 * e-mail change (R14-S1), the uninstall on a network with deleted sites run through
 * tools/purge-retained.php (R14-A1/A2/A3). Started by r14-g1.php in its own process with its own
 * table prefix; prints the results as JSON on the last line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;

$g1i = array();
function g1i( $name, $expected, $actual ) {
	global $g1i;
	$g1i[ $name ] = array( $expected, $actual );
}

// Stand-ins: WooCommerce coupons, nonces, current user.
function wp_verify_nonce( $n, $a ) { return 1; }
function wc_get_coupon_id_by_code( $code ) { return (int) ( $GLOBALS['test_wc_coupons'][ strtolower( trim( $code ) ) ] ?? 0 ); }
class WC_Coupon {
	public $id = 0;
	public $props = array();
	public function __construct( $id = 0 ) {
		if ( $id && isset( $GLOBALS['g1i_cd'][ (int) $id ] ) ) {
			$this->id    = (int) $id;
			$this->props = $GLOBALS['g1i_cd'][ (int) $id ];
		}
	}
	public function set_code( $c ) { $this->props['code'] = $c; }
	public function get_meta( $k ) { return $GLOBALS['g1i_cm'][ $this->id ][ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $GLOBALS['g1i_cm'][ $this->id ][ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $GLOBALS['g1i_cm'][ $this->id ][ $k ] ); }
	public function save_meta_data() {}
	public function get_id() { return $this->id; }
	public function get_code() { return $this->props['code'] ?? ''; }
	public function save() {
		static $s = 500;
		if ( ! $this->id ) {
			$this->id = ++$s;
		}
		foreach ( $GLOBALS['test_wc_coupons'] ?? array() as $c => $cid ) {
			if ( $cid === $this->id ) {
				unset( $GLOBALS['test_wc_coupons'][ $c ] ); // Renamed.
			}
		}
		$GLOBALS['test_wc_coupons'][ strtolower( $this->get_code() ) ] = $this->id;
		$GLOBALS['g1i_cd'][ $this->id ] = $this->props;
		return $this->id;
	}
}
class G1I_User {
	public $ID;
	public $user_login;
	public $user_email;
	public function __construct( $i, $l, $e ) { $this->ID = $i; $this->user_login = $l; $this->user_email = $e; }
	public function exists() { return (bool) $this->ID; }
}
function wp_get_current_user() { return get_userdata( $GLOBALS['test_user'] ) ?: new G1I_User( 0, '', '' ); }

// ---------------------------------------------------------------------------------------------
// R14-A1 (repro): a stale _wpac_code_id on an old coupon points at a new partner's code row.
$b    = AffiliateRepository::create( array( 'name' => 'Bea New', 'email' => 'bea-new@example.de', 'status' => 'active' ) );
$cb   = new WC_Coupon();
$cb->set_code( 'bxyz10' );
$cb->save();
$rowb = (int) CodeRepository::create( $b, 'BXYZ' );
CodeRepository::set_coupon( $rowb, 'bxyz10' );
CouponLink::remember( $rowb, 'bxyz10' );
$cs = new WC_Coupon();
$cs->set_code( 'summer10' );
$cs->save();
$GLOBALS['g1i_cm'][ $cs->get_id() ][ CouponLink::META_CODE_ID ] = $rowb;
$_POST = array( '_wpac_coupon_nonce' => 'x', 'wpac_coupon_affiliate' => '' );
CouponLink::coupon_save( $cs->get_id(), new WC_Coupon( $cs->get_id() ) );
$r = CodeRepository::find( $rowb );
g1i( 'R14-A1 saving an old coupon with a stale code ID leaves the other partner\'s code alone', array( 'active', 'bxyz10' ), array( $r->status, $r->coupon_code ) );
g1i( 'R14-A1 the stale code ID is removed from the old coupon', '', (string) ( $GLOBALS['g1i_cm'][ $cs->get_id() ][ CouponLink::META_CODE_ID ] ?? '' ) );
// A real rename still moves the link (the old text finds no coupon any more).
$ren = new WC_Coupon( $cb->get_id() );
$ren->set_code( 'bxyz20' );
$ren->save();
$_POST = array( '_wpac_coupon_nonce' => 'x', 'wpac_coupon_affiliate' => (string) $b );
CouponLink::coupon_save( $ren->get_id(), new WC_Coupon( $ren->get_id() ) );
$r = CodeRepository::find( $rowb );
g1i( 'R14-A1 a renamed coupon still takes its code row along', array( 'active', 'bxyz20' ), array( $r->status, $r->coupon_code ) );

// ---------------------------------------------------------------------------------------------
// R14-S1: the partner confirms the account link (link resent to the new address) shortly after an
// e-mail change: the previous address and the admin are told; the access change is remembered.
$GLOBALS['test_users'] = array(
	1  => new G1I_User( 1, 'admin', 'admin@example.de' ),
	60 => new G1I_User( 60, 'alias', 'alias@evil.test' ),
);
$lp = AffiliateRepository::create( array( 'name' => 'Link Partner', 'email' => 'owner@partner.test', 'status' => 'active' ) );
AffiliateRepository::unlink_user( $lp, 'user_deleted' ); // The account created on approval was deleted.
AffiliateRepository::set_email( $lp, 'alias@evil.test' );
update_option( 'wpac_user_link_' . $lp, array( 'hash' => wp_hash( 'tok' ), 'expires' => time() + 3600, 'sent' => time() ) );
$GLOBALS['test_user']  = 60;
$GLOBALS['test_mails'] = array();
$ok = AffiliateRepository::confirm_user_link( $lp, 'tok' );
$GLOBALS['test_user'] = 1;
g1i(
	'R14-S1 link confirmed after a recent e-mail change: previous address and admin informed',
	array( true, 2, 'alias@evil.test,owner@partner.test', true, true ),
	array(
		$ok,
		count( $GLOBALS['test_mails'] ),
		is_array( $GLOBALS['test_mails'][0][0] ?? null ) ? implode( ',', $GLOBALS['test_mails'][0][0] ) : '',
		false !== strpos( (string) ( $GLOBALS['test_mails'][1][1] ?? '' ), 'Partner account linked' ),
		AffiliateRepository::access_changed_recently( $lp ),
	)
);

// ---------------------------------------------------------------------------------------------
// Network stand-ins for the uninstall.
$GLOBALS['test_multisite']  = 1;
$GLOBALS['g1i_site_options'] = array();
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['g1i_site_options'] ) ? $GLOBALS['g1i_site_options'][ $k ] : $d; }
function update_site_option( $k, $v ) { $GLOBALS['g1i_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['g1i_site_options'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_site_option( '_t_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['g1i_site_options'][ '_t_' . $k ] = $v; return true; }
function delete_site_transient( $k ) { return delete_site_option( '_t_' . $k ); }
function get_site( $id ) { return in_array( (int) $id, array( 1, 10 ), true ) ? (object) array( 'blog_id' => (int) $id ) : null; }
function get_sites( $a = array() ) { return array( 1 ); }
function esc_sql( $s ) { return $GLOBALS['wpdb']->db->real_escape_string( (string) $s ); }
function switch_to_blog( $id ) { $GLOBALS['test_blog_id'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['test_blog_id'] = 1; return true; }
function delete_post_meta_by_key( $k ) { $GLOBALS['g1i_meta_keys'][] = $k; return true; }
function wp_roles() { return (object) array( 'role_objects' => array() ); }
function delete_metadata( $t, $id, $k, $v = '', $all = false ) { return true; }
function wp_trash_post( $id ) { return true; }
function is_plugin_active( $p ) { return false; }
function is_plugin_active_for_network( $p ) { return false; }
define( 'WP_CLI', true );
class WP_CLI {
	public static $out = array();
	public static function success( $m ) { self::$out[] = 'success: ' . $m; }
	public static function warning( $m ) { self::$out[] = 'warning: ' . $m; }
	public static function log( $m ) { self::$out[] = 'log: ' . $m; }
	public static function error( $m ) { self::$out[] = 'error: ' . $m; throw new RuntimeException( $m ); }
}

global $wpdb;
$pre = $wpdb->base_prefix;
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'delete_on_uninstall' => 1, 'statement_retention_years' => 10, 'operator_country' => 'DE' ) ) );

// R14-A1: order meta of the shop (posts table and HPOS) with a manual assignment by partner ID.
foreach ( array( $wpdb->postmeta, $pre . 'wc_orders_meta' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	$wpdb->query( "CREATE TABLE {$t} ( meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255), meta_value longtext )" );
	$wpdb->query( "INSERT INTO {$t} ( post_id, meta_key, meta_value ) VALUES ( 9, '_wpac_manual_affiliate', '5' ), ( 9, '_wpac_manual_own', '1' ), ( 9, '_wpac_ref_snapshot', 'x' )" );
}

// R14-A3: Action Scheduler tables while WooCommerce is not loaded.
$wpdb->query( "DROP TABLE IF EXISTS {$pre}actionscheduler_actions" );
$wpdb->query( "DROP TABLE IF EXISTS {$pre}actionscheduler_groups" );
$wpdb->query( "CREATE TABLE {$pre}actionscheduler_groups ( group_id bigint unsigned NOT NULL PRIMARY KEY, slug varchar(255) )" );
$wpdb->query( "CREATE TABLE {$pre}actionscheduler_actions ( action_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, hook varchar(191), status varchar(20), group_id bigint unsigned NOT NULL DEFAULT 0 )" );
$wpdb->query( "INSERT INTO {$pre}actionscheduler_groups VALUES ( 1, 'wp-affiliate-captain' ), ( 2, 'woocommerce' )" );
$wpdb->query( "INSERT INTO {$pre}actionscheduler_actions ( hook, status, group_id ) VALUES ( 'wpac_vies_check', 'pending', 1 ), ( 'wpac_daily', 'complete', 1 ), ( 'woocommerce_cleanup', 'pending', 2 )" );

// R14-A2: deleted sites. 7: retention over (tables and kept folder go); 8: a credit note within the
// period (booking tables and entry stay); 9: tables already gone, kept folder left; 10: a live site.
$ret = sys_get_temp_dir() . '/wpac-r14g1-retained';
foreach ( array( 7, 9 ) as $s ) {
	@mkdir( $ret . '/site-' . $s, 0777, true );
	file_put_contents( $ret . '/site-' . $s . '/GS-old.pdf', 'pdf' );
}
foreach ( array( 7, 8 ) as $s ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$pre}{$s}_wpac_payouts" );
	$wpdb->query( "CREATE TABLE {$pre}{$s}_wpac_payouts ( id int, created_at datetime, storno_at datetime NULL )" );
	foreach ( array( 'affiliates', 'partner_codes', 'visits' ) as $n ) {
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}{$s}_wpac_{$n} ( id int )" );
	}
}
$wpdb->query( "INSERT INTO {$pre}7_wpac_payouts VALUES ( 1, '2001-01-31 00:00:00', NULL )" );
$wpdb->query( "INSERT INTO {$pre}8_wpac_payouts VALUES ( 1, '" . gmdate( 'Y-m-d H:i:s' ) . "', NULL )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}10_wpac_affiliates ( id int )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$pre}10_options ( id int )" );
$GLOBALS['g1i_site_options']['wpac_retained_sites'] = array(
	7 => array( 'years' => 10, 'hold' => 0, 'folder' => $ret . '/site-7', 'time' => 1 ),
	8 => array( 'years' => 10, 'hold' => 0, 'folder' => $ret . '/site-8', 'time' => 1 ),
	9 => array( 'years' => 10, 'hold' => 0, 'folder' => $ret . '/site-9', 'time' => 1 ),
);
delete_option( 'wpac_retained_after_uninstall' ); // The main site kept nothing: earlier "No retained data".
$wpdb->query( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( 'wpac_bank_pending_3', 'a:0:{}', 'off' ), ( 'wpac_access_changed_3', '1', 'off' )" );

try {
	include WPAC_DIR . 'tools/purge-retained.php';
} catch ( RuntimeException $e ) {
	WP_CLI::$out[] = 'exception: ' . $e->getMessage();
}
$tbl = static function ( $t ) use ( $wpdb ) {
	return (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . addcslashes( $t, '_%' ) . "'" );
};
g1i( 'R14-A2 purge tool runs for records of deleted sites (main site without the flag)', true, (bool) preg_grep( '/deleted sites/', WP_CLI::$out ) );
g1i( 'R14-A2 purge tool reports what is still within the period', true, (bool) preg_grep( '/^warning: /', WP_CLI::$out ) );
g1i(
	'R14-A2 deleted site past its period: tables and kept credit notes deleted',
	array( false, false, false, false ),
	array( $tbl( $pre . '7_wpac_payouts' ), $tbl( $pre . '7_wpac_affiliates' ), $tbl( $pre . '7_wpac_visits' ), is_dir( $ret . '/site-7' ) )
);
g1i(
	'R14-A2 deleted site within its period: booking tables and entry kept, other tables dropped',
	array( true, true, false, false, array( 8 ) ),
	array( $tbl( $pre . '8_wpac_payouts' ), $tbl( $pre . '8_wpac_affiliates' ), $tbl( $pre . '8_wpac_partner_codes' ), $tbl( $pre . '8_wpac_visits' ), array_keys( (array) get_site_option( 'wpac_retained_sites', array() ) ) )
);
g1i( 'R14-A2 kept folder of a deleted site without tables removed; live site untouched', array( false, true ), array( is_dir( $ret . '/site-9' ), $tbl( $pre . '10_wpac_affiliates' ) ) );
g1i(
	'R14-A1 manual assignments by partner ID removed from posts and HPOS order meta, other order meta kept',
	array( 1, 1 ),
	array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wpac\\_%'" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pre}wc_orders_meta WHERE meta_key LIKE '\\_wpac\\_%'" ) )
);
g1i( 'R14-A1/A3 coupon code IDs and application form groups removed', array( true, true ), array( in_array( '_wpac_code_id', (array) ( $GLOBALS['g1i_meta_keys'] ?? array() ), true ), in_array( '_wpac_form_groups', (array) ( $GLOBALS['g1i_meta_keys'] ?? array() ), true ) ) );
g1i(
	'R14-A3 pending Action Scheduler tasks of the plugin removed without WooCommerce',
	array( 'wpac_daily', 'woocommerce_cleanup' ),
	$wpdb->get_col( "SELECT hook FROM {$pre}actionscheduler_actions ORDER BY action_id" )
);
g1i( 'R14-S1 uninstall removes held bank changes and access marks', array( false, false ), array( (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_bank\\_pending\\_%'" ), (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_access\\_changed\\_%'" ) ) );

// Clean up.
foreach ( array( '8_wpac_payouts', '8_wpac_affiliates', '10_wpac_affiliates', '10_options', 'wc_orders_meta', 'actionscheduler_actions', 'actionscheduler_groups' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$pre}{$t}" );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->postmeta}" );
foreach ( (array) glob( $ret . '/*/*' ) as $f ) {
	@unlink( $f );
}
foreach ( (array) glob( $ret . '/*' ) as $f ) {
	@rmdir( $f );
}

echo "\n" . json_encode( $g1i ) . "\n";
