<?php
/**
 * Audit round 12 (money): correction credit notes per VAT part (R12-K3), settled corrections on
 * the next credit note and in the §19 warning (R12-M7), pending increases on a full reversal
 * (R12-M1), refunds after a correction (R12-M2), after-commit callbacks (R12-M5), amount input
 * (R12-K6), objection text without a document (R12-N1), exchange rate precision (R12-N2).
 * Included from run.php (helpers check(), t(), q() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\Objections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R12 A2 correction VAT parts, settled corrections, increases, refunds after corrections\n";
global $wpdb;
$a2_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $a2_keep, array( 'min_payout_minor' => 100, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );

$a2_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'A2 ' . $slug, 'email' => 'a2-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'A2 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'A2 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$a2_earn = static function ( $aff, $order, $net, $source = 'test', $approve = true ) {
	$r = wpac_record_sale( array( 'source' => $source, 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'a2-kunde-' . $order . '@example.de' ) ) );
	if ( $approve ) {
		foreach ( (array) $r['commission_ids'] as $cid ) {
			Ledger::transition( (int) $cid, 'approved' );
		}
	}
	return (int) $r['commission_ids'][0];
};
$a2_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
$a2_kg = static function ( $r ) use ( $a2_code ) {
	$c = is_wp_error( $r ) ? null : Corrections::find( (int) $r );
	return $c ? array( (int) $c->prev_net_minor, (int) $c->prev_vat_minor, (int) $c->net_minor, (int) $c->vat_minor, (string) $c->vat_mode, (string) $c->vat_rate, (int) Ledger::find( (int) $c->commission_id )->amount_minor ) : $a2_code( $r );
};
$a2_past   = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
$a2_change = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

/* R12-K3: small business at the time of the service, VAT-registered when paid. */
$a2_a  = $a2_partner( 'sb2std', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_c1 = $a2_earn( $a2_a, 'a2-1', 100000 );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$a2_past}' WHERE id = " . (int) $a2_c1 );
update_option( 'wpac_tax_history_' . $a2_a, array( array( 'until' => $a2_change, 'tax_status' => 'small_business', 'country' => 'DE', 'vat_id' => '', 'tax_number' => '12/345/67890' ) ) );
$a2_pa  = PayoutService::create_for_affiliate( $a2_a, gmdate( 'Y-m-d' ), 'EUR' );
$a2_pao = PayoutService::find( $a2_pa );
check( 'R12-K3 setup: credit note in the current treatment (standard), the line as small business', array( 'standard', 10000, 0 ), array( (string) $a2_pao->vat_mode, (int) $a2_pao->net_minor, (int) $a2_pao->vat_minor ) );
PayoutService::mark_paid( $a2_pa );
check( 'R12-K3 parts of the credit note come from its lines (treatment at the time of the service)', array( 'small_business|0' => array( 'small_business', '0', 10000, 0 ) ), array_map( static function ( $g ) { return array( $g['mode'], $g['rate'], $g['net'], $g['vat'] ); }, Corrections::parts( $a2_pao ) ) );
$a2_bal = Balance::get( $a2_a, 'EUR' );
check( 'R12-K3 small business → regular: correction without VAT (treatment of the line, not of the payout)', array( 10000, 0, 9000, 0, 'small_business', '0', -1000 ), $a2_kg( Corrections::create( $a2_pa, 9000, 'Satz 9 %', 'a2-k1', 10000 ) ) );
check( 'R12-K3 balance moves by the gross difference only', -1000, Balance::get( $a2_a, 'EUR' ) - $a2_bal );
$a2_k2 = Corrections::create( $a2_pa, 12000, 'Satz 12 %', 'a2-k2', 9000 );
check( 'R12-K3 a further correction starts from the stored corrected amounts of that part', array( 9000, 0, 12000, 0, 'small_business', '0', 3000 ), $a2_kg( $a2_k2 ) );

