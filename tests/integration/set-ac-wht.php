<?php
/**
 * SET-AC-20 (owner decision E8 (a)): withholding per partner. Percentage and note on the partner
 * page (0 = off), the payout transfers the amount less the withholding, the credit note (PDF), the
 * e-invoice (paid amount BT-113, note BT-22, payment terms BT-20; no allowance, no VAT line), the
 * cancellation and the annual overview show it, the payout export has the sum; filter
 * wpac_withholding; 0 % leaves payout row and documents as before.
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\EInvoice;
use WPAffiliateCaptain\Payouts\Pdf;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\Withholding;

echo "SET-AC-20 withholding per partner\n";
global $wpdb;
$wh_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $wh_keep, array( 'min_payout_minor' => 100, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'vies_check' => 0, 'maintenance_mode' => 0, 'einvoice_buyer_reference' => '', 'default_rate_type' => 'percent', 'default_rate' => '10' ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );

$wh_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'WH ' . $slug, 'email' => 'wh-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'WH ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'WH ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return (int) $id;
};
$wh_earn = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'wh-kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
};
$wh_in = static function ( $pdf, $text ) {
	return false !== strpos( (string) $pdf, Pdf::literal( $text ) );
};
$wh_has = static function ( $pdf, $text ) {
	return false !== strpos( (string) $pdf, substr( Pdf::literal( $text ), 1, -1 ) );
};
$wh_x = static function ( $xml ) {
	$dom = new DOMDocument();
	$dom->loadXML( (string) $xml );
	$x = new DOMXPath( $dom );
	$x->registerNamespace( 'rsm', EInvoice::NS_RSM );
	$x->registerNamespace( 'ram', EInvoice::NS_RAM );
	return static function ( $path, $all = false ) use ( $x ) {
		$out = array();
		foreach ( $x->query( $path ) as $n ) {
			$out[] = trim( (string) $n->textContent );
		}
		return $all ? $out : ( $out ? $out[0] : null );
	};
};
$wh_sum = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';

/* Input: percentage as typed, limits, note required, 0 removes the setting. */
check(
	'SET-AC-20 parse: 15 / 12,5 / "15 %" / 99,99 / 0,00 accepted; 100, -1, 7.125, text refused',
	array( '15', '12.5', '15', '99.99', '0', null, null, null, null, null ),
	array( Withholding::parse( '15' ), Withholding::parse( '12,5' ), Withholding::parse( '15 %' ), Withholding::parse( '99,99' ), Withholding::parse( '0,00' ), Withholding::parse( '100' ), Withholding::parse( '-1' ), Withholding::parse( '7.125' ), Withholding::parse( 'abc' ), Withholding::parse( array() ) )
);
$wh_a = $wh_partner( 'std', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
check( 'SET-AC-20 default: off (0, no note, nothing stored)', array( array( 'percent' => '0', 'note' => '' ), false ), array( Withholding::get( $wh_a ), get_option( Withholding::OPTION . $wh_a ) ) );
$wh_e1 = Withholding::save( $wh_a, '15', '' );
$wh_e2 = Withholding::save( $wh_a, '120', 'x' );
$wh_e3 = Withholding::save( $wh_a, '15', str_repeat( 'a', Withholding::NOTE_MAX + 1 ) );
check( 'SET-AC-20 save refuses: no note, more than 99.99 %, note too long (nothing stored)', array( 'wpac_withholding_note', 'wpac_withholding_percent', 'wpac_withholding_note', false ), array( is_wp_error( $wh_e1 ) ? $wh_e1->get_error_code() : '', is_wp_error( $wh_e2 ) ? $wh_e2->get_error_code() : '', is_wp_error( $wh_e3 ) ? $wh_e3->get_error_code() : '', get_option( Withholding::OPTION . $wh_a ) ) );
$wh_note = 'Quellensteuer nach §50a EStG, abgeführt an das Finanzamt';
check( 'SET-AC-20 save: 15,0 % with note stored (canonical), not autoloaded', array( true, array( 'percent' => '15', 'note' => $wh_note ) ), array( Withholding::save( $wh_a, '15,0', $wh_note ), Withholding::get( $wh_a ) ) );
$wh_audit = $wpdb->get_row( 'SELECT * FROM ' . t( 'audit' ) . " WHERE action = 'affiliate.withholding' AND object_id = " . (int) $wh_a . ' ORDER BY id DESC LIMIT 1' );
check( 'SET-AC-20 change is in the audit log with old and new value', array( true, true ), array( (bool) $wh_audit, $wh_audit && false !== strpos( (string) $wh_audit->details, '"new":"15"' ) && false !== strpos( (string) $wh_audit->details, '"old":"0"' ) ) );
$wh_f = Withholding::field();
check( 'SET-AC-20 field definition: text, 0 … 99.99, default 0, marked legal (tax adviser)', array( 'text', '0', 0, 99.99, true ), array( $wh_f['type'], $wh_f['default'], $wh_f['min'], $wh_f['max'], $wh_f['legal'] ) );

/* Calculation: 1.000 € order, 10 % = 100 € net, 19 % VAT = 19 €, 15 % of 100 € = 15 € withheld. */
$wh_seen = array();
$wh_spy  = static function ( $percent, $aff, $payout ) use ( &$wh_seen ) {
	$wh_seen[] = array( $percent, $aff, array_keys( $payout ) );
	return $percent;
};
add_filter( 'wpac_withholding', $wh_spy, 10, 3 );
$wh_earn( $wh_a, 'wh-1', 100000 );
$wh_pid = PayoutService::create_for_affiliate( $wh_a, gmdate( 'Y-m-d' ), 'EUR' );
remove_all_filters( 'wpac_withholding' );
$wh_p    = PayoutService::find( $wh_pid );
$wh_snap = json_decode( (string) $wh_p->profile_snapshot, true );
check(
	'SET-AC-20 payout: net 100 €, VAT 19 €, amount transferred 104 € (119 € less 15 €)',
	array( 10000, 1900, 10400, 1500 ),
	array( (int) $wh_p->net_minor, (int) $wh_p->vat_minor, (int) $wh_p->amount_minor, Withholding::minor( $wh_p ) )
);
check(
	'SET-AC-20 frozen in the snapshot: percent, note, basis, amount',
	array( 'percent' => '15', 'note' => $wh_note, 'base_minor' => 10000, 'minor' => 1500 ),
	isset( $wh_snap['withholding'] ) ? $wh_snap['withholding'] : null
);
check(
	'SET-AC-20 filter wpac_withholding( $percent, $affiliate_id, $payout ) with the payout being created',
	array( array( '15', $wh_a, array( 'currency', 'period_end', 'net_minor', 'taxable_minor', 'vat_minor', 'gross_minor', 'vat_mode' ) ) ),
	$wh_seen
);
$wh_audit = $wpdb->get_row( 'SELECT * FROM ' . t( 'audit' ) . " WHERE action = 'payout.created' AND object_id = " . (int) $wh_pid );
check( 'SET-AC-20 audit of the payout names the withholding', true, $wh_audit && false !== strpos( (string) $wh_audit->details, '"withholding":1500' ) );

/* Credit note (PDF): own lines, total of the document, amount transferred, note. */
$wh_pdf = Statement::build( $wh_p );
check(
	'SET-AC-20 PDF: total credit note 119 €, line "Withholding 15 % of 100 €" with −15 €, total payout 104 €, note text',
	array( true, true, true, true, true, true ),
	array(
		$wh_in( $wh_pdf, 'Total credit note' ),
		$wh_in( $wh_pdf, Money::format( 11900, 'EUR' ) ),
		$wh_has( $wh_pdf, 'Withholding 15 %' ),
		$wh_in( $wh_pdf, Money::format( -1500, 'EUR' ) ),
		$wh_in( $wh_pdf, Money::format( 10400, 'EUR' ) ),
		$wh_has( $wh_pdf, 'Quellensteuer' ),
	)
);
check( 'SET-AC-20 PDF line label', 'Withholding 15 % of ' . Money::format( 10000, 'EUR' ), Withholding::label( $wh_snap['withholding'], 'EUR' ) );

/* E-invoice: total stays 119 €, 15 € as paid amount (BT-113), due 104 €, note and terms; valid. */
$wh_xml = EInvoice::payout_xml( $wh_p );
$wh_v   = $wh_x( $wh_xml );
check( 'SET-AC-20 e-invoice passes the built-in EN 16931 checks', array(), EInvoice::check( (string) $wh_xml ) );
check(
	'SET-AC-20 e-invoice: basis 100, VAT 19, total 119, TotalPrepaidAmount 15.00, DuePayableAmount 104.00, no allowance/charge, VAT breakdown only S 19',
	array( '100.00', '19.00', '119.00', '15.00', '104.00', 0, array( 'S' ) ),
	array( $wh_v( $wh_sum . 'ram:TaxBasisTotalAmount' ), $wh_v( $wh_sum . 'ram:TaxTotalAmount' ), $wh_v( $wh_sum . 'ram:GrandTotalAmount' ), $wh_v( $wh_sum . 'ram:TotalPrepaidAmount' ), $wh_v( $wh_sum . 'ram:DuePayableAmount' ), substr_count( (string) $wh_xml, 'AllowanceCharge' ), $wh_v( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', true ) )
);
$wh_notes = implode( ' | ', $wh_v( '//rsm:ExchangedDocument/ram:IncludedNote/ram:Content', true ) );
check(
	'SET-AC-20 e-invoice: note (BT-22) with label, amount, admin text; payment terms (BT-20) name the deduction',
	array( true, true, true ),
	array( false !== strpos( $wh_notes, Withholding::label( $wh_snap['withholding'], 'EUR' ) ), false !== strpos( $wh_notes, $wh_note ), false !== strpos( (string) $wh_v( '//ram:SpecifiedTradePaymentTerms/ram:Description' ), 'is deducted from the amount transferred' ) )
);

/* Payout export and admin detail have the sum. */
check( 'SET-AC-20 export: a payout with a withholding exists (column "withholding")', true, Withholding::used() );

/* Cancellation: the withholding is reversed with the credit note. */
PayoutService::cancel( $wh_pid, 'Falscher Zeitraum', true );
$wh_pc  = PayoutService::find( $wh_pid );
$wh_spf = Statement::build( $wh_pc, true );
$wh_sx  = EInvoice::payout_xml( $wh_pc, true );
$wh_sv  = $wh_x( $wh_sx );
check(
	'SET-AC-20 cancellation: PDF −119 € / +15 € / −104 €; XML valid with paid amount 15.00 and due 104.00',
	array( true, true, true, array(), '15.00', '104.00' ),
	array( $wh_in( $wh_spf, Money::format( -11900, 'EUR' ) ), $wh_in( $wh_spf, Money::format( 1500, 'EUR' ) ), $wh_in( $wh_spf, Money::format( -10400, 'EUR' ) ), EInvoice::check( (string) $wh_sx ), $wh_sv( $wh_sum . 'ram:TotalPrepaidAmount' ), $wh_sv( $wh_sum . 'ram:DuePayableAmount' ) )
);

/* Annual overview: gross with the withholding, sum withheld on its own line. */
$wh_pid2 = PayoutService::create_for_affiliate( $wh_a, gmdate( 'Y-m-d' ), 'EUR' );
$wh_ann  = Statement::annual( $wh_a, (int) wp_date( 'Y' ) );
check(
	'SET-AC-20 annual overview: gross 119 € per credit note, "Withheld from the payouts" with 15 € (cancelled one netted out)',
	array( true, true, true ),
	array( $wh_in( $wh_ann, Money::format( 11900, 'EUR' ) ), $wh_in( $wh_ann, 'Withheld from the payouts (included in the total EUR)' ), $wh_in( $wh_ann, Money::format( 1500, 'EUR' ) ) )
);

/* Filter wins: another percentage, or 0 / invalid = none. */
$wh_b = $wh_partner( 'filter', array( 'tax_status' => 'private' ) );
add_filter( 'wpac_withholding', static function ( $percent, $aff ) use ( $wh_b ) { return $aff === $wh_b ? 20 : $percent; }, 10, 2 );
$wh_earn( $wh_b, 'wh-2', 50000 );
$wh_pb = PayoutService::find( PayoutService::create_for_affiliate( $wh_b, gmdate( 'Y-m-d' ), 'EUR' ) );
remove_all_filters( 'wpac_withholding' );
check( 'SET-AC-20 filter sets 20 % for a partner without setting (private, no VAT): 50 € − 10 € = 40 €', array( 5000, 0, 4000, 1000 ), array( (int) $wh_pb->net_minor, (int) $wh_pb->vat_minor, (int) $wh_pb->amount_minor, Withholding::minor( $wh_pb ) ) );
check( 'SET-AC-20 private partner (category O): e-invoice valid with the withholding', array( array(), '10.00', '40.00' ), array( EInvoice::check( (string) EInvoice::payout_xml( $wh_pb ) ), $wh_x( EInvoice::payout_xml( $wh_pb ) )( $wh_sum . 'ram:TotalPrepaidAmount' ), $wh_x( EInvoice::payout_xml( $wh_pb ) )( $wh_sum . 'ram:DuePayableAmount' ) ) );
add_filter( 'wpac_withholding', static function () { return 'viel'; } );
$wh_earn( $wh_a, 'wh-3', 20000 );
$wh_pi = PayoutService::find( PayoutService::create_for_affiliate( $wh_a, gmdate( 'Y-m-d' ), 'EUR' ) );
remove_all_filters( 'wpac_withholding' );
check( 'SET-AC-20 filter returning something invalid: no withholding', array( 0, (int) $wh_pi->net_minor + (int) $wh_pi->vat_minor ), array( Withholding::minor( $wh_pi ), (int) $wh_pi->amount_minor ) );

/* Basis: never more than the net settled, never the whole transfer. */
check(
	'SET-AC-20 compute: basis = taxable net, capped at the net total (repayments); no withholding of the whole gross',
	array( 1500, 150, null ),
	array(
		Withholding::compute( $wh_a, array( 'net_minor' => 20000, 'taxable_minor' => 10000, 'gross_minor' => 21900 ) )['minor'],
		Withholding::compute( $wh_a, array( 'net_minor' => 1000, 'taxable_minor' => 10000, 'gross_minor' => 1190 ) )['minor'],
		Withholding::compute( $wh_a, array( 'net_minor' => 0, 'taxable_minor' => 0, 'gross_minor' => 0 ) ),
	)
);

/* 0 % = as before: amount = net + VAT, snapshot without the key, documents without a word of it. */
$wh_z = $wh_partner( 'zero', array( 'tax_status' => 'business', 'vat_id' => 'DE811111111' ) );
Withholding::save( $wh_z, '0', 'ignored' );
$wh_earn( $wh_z, 'wh-4', 100000 );
$wh_pz   = PayoutService::find( PayoutService::create_for_affiliate( $wh_z, gmdate( 'Y-m-d' ), 'EUR' ) );
$wh_zs   = json_decode( (string) $wh_pz->profile_snapshot, true );
$wh_zpdf = Statement::build( $wh_pz );
$wh_zx   = (string) EInvoice::payout_xml( $wh_pz );
check(
	'SET-AC-20 0 %: nothing stored, amount = net + VAT, snapshot = profile snapshot (no withholding key), PDF/XML without withholding or paid amount',
	array( false, 11900, ProfileRepository::snapshot( ProfileRepository::get( $wh_z ) ), false, false, false, false, null ),
	array( get_option( Withholding::OPTION . $wh_z ), (int) $wh_pz->amount_minor, $wh_zs, $wh_has( $wh_zpdf, 'Withholding' ), $wh_in( $wh_zpdf, 'Total credit note' ), false !== strpos( $wh_zx, 'TotalPrepaidAmount' ), false !== strpos( $wh_zx, 'Withholding' ), Withholding::of_payout( $wh_pz ) )
);
check( 'SET-AC-20 0 %: the PDF is built the same way twice (deterministic, byte-identical)', $wh_zpdf, Statement::build( PayoutService::find( (int) $wh_pz->id ) ) );

/* Saving 0 removes the setting; the payout details deletion removes it too. */
Withholding::save( $wh_z, '5', 'Test' );
check( 'SET-AC-20 saving 0 removes the setting', array( true, false ), array( Withholding::save( $wh_z, '0', '' ), get_option( Withholding::OPTION . $wh_z ) ) );
Withholding::save( $wh_z, '5', 'Test' );
ProfileRepository::delete( $wh_z );
check( 'SET-AC-20 deleting the payout details (erasure) removes the setting', false, get_option( Withholding::OPTION . $wh_z ) );

/* Admin action: route with the payout permission, password confirmation, audit label; docs. */
$wh_src = static function ( $file ) {
	return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
};
check(
	'SET-AC-20 admin route needs the payout permission and a password confirmation; uninstall removes the options; docs',
	array( true, true, true, true, true, true ),
	array(
		false !== strpos( $wh_src( 'src/Admin/Admin.php' ), "'withholding_update' => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS )" ),
		in_array( 'withholding_update', WPAffiliateCaptain\Admin\Reauth::ACTIONS, true ),
		false !== strpos( $wh_src( 'uninstall.php' ), "wpac\\\\_withholding\\\\_%" ),
		false !== strpos( $wh_src( 'docs/CONTRACT.md' ), '`wpac_withholding`' ),
		false !== strpos( $wh_src( 'docs/HANDBOOK.md' ), 'Steuerabzug' ),
		false !== strpos( $wh_src( 'src/Admin/Pages/PayoutsPage.php' ), "array( 'withholding' )" ),
	)
);

$GLOBALS['wht_fixture'] = array( 'partner' => $wh_a, 'payout' => (int) $wh_pid2, 'zero' => (int) $wh_pz->id );
update_option( 'wpac_settings', $wh_keep );
Settings::flush_cache();
