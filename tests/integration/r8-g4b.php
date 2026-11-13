<?php
/**
 * R8 package G4b: payout flow, payout requests, SEPA/CSV/PayPal, objections, payout details.
 * Included from run.php (helpers check(), sale(), refund(), t(), q() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Objections;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R8 G4b payout flow, requests, exports, objections, payout details\n";
global $wpdb;
$g4b_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $g4b_keep, array( 'min_payout_minor' => 5000, 'exchange_rates' => "CHF=1.05\nUSD=0.9", 'terminated_open' => 'pay', 'vies_check' => 0, 'payout_requests' => 1, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();

/** Active partner with complete SEPA (or other) payout details, changed long ago. */
function g4b_partner( $slug, array $profile = array() ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'G4b ' . $slug, 'email' => 'g4b-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'tax_status' => 'private', 'legal_name' => 'G4b ' . $slug, 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4b ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
}
/** Approved sale commission (10 % of the net amount) in a currency. */
function g4b_earn( $aff, $order, $net, $currency = 'EUR' ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => $currency, 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return isset( $r['commission_ids'][0] ) ? (int) $r['commission_ids'][0] : 0;
}
function g4b_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
}

/* R5-W27: payout run writes the credit notes through the job queue ------------------------- */
PayoutService::register_hooks();
$g4b_created_hook = array();
add_action( 'wpac_payout_created', static function ( $id ) use ( &$g4b_created_hook ) { $g4b_created_hook[] = (int) $id; } );
$g4b_r1 = g4b_partner( 'run1' );
$g4b_r2 = g4b_partner( 'run2' );
g4b_earn( $g4b_r1, 'g4b-run-1', 100000, 'CHF' );
g4b_earn( $g4b_r2, 'g4b-run-2', 200000, 'CHF' );
$g4b_run = PayoutService::create_run( gmdate( 'Y-m-d' ), 'CHF' );
$g4b_ids = $g4b_run[0];
$g4b_files = array_map( static function ( $id ) { return (string) PayoutService::find( $id )->statement_file; }, $g4b_ids );
$g4b_jobs  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'jobs' ) . " WHERE type = 'statement' AND status = 'queued' AND next_attempt_at <= '" . gmdate( 'Y-m-d H:i:s', time() + 1 ) . "' AND idempotency_key IN ('statement:" . implode( "','statement:", array_map( 'intval', $g4b_ids ) ) . "')" );
check( 'R5-W27 run creates the payouts in the request, the credit notes are queued (not yet written, no hook yet)', array( 2, array( '', '' ), 2, array() ), array( count( $g4b_ids ), $g4b_files, $g4b_jobs, array_values( array_intersect( $g4b_created_hook, $g4b_ids ) ) ) );
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'jobs' ) . " WHERE type = 'statement' AND status = 'queued'" ) as $g4b_job ) {
	WPAffiliateCaptain\Core\Jobs::run( (int) $g4b_job );
}
$g4b_files = array_map( static function ( $id ) { return '' !== (string) PayoutService::find( $id )->statement_file; }, $g4b_ids );
sort( $g4b_created_hook );
check( 'R5-W27 the queue writes the credit notes and then fires wpac_payout_created once per payout', array( array( true, true ), $g4b_ids ), array( $g4b_files, array_values( array_intersect( $g4b_created_hook, $g4b_ids ) ) ) );
check( 'R5-W27 single payouts (requests, rebuild) still get their credit note right away', true, ( function () {
	$a = g4b_partner( 'single' );
	g4b_earn( $a, 'g4b-single', 100000, 'CHF' );
	$p = PayoutService::create_for_affiliate( $a, gmdate( 'Y-m-d' ), 'CHF' );
	return $p > 0 && '' !== (string) PayoutService::find( $p )->statement_file;
} )() );
// Head-of-line blocking: payouts in back-off must not block the others.
$g4b_y = $g4b_ids[1];
$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET statement_file = '' WHERE id = " . (int) $g4b_y );
$g4b_retry_keep = get_option( Statement::RETRY_OPTION, array() );
$g4b_retry      = array();
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'payouts' ) . " WHERE statement_file = '' AND statement_deleted_at IS NULL AND status <> 'cancelled' AND id <> " . (int) $g4b_y ) as $g4b_wid ) {
	$g4b_retry[ (int) $g4b_wid ] = array( 'n' => 1, 'next' => time() + 3600 );
}
$g4b_retry[999999] = array( 'n' => 1, 'next' => time() + 3600 );
update_option( Statement::RETRY_OPTION, $g4b_retry, false );
PayoutService::generate_missing_statements( 1, 0 );
check( 'R5-W27 hourly catch-up skips payouts in back-off instead of being blocked by them', true, '' !== (string) PayoutService::find( $g4b_y )->statement_file );