/* R12-K3: a payout that only settles correction credit notes is not taxable and cannot be corrected. */
$a2_pk  = PayoutService::create_for_affiliate( $a2_a, gmdate( 'Y-m-d' ), 'EUR' );
$a2_pko = PayoutService::find( $a2_pk );
check( 'R12-K3 payout of settled corrections only: no VAT on them', array( 2000, 0, 2000 ), $a2_pko ? array( (int) $a2_pko->net_minor, (int) $a2_pko->vat_minor, (int) $a2_pko->amount_minor ) : array() );
PayoutService::mark_paid( $a2_pk );
$a2_pko = PayoutService::find( $a2_pk );
check( 'R12-K3 such a credit note has no taxable part and gets no correction', array( array(), true, 'wpac_state' ), array( Corrections::parts( $a2_pko ), false !== strpos( Corrections::blocked( $a2_pko ), 'no taxable' ), $a2_code( Corrections::create( $a2_pk, 0, 'x', 'a2-kk' ) ) ) );
$a2_pdf = Statement::build( $a2_pko );
check( 'R12-M7 credit note of settled corrections: a line of their own, no "net" and no "VAT 0 %"', array( true, false, false ), array( false !== strpos( $a2_pdf, 'VAT shown there' ), false !== strpos( $a2_pdf, 'Net amount' ), false !== strpos( $a2_pdf, 'VAT 0 %' ) ) );

/* R12-K3: regular at the time of the service, small business when paid. */
$a2_b  = $a2_partner( 'std2sb', array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890' ) );
$a2_c2 = $a2_earn( $a2_b, 'a2-2', 100000 );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$a2_past}' WHERE id = " . (int) $a2_c2 );
update_option( 'wpac_tax_history_' . $a2_b, array( array( 'until' => $a2_change, 'tax_status' => 'business', 'country' => 'DE', 'vat_id' => 'DE123456789', 'tax_number' => '' ) ) );
$a2_pb = PayoutService::create_for_affiliate( $a2_b, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_pb );
$a2_bal = Balance::get( $a2_b, 'EUR' );
$a2_kb  = Corrections::create( $a2_pb, 0, 'Storno Bestellung', 'a2-kb', 10000 );
check( 'R12-K3 regular → small business: the VAT of the line is corrected (19 %)', array( 10000, 1900, 0, 0, 'standard', '19', -11900 ), $a2_kg( $a2_kb ) );
check( 'R12-K3 balance moves by net and VAT', -11900, Balance::get( $a2_b, 'EUR' ) - $a2_bal );
$a2_kbo = Corrections::find( (int) $a2_kb );
check( 'R12-K3 document shows the VAT rate of the corrected part', true, false !== strpos( Corrections::build( $a2_kbo ), 'VAT rate of the corrected credit note: 19 %' ) );

