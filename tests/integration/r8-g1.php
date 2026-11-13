<?php
/**
 * Integration checks "R8 G1": tracking, consent, cookies, caching (round 8, work package G1).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Rest\RestApi;
use WPAffiliateCaptain\Tracking\RateLimiter;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R8 G1 tracking, consent, cookies, caching\n";

// Minimal stand-ins (only what these checks need).
class G1_Session {
	public $data    = array();
	public $has     = false;
	public $created = 0;
	public function has_session() { return $this->has; }
	public function get( $k, $d = null ) { return array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d; }
	public function set( $k, $v ) { $this->data[ $k ] = $v; }
	public function set_customer_session_cookie( $s ) { $this->has = true; ++$this->created; }
}
class G1_WC {
	public $session;
}
class G1_Order {
	private $status;
	public function __construct( $status ) { $this->status = $status; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
}
$GLOBALS['g1_wc'] = new G1_WC();
if ( ! function_exists( 'WC' ) ) { function WC() { return $GLOBALS['g1_wc']; } }
if ( ! function_exists( 'wc_load_cart' ) ) { function wc_load_cart() { ++$GLOBALS['g1_cart_loads']; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
if ( ! function_exists( 'is_singular' ) ) { function is_singular() { return false; } }
if ( ! function_exists( 'get_queried_object_id' ) ) { function get_queried_object_id() { return 0; } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'status_header' ) ) { function status_header( $c ) {} }
if ( ! function_exists( 'url_to_postid' ) ) { function url_to_postid( $u ) { return 0; } }
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
}
if ( ! function_exists( 'shortcode_atts' ) ) { function shortcode_atts( $d, $a, $s = '' ) { return array_merge( $d, array_intersect_key( $a, $d ) ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style() {} }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://example.test/wp-json/' . $p; } }
$GLOBALS['g1_cart_loads'] = 0;

global $wpdb;
$g1_settings = get_option( 'wpac_settings' );
$g1_set      = static function ( array $s ) use ( $g1_settings ) {
	update_option( 'wpac_settings', array_merge( $g1_settings, $s ) );
	Settings::flush_cache();
};
$g1_server   = $_SERVER;
$g1_visits   = static function ( $aff ) use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'visits' ) . ' WHERE affiliate_id = ' . (int) $aff ); };
$g1_cookie   = static function () {
	$w = Tracker::written_cookies();
	return $w ? end( $w ) : null;
};
$g1_reset    = static function ( $has_session = false ) {
	$GLOBALS['g1_wc']->session      = new G1_Session();
	$GLOBALS['g1_wc']->session->has = $has_session;
	unset( $_COOKIE[ Tracker::cookie_name() ] );
	$GLOBALS['test_consent_type']   = '';
	$GLOBALS['test_consent_cookie'] = '';
};
$g1_browser  = static function ( $ip, $ua = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0' ) {
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	$_SERVER['REQUEST_METHOD']  = 'GET';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_REFERER'] );
};
$g1_consent  = static function ( $given ) {
	$GLOBALS['test_consent_type']   = 'optin';
	$GLOBALS['test_consent_cookie'] = $given ? 'allow' : 'deny';
};

$g1a   = AffiliateRepository::create( array( 'name' => 'Gina Eins', 'email' => 'g1-a@example.de', 'status' => 'active' ) );
$g1b   = AffiliateRepository::create( array( 'name' => 'Gerd Zwei', 'email' => 'g1-b@example.de', 'status' => 'active' ) );
$g1ca  = CodeRepository::main_code( $g1a );
$g1cb  = CodeRepository::main_code( $g1b );
$g1_set( array( 'cookie_mode' => 'consent', 'js_tracking' => 0, 'touch_model' => 'last', 'use_wc_session' => 1, 'cookie_domain' => '', 'visit_log_percent' => 100 ) );

/* R6-CC14: with the beacon, a guest's page request stays cacheable and writes nothing ---------- */
$g1_reset();
$g1_browser( '203.0.113.30' );
$g1_set( array( 'cookie_mode' => 'consent', 'js_tracking' => 1 ) );
$_GET['partner'] = $g1ca;
$g1_before       = $g1_visits( $g1a );
$g1_dnc          = defined( 'DONOTCACHEPAGE' );
$g1_written      = count( Tracker::written_cookies() );
$g1_test_user    = $GLOBALS['test_user'];
$GLOBALS['test_user'] = 0;
Tracker::capture();
check( 'R6-CC14 beacon on: page request of a guest left to the beacon (cacheable, no visit/cookie)', array( $g1_dnc, $g1_before, $g1_written ), array( defined( 'DONOTCACHEPAGE' ), $g1_visits( $g1a ), count( Tracker::written_cookies() ) ) );
unset( $_GET['partner'] );
$g1_set( array( 'js_tracking' => 0 ) );

