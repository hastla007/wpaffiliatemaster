<?php
/**
 * Integration checks of R5-O47 (native list patterns): audit log, payout list and creatives as
 * WP_List_Table with sortable columns (whitelisted ORDER BY, injected orderby/order ignored),
 * search (LIKE with escaped wildcards) and paging.
 *
 * Included from tests/integration/run.php after r8-render.php (uses check(), t(), r_render() and
 * the template stubs of that file).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Admin\Pages\CreativesPage;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Admin\Tables\AuditTable;
use WPAffiliateCaptain\Admin\Tables\CreativesTable;
use WPAffiliateCaptain\Admin\Tables\PayoutsTable;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Promotion\Creatives;

echo "R10 O47 list tables: sorting, search, whitelisting\n";

foreach ( array( 'wp_enqueue_media' => 'return true;', 'wp_trim_words' => 'return (string) $a[0];' ) as $o47_fn => $o47_body ) {
	if ( ! function_exists( $o47_fn ) ) {
		eval( 'function ' . $o47_fn . '( ...$a ) { ' . $o47_body . ' }' );
	}
}

/** IDs of rows, as ints. */
function o47_ids( $rows ) {
	return array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
}

/** Sortable column => orderby value of a list table (protected method). */
function o47_sortable( $table ) {
	$m = new ReflectionMethod( $table, 'get_sortable_columns' );
	$m->setAccessible( true );
	return array_map(
		static function ( $v ) {
			return is_array( $v ) ? $v[0] : $v;
		},
		$m->invoke( $table )
	);
}

$o47_bad = array( 'id; DROP TABLE x', '(SELECT 1)', 'created_at DESC, (SELECT SLEEP(1))', 'details' );

// ---------------------------------------------------------------- Audit log.
Audit::log( 'o47.zeta', 'affiliate', 470001, array( 'note' => 'o47 first' ) );
Audit::log( 'o47.alpha', 'payout', 470002, array( 'note' => 'o47 second' ) );
Audit::log( 'o47.mid', 'commission', 470003, array( 'note' => 'o47 third 100%' ) );
$o47_a = Audit::page( array( 'search' => 'o47.', 'orderby' => 'action', 'sort' => 'asc' ) );
check( 'R5-O47 audit search finds the entries, total counted', 3, $o47_a[1] );
check( 'R5-O47 audit sorted by action ascending', array( 'o47.alpha', 'o47.mid', 'o47.zeta' ), wp_list_pluck( $o47_a[0], 'action' ) );
$o47_a = Audit::page( array( 'search' => 'o47.', 'orderby' => 'action', 'sort' => 'desc' ) );
check( 'R5-O47 audit sorted by action descending', array( 'o47.zeta', 'o47.mid', 'o47.alpha' ), wp_list_pluck( $o47_a[0], 'action' ) );
$o47_a = Audit::page( array( 'search' => 'o47.', 'orderby' => 'object_type', 'sort' => 'asc' ) );
check( 'R5-O47 audit sorted by object type', array( 'affiliate', 'commission', 'payout' ), wp_list_pluck( $o47_a[0], 'object_type' ) );
$o47_default = o47_ids( Audit::page( array( 'search' => 'o47.' ) )[0] );
check( 'R5-O47 audit default: newest first', true, $o47_default[0] > $o47_default[1] && $o47_default[1] > $o47_default[2] );
foreach ( $o47_bad as $o47_ob ) {
	$o47_a = Audit::page( array( 'search' => 'o47.', 'orderby' => $o47_ob, 'sort' => 'asc; DELETE' ) );
	check( 'R5-O47 audit orderby injection ignored: ' . $o47_ob, $o47_default, o47_ids( $o47_a[0] ) );
}
check( 'R5-O47 audit search escapes LIKE wildcards (o4_. finds nothing)', 0, Audit::page( array( 'search' => 'o4_.' ) )[1] );
check( 'R5-O47 audit search for a literal percent sign', array( 'o47.mid' ), wp_list_pluck( Audit::page( array( 'search' => 'third 100%' ) )[0], 'action' ) );
check( 'R5-O47 audit search for a number finds the object ID', array( 'o47.alpha' ), array_values( array_filter( wp_list_pluck( Audit::page( array( 'search' => '470002' ) )[0], 'action' ), static function ( $a ) { return 0 === strpos( $a, 'o47.' ); } ) ) ); // Other entries may contain 470002 by chance (e.g. a hash).
check( 'R5-O47 audit search combines with the filters', array( 'o47.zeta' ), wp_list_pluck( Audit::page( array( 'search' => 'o47', 'object_type' => 'affiliate', 'object_id' => 470001 ) )[0], 'action' ) );
$o47_p = Audit::page( array( 'search' => 'o47.', 'orderby' => 'action', 'sort' => 'asc', 'limit' => 2, 'offset' => 2 ) );
check( 'R5-O47 audit paging: second page, total of all', array( array( 'o47.zeta' ), 3 ), array( wp_list_pluck( $o47_p[0], 'action' ), $o47_p[1] ) );
check( 'R5-O47 audit sortable columns are all in the SQL whitelist', array(), array_diff( o47_sortable( new AuditTable( array() ) ), Audit::sortable() ) );