/* R12-K3: chain 100.00 → 100.03 → 100.06 → 0 leaves nothing (VAT on the corrected total). */
$a2_c = $a2_partner( 'chain', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_earn( $a2_c, 'a2-3', 100000 );
$a2_pc = PayoutService::create_for_affiliate( $a2_c, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_pc );
$a2_sum = 0;
$a2_vat = array();
foreach ( array( array( 10003, 10000 ), array( 10006, 10003 ), array( 0, 10006 ) ) as $a2_i => $a2_step ) {
	$a2_r = Corrections::create( $a2_pc, $a2_step[0], 'Kette ' . $a2_i, 'a2-chain-' . $a2_i, $a2_step[1] );
	$a2_o = is_wp_error( $a2_r ) ? null : Corrections::find( (int) $a2_r );
	$a2_sum  += $a2_o ? (int) Ledger::find( (int) $a2_o->commission_id )->amount_minor : 0;
	$a2_vat[] = $a2_o ? (int) $a2_o->vat_minor : $a2_code( $a2_r );
}
check( 'R12-K3 chain of corrections: VAT always on the corrected net, nothing left at 0', array( array( 1901, 1901, 0 ), -11900 ), array( $a2_vat, $a2_sum ) );

/* R12-K3: mixed credit note (services before and after a change of the tax status). */
$a2_m  = $a2_partner( 'mixed', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_m1 = $a2_earn( $a2_m, 'a2-4', 100000 );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$a2_past}' WHERE id = " . (int) $a2_m1 );
$a2_earn( $a2_m, 'a2-5', 50000 );
update_option( 'wpac_tax_history_' . $a2_m, array( array( 'until' => $a2_change, 'tax_status' => 'small_business', 'country' => 'DE', 'vat_id' => '', 'tax_number' => '12/345/67890' ) ) );
$a2_pm  = PayoutService::create_for_affiliate( $a2_m, gmdate( 'Y-m-d' ), 'EUR' );
$a2_pmo = PayoutService::find( $a2_pm );
PayoutService::mark_paid( $a2_pm );
check( 'R12-K3 mixed credit note: two parts', array( 'small_business|0' => 10000, 'standard|19' => 5000 ), array_map( static function ( $g ) { return $g['net']; }, Corrections::parts( $a2_pmo ) ) );
check( 'R12-K3 mixed credit note: a correction without the part is refused (no payout-level rate)', array( 'wpac_part', 0 ), array( $a2_code( Corrections::create( $a2_pm, 4000, 'x', 'a2-m0' ) ), count( Corrections::for_payout( $a2_pm ) ) ) );
check( 'R12-K3 mixed credit note: the standard part is corrected with its own VAT', array( 5000, 950, 4000, 760, 'standard', '19', -1190 ), $a2_kg( Corrections::create( $a2_pm, 4000, 'Satz', 'a2-m1', 5000, 'standard|19' ) ) );
check( 'R12-K3 mixed credit note: stale "before" of a part is refused', 'wpac_changed', $a2_code( Corrections::create( $a2_pm, 3000, 'x', 'a2-m2', 5000, 'standard|19' ) ) );
$a2_km = Corrections::create( $a2_pm, 0, 'Storno', 'a2-m3', 10000, 'small_business|0' );
check( 'R12-K3 mixed credit note: the small-business part without VAT', array( 10000, 0, 0, 0, 'small_business', '0', -10000 ), $a2_kg( $a2_km ) );
$a2_kmpdf = Corrections::build( Corrections::find( (int) $a2_km ) );
check( 'R12-K3 document of a part names the part', true, false !== strpos( $a2_kmpdf, 'concerns only the part of credit note ' . $a2_pmo->number ) );
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_form( PayoutService::find( $a2_pm ) );
$a2_form = ob_get_clean();
check( 'R12-K3 form of a mixed credit note: one input per part with its own "before"', array( true, true, true, true ), array( false !== strpos( $a2_form, 'name="parts" value="2"' ), false !== strpos( $a2_form, 'name="part_1" value="standard|19"' ), false !== strpos( $a2_form, 'name="base_net_1" value="4000"' ), false !== strpos( $a2_form, 'name="net_0"' ) ) );

/* R12-M7: annual overview and §19 warning count a correction at its own date, net. */
$a2_s = $a2_partner( 'sb19', array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890' ) );
$a2_earn( $a2_s, 'a2-6', 99000000 ); // 99,000.00 € commission.
$a2_ps = PayoutService::create_for_affiliate( $a2_s, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_ps );
$a2_warn = static function () use ( $a2_s ) {
	foreach ( PayoutService::small_business_warnings() as $w ) {
		if ( (int) $w[0] === (int) $a2_s ) {
			return array( $w[3], $w[4] );
		}
	}
	return array();
};
check( 'R12-M7 §19 warning: 99,000 € is below the limit', array(), $a2_warn() );
Corrections::create( $a2_ps, 10050000, 'Bonus', 'a2-s1', 9900000 );
check( 'R12-M7 §19 warning counts the correction net at its date', array( 10050000, 'current' ), $a2_warn() );
$a2_earn( $a2_s, 'a2-7', 100000 );
$a2_ps2 = PayoutService::create_for_affiliate( $a2_s, gmdate( 'Y-m-d' ), 'EUR' );
check( 'R12-M7 §19 warning does not count the settled correction a second time', array( 10060000, 'current' ), $a2_warn() );
check( 'R12-M7 annual overview still builds', true, 0 === strpos( Statement::annual( $a2_s, (int) wp_date( 'Y' ) ), '%PDF' ) );
$a2_ps2pdf = Statement::build( PayoutService::find( $a2_ps2 ) );
check( 'R12-M7 next credit note: settled correction on a line of its own', true, false !== strpos( $a2_ps2pdf, 'VAT shown there' ) );

/* R12-M1: full cancellation with a pending increase (QA repro). */
$a2_i   = $a2_partner( 'inc', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_ic  = $a2_earn( $a2_i, 'a2-8', 100000 );
$a2_pi  = PayoutService::create_for_affiliate( $a2_i, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_pi );
$a2_bal = Balance::get( $a2_i, 'EUR' );
$a2_inc = Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'a2-8', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 200000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
$a2_iid = isset( $a2_inc['commission_ids'][0] ) ? (int) $a2_inc['commission_ids'][0] : 0;
check( 'R12-M1 setup: pending increase of 100 €', array( 'pending', 10000 ), $a2_iid ? array( Ledger::find( $a2_iid )->status, (int) Ledger::find( $a2_iid )->amount_minor ) : array() );
$a2_rf = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'a2-8', 'refund_id' => 'a2-r8', 'type' => 'cancellation', 'reason' => 'storno', 'full' => true ) );
$a2_adj = isset( $a2_rf['adjustment_ids'][0] ) ? Ledger::find( (int) $a2_rf['adjustment_ids'][0] ) : null;
check( 'R12-M1 full cancellation rejects the pending increase and reverses only what was paid', array( 'rejected', -10000, 'approved', -10000 ), array( Ledger::find( $a2_iid )->status, $a2_adj ? (int) $a2_adj->amount_minor : 0, $a2_adj ? $a2_adj->status : '', Balance::get( $a2_i, 'EUR' ) - $a2_bal ) );
check( 'R12-M1 order sums to 0, account consistent', array( 0, array() ), array( (int) $wpdb->get_var( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'a2-8' AND status <> 'rejected'" ), Balance::verify( $a2_i, 'EUR' ) ) );
// Pending sale with a pending increase: the reversal follows the sale, the increase is rejected.
$a2_ic2 = $a2_earn( $a2_i, 'a2-9', 100000, 'test', false );
Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'a2-9', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 150000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
$a2_rf2 = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'a2-9', 'refund_id' => 'a2-r9', 'type' => 'cancellation', 'reason' => 'storno', 'full' => true ) );
check( 'R12-M1 pending sale: reversal follows the sale, increase rejected, order sums to 0', array( Ledger::find( $a2_ic2 )->status, -10000, 0 ), array( Ledger::find( (int) $a2_rf2['adjustment_ids'][0] )->status, (int) Ledger::find( (int) $a2_rf2['adjustment_ids'][0] )->amount_minor, (int) $wpdb->get_var( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'a2-9' AND status <> 'rejected'" ) ) );

/* R12-M2: refund after a correction credit note of the same payout. */
add_action( 'wpac_refund_after_correction', array( WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::class, 'refund_after_correction' ), 10, 3 );
$a2_w = $a2_partner( 'wc', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_earn( $a2_w, '912001', 100000, 'woocommerce' );
$a2_pw = PayoutService::create_for_affiliate( $a2_w, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_pw );
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_form( PayoutService::find( $a2_pw ) );
$a2_form = ob_get_clean();
check( 'R12-M2 correction form lists the orders of the credit note', true, false !== strpos( $a2_form, '#912001' ) );
Corrections::create( $a2_pw, 0, 'Bestellung storniert', 'a2-w1', 10000 );
$GLOBALS['za_orders'][912001] = new ZA_Order( 912001 );
$GLOBALS['za_notes']          = array();
$a2_bal = Balance::get( $a2_w, 'EUR' );
wpac_record_refund( array( 'source' => 'woocommerce', 'order_id' => '912001', 'refund_id' => 'a2-rw', 'type' => 'refund', 'reason' => 'Erstattung', 'full' => true ) );
$a2_notes = implode( ' ', (array) $GLOBALS['za_notes'] );
check( 'R12-M2 booking unchanged (deducted again), but audited and noted on the order', array( -10000, 1, true ), array( Balance::get( $a2_w, 'EUR' ) - $a2_bal, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.refund_after_correction' AND object_id = %d", $a2_pw ) ), false !== strpos( $a2_notes, 'already has correction credit note' ) ) );
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::refund_warnings( PayoutService::find( $a2_pw ) );
$a2_list = ob_get_clean();
check( 'R12-M2 warning shown with the corrections of the payout', true, false !== strpos( $a2_list, 'Order #912001 was refunded or cancelled' ) );
unset( $GLOBALS['za_orders'][912001] );

/* R12-M5: a failing after-commit callback neither hides the commit nor stops the others. */
$a2_ran = array();
$a2_res = Db::transaction(
	static function () use ( &$a2_ran ) {
		Db::after_commit( static function () { throw new RuntimeException( 'listener broke' ); } );
		Db::after_commit( static function () use ( &$a2_ran ) { $a2_ran[] = 'second'; } );
		return 'committed';
	}
);
check( 'R12-M5 after-commit callbacks run each in their own try/catch', array( 'committed', array( 'second' ) ), array( $a2_res, $a2_ran ) );
$a2_e = $a2_partner( 'm5', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$a2_earn( $a2_e, 'a2-10', 100000 );
$a2_pe = PayoutService::create_for_affiliate( $a2_e, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $a2_pe );
$a2_boom_on = true;
$a2_boom    = static function () use ( &$a2_boom_on ) { if ( $a2_boom_on ) { throw new RuntimeException( 'add-on broke' ); } };
add_action( 'wpac_commission_created', $a2_boom );
$a2_ke = Corrections::create( $a2_pe, 11000, 'Bonus', 'a2-e1', 10000 );
$a2_boom_on = false;
check( 'R12-M5 correction reports success although a listener failed after the commit', array( true, true ), array( is_int( $a2_ke ), is_int( $a2_ke ) && '' !== (string) Corrections::find( $a2_ke )->file ) );

/* R12-N1: objection resolution without a written document does not point to it. */
Objections::file( $a2_pe, $a2_e, 'Falscher Satz' );
$a2_dir_on = true;
$a2_dir    = static function ( $d ) use ( &$a2_dir_on ) { return $a2_dir_on ? '/proc/wpac-not-writable' : $d; };
add_filter( 'wpac_statement_dir', $a2_dir );
set_error_handler( static function () { return true; } );
$a2_kn = Corrections::create( $a2_pe, 12000, 'Satz', 'a2-n1', 11000 );
restore_error_handler();
$a2_dir_on = false;
$a2_ob = Objections::for_payout( $a2_pe );
check( 'R12-N1 document not written: resolution says it follows by e-mail, no pointer to the partner area', array( true, 'resolved', false, true ), array( is_int( $a2_kn ) && '' === (string) Corrections::find( $a2_kn )->file, $a2_ob['status'], false !== strpos( $a2_ob['resolution'], 'partner area' ), false !== strpos( $a2_ob['resolution'], 'by e-mail' ) ) );
check( 'R12-N1 the missing document is written again later', true, Corrections::generate( (int) $a2_kn ) );

/* R12-K6: amounts are parsed per currency and never rounded silently. */
$a2_p = array();
foreach ( array( array( '1.000', 'EUR' ), array( '1.000,50', 'EUR' ), array( '12,345', 'EUR' ), array( '12,3456', 'EUR' ), array( '0,125', 'EUR' ), array( '0,500', 'EUR' ), array( '5.000', 'KWD' ), array( '12,5', 'JPY' ), array( 'abc', 'EUR' ) ) as $a2_in ) {
	$a2_v   = Money::parse_minor( $a2_in[0], $a2_in[1], $a2_problem );
	$a2_p[] = null === $a2_v ? $a2_problem : $a2_v;
}
check( 'R12-K6 parse_minor: thousands, decimals, too precise and invalid input', array( 100000, 100050, 1234500, 'precision', 'precision', 50, 5000, 'precision', 'invalid' ), $a2_p );
check( 'R12-K6 error message for too precise input names the decimals', true, false !== strpos( WPAffiliateCaptain\Admin\Admin::amount_error( 'precision', 'EUR' ), '2' ) );

/* R12-N2: exchange rates keep their precision; no division by zero. */
check( 'R12-N2 small rate keeps its digits (1,000,000 IRR × 0.0000213 = 21.30 €)', 2130, Fx::to_program( 100000000, 'IRR', 'EUR', array( 'IRR' => '0.0000213' ) ) );
check( 'R12-N2 conversion back with a tiny rate: no division by zero', array( 50000000000, null, null ), array( Fx::from_program( 5000, 'IRR', 'EUR', array( 'IRR' => '0.0000001' ) ), Fx::from_program( 5000, 'IRR', 'EUR', array( 'IRR' => '0.0000000000000000001' ) ), Fx::to_program( 5000, 'IRR', 'EUR', array( 'IRR' => '0' ) ) ) );
check( 'R12-N2 ordinary rates unchanged', array( 9200, 5435, 620 ), array( Fx::to_program( 10000, 'USD', 'EUR', array( 'USD' => '0.92' ) ), Fx::from_program( 5000, 'USD', 'EUR', array( 'USD' => '0.92' ) ), Fx::to_program( 1000, 'JPY', 'EUR', array( 'JPY' => '0.0062' ) ) ) );
check( 'R12-N2 large amounts do not overflow', 92000000000, Fx::to_program( 100000000000, 'USD', 'EUR', array( 'USD' => '0.920000000001' ) ) );

update_option( 'wpac_settings', $a2_keep );
Settings::flush_cache();
