<?php
/**
 * Integration checks "R10 J3": referral carried in same-site links until consent (R6-CC2 rest).
 * Included from tests/integration/run.php (uses its check() helper, the database and the
 * stand-ins of r8-g1.php: G1_Session, $GLOBALS['g1_wc'], WC()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Rest\RestApi;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R10 J3 referral carried until consent\n";

global $wpdb;
$j3_settings = get_option( 'wpac_settings' );
$j3_set      = static function ( array $s ) use ( $j3_settings ) {
	update_option( 'wpac_settings', array_merge( $j3_settings, $s ) );
	Settings::flush_cache();
};
$j3_server = $_SERVER;
$j3_visits = static function ( $aff ) use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'visits' ) . ' WHERE affiliate_id = ' . (int) $aff ); };
$j3_cookie = static function ( $since ) {
	$w = array_slice( Tracker::written_cookies(), $since );
	return $w ? end( $w ) : null;
};
$j3_flag   = static function ( $prop, $value ) {
	$r = new ReflectionProperty( Tracker::class, $prop );
	$r->setAccessible( true );
	$r->setValue( null, $value );
};
$j3_reset  = static function () use ( $j3_flag ) {
	$GLOBALS['g1_wc']->session      = new G1_Session();
	$GLOBALS['g1_wc']->session->has = false;
	unset( $_COOKIE[ Tracker::cookie_name() ], $_GET['partner'], $_GET[ Tracker::CARRY_PARAM ], $_GET['utm_campaign'], $_GET['sub'] );
	$GLOBALS['test_consent_type']   = 'optin';
	$GLOBALS['test_consent_cookie'] = 'deny';
	$_SERVER['REMOTE_ADDR']         = '203.0.113.' . wp_rand( 100, 250 );
	$_SERVER['HTTP_USER_AGENT']     = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0';
	$_SERVER['REQUEST_METHOD']      = 'GET';
	unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_PURPOSE'] );
	$j3_flag( 'carried_now', false );
	$j3_flag( 'carry_token', '' );
};
$j3_consent = static function ( $given ) {
	$GLOBALS['test_consent_type']   = 'optin';
	$GLOBALS['test_consent_cookie'] = $given ? 'allow' : 'deny';
};
$j3_test_user          = $GLOBALS['test_user'];
$GLOBALS['test_user'] = 0;

$j3a  = AffiliateRepository::create( array( 'name' => 'Jana Drei', 'email' => 'j3-a@example.de', 'status' => 'active' ) );
$j3b  = AffiliateRepository::create( array( 'name' => 'Jens Vier', 'email' => 'j3-b@example.de', 'status' => 'active' ) );
$j3ca = CodeRepository::main_code( $j3a );
$j3cb = CodeRepository::main_code( $j3b );
$j3_set( array( 'cookie_mode' => 'consent', 'js_tracking' => 0, 'touch_model' => 'last', 'use_wc_session' => 1, 'cookie_domain' => '', 'visit_log_percent' => 100, 'carry_referral_links' => 1 ) );

/* Token -------------------------------------------------------------------------------------------- */
$j3_now = time();
$j3_tok = Tracker::carry_token( $j3ca, 'sommer', 'ig-1', $j3_now );
$j3_p   = Tracker::parse_carry( $j3_tok );
check( 'R6-CC2 carry token: URL-safe, signed, keeps code, campaign, sub-ID and click time', array( 1, $j3ca, 'sommer', 'ig-1', $j3_now ), array( preg_match( '/^[A-Za-z0-9_\-]+\.[0-9a-f]{16}$/', $j3_tok ), $j3_p['code'] ?? null, $j3_p['campaign'] ?? null, $j3_p['sub_id'] ?? null, $j3_p['ts'] ?? null ) );
check( 'R6-CC2 carry token valid for 30 minutes, not before/after (no extension)', array( true, false, false ), array( null !== Tracker::parse_carry( $j3_tok, $j3_now + 1799 ), null !== Tracker::parse_carry( $j3_tok, $j3_now + 1801 ), null !== Tracker::parse_carry( Tracker::carry_token( $j3ca, '', '', $j3_now + 600 ), $j3_now ) ) );
list( $j3_pl, $j3_sig ) = explode( '.', $j3_tok );
$j3_forged = rtrim( strtr( base64_encode( json_encode( array( $j3cb, 'sommer', 'ig-1', $j3_now ) ) ), '+/', '-_' ), '=' ) . '.' . $j3_sig;
$j3_later  = rtrim( strtr( base64_encode( json_encode( array( $j3ca, 'sommer', 'ig-1', $j3_now + 3600 ) ) ), '+/', '-_' ), '=' ) . '.' . $j3_sig;
check( 'R6-CC2 tampered carry signature rejected (other code, later click time, changed signature, cookie value)', array( null, null, null, null, null ), array( Tracker::parse_carry( $j3_forged ), Tracker::parse_carry( $j3_later, $j3_now + 3600 ), Tracker::parse_carry( $j3_pl . '.' . strrev( $j3_sig ) ), Tracker::parse_carry( Tracker::cookie_value( array( 'code' => $j3ca, 'campaign' => '', 'content_id' => 0, 'ts' => $j3_now ) ) ), Tracker::parse_cookie( $j3_tok ) ) );
add_filter( 'wpac_carry_referral_window', static function () { return 120; } );
check( 'R6-CC2 window filter wpac_carry_referral_window', array( 120, true, false ), array( Tracker::carry_window(), null !== Tracker::parse_carry( $j3_tok, $j3_now + 119 ), null !== Tracker::parse_carry( $j3_tok, $j3_now + 121 ) ) );
remove_all_filters( 'wpac_carry_referral_window' );

