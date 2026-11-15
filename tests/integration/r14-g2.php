<?php
/**
 * Audit round 14, fix agent g2: self-referral address match on whole words, unpublished landing
 * pages, noindex for partner-link pages, the partner area and confirmation pages, landing URLs of
 * paused/unknown partners, bot filter additions, reserved names for the referral parameter, pass-
 * through of campaign parameters and click IDs, creative target URL check and the code validity
 * oracle.
 * Included from run.php (helpers check(), q(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Promotion\Creatives;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 g2 tracking, landing, SEO, bot filter, code oracle\n";
global $wpdb;

$r14g2_root = dirname( __DIR__, 2 );
$r14g2_src  = static function ( $file ) use ( $r14g2_root ) {
	return (string) file_get_contents( $r14g2_root . '/' . $file );
};
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script() { return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $js ) { $GLOBALS['r14g2_inline'][] = $js; return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return false; } }

$r14g2_settings = get_option( 'wpac_settings' );
$r14g2_set      = static function ( array $s ) {
	$cur = get_option( 'wpac_settings' );
	update_option( 'wpac_settings', array_merge( is_array( $cur ) ? $cur : array(), $s ) );
	Settings::flush_cache();
};
$r14g2_get    = $_GET;
$r14g2_server = $_SERVER;
$r14g2_param  = (string) Settings::get( 'referral_param' );

// ---------------------------------------------------------------------------------------------
// R14-T1: the street of the buyer is compared on whole words, the house number exactly.
$t1 = static function ( $street, $partner ) {
	return AttributionResolver::matches_profile( array( 'name' => 'Fremde Kundin', 'address' => $street, 'postcode' => '12345' ), array( 'Anna Partner' ), $partner );
};
check(
	'R14-T1 neighbours are no self-referral (1/15, Str. 2/Straße 21, 1/1a, 12/2)',
	array( false, false, false, false ),
	array( $t1( 'Am Markt 1', "Am Markt 15\n12345 Berlin" ), $t1( 'Hauptstr. 2', 'Hauptstraße 21, 12345 Berlin' ), $t1( 'Ring 1', 'Ring 1a, 12345 Berlin' ), $t1( 'Ring 2', 'Ring 12, 12345 Berlin' ) )
);
check(
	'R14-T1 the same address still matches (Str./Straße, missing space, other case)',
	array( true, true, true ),
	array( $t1( 'Am Markt 15', "Am Markt 15\n12345 Berlin" ), $t1( 'Hauptstr.21', 'Hauptstraße 21, 12345 Berlin' ), $t1( 'RING 1A', 'Ring 1a, 12345 Berlin' ) )
);

// ---------------------------------------------------------------------------------------------
// R14-T2: only published landing pages; partner page → default page → home page; monitor check.
$GLOBALS['test_post_status'] = array( 7701 => 'publish', 7702 => 'draft', 7703 => 'trash', 7704 => 'private' );
check(
	'R14-T2 page_url: published page, draft/trash/private fall back',
	array( 'http://example.test/?p=7701', 'http://example.test/', 'http://example.test/x', 'http://example.test/' ),
	array( Locale::page_url( 7701 ), Locale::page_url( 7702 ), Locale::page_url( 7703, '', 'http://example.test/x' ), Locale::page_url( 7704 ) )
);
$t2_aff  = AffiliateRepository::create( array( 'name' => 'Tobias Landing', 'email' => 'r14g2-t2@example.de', 'status' => 'active' ) );
$t2_code = CodeRepository::main_code( $t2_aff );
$r14g2_set( array( 'landing_page_id' => 7701 ) );
$t2_url = Landing::target_url( (object) array( 'id' => $t2_aff, 'landing_page_id' => 7702 ), array() );
check( 'R14-T2 unpublished partner page: default landing page with the code', 'http://example.test/?p=7701&' . $r14g2_param . '=' . rawurlencode( $t2_code ), $t2_url );
$r14g2_set( array( 'landing_page_id' => 7703 ) );
$t2_url = Landing::target_url( (object) array( 'id' => $t2_aff, 'landing_page_id' => 7704 ), array() );
check( 'R14-T2 default page in the trash too: home page with the code', 'http://example.test/?' . $r14g2_param . '=' . rawurlencode( $t2_code ), $t2_url );
$t2_unpub = Monitor::unpublished_landing_pages();
$t2_check = Monitor::checks();
check( 'R14-T2 monitor reports the unpublished default landing page', array( true, 'recommended' ), array( in_array( 7703, $t2_unpub, true ), isset( $t2_check['landing_pages'] ) ? $t2_check['landing_pages'][0] : '' ) );
$r14g2_set( array( 'landing_page_id' => 7701 ) );
check( 'R14-T2 no monitor entry with a published landing page', false, in_array( 7701, Monitor::unpublished_landing_pages(), true ) );

// ---------------------------------------------------------------------------------------------
// R14-T3 / R14-T8: noindex, follow for referral links, the partner area and confirmation pages.
$r14g2_set( array( 'portal_page_id' => 7701 ) );
check(
	'R14-T3/T8 noindex: referral parameter, partner area, confirmation; not for normal pages',
	array( true, true, true, true, false, false ),
	array(
		Landing::noindex( array( $r14g2_param => 'ANNA' ), 0 ),
		Landing::noindex( array(), 7701 ),
		Landing::noindex( array( 'wpac_confirm' => 'tok' ), 0 ),
		Landing::noindex( array( 'wpac_unsubscribe' => 'monthly' ), 0 ),
		Landing::noindex( array( 'utm_campaign' => 'x' ), 0 ),
		Landing::noindex( array( $r14g2_param => '' ), 5 ),
	)
);
$_GET   = array( $r14g2_param => 'ANNA', 'sub' => 'x' );
$t3_rob = Landing::robots( array( 'index' => true, 'max-image-preview' => 'large' ) );
$_GET   = array();
$t3_off = Landing::robots( array( 'max-image-preview' => 'large' ) );
check( 'R14-T3 wp_robots: noindex + follow, index removed; untouched without the parameter', array( array( 'max-image-preview' => 'large', 'noindex' => true, 'follow' => true ), array( 'max-image-preview' => 'large' ) ), array( $t3_rob, $t3_off ) );
check( 'R14-T3 the filter is registered (not robots.txt)', true, false !== strpos( $r14g2_src( 'src/Promotion/Landing.php' ), "add_filter( 'wp_robots', array( __CLASS__, 'robots' ) )" ) );
$r14g2_set( array( 'portal_page_id' => isset( $r14g2_settings['portal_page_id'] ) ? $r14g2_settings['portal_page_id'] : 0 ) );

// ---------------------------------------------------------------------------------------------
// R14-T4 / R14-S2 (slug) / R14-T7: landing URLs.
$t4_paused      = AffiliateRepository::create( array( 'name' => 'Paula Pause', 'email' => 'r14g2-t4@example.de', 'status' => 'paused' ) );
$t4_paused_slug = (string) AffiliateRepository::find( $t4_paused )->slug;
$t4_slug        = (string) AffiliateRepository::find( $t2_aff )->slug;
$_SERVER['REMOTE_ADDR'] = '198.51.100.141';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$t4_query = array( 'utm_campaign' => 'qr', 'utm_content' => 'flyer', 'utm_term' => 'kurs', 'gclid' => 'Cj0KCQ-abc_123', 'fbclid' => 'IwAR<b>x', 'sub' => 'box-1', 'foo' => 'bar', 'utm_source' => 'PANTHEON_STRIPPED' );
check(
	'R14-T4 paused partner / unknown slug on a 404: default landing page without code; a real page wins',
	array( 'http://example.test/?p=7701', 'http://example.test/?p=7701', null, null ),
	array( Landing::redirect_url( $t4_paused_slug, array(), true ), Landing::redirect_url( 'gibt-es-nicht-r14', array(), true ), Landing::redirect_url( $t4_paused_slug, array(), false ), Landing::redirect_url( 'bewerbung-r14', array(), false ) )
);
$t7_url = (string) Landing::redirect_url( $t4_slug, $t4_query, false );
parse_str( (string) parse_url( $t7_url, PHP_URL_QUERY ), $t7_args );
check(
	'R14-T7 all utm_* and click IDs passed on (cleaned), sub-ID kept, other parameters and placeholders dropped',
	array( 'p' => '7701', $r14g2_param => $t2_code, 'utm_campaign' => 'qr', 'utm_content' => 'flyer', 'utm_term' => 'kurs', 'gclid' => 'Cj0KCQ-abc_123', 'fbclid' => 'IwARbx', 'sub' => 'box-1' ),
	$t7_args
);
$t7_def = (string) Landing::redirect_url( $t4_paused_slug, array( 'utm_campaign' => 'qr', 'msclkid' => 'm1' ), true );
check( 'R14-T4 default landing page keeps the campaign parameters, no code', array( false, true, true ), array( false !== strpos( $t7_def, $r14g2_param . '=' ), false !== strpos( $t7_def, 'utm_campaign=qr' ), false !== strpos( $t7_def, 'msclkid=m1' ) ) );
add_filter( 'wpac_landing_passthrough_params', static function ( $names ) { return array_diff( $names, array( 'gclid' ) ); } );
check( 'R14-T7 pass-through list is filterable', false, false !== strpos( (string) Landing::target_url( AffiliateRepository::find( $t2_aff ), $t4_query ), 'gclid' ) );
remove_all_filters( 'wpac_landing_passthrough_params' );
check( 'R14-T4 redirect() also finds the slug after fallback_to_page() resolved it as a page', true, false !== strpos( $r14g2_src( 'src/Promotion/Landing.php' ), '$slug = self::$fallback_slug;' ) );

// ---------------------------------------------------------------------------------------------
// R14-T5: bot filter.
$t5_bots = array(
	'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.6422.175 Mobile Safari/537.36 (compatible; Google-InspectionTool/1.0;)',
	'Mediapartners-Google',
	'facebookcatalog/1.0',
	'Mozilla/5.0 (compatible; Embedly/0.2; +http://support.embed.ly/)',
	'Feedly/1.0 (+http://www.feedly.com/fetcher.html; 16 subscribers; )',
	'ia_archiver (+http://www.alexa.com/site/en.html)',
	'python-httpx/0.27.0',
	'PostmanRuntime/7.39.0',
	'Mozilla/5.0 (compatible; Snapchat/1.0)',
	'Expanse, a Palo Alto Networks company, searches across the global IPv4 space multiple times per day',
	'Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)',
	'Google-Read-Aloud',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Google (+https://developers.google.com/+/web/snippet/)',
	'Mozilla/5.0 (compatible; SomeNewFetcher/2.0; +https://example.org/fetcher)',
);
$t5_humans = array(
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.0',
	'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/450.0.0.0;] ',
	'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 Telegram-Android/10.0',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Snapchat/12.53.0.40 (like Safari/8614.2.9.0.10, panda)',
	'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
);
$t5_is = static function ( $ua ) { return Tracker::is_bot( array( 'REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => $ua ) ); };
check( 'R14-T5 Google services, feed readers, archives, scanners, HTTP clients and URL-in-UA are bots', array_fill( 0, count( $t5_bots ), true ), array_map( $t5_is, $t5_bots ) );
check( 'R14-T5 browsers and in-app browsers (incl. Snapchat) stay human', array_fill( 0, count( $t5_humans ), false ), array_map( $t5_is, $t5_humans ) );

// ---------------------------------------------------------------------------------------------
// R14-T6: reserved names for the referral parameter.
$t6 = array();
foreach ( array( 'p', 's', 'name', 'page', 'add-to-cart', 'sub', 'utm_campaign', 'utm_whatever', 'wpac_landing', 'gclid', 'product_cat', 'empfehlung' ) as $t6_name ) {
	$t6_out           = Settings::sanitize( array( 'referral_param' => $t6_name ), $t6_err );
	$t6[ $t6_name ] = array( isset( $t6_err['referral_param'] ), $t6_out['referral_param'] );
}
$t6_old = (string) Settings::get( 'referral_param' );
check(
	'R14-T6 reserved names rejected (old value kept), a free name accepted',
	array( 'p' => array( true, $t6_old ), 's' => array( true, $t6_old ), 'name' => array( true, $t6_old ), 'page' => array( true, $t6_old ), 'add-to-cart' => array( true, $t6_old ), 'sub' => array( true, $t6_old ), 'utm_campaign' => array( true, $t6_old ), 'utm_whatever' => array( true, $t6_old ), 'wpac_landing' => array( true, $t6_old ), 'gclid' => array( true, $t6_old ), 'product_cat' => array( true, $t6_old ), 'empfehlung' => array( false, 'empfehlung' ) ),
	$t6
);
Settings::sanitize( array( 'referral_param' => $t6_old ), $t6_err );
check( 'R14-T6 the current value is not rejected on save', false, isset( $t6_err['referral_param'] ) );

// ---------------------------------------------------------------------------------------------
// R14-T9: creative target page checked like the link generator (www, case, path), stored normalized.
$t9 = array();
foreach ( array( 'https://WWW.Example.test/kurse/?a=1', 'www.example.test/kurse/', '/kurse/', 'https://evil.test/kurse/' ) as $t9_url ) {
	$t9_res = Creatives::sanitize( array( 'title' => 'T9', 'type' => 'text', 'content' => 'Hallo', 'landing_url' => $t9_url ) );
	$t9[]   = is_wp_error( $t9_res ) ? $t9_res->get_error_code() : $t9_res['landing_url'];
}
check( 'R14-T9 www/case/path accepted and normalized, other hosts refused', array( 'http://example.test/kurse/?a=1', 'http://example.test/kurse/', 'http://example.test/kurse/', 'wpac_url' ), $t9 );

// ---------------------------------------------------------------------------------------------
// R14-S2: code validity oracle.
$r14g2_set( array( 'cookie_mode' => 'consent', 'js_tracking' => 0, 'use_wc_session' => 0, 'touch_model' => 'last' ) );
$GLOBALS['test_consent_type']   = 'optin';
$GLOBALS['test_consent_cookie'] = '';
$s2_aff  = AffiliateRepository::create( array( 'name' => 'Sven Orakel', 'email' => 'r14g2-s2@example.de', 'status' => 'active' ) );
$s2_code = CodeRepository::main_code( $s2_aff );
$s2_has  = array();
foreach ( array( 'GUESS-R14-1', $s2_code ) as $s2_c ) {
	$GLOBALS['r14g2_inline'] = array();
	$_GET                    = array( $r14g2_param => $s2_c );
	Tracker::capture_code( $s2_c, '', 0 );
	Tracker::enqueue_beacon();
	$s2_has[] = count( $GLOBALS['r14g2_inline'] );
}
check( 'R14-S2 consent listener script for unknown and valid codes alike', array( 1, 1 ), $s2_has );
check( 'R14-S2 no captured_now condition left in enqueue_beacon()', false, false !== strpos( $r14g2_src( 'src/Tracking/Tracker.php' ), 'captured_now' ) );

$r14g2_set( array( 'cookie_mode' => 'always', 'js_tracking' => 0, 'use_wc_session' => 0, 'touch_model' => 'last' ) );
add_filter( 'wpac_rate_limit', static function ( $limit, $bucket ) { return 'code_miss' === $bucket ? 5 : $limit; }, 10, 2 );
$_SERVER['REMOTE_ADDR'] = '198.51.100.142';
if ( time() % 60 >= 50 ) { // The miss counter uses fixed one-minute windows: do not straddle two.
	sleep( 61 - time() % 60 );
}
$s2_cookies = static function () { return count( Tracker::written_cookies() ); };
$s2_before  = $s2_cookies();
$s2_first   = Tracker::capture_code( $s2_code, '', 0 );
$s2_after1  = $s2_cookies();
for ( $s2_i = 0; $s2_i < 6; $s2_i++ ) {
	Tracker::capture_code( 'GUESS-R14-X' . $s2_i, '', 0 );
}
$s2_after_miss = $s2_cookies();
$s2_blocked    = Tracker::capture_code( $s2_code, '', 0 );
$s2_after2     = $s2_cookies();
$s2_slug       = (string) AffiliateRepository::find( $s2_aff )->slug;
$s2_land       = (string) Landing::redirect_url( $s2_slug, array(), false );
$_SERVER['REMOTE_ADDR'] = '198.51.100.143'; // Another address is not affected.
$s2_other      = Tracker::capture_code( $s2_code, '', 0 );
$s2_land2      = (string) Landing::redirect_url( $s2_slug, array(), false );
check(
	'R14-S2 after too many unknown codes valid codes get no cookie and the landing no code (per address)',
	array( true, 1, 0, false, 0, false, true, true ),
	array( $s2_first, $s2_after1 - $s2_before, $s2_after_miss - $s2_after1, $s2_blocked, $s2_after2 - $s2_after_miss, false !== strpos( $s2_land, $r14g2_param . '=' ), $s2_other, false !== strpos( $s2_land2, $r14g2_param . '=' ) )
);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9'; // Untrusted proxy: all visitors share the address, nothing is blocked.
$_SERVER['REMOTE_ADDR']          = '198.51.100.142';
check( 'R14-S2 not applied behind an untrusted proxy', true, Tracker::capture_code( $s2_code, '', 0 ) );
remove_all_filters( 'wpac_rate_limit' );

// Restore.
$GLOBALS['test_consent_type']   = '';
$GLOBALS['test_consent_cookie'] = '';
unset( $GLOBALS['test_post_status'] );
$_GET    = $r14g2_get;
$_SERVER = $r14g2_server;
update_option( 'wpac_settings', $r14g2_settings );
Settings::flush_cache();
