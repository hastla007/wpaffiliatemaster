<?php
/**
 * Audit round 14, fix agent g7: database performance and caching (R14-D1 … R14-D8) – covering
 * index on visits (schema 14, online), cached partner and editor statistics, report generation
 * without autoload, rate-limit purge and partner erasure with time budget and continuation,
 * beacon reachability (probe, REST exception, admin-ajax fallback), beacon guard and delay-JS
 * exclusions. Included from run.php before r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Rest\RestApi;
use WPAffiliateCaptain\Tracking\RateLimiter;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 g7 performance: visits index, statistics cache, purges, beacon reachability\n";
global $wpdb;

$g7_root = dirname( __DIR__, 2 );
$g7_src  = static function ( $file ) use ( $g7_root ) {
	return (string) file_get_contents( $g7_root . '/' . $file );
};
$g7_settings = get_option( 'wpac_settings' );
$g7_set      = static function ( array $s ) {
	$cur = get_option( 'wpac_settings' );
	update_option( 'wpac_settings', array_merge( is_array( $cur ) ? $cur : array(), $s ) );
	Settings::flush_cache();
};
if ( ! function_exists( 'rest_get_url_prefix' ) ) { function rest_get_url_prefix() { return 'wp-json'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://example.test/wp-json/' . $p; } }
// Queries of a block of code (the "query" filter of Test_WPDB).
$g7_log = array();
add_filter(
	'query',
	static function ( $sql ) use ( &$g7_log ) {
		if ( is_array( $g7_log ) && isset( $g7_log['on'] ) ) {
			$g7_log['q'][] = (string) $sql;
		}
		return $sql;
	}
);
$g7_count = static function ( callable $fn, $needle ) use ( &$g7_log ) {
	$g7_log = array( 'on' => 1, 'q' => array() );
	$fn();
	$n      = count( preg_grep( '/' . preg_quote( $needle, '/' ) . '/', $g7_log['q'] ) );
	$g7_log = array();
	return $n;
};
$g7_visit = static function ( $aff, $campaign, $sub, $n, $content = 0 ) use ( $wpdb ) {
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $aff, 'campaign' => $campaign, 'sub_id' => $sub, 'content_id' => $content, 'created_at' => Db::now( time() - 60 * ( $i + 1 ) ) ) );
	}
};

/* R14-D1: covering index (schema 14), replaced online; verify_schema compares the columns. ---- */
$g7_vt      = t( 'visits' );
$g7_columns = static function () use ( $wpdb, $g7_vt ) {
	$cols = array();
	foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$g7_vt} WHERE Key_name = 'affiliate_created'" ) as $r ) {
		$cols[ (int) $r->Seq_in_index ] = strtolower( $r->Column_name );
	}
	ksort( $cols );
	return implode( ',', $cols );
};
check( 'R14-D1 schema 14: visits.affiliate_created covers campaign and sub-ID', array( 15, 'affiliate_id,created_at,campaign,sub_id' ), array( Installer::DB_VERSION, $g7_columns() ) );
q( "ALTER TABLE {$g7_vt} DROP KEY affiliate_created, ADD KEY affiliate_created (affiliate_id,created_at)" );
Installer::verify_schema();
$g7_warn = array_values( preg_grep( '/visits\.affiliate_created/', (array) get_option( 'wpac_schema_warnings' ) ) );
check( 'R14-D1 key with the columns of schema 13: warning (not a blocker) that names both column lists', array( array(), 1, true ), array( Installer::verify_schema(), count( $g7_warn ), isset( $g7_warn[0] ) && 0 === strpos( $g7_warn[0], 'missing index ' . $g7_vt . '.affiliate_created (affiliate_id,created_at,campaign,sub_id; still affiliate_id,created_at;' ) ) );
update_option( Installer::DB_OPTION, 13 );
Installer::install_schema();
check( 'R14-D1 migration 13 → 14 replaces the key online (DROP + ADD in one ALTER), no warning left', array( 15, 'affiliate_id,created_at,campaign,sub_id', array() ), array( (int) get_option( Installer::DB_OPTION ), $g7_columns(), array_values( preg_grep( '/^missing index /', (array) get_option( 'wpac_schema_warnings' ) ) ) ) );
check( 'R14-D1 the replacement is one online statement (key never missing, no table copy)', true, false !== strpos( $g7_src( 'src/Core/Installer.php' ), '"ALTER TABLE {$name} {$drop}ADD KEY {$key} ({$columns}), ALGORITHM=INPLACE, LOCK=NONE"' ) );