/* R5-W30: waiting statements are checked with one query ------------------------------------- */
g4b_earn( $g4b_r1, 'g4b-w30', 100000 );
$g4b_missing = PayoutService::create_for_affiliate( $g4b_r1, gmdate( 'Y-m-d' ), 'EUR' );
$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET statement_file = '' WHERE id = " . (int) $g4b_missing );
update_option( Statement::RETRY_OPTION, array( (int) $g4b_missing => array( 'n' => 1, 'next' => time() + 60 ), (int) $g4b_ids[0] => array( 'n' => 1, 'next' => time() + 60 ), 888888 => array( 'n' => 2, 'next' => time() ) ), false );
check( 'R5-W30 waiting() keeps only payouts that still need their credit note', array( (int) $g4b_missing ), array_keys( Statement::waiting() ) );
update_option( Statement::RETRY_OPTION, $g4b_retry_keep, false );
Statement::generate( $g4b_missing );

/* R6-DD14 / R7-PV8: available amount = what a payout pays (gross, hold-backs), per currency -- */
$g4b_vat = g4b_partner( 'vat', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
g4b_earn( $g4b_vat, 'g4b-vat-1', 100000 ); // 100 € net.
// 50 € whose correction is still pending: held back by a payout, so not "available".
$g4b_held = (int) wpac_record_sale( array( 'source' => 'test', 'order_id' => 'g4b-vat-2', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4b_vat ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 50000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-vat2@example.de' ) ) )['commission_ids'][0];
refund( 'g4b-vat-2', 'g4b-r1', array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ); // Pending correction.
Ledger::transition( $g4b_held, 'approved' ); // Only the original commission is approved.
$g4b_pay = PayoutService::payable( $g4b_vat, 'EUR' );
check( 'R6-DD14 available = gross incl. VAT and without commissions held back by an open correction', array( 10000, 11900, 11900 ), array( $g4b_pay['net'], $g4b_pay['gross'], PayoutRequests::available( $g4b_vat ) ) );
$g4b_usd = g4b_partner( 'usd' );
g4b_earn( $g4b_usd, 'g4b-usd-1', 100000, 'USD' );
check( 'R7-PV8 request in the currency that reaches the minimum (USD only), amount in USD', array( true, 'USD', 10000 ), array( PayoutRequests::can_request( AffiliateRepository::find( $g4b_usd ) ), PayoutRequests::request_currency( $g4b_usd ), (int) PayoutRequests::available( $g4b_usd, 'USD' ) ) );
$g4b_req = PayoutRequests::request( AffiliateRepository::find( $g4b_usd ) );
check( 'R7-PV8 request is stored in USD', 'USD', is_wp_error( $g4b_req ) ? $g4b_req->get_error_message() : (string) PayoutRequests::find( $g4b_req )->currency );
g4b_earn( $g4b_r1, 'g4b-pv8-eur', 100000 );
$g4b_pe = PayoutService::create_for_affiliate( $g4b_r1, gmdate( 'Y-m-d' ), 'EUR' );
g4b_earn( $g4b_r1, 'g4b-pv8-chf', 100000, 'CHF' );
$g4b_pc = PayoutService::create_for_affiliate( $g4b_r1, gmdate( 'Y-m-d' ), 'CHF' );
$g4b_tt = WPAffiliateCaptain\Admin\Pages\PayoutsPage::totals_text( array( $g4b_pe, $g4b_pc ) );
check( 'R7-PV8 bulk confirmation sums per currency, never across currencies', 1, preg_match( '/EUR \+ .*CHF$/u', $g4b_tt ) );
check( 'R7-PV8 minimum shown in the run currency (converted)', WPAffiliateCaptain\Core\Money::format( (int) WPAffiliateCaptain\Core\Fx::min_payout( 'CHF' ), 'CHF' ), WPAffiliateCaptain\Admin\Pages\PayoutsPage::min_text( 'CHF' ) );
$g4b_orders = new ReflectionMethod( WPAffiliateCaptain\Commissions\TierService::class, 'orders' );
$g4b_orders->setAccessible( true );
$g4b_tier   = $g4b_orders->invoke( null, $g4b_usd, gmdate( 'Y-m' ), 'EUR' );
check( 'R7-PV8 tier bonus counts orders in other currencies (converted to the program currency)', array( 1, 90000 ), array( count( $g4b_tier ), (int) $g4b_tier[0]['revenue'] ) );

/* R5-O33: a request settled by a cancelled payout is open again ----------------------------- */
$g4b_rq_payout = PayoutRequests::process( $g4b_req );
check( 'R5-O33 request processed by its payout', 'processed', is_wp_error( $g4b_rq_payout ) ? $g4b_rq_payout->get_error_message() : (string) PayoutRequests::find( $g4b_req )->status );
PayoutService::cancel( (int) $g4b_rq_payout, 'Test O33' );
check( 'R5-O33 cancelling the payout opens the request again', array( 'requested', 0 ), array( (string) PayoutRequests::find( $g4b_req )->status, (int) PayoutRequests::find( $g4b_req )->payout_id ) );
$g4b_rq_new = PayoutRequests::process( $g4b_req );
check( 'R5-O33 the next payout settles it again', array( 'processed', (int) $g4b_rq_new ), array( (string) PayoutRequests::find( $g4b_req )->status, (int) PayoutRequests::find( $g4b_req )->payout_id ) );

/* R7-PV5 / R5-W34: termination with "rejected" cancels unexported payouts, keeps write-offs -- */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terminated_open' => 'reject' ) ) );
Settings::flush_cache();
$g4b_t = g4b_partner( 'term' );
g4b_earn( $g4b_t, 'g4b-term-1', 100000 );
$g4b_tp = PayoutService::create_for_affiliate( $g4b_t, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $g4b_tp );
refund( 'g4b-term-1', 'g4b-term-r', array(), 'refund' ); // −100 € after payment.
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'g4b-term-1' AND parent_id > 0" ) as $g4b_child ) {
	Ledger::transition( (int) $g4b_child, 'approved' );
}
update_option( 'wpac_dunning_' . (int) $g4b_t . '_EUR', array( array( 'level' => 1 ), array( 'level' => 2 ), array( 'level' => 3 ) ), false );
$g4b_wo = Ledger::write_off( $g4b_t, 'EUR', 'Test G4b' );
check( 'R5-O31 write-off resets the payment requests (a new debt starts with the first request)', array( true, array() ), array( is_int( $g4b_wo ), WPAffiliateCaptain\Commissions\Dunning::history( $g4b_t, 'EUR' ) ) );
g4b_earn( $g4b_t, 'g4b-term-2', 100000 );
$g4b_tp2 = PayoutService::create_for_affiliate( $g4b_t, gmdate( 'Y-m-d' ), 'EUR' );
AffiliateRepository::set_status( $g4b_t, 'terminated', 'Test G4b' );
$g4b_code = '';
PayoutService::blocked_reason( $g4b_t, null, $g4b_code );
check( 'R7-PV5 termination cancels the unexported payout, rejects its commissions and blocks new payouts', array( 'cancelled', 'rejected', 'terminated' ), array( (string) PayoutService::find( $g4b_tp2 )->status, (string) $wpdb->get_var( 'SELECT status FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'g4b-term-2'" ), $g4b_code ) );
check( 'R5-W34 the write-off is not rejected on termination (the debt does not come back)', 'approved', (string) Ledger::find( (int) $g4b_wo )->status );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terminated_open' => 'pay' ) ) );
Settings::flush_cache();

/* R5-W60 / R7-PU11 / R5-W54: exports report what they contain and what was left out ---------- */
$g4b_pp = g4b_partner( 'paypal', array( 'payout_method' => 'manual', 'payment_details' => "PayPal: G4b.PayPal@Example.de\nBitte mit Referenz" ) );
$g4b_nopp = g4b_partner( 'wise', array( 'payout_method' => 'manual', 'payment_details' => 'Wise, Kontonummer 12345678' ) );
$g4b_sepa = g4b_partner( 'sepa' );
$g4b_pids = array();
foreach ( array( $g4b_pp, $g4b_nopp, $g4b_sepa ) as $g4b_i => $g4b_a ) {
	g4b_earn( $g4b_a, 'g4b-exp-' . $g4b_i, 150000 );
	$g4b_pids[] = PayoutService::create_for_affiliate( $g4b_a, gmdate( 'Y-m-d' ), 'EUR' );
}
$g4b_sx = PayoutService::sepa_export( $g4b_pids );
check( 'R5-W60 SEPA export reports how many payouts of another method were left out', array( 2, array( $g4b_pids[2] ) ), is_array( $g4b_sx ) ? array( $g4b_sx[2], $g4b_sx[3] ) : array( g4b_code( $g4b_sx ) ) );
$g4b_ppx = PayoutService::paypal_csv( array( $g4b_pids[0], $g4b_pids[1], $g4b_pids[2] ), true );
$g4b_pn  = (string) PayoutService::find( $g4b_pids[0] )->number;
check( 'R7-PU11 PayPal payouts file: PayPal address, amount with decimal point, currency, reference; others left out', array( array( array( 'g4b.paypal@example.de', '150.00', 'EUR', $g4b_pn ) ), array( (string) PayoutService::find( $g4b_pids[1] )->number, (string) PayoutService::find( $g4b_pids[2] )->number ), 'exported', 'pending' ), is_array( $g4b_ppx ) ? array( array_map( static function ( $r ) { return array_slice( $r, 0, 4 ); }, $g4b_ppx[1] ), $g4b_ppx[2], (string) PayoutService::find( $g4b_pids[0] )->status, (string) PayoutService::find( $g4b_pids[1] )->status ) : array( g4b_code( $g4b_ppx ) ) );
check( 'R7-PU11 PayPal file has no header, no BOM and comma separators', 0, strpos( PayoutService::paypal_csv_text( $g4b_ppx[1] ), 'g4b.paypal@example.de,150.00,EUR,' ) );
$g4b_tl = PayoutService::transfer_csv( array( $g4b_pids[1] ) );
$g4b_xl = WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_rows_for_excel( $g4b_tl[1] );
check( 'R5-W54 transfer list for German Excel: decimal comma, readable method, translated header', array( '150,00', 'Other payment method', 'Account holder' ), array( $g4b_xl[0][4], $g4b_xl[0][7], WPAffiliateCaptain\Admin\Pages\PayoutsPage::transfer_header()[1] ) );
check( 'R7-PU12 partner status of a manual payout is not "transferred" (snapshot method)', array( 'manual', 'sepa' ), array( PayoutService::snapshot_method( PayoutService::find( $g4b_pids[0] ) ), PayoutService::snapshot_method( PayoutService::find( $g4b_pids[2] ) ) ) );

$GLOBALS['test_mails'] = array();
WPAffiliateCaptain\Affiliates\Notifications::payout_paid( $g4b_pids[0] );
WPAffiliateCaptain\Affiliates\Notifications::payout_paid( $g4b_pids[2] );
check( 'R7-PU12 payout mail says "paid" for PayPal & co., "transferred" for bank transfers', array( 1, 1 ), array( preg_match( '/ paid$/', (string) $GLOBALS['test_mails'][0][1] ), preg_match( '/ transferred$/', (string) $GLOBALS['test_mails'][1][1] ) ) );

/* R5-W55 / R6-QA5: objections --------------------------------------------------------------- */
check( 'R5-W55 paid credit note: guidance does not recommend the impossible cancellation', array( false, true ), array( false !== strpos( Objections::guidance( PayoutService::find( $g4b_tp ) ), 'Cancel the payout' ), false !== strpos( Objections::guidance( PayoutService::find( $g4b_pids[2] ) ), 'Cancel the payout' ) ) );
$GLOBALS['test_option_fail'] = array( Objections::OPTION => true );
$g4b_obj = Objections::file( $g4b_pids[2], $g4b_sepa, 'Betrag falsch' );
unset( $GLOBALS['test_option_fail'] );
check( 'R6-QA5 objection that could not be stored is reported as an error', array( 'wpac_objection_save', null ), array( g4b_code( $g4b_obj ), Objections::for_payout( $g4b_pids[2] ) ) );
$g4b_long = str_repeat( 'a', 999 ) . 'äöü';
Objections::file( $g4b_pids[2], $g4b_sepa, $g4b_long );
$g4b_o = Objections::for_payout( $g4b_pids[2] );
check( 'R6-QA5 objection text is cut by characters (valid UTF-8, 1000 characters)', array( true, 1000 ), array( (bool) preg_match( '//u', (string) $g4b_o['reason'] ), mb_strlen( (string) $g4b_o['reason'], 'UTF-8' ) ) );
$GLOBALS['test_option_fail'] = array( Objections::OPTION => true );
$g4b_res = Objections::resolve( $g4b_pids[2], 'Erklärt' );
unset( $GLOBALS['test_option_fail'] );
check( 'R6-QA5 resolution that could not be stored: not reported as handled, objection stays open', array( false, 'open' ), array( $g4b_res, Objections::for_payout( $g4b_pids[2] )['status'] ) );
Objections::resolve( $g4b_pids[2], 'Erklärt' );

/* R6-QA8 / R5-W52 / R5-W53 / R5-O14 / R6-DD9: payout details --------------------------------- */
$g4b_q = g4b_partner( 'qa8' );
$GLOBALS['test_mails'] = array();
$g4b_e = ProfileRepository::save( $g4b_q, array( 'tax_status' => 'private', 'legal_name' => str_repeat( 'N', 191 ), 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4b qa8', 'iban' => 'NL91ABNA0417164300' ) );
check( 'R6-QA8 too long name refused, nothing changed, no warning mail', array( 'wpac_too_long_legal_name', 'DE89370400440532013000', array() ), array( g4b_code( $g4b_e ), ProfileRepository::iban( ProfileRepository::get( $g4b_q ) ), $GLOBALS['test_mails'] ) );
$wpdb->query( 'CREATE TRIGGER g4b_block BEFORE UPDATE ON ' . t( 'payout_profiles' ) . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'" );
$g4b_e = ProfileRepository::save( $g4b_q, array( 'tax_status' => 'private', 'legal_name' => 'G4b qa8', 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4b qa8', 'iban' => 'NL91ABNA0417164300' ) );
$wpdb->query( 'DROP TRIGGER IF EXISTS g4b_block' );
check( 'R6-QA8 database error: error instead of success, no "bank details changed" mail', array( 'wpac_profile_db', 'DE89370400440532013000', array() ), array( g4b_code( $g4b_e ), ProfileRepository::iban( ProfileRepository::get( $g4b_q ) ), $GLOBALS['test_mails'] ) );

update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'payout_method_manual' => 0 ) ) );
Settings::flush_cache();
$g4b_e = ProfileRepository::save( $g4b_q, array( 'tax_status' => 'private', 'legal_name' => 'G4b qa8', 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'payout_method' => 'manual', 'payment_details' => 'PayPal: x@example.de' ) );
check( 'R5-W52 other payment method can be switched off (not offered, not saved; existing users keep it)', array( 'wpac_method_disabled', array( 'sepa' ), array( 'sepa', 'manual' ) ), array( g4b_code( $g4b_e ), array_keys( ProfileRepository::method_options( ProfileRepository::get( $g4b_q ) ) ), array_keys( ProfileRepository::method_options( ProfileRepository::get( $g4b_pp ) ) ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'payout_method_manual' => 1 ) ) );
Settings::flush_cache();
$g4b_export = WPAffiliateCaptain\Privacy\Privacy::export( 'g4b-paypal@example.de' );
$g4b_found  = false;
foreach ( $g4b_export['data'] as $g4b_item ) {
	foreach ( (array) $g4b_item['data'] as $g4b_d ) {
		$g4b_found = $g4b_found || false !== strpos( (string) $g4b_d['value'], 'G4b.PayPal@Example.de' );
	}
}
$g4b_row = new ReflectionMethod( WPAffiliateCaptain\Migration\ProgramExport::class, 'row' );
$g4b_row->setAccessible( true );
$g4b_prow = $g4b_row->invoke( null, 'payout_profiles', (array) ProfileRepository::get( $g4b_pp ), true );
check( 'R5-W52 Art. 15 export and program export carry the method and payment details; record of processing names the provider', array( true, 'manual', true, true ), array( $g4b_found, $g4b_prow['payout_method'], false !== strpos( $g4b_prow['payment_details'], 'G4b.PayPal@' ), false !== strpos( WPAffiliateCaptain\Privacy\Privacy::ropa(), 'Zahlungsdienstleister' ) ) );
$g4b_kept = ProfileRepository::form_keep( array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: geheim@example.de', 'iban' => 'DE89370400440532013000', 'legal_name' => 'X' ) );
check( 'R5-W53 kept admin form: method kept, payment details only encrypted, no IBAN', array( 'manual', false, false, 'PayPal: geheim@example.de' ), array( $g4b_kept['payout_method'], false !== strpos( serialize( $g4b_kept ), 'geheim' ), isset( $g4b_kept['iban'] ), ProfileRepository::form_details( $g4b_kept ) ) );
check( 'R5-O14 partner sees the stored payment details masked', 'PayPal: g4•••@Example.de / Kontonummer •••5678', ProfileRepository::mask_details( 'PayPal: g4b.paypal@Example.de / Kontonummer 12345678' ) );
$g4b_pr = ProfileRepository::get( $g4b_sepa );
check( 'R6-DD9 changing IBAN, payment details or method needs the password; other fields do not', array( true, false, false, true ), array(
	ProfileRepository::changes_destination( $g4b_pr, array( 'iban' => 'NL91ABNA0417164300' ) ),
	ProfileRepository::changes_destination( $g4b_pr, array( 'iban' => 'DE89 3704 0044 0532 0130 00', 'legal_name' => 'Neu' ) ),
	ProfileRepository::changes_destination( null, array( 'iban' => 'NL91ABNA0417164300' ) ),
	ProfileRepository::changes_destination( $g4b_pr, array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: a@b.de' ) ),
) );

/* R6-DD10 / R6-DD11 / R6-DD15: permissions --------------------------------------------------- */
$g4b_hold = (int) ( wpac_record_sale( array( 'source' => 'test', 'order_id' => 'g4b-dd10', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4b_sepa ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-dd10@example.de' ) ) )['commission_ids'][0] );
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET hold_until = '" . gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ) . "' WHERE id = " . $g4b_hold );
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES );
$g4b_e = WPAffiliateCaptain\Admin\Pages\CommissionsPage::approve_manual( $g4b_hold, 'früh' );
$g4b_page_cap_mgr = Capabilities::payouts_page_cap();
$GLOBALS['test_caps'] = array( Capabilities::VIEW_TAX_DATA );
$g4b_page_cap_tax = Capabilities::payouts_page_cap();
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_PROGRAM );
$g4b_audit = WPAffiliateCaptain\Admin\Pages\AuditPage::details_text( '{"tax_status":"business","payout_method":"sepa"}' );
unset( $GLOBALS['test_caps'] );
check( 'R6-DD10 partner manager cannot approve before the end of the hold period', array( 'wpac_early_approval', 'pending' ), array( g4b_code( $g4b_e ), (string) Ledger::find( $g4b_hold )->status ) );
check( 'R6-DD10 payout manager can', array( true, 'approved' ), array( WPAffiliateCaptain\Admin\Pages\CommissionsPage::approve_manual( $g4b_hold, 'früh' ), (string) Ledger::find( $g4b_hold )->status ) );
check( 'R6-DD11 tax data permission alone opens the payouts page (read only)', array( Capabilities::MANAGE_PAYOUTS, Capabilities::VIEW_TAX_DATA ), array( $g4b_page_cap_mgr, $g4b_page_cap_tax ) );
$g4b_prow = $g4b_row->invoke( null, 'payouts', (array) PayoutService::find( $g4b_pids[0] ), false );
check( 'R6-DD15 tax status hidden in the audit log and VAT mode not in the export without tax permission', array( false, '' ), array( false !== strpos( $g4b_audit, 'business' ), $g4b_prow['vat_mode'] ) );

/* R5-W57: the payout period setting without effect is no longer offered --------------------- */
check( 'R5-W57 payout period removed from the settings page (stored value kept, no effect)', array( false, true ), array( in_array( 'payout_period', WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['payouts'], true ), in_array( 'payout_method_manual', WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['payouts'], true ) ) );

update_option( 'wpac_settings', $g4b_keep );
Settings::flush_cache();
check( 'R8 G4b accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );
