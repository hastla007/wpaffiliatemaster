<?php
/**
 * Integration checks of work package K1 (R5-W55): correction credit note (Korrekturgutschrift)
 * for a paid credit note after an objection. Included from tests/integration/run.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\CorrectionNote;
use WPAffiliateCaptain\Payouts\Objections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R11-K1 correction credit notes for paid credit notes\n";

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $k, $v = '', $url = '' ) { return (string) $url . '?' . $k . '=' . $v; }
}

global $wpdb;
$k1_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $k1_keep, array( 'operator_country' => 'DE', 'vat_rate' => '19', 'self_billing' => 1, 'maintenance_mode' => 0, 'min_payout_minor' => 5000, 'vies_check' => 0, 'terminated_open' => 'pay' ) ) );
Settings::flush_cache();

$k1_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
$k1_aff = AffiliateRepository::create( array( 'name' => 'K1 Partner', 'email' => 'k1-partner@example.de', 'status' => 'active' ) );
ProfileRepository::save( $k1_aff, array( 'tax_status' => 'business', 'vat_id' => 'DE811111143', 'legal_name' => 'K1 Partner GmbH', 'address' => "Hafen 1\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'K1 Partner', 'iban' => 'DE89370400440532013000' ) );
$k1_s = sale( 'k1-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $k1_aff ), array( 100000, 50000 ) ); // 100 € + 50 € commission.
foreach ( $k1_s['commission_ids'] as $k1_cid ) {
	Ledger::transition( (int) $k1_cid, 'approved' );
}
list( $k1_c1, $k1_c2 ) = array_map( 'intval', $k1_s['commission_ids'] );
$k1_p1 = PayoutService::create_for_affiliate( $k1_aff, gmdate( 'Y-m-d' ), 'EUR' );
$k1_pending_check = CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'positive', 'x', 'tokpending1' );
PayoutService::mark_paid( $k1_p1 );
$k1_orig = PayoutService::find( $k1_p1 );
check( 'R5-W55 setup: paid credit note with 19 % VAT', array( 'paid', 15000, 2850 ), array( (string) $k1_orig->status, (int) $k1_orig->net_minor, (int) $k1_orig->vat_minor ) );

/* Guards -------------------------------------------------------------------------------------- */
check( 'R5-W55 guard: only for a paid credit note (pending payout refused)', 'wpac_state', $k1_code( $k1_pending_check ) );
check( 'R5-W55 guard: no open objection → refused', 'wpac_objection', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'positive', 'Zu wenig', 'tokguard01' ) ) );
Objections::file( $k1_p1, $k1_aff, 'Provision für Position 1 zu niedrig' );
$GLOBALS['test_caps'] = array( 'wpac_view_tax_data' );
check( 'R5-W55 guard: needs the payouts capability', 'wpac_forbidden', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'positive', 'Zu wenig', 'tokguard02' ) ) );
unset( $GLOBALS['test_caps'] );
check( 'R5-W55 guard: request token required', 'wpac_token', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'positive', 'Zu wenig', '' ) ) );
check( 'R5-W55 guard: reason required', 'wpac_reason_required', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'positive', '  ', 'tokguard03' ) ) );
check( 'R5-W55 guard: direction required', 'wpac_direction', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 1000 ), 'sideways', 'Zu wenig', 'tokguard04' ) ) );
check( 'R5-W55 guard: line must belong to the credit note', 'wpac_line', $k1_code( CorrectionNote::issue( $k1_p1, array( 999999 => 1000 ), 'positive', 'Zu wenig', 'tokguard05' ) ) );
check( 'R5-W55 guard: at least one amount', 'wpac_amount', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 0 ), 'positive', 'Zu wenig', 'tokguard06' ) ) );
check( 'R5-W55 guard: negative correction not above the original line', 'wpac_amount', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c2 => 5001 ), 'negative', 'Zu viel', 'tokguard07' ) ) );
check( 'R5-W55 guards booked nothing', array( 0, 'open' ), array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE parent_id IN (' . $k1_c1 . ',' . $k1_c2 . ')' ), Objections::for_payout( $k1_p1 )['status'] ) );

/* Positive correction: additional payment ------------------------------------------------------ */
add_action( 'wpac_statement_generated', array( WPAffiliateCaptain\Affiliates\Notifications::class, 'credit_note_issued' ), 10, 2 );
$GLOBALS['test_mails'] = array();
$k1_audit0 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.correction_note_created'" );
$k1_kg1    = CorrectionNote::issue( $k1_p1, array( $k1_c1 => 2000 ), 'positive', 'Provisionssatz 12 % statt 10 %', 'tokpositive1' );
$k1_doc1   = is_int( $k1_kg1 ) ? PayoutService::find( $k1_kg1 ) : null;
$k1_year   = (int) wp_date( 'Y' );
check( 'R5-W55 positive: correction credit note issued as a payout to transfer', array( true, 'pending', 2000, 380, 2380 ), $k1_doc1 ? array( true, (string) $k1_doc1->status, (int) $k1_doc1->net_minor, (int) $k1_doc1->vat_minor, (int) $k1_doc1->amount_minor ) : array( $k1_code( $k1_kg1 ) ) );
check( 'R5-W55 numbering: own gapless correction range (KG), not the credit note range', sprintf( 'KG-%d-000001', $k1_year ), $k1_doc1 ? (string) $k1_doc1->number : '' );
$k1_items = $k1_doc1 ? PayoutService::items( (int) $k1_doc1->id ) : array();
check( 'R5-W55 VAT reuse: line keeps treatment and rate of the original line and refers to it', array( 1, 'standard', '19', (int) $k1_p1, 'adjustment', 2000 ), $k1_items ? array( count( $k1_items ), (string) $k1_items[0]->vat_mode, (string) $k1_items[0]->vat_rate, (int) $k1_items[0]->ref_payout_id, (string) $k1_items[0]->kind, (int) $k1_items[0]->amount_minor ) : array() );
$k1_ref = $k1_doc1 ? CorrectionNote::document( $k1_doc1 ) : array();
check( 'R5-W55 reference fields: original number, date, reason, direction in the document data', array( (string) $k1_orig->number, (string) $k1_orig->created_at, 'Provisionssatz 12 % statt 10 %', 'positive', (int) $k1_p1 ), $k1_ref ? array( $k1_ref['number'], $k1_ref['created_at'], $k1_ref['reason'], $k1_ref['direction'], (int) $k1_ref['payout_id'] ) : array() );
$k1_pdf = $k1_doc1 ? Statement::build( $k1_doc1 ) : '';
check(
	'R5-W55 PDF: title, reference to the original credit note and date, reason, total label',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $k1_pdf, 'Correction credit note \\(Korrekturgutschrift\\)' ), // PDF strings escape parentheses.
		false !== strpos( $k1_pdf, 'Corrects credit note' ) && false !== strpos( $k1_pdf, (string) $k1_orig->number . ' \\(' . gmdate( 'd.m.Y', strtotime( $k1_orig->created_at ) ) . '\\)' ),
		false !== strpos( $k1_pdf, 'Correction of credit note ' . $k1_orig->number ),
		false !== strpos( $k1_pdf, 'Provisionssatz 12' ),
		false !== strpos( $k1_pdf, 'Additional payment' ),
	)
);
$k1_obj = Objections::for_payout( $k1_p1 );
check( 'R5-W55 objection resolved with link to the document', array( 'resolved', (int) $k1_kg1, true ), array( (string) $k1_obj['status'], (int) ( $k1_obj['document_id'] ?? 0 ), false !== strpos( (string) $k1_obj['resolution'], (string) ( $k1_doc1 ? $k1_doc1->number : '?' ) ) ) );
$k1_subjects = array_column( (array) $GLOBALS['test_mails'], 1 );
check( 'R5-W55 PDF stored and mailed to the partner (correction mail), objection mail sent', array( true, true, true ), array( $k1_doc1 && '' !== (string) PayoutService::find( $k1_kg1 )->statement_file, (bool) preg_grep( '/Correction credit note KG-/', $k1_subjects ), (bool) preg_grep( '/Your objection to credit note/', $k1_subjects ) ) );
check( 'R5-W55 audit entry of the correction credit note', $k1_audit0 + 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.correction_note_created'" ) );
check( 'R5-W55 positive: balance unchanged (assigned to the document), nothing open', array( 0, array( 0, 0, 0 ) ), array( (int) Balance::get( $k1_aff, 'EUR' ), PayoutService::open_totals( $k1_aff, 'EUR' ) ) );

/* Idempotency: the same form again (double click) ------------------------------------------- */
$k1_rows_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $k1_aff );
$k1_again       = CorrectionNote::issue( $k1_p1, array( $k1_c1 => 2000 ), 'positive', 'Provisionssatz 12 % statt 10 %', 'tokpositive1' );
check( 'R5-W55 idempotency: the same token returns the same document and books nothing', array( (int) $k1_kg1, $k1_rows_before, 1 ), array( is_int( $k1_again ) ? $k1_again : -1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $k1_aff ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . " WHERE number LIKE 'KG-%' AND affiliate_id = " . (int) $k1_aff ) ) );
check( 'R5-W55 guard: objection already handled → a new form issues nothing', 'wpac_objection', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c1 => 2000 ), 'positive', 'Nochmal', 'tokpositive2' ) ) );
check( 'R5-W55 positive: paid like any payout', true, true === PayoutService::mark_paid( (int) $k1_kg1 ) );
check( 'R5-W55 a correction credit note is not itself corrected', 'wpac_state', $k1_code( CorrectionNote::issue( (int) $k1_kg1, array( $k1_c1 => 100 ), 'positive', 'x', 'tokkgofkg1' ) ) );

/* Negative correction: overpayment becomes a claim, never a negative payout ----------------- */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'vat_rate' => '16' ) ) ); // The rate changed meanwhile: the original rate still applies.
Settings::flush_cache();
Objections::file( $k1_p1, $k1_aff, 'Position 2 wurde storniert' );
$k1_kg2  = CorrectionNote::issue( $k1_p1, array( $k1_c2 => 1000 ), 'negative', 'Teilstorno Position 2', 'toknegative1' );
$k1_doc2 = is_int( $k1_kg2 ) ? PayoutService::find( $k1_kg2 ) : null;
check( 'R5-W55 negative: document settled at once (no transfer) with the original 19 %', array( true, 'paid', -1000, -190, -1190, 'standard', '19' ), $k1_doc2 ? array( true, (string) $k1_doc2->status, (int) $k1_doc2->net_minor, (int) $k1_doc2->vat_minor, (int) $k1_doc2->amount_minor, (string) $k1_doc2->vat_mode, (string) $k1_doc2->vat_rate ) : array( $k1_code( $k1_kg2 ) ) );
check( 'R5-W55 numbering: next number of the correction range', sprintf( 'KG-%d-000002', $k1_year ), $k1_doc2 ? (string) $k1_doc2->number : '' );
$k1_claim = $wpdb->get_row( 'SELECT * FROM ' . t( 'commissions' ) . " WHERE kind = 'claim' AND affiliate_id = " . (int) $k1_aff );
check( 'R5-W55 negative: gross amount owed as an open, non-taxable claim on the balance', array( -1190, 'approved', 0, -1190, -1190, array( -1190, 0, -1190 ) ), $k1_claim ? array( (int) $k1_claim->amount_minor, (string) $k1_claim->status, (int) $k1_claim->payout_id, (int) Balance::get( $k1_aff, 'EUR' ), Dunning::open( $k1_aff, 'EUR' ), PayoutService::open_totals( $k1_aff, 'EUR' ) ) : array() );
check( 'R5-W55 negative: correction rows settled by the document, balance ledger consistent', array( 'paid', true ), array( (string) $wpdb->get_var( 'SELECT status FROM ' . t( 'commissions' ) . ' WHERE kind = \'adjustment\' AND parent_id = ' . $k1_c2 ), array() === Balance::verify( $k1_aff, 'EUR' ) ) );
$k1_pdf2 = $k1_doc2 ? Statement::build( $k1_doc2 ) : '';
check( 'R5-W55 negative PDF: owed amount, no transfer', array( true, true ), array( false !== strpos( $k1_pdf2, 'Amount owed by the partner' ), false !== strpos( $k1_pdf2, 'Correction of credit note ' . $k1_orig->number ) ) );
Objections::file( $k1_p1, $k1_aff, 'Position 2 komplett' );
check( 'R5-W55 guard: negative correction limited to what remains after earlier corrections', 'wpac_amount', $k1_code( CorrectionNote::issue( $k1_p1, array( $k1_c2 => 4001 ), 'negative', 'Rest', 'toknegative2' ) ) );
Objections::resolve( $k1_p1, 'Erledigt' );

/* The claim is deducted from the next credit note (existing balance path) -------------------- */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'vat_rate' => '19' ) ) );
Settings::flush_cache();
$k1_s2 = sale( 'k1-2', WPAffiliateCaptain\Codes\CodeRepository::main_code( $k1_aff ), array( 100000 ) );
Ledger::transition( (int) $k1_s2['commission_ids'][0], 'approved' );
$k1_p3 = PayoutService::create_for_affiliate( $k1_aff, gmdate( 'Y-m-d' ), 'EUR' );
$k1_n3 = $k1_p3 ? PayoutService::find( $k1_p3 ) : null;
check( 'R5-W55 balance effect: the claim is deducted from the next credit note without VAT', array( 8810, 1900, 10710 ), $k1_n3 ? array( (int) $k1_n3->net_minor, (int) $k1_n3->vat_minor, (int) $k1_n3->amount_minor ) : array() );
check( 'R5-W55 next credit note uses the regular number range', 0, $k1_n3 ? strpos( (string) $k1_n3->number, (string) Settings::get( 'credit_prefix' ) . '-' ) : -1 );

/* Portal: the partner sees the correction documents in the list ------------------------------- */
list( $k1_list ) = PayoutService::query( array( 'affiliate_id' => (int) $k1_aff, 'limit' => 25 ) );
$k1_listed = array();
foreach ( $k1_list as $k1_row ) {
	if ( CorrectionNote::is_note( $k1_row ) && '' !== (string) $k1_row->statement_file ) {
		$k1_listed[] = (string) $k1_row->number;
	}
}
sort( $k1_listed );
check( 'R5-W55 portal: both correction credit notes listed with their document', array( (string) $k1_doc1->number, (string) $k1_doc2->number ), $k1_listed );
check( 'R5-W55 guidance for a paid credit note names the correction credit note', true, false !== strpos( Objections::guidance( $k1_orig ), 'correction credit note' ) );

// Left open on purpose: r8-render.php renders the payouts page with the correction form.
Objections::file( $k1_p1, $k1_aff, 'Offen für die Darstellung' );
remove_all_filters( 'wpac_statement_generated' );
update_option( 'wpac_settings', $k1_keep );
Settings::flush_cache();
