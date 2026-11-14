<?php
/**
 * Integration checks of work package J4 (R5-O47): the remaining admin lists (payouts, audit log,
 * creatives, rules, retry queue) sort by whitelisted columns, search and page on the server.
 * Included from tests/integration/run.php (uses its check() helper and database). The rendered
 * markup (sort links, search box) is checked in r8-render.php with its template stubs.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Admin\Tables\AuditTable;
use WPAffiliateCaptain\Admin\Tables\CreativesTable;
use WPAffiliateCaptain\Admin\Tables\JobsTable;
use WPAffiliateCaptain\Admin\Tables\PayoutsTable;
use WPAffiliateCaptain\Admin\Tables\RulesTable;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Promotion\Creatives;

echo "J4 admin lists: sorting, search, paging\n";

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; // Minimal test version.
}
$j4_get = $_GET;
$j4_req = $_REQUEST;
$j4_now = Db::now();
$j4_aff = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'affiliates' ) . ' ORDER BY id LIMIT 1' );
$j4_ids = static function ( $rows ) {
	return array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
};

// Payouts: three test rows (removed again at the end, the account checks follow).
$j4_pay = array();
foreach ( array( array( 'J4X-0001', 300 ), array( 'J4X-0002', 100 ), array( 'J4X-0003', 200 ) ) as $j4_p ) {
	$wpdb->insert( t( 'payouts' ), array( 'number' => $j4_p[0], 'affiliate_id' => $j4_aff, 'period_end' => '2020-01-31', 'currency' => 'EUR', 'amount_minor' => $j4_p[1], 'status' => 'cancelled', 'sepa_batch' => 'J4BATCH', 'created_at' => $j4_now ) );
	$j4_pay[ $j4_p[0] ] = (int) $wpdb->insert_id;
}
list( $j4_rows, $j4_total ) = PayoutService::query( array( 'search' => 'J4X-', 'orderby' => 'amount_minor', 'order' => 'asc' ) );
list( $j4_desc ) = PayoutService::query( array( 'search' => 'J4X-', 'orderby' => 'amount_minor', 'order' => 'desc' ) );
check( 'R5-O47 payouts: search by document number, sorted by amount both ways', array( 3, array( $j4_pay['J4X-0002'], $j4_pay['J4X-0003'], $j4_pay['J4X-0001'] ), array( $j4_pay['J4X-0001'], $j4_pay['J4X-0003'], $j4_pay['J4X-0002'] ) ), array( $j4_total, $j4_ids( $j4_rows ), $j4_ids( $j4_desc ) ) );
list( , $j4_t1 ) = PayoutService::query( array( 'search' => 'J4X-0002' ) );
list( , $j4_t2 ) = PayoutService::query( array( 'search' => 'J4BATCH' ) );
list( , $j4_t3 ) = PayoutService::query( array( 'search' => 'J4X_%' ) ); // LIKE wildcards are literal.
check( 'R5-O47 payouts: search one number, the SEPA batch; LIKE wildcards are escaped', array( 1, 3, 0 ), array( $j4_t1, $j4_t2, $j4_t3 ) );
$j4_name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . t( 'affiliates' ) . ' WHERE id = %d', $j4_aff ) );
list( $j4_by_name ) = PayoutService::query( array( 'search' => $j4_name, 'limit' => 0 ) );
check( 'R5-O47 payouts: search by partner name', true, '' !== $j4_name && in_array( $j4_pay['J4X-0002'], $j4_ids( $j4_by_name ), true ) );

// Orderby whitelist: injected column or direction falls back to the default order, no SQL error.
$wpdb->last_error = '';
list( $j4_inj ) = PayoutService::query( array( 'search' => 'J4X-', 'orderby' => 'amount_minor; DROP TABLE x', 'order' => 'asc, (SELECT 1)' ) );
check( 'R5-O47 payouts: injected orderby/order rejected (default newest first, no SQL error)', array( array( $j4_pay['J4X-0003'], $j4_pay['J4X-0002'], $j4_pay['J4X-0001'] ), '' ), array( $j4_ids( $j4_inj ), (string) $wpdb->last_error ) );
$_GET = array( 'page' => 'wpac-payouts', 'orderby' => 'amount_minor`, (SELECT 1)', 'order' => 'asc' );
$j4_tbl = new PayoutsTable( true );
$j4_s1  = $j4_tbl->sort_args();
$_GET   = array( 'page' => 'wpac-payouts', 'orderby' => 'profile_snapshot', 'order' => 'ASC' );
$j4_s2  = $j4_tbl->sort_args();
$_GET   = array( 'page' => 'wpac-payouts', 'orderby' => 'period_end', 'order' => 'nonsense' );
$j4_s3  = $j4_tbl->sort_args();
check( 'R5-O47 list table: only sortable columns pass (others → default), order asc|desc', array( array( 'orderby' => '', 'order' => 'asc' ), array( 'orderby' => '', 'order' => 'asc' ), array( 'orderby' => 'period_end', 'order' => 'desc' ) ), array( $j4_s1, $j4_s2, $j4_s3 ) );

// Payout table: search, sort and paging from the request.
$_GET = $_REQUEST = array( 'page' => 'wpac-payouts', 's' => 'J4X-', 'orderby' => 'amount_minor', 'order' => 'asc' );
$j4_tbl = new PayoutsTable( true );
$j4_tbl->prepare_items();
check( 'R5-O47 payout table: search and sorting from the request', array( array( $j4_pay['J4X-0002'], $j4_pay['J4X-0003'], $j4_pay['J4X-0001'] ), 3 ), array( $j4_ids( $j4_tbl->items ), (int) $j4_tbl->get_pagination_arg( 'total_items' ) ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-payouts', 's' => 'J4X-', 'status' => 'paid' );
$j4_tbl = new PayoutsTable( true, 'paid' );
$j4_tbl->prepare_items();
check( 'R5-O47 payout table: status filter kept with the search', array(), $j4_ids( $j4_tbl->items ) );
foreach ( $j4_pay as $j4_id ) {
	$wpdb->delete( t( 'payouts' ), array( 'id' => $j4_id ) );
}

// Audit log: 105 entries of a test area → two pages of 100; sort, search; order filter old and new.
for ( $j4_i = 1; $j4_i <= 105; $j4_i++ ) {
	Audit::log( 'jfour.entry', 'job', $j4_i, array( 'reason' => 'J4 needle ' . ( 0 === $j4_i % 50 ? 'special' : 'plain' ) ) );
}
Audit::log( 'jfour.order', 'order', 0, array( 'order' => '987654' ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-audit', 'audit_action' => 'jfour.entry' );
$j4_tbl = new AuditTable( AuditPage::filters() );
$j4_tbl->prepare_items();
$j4_p1 = $j4_ids( $j4_tbl->items );
$_GET = $_REQUEST = array( 'page' => 'wpac-audit', 'audit_action' => 'jfour.entry', 'paged' => '2' );
$j4_tbl = new AuditTable( AuditPage::filters() );
$j4_tbl->prepare_items();
$j4_p2 = $j4_ids( $j4_tbl->items );
check( 'R5-O47 audit table: real paging (100 per page, total and page count)', array( 100, 5, 105, 2, array() ), array( count( $j4_p1 ), count( $j4_p2 ), (int) $j4_tbl->get_pagination_arg( 'total_items' ), (int) $j4_tbl->get_pagination_arg( 'total_pages' ), array_intersect( $j4_p1, $j4_p2 ) ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-audit', 'audit_action' => 'jfour.entry', 'orderby' => 'created_at', 'order' => 'asc' );
$j4_tbl = new AuditTable( AuditPage::filters() );
$j4_tbl->prepare_items();
check( 'R5-O47 audit table: sorted oldest first', true, $j4_ids( $j4_tbl->items ) === array_slice( array_reverse( array_merge( $j4_p1, $j4_p2 ) ), 0, 100 ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-audit', 'audit_action' => 'jfour.', 's' => 'needle special' );
$j4_tbl = new AuditTable( AuditPage::filters() );
$j4_tbl->prepare_items();
check( 'R5-O47 audit table: search in the details', 2, count( $j4_tbl->items ) );
list( , $j4_t ) = Audit::query_paged( array( 'action' => 'jfour.', 'orderby' => 'details', 'sort_order' => 'asc; DROP' ) );
check( 'R5-O47 audit: orderby outside the list is ignored', array( 106, '' ), array( $j4_t, (string) $wpdb->last_error ) );
$_GET = array( 'page' => 'wpac-audit', 'order' => '#987654' );
$j4_f_old = AuditPage::filters()['order'];
$_GET = array( 'page' => 'wpac-audit', 'order_ref' => '987654', 'order' => 'asc' );
$j4_f_new = AuditPage::filters()['order'];
$_GET = array( 'page' => 'wpac-audit', 'order' => 'desc' );
$j4_f_dir = AuditPage::filters()['order'];
check( 'R5-O47 audit: order number as order_ref, old links with order=<number> keep working, order=asc|desc is the sort', array( '987654', '987654', '' ), array( $j4_f_old, $j4_f_new, $j4_f_dir ) );
check( 'R5-O47 audit: query() still returns the rows only', 1, count( Audit::query( array( 'order' => '987654', 'limit' => 10 ) ) ) );
$wpdb->query( 'DELETE FROM ' . t( 'audit' ) . " WHERE action LIKE 'jfour.%'" );

// Creatives: search and sort; default keeps the versions of a creative together.
$j4_cr = array();
foreach ( array( 'J4 Zebra banner', 'J4 Alpha text', 'J4 Mango mail' ) as $j4_i => $j4_title ) {
	$wpdb->insert( t( 'creatives' ), array( 'family_id' => 900000 + $j4_i, 'version' => 1, 'title' => $j4_title, 'type' => 'text', 'content' => 'J4 creative ' . $j4_i, 'status' => 'draft', 'created_at' => $j4_now ) );
	$j4_cr[] = (int) $wpdb->insert_id;
}
list( $j4_rows, $j4_total ) = Creatives::query( array( 'search' => 'J4 ', 'orderby' => 'title', 'order' => 'asc' ) );
list( $j4_def ) = Creatives::query( array( 'search' => 'J4 ' ) );
list( $j4_inj ) = Creatives::query( array( 'search' => 'J4 ', 'orderby' => 'title DESC, (SELECT SLEEP(1))', 'order' => 'asc' ) );
check( 'R5-O47 creatives: search, sort by title, default newest creative first, injection → default', array( 3, array( $j4_cr[1], $j4_cr[2], $j4_cr[0] ), array( $j4_cr[2], $j4_cr[1], $j4_cr[0] ), array( $j4_cr[2], $j4_cr[1], $j4_cr[0] ) ), array( $j4_total, $j4_ids( $j4_rows ), $j4_ids( $j4_def ), $j4_ids( $j4_inj ) ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-creatives', 's' => 'Mango' );
$j4_tbl = new CreativesTable();
$j4_tbl->prepare_items();
check( 'R5-O47 creative table: search from the request', array( $j4_cr[2] ), $j4_ids( $j4_tbl->items ) );
foreach ( $j4_cr as $j4_id ) {
	$wpdb->delete( t( 'creatives' ), array( 'id' => $j4_id ) );
}

// Rules: search in note/products, sort by priority; default is the resolution order of all().
$j4_rl = array();
foreach ( array( 5, 1, 9 ) as $j4_prio ) {
	$wpdb->insert( t( 'rules' ), array( 'scope' => 'affiliate', 'scope_id' => 999999, 'target' => 'sku', 'target_value' => 'J4SKU-' . $j4_prio, 'rate_type' => 'percent', 'rate' => '1', 'priority' => $j4_prio, 'active' => 0, 'note' => 'J4 rule', 'created_at' => $j4_now, 'updated_at' => $j4_now ) );
	$j4_rl[ $j4_prio ] = (int) $wpdb->insert_id;
}
list( $j4_rows, $j4_total ) = RuleRepository::query( array( 'search' => 'J4 rule', 'orderby' => 'priority', 'order' => 'asc' ) );
list( $j4_def ) = RuleRepository::query( array( 'limit' => 0 ) );
list( , $j4_sku ) = RuleRepository::query( array( 'search' => 'J4SKU-9' ) );
check( 'R5-O47 rules: search, sort by priority, default as the resolution order', array( 3, array( $j4_rl[1], $j4_rl[5], $j4_rl[9] ), $j4_ids( RuleRepository::all() ), 1 ), array( $j4_total, $j4_ids( $j4_rows ), $j4_ids( $j4_def ), $j4_sku ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-rules', 's' => 'J4 rule', 'orderby' => 'priority', 'order' => 'desc' );
$j4_tbl = new RulesTable( array(), array(), 'EUR' );
$j4_tbl->prepare_items();
check( 'R5-O47 rule table: search and sorting from the request', array( $j4_rl[9], $j4_rl[5], $j4_rl[1] ), $j4_ids( $j4_tbl->items ) );
foreach ( $j4_rl as $j4_id ) {
	$wpdb->delete( t( 'rules' ), array( 'id' => $j4_id ) );
}

// Retry queue: status filter, search, sort; done jobs stay out.
$j4_jb = array();
foreach ( array( array( 'failed', 3 ), array( 'queued', 1 ), array( 'done', 2 ) ) as $j4_i => $j4_j ) {
	$wpdb->insert( t( 'jobs' ), array( 'type' => 'j4test', 'idempotency_key' => 'j4test:' . $j4_i, 'status' => $j4_j[0], 'attempts' => $j4_j[1], 'last_error' => 'J4 boom', 'created_at' => $j4_now, 'updated_at' => $j4_now, 'next_attempt_at' => '2099-01-01 00:00:00' ) );
	$j4_jb[ $j4_j[0] ] = (int) $wpdb->insert_id;
}
list( $j4_rows, $j4_total ) = Jobs::query_paged( array( 'search' => 'j4test:', 'orderby' => 'attempts', 'order' => 'asc' ) );
list( , $j4_failed ) = Jobs::query_paged( array( 'search' => 'J4 boom', 'status' => 'failed' ) );
check( 'R5-O47 retry queue: search, sort by attempts, status filter, done jobs not listed', array( 2, array( $j4_jb['queued'], $j4_jb['failed'] ), 1 ), array( $j4_total, $j4_ids( $j4_rows ), $j4_failed ) );
$_GET = $_REQUEST = array( 'page' => 'wpac-integrations', 'tab' => 'queue', 's' => 'j4test', 'orderby' => 'attempts', 'order' => 'desc' );
$j4_tbl = new JobsTable( 'nonsense' );
$j4_tbl->prepare_items();
check( 'R5-O47 job table: unknown status filter ignored, sorting from the request', array( $j4_jb['failed'], $j4_jb['queued'] ), $j4_ids( $j4_tbl->items ) );
foreach ( $j4_jb as $j4_id ) {
	$wpdb->delete( t( 'jobs' ), array( 'id' => $j4_id ) );
}

// Screen options: rows per page of the new lists are stored within 1–500, others untouched.
check( 'R5-O47 screen options per page for payouts, creatives, rules and audit (1–500)', array( 500, 1, 20, 'keep' ), array( Admin::save_screen_option( false, 'wpac_audit_per_page', 9999 ), Admin::save_screen_option( false, 'wpac_payouts_per_page', 0 ), Admin::save_screen_option( false, 'wpac_rules_per_page', '20' ), Admin::save_screen_option( 'keep', 'other_per_page', 7 ) ) );

$_GET     = $j4_get;
$_REQUEST = $j4_req;