// Table reads search, orderby and order from the request; the order number filter is "order_no".
$_GET = array( 'page' => 'wpac-audit', 's' => 'o47.', 'orderby' => 'action', 'order' => 'asc' );
check( 'R5-O47 audit: sort direction "asc" is not taken as an order number', '', AuditPage::filters()['order'] );
$o47_t = new AuditTable( AuditPage::filters() );
$o47_t->prepare_items();
check( 'R5-O47 audit table sorts and searches from the request', array( 'o47.alpha', 'o47.mid', 'o47.zeta' ), wp_list_pluck( $o47_t->items, 'action' ) );
$_GET = array( 'page' => 'wpac-audit', 's' => 'o47.', 'orderby' => 'action`; DROP TABLE x; --', 'order' => 'asc' );
$o47_t = new AuditTable( AuditPage::filters() );
$o47_t->prepare_items();
check( 'R5-O47 audit table: injected orderby falls back to the ID', array_reverse( $o47_default ), o47_ids( $o47_t->items ) );
$_GET = array( 'page' => 'wpac-audit', 'order_no' => '#4711' );
check( 'R5-O47 audit order number filter in order_no', '4711', AuditPage::filters()['order'] );
$_GET = array( 'page' => 'wpac-audit', 'order' => '4711' );
check( 'R5-O47 audit order number in the old "order" parameter still works', '4711', AuditPage::filters()['order'] );
$_GET = array( 'page' => 'wpac-audit', 's' => 'o47.', 'orderby' => 'action', 'order' => 'desc' );
$r    = r_render( array( AuditPage::class, 'render' ) );
check( 'R5-O47 audit page renders filters, search box and sorted rows', array( true, true, true, array(), '' ), array( false !== strpos( $r[0], 'name="order_no"' ), false !== strpos( $r[0], 'id="wpac-audit' ), strpos( $r[0], 'o47.zeta' ) < strpos( $r[0], 'o47.alpha' ), $r[1], $r[2] ) );
$_GET = array( 'page' => 'wpac-audit', 's' => 'no-such-entry-o47' );
$r    = r_render( array( AuditPage::class, 'render' ) );
check( 'R5-O47 audit: search without hits shows the filter notice', true, false !== strpos( $r[0], 'No entries match this filter.' ) );

