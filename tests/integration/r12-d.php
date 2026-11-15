<?php
/**
 * Integration checks of audit panel round 12, part D: schema 13 (indexes), audit log filters and
 * totals, report cache, deleted WooCommerce refunds, currencies, the contract action path,
 * PublicApi validation, cache detection, option priming, ABSPATH guards, blocks and CI.
 * Included from tests/integration/run.php before r8-render.php (uses the G3 WooCommerce stand-ins).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\PublicApi;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Sales\ContractApi;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R12 D: schema 13, audit/report performance, refunds, currencies, API validation\n";

global $wpdb;
$r12_root = dirname( __DIR__, 2 );
$r12_src  = static function ( $f ) use ( $r12_root ) {
	return (string) file_get_contents( $r12_root . '/' . $f );
};
$r12_keys = static function ( $table ) use ( $wpdb ) {
	return array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW INDEX FROM ' . Db::table( $table ), 2 ) );
};

/* R12-M21/M22: schema 13 with the new indexes, added by the migration. ---------------------- */
check( 'R12-M21 schema version 13 everywhere (R14-D1: 14)', array( 14, 14, 14 ), array( Installer::DB_VERSION, WPAC_DB_VERSION, Installer::db_version() ) );
check( 'R12-M21/M22 indexes on audit (action,created_at) and commissions (source_order_id)', array( true, true ), array( in_array( 'action_created', $r12_keys( 'audit' ), true ), in_array( 'source_order_id', $r12_keys( 'commissions' ), true ) ) );
q( 'ALTER TABLE ' . Db::table( 'audit' ) . ' DROP INDEX action_created' );
q( 'ALTER TABLE ' . Db::table( 'commissions' ) . ' DROP INDEX source_order_id' );
check( 'R12-M21 verify_schema reports missing indexes (R13-M8: as warnings, not blockers)', array( array(), array( 'missing index ' . Db::table( 'commissions' ) . '.source_order_id', 'missing index ' . Db::table( 'audit' ) . '.action_created' ) ), array( Installer::verify_schema(), array_values( array_map( static function ( $w ) { return strstr( $w, ' (', true ); }, preg_grep( '/^missing index /', (array) get_option( 'wpac_schema_warnings' ) ) ) ) ) );
update_option( 'wpac_db_version', 12 );
Installer::install_schema();
check( 'R12-M21 migration 12 → 13 adds the indexes (dbDelta) and raises the version', array( true, true, Installer::DB_VERSION, array() ), array( in_array( 'action_created', $r12_keys( 'audit' ), true ), in_array( 'source_order_id', $r12_keys( 'commissions' ), true ), (int) get_option( 'wpac_db_version' ), Installer::verify_schema() ) );

