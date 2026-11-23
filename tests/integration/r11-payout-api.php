<?php
/**
 * Integration checks of the payout API for add-ons (contract 1.3.0): wpac_get_payout(),
 * wpac_get_payouts(), wpac_claim_payout(), wpac_complete_payout(), wpac_release_payout().
 * A payment-provider add-on (e.g. PayPal Payouts) reads the payout, claims it, pays it and marks
 * it paid – with the same guards against paying twice as the export files.
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "R11 payout API for add-ons\n";

function r11_partner( $slug, array $profile = array() ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'R11 ' . $slug, 'email' => 'r11-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'tax_status' => 'private', 'legal_name' => 'R11 ' . $slug, 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'R11 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
}
function r11_payout( $aff, $order, $net = 150000 ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return (int) PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' );
}
function r11_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
}

$r11_pp   = r11_partner( 'paypal', array( 'payout_method' => 'manual', 'payment_details' => "PayPal: R11.PayPal@Example.de\nBitte mit Referenz" ) );
$r11_sepa = r11_partner( 'sepa' );
$r11_p1   = r11_payout( $r11_pp, 'r11-1' );
$r11_p2   = r11_payout( $r11_sepa, 'r11-2' );

// Read.
$r11_v = wpac_get_payout( $r11_p1 );
check(
	'R11 wpac_get_payout: number, partner, status, currency, amount, method, PayPal address; no bank details',
	array( (string) PayoutService::find( $r11_p1 )->number, $r11_pp, 'pending', 'EUR', 15000, 'manual', 'r11.paypal@example.de', '' ),
	is_array( $r11_v ) ? array( $r11_v['number'], $r11_v['affiliate_id'], $r11_v['status'], $r11_v['currency'], $r11_v['amount_minor'], $r11_v['method'], $r11_v['paypal_email'], $r11_v['claim'] ) : array()
);
check( 'R11 wpac_get_payout never returns an IBAN', false, false !== strpos( (string) wp_json_encode( wpac_get_payout( $r11_p2 ) ), 'DE89' ) );
check( 'R11 wpac_get_payout: SEPA partner has no PayPal address', array( 'sepa', '' ), array( wpac_get_payout( $r11_p2 )['method'], wpac_get_payout( $r11_p2 )['paypal_email'] ) );
check( 'R11 wpac_get_payout: unknown payout', null, wpac_get_payout( 999999 ) );

$r11_list = wpac_get_payouts( array( 'status' => 'pending', 'method' => 'manual', 'limit' => 200 ) );
check( 'R11 wpac_get_payouts filters by status and method', array( true, false ), array( in_array( $r11_p1, array_column( $r11_list, 'id' ), true ), in_array( $r11_p2, array_column( $r11_list, 'id' ), true ) ) );
check( 'R11 wpac_get_payouts: unknown status gives nothing', array(), wpac_get_payouts( array( 'status' => 'nonsense' ) ) );
check( 'R11 wpac_get_payouts: limit is honoured', 1, count( wpac_get_payouts( array( 'limit' => 1 ) ) ) );

// Claim.
check( 'R11 wpac_claim_payout refuses an invalid provider key', 'wpac_provider', r11_code( wpac_claim_payout( $r11_p1, 'Pay Pal!' ) ) );
$r11_c = wpac_claim_payout( $r11_p1, 'paypal' );
$r11_claim = is_array( $r11_c ) ? $r11_c['claim'] : '';
check( 'R11 claim: payout is exported under an API claim', array( 'exported', 1 ), array( (string) PayoutService::find( $r11_p1 )->status, preg_match( '/^API-PAYPAL-' . $r11_p1 . '-[A-Z0-9]{6}$/', $r11_claim ) ) );
check( 'R11 claim: a second claim is refused', 'wpac_payout_state', r11_code( wpac_claim_payout( $r11_p1, 'stripe' ) ) );
check( 'R11 claim: the PayPal payouts file does not take a claimed payout again', 'wpac_claimed', r11_code( PayoutService::paypal_csv( array( $r11_p1 ) ) ) );
check( 'R11 claim: the transfer list does not take a claimed payout again', 'wpac_claimed', r11_code( PayoutService::transfer_csv( array( $r11_p1 ) ) ) );
check( 'R11 claim is in the audit log', 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.claimed' AND object_id = %d", $r11_p1 ) ) );

// Complete: only with the right claim, then the payout and its commissions are paid.
check( 'R11 complete with a wrong claim is refused', 'wpac_claim', r11_code( wpac_complete_payout( $r11_p1, 'API-PAYPAL-' . $r11_p1 . '-WRONG1', 'x' ) ) );
check( 'R11 complete with a non-claim reference is refused', 'wpac_claim', r11_code( wpac_complete_payout( $r11_p1, '', 'x' ) ) );
check( 'R11 a pending payout cannot be completed without a claim', 'wpac_claim', r11_code( wpac_complete_payout( $r11_p2, 'API-PAYPAL-' . $r11_p2 . '-AAAAAA', 'x' ) ) );
$r11_paid_hook = array();
add_action( 'wpac_payout_paid', static function ( $id ) use ( &$r11_paid_hook ) { $r11_paid_hook[] = (int) $id; } );
$r11_done = wpac_complete_payout( $r11_p1, $r11_claim, 'PAYPAL-BATCH-123' );
remove_all_filters( 'wpac_payout_paid' );
$r11_comm = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT c.status FROM ' . t( 'payout_items' ) . ' i JOIN ' . t( 'commissions' ) . ' c ON c.id = i.commission_id WHERE i.payout_id = %d LIMIT 1', $r11_p1 ) );
check( 'R11 complete: payout and commissions paid, wpac_payout_paid fired once', array( true, 'paid', 'paid', array( $r11_p1 ) ), array( $r11_done, (string) PayoutService::find( $r11_p1 )->status, $r11_comm, $r11_paid_hook ) );
check( 'R11 complete: provider reference in the audit log', 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.provider_paid' AND object_id = %d AND details LIKE %s", $r11_p1, '%PAYPAL-BATCH-123%' ) ) );
check( 'R11 complete twice is refused (already paid)', 'wpac_state', r11_code( wpac_complete_payout( $r11_p1, $r11_claim, 'again' ) ) );
check( 'R11 a paid payout shows no PayPal address any more', '', wpac_get_payout( $r11_p1 )['paypal_email'] );

// Release: the payout is created again and can be exported or claimed anew.
$r11_p3 = r11_payout( $r11_pp, 'r11-3' );
$r11_c3 = wpac_claim_payout( $r11_p3, 'paypal' );
check( 'R11 release with a wrong claim is refused', 'wpac_claim', r11_code( wpac_release_payout( $r11_p3, 'API-PAYPAL-' . $r11_p3 . '-WRONG1', 'x' ) ) );
check( 'R11 release: payout is created again, claim cleared', array( true, 'pending', '' ), array( wpac_release_payout( $r11_p3, $r11_c3['claim'], 'PayPal refused: receiver unconfirmed' ), (string) PayoutService::find( $r11_p3 )->status, wpac_get_payout( $r11_p3 )['claim'] ) );
check( 'R11 release: the old claim cannot complete the payout', 'wpac_claim', r11_code( wpac_complete_payout( $r11_p3, $r11_c3['claim'], 'late' ) ) );
check( 'R11 release: the payout can be claimed again', 'exported', is_array( wpac_claim_payout( $r11_p3, 'paypal' ) ) ? (string) PayoutService::find( $r11_p3 )->status : 'not claimed' );

// A SEPA export (no claim) cannot be released or completed through the API.
$r11_p4 = r11_payout( $r11_sepa, 'r11-4' );
PayoutService::sepa_export( array( $r11_p4 ) );
$r11_batch = (string) PayoutService::find( $r11_p4 )->sepa_batch;
check( 'R11 an export file is not a claim: release refused', 'wpac_claim', r11_code( wpac_release_payout( $r11_p4, $r11_batch, 'x' ) ) );
check( 'R11 an export file is not a claim: complete refused', 'wpac_claim', r11_code( wpac_complete_payout( $r11_p4, $r11_batch, 'x' ) ) );
check( 'R11 an exported payout cannot be claimed', 'wpac_payout_state', r11_code( wpac_claim_payout( $r11_p4, 'paypal' ) ) );

// Partner changed the payout details after the payout was created: refused like the export files.
$r11_p5 = r11_payout( $r11_pp, 'r11-5' );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '" . gmdate( 'Y-m-d H:i:s', time() + 60 ) . "' WHERE affiliate_id = " . (int) $r11_pp );
check( 'R11 claim refused after the partner changed the payout details', array( 'wpac_profile_changed', 'pending' ), array( r11_code( wpac_claim_payout( $r11_p5, 'paypal' ) ), (string) PayoutService::find( $r11_p5 )->status ) );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $r11_pp );

// A cancelled payout cannot be claimed.
$r11_p6 = r11_payout( $r11_pp, 'r11-6' );
PayoutService::cancel( $r11_p6, 'test' );
check( 'R11 a cancelled payout cannot be claimed', 'wpac_payout_state', r11_code( wpac_claim_payout( $r11_p6, 'paypal' ) ) );
check( 'R11 contract version is 1.8.1 (same major as the 1.3.0 payout API)', '1.8.1', wpac_contract_version() );

// Cancelling a claimed payout in the admin: refused for 45 days (filter wpac_claim_cancel_after) (the provider may be paying it),
// then only with the confirmation that the provider did not pay it.
$r11_p7 = r11_payout( $r11_pp, 'r11-7' );
$r11_c7 = wpac_claim_payout( $r11_p7, 'paypal' );
check( 'R11 a fresh claim cannot be cancelled in the admin, even with confirmation', array( 'wpac_claimed', 'exported' ), array( r11_code( PayoutService::cancel( $r11_p7, 'test', true ) ), (string) PayoutService::find( $r11_p7 )->status ) );
$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET exported_at = '" . gmdate( 'Y-m-d H:i:s', time() - 46 * DAY_IN_SECONDS ) . "' WHERE id = " . (int) $r11_p7 );
$r11_x7 = PayoutService::cancel( $r11_p7, 'test' );
check( 'R11 after 45 days: confirmation required, the message names the payment provider, not the bank', array( 'wpac_exported', true ), array( r11_code( $r11_x7 ), is_wp_error( $r11_x7 ) && false !== strpos( $r11_x7->get_error_message(), $r11_c7['claim'] ) ) );
check( 'R11 after 45 days with confirmation the claimed payout is cancelled; the old claim cannot pay it', array( true, 'cancelled', 'wpac_state' ), array( PayoutService::cancel( $r11_p7, 'test', true ), (string) PayoutService::find( $r11_p7 )->status, r11_code( wpac_complete_payout( $r11_p7, $r11_c7['claim'], 'late' ) ) ) );

// Cancellation guard for claimed payouts: 45 days by default, adjustable by filter, never below one day.
check( 'R11 claim cancel guard defaults to 45 days', 45 * DAY_IN_SECONDS, PayoutService::claim_cancel_after() );
$r11_f = static function () { return 60; };
add_filter( 'wpac_claim_cancel_after', $r11_f );
check( 'R11 claim cancel guard is never below one day', DAY_IN_SECONDS, PayoutService::claim_cancel_after() );
remove_filter( 'wpac_claim_cancel_after', $r11_f );