/* Switches ----------------------------------------------------------------------------------------- */
check( 'R6-CC2 carrying on by default in cookie mode "consent", only there', array( 1, true, false, false ), array(
	Settings::defaults()['carry_referral_links'],
	Tracker::carry_enabled(),
	( function () use ( $j3_set ) { $j3_set( array( 'cookie_mode' => 'always' ) ); $r = Tracker::carry_enabled(); $j3_set( array( 'cookie_mode' => 'consent' ) ); return $r; } )(),
	( function () use ( $j3_set ) { $j3_set( array( 'carry_referral_links' => 0 ) ); $r = Tracker::carry_enabled(); $j3_set( array( 'carry_referral_links' => 1 ) ); return $r; } )(),
) );
add_filter( 'wpac_carry_referral_links', static function () { return false; } );
check( 'R6-CC2 filter wpac_carry_referral_links switches carrying off', false, Tracker::carry_enabled() );
remove_all_filters( 'wpac_carry_referral_links' );
check( 'R6-CC2 consent listener needed on a landing or a carried page without consent', array( true, true, false, false, false ), array( Tracker::listener_needed( true, true, false, false ), Tracker::listener_needed( true, false, true, false ), Tracker::listener_needed( true, false, true, true ), Tracker::listener_needed( false, false, true, false ), Tracker::listener_needed( true, false, false, false ) ) );

/* Landing page without consent issues the token --------------------------------------------------- */
$j3_reset();
$_GET['partner']      = $j3ca;
$_GET['utm_campaign'] = 'herbst';
$j3_before            = $j3_visits( $j3a );
$j3_w                 = count( Tracker::written_cookies() );
Tracker::capture();
$j3_land = Tracker::issued_carry();
check( 'R6-CC2 landing without consent: visit counted, nothing stored, links get a carry token', array( $j3_before + 1, null, null, $j3ca, 'herbst' ), array( $j3_visits( $j3a ), $j3_cookie( $j3_w ), $GLOBALS['g1_wc']->session->get( 'wpac_ref' ), Tracker::parse_carry( $j3_land )['code'] ?? null, Tracker::parse_carry( $j3_land )['campaign'] ?? null ) );
check( 'R6-CC2 carry token in the per-page data of the landing (never in the static script)', array( $j3_land, false ), array( Tracker::beacon_data( 0, false, time(), $j3_land )['carry'] ?? null, array_key_exists( 'carry', Tracker::beacon_data( 0, false, time() ) ) ) );
$j3_reset();
$j3_consent( true );
$_GET['partner'] = $j3ca;
Tracker::capture();
check( 'R6-CC2 landing with consent: no carry token (the cookie is set right away)', '', Tracker::issued_carry() );

/* Later page: carried parameter recognised without a new visit ------------------------------------ */
$j3_reset();
$_GET[ Tracker::CARRY_PARAM ] = $j3_land;
$j3_before                    = $j3_visits( $j3a );
$j3_w                         = count( Tracker::written_cookies() );
Tracker::capture();
check( 'R6-CC2 server recognises carry param without new visit (no consent: nothing stored, waiting script again, no cache)', array( $j3_before, null, null, true, true ), array( $j3_visits( $j3a ), $j3_cookie( $j3_w ), $GLOBALS['g1_wc']->session->get( 'wpac_ref' ), Tracker::carried_now(), defined( 'DONOTCACHEPAGE' ) ) );

$j3_reset();
$j3_consent( true );
$_GET[ Tracker::CARRY_PARAM ] = $j3_land;
$j3_w                         = count( Tracker::written_cookies() );
Tracker::capture();
$j3_c = $j3_cookie( $j3_w );
check( 'R6-CC2 later page with consent by now: cookie with the original code and click time, no new visit', array( $j3ca, 'herbst', Tracker::parse_carry( $j3_land )['ts'], $j3_before, false ), array( $j3_c ? Tracker::parse_cookie( $j3_c[1] )['code'] : null, $j3_c ? Tracker::parse_cookie( $j3_c[1] )['campaign'] : null, $j3_c ? Tracker::parse_cookie( $j3_c[1] )['ts'] : null, $j3_visits( $j3a ), Tracker::carried_now() ) );

$j3_reset();
$j3_consent( true );
$_SERVER['HTTP_SEC_PURPOSE']  = 'prefetch';
$_GET[ Tracker::CARRY_PARAM ] = $j3_land;
$j3_w                         = count( Tracker::written_cookies() );
Tracker::capture();
check( 'R6-CC2 prefetch of a carried link stores and counts nothing', array( null, $j3_before ), array( $j3_cookie( $j3_w ), $j3_visits( $j3a ) ) );

/* Expired / tampered on the page request --------------------------------------------------------- */
$j3_old = Tracker::carry_token( $j3ca, '', '', time() - 1801 );
foreach ( array( false, true ) as $j3_with ) {
	$j3_reset();
	$j3_consent( $j3_with );
	$_GET[ Tracker::CARRY_PARAM ] = $j3_old;
	$j3_w                         = count( Tracker::written_cookies() );
	Tracker::capture();
	$j3_exp[] = array( Tracker::carried_now(), $j3_cookie( $j3_w ), $j3_visits( $j3a ) );
}
check( 'R6-CC2 expired carry ignored (no waiting script, no cookie even with consent, no visit)', array( array( false, null, $j3_before ), array( false, null, $j3_before ) ), $j3_exp );
$j3_reset();
$j3_consent( true );
$j3_forged_now                = rtrim( strtr( base64_encode( json_encode( array( $j3cb, '', '', time() ) ) ), '+/', '-_' ), '=' ) . '.' . explode( '.', $j3_land )[1];
$_GET[ Tracker::CARRY_PARAM ] = $j3_forged_now;
$j3_w                         = count( Tracker::written_cookies() );
Tracker::capture();
check( 'R6-CC2 tampered carry signature rejected on the page request (no referral for the other code)', array( false, null ), array( Tracker::carried_now(), $j3_cookie( $j3_w ) ) );

/* /touch with consent after carry --------------------------------------------------------------- */
$j3_reset();
$j3_consent( true );
$j3_bv = $j3_visits( $j3a );
$j3_w  = count( Tracker::written_cookies() );
$j3_r  = RestApi::touch( new WP_REST_Request( array( 'code' => $j3ca, 'carry' => $j3_land, 'consent' => true ), array( 'Origin' => 'http://example.test' ) ) );
$j3_c  = $j3_cookie( $j3_w );
check( 'R6-CC2 /touch with consent after carry sets cookie and attribution equals original code', array( true, $j3ca, $j3ca, 'cookie', 'herbst', $j3_bv ), array( $j3_r instanceof WP_REST_Response, $j3_c ? Tracker::parse_cookie( $j3_c[1] )['code'] : null, Tracker::current_touch()['code'] ?? null, Tracker::current_touch()['method'] ?? null, Tracker::current_touch()['campaign'] ?? null, $j3_visits( $j3a ) ) );
$j3_reset();
$j3_consent( true );
$j3_w = count( Tracker::written_cookies() );
RestApi::touch( new WP_REST_Request( array( 'code' => $j3cb, 'carry' => $j3_forged_now, 'consent' => true ), array( 'Origin' => 'http://example.test' ) ) );
RestApi::touch( new WP_REST_Request( array( 'code' => $j3ca, 'carry' => $j3_old, 'consent' => true ), array( 'Origin' => 'http://example.test' ) ) );
check( 'R6-CC2 /touch ignores a tampered or expired carry (no cookie, the sent code is not used instead)', array( null, $j3_bv, 0 ), array( $j3_cookie( $j3_w ), $j3_visits( $j3a ), $j3_visits( $j3b ) ) );
$j3_reset();
$j3_w = count( Tracker::written_cookies() );
RestApi::touch( new WP_REST_Request( array( 'code' => $j3ca, 'carry' => $j3_land ), array( 'Origin' => 'http://example.test' ) ) );
check( 'R6-CC2 /touch with carry but still without consent: nothing stored, no visit', array( null, $j3_bv ), array( $j3_cookie( $j3_w ), $j3_visits( $j3a ) ) );

/* Beacon (full-page cache): the answer carries the token ------------------------------------------ */
$j3_set( array( 'js_tracking' => 1 ) );
$j3_reset();
$j3_r1 = RestApi::touch( new WP_REST_Request( array( 'code' => $j3ca, 'campaign' => 'herbst' ), array( 'Origin' => 'http://example.test' ) ) );
$j3_r2 = RestApi::touch( new WP_REST_Request( array( 'code' => 'J3-GIBT-ES-NICHT' ), array( 'Origin' => 'http://example.test' ) ) );
$j3_consent( true );
$j3_r3 = RestApi::touch( new WP_REST_Request( array( 'code' => $j3ca ), array( 'Origin' => 'http://example.test' ) ) );
check( 'R6-CC2 beacon answer without consent carries a token (also for unknown codes: no enumeration), none with consent, never cached', array( $j3ca, true, false, 'no-store' ), array( Tracker::parse_carry( $j3_r1->data['carry'] ?? '' )['code'] ?? null, isset( $j3_r2->data['carry'] ), isset( $j3_r3->data['carry'] ), $j3_r1->headers['Cache-Control'] ?? null ) );
$j3_script = Tracker::beacon_script( array( 'url' => 'u', 'param' => 'partner', 'ref' => 0, 'beacon' => 1, 'consent' => 1, 'carry' => Tracker::CARRY_PARAM, 'window' => 1800 ) );
check( 'R6-CC2 beacon hands the token of its answer to the carry script (event wpac_carry)', true, false !== strpos( $j3_script, 'wpac_carry' ) );
$j3_set( array( 'js_tracking' => 0 ) );

/* Add to cart on a carried page ------------------------------------------------------------------- */
$j3_reset();
$j3_bv                   = $j3_visits( $j3a );
$_SERVER['HTTP_REFERER'] = 'http://example.test/shop/?' . Tracker::CARRY_PARAM . '=' . rawurlencode( $j3_land );
Tracker::capture_on_add_to_cart();
check( 'R6-CC2 add to cart from a carried page keeps the referral in the shop session (no visit)', array( $j3ca, 'herbst', $j3_bv ), array( $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['campaign'] ?? null, $j3_visits( $j3a ) ) );
$j3_reset();
$_SERVER['HTTP_REFERER'] = 'http://example.test/shop/?' . Tracker::CARRY_PARAM . '=' . rawurlencode( $j3_old );
Tracker::capture_on_add_to_cart();
check( 'R6-CC2 add to cart with an expired carry keeps nothing', null, $GLOBALS['g1_wc']->session->get( 'wpac_ref' ) );

/* Script (static checks) ------------------------------------------------------------------------- */
$j3_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/carry.js' );
check( 'R6-CC2 carry script: no browser storage, skips external/admin/login/logout/nonce/download links, opt-out attribute, GET forms only', array( 0, true, true, true, true, true, true ), array(
	preg_match( '/localStorage|sessionStorage|indexedDB|document\.cookie/', $j3_js ),
	false !== strpos( $j3_js, 'u.origin !== window.location.origin' ),
	false !== strpos( $j3_js, 'wp-admin' ) && false !== strpos( $j3_js, 'wp-login' ) && false !== strpos( $j3_js, 'logout' ),
	false !== strpos( $j3_js, 'nonce' ),
	false !== strpos( $j3_js, "hasAttribute( 'download' )" ),
	false !== strpos( $j3_js, 'data-wpac-no-carry' ),
	false !== strpos( $j3_js, "'get' !== String( f.getAttribute( 'method' )" ),
) );
check( 'R6-CC2 setting has a label and sits on the tracking tab', array( true, true ), array( isset( WPAffiliateCaptain\Admin\Pages\SettingsPage::setting_labels()['carry_referral_links'] ), in_array( 'carry_referral_links', WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['tracking'], true ) ) );

// Clean up.
$j3_reset();
$GLOBALS['test_consent_type']   = '';
$GLOBALS['test_consent_cookie'] = '';
$GLOBALS['test_user']           = $j3_test_user;
$_SERVER                        = $j3_server;
update_option( 'wpac_settings', $j3_settings );
Settings::flush_cache();