/* R6-CC2: consent after the landing ------------------------------------------------------------- */
$g1_reset();
$g1_browser( '203.0.113.31' );
$g1_before = $g1_visits( $g1a );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R6-CC2 landing without consent and without cart: nothing stored, visit counted', array( null, null, $g1_before + 1 ), array( $GLOBALS['g1_wc']->session->get( 'wpac_ref' ), $g1_cookie(), $g1_visits( $g1a ) ) );
check( 'R6-CC2 beacon endpoint without JS tracking only for consent re-sends', 'wpac_disabled', ( $e = RestApi::touch( new WP_REST_Request( array( 'code' => $g1ca ) ) ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
$g1_consent( true );
$g1_res = RestApi::touch( new WP_REST_Request( array( 'code' => $g1ca, 'consent' => true ), array( 'Origin' => 'http://example.test' ) ) );
$g1_c   = $g1_cookie();
check( 'R6-CC2 consent given later: the re-send sets the signed cookie, no second visit', array( true, $g1ca, $g1_before + 1 ), array( $g1_res instanceof WP_REST_Response, $g1_c ? Tracker::parse_cookie( $g1_c[1] )['code'] : null, $g1_visits( $g1a ) ) );
check( 'R6-CC2 checkout sees the referral from the cookie', array( $g1ca, 'cookie' ), array( Tracker::current_touch()['code'] ?? null, Tracker::current_touch()['method'] ?? null ) );
$g1_script = Tracker::beacon_script( array( 'url' => 'u', 'param' => 'partner', 'ref' => 0, 'beacon' => 0, 'consent' => 1 ) );
check( 'R6-CC2 beacon script listens for the WP Consent API change and offers wpacConsentGranted()', array( true, true ), array( false !== strpos( $g1_script, 'wp_listen_for_consent_change' ), false !== strpos( $g1_script, 'window.wpacConsentGranted' ) ) );
// Add-to-cart fallback (product page reached from the landing page) also sets the cookie with consent.
$g1_reset();
$g1_browser( '203.0.113.32' );
$_SERVER['HTTP_REFERER'] = 'http://example.test/kurse/?partner=' . $g1cb . '&utm_campaign=sommer';
$g1_consent( true );
Tracker::capture_on_add_to_cart();
$g1_c = $g1_cookie();
check( 'R6-CC2/R7-TR9 cart fallback stores session and cookie (with consent)', array( $g1cb, 'sommer', $g1cb ), array( $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['campaign'] ?? null, $g1_c ? Tracker::parse_cookie( $g1_c[1] )['code'] : null ) );

/* R6-CC8: consent alone never creates a shop session --------------------------------------------- */
$g1_reset();
$g1_browser( '203.0.113.33' );
$g1_consent( true );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R6-CC8 click with consent: cookie only, no WooCommerce session created', array( 0, null, $g1ca ), array( $GLOBALS['g1_wc']->session->created, $GLOBALS['g1_wc']->session->get( 'wpac_ref' ), Tracker::parse_cookie( $g1_cookie()[1] )['code'] ?? null ) );

/* R7-TR9: ?add-to-cart=…&partner=X is captured completely ---------------------------------------- */
$g1_reset();
$g1_browser( '203.0.113.34' );
$g1_consent( true );
$_GET['partner'] = $g1cb;
$g1_before       = $g1_visits( $g1b );
Tracker::capture_on_add_to_cart();
unset( $_GET['partner'] );
check( 'R7-TR9 add-to-cart link: visit, session (cart) and cookie', array( $g1_before + 1, $g1cb, $g1cb ), array( $g1_visits( $g1b ), $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null, Tracker::parse_cookie( $g1_cookie()[1] )['code'] ?? null ) );

/* R7-TR1: the session touch survives a failed payment -------------------------------------------- */
$GLOBALS['za_orders'][88001] = new G1_Order( 'failed' );
$GLOBALS['za_orders'][88002] = new G1_Order( 'processing' );
Tracker::clear_touch_for_order( 88001 );
$g1_kept = $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null;
Tracker::clear_touch_for_order( 88002 );
check( 'R7-TR1 touch kept after a failed payment, cleared once paid', array( $g1cb, null ), array( $g1_kept, $GLOBALS['g1_wc']->session->get( 'wpac_ref' ) ) );
check( 'R7-TR1 not cleared when the order is placed (only after payment / thank-you page)', array( false, false, true, true ), array( isset( $GLOBALS['test_hooks']['woocommerce_checkout_order_processed'] ), isset( $GLOBALS['test_hooks']['woocommerce_store_api_checkout_order_processed'] ), ( WPAffiliateCaptain\Integrations\WooCommerce::register_hooks() || true ) && isset( $GLOBALS['test_hooks']['woocommerce_payment_complete'] ), isset( $GLOBALS['test_hooks']['woocommerce_thankyou'] ) ) );

/* R7-TR2: first touch replaces an invalid earlier touch ------------------------------------------ */
$g1_set( array( 'touch_model' => 'first' ) );
$g1_c3  = AffiliateRepository::create( array( 'name' => 'Greta Drei', 'email' => 'g1-c@example.de', 'status' => 'active' ) );
$g1cc   = CodeRepository::main_code( $g1_c3 );
$g1_reset( true );
$g1_browser( '203.0.113.35' );
$GLOBALS['g1_wc']->session->set( 'wpac_ref', array( 'code' => $g1cc, 'campaign' => '', 'content_id' => 0, 'ts' => time() - 60 ) );
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'inactive' WHERE affiliate_id = " . (int) $g1_c3 );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R7-TR2 first touch: deactivated earlier code replaced by the new referral', $g1ca, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null );
$GLOBALS['g1_wc']->session->set( 'wpac_ref', array( 'code' => $g1ca, 'campaign' => '', 'content_id' => 0, 'ts' => time() - 40 * DAY_IN_SECONDS ) );
Tracker::capture_code( $g1cb, '', 0 );
check( 'R7-TR2 first touch: touch outside the attribution window replaced', $g1cb, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null );
$g1_consent( true );
$GLOBALS['g1_wc']->session->set( 'wpac_ref', array( 'code' => $g1cc, 'campaign' => '', 'content_id' => 0, 'ts' => time() - 120 ) );
$_COOKIE[ Tracker::cookie_name() ] = Tracker::sign( $g1ca . '||0|' . ( time() - 60 ) );
check( 'R7-TR2 current touch skips the invalid older session touch and uses the valid cookie', $g1ca, Tracker::current_touch()['code'] ?? null );
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'active' WHERE affiliate_id = " . (int) $g1_c3 );
$g1_set( array( 'touch_model' => 'last' ) );

/* R7-TR3: a consent-bound session stays consent-bound ------------------------------------------- */
$g1_reset( true );
$g1_browser( '203.0.113.36' );
$GLOBALS['g1_wc']->session->set( 'wpac_ref', array( 'code' => $g1ca, 'campaign' => '', 'content_id' => 0, 'ts' => time() - 60, 'via_consent' => 1 ) );
$g1_consent( true );
Tracker::capture_code( $g1cb, '', 0 );
check( 'R7-TR3 later touch in a consent-created session keeps the consent mark', 1, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['via_consent'] ?? 0 );
$g1_consent( false );
check( 'R7-TR3 consent withdrawn: the later touch is ignored too', null, Tracker::current_touch() );

/* R7-TR5: landing URLs excluded from speculative loading ----------------------------------------- */
check( 'R7-TR5 /partner/* excluded from Speculation Rules', true, in_array( '/partner/*', Landing::speculation_exclude( array() ), true ) );

/* R7-TR6: link previews, monitors and HTTP libraries are bots, CUBOT phones are not -------------- */
$g1_bots = array();
foreach ( array( 'WhatsApp/2.23.20.0', 'Viber/16.0', 'Mastodon/4.2.1 (http.rb/5.1.1; +https://m.example/)', 'Iframely/1.3.1', 'Pinterest/0.2 (+https://www.pinterest.com/)', 'Mozilla/4.0 (compatible; ms-office; MSOffice 16)', 'GoogleOther', 'FeedFetcher-Google', 'StatusCake', 'Uptime-Kuma/1.23', 'Site24x7', 'GuzzleHttp/7', 'Go-http-client/2.0', 'okhttp/4.9.0', 'axios/1.6.0', 'node-fetch/1.0', 'Java/17.0.1', 'Apache-HttpClient/4.5', 'Scrapy/2.11', 'WordPress/6.5; https://example.org' ) as $ua ) {
	$g1_bots[] = Tracker::is_bot( array( 'HTTP_USER_AGENT' => $ua, 'REQUEST_METHOD' => 'GET' ) );
}
check( 'R7-TR6 link previews, monitors and HTTP clients are bots', array_fill( 0, 20, true ), $g1_bots );
check( 'R7-TR6 HEAD request is a bot; CUBOT phone and Chrome are people', array( true, false, false ), array(
	Tracker::is_bot( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130.0', 'REQUEST_METHOD' => 'HEAD' ) ),
	Tracker::is_bot( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 12; CUBOT KingKong 9) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 'REQUEST_METHOD' => 'GET' ) ),
	Tracker::is_bot( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0', 'REQUEST_METHOD' => 'GET' ) ),
) );

/* R7-TR7: canonical redirect / reload counted once ------------------------------------------------ */
$g1_reset();
$g1_browser( '203.0.113.37' );
$g1_before = $g1_visits( $g1a );
Tracker::capture_code( $g1ca, '', 0 );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R7-TR7 same visitor twice within a minute (canonical redirect, reload) = one visit, also without JS tracking', $g1_before + 1, $g1_visits( $g1a ) );
Tracker::register_hooks();
check( 'R7-TR7 capture runs after redirect_canonical (priority 11)', true, isset( $GLOBALS['test_hooks']['template_redirect'][11] ) );

/* R7-TR8: cookie domain for landing and shop on different subdomains ----------------------------- */
check( 'R7-TR8 cookie domain: parent domain accepted, foreign domain rejected', array( '.example.test', '', true ), array( Settings::sanitize( array( 'cookie_domain' => 'example.test' ) )['cookie_domain'], Settings::sanitize( array( 'cookie_domain' => 'other.test' ) )['cookie_domain'], isset( ( function () { Settings::sanitize( array( 'cookie_domain' => 'other.test' ), $e ); return $e; } )()['cookie_domain'] ) ) );
$g1_set( array( 'cookie_domain' => '.example.test' ) );
check( 'R7-TR8 cookie uses the domain; subdomains count as own hosts, others not', array( '.example.test', true, false ), array( Tracker::cookie_options( 0 )['domain'], Tracker::is_own_host( 'shop.example.test' ), Tracker::is_own_host( 'evil-example.test' ) ) );
check( 'R7-TR8 beacon from a subdomain accepted, from a foreign origin ignored', array( true, false ), array( RestApi::own_origin( 'https://www.example.test' ), RestApi::own_origin( 'https://attacker.test' ) ) );
$g1_set( array( 'cookie_domain' => '' ) );

/* R7-TR11: own clicks of the partner are not counted ------------------------------------------------ */
$g1_user = 4711;
$wpdb->query( 'UPDATE ' . Db::table( 'affiliates' ) . ' SET user_id = ' . $g1_user . ' WHERE id = ' . (int) $g1a );
$g1_reset();
$g1_browser( '203.0.113.38' );
$g1_before          = $g1_visits( $g1a );
$g1_old_user        = $GLOBALS['test_user'];
$GLOBALS['test_user'] = $g1_user;
Tracker::capture_code( $g1ca, '', 0 );
$GLOBALS['test_user'] = $g1_old_user;
check( 'R7-TR11 partner following the own link: no visit', $g1_before, $g1_visits( $g1a ) );

/* R7-TR12: beacon accepts codes the page request accepts --------------------------------------- */
RestApi::routes();
$g1_args = $GLOBALS['test_routes']['wpac/v1/touch']['args']['code'] ?? array();
check( 'R7-TR12 beacon code argument without the strict pattern', array( false, 100 ), array( isset( $g1_args['pattern'] ), $g1_args['maxLength'] ?? 0 ) );
$g1_set( array( 'js_tracking' => 1 ) );
$g1_reset();
$g1_browser( '203.0.113.39' );
$g1_before = $g1_visits( $g1b );
RestApi::touch( new WP_REST_Request( array( 'code' => strtolower( $g1cb ) . '.' ) ) );
check( 'R7-TR12 "code." from the end of a sentence is recorded via the beacon', $g1_before + 1, $g1_visits( $g1b ) );

/* R7-AD1: referrer host only with consent ------------------------------------------------------- */
$g1_reset();
$g1_browser( '203.0.113.40' );
$_SERVER['HTTP_REFERER'] = 'https://news.example.org/artikel?id=5';
$g1_set( array( 'js_tracking' => 0 ) );
Tracker::capture_code( $g1cb, '', 0 );
$g1_ref_no = (string) $wpdb->get_var( 'SELECT referrer_host FROM ' . Db::table( 'visits' ) . ' ORDER BY id DESC LIMIT 1' );
$g1_browser( '203.0.113.41' );
$_SERVER['HTTP_REFERER'] = 'https://news.example.org/artikel?id=5';
$g1_consent( true );
Tracker::capture_code( $g1cb, '', 0 );
$g1_ref_yes = (string) $wpdb->get_var( 'SELECT referrer_host FROM ' . Db::table( 'visits' ) . ' ORDER BY id DESC LIMIT 1' );
check( 'R7-AD1 referrer host stored only with marketing consent', array( '', 'news.example.org' ), array( $g1_ref_no, $g1_ref_yes ) );

/* R6-CC1: beacon of a logged-in customer does not load (and end) the shop session --------------- */
if ( ! defined( 'LOGGED_IN_COOKIE' ) ) { define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_test' ); }
$g1_set( array( 'js_tracking' => 1 ) );
$GLOBALS['g1_wc']->session = null;
$GLOBALS['g1_cart_loads']  = 0;
$_COOKIE[ LOGGED_IN_COOKIE ] = 'kunde|123|token|hash';
RestApi::touch( new WP_REST_Request( array( 'code' => $g1ca ) ) );
$g1_logged = $GLOBALS['g1_cart_loads'];
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
RestApi::touch( new WP_REST_Request( array( 'code' => $g1ca ) ) );
check( 'R6-CC1 session only loaded for guests (logged-in: none, guest: once)', array( 0, 1 ), array( $g1_logged, $GLOBALS['g1_cart_loads'] ) );

/* R6-CC4 / R7-DB2 / R5-W47: proxies and limits ---------------------------------------------------- */
$g1_reset( true );
$g1_browser( '198.51.100.200' );
for ( $i = 0; $i < 35; $i++ ) {
	RestApi::touch( new WP_REST_Request( array( 'code' => $g1ca ) ) );
}
$GLOBALS['g1_wc']->session->set( 'wpac_ref', null );
RestApi::touch( new WP_REST_Request( array( 'code' => $g1cb ) ) );
check( 'R7-DB2 beacon over 30/min from one edge address still stores the referral', $g1cb, $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null );
$g1_before = $g1_visits( $g1a );
$g1_browser( '198.51.100.201', 'Mozilla/5.0 (iPhone) Safari/604.1' );
Tracker::capture_code( $g1ca, '', 0 );
$g1_browser( '198.51.100.201', 'Mozilla/5.0 (Android 14) Chrome/130.0' );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R6-CC4 two visitors behind one proxy address are two visits', $g1_before + 2, $g1_visits( $g1a ) );
$GLOBALS['test_hooks']['wpac_rate_limit'][10][] = array( static function ( $limit, $bucket ) { return 'g1-bucket' === $bucket ? 1 : $limit; }, 2 );
check( 'R5-W47 limits can be changed with wpac_rate_limit', array( true, false ), array( RateLimiter::allow( 'g1-bucket', 50 ), RateLimiter::allow( 'g1-bucket', 50 ) ) );
unset( $GLOBALS['test_hooks']['wpac_rate_limit'] );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44';
check( 'R5-W47 Site Health: proxy headers from an untrusted peer are reported', array( true, true ), array( RateLimiter::behind_untrusted_proxy(), (bool) array_filter( Tracker::tracking_problems(), static function ( $p ) { return false !== strpos( $p[1], 'X-Forwarded-For' ); } ) ) );
$g1_set( array( 'trusted_proxies' => '198.51.100.0/24' ) );
check( 'R5-W47 configured proxy: no warning, client address from X-Forwarded-For', array( false, '192.0.2.44' ), array( RateLimiter::behind_untrusted_proxy(), RateLimiter::current_ip() ) );
$g1_set( array( 'trusted_proxies' => '' ) );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

/* R5-W31 / R7-DB5 / R7-DB11: counter table ------------------------------------------------------ */
$g1_rl   = Db::table( 'rate_limits' );
$g1_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g1_rl}" );
RestApi::touch( new WP_REST_Request( array( 'code' => 'UNKNOWN-G1' ) ) );
check( 'R5-W31 beacon with an unknown code writes no counter row', $g1_rows, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g1_rl}" ) );
check( 'R7-DB11 counter keys stored as 16 bytes', array( '16' ), $wpdb->get_col( "SELECT DISTINCT LENGTH(rl_key) FROM {$g1_rl}" ) );
check( 'R7-DB5 counter values come back from the same statement (1, 2, 3)', array( true, false, false ), array( RateLimiter::first( 'g1-db5', 'x', 60 ), RateLimiter::first( 'g1-db5', 'x', 60 ), RateLimiter::first( 'g1-db5', 'x', 60 ) ) );
for ( $i = 0; $i < 12; $i++ ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$g1_rl} (rl_key, hits, expires_at) VALUES (UNHEX(%s), 1, %d)", md5( 'g1-old-' . $i ), time() - 10 ) );
}
check( 'R5-W31 expired counters purged (hourly hook)', array( true, 0 ), array( RateLimiter::purge() >= 12, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$g1_rl} WHERE expires_at < %d", time() ) ) ) );
check( 'R5-W31 purge runs every hour', true, isset( $GLOBALS['test_hooks']['wpac_hourly'] ) && (bool) array_filter( $GLOBALS['test_hooks']['wpac_hourly'][10], static function ( $h ) { return is_array( $h[0] ) && 'purge' === $h[0][1]; } ) );
// Migration: a char(32) table of an older version becomes BINARY(16).
$wpdb->query( "DROP TABLE {$g1_rl}" );
$wpdb->query( "CREATE TABLE {$g1_rl} ( rl_key char(32) NOT NULL, hits int(10) unsigned NOT NULL DEFAULT 0, expires_at int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (rl_key), KEY expires_at (expires_at) )" );
$wpdb->query( "INSERT INTO {$g1_rl} VALUES ('0123456789abcdef0123456789abcdef', 1, 1)" );
update_option( 'wpac_db_version', 9 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'R7-DB11 migration changes the key column to binary(16)', 'binary(16)', strtolower( (string) $wpdb->get_row( "SHOW COLUMNS FROM {$g1_rl} LIKE 'rl_key'" )->Type ) );
check( 'R7-DB11 counting works after the migration', array( true, false ), array( RateLimiter::first( 'g1-mig', 'y', 60 ), RateLimiter::first( 'g1-mig', 'y', 60 ) ) );

/* R6-CC12: node-local object caches are not used for limits ------------------------------------- */
check( 'R6-CC12 APCu object cache is node-local, Redis is shared', array( false, true ), array( RateLimiter::shared_cache_class( 'APCu_Object_Cache' ), RateLimiter::shared_cache_class( 'WP_Object_Cache_Redis' ) ) );

/* R6-CC10: public code check never cached ------------------------------------------------------ */
$g1_res = RestApi::check_code( new WP_REST_Request( array( 'code' => $g1ca ) ) );
check( 'R6-CC10 /codes/{code} answers with Cache-Control: no-store', array( true, 'no-store, private' ), array( $g1_res->data['valid'] ?? null, $g1_res->headers['Cache-Control'] ?? '' ) );

/* R6-CC11: edge placeholders are no campaign ------------------------------------------------------ */
$g1_aff = AffiliateRepository::find( $g1a );
check( 'R6-CC11 PANTHEON_STRIPPED dropped (tracker and landing redirect)', array( '', 'sommer', false, true ), array( Tracker::clean_utm( 'PANTHEON_STRIPPED' ), Tracker::clean_utm( 'sommer' ), false !== strpos( Landing::target_url( $g1_aff, array( 'utm_campaign' => 'PANTHEON_STRIPPED', 'utm_source' => 'nl' ) ), 'STRIPPED' ), false !== strpos( Landing::target_url( $g1_aff, array( 'utm_campaign' => 'PANTHEON_STRIPPED', 'utm_source' => 'nl' ) ), 'utm_source=nl' ) ) );

/* R6-CC13: Secure flag behind a TLS proxy, delete cookie with the same attributes --------------- */
check( 'R6-CC13 Secure when the site is HTTPS even if is_ssl() is false', array( true, false ), array( Tracker::secure_for( 'https://shop.example', false ), Tracker::secure_for( 'http://shop.example', false ) ) );
$g1_reset();
$g1_consent( false );
$_COOKIE[ Tracker::cookie_name() ] = 'x';
Tracker::drop_cookie_without_consent();
$g1_c = $g1_cookie();
check( 'R6-CC13 deleting the cookie keeps SameSite, path and HttpOnly', array( '', 'Lax', '/', true ), array( $g1_c[1], $g1_c[2]['samesite'], $g1_c[2]['path'], $g1_c[2]['httponly'] ) );

/* R6-CC15: cookie name filterable, Site Health on hosts with a cookie allow-list ---------------- */
$GLOBALS['test_hooks']['wpac_cookie_name'][10][] = array( static function () { return 'STYXKEY_wpac_ref'; }, 1 );
check( 'R6-CC15 cookie name filterable', 'STYXKEY_wpac_ref', Tracker::cookie_name() );
unset( $GLOBALS['test_hooks']['wpac_cookie_name'] );
putenv( 'PANTHEON_ENVIRONMENT=live' );
check( 'R6-CC15 Site Health warns when the host filters the cookie name', true, (bool) array_filter( Tracker::tracking_problems(), static function ( $p ) { return false !== strpos( $p[1], 'STYXKEY_' ); } ) );
putenv( 'PANTHEON_ENVIRONMENT' );

/* R7-DB6: visit log off / sampling ----------------------------------------------------------------- */
$g1_set( array( 'visit_log_percent' => 0 ) );
$g1_reset( true );
$g1_browser( '203.0.113.50' );
$g1_before = $g1_visits( $g1a );
Tracker::capture_code( $g1ca, '', 0 );
check( 'R7-DB6 visit log off: no visit row, the referral is still stored', array( $g1_before, $g1ca ), array( $g1_visits( $g1a ), $GLOBALS['g1_wc']->session->get( 'wpac_ref' )['code'] ?? null ) );
check( 'R7-DB6 sampling percentage validated', array( 10, true ), array( Settings::sanitize( array( 'visit_log_percent' => '10' ) )['visit_log_percent'], isset( ( function () { Settings::sanitize( array( 'visit_log_percent' => '150' ), $e ); return $e; } )()['visit_log_percent'] ) ) );
$g1_set( array( 'visit_log_percent' => 100 ) );

/* R7-DB8: main code / vanity slug without loading every code ------------------------------------- */
$g1_d = AffiliateRepository::create( array( 'name' => 'Gustav Vier', 'email' => 'g1-d@example.de', 'status' => 'active' ) );
$g1_main = CodeRepository::main_code( $g1_d );
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'inactive' WHERE affiliate_id = " . (int) $g1_d );
CodeRepository::create_batch( $g1_d, 3, 'GB' );
CodeRepository::create( $g1_d, 'G1MAINNEW', array( 'expires_at' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) );
CodeRepository::create( $g1_d, 'G1EXPIRED', array() );
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET expires_at = '2020-01-01 00:00:00' WHERE code = 'G1EXPIRED'" );
check( 'R7-DB8 main code: first valid non-batch code (inactive and expired skipped)', 'G1MAINNEW', CodeRepository::main_code( $g1_d ) );
check( 'R7-DB8 vanity slug resolves to the first valid code', true, in_array( wpac_resolve_partner_code( AffiliateRepository::find( $g1_d )->slug, array( 'context' => 'link' ) )['code'] ?? '', array( 'G1MAINNEW' ), true ) || 0 === strpos( wpac_resolve_partner_code( AffiliateRepository::find( $g1_d )->slug, array( 'context' => 'link' ) )['code'] ?? '', 'GB' ) );

/* R5-W50: the beacon reports on cached copies too ------------------------------------------------- */
$g1_script = Tracker::beacon_script( array( 'url' => 'u', 'param' => 'partner', 'ref' => 0 ) );
check( 'R5-W50 beacon not suppressed by the page time (server dedup only); prerender waits', array( false, true ), array( false !== strpos( $g1_script, 'd.cap' ), false !== strpos( $g1_script, 'prerenderingchange' ) ) );

/* R6-MS1: one cookie per site in a multisite network --------------------------------------------- */
$GLOBALS['test_multisite'] = 1;
$GLOBALS['test_blog_id']   = 2;
$g1_ms_name = Tracker::cookie_name();
$g1_ms_sig  = Tracker::sign( $g1ca . '||0|' . time() );
$GLOBALS['test_blog_id'] = 3;
$g1_ms_other = Tracker::parse_cookie( $g1_ms_sig );
$GLOBALS['test_blog_id'] = 2;
$g1_ms_same = Tracker::parse_cookie( $g1_ms_sig );
$GLOBALS['test_multisite'] = 0;
$GLOBALS['test_blog_id']   = 1;
check( 'R6-MS1 cookie name per site, signature bound to the site', array( 'wpac_ref_2', null, $g1ca ), array( $g1_ms_name, $g1_ms_other, $g1_ms_same['code'] ?? null ) );
check( 'R6-MS1 single site keeps the name and accepts existing cookies', array( 'wpac_ref', $g1ca ), array( Tracker::cookie_name(), Tracker::parse_cookie( Tracker::sign( $g1ca . '||0|' . time() ) )['code'] ?? null ) );

/* R7-FR7: unreviewed partner names on shop pages -------------------------------------------------- */
check( 'R7-FR7 plain names shown, messages and payment instructions not', array( 'Anna Müller', 'Segelschule Nord & Co. KG', '', '', '' ), array( Landing::display_name( 'Anna  Müller' ), Landing::display_name( 'Segelschule Nord & Co. KG' ), Landing::display_name( 'Support – Zahlung nur per Überweisung an DE89 3704 0044' ), Landing::display_name( 'Mehr Infos auf www.example.com' ), Landing::display_name( 'kontakt@example.com' ) ) );
AffiliateRepository::update( $g1b, array( 'name' => 'Support - Zahlung nur per Ueberweisung an Konto 12345' ) );
$_GET['partner'] = $g1cb;
$g1_html = Landing::welcome( array() );
unset( $_GET['partner'] );
check( 'R7-FR7 welcome box without the suspicious name', array( false, '' ), array( false !== strpos( $g1_html, 'Konto' ), $g1_html ) );

// Clean up.
$GLOBALS['test_user'] = $g1_test_user;
update_option( 'wpac_settings', $g1_settings );
Settings::flush_cache();
$_SERVER = $g1_server;
$_COOKIE = array();
$GLOBALS['g1_wc']->session    = null;
$GLOBALS['test_consent_type']   = '';
$GLOBALS['test_consent_cookie'] = '';
