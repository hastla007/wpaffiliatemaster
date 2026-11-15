<?php
/**
 * Integration checks of audit round 12 (payout money paths): R12-K1 (claimed payouts are never
 * exported again), R12-K2 (no credit note of a cancelled payout without its cancellation),
 * R12-K7 (document prefixes pairwise different), R12-M4 (wpac_get_payouts() cursor and method
 * filter before the limit), R12-M6 (cut-off of a payout run in the site's time zone).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R12-A1 payout money paths\n";

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz = (string) get_option( 'timezone_string', '' );
		return new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
	}
}

function r12a1_partner( $slug, array $profile = array() ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'R12A1 ' . $slug, 'email' => 'r12a1-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'tax_status' => 'private', 'legal_name' => 'R12A1 ' . $slug, 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'R12A1 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
}
function r12a1_commission( $aff, $order, $net = 150000 ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return (array) $r['commission_ids'];
}
function r12a1_payout( $aff, $order, $net = 150000 ) {
	r12a1_commission( $aff, $order, $net );
	return (int) PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' );
}
function r12a1_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
}
function r12a1_async( $on ) {
	$prop = new ReflectionProperty( PayoutService::class, 'async_statements' );
	$prop->setAccessible( true );
	$prop->setValue( null, (bool) $on );
}

// ---------------------------------------------------------------------------------------------
// R12-K1: a claimed payout is refused by every export, even with "export again".
$k1_sepa = r12a1_partner( 'k1-sepa' );
$k1_pp   = r12a1_partner( 'k1-pp', array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: k1@example.de' ) );
$k1_p    = r12a1_payout( $k1_sepa, 'r12a1-k1-1' );
$k1_c    = wpac_claim_payout( $k1_p, 'paypal' );
$k1_claim = is_array( $k1_c ) ? $k1_c['claim'] : '';
$k1_x    = PayoutService::sepa_export( array( $k1_p ), true );
check( 'R12-K1 repro: claim, then SEPA export with "export again" is refused (wpac_claimed, message names the claim)', array( 'wpac_claimed', true ), array( r12a1_code( $k1_x ), is_wp_error( $k1_x ) && false !== strpos( $k1_x->get_error_message(), $k1_claim ) ) );
check( 'R12-K1 the claim is kept after the refused export', array( 'exported', $k1_claim ), array( (string) PayoutService::find( $k1_p )->status, (string) PayoutService::find( $k1_p )->sepa_batch ) );
check( 'R12-K1 transfer list with "export again" refuses a claimed payout', 'wpac_claimed', r12a1_code( PayoutService::transfer_csv( array( $k1_p ), true ) ) );
$k1_pp_p = r12a1_payout( $k1_pp, 'r12a1-k1-2' );
$k1_pp_c = wpac_claim_payout( $k1_pp_p, 'paypal' );
check( 'R12-K1 PayPal file with "export again" refuses a claimed payout', 'wpac_claimed', r12a1_code( PayoutService::paypal_csv( array( $k1_pp_p ), true ) ) );
check( 'R12-K1 without "export again" the claimed payout is refused as claimed as well', 'wpac_claimed', r12a1_code( PayoutService::sepa_export( array( $k1_p ) ) ) );
check( 'R12-K1 complete_claim still works after the refused exports', array( true, 'paid' ), array( wpac_complete_payout( $k1_p, $k1_claim, 'PP-1' ), (string) PayoutService::find( $k1_p )->status ) );
check( 'R12-K1 release_claim still works after a refused PayPal export', true, wpac_release_payout( $k1_pp_p, $k1_pp_c['claim'], 'test' ) );
// Unchanged: an ordinary export can be exported again with the confirmation.
$k1_p2 = r12a1_payout( $k1_sepa, 'r12a1-k1-3' );
$k1_e1 = PayoutService::sepa_export( array( $k1_p2 ) );
check( 'R12-K1 ordinary export: second export without confirmation refused (wpac_reexport)', 'wpac_reexport', r12a1_code( PayoutService::sepa_export( array( $k1_p2 ) ) ) );
$k1_e2 = PayoutService::sepa_export( array( $k1_p2 ), true );
check( 'R12-K1 ordinary export: "export again" still works', array( true, true ), array( is_array( $k1_e1 ), is_array( $k1_e2 ) ) );
PayoutService::cancel( $k1_p2, 'test', true );

// ---------------------------------------------------------------------------------------------
// R12-K2: payout of a run (credit note written later by the queue) cancelled before its PDF.
$k2_aff = r12a1_partner( 'k2' );
r12a1_async( true );
try {
	$k2_p = r12a1_payout( $k2_aff, 'r12a1-k2-1' );
} finally {
	r12a1_async( false );
}
$k2_before = PayoutService::find( $k2_p );
check( 'R12-K2 setup: payout of a run has a credit note number but no PDF yet', array( true, '' ), array( '' !== (string) $k2_before->number, (string) $k2_before->statement_file ) );
$k2_seq = static function ( $name ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM ' . t( 'sequences' ) . ' WHERE name = %s AND year = %d', $name, (int) wp_date( 'Y' ) ) );
};
$k2_storno_seq = $k2_seq( 'storno' );
check( 'R12-K2 cancel before the PDF succeeds', true, PayoutService::cancel( $k2_p, 'test K2' ) );
$k2_after = PayoutService::find( $k2_p );
check(
	'R12-K2 cancelled before the PDF: credit note PDF and ST- cancellation document both exist',
	array( 'cancelled', true, 1, true ),
	array( (string) $k2_after->status, '' !== (string) $k2_after->statement_file, preg_match( '/^ST-\d{4}-\d{6}$/', (string) $k2_after->storno_number ), '' !== (string) $k2_after->storno_file )
);
check( 'R12-K2 numbering stays gapless: the cancellation takes the next number, the credit note keeps its number', array( $k2_storno_seq + 1, $k2_storno_seq + 1, (string) $k2_before->number ), array( $k2_seq( 'storno' ), (int) substr( (string) $k2_after->storno_number, -6 ), (string) $k2_after->number ) );
$k2_file = (string) $k2_after->statement_file;
check( 'R12-K2 claim of the cancelled payout refused, no new document', array( 'wpac_payout_state', $k2_file ), array( r12a1_code( wpac_claim_payout( $k2_p, 'paypal' ) ), (string) PayoutService::find( $k2_p )->statement_file ) );
check( 'R12-K2 export and mark_paid of the cancelled payout refused', array( 'wpac_empty', 'wpac_empty', 'wpac_state' ), array( r12a1_code( PayoutService::sepa_export( array( $k2_p ) ) ), r12a1_code( PayoutService::transfer_csv( array( $k2_p ) ) ), r12a1_code( PayoutService::mark_paid( $k2_p ) ) ) );
check( 'R12-K2 the queued credit note job does nothing for the cancelled payout', array( true, $k2_file ), array( PayoutService::run_job( false, PayoutService::STATEMENT_JOB, array( 'payout_id' => $k2_p ) ), (string) PayoutService::find( $k2_p )->statement_file ) );

// Payout cancelled by an earlier version (no cancellation number, no PDF): never gets a credit note.
r12a1_async( true );
try {
	$k2_old = r12a1_payout( $k2_aff, 'r12a1-k2-2' );
} finally {
	r12a1_async( false );
}
PayoutService::cancel( $k2_old, 'test legacy' );
// As cancelled by an earlier version: no cancellation number, no documents.
$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET statement_file = '', statement_hash = '', storno_number = '', storno_file = '', storno_hash = '', storno_at = NULL WHERE id = " . (int) $k2_old );
check( 'R12-K2 Statement::generate() refuses a cancelled payout without cancellation number', array( false, '' ), array( Statement::generate( $k2_old ), (string) PayoutService::find( $k2_old )->statement_file ) );
check( 'R12-K2 statement_missing() does not generate for a cancelled payout', array( null, '' ), array( PayoutService::statement_missing( $k2_old ), (string) PayoutService::find( $k2_old )->statement_file ) );
check( 'R12-K2 claim/export/mark_paid leave no credit note for the cancelled payout', array( 'wpac_payout_state', 'wpac_empty', 'wpac_state', '' ), array( r12a1_code( wpac_claim_payout( $k2_old, 'paypal' ) ), r12a1_code( PayoutService::sepa_export( array( $k2_old ) ) ), r12a1_code( PayoutService::mark_paid( $k2_old ) ), (string) PayoutService::find( $k2_old )->statement_file ) );
PayoutService::generate_missing_statements( 100, 0 );
check( 'R12-K2 the hourly retry does not write a credit note for it either', '', (string) PayoutService::find( $k2_old )->statement_file );

// ---------------------------------------------------------------------------------------------
// R12-K7: number prefixes must be pairwise different.
Settings::flush_cache();
$k7_old = array( Settings::get( 'credit_prefix' ), Settings::get( 'storno_prefix' ), Settings::get( 'correction_prefix' ) );
$k7_out = Settings::sanitize( array( 'credit_prefix' => 'GS', 'storno_prefix' => 'gs', 'correction_prefix' => 'KG2' ), $k7_err );
check( 'R12-K7 same prefix for credit notes and cancellations: error on both fields', array( true, true, false ), array( isset( $k7_err['credit_prefix'] ), isset( $k7_err['storno_prefix'] ), isset( $k7_err['correction_prefix'] ) ) );
check( 'R12-K7 on a conflict all three prefixes keep their stored values', $k7_old, array( $k7_out['credit_prefix'], $k7_out['storno_prefix'], $k7_out['correction_prefix'] ) );
$k7_out = Settings::sanitize( array( 'credit_prefix' => 'KG', 'storno_prefix' => 'ST', 'correction_prefix' => 'KG' ), $k7_err );
check( 'R12-K7 same prefix for credit notes and correction credit notes refused', array( true, false, true, $k7_old ), array( isset( $k7_err['credit_prefix'] ), isset( $k7_err['storno_prefix'] ), isset( $k7_err['correction_prefix'] ), array( $k7_out['credit_prefix'], $k7_out['storno_prefix'], $k7_out['correction_prefix'] ) ) );
$k7_out = Settings::sanitize( array( 'credit_prefix' => 'gs2', 'storno_prefix' => 'ST2', 'correction_prefix' => 'KG2' ), $k7_err );
check( 'R12-K7 different prefixes are accepted', array( false, false, false, array( 'GS2', 'ST2', 'KG2' ) ), array( isset( $k7_err['credit_prefix'] ), isset( $k7_err['storno_prefix'] ), isset( $k7_err['correction_prefix'] ), array( $k7_out['credit_prefix'], $k7_out['storno_prefix'], $k7_out['correction_prefix'] ) ) );

// ---------------------------------------------------------------------------------------------
// R12-M4: wpac_get_payouts() pages with after_id and applies the method filter before the limit.
$m4_sepa  = r12a1_partner( 'm4-sepa' );
$m4_pp    = r12a1_partner( 'm4-pp', array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: m4@example.de' ) );
$m4_start = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . t( 'payouts' ) );
$m4_s1    = r12a1_payout( $m4_sepa, 'r12a1-m4-1' );
$m4_s2    = r12a1_payout( $m4_sepa, 'r12a1-m4-2' );
$m4_s3    = r12a1_payout( $m4_sepa, 'r12a1-m4-3' );
$m4_m1    = r12a1_payout( $m4_pp, 'r12a1-m4-4' );
check( 'R12-M4 setup: four payouts', true, $m4_s1 && $m4_s2 && $m4_s3 && $m4_m1 );
check( 'R12-M4 method filter before the limit: manual payout found behind three SEPA payouts', array( $m4_m1 ), array_column( wpac_get_payouts( array( 'method' => 'manual', 'after_id' => $m4_start, 'limit' => 2 ) ), 'id' ) );
check( 'R12-M4 SEPA filter with after_id and limit', array( $m4_s1, $m4_s2 ), array_column( wpac_get_payouts( array( 'method' => 'sepa', 'after_id' => $m4_start, 'limit' => 2 ) ), 'id' ) );
$m4_seen  = array();
$m4_after = $m4_start;
for ( $i = 0; $i < 10; $i++ ) {
	$page = wpac_get_payouts( array( 'after_id' => $m4_after, 'limit' => 1 ) );
	if ( ! $page ) {
		break;
	}
	$m4_seen[] = $page[0]['id'];
	$m4_after  = $page[0]['id'];
}
check( 'R12-M4 paging with after_id reaches every payout once, oldest first', array( $m4_s1, $m4_s2, $m4_s3, $m4_m1 ), $m4_seen );
check( 'R12-M4 unknown status still gives an empty list (contract)', array(), wpac_get_payouts( array( 'status' => 'nonsense' ) ) );
check( 'R12-M4 unknown method gives an empty list', array(), wpac_get_payouts( array( 'method' => 'nonsense', 'after_id' => $m4_start ) ) );
check( 'R12-M4 without after_id the list still starts with the oldest payout', (int) $wpdb->get_var( 'SELECT MIN(id) FROM ' . t( 'payouts' ) ), wpac_get_payouts( array( 'limit' => 1 ) )[0]['id'] );

// ---------------------------------------------------------------------------------------------
// R12-M6: the period end is the end of that day in the site's time zone.
$m6_tz = get_option( 'timezone_string', null );
update_option( 'timezone_string', 'Europe/Berlin' );
check( 'R12-M6 cut-off in winter (UTC+1)', '2026-11-30 22:59:59', PayoutService::cutoff( '2026-11-30' ) );
check( 'R12-M6 cut-off in summer (UTC+2)', '2026-07-31 21:59:59', PayoutService::cutoff( '2026-07-31' ) );
$m6_aff   = r12a1_partner( 'm6' );
$m6_late  = r12a1_commission( $m6_aff, 'r12a1-m6-1', 100000 ); // 1 December, 00:30 local.
$m6_early = r12a1_commission( $m6_aff, 'r12a1-m6-2', 50000 );  // 30 November, 23:30 local.
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2026-11-30 23:30:00' WHERE id IN (" . implode( ',', array_map( 'intval', $m6_late ) ) . ')' );
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2026-11-30 22:30:00' WHERE id IN (" . implode( ',', array_map( 'intval', $m6_early ) ) . ')' );
$m6_pay = PayoutService::payable( $m6_aff, 'EUR', '2026-11-30' );
check( 'R12-M6 November run (Berlin) holds back the commission booked after local midnight', array( 1, 5000 ), array( $m6_pay['count'], $m6_pay['net'] ) );
$m6_id = (int) PayoutService::create_for_affiliate( $m6_aff, '2026-11-30', 'EUR' );
$m6_in = $m6_id ? array_map( 'intval', array_column( PayoutService::items( $m6_id ), 'commission_id' ) ) : array();
check( 'R12-M6 the payout of the November run contains only the commission of 30 November local time', array_map( 'intval', $m6_early ), $m6_in );
update_option( 'timezone_string', 'UTC' );
check( 'R12-M6 with UTC the cut-off is 23:59:59 of that day', '2026-11-30 23:59:59', PayoutService::cutoff( '2026-11-30' ) );
if ( $m6_id ) {
	PayoutService::cancel( $m6_id, 'test' );
}
foreach ( array_merge( $m6_late, $m6_early ) as $cid ) {
	Ledger::transition( (int) $cid, 'rejected' ); // Not payable in later tests.
}
if ( null === $m6_tz ) {
	unset( $GLOBALS['test_options']['timezone_string'] );
} else {
	update_option( 'timezone_string', $m6_tz );
}