/* R12-M21: order filter from indexed queries; totals cached but exact for new entries. ------- */
$r12_aff = AffiliateRepository::create( array( 'name' => 'R12 Partner', 'email' => 'r12-partner@example.de', 'status' => 'active' ) );
Audit::log( 'refund.unknown_items', 'order', 0, array( 'source' => 'woocommerce', 'order_id' => '1201201' ) );
Audit::log( 'attribution.reassigned', 'order', 0, array( 'source' => 'test', 'order' => 'PC-1201' ) );
Audit::log( 'attribution.flagged', 'affiliate', $r12_aff, array( 'source' => 'test', 'order_id' => 'PC-1201', 'flags' => array( 'x' ) ) );
$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $r12_aff, 'source' => 'test', 'source_order_id' => 'PC-1201', 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'pending', 'idempotency_key' => 'r12-pc-1201', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
Audit::log( 'commission.reopened', 'commission', (int) $wpdb->insert_id, array( 'reason' => 'r12' ) );
Audit::log( 'commission.reopened', 'commission', 99999999, array( 'reason' => 'other' ) );
Audit::log( 'note.added', 'affiliate', $r12_aff, array( 'text' => 'mentions "order":"PC-1201" in another object' ) );
check(
	'R12-M21 order filter (non-numeric number): order event, flag and commission event, nothing else',
	array( array( 'attribution.flagged', 'attribution.reassigned', 'commission.reopened' ), 3 ),
	array( ( static function ( $a ) { $a = array_values( array_unique( $a ) ); sort( $a ); return $a; } )( wp_list_pluck( Audit::query( array( 'order' => 'PC-1201', 'limit' => 50 ) ), 'action' ) ), Audit::page( array( 'order' => 'PC-1201' ) )[1] )
);
check( 'R12-M21 numeric order number: event via object_type/object_id', array( 'refund.unknown_items' ), wp_list_pluck( Audit::query( array( 'order' => '1201201' ) ), 'action' ) );
check( 'R12-M21 unknown order: nothing (no fallback to all)', array( array(), 0 ), array( Audit::query( array( 'order' => 'R12-NONE' ) ), Audit::page( array( 'order' => 'R12-NONE' ) )[1] ) );
check( 'R12-M21 order filter uses no LIKE over all details', false, (bool) preg_match( '/OR details LIKE %s OR details LIKE %s OR/', $r12_src( 'src/Core/Audit.php' ) ) );
$r12_t1 = Audit::page( array( 'limit' => 1 ) )[1];
check( 'R12-M21 unfiltered total cached in one transient', array( true, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) ) ), array( is_array( get_transient( 'wpac_audit_totals' ) ), $r12_t1 ) );
Audit::log( 'r12.new', 'setup', 0 );
check( 'R12-M21 a new entry counts at once (newest ID in the key)', $r12_t1 + 1, Audit::page( array( 'limit' => 1 ) )[1] );
$r12_cache = get_transient( 'wpac_audit_totals' );
check( 'R12-M21 the transient keeps at most 10 counts', true, count( $r12_cache ) <= 10 );
$r12_sorted = Audit::page( array( 'search' => 'r12', 'orderby' => 'action', 'sort' => 'asc', 'limit' => 100 ) )[0];
$r12_actions = wp_list_pluck( $r12_sorted, 'action' );
$r12_copy    = $r12_actions;
sort( $r12_copy );
check( 'R12-M21 sort by action along the index (action, created_at, id same direction)', $r12_copy, $r12_actions );

/* R12-M23: report cache with time buckets, emptied by bookings; REST uses it. ---------------- */
Reports::register_hooks(); // Plugin::boot() is not run by the test runner.
$r12_from = gmdate( 'Y-m-01' );
$r12_to   = gmdate( 'Y-m-d' );
$r12_rep  = Reports::cached( 'partners', $r12_from, $r12_to );
$r12_n1   = Reports::cache_name( 'partners', $r12_from, $r12_to );
$r12_c1   = get_transient( $r12_n1 );
q( 'INSERT INTO ' . t( 'visits' ) . " (affiliate_id, created_at) VALUES ({$r12_aff}, '" . Db::now() . "')" );
check( 'R12-M23 a new visit does not create a new cache entry (same bucket)', array( $r12_rep, $r12_n1, true ), array( Reports::cached( 'partners', $r12_from, $r12_to ), Reports::cache_name( 'partners', $r12_from, $r12_to ), is_array( $r12_c1 ) && $r12_c1 === get_transient( $r12_n1 ) ) );
Reports::cached( 'campaign', $r12_from, $r12_to );
check( 'R12-M23/R13-N12 each report has its own transient', array( true, true ), array( $r12_n1 !== Reports::cache_name( 'campaign', $r12_from, $r12_to ), is_array( get_transient( Reports::cache_name( 'campaign', $r12_from, $r12_to ) ) ) ) );
do_action( 'wpac_commission_created', 1 );
check( 'R12-M23 a booking empties the report cache (R13-N12: new generation in the key)', array( false, false ), array( $r12_n1 === Reports::cache_name( 'partners', $r12_from, $r12_to ), get_transient( Reports::cache_name( 'partners', $r12_from, $r12_to ) ) ) );
$r12_rest = \WPAffiliateCaptain\Rest\RestApi::report( new WP_REST_Request( array( 'view' => 'campaign', 'from' => $r12_from, 'to' => $r12_to ) ) );
check( 'R12-M23 the REST report is served from the same cache', array( true, true ), array( is_array( $r12_rest['rows'] ), is_array( get_transient( Reports::cache_name( 'campaign', $r12_from, $r12_to ) ) ) ) );
check( 'R12-M23 no visit fingerprint in the key any more', false, false !== strpos( $r12_src( 'src/Reports/Reports.php' ), "'visits' => 'MAX(id)'" ) );

/* R12-M8: a deleted WooCommerce refund books its deduction back. ---------------------------- */
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $r12_aff, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'r12' );
$r12_sum = static function ( $order ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . " WHERE source = 'woocommerce' AND source_order_id = %s AND status <> 'rejected'", (string) $order ) );
};
$r12_o = new G3_Order( 1208801, array( 1 => new G3_Item( '100.00' ) ), '100.00', array( '_wpac_partner_code' => CodeRepository::main_code( $r12_aff ) ) );
$GLOBALS['za_orders'][1208801] = $r12_o;
OrderSync::sync_order( 1208801 );
$r12_ref = new G3_Refund( 1208901, '20.00', array( new G3_Item( '-20.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) );
$r12_o->refunds[] = $r12_ref;
$GLOBALS['za_orders'][1208901] = $r12_ref;
OrderSync::sync_refund( 1208801, 1208901 );
check( 'R12-M8 refund of 20 € deducted (10 € − 2 €)', 800, $r12_sum( 1208801 ) );
$r12_o->refunds = array(); // Deleted in WooCommerce.
unset( $GLOBALS['za_orders'][1208901] );
OrderSync::refund_deleted( 1208901, 1208801 );
OrderSync::refund_deleted( 1208901, 1208801 ); // The hook may fire twice: books nothing more.
check( 'R12-M8 deleted refund: the deduction is booked again, once, with an order note', array( 1000, true ), array( $r12_sum( 1208801 ), false !== strpos( (string) end( $r12_o->notes ), 'was booked again' ) ) );
check( 'R12-M8 audit entry for the order', 1, count( array_filter( Audit::query( array( 'order' => '1208801', 'limit' => 50 ) ), static function ( $r ) { return 'commission.refund_deleted' === $r->action; } ) ) );
check( 'R12-M8 hook registered on woocommerce_refund_deleted', true, false !== strpos( $r12_src( 'src/Integrations/WooCommerce.php' ), "add_action( 'woocommerce_refund_deleted', array( OrderSync::class, 'refund_deleted' ), 10, 2 );" ) );
$wpdb->insert( t( 'jobs' ), array( 'type' => 'refund', 'idempotency_key' => 'refund:woocommerce:1208801:1208902', 'status' => 'queued', 'payload' => '{}', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
OrderSync::refund_deleted( 1208902, 1208801 );
check( 'R12-M8 a queued refund that was deleted is not booked later', true, 'queued' !== $wpdb->get_var( 'SELECT status FROM ' . t( 'jobs' ) . " WHERE idempotency_key = 'refund:woocommerce:1208801:1208902'" ) );

/* R12-M9: WooCommerce currencies are accepted. ---------------------------------------------- */
if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
	function get_woocommerce_currencies() { return array( 'EUR' => 'Euro', 'IRT' => 'Iranian toman', 'BTC' => 'Bitcoin', 'GGP' => 'Guernsey pound', 'xx1' => 'bad' ); }
}
$r12_cur = ContractApi::currencies();
check( 'R12-M9 currencies: ISO list (incl. ZWG/XCG) plus WooCommerce codes, nothing malformed', array( true, true, true, true, false ), array( in_array( 'IRT', $r12_cur, true ), in_array( 'BTC', $r12_cur, true ), in_array( 'ZWG', $r12_cur, true ), in_array( 'XCG', $r12_cur, true ), in_array( 'XX1', $r12_cur, true ) ) );
$r12_norm = static function ( $cur ) {
	try {
		return ContractApi::normalize_sale( array( 'source' => 'test', 'order_id' => 'r12-cur', 'currency' => $cur, 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) )['currency'];
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
};
check( 'R12-M9 a sale in IRT is valid, an unknown code is refused', array( 'IRT', 'currency must be an ISO 4217 code' ), array( $r12_norm( 'irt' ), $r12_norm( 'QQQ' ) ) );
$r12_settings = WPAffiliateCaptain\Core\Settings::sanitize( array( 'currency' => 'QQQ' ) );
check( 'R12-M9 settings refuse a currency the contract would refuse', 'QQQ' !== $r12_settings['currency'], true );

/* R12-M10: errors on the action path are logged and listed. --------------------------------- */
delete_transient( ContractApi::REJECTED );
do_action( 'wpac_v1_record_sale', array( 'source' => 'r12addon', 'order_id' => 'R12-A1', 'currency' => 'QQQ', 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) );
$r12_rej = ContractApi::rejected();
check( 'R12-M10 refused action payload remembered with source, order and code', array( 1, 'wpac_v1_record_sale', 'r12addon', 'R12-A1', 'wpac_invalid_currency' ), array( count( $r12_rej ), $r12_rej[0]['hook'], $r12_rej[0]['source'], $r12_rej[0]['order'], $r12_rej[0]['code'] ) );
check( 'R12-M10 shown in the monitor checks (Site Health / dashboard)', 'recommended', \WPAffiliateCaptain\Core\Monitor::checks()['contract_rejected'][0] ?? '' );
ContractApi::action_result( 'wpac_v1_record_sale', array(), array( 'status' => 'booked' ) );
ContractApi::action_result( 'wpac_v1_record_sale', array(), new WP_Error( 'wpac_queued', 'q' ) );
check( 'R12-M10 successes and queued payloads are not listed', 1, count( ContractApi::rejected() ) );

/* R12-M11: strict PublicApi arguments. ------------------------------------------------------- */
$r12_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
check(
	'R12-M11 affiliate_id -5 / "abc" / 1.5 / array refused instead of listing all partners',
	array( 'wpac_invalid_affiliate_id', 'wpac_invalid_affiliate_id', 'wpac_invalid_affiliate_id', 'wpac_invalid_affiliate_id', 'ok', 'ok' ),
	array( $r12_code( wpac_get_commissions( array( 'affiliate_id' => -5 ) ) ), $r12_code( wpac_get_commissions( array( 'affiliate_id' => 'abc' ) ) ), $r12_code( wpac_get_commissions( array( 'affiliate_id' => 1.5 ) ) ), $r12_code( wpac_get_commissions( array( 'affiliate_id' => array( 1 ) ) ) ), $r12_code( wpac_get_commissions( array( 'affiliate_id' => (string) $r12_aff ) ) ), $r12_code( wpac_get_commissions( array( 'affiliate_id' => '' ) ) ) )
);
check(
	'R12-M11 arrays refused as wpac_invalid_<arg> (no "Array to string")',
	array( 'wpac_invalid_status', 'wpac_invalid_search', 'wpac_invalid_kind', 'wpac_invalid_page', 'wpac_invalid_group', 'wpac_invalid_per_page' ),
	array( $r12_code( wpac_get_affiliates( array( 'status' => array( 'active' ) ) ) ), $r12_code( wpac_get_affiliates( array( 'search' => array( 'x' ) ) ) ), $r12_code( wpac_get_commissions( array( 'kind' => array( 'sale' ) ) ) ), $r12_code( wpac_get_commissions( array( 'page' => array( 2 ) ) ) ), $r12_code( wpac_get_affiliates( array( 'group' => '1e3' ) ) ), $r12_code( wpac_get_affiliates( array( 'per_page' => '20.5' ) ) ) )
);
$r12_far = wpac_get_commissions( array( 'page' => PHP_INT_MAX, 'per_page' => 100 ) );
$r12_one = wpac_get_commissions( array( 'page' => '1', 'per_page' => '100' ) );
check( 'R12-M11 page PHP_INT_MAX: empty items, real total (no overflow to page 1)', array( array(), $r12_one['total'], PHP_INT_MAX, true ), array( $r12_far['items'], $r12_far['total'], $r12_far['page'], $r12_one['total'] > 0 ) );
$r12_end = wpac_get_commissions( array( 'page' => (int) ceil( $r12_one['total'] / 100 ) + 1, 'per_page' => 100 ) );
check( 'R12-M11 page right behind the end: empty items', array(), $r12_end['items'] );

/* R12-M29: no-cache detection in widgets and block templates, filter. ----------------------- */
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); }
}
if ( ! function_exists( 'has_block' ) ) {
	function has_block( $name, $content = null ) { return false !== strpos( (string) $content, '<!-- wp:' . $name . ' ' ) || false !== strpos( (string) $content, '<!-- wp:' . $name . ' /-->' ); }
}
if ( ! function_exists( 'wp_is_block_theme' ) ) {
	function wp_is_block_theme() { return ! empty( $GLOBALS['r12_block_theme'] ); }
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet() { return 'r12theme'; }
}
if ( ! function_exists( 'get_block_template' ) ) {
	function get_block_template( $id, $type = 'wp_template' ) { return isset( $GLOBALS['r12_parts'][ $id ] ) ? (object) array( 'content' => $GLOBALS['r12_parts'][ $id ] ) : null; }
}
update_option( 'widget_block', array( 3 => array( 'content' => '<!-- wp:wpac/partner-welcome /-->' ), 4 => array( 'content' => 'nothing' ) ) );
update_option( 'widget_text', array( 2 => array( 'text' => 'Hallo [wpac_partner_welcome]' ) ) );
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array( 'block-3', 'text-2' ), 'sidebar-1' => array( 'block-4' ) ) );
$r12_w1 = Landing::widgets_have( 'wpac_partner_welcome', 'wpac/partner-welcome' );
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array( 'block-3', 'text-2' ), 'sidebar-1' => array( 'block-4', 'block-3' ) ) );
$r12_w2 = Landing::widgets_have( 'wpac_partner_welcome', 'wpac/partner-welcome' );
update_option( 'sidebars_widgets', array( 'footer' => array( 'text-2' ) ) );
$r12_w3 = Landing::widgets_have( 'wpac_partner_welcome', 'wpac/partner-welcome' );
check( 'R12-M29 welcome box in active widgets found (block and shortcode), inactive ones ignored', array( false, true, true ), array( $r12_w1, $r12_w2, $r12_w3 ) );
$GLOBALS['_wp_current_template_content'] = '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:post-content /-->';
$GLOBALS['r12_parts'] = array( 'r12theme//header' => '<!-- wp:wpac/partner-welcome {"text":"Hi %s"} /-->' );
$r12_b1 = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$GLOBALS['r12_block_theme'] = true;
$r12_b2 = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$GLOBALS['r12_parts'] = array();
Landing::flush_template_scan(); // R13-N12: the scan is cached per template; saving a part renews it.
$r12_b3 = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
check( 'R12-M29 block theme: welcome box in a template part found; classic theme or without box not', array( false, true, false ), array( $r12_b1, $r12_b2, $r12_b3 ) );
update_option( 'sidebars_widgets', array() );
$r12_seen = null;
add_filter( 'wpac_needs_nocache', static function ( $found, $sc, $block ) use ( &$r12_seen ) { $r12_seen = array( $found, $sc, $block ); return false; }, 10, 3 );
Landing::no_cache_welcome();
check( 'R12-M29 filter wpac_needs_nocache gets the result of the own checks', array( false, 'wpac_partner_welcome', 'wpac/partner-welcome' ), $r12_seen );
remove_all_filters( 'wpac_needs_nocache' );
unset( $GLOBALS['_wp_current_template_content'], $GLOBALS['r12_block_theme'] );

/* R12-M30/M31: edge cache hint, multisite cron hint. ----------------------------------------- */
$r12_keep = get_option( 'wpac_settings' );
$_SERVER['HTTP_CF_RAY'] = 'r12-test';
check( 'R13-N10 a CF-Ray header alone is no cache sign', '', Tracker::edge_cache() );
unset( $_SERVER['HTTP_CF_RAY'] );
$_SERVER['HTTP_X_VARNISH'] = 'r12-test';
check( 'R12-M30 Varnish detected from the request, beacon recommended while it is off', array( 'Varnish/Proxy', true ), array( Tracker::edge_cache(), false !== strpos( Tracker::edge_cache_hint(), 'Varnish/Proxy' ) ) );
update_option( 'wpac_settings', array_merge( (array) $r12_keep, array( 'js_tracking' => 1 ) ) );
WPAffiliateCaptain\Core\Settings::flush_cache();
check( 'R12-M30 no hint once the beacon is on', '', Tracker::edge_cache_hint() );
update_option( 'wpac_settings', $r12_keep );
WPAffiliateCaptain\Core\Settings::flush_cache();
unset( $_SERVER['HTTP_X_VARNISH'] );
check( 'R12-M30 nothing detected without cache signs', '', Tracker::edge_cache() );
check( 'R12-M31 network cron hint loops over the sites with --url', true, false !== strpos( WPAffiliateCaptain\Core\Monitor::network_cron_hint(), 'wp site list --field=url | xargs -I {} wp cron event run --due-now --url={}' ) );
check( 'R12-M31 admin notice and monitor use it on multisite', array( true, true ), array( false !== strpos( $r12_src( 'src/Admin/Admin.php' ), 'Monitor::network_cron_hint()' ), false !== strpos( $r12_src( 'src/Core/Monitor.php' ), "is_multisite() ? ' ' . self::network_cron_hint()" ) ) );

/* R12-N9: option priming, click path without SHOW FULL COLUMNS. ------------------------------ */
$r12_tracker = $r12_src( 'src/Tracking/Tracker.php' );
$r12_rv      = substr( $r12_tracker, strpos( $r12_tracker, 'private static function record_visit' ), 4000 );
check( 'R12-N9 record_visit writes with a prepared INSERT (no $wpdb->insert)', array( false, true ), array( false !== strpos( substr( $r12_rv, 0, strpos( $r12_rv, 'public static function is_bot' ) ), "\$wpdb->insert(\n" ), false !== strpos( $r12_rv, 'INSERT INTO {$table} (affiliate_id, code_id, campaign' ) ) );
check( 'R12-N9 status options primed (WordPress 6.4+, guarded)', true, false !== strpos( $r12_src( 'src/Plugin.php' ), "if ( ! function_exists( 'wp_prime_option_caches' ) ) {" ) );

/* R12-N11/N12/N13. --------------------------------------------------------------------------- */
$r12_noguard = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $r12_root . '/src' ) ) as $r12_f ) {
	if ( '.php' === substr( (string) $r12_f, -4 ) && false === strpos( (string) file_get_contents( (string) $r12_f ), "defined( 'ABSPATH' ) || exit;" ) ) {
		$r12_noguard[] = basename( (string) $r12_f );
	}
}
check( 'R12-N11 every shipped class file has the ABSPATH guard', array(), $r12_noguard );
check( 'R12-N12 cut_slug without mbstring falls back to a PCRE check; multibyte cut stays valid', array( true, true ), array( false !== strpos( $r12_src( 'src/Affiliates/AffiliateRepository.php' ), "function_exists( 'mb_check_encoding' ) ? mb_check_encoding( \$bytes, 'UTF-8' ) : 1 === preg_match( '//u', \$bytes )" ), 1 === preg_match( '//u', rawurldecode( AffiliateRepository::cut_slug( sanitize_title( str_repeat( 'Ж', 30 ) ), 50 ) ) ) ) );
check( 'R12-N13 role stored with the untranslated name, translated at init', array( 'Tax advisor (Affiliate Captain, read only)', true, true ), array( WPAffiliateCaptain\Core\Capabilities::TAX_ADVISOR_NAME, false !== strpos( $r12_src( 'src/Core/Capabilities.php' ), 'self::TAX_ADVISOR_NAME,' ), false !== strpos( $r12_src( 'src/Plugin.php' ), "'translate_role_name' ), 2 )" ) ) );

