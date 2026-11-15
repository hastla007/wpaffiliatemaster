<?php
/**
 * Audit panel round 14, fix agent g3: partner statistics in the site time zone (U1), payout list
 * filters (U2), payout details after the partnership ended (U3), tier bonus in the partner's words
 * and progress (U4), refund rate (U5), conversion rate (U6), registration notices (U7) and the HTML
 * text link (T10). Included from tests/integration/run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Reports\Reports;

echo "R14 g3: partner statistics, payout filters, tiers, conversion\n";

global $wpdb;
$r14g3_tz = get_option( 'timezone_string', null );
update_option( 'timezone_string', 'Europe/Berlin' );

// ---------------------------------------------------------------------------------------------
// R14-U1: a commission at 00:30 local time on the first day of the current month (the evening
// before in UTC) belongs to this month in the statistics, the monthly table and the CSV.
$r14g3_aff  = AffiliateRepository::create( array( 'name' => 'R14 G3 Zeit', 'email' => 'r14g3-tz@example.de', 'status' => 'active' ) );
$r14g3_code = CodeRepository::main_code( $r14g3_aff );
$r14g3_r    = sale( 'r14g3-tz-1', $r14g3_code, array( 10000 ) );
$r14g3_amt  = (int) $wpdb->get_var( 'SELECT amount_minor FROM ' . t( 'commissions' ) . ' WHERE id = ' . (int) $r14g3_r['commission_ids'][0] );
$r14g3_m    = Db::local_month( 0 );
$r14g3_prev = Db::local_month( -1 );
list( $r14g3_mstart ) = Db::month_range( $r14g3_m );
$r14g3_at   = gmdate( 'Y-m-d H:i:s', strtotime( $r14g3_mstart . ' UTC' ) + 1800 ); // 00:30 local.
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'commissions' ) . ' SET created_at = %s WHERE affiliate_id = %d', $r14g3_at, $r14g3_aff ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'attributions' ) . ' SET created_at = %s WHERE affiliate_id = %d', $r14g3_at, $r14g3_aff ) );
check( 'R14-U1 precondition: the booking lies in the previous month in UTC', $r14g3_prev, substr( $r14g3_at, 0, 7 ) );
$r14g3_today = Portal::period( 'month' )['to'];
$r14g3_sum   = Reports::partner_summary( $r14g3_aff, $r14g3_m . '-01', $r14g3_today );
check( 'R14-U1 partner statistics "this month" count the sale of 00:30 local time', array( 1, array( 'EUR' => $r14g3_amt ) ), array( $r14g3_sum['sales'], $r14g3_sum['earned'] ) );
$r14g3_psum = Reports::partner_summary( $r14g3_aff, $r14g3_prev . '-01', gmdate( 'Y-m-t', strtotime( $r14g3_prev . '-01 12:00:00 UTC' ) ) );
check( 'R14-U1 the previous month does not show it', array( 0, array() ), array( $r14g3_psum['sales'], $r14g3_psum['earned'] ) );
$r14g3_months = Reports::partner_months( $r14g3_aff, 3 );
check( 'R14-U1 monthly table: local months, booking in the current month', array( array( $r14g3_m, $r14g3_prev, Db::local_month( -2 ) ), 1, array( 'EUR' => $r14g3_amt ), 0, array() ), array( array_keys( $r14g3_months ), $r14g3_months[ $r14g3_m ]['sales'], $r14g3_months[ $r14g3_m ]['earned'], $r14g3_months[ $r14g3_prev ]['sales'], $r14g3_months[ $r14g3_prev ]['earned'] ) );
$r14g3_camp = Reports::partner_campaigns( $r14g3_aff, $r14g3_m . '-01', $r14g3_today );
check( 'R14-U1 campaign report uses local days', array( 1, 1 ), array( count( $r14g3_camp ), isset( $r14g3_camp[0] ) ? $r14g3_camp[0]['orders'] : 0 ) );
$r14g3_csv = Portal::export_rows( AffiliateRepository::find( $r14g3_aff ), 'commissions', array( 'status' => '', 'from' => '', 'to' => '' ) );
check( 'R14-U1 CSV export: local day of the commission', $r14g3_m . '-01', isset( $r14g3_csv[1][0][0] ) ? $r14g3_csv[1][0][0] : null );

// ---------------------------------------------------------------------------------------------
// R14-U6: no visits → no conversion rate ("—"); only sales from a click count.
check( 'R14-U6 conversion rate without visits is null (shown as "—")', array( 0, 1, 0, null ), array( $r14g3_sum['visits'], $r14g3_sum['sales'], $r14g3_sum['click_sales'], $r14g3_sum['conversion'] ) );
check( 'R14-U6 campaign row without visits has no conversion rate', null, $r14g3_camp[0]['conversion'] );
$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $r14g3_aff, 'code_id' => 0, 'campaign' => '', 'sub_id' => '', 'created_at' => $r14g3_at ) );
$wpdb->insert( t( 'visits' ), array( 'affiliate_id' => $r14g3_aff, 'code_id' => 0, 'campaign' => '', 'sub_id' => '', 'created_at' => $r14g3_at ) );
$r14g3_sum = Reports::partner_summary( $r14g3_aff, $r14g3_m . '-01', $r14g3_today );
check( 'R14-U6 a code sale is not a conversion of the visits', array( 2, 1, 0, 0.0 ), array( $r14g3_sum['visits'], $r14g3_sum['sales'], $r14g3_sum['click_sales'], $r14g3_sum['conversion'] ) );
$wpdb->query( 'UPDATE ' . t( 'attributions' ) . " SET method = 'link' WHERE affiliate_id = " . (int) $r14g3_aff );
$r14g3_sum  = Reports::partner_summary( $r14g3_aff, $r14g3_m . '-01', $r14g3_today );
$r14g3_camp = Reports::partner_campaigns( $r14g3_aff, $r14g3_m . '-01', $r14g3_today );
check( 'R14-U6 a sale after a click counts (1 of 2 visits = 50 %), also per campaign', array( 1, 50.0, 50.0 ), array( $r14g3_sum['click_sales'], $r14g3_sum['conversion'], $r14g3_camp[0]['conversion'] ) );

// ---------------------------------------------------------------------------------------------
// R14-U5: refund rate only from reversals – a manual increase does not hide a refund.
$r14g3_ref  = AffiliateRepository::create( array( 'name' => 'R14 G3 Quote', 'email' => 'r14g3-rate@example.de', 'status' => 'active' ) );
$r14g3_rr   = sale( 'r14g3-rate-1', CodeRepository::main_code( $r14g3_ref ), array( 100000 ) );
$r14g3_row  = $wpdb->get_row( 'SELECT * FROM ' . t( 'commissions' ) . ' WHERE id = ' . (int) $r14g3_rr['commission_ids'][0], ARRAY_A );
$r14g3_half = (int) round( (int) $r14g3_row['amount_minor'] / 2 );
foreach ( array( 'refund' => -$r14g3_half, 'increase' => $r14g3_half ) as $r14g3_kind => $r14g3_v ) {
	$r14g3_new = $r14g3_row;
	unset( $r14g3_new['id'] );
	$r14g3_new['kind']            = $r14g3_kind;
	$r14g3_new['parent_id']       = (int) $r14g3_row['id'];
	$r14g3_new['amount_minor']    = $r14g3_v;
	$r14g3_new['base_minor']      = 0;
	$r14g3_new['idempotency_key'] = 'r14g3:' . $r14g3_kind;
	$wpdb->insert( t( 'commissions' ), $r14g3_new );
}
$r14g3_rep = Reports::partners( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), $r14g3_ref );
check( 'R14-U5 refund rate: 50 % refunded, +50 % increase does not net it out', array( 50.0, 0, -$r14g3_half ), array( $r14g3_rep[0]['refund_rate'] ?? null, $r14g3_rep[0]['corrections'] ?? null, $r14g3_rep[0]['refunds'] ?? null ) );

// ---------------------------------------------------------------------------------------------
// R14-U2: payouts list filters by local day and leaves out cancelled payouts without document.
$r14g3_pa  = r12a1_partner( 'r14g3' );
$r14g3_p1  = r12a1_payout( $r14g3_pa, 'r14g3-p-1' );
$r14g3_p2  = r12a1_payout( $r14g3_pa, 'r14g3-p-2' );
PayoutService::cancel( $r14g3_p2, 'test' );
$wpdb->update( t( 'payouts' ), array( 'storno_file' => '' ), array( 'id' => $r14g3_p2 ) );
$r14g3_q = static function ( array $args ) use ( $r14g3_pa ) {
	list( $rows, $total ) = PayoutService::query( $args + array( 'affiliate_id' => $r14g3_pa, 'limit' => 25 ) );
	return array( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ), (int) $total );
};
check( 'R14-U2 precondition: two payouts, one cancelled', array( array( $r14g3_p2, $r14g3_p1 ), 2 ), $r14g3_q( array() ) );
check( 'R14-U2 partner list: cancelled payout without cancellation document is left out in SQL (pager count too)', array( array( $r14g3_p1 ), 1 ), $r14g3_q( array( 'portal' => true ) ) );
check( 'R14-U2 date filter in the future: nothing', array( array(), 0 ), $r14g3_q( array( 'portal' => true, 'from' => '2099-01-01', 'to' => '2099-12-31' ) ) );
check( 'R14-U2 date filter today (local) and open end', array( array( array( $r14g3_p1 ), 1 ), array( array( $r14g3_p1 ), 1 ) ), array( $r14g3_q( array( 'portal' => true, 'from' => $r14g3_today, 'to' => $r14g3_today ) ), $r14g3_q( array( 'portal' => true, 'from' => $r14g3_today ) ) ) );
check( 'R14-U2 invalid dates are ignored', array( array( $r14g3_p1 ), 1 ), $r14g3_q( array( 'portal' => true, 'from' => "x' OR 1=1", 'to' => array() ) ) );
$r14g3_csv = Portal::export_rows( AffiliateRepository::find( $r14g3_pa ), 'payouts', array( 'status' => '', 'from' => '2099-01-01', 'to' => '2099-12-31' ) );
$r14g3_all = Portal::export_rows( AffiliateRepository::find( $r14g3_pa ), 'payouts', array( 'status' => '', 'from' => '', 'to' => '' ) );
check( 'R14-U2 CSV export uses the same filter and visibility as the table', array( 0, 1 ), array( count( $r14g3_csv[1] ), count( $r14g3_all[1] ) ) );

// ---------------------------------------------------------------------------------------------
// R14-U3: terminated partners keep the payout details while money is open.
$r14g3_t  = AffiliateRepository::create( array( 'name' => 'R14 G3 Ende', 'email' => 'r14g3-end@example.de', 'status' => 'active' ) );
$r14g3_tr = sale( 'r14g3-end-1', CodeRepository::main_code( $r14g3_t ), array( 20000 ) );
check( 'R14-U3 active partner: no special case', false, Portal::payout_open( AffiliateRepository::find( $r14g3_t ) ) );
$wpdb->update( t( 'affiliates' ), array( 'status' => 'terminated' ), array( 'id' => $r14g3_t ) );
AffiliateRepository::clear_cache( $r14g3_t );
check( 'R14-U3 terminated with open commission: payout details stay', true, Portal::payout_open( AffiliateRepository::find( $r14g3_t ) ) );
Ledger::reject( (int) $r14g3_tr['commission_ids'][0], 'test' );
check( 'R14-U3 terminated without open money: no payout details', false, Portal::payout_open( AffiliateRepository::find( $r14g3_t ) ) );
$wpdb->update( t( 'affiliates' ), array( 'status' => 'terminated' ), array( 'id' => $r14g3_pa ) );
AffiliateRepository::clear_cache( $r14g3_pa );
check( 'R14-U3 terminated with an unpaid payout: payout details stay', true, Portal::payout_open( AffiliateRepository::find( $r14g3_pa ) ) );
$r14g3_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Portal/Portal.php' );
check( 'R14-U3 handle_post allows the profile action while money is open', true, false !== strpos( $r14g3_src, "! ( 'profile' === \$action && self::payout_open( \$aff ) )" ) );

// ---------------------------------------------------------------------------------------------
// R14-U4: tier steps in the partner's words and progress in the running month.
$r14g3_plan = static function ( $basis, $mode ) {
	return (object) array( 'basis' => $basis, 'mode' => $mode, 'steps' => wp_json_encode( array( array( 'threshold' => 'orders' === $basis ? 5 : 100000, 'rate' => '2' ) ) ) );
};
$r14g3_texts = array();
foreach ( array( array( 'orders', 'retroactive' ), array( 'orders', 'marginal' ), array( 'revenue', 'retroactive' ), array( 'revenue', 'marginal' ) ) as $r14g3_pm ) {
	$r14g3_texts[] = TierService::partner_text( $r14g3_plan( $r14g3_pm[0], $r14g3_pm[1] ), 'EUR' )[0];
}
check(
	'R14-U4 partner texts name unit, period and what the extra rate applies to',
	array( true, true, true, true ),
	array(
		0 === strpos( $r14g3_texts[0], 'From 5 orders in a month: +' ) && false !== strpos( $r14g3_texts[0], 'all orders of that month' ),
		0 === strpos( $r14g3_texts[1], 'From order no. 5 in a month: +' ) && false !== strpos( $r14g3_texts[1], 'every further order' ),
		false !== strpos( $r14g3_texts[2], 'net sales in a month' ) && false !== strpos( $r14g3_texts[2], 'all net sales of that month' ),
		false !== strpos( $r14g3_texts[3], 'net sales above this amount' ),
	)
);
$r14g3_ta = AffiliateRepository::create( array( 'name' => 'R14 G3 Staffel', 'email' => 'r14g3-tier@example.de', 'status' => 'active' ) );
TierService::create( array( 'scope' => 'affiliate', 'scope_id' => $r14g3_ta, 'basis' => 'orders', 'mode' => 'retroactive', 'steps' => array( array( 'threshold' => 3, 'rate' => '2' ), array( 'threshold' => 5, 'rate' => '3' ) ) ) );
$r14g3_tplan = TierService::plan_for( AffiliateRepository::find( $r14g3_ta ) );
sale( 'r14g3-tier-1', CodeRepository::main_code( $r14g3_ta ), array( 10000 ) ); // Pending (hold period): counts for the progress.
$r14g3_prog = TierService::progress( AffiliateRepository::find( $r14g3_ta ), $r14g3_tplan );
check( 'R14-U4 progress: 1 order this month, next step 3 orders (+2 %)', array( 'orders', 1, null, 3 ), array( $r14g3_prog['basis'], $r14g3_prog['measure'], $r14g3_prog['reached'], (int) $r14g3_prog['next']['threshold'] ) );
$GLOBALS['r14g3_tier_aff'] = $r14g3_ta;
$GLOBALS['r14g3_tier_id']  = (int) $r14g3_tplan->id;

// ---------------------------------------------------------------------------------------------
// R14-U7: status-specific notices for logged-in users with an application.
$r14g3_notice = static function ( $status ) {
	return Registration::account_notice( (object) array( 'status' => $status ) );
};
$r14g3_pend = $r14g3_notice( 'pending' );
$r14g3_rej  = $r14g3_notice( 'rejected' );
$r14g3_act  = $r14g3_notice( 'active' );
check(
	'R14-U7 pending: "being reviewed", rejected: "not accepted" without partner area link, active: account',
	array( true, false, true, false, false, true ),
	array(
		false !== strpos( $r14g3_pend, 'Your application is being reviewed.' ),
		false !== strpos( $r14g3_pend, 'already have a partner account' ),
		false !== strpos( $r14g3_rej, 'Your application was not accepted.' ) && false !== strpos( $r14g3_rej, 'mailto:' ),
		false !== strpos( $r14g3_rej, 'Go to partner area' ),
		false !== strpos( $r14g3_rej, 'already have a partner account' ),
		false !== strpos( $r14g3_act, 'You already have a partner account.' ),
	)
);
check( 'R14-U7 terminated: partnership ended', true, false !== strpos( $r14g3_notice( 'terminated' ), 'Your partnership has ended.' ) );

// ---------------------------------------------------------------------------------------------
// R14-T10: HTML text link with rel="sponsored noopener".
check( 'R14-T10 text link code', '<a href="http://example.test/?partner=AB&amp;sub=x" rel="sponsored noopener">Test &amp; Shop</a>', Portal::text_link_code( 'http://example.test/?partner=AB&sub=x', 'Test & Shop' ) );
check( 'R14-T10 text link code: site name as default text', '<a href="http://example.test/?partner=AB" rel="sponsored noopener">Test Shop</a>', Portal::text_link_code( 'http://example.test/?partner=AB' ) );

if ( null === $r14g3_tz ) {
	unset( $GLOBALS['test_options']['timezone_string'] );
} else {
	update_option( 'timezone_string', $r14g3_tz );
}
