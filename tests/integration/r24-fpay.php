<?php
/**
 * Fix round 24, agent fpay: maximum payout with corrections of held-back commissions (P2-09),
 * one payout per partner and currency at a time (P2-10), per-currency minimum above the
 * converted maximum (P2-11), withholding shown in the partner area (P2-14). P2-15 (SEPA
 * execution date in the site's time zone) is in tests/run.php. Included from run.php before
 * r8-render.php (helpers check(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Withholding;

echo "R24 fpay: maximum payout, one open payout, minimum per currency, withholding in the partner area\n";
global $wpdb;
$fp_keep = get_option( 'wpac_settings' );
$fp_set  = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$fp_set( array( 'min_payout_minor' => 1000, 'max_payout_minor' => 10000, 'currency' => 'EUR', 'exchange_rates' => '', 'min_payout_currency' => '', 'admin_email' => 'ops@shop.example', 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Pay GmbH', 'vies_check' => 0, 'maintenance_mode' => 0, 'payout_reminder' => 'off', 'payout_requests' => 1, 'default_rate_type' => 'percent', 'default_rate' => '10' ) );
$fp_partner = static function ( $slug ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'FPay ' . $slug, 'email' => 'fpay-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'tax_status' => 'private', 'vat_id' => '', 'tax_number' => '', 'legal_name' => 'FPay ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'FPay ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
// A commission of $amount (10 % of the net), approved, $days_ago days old.
$fp_earn = static function ( $aff, $order, $amount, $days_ago, $currency = 'EUR' ) use ( $wpdb ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => $currency, 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $amount * 10 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	$cid = (int) $r['commission_ids'][0];
	Ledger::transition( $cid, 'approved' );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'commissions' ) . ' SET created_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $days_ago ), $cid ) );
	return $cid;
};
// Approved correction (partial refund) of a commission, booked today.
$fp_correct = static function ( $parent_id, $amount ) use ( $wpdb ) {
	$p = Ledger::find( (int) $parent_id );
	$wpdb->insert(
		t( 'commissions' ),
		array(
			'affiliate_id'    => (int) $p->affiliate_id,
			'source'          => $p->source,
			'source_order_id' => $p->source_order_id,
			'source_item_id'  => $p->source_item_id,
			'kind'            => 'refund',
			'parent_id'       => (int) $p->id,
			'currency'        => $p->currency,
			'base_minor'      => 0,
			'amount_minor'    => (int) $amount,
			'rate_type'       => $p->rate_type,
			'rate'            => $p->rate,
			'status'          => 'approved',
			'hold_until'      => $p->hold_until,
			'idempotency_key' => 'fpay-corr-' . (int) $p->id . '-' . wp_generate_password( 8, false ),
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	$id = (int) $wpdb->insert_id;
	WPAffiliateCaptain\Commissions\Balance::post( (int) $p->affiliate_id, $p->currency, 'approved', (int) $amount, $id, 0, 'refund' );
	return $id;
};
$fp_row = static function ( $aff, $currency = 'EUR' ) {
	foreach ( PayoutService::preview( gmdate( 'Y-m-d' ), $currency ) as $r ) {
		if ( $r['affiliate'] && (int) $r['affiliate']->id === (int) $aff ) {
			return $r;
		}
	}
	return null;
};

// --- P2-09: a correction belongs to its commission ---------------------------------------------
// Max 100 €, min 10 €; A 90 € (oldest), B 200 €, partial refund of B −100 €, all approved.
$fp_a  = $fp_partner( 'p209' );
$fp_ca = $fp_earn( $fp_a, 'FPAY-209-A', 9000, 30 );
$fp_cb = $fp_earn( $fp_a, 'FPAY-209-B', 20000, 20 );
$fp_cc = $fp_correct( $fp_cb, -10000 );
$fp_r  = $fp_row( $fp_a );
check( 'P2-09 preview: A (90 €) is paid now; the correction waits with its commission B (no "above maximum")', array( true, '', 9000, true ), array( $fp_r['eligible'], $fp_r['code'], $fp_r['pay_minor'], $fp_r['capped'] ) );
check( 'P2-09 payable: one payout pays 90 € (not −100 €)', array( 10000 + 9000, 9000 ), array( PayoutService::payable( $fp_a, 'EUR' )['gross'], PayoutService::payable( $fp_a, 'EUR' )['payout_gross'] ) );
$fp_pid = PayoutService::create_for_affiliate( $fp_a, gmdate( 'Y-m-d' ), 'EUR' );
check( 'P2-09 the payout pays A only; B and its correction stay payable together', array( true, 9000, array( $fp_pid, 0, 0 ) ), array( $fp_pid > 0, $fp_pid ? (int) PayoutService::find( $fp_pid )->amount_minor : 0, array( (int) Ledger::find( $fp_ca )->payout_id, (int) Ledger::find( $fp_cb )->payout_id, (int) Ledger::find( $fp_cc )->payout_id ) ) );

// --- P2-10: one payout per partner and currency at a time --------------------------------------
$fp_r2 = $fp_row( $fp_a );
$fp_e  = '';
try {
	PayoutService::create_for_affiliate( $fp_a, gmdate( 'Y-m-d' ), 'EUR' );
} catch ( WPAffiliateCaptain\Core\IntegrationException $e ) {
	$fp_e = $e->error_code;
}
$fp_can = PayoutRequests::can_request( AffiliateRepository::find( $fp_a ) );
check( 'P2-10 while the payout is pending: preview names the reason, no second payout, no request (the rest would exceed the maximum together)', array( false, 'open_payout', 'not_eligible', true, '' ), array( $fp_r2['eligible'], $fp_r2['code'], $fp_e, is_string( $fp_can ) && false !== strpos( $fp_can, 'still being processed' ), PayoutRequests::request_currency( $fp_a ) ) );
list( $fp_created, , $fp_details ) = PayoutService::create_run( gmdate( 'Y-m-d' ), 'EUR' );
$fp_mine = array();
foreach ( $fp_details as $d ) {
	if ( (int) $d[0] === $fp_a ) {
		$fp_mine = $d;
	}
}
check( 'P2-10 a second run (double submit) creates no second payout for the partner and says why', array( 1, 'blocked', true ), array( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . " WHERE affiliate_id = %d AND status IN ('pending','exported')", $fp_a ) ), $fp_mine[2] ?? '', false !== strpos( (string) ( $fp_mine[3] ?? '' ), 'not paid yet' ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . " SET status = 'exported' WHERE id = %d", $fp_pid ) );
check( 'P2-10 an exported payout blocks as well', 'open_payout', $fp_row( $fp_a )['code'] );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . " SET status = 'paid', paid_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $fp_pid ) );
$fp_r3   = $fp_row( $fp_a );
$fp_pid2 = PayoutService::create_for_affiliate( $fp_a, gmdate( 'Y-m-d' ), 'EUR' );
check( 'P2-10 once paid: the next payout pays B with its correction (100 €)', array( true, 10000, 10000, array( $fp_pid2, $fp_pid2 ) ), array( $fp_r3['eligible'], $fp_r3['pay_minor'], $fp_pid2 ? (int) PayoutService::find( $fp_pid2 )->amount_minor : 0, array( (int) Ledger::find( $fp_cb )->payout_id, (int) Ledger::find( $fp_cc )->payout_id ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . " SET status = 'paid', paid_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $fp_pid2 ) );

// cap_rows(): unit = commission + its follow-up rows; corrections of paid commissions first.
$fp_treat = array_fill_keys( array( 1, 2, 3, 4, 5 ), array( 'mode' => 'private', 'rate' => '0', 'ref' => 0 ) );
$fp_rows  = array(
	(object) array( 'id' => 1, 'amount_minor' => 9000, 'created_at' => '2026-01-01 00:00:00', 'parent_id' => 0 ),
	(object) array( 'id' => 2, 'amount_minor' => 20000, 'created_at' => '2026-01-02 00:00:00', 'parent_id' => 0 ),
	(object) array( 'id' => 3, 'amount_minor' => -10000, 'created_at' => '2026-01-05 00:00:00', 'parent_id' => 2, 'parent_created_at' => '2026-01-02 00:00:00', 'parent_payout_id' => 0 ),
	(object) array( 'id' => 4, 'amount_minor' => -500, 'created_at' => '2026-01-06 00:00:00', 'parent_id' => 99, 'parent_created_at' => '2025-12-01 00:00:00', 'parent_payout_id' => 7 ),
	(object) array( 'id' => 5, 'amount_minor' => 5000, 'created_at' => '2026-01-03 00:00:00', 'parent_id' => 0 ),
);
check( 'P2-09 cap_rows: correction of a paid-out commission first, then A; B with its correction held back together', array( 4, 1 ), wp_list_pluck( PayoutService::cap_rows( $fp_rows, $fp_treat, 10000 )[0], 'id' ) );
check( 'P2-09 cap_rows: B fits with its correction → taken as a unit in the order of B', array( 4, 1, 2, 3 ), wp_list_pluck( PayoutService::cap_rows( $fp_rows, $fp_treat, 19000 )[0], 'id' ) );

// --- P2-11: minimum per currency above the converted maximum -----------------------------------
$fp_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.92', 'min_payout_minor' => 1000, 'max_payout_minor' => 10000, 'min_payout_currency' => '' ) );
$fp_c1 = SettingsTransfer::check( array( 'min_payout_currency' => 'USD=150' ), Settings::all() );
$fp_c2 = SettingsTransfer::check( array( 'min_payout_currency' => 'USD=100' ), Settings::all() );
$fp_set( array( 'min_payout_currency' => 'USD=100' ) );
$fp_c3 = SettingsTransfer::check( array( 'max_payout_minor' => 5000 ), Settings::all() );
check( 'P2-11 save: USD minimum 150 above the converted maximum (108.70 $) rejected with a field error; 100 accepted; lowering the maximum below it rejected', array( true, true, false, true ), array( isset( $fp_c1['errors']['min_payout_currency'] ), false !== strpos( (string) ( $fp_c1['errors']['min_payout_currency'] ?? '' ), '108' ), isset( $fp_c2['errors']['min_payout_currency'] ), isset( $fp_c3['errors']['max_payout_minor'] ) ) );
// Stored anyway (e.g. an older version or a changed rate): the preview names the real reason.
$fp_set( array( 'min_payout_currency' => 'USD=150' ) );
$fp_u = $fp_partner( 'p211' );
$fp_earn( $fp_u, 'FPAY-211-1', 10000, 10, 'USD' );
$fp_earn( $fp_u, 'FPAY-211-2', 10000, 9, 'USD' );
$fp_ru  = $fp_row( $fp_u, 'USD' );
$fp_cu  = PayoutRequests::can_request( AffiliateRepository::find( $fp_u ) );
check( 'P2-11 preview (USD): own reason "minimum above maximum", not "oldest commission larger than the maximum"; the partner gets a neutral message', array( false, 'min_above_max', true, true ), array( $fp_ru['eligible'], $fp_ru['code'], false !== strpos( $fp_ru['reason'], 'Minimum above maximum' ), is_string( $fp_cu ) && false !== strpos( $fp_cu, 'organisational reasons' ) ) );
$fp_bt = new ReflectionMethod( WPAffiliateCaptain\Admin\Admin::class, 'blocked_text' );
$fp_bt->setAccessible( true );
check( 'P2-10/P2-11 dashboard list of blocked partners names both new reasons', array( true, true ), array( false !== strpos( (string) $fp_bt->invoke( null, $fp_u, 'open_payout', 'USD' ), 'not paid yet' ), false !== strpos( (string) $fp_bt->invoke( null, $fp_u, 'min_above_max', 'USD' ), 'Minimum above maximum' ) ) );
$fp_set( array( 'min_payout_currency' => '', 'exchange_rates' => '', 'max_payout_minor' => 0 ) );

// --- P2-14: withholding in the partner area ----------------------------------------------------
$fp_w = $fp_partner( 'p214' );
$fp_set( array( 'min_payout_minor' => 5000, 'max_payout_minor' => 0 ) );
$fp_earn( $fp_w, 'FPAY-214-1', 6000, 5 );
Withholding::save( $fp_w, '50', 'Quellensteuer' );
$fp_pw = PayoutService::payable( $fp_w, 'EUR' );
check( 'P2-14 payable: expected withholding of the payout (50 % of 60 €)', array( 6000, 3000, 3000 ), array( $fp_pw['payout_gross'], $fp_pw['payout_withheld'], $fp_pw['withheld'] ) );
check( 'P2-14 minimum stays on the gross amount (60 € ≥ 50 €, although 30 € is transferred)', true, true === PayoutRequests::can_request( AffiliateRepository::find( $fp_w ) ) );
$fp_wid = PayoutService::create_for_affiliate( $fp_w, gmdate( 'Y-m-d' ), 'EUR' );
check( 'P2-14 the estimate matches the payout (transferred 30 €)', 3000, (int) PayoutService::find( $fp_wid )->amount_minor );
Withholding::save( $fp_w, '0', '' );
check( 'P2-14 without withholding: 0', 0, PayoutService::payable( $fp_a, 'EUR' )['payout_withheld'] );

update_option( 'wpac_settings', $fp_keep );
Settings::flush_cache();