/* R12-M19: blocks load the portal CSS only where they are rendered. ------------------------- */
$r12_blocks = array();
foreach ( glob( $r12_root . '/blocks/*/block.json' ) as $r12_bj ) {
	$r12_json                              = json_decode( (string) file_get_contents( $r12_bj ), true );
	$r12_blocks[ basename( dirname( $r12_bj ) ) ] = array( isset( $r12_json['style'] ), $r12_json['editorStyle'] ?? '' );
}
check( 'R12-M19 no block.json "style" (loaded on every page of classic themes), "editorStyle" for the editor', array( 'partner-targets' => array( false, 'wpac-portal' ), 'partner-welcome' => array( false, 'wpac-portal' ), 'portal' => array( false, 'wpac-portal' ), 'registration' => array( false, 'wpac-portal' ) ), $r12_blocks );
check(
	'R12-M19 every render path enqueues the portal stylesheet itself',
	array( true, true, true, true ),
	array(
		false !== strpos( $r12_src( 'src/Portal/Portal.php' ), "\t\twp_enqueue_style( 'wpac-portal' );\n\t\t\\WPAffiliateCaptain\\Core\\Locale::enqueue_rtl( 'wpac-portal', 'portal' ); // R6-MS11.\n\t\twp_enqueue_script( 'wpac-portal' );" ),
		false !== strpos( $r12_src( 'src/Affiliates/Registration.php' ), "wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css'" ),
		false !== strpos( $r12_src( 'src/Promotion/Landing.php' ), "wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css'" ),
		false !== strpos( $r12_src( 'src/Content/Editor.php' ), "wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css'" ),
	)
);