$g7_aff  = AffiliateRepository::create( array( 'name' => 'G7 Influencer', 'email' => 'g7-influencer@example.de', 'status' => 'active' ) );
$g7_aff2 = AffiliateRepository::create( array( 'name' => 'G7 Other', 'email' => 'g7-other@example.de', 'status' => 'active' ) );
$g7_visit( $g7_aff, 'insta', 'story1', 6 );
$g7_visit( $g7_aff, 'insta', 'story2', 3 );
$g7_visit( $g7_aff, '', '', 2 );
$g7_visit( $g7_aff2, 'insta', 'story1', 4 );
$g7_from = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$g7_to   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
list( $g7_s, $g7_e ) = Db::local_range( $g7_from, $g7_to );
$g7_explain = $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT campaign, sub_id, COUNT(*) n FROM {$g7_vt} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s GROUP BY campaign, sub_id", $g7_aff, $g7_s, $g7_e ), ARRAY_A );
check( 'R14-D1 EXPLAIN of the campaign statistics: index only (no row lookups by primary key)', array( 'affiliate_created', true ), array( (string) $g7_explain['key'], false !== stripos( (string) $g7_explain['Extra'], 'Using index' ) ) );

/* R14-D1: statistics of the partner area cached per partner and period. ----------------------- */
Reports::register_hooks();
$g7_c1 = null;
$g7_q1 = $g7_count( static function () use ( &$g7_c1, $g7_aff, $g7_from, $g7_to ) { $g7_c1 = Reports::partner_cached( 'campaigns', $g7_aff, $g7_from, $g7_to ); }, $g7_vt );
$g7_c2 = null;
$g7_q2 = $g7_count( static function () use ( &$g7_c2, $g7_aff, $g7_from, $g7_to ) { $g7_c2 = Reports::partner_cached( 'campaigns', $g7_aff, $g7_from, $g7_to ); }, $g7_vt );
check( 'R14-D1 campaigns: first call reads the visits once, a reload reads none (same rows)', array( 1, 0, true ), array( $g7_q1, $g7_q2, $g7_c1 === $g7_c2 ) );
check( 'R14-D1 cached rows as uncached (only this partner: 6+3+2 visits)', array( Reports::partner_campaigns( $g7_aff, $g7_from, $g7_to ), 11 ), array( $g7_c1, array_sum( wp_list_pluck( $g7_c1, 'visits' ) ) ) );
$g7_s1 = Reports::partner_cached( 'summary', $g7_aff, $g7_from, $g7_to );
$g7_q3 = $g7_count( static function () use ( $g7_aff, $g7_from, $g7_to ) { Reports::partner_cached( 'summary', $g7_aff, $g7_from, $g7_to ); }, 'FROM ' );
check( 'R14-D1 summary cached as well: no query on a reload; other partner and period have own entries', array( 11, 0, 4, 0 ), array( $g7_s1['visits'], $g7_q3, Reports::partner_cached( 'summary', $g7_aff2, $g7_from, $g7_to )['visits'], Reports::partner_cached( 'summary', $g7_aff, '2020-01-01', '2020-01-31' )['visits'] ) );
$g7_visit( $g7_aff, 'insta', 'story1', 1 );
$g7_still = Reports::partner_cached( 'summary', $g7_aff, $g7_from, $g7_to )['visits'];
do_action( 'wpac_commission_created', 1 ); // A booking raises the generation (Reports::register_hooks()).
check( 'R14-D1 a new visit shows with the next time bucket, a booking at once', array( 11, 12 ), array( $g7_still, Reports::partner_cached( 'summary', $g7_aff, $g7_from, $g7_to )['visits'] ) );
check( 'R14-D1 the partner area reads the cached statistics (R14-D9: campaigns cached per page)', array( 2, 2, 0, 0 ), array( substr_count( $g7_src( 'src/Portal/Portal.php' ), "partner_cached( 'summary'" ), substr_count( $g7_src( 'src/Portal/Portal.php' ), 'Reports::partner_campaigns_page(' ), substr_count( $g7_src( 'src/Portal/Portal.php' ), 'Reports::partner_summary(' ), substr_count( $g7_src( 'src/Portal/Portal.php' ), 'Reports::partner_campaigns(' ) ) );

/* R14-D6: editor panel cached. ---------------------------------------------------------------- */
$g7_visit( $g7_aff, 'blog', '', 3, 4711 );
$g7_e1 = Reports::content_cached( 4711, 90 );
$g7_q4 = $g7_count( static function () { Reports::content_cached( 4711, 90 ); }, $g7_vt );
check( 'R14-D6 editor panel: 3 visits of the page, the next opening reads no visits', array( 3, 1, 0, true ), array( $g7_e1['visits'], $g7_e1['partners'], $g7_q4, false !== strpos( $g7_src( 'src/Content/Editor.php' ), 'Reports::content_cached( (int) $post->ID, 90 )' ) ) );

/* R14-D5: at most one generation raise per request, not autoloaded. --------------------------- */
$g7_gen = (int) get_option( Reports::CACHE_GEN );
Reports::flush_cache();
$g7_after_one = (int) get_option( Reports::CACHE_GEN );
$g7_writes    = $g7_count( static function () { for ( $i = 0; $i < 50; $i++ ) { Reports::flush_cache(); } }, 'wpac_reports_gen' );
$g7_after_50  = (int) get_option( Reports::CACHE_GEN );
Reports::cached( 'partners', $g7_from, $g7_to ); // A report cached after the raise …
Reports::flush_cache(); // … allows the next one (it may hold the older state).
check( 'R14-D5 50 bookings in one request: no further raise; a report cached afterwards allows the next', array( $g7_after_one, 0, $g7_after_one + 1 ), array( $g7_after_50, $g7_writes, (int) get_option( Reports::CACHE_GEN ) ) );
check( 'R14-D5 generation option written with autoload=false', array( true, false ), array( false !== strpos( $g7_src( 'src/Reports/Reports.php' ), 'update_option( self::CACHE_GEN, self::generation() + 1, false );' ), false !== strpos( $g7_src( 'src/Reports/Reports.php' ), 'update_option( self::CACHE_GEN, self::generation() + 1, true );' ) ) );
unset( $g7_gen );

/* R14-D2: rate-limit purge with time budget and continuation. --------------------------------- */
$g7_rl = t( 'rate_limits' );
q( "DELETE FROM {$g7_rl}" );
for ( $i = 0; $i < 12; $i++ ) {
	q( "INSERT INTO {$g7_rl} (rl_key, hits, expires_at) VALUES (UNHEX(MD5('g7-old-{$i}')), 1, " . ( time() - 100 ) . ')' );
}
q( "INSERT INTO {$g7_rl} (rl_key, hits, expires_at) VALUES (UNHEX(MD5('g7-live')), 1, " . ( time() + 600 ) . ')' );
add_filter( 'wpac_rate_limit_purge_batch', static function () { return 5; } );
add_filter( 'wpac_rate_limit_purge_budget', static function () { return 0.000001; } );
$GLOBALS['test_scheduled'] = array();
delete_option( RateLimiter::BACKLOG_OPTION );
$g7_n1   = RateLimiter::purge();
$g7_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_rl}" );
check( 'R14-D2 out of time after one batch: rest left, continuation scheduled, backlog noted', array( 5, 8, true, true ), array( $g7_n1, $g7_left, in_array( RateLimiter::PURGE_HOOK, $GLOBALS['test_scheduled'], true ), (bool) get_option( RateLimiter::BACKLOG_OPTION ) ) );
remove_all_filters( 'wpac_rate_limit_purge_budget' );
$g7_n2 = RateLimiter::purge(); // The continuation: no fixed upper bound of rows any more.
check( 'R14-D2 the continuation deletes the rest (live counter kept), clears the event and the backlog', array( 7, 1, false, false ), array( $g7_n2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_rl}" ), in_array( RateLimiter::PURGE_HOOK, $GLOBALS['test_scheduled'], true ), get_option( RateLimiter::BACKLOG_OPTION ) ) );
remove_all_filters( 'wpac_rate_limit_purge_batch' );
update_option( RateLimiter::BACKLOG_OPTION, time() - 7 * HOUR_IN_SECONDS );
$g7_mon = Monitor::checks();
delete_option( RateLimiter::BACKLOG_OPTION );
check( 'R14-D2 Monitor warns when the clean-up has not caught up for six hours', array( 'recommended', false ), array( isset( $g7_mon['rate_limits'] ) ? $g7_mon['rate_limits'][0] : '', isset( Monitor::checks()['rate_limits'] ) ) );
check( 'R14-D2 continuation hook registered and removed on deactivation/uninstall', array( true, true, true ), array( false !== strpos( $g7_src( 'src/Tracking/Tracker.php' ), 'add_action( RateLimiter::PURGE_HOOK' ), false !== strpos( $g7_src( 'src/Core/Cron.php' ), 'RateLimiter::PURGE_HOOK' ), false !== strpos( $g7_src( 'uninstall.php' ), "'wpac_purge_rate_limits'" ) ) );

/* R14-D3: partner erasure deletes visits in batches; the eraser reports done=false until done. */
$g7_er = AffiliateRepository::create( array( 'name' => 'G7 Erased', 'email' => 'g7-erased@example.de', 'status' => 'active' ) );
$g7_visit( $g7_er, 'x', '', 12 );
$g7_vcount = static function ( $aff ) use ( $wpdb, $g7_vt ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_vt} WHERE affiliate_id = " . (int) $aff ); };
add_filter( 'wpac_visit_erase_batch', static function () { return 5; } );
add_filter( 'wpac_erase_request_budget', static function () { return 0.000001; } );
$GLOBALS['test_scheduled'] = array();
$g7_r1 = Privacy::erase( 'g7-erased@example.de', 1 );
$g7_l1 = $g7_vcount( $g7_er );
check( 'R14-D3 first eraser call: partner anonymised, one batch of visits, done=false, rest listed and scheduled', array( false, 7, true, true, 'privacy_erasure' ), array( $g7_r1['done'], $g7_l1, in_array( $g7_er, (array) get_option( Privacy::VISITS_ERASE_OPTION ), true ), in_array( Privacy::VISITS_ERASE_HOOK, $GLOBALS['test_scheduled'], true ), (string) AffiliateRepository::find( $g7_er, true )->status_reason ) );
$g7_r2 = Privacy::erase( 'g7-erased@example.de', 2 );
$g7_r3 = Privacy::erase( 'g7-erased@example.de', 3 );
check( 'R14-D3 next pages continue for the same request (address already anonymised): done once nothing is left', array( false, true, 0, false, true ), array( $g7_r2['done'], $g7_r3['done'], $g7_vcount( $g7_er ), (bool) get_option( Privacy::VISITS_ERASE_OPTION ), $g7_r3['items_removed'] ) );
check( 'R14-D3 a later request for the address finds nothing to continue', true, Privacy::erase( 'g7-erased@example.de', 2 )['done'] );
// Background: retention anonymisation (erase_partner() from the daily run) leaves a rest for the event.
$g7_er2 = AffiliateRepository::create( array( 'name' => 'G7 Ended', 'email' => 'g7-ended@example.de', 'status' => 'terminated' ) );
$g7_visit( $g7_er2, 'y', '', 11 );
Privacy::erase_partner( AffiliateRepository::find( $g7_er2, true ), 0.000001 );
$g7_bg1 = $g7_vcount( $g7_er2 );
add_filter( 'wpac_visit_erase_budget', static function () { return 0; } );
$g7_bg  = Privacy::continue_visit_erasure();
check( 'R14-D3 background continuation deletes the rest and empties the list', array( 6, true, 0, false ), array( $g7_bg1, $g7_bg, $g7_vcount( $g7_er2 ), (bool) get_option( Privacy::VISITS_ERASE_OPTION ) ) );
remove_all_filters( 'wpac_visit_erase_batch' );
remove_all_filters( 'wpac_erase_request_budget' );
remove_all_filters( 'wpac_visit_erase_budget' );
check( 'R14-D3 no single DELETE of all visits of a partner left; event and option removed on uninstall', array( 0, true, true ), array( preg_match_all( "/->delete\\( Db::table\\( 'visits' \\)/", $g7_src( 'src/Privacy/Privacy.php' ) ), false !== strpos( $g7_src( 'uninstall.php' ), "'wpac_erase_visits'" ), false !== strpos( $g7_src( 'uninstall.php' ), "'wpac_visit_erasure_pending'" ) ) );

/* R14-D4: beacon route reachable for guests; probe; admin-ajax fallback. ---------------------- */
$g7_server = $_SERVER;
$g7_get    = $_GET;
$g7_err    = new WP_Error( 'rest_not_logged_in', 'REST API restricted to authenticated users.' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/wp-json/wpac/v1/touch';
$g7_own = RestApi::allow_touch_route( $g7_err );
$_SERVER['REQUEST_URI'] = '/shop/wp-json/wpac/v1/touch/';
$g7_sub = RestApi::allow_touch_route( $g7_err );
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';
$g7_other = RestApi::allow_touch_route( $g7_err );
$_SERVER['REQUEST_URI'] = '/index.php';
$_GET['rest_route']     = '/wpac/v1/touch';
$g7_plain = RestApi::allow_touch_route( $g7_err );
$_GET['rest_route']     = '/wpac/v1/payouts';
$g7_plain2 = RestApi::allow_touch_route( $g7_err );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['rest_route']        = '/wpac/v1/touch';
$g7_get_m = RestApi::allow_touch_route( $g7_err );
$_SERVER = $g7_server;
$_GET    = $g7_get;
check( 'R14-D4 rest_authentication_errors: only the own touch route (pretty, subfolder, ?rest_route) is let through, other routes keep the error', array( null, null, true, null, true, true, true ), array( $g7_own, $g7_sub, $g7_other === $g7_err, $g7_plain, $g7_plain2 === $g7_err, $g7_get_m === $g7_err, true === RestApi::allow_touch_route( true ) ) );
check( 'R14-D4 filter at priority 999, admin-ajax actions for guests and users', array( true, true, true ), array( false !== strpos( $g7_src( 'src/Rest/RestApi.php' ), "add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_touch_route' ), 999 );" ), false !== strpos( $g7_src( 'src/Rest/RestApi.php' ), "'wp_ajax_nopriv_wpac_touch'" ), false !== strpos( $g7_src( 'src/Rest/RestApi.php' ), "'wp_ajax_wpac_touch'" ) ) );

$g7_set( array( 'js_tracking' => 1 ) );
$g7_vis  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_vt}" );
$g7_rls  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_rl}" );
$g7_pr   = RestApi::touch_data( array( 'code' => RestApi::PROBE, 'probe' => RestApi::PROBE ), '' );
check( 'R14-D4 probe request answered {"ok":true} without writing a visit or counter', array( array( 'ok' => true ), $g7_vis, $g7_rls ), array( $g7_pr instanceof WP_REST_Response ? $g7_pr->data : null, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_vt}" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g7_rl}" ) ) );
$g7_code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $g7_aff2 );
add_filter( 'wpac_count_visit', static function () { return true; }, 99 ); // Bot and per-minute checks are not the subject here.
$g7_ajax = RestApi::touch_data( array( 'code' => $g7_code, 'campaign' => 'ajax', 'sub' => 'fb', 'content_id' => '0', 'path' => '/kurs/' ), '' ); // As $_POST of the fallback: strings only.
check( 'R14-D4 the fallback (string parameters) records the visit like the REST route', array( true, 1 ), array( $g7_ajax instanceof WP_REST_Response, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$g7_vt} WHERE affiliate_id = %d AND campaign = 'ajax' AND sub_id = 'fb'", $g7_aff2 ) ) ) );
remove_all_filters( 'wpac_count_visit' );
$g7_bad = RestApi::touch_data( array( 'code' => str_repeat( 'A', 101 ) ), '' );
check( 'R14-D4 fallback validates the code like the route arguments (1–100 characters)', array( 'rest_invalid_param', 'rest_invalid_param' ), array( is_wp_error( $g7_bad ) ? $g7_bad->get_error_code() : '', is_wp_error( $e = RestApi::touch_data( array(), '' ) ) ? $e->get_error_code() : '' ) );

$g7_http = static function ( $rest, $ajax ) {
	$GLOBALS['test_http_calls'] = array();
	$GLOBALS['test_http']       = static function ( $url, $args ) use ( $rest, $ajax ) {
		$r = false !== strpos( $url, 'admin-ajax.php' ) ? $ajax : $rest;
		if ( null === $r ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7' );
		}
		return array( 'response' => array( 'code' => $r ), 'body' => 200 === $r ? '{"ok":true}' : '{"code":"rest_forbidden"}' );
	};
};
delete_option( Tracker::PROBE_OPTION );
$g7_http( 200, 200 );
$g7_p_ok = Tracker::touch_probe( HOUR_IN_SECONDS );
$g7_ok_calls = $GLOBALS['test_http_calls'];
$g7_http( 401, 200 );
$g7_cached = Tracker::touch_probe( HOUR_IN_SECONDS );
check( 'R14-D4 probe: REST answers → ok (one loopback to /wpac/v1/touch); result kept for an hour', array( 'ok', array( 'http://example.test/wp-json/wpac/v1/touch' ), 'ok', array() ), array( $g7_p_ok, $g7_ok_calls, $g7_cached, $GLOBALS['test_http_calls'] ) );
update_option( Tracker::PROBE_OPTION, array( 'status' => 'ok', 'time' => time() - 7200 ) ); // Older than an hour: checked again.
$g7_p_ajax = Tracker::touch_probe( HOUR_IN_SECONDS );
$g7_mon    = Monitor::checks();
$g7_sh     = Tracker::tracking_problems();
check( 'R14-D4 REST refused, admin-ajax answers → "ajax": Site Health and Monitor recommend allowing the route', array( 'ajax', 'recommended', true ), array( $g7_p_ajax, isset( $g7_mon['beacon'] ) ? $g7_mon['beacon'][0] : '', (bool) preg_grep( '/admin-ajax\.php/', wp_list_pluck( $g7_sh, 1 ) ) ) );
update_option( Tracker::PROBE_OPTION, array( 'status' => 'ok', 'time' => time() - 7200 ) );
$g7_http( 403, 403 );
$g7_p_block = Tracker::touch_probe( HOUR_IN_SECONDS );
$g7_mon     = Monitor::checks();
check( 'R14-D4 both refused → "blocked": critical in the Monitor (daily alert, health route)', array( 'blocked', 'critical', true ), array( $g7_p_block, isset( $g7_mon['beacon'] ) ? $g7_mon['beacon'][0] : '', isset( Monitor::problems( true )['beacon'] ) ) );
update_option( Tracker::PROBE_OPTION, array( 'status' => 'ok', 'time' => time() - 7200 ) );
$g7_http( null, null );
check( 'R14-D4 no loopback possible → "unknown", no warning (core Site Health reports loopbacks)', array( 'unknown', false ), array( Tracker::touch_probe( HOUR_IN_SECONDS ), isset( Monitor::checks()['beacon'] ) ) );
$g7_set( array( 'js_tracking' => 0 ) );
update_option( Tracker::PROBE_OPTION, array( 'status' => 'blocked', 'time' => time() ) );
check( 'R14-D4 beacon off: no probe result, no warning', array( '', false ), array( Tracker::touch_probe( HOUR_IN_SECONDS ), isset( Monitor::checks()['beacon'] ) ) );
delete_option( Tracker::PROBE_OPTION );
unset( $GLOBALS['test_http'] );
check( 'R14-D4 probe option removed on uninstall, refreshed hourly (every 6 h)', array( true, true ), array( false !== strpos( $g7_src( 'uninstall.php' ), "'wpac_touch_probe'" ), false !== strpos( $g7_src( 'src/Tracking/Tracker.php' ), "add_action( 'wpac_hourly', array( __CLASS__, 'refresh_touch_probe' ) );" ) ) );

/* R14-D7: guard in the beacon; WooCommerce cart only with a shop session cookie. -------------- */
$g7_js = Tracker::beacon_script( array( 'url' => 'http://example.test/wp-json/wpac/v1/touch', 'param' => 'partner', 'ref' => 0, 'beacon' => 1, 'consent' => 0, 'sub' => 'sub', 'ajax' => 'http://example.test/wp-admin/admin-ajax.php?action=wpac_touch' ) );
check( 'R14-D7/D4 beacon: sessionStorage guard (not for consent re-sends), fallback over admin-ajax on refusal', array( true, true, true ), array( false !== strpos( $g7_js, 'sessionStorage.getItem("wpac_seen")===s' ), false !== strpos( $g7_js, 'if(k){b.consent=1;}else{' ), false !== strpos( $g7_js, 'if(a.ok){ok();}else{alt();}' ) ) );
// Run the script in Node with stand-ins: first landing sends, a reload sends nothing, REST 403 → admin-ajax.
$g7_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' !== $g7_node ) {
	$g7_dir = sys_get_temp_dir() . '/wpac-g7-' . getmypid();
	@mkdir( $g7_dir );
	$g7_harness = 'var calls=[],store={},status=' . "%d" . ';global.location={search:"?partner=ANNA&sub=fb",pathname:"/kurs/"};global.document={referrer:"",getElementById:function(){return null;},addEventListener:function(){}};global.sessionStorage={getItem:function(k){return k in store?store[k]:null;},setItem:function(k,v){store[k]=String(v);}};global.window=global;global.fetch=function(u,o){calls.push(u.indexOf("admin-ajax")>0?"ajax:"+o.body:"rest");var st=u.indexOf("admin-ajax")>0?200:status;return Promise.resolve({ok:st===200,status:st});};'
		. 'function run(){eval(SCRIPT);}run();setTimeout(function(){run();setTimeout(function(){console.log(JSON.stringify({calls:calls,seen:store.wpac_seen||""}));},20);},20);';
	$g7_run = static function ( $status ) use ( $g7_dir, $g7_harness, $g7_js, $g7_node ) {
		$file = $g7_dir . '/b' . $status . '.js';
		file_put_contents( $file, str_replace( 'SCRIPT', json_encode( $g7_js ), sprintf( $g7_harness, $status ) ) );
		$out = json_decode( (string) shell_exec( escapeshellarg( $g7_node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' ), true );
		@unlink( $file );
		return $out;
	};
	$g7_ok   = $g7_run( 200 );
	$g7_deny = $g7_run( 403 );
	@rmdir( $g7_dir );
	check(
		'R14-D7/D4 beacon in Node: reload sends nothing after a successful touch; REST 403 → same data over admin-ajax (form-encoded), then no repeat',
		array( array( 'rest' ), 'ANNA||||fb|/kurs/', array( 'rest', 'ajax:code=ANNA&campaign=&utm_source=&utm_medium=&sub=fb&content_id=0&path=%2Fkurs%2F&referrer=' ) ),
		array( is_array( $g7_ok ) ? $g7_ok['calls'] : $g7_ok, is_array( $g7_ok ) ? $g7_ok['seen'] : '', is_array( $g7_deny ) ? $g7_deny['calls'] : $g7_deny )
	);
}
if ( isset( $GLOBALS['g1_wc'] ) ) {
	$GLOBALS['g1_wc']->session = null;
	$GLOBALS['g1_cart_loads']  = 0;
	$g7_set( array( 'js_tracking' => 1 ) );
	RestApi::touch_data( array( 'code' => $g7_code ), '' );
	$g7_no_cookie = $GLOBALS['g1_cart_loads'];
	$_COOKIE['wp_woocommerce_session_g7'] = 'x';
	RestApi::touch_data( array( 'code' => $g7_code ), '' );
	unset( $_COOKIE['wp_woocommerce_session_g7'] );
	$g7_set( array( 'js_tracking' => 0 ) );
	check( 'R14-D7 touch loads the cart only for guests with a WooCommerce session cookie', array( 0, 1 ), array( $g7_no_cookie, $GLOBALS['g1_cart_loads'] ) );
}

/* R14-D8: delay-JS exclusions and script attributes. ------------------------------------------ */
check(
	'R14-D8 exclusion lists get the keyword once (array, string, empty)',
	array( array( 'jquery', 'wpacBeacon' ), array( 'a', 'wpacBeacon' ), array( 'wpacBeacon' ), array( 'wpacBeacon' ) ),
	array( Tracker::delay_js_exclusions( array( 'jquery' ) ), Tracker::delay_js_exclusions( 'a' ), Tracker::delay_js_exclusions( null ), Tracker::delay_js_exclusions( array( 'wpacBeacon' ) ) )
);
check(
	'R14-D8 inline beacon tag: data-no-optimize, data-no-defer, data-cfasync=false, nowprocket; other tags unchanged',
	array( array( 'id' => 'wpac-beacon-js-after', 'data-no-optimize' => '1', 'data-no-defer' => '1', 'data-cfasync' => 'false', 'nowprocket' => true ), array( 'id' => 'other-js-after' ) ),
	array( Tracker::beacon_script_attributes( array( 'id' => 'wpac-beacon-js-after' ), '' ), Tracker::beacon_script_attributes( array( 'id' => 'other-js-after' ), '' ) )
);
$g7_tsrc = $g7_src( 'src/Tracking/Tracker.php' );
check( 'R14-D8 filters of WP Rocket, LiteSpeed, Perfmatters, FlyingPress and the script attribute filters', array( true, true, true, true, true ), array( false !== strpos( $g7_tsrc, "'rocket_delay_js_exclusions'" ), false !== strpos( $g7_tsrc, "'litespeed_optm_js_defer_exc'" ), false !== strpos( $g7_tsrc, "'perfmatters_delay_js_exclusions'" ), false !== strpos( $g7_tsrc, "'flying_press_exclude_from_delay:js'" ), false !== strpos( $g7_tsrc, "'wp_inline_script_attributes'" ) ) );

/* Handbook. ----------------------------------------------------------------------------------- */
$g7_doc = $g7_src( 'docs/HANDBOOK.md' );
check( 'R14-D handbook: index, cache, purge/erasure continuation, beacon fallback and delay-JS documented', array( true, true, true, true ), array( false !== strpos( $g7_doc, 'wpac_rate_limit_purge_budget' ), false !== strpos( $g7_doc, 'wpac_erase_request_budget' ), false !== strpos( $g7_doc, 'admin-ajax.php?action=wpac_touch' ), false !== strpos( $g7_doc, 'flying_press_exclude_from_delay:js' ) ) );

update_option( 'wpac_settings', $g7_settings );
Settings::flush_cache();