// ---------------------------------------------------------------- Payouts.
$wpdb->insert( t( 'affiliates' ), array( 'slug' => 'o47-zed', 'name' => 'O47 Zed Partner', 'email' => 'zed-o47@example.de', 'status' => 'active', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$o47_aff = (int) $wpdb->insert_id;
$o47_pay = array();
foreach ( array( array( 'O47-B', 3000, 'paid', '2026-03-31' ), array( 'O47-A', 1000, 'pending', '2026-01-31' ), array( 'O47-C', 2000, 'exported', '2026-02-28' ) ) as $o47_row ) {
	$wpdb->insert( t( 'payouts' ), array( 'number' => $o47_row[0], 'affiliate_id' => $o47_aff, 'period_end' => $o47_row[3], 'currency' => 'EUR', 'amount_minor' => $o47_row[1], 'status' => $o47_row[2], 'created_at' => Db::now() ) );
	$o47_pay[ $o47_row[0] ] = (int) $wpdb->insert_id;
}
$o47_q = PayoutService::query( array( 'search' => 'O47 Zed', 'orderby' => 'amount_minor', 'order' => 'asc' ) );
check( 'R5-O47 payouts search by partner name, sorted by amount', array( array( 'O47-A', 'O47-C', 'O47-B' ), 3 ), array( wp_list_pluck( $o47_q[0], 'number' ), $o47_q[1] ) );
$o47_q = PayoutService::query( array( 'search' => 'zed-o47@', 'orderby' => 'period_end', 'order' => 'desc' ) );
check( 'R5-O47 payouts search by e-mail, sorted by period end', array( 'O47-B', 'O47-C', 'O47-A' ), wp_list_pluck( $o47_q[0], 'number' ) );
check( 'R5-O47 payouts search by document number', array( 'O47-C' ), wp_list_pluck( PayoutService::query( array( 'search' => 'O47-C' ) )[0], 'number' ) );
check( 'R5-O47 payouts search escapes wildcards', 0, PayoutService::query( array( 'search' => 'O47_Zed' ) )[1] );
check( 'R5-O47 payouts search combines with the status filter', array( 'O47-A' ), wp_list_pluck( PayoutService::query( array( 'search' => 'O47', 'status' => 'pending' ) )[0], 'number' ) );
foreach ( $o47_bad as $o47_ob ) {
	check( 'R5-O47 payouts orderby injection ignored (ID, direction kept): ' . $o47_ob, array( 'O47-B', 'O47-A', 'O47-C' ), wp_list_pluck( PayoutService::query( array( 'search' => 'O47 Zed', 'orderby' => $o47_ob, 'order' => 'asc' ) )[0], 'number' ) );
}
$_GET   = array( 'page' => 'wpac-payouts', 's' => 'O47 Zed', 'orderby' => 'id', 'order' => 'asc' );
$o47_pt = new PayoutsTable( true, '' );
$o47_pt->prepare_items();
check( 'R5-O47 payouts table: number column sorts by ID from the request', array( 'O47-B', 'O47-A', 'O47-C' ), wp_list_pluck( $o47_pt->items, 'number' ) );
check( 'R5-O47 payouts sortable columns use whitelisted keys', array( 'number' => 'id', 'period_end' => 'period_end', 'amount_minor' => 'amount_minor', 'status' => 'status' ), o47_sortable( $o47_pt ) );
$_GET = array( 'page' => 'wpac-payouts', 's' => 'O47 Zed', 'orderby' => 'amount_minor', 'order' => 'desc' );
$r    = r_render( array( PayoutsPage::class, 'render' ) );
check(
	'R5-O47 payouts page: search form, sorted rows, checkboxes only for open/exported, bulk form kept',
	array( true, true, true, false, true, array(), '' ),
	array(
		false !== strpos( $r[0], 'id="wpac-payout' ),
		strpos( $r[0], 'O47-B' ) < strpos( $r[0], 'O47-C' ) && strpos( $r[0], 'O47-C' ) < strpos( $r[0], 'O47-A' ),
		false !== strpos( $r[0], 'name="ids[]" value="' . $o47_pay['O47-A'] . '"' ),
		false !== strpos( $r[0], 'name="ids[]" value="' . $o47_pay['O47-B'] . '"' ),
		false !== strpos( $r[0], 'name="bulk_action"' ),
		$r[1],
		$r[2],
	)
);
$_GET = array( 'page' => 'wpac-payouts', 's' => 'nothing-o47' );
$r    = r_render( array( PayoutsPage::class, 'render' ) );
check( 'R5-O47 payouts: search without hits says so', true, false !== strpos( $r[0], 'No payouts match this search.' ) );

// ---------------------------------------------------------------- Creatives.
foreach ( array( array( 'O47 Charlie', 'approved' ), array( 'O47 Alpha', 'draft' ), array( 'O47 Bravo', 'archived' ) ) as $o47_row ) {
	$wpdb->insert( t( 'creatives' ), array( 'family_id' => 0, 'version' => 1, 'title' => $o47_row[0], 'type' => 'text', 'content' => 'Text {link}', 'disclosure' => 'Werbung', 'status' => $o47_row[1], 'created_at' => Db::now() ) );
	$wpdb->update( t( 'creatives' ), array( 'family_id' => (int) $wpdb->insert_id ), array( 'id' => (int) $wpdb->insert_id ) );
}
$o47_c = Creatives::query( array( 'search' => 'O47 ', 'orderby' => 'title', 'order' => 'asc' ) );
check( 'R5-O47 creatives search and sort by title', array( array( 'O47 Alpha', 'O47 Bravo', 'O47 Charlie' ), 3 ), array( wp_list_pluck( $o47_c[0], 'title' ), $o47_c[1] ) );
check( 'R5-O47 creatives sort by status descending', array( 'O47 Alpha', 'O47 Bravo', 'O47 Charlie' ), wp_list_pluck( Creatives::query( array( 'search' => 'O47 ', 'orderby' => 'status', 'order' => 'desc' ) )[0], 'title' ) );
check( 'R5-O47 creatives default: newest family first', array( 'O47 Bravo', 'O47 Alpha', 'O47 Charlie' ), wp_list_pluck( Creatives::query( array( 'search' => 'O47 ' ) )[0], 'title' ) );
foreach ( $o47_bad as $o47_ob ) {
	check( 'R5-O47 creatives orderby injection ignored: ' . $o47_ob, array( 'O47 Bravo', 'O47 Alpha', 'O47 Charlie' ), wp_list_pluck( Creatives::query( array( 'search' => 'O47 ', 'orderby' => $o47_ob, 'order' => 'asc' ) )[0], 'title' ) );
}
check( 'R5-O47 creatives search escapes wildcards', 0, Creatives::query( array( 'search' => 'O47_Alpha' ) )[1] );
check( 'R5-O47 creatives paging', array( array( 'O47 Charlie' ), 3 ), array( wp_list_pluck( Creatives::query( array( 'search' => 'O47 ', 'orderby' => 'title', 'order' => 'asc', 'limit' => 1, 'offset' => 2 ) )[0], 'title' ), Creatives::query( array( 'search' => 'O47 ', 'limit' => 1 ) )[1] ) );
$_GET = array( 'page' => 'wpac-creatives', 's' => 'O47 ', 'orderby' => 'title', 'order' => 'desc' );
$r    = r_render( array( CreativesPage::class, 'render' ) );
check(
	'R5-O47 creatives page: search box, sorted rows, approve only for drafts',
	array( true, true, 1, array(), '' ),
	array(
		false !== strpos( $r[0], 'id="wpac-creative' ),
		strpos( $r[0], 'O47 Charlie' ) < strpos( $r[0], 'O47 Bravo' ) && strpos( $r[0], 'O47 Bravo' ) < strpos( $r[0], 'O47 Alpha' ),
		substr_count( $r[0], 'creative_approve' ),
		$r[1],
		$r[2],
	)
);
$_GET = array( 'page' => 'wpac-creatives', 's' => 'nothing-o47' );
$r    = r_render( array( CreativesPage::class, 'render' ) );
check( 'R5-O47 creatives: search without hits says so', true, false !== strpos( $r[0], 'No creatives match this search.' ) );
$_GET = array();

// Rows-per-page screen options of the three new tables are stored (clamped 1–500), others untouched.
foreach ( array( 'wpac_payouts_per_page', 'wpac_creatives_per_page', 'wpac_audit_per_page' ) as $o47_opt ) {
	check( "R5-O47 screen option {$o47_opt} clamped", 500, WPAffiliateCaptain\Admin\Admin::save_screen_option( false, $o47_opt, '9999' ) );
}
check( 'R5-O47 foreign screen option untouched', false, WPAffiliateCaptain\Admin\Admin::save_screen_option( false, 'edit_post_per_page', '20' ) );