/* R12-N20: CI and build. --------------------------------------------------------------------- */
$r12_ci = $r12_src( '.github/workflows/ci.yml' );
preg_match_all( '/- uses: actions\/checkout@[^\n]*\n((?:\s+[^-\s][^\n]*\n)*)/', $r12_ci . $r12_src( '.github/workflows/wordpress-org-deploy.yml' ), $r12_m );
$r12_without = count( array_filter( $r12_m[1], static function ( $w ) { return false === strpos( $w, 'persist-credentials: false' ); } ) );
check(
	'R12-N20 CI: previous release without the current tag and pre-releases; release only from main; SHA-256 in the release; no persisted credentials',
	array( true, true, true, 0 ),
	array(
		false !== strpos( $r12_ci, "grep -E '^v[0-9]+\\.[0-9]+\\.[0-9]+$' | grep -vxF \"\${current:-none}\"" ),
		false !== strpos( $r12_ci, 'git merge-base --is-ancestor "$GITHUB_SHA" origin/main' ),
		false !== strpos( $r12_ci, 'gh release create "$TAG" "$zip" "$zip.sha256"' ),
		$r12_without,
	)
);
check( 'R12-N20 build-zip.sh refuses a version without changelog entry; deploy checks the CI of the tag', array( true, true ), array( false !== strpos( $r12_src( 'bin/build-zip.sh' ), 'has no changelog entry' ), false !== strpos( $r12_src( '.github/workflows/wordpress-org-deploy.yml' ), '--workflow ci.yml --commit "$sha" --status success' ) ) );
