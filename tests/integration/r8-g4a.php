<?php
/**
 * R8 work package G4a: VAT, self-billing statements, PDFs, tax profile.
 * Included by run.php (helpers check(), sale(), refund(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\TaxHistory;
use WPAffiliateCaptain\Payouts\Vies;

echo "R8-G4a VAT, credit notes, tax profile\n";

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $k, $v = '', $url = '' ) { return (string) $url . '?' . $k . '=' . $v; }
}

global $wpdb;
$g4_ct       = t( 'commissions' );
$g4_settings = get_option( 'wpac_settings' );
update_option(
	'wpac_settings',
	array_merge(
		(array) $g4_settings,
		array(
			'operator_country' => 'DE',
			'vat_rate'         => '19',
			'self_billing'     => 1,
			'terms_page_id'    => 900,
			'maintenance_mode' => 0,
			'min_payout_minor' => 5000,
			'vies_check'       => 0,
		)
	)
);
Settings::flush_cache();

$g4_n       = 0;
$g4_partner = static function ( array $profile ) use ( &$g4_n ) {
	++$g4_n;
	$id = AffiliateRepository::create( array( 'name' => 'G4 Partner ' . $g4_n, 'email' => 'g4-' . $g4_n . '@example.de', 'status' => 'active' ) );
	$r  = ProfileRepository::save( $id, $profile + array( 'legal_name' => 'G4 Partner ' . $g4_n, 'address' => "Weg {$g4_n}\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4 Partner', 'iban' => 'DE89370400440532013000' ) );
	return is_wp_error( $r ) ? $r : $id;
};
$g4_approve_children = static function ( $parent ) use ( $wpdb, $g4_ct ) {
	foreach ( $wpdb->get_col( "SELECT id FROM {$g4_ct} WHERE parent_id = " . (int) $parent ) as $child ) {
		Ledger::transition( (int) $child, 'approved' );
	}
};
$g4_last_payout = static function ( $aff ) use ( $wpdb ) {
	return PayoutService::find( (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = ' . (int) $aff ) );
};

/* R6-DD1/DD2/DD3: refund after a credit note with VAT – claim, repayment and correction document. */
$g4_b = $g4_partner( array( 'tax_status' => 'business', 'vat_id' => 'DE811111119' ) );
$g4_s = sale( 'g4-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_b ), array( 100000 ) ); // 100 € commission.
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$g4_p1 = PayoutService::create_for_affiliate( $g4_b, gmdate( 'Y-m-d' ), 'EUR' );
check( 'R6-DD1 credit note with 19 % VAT', array( 10000, 1900, 11900 ), array( (int) PayoutService::find( $g4_p1 )->net_minor, (int) PayoutService::find( $g4_p1 )->vat_minor, (int) PayoutService::find( $g4_p1 )->amount_minor ) );
PayoutService::mark_paid( $g4_p1 );
AffiliateRepository::set_status( $g4_b, 'terminated', 'Ende' );
refund( 'g4-1', 'g4-r1' );
$g4_approve_children( (int) $g4_s['commission_ids'][0] );
check( 'R6-DD1 the claim includes the VAT of the credit note', array( -11900, -1900 ), array( Dunning::open( $g4_b, 'EUR' ), Dunning::open_vat( $g4_b, 'EUR' ) ) );
$g4_cand = array_values( array_filter( Dunning::candidates(), static function ( $c ) use ( $g4_b ) { return (int) $c['affiliate']->id === (int) $g4_b; } ) );
check( 'R6-DD1 payment request lists the gross amount', array( -11900, -1900 ), $g4_cand ? array( $g4_cand[0]['amount'], $g4_cand[0]['vat'] ) : array() );
check( 'R6-DD1 repayment above the gross claim refused', true, is_wp_error( Dunning::record_repayment( $g4_b, 'EUR', 11901, 'x' ) ) );
$g4_rep = Dunning::record_repayment( $g4_b, 'EUR', 11900, 'Überweisung' );
check( 'R6-DD1 repayment of the gross amount settles the claim', array( true, 0 ), array( is_int( $g4_rep ), Dunning::open( $g4_b, 'EUR' ) ) );
$g4_c = $g4_last_payout( $g4_b );
check( 'R6-DD3 correction document settles the balance (VAT reduced, nothing transferred)', array( 'paid', 1900, -1900, 0 ), array( $g4_c->status, (int) $g4_c->net_minor, (int) $g4_c->vat_minor, (int) $g4_c->amount_minor ) );
$g4_items = PayoutService::items( (int) $g4_c->id );
$g4_modes = array();
foreach ( $g4_items as $g4_i ) {
	$g4_modes[ $g4_i->kind ] = array( $g4_i->vat_mode, $g4_i->vat_rate, (int) $g4_i->ref_payout_id );
}
ksort( $g4_modes );
check( 'R6-DD2 repayment line is not taxable, correction keeps the VAT of the original credit note', array( 'refund' => array( 'standard', '19', (int) $g4_p1 ), 'repayment' => array( 'none', '0', 0 ) ), $g4_modes );
$g4_pdf = Statement::build( $g4_c );
check( 'R6-DD3 correction document names the original credit note and the non-taxable repayment', array( true, true, true ), array( false !== strpos( $g4_pdf, 'Correction of credit notes' ), false !== strpos( $g4_pdf, 'Correction of credit note ' . PayoutService::find( $g4_p1 )->number ), false !== strpos( $g4_pdf, 'not taxable' ) ) );

/* R6-DD1: write-off covers the gross claim and is not taxable. */
$g4_w = $g4_partner( array( 'tax_status' => 'business', 'vat_id' => 'DE811111127' ) );
$g4_s = sale( 'g4-2', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_w ), array( 100000 ) );
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$g4_p2 = PayoutService::create_for_affiliate( $g4_w, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $g4_p2 );
AffiliateRepository::set_status( $g4_w, 'terminated', 'Ende' );
refund( 'g4-2', 'g4-r2' );
$g4_approve_children( (int) $g4_s['commission_ids'][0] );
$g4_wo = Ledger::write_off( $g4_w, 'EUR', 'Uneinbringlich' );
check( 'R6-DD1 write-off books the gross claim', array( 11900, 0 ), array( (int) $wpdb->get_var( "SELECT amount_minor FROM {$g4_ct} WHERE id = " . (int) $g4_wo ), Dunning::open( $g4_w, 'EUR' ) ) );
check( 'R6-DD3 write-off also gets a correction document', array( 'paid', -1900 ), array( $g4_last_payout( $g4_w )->status, (int) $g4_last_payout( $g4_w )->vat_minor ) );

/* R6-DD4: never a negative transfer after a change of the tax status. */
$g4_k = $g4_partner( array( 'tax_status' => 'business', 'vat_id' => 'DE811111135' ) );
$g4_s = sale( 'g4-3', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_k ), array( 300000 ) ); // 300 €.
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$g4_p3 = PayoutService::create_for_affiliate( $g4_k, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $g4_p3 );
ProfileRepository::save( $g4_k, array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890', 'legal_name' => 'G4 Partner', 'address' => "Weg\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4', 'iban' => '' ) );
refund( 'g4-3', 'g4-r3' );
$g4_approve_children( (int) $g4_s['commission_ids'][0] );
$g4_s2 = sale( 'g4-4', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_k ), array( 351000 ) ); // 351 €, no VAT now.
Ledger::transition( (int) $g4_s2['commission_ids'][0], 'approved' );
$g4_prev = array_values( array_filter( PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' ), static function ( $r ) use ( $g4_k ) { return $r['affiliate'] && (int) $r['affiliate']->id === (int) $g4_k; } ) );
check( 'R6-DD4 net 51 € but -57 € VAT correction: carried forward, no payout', array( 5100, false, 'negative', 0 ), array( $g4_prev ? $g4_prev[0]['total_minor'] : null, $g4_prev ? $g4_prev[0]['eligible'] : null, $g4_prev ? $g4_prev[0]['code'] : null, PayoutService::create_for_affiliate( $g4_k, gmdate( 'Y-m-d' ), 'EUR' ) ) );

/* R6-DD5 / R7-AD4: VAT by the time of the service. */
$g4_h = $g4_partner( array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67891' ) );
$g4_s = sale( 'g4-5', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_h ), array( 100000 ) );
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$wpdb->query( "UPDATE {$g4_ct} SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 20 DAY ) WHERE id = " . (int) $g4_s['commission_ids'][0] );
ProfileRepository::save( $g4_h, array( 'tax_status' => 'business', 'vat_id' => 'DE811111143', 'legal_name' => 'G4 H', 'address' => "Weg\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4', 'iban' => '' ) );
$g4_s2 = sale( 'g4-6', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_h ), array( 200000 ) );
Ledger::transition( (int) $g4_s2['commission_ids'][0], 'approved' );
$g4_p5 = PayoutService::find( PayoutService::create_for_affiliate( $g4_h, gmdate( 'Y-m-d' ), 'EUR' ) );
check( 'R6-DD5 service before the change keeps "small business", the later one gets 19 %', array( 30000, 3800, 33800 ), array( (int) $g4_p5->net_minor, (int) $g4_p5->vat_minor, (int) $g4_p5->amount_minor ) );
$g4_pdf = Statement::build( $g4_p5 );
check( 'R6-DD7 notes for every treatment on the credit note', array( true, true, true ), array( false !== strpos( $g4_pdf, '19 UStG' ), false !== strpos( $g4_pdf, 'statutory rate' ), false !== strpos( $g4_pdf, 'different VAT treatments' ) ) );
Settings::update( array( 'vat_rate' => '16' ) );
list( , $g4_rate_old ) = TaxHistory::at( $g4_h, '2000-01-01 00:00:00', array() );
list( , $g4_rate_new ) = TaxHistory::at( $g4_h, gmdate( 'Y-m-d H:i:s', time() + 60 ), array() );
check( 'R6-DD5 rate change only applies to later services', array( '19', '16' ), array( $g4_rate_old, $g4_rate_new ) );
Settings::update( array( 'vat_rate' => '19' ) );
delete_option( TaxHistory::RATE_OPTION );

/* R6-DD6 / R7-AD2: tax data changed by the partner wait for approval; format check. */
$GLOBALS['test_mails'] = array();
$g4_t = $g4_partner( array( 'tax_status' => 'private' ) );
$g4_base = array( 'legal_name' => 'G4 T', 'address' => "Weg\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G4', 'iban' => '' );
check( 'R6-DD6 VAT ID with a wrong format refused', 'wpac_vat_format', ( $e = ProfileRepository::save( $g4_t, $g4_base + array( 'tax_status' => 'business', 'vat_id' => 'DE12' ), 'partner' ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
check( 'R6-DD6 VAT ID of another country refused', 'wpac_vat_format', ( $e = ProfileRepository::save( $g4_t, $g4_base + array( 'tax_status' => 'business', 'vat_id' => 'ATU12345678' ), 'partner' ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
ProfileRepository::save( $g4_t, $g4_base + array( 'tax_status' => 'business', 'vat_id' => 'DE811111151' ), 'partner' );
check( 'R6-DD6 partner change to "business" waits for approval', array( 'private', 'business' ), array( ProfileRepository::get( $g4_t )->tax_status, TaxHistory::pending( $g4_t )['new']['tax_status'] ) );
$g4_audit = $wpdb->get_var( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'payout_profile.tax_change_requested' AND object_id = " . (int) $g4_t );
check( 'R7-AD2 admin mail and audit with the old value', array( true, true ), array( (bool) array_filter( $GLOBALS['test_mails'], static function ( $m ) { return false !== strpos( $m[1], 'waits for your approval' ); } ), false !== strpos( (string) $g4_audit, '"old":{"tax_status":"private"' ) ) );
ProfileRepository::approve_tax_change( $g4_t );
check( 'R6-DD6 approved: applies now, the old status is kept for earlier services', array( 'business', null, 'private' ), array( ProfileRepository::get( $g4_t )->tax_status, TaxHistory::pending( $g4_t ), TaxHistory::profile( $g4_t )[0]['tax_status'] ) );
$g4_t2 = $g4_partner( array( 'tax_status' => 'private' ) );
ProfileRepository::save( $g4_t2, $g4_base + array( 'tax_status' => 'business', 'vat_id' => 'DE811111160' ), 'partner' );
ProfileRepository::reject_tax_change( $g4_t2 );
check( 'R6-DD6 rejected: previous data stay', array( 'private', null ), array( ProfileRepository::get( $g4_t2 )->tax_status, TaxHistory::pending( $g4_t2 ) ) );
$g4_t3 = AffiliateRepository::create( array( 'name' => 'G4 New Biz', 'email' => 'g4-newbiz@example.de', 'status' => 'active' ) );
ProfileRepository::save( $g4_t3, $g4_base + array( 'tax_status' => 'business', 'vat_id' => 'DE811111178', 'iban' => 'DE89370400440532013000' ), 'partner' );
PayoutService::blocked_reason( $g4_t3, null, $g4_code );
check( 'R6-DD6 first declaration as business needs approval before a credit note', 'tax_pending', $g4_code );
ProfileRepository::save( $g4_t3, $g4_base + array( 'tax_status' => 'private' ), 'partner' );
check( 'R6-DD6 private partner: no approval needed', array( 'private', null ), array( ProfileRepository::get( $g4_t3 )->tax_status, TaxHistory::pending( $g4_t3 ) ) );
add_filter( 'wpac_vies_enabled', static function () { return true; } );
update_option( 'wpac_vies_' . (int) $g4_t, array( 'vat_id' => 'DE811111151', 'status' => 'invalid', 'name' => '', 'consultation' => '', 'checked_at' => Db::now() ) );
PayoutService::blocked_reason( $g4_t, null, $g4_code );
check( 'R6-DD6 invalid domestic VAT ID also blocks credit notes with VAT', 'vies', $g4_code );
remove_all_filters( 'wpac_vies_enabled' );

/* R7-AD3: VAT-registered business with a tax number instead of a VAT ID. */
$g4_a3 = $g4_partner( array( 'tax_status' => 'business', 'tax_number' => '21/815/08150' ) );
check( 'R7-AD3 domestic business with tax number only is accepted and complete', array( true, true ), array( is_int( $g4_a3 ), is_int( $g4_a3 ) && ProfileRepository::is_complete( ProfileRepository::get( $g4_a3 ) ) ) );
$g4_a3b = $g4_partner( array( 'tax_status' => 'business', 'tax_number' => '21/815/08150', 'country' => 'AT', 'iban' => 'AT611904300234573201' ) );
check( 'R7-AD3 foreign business still needs the VAT ID (reverse charge)', 'wpac_vat', $g4_a3b instanceof WP_Error ? $g4_a3b->get_error_code() : 'ok' );

/* R6-DD8 / R7-AD5: the acceptance must cover the current version of the partner's own terms page. */
$g4_terms = Agreements::current( 0 );
update_option( 'wpac_terms_text_aaaa', array( 'title' => 'A', 'content' => 'x', 'page_id' => 900 ) );
check( 'R7-AD5 acceptance of page 900 does not cover page 901 with the same version', array( true, false, true ), array( Agreements::matches( $g4_terms['version'], 'aaaa', array( 'version' => $g4_terms['version'], 'hash' => 'bbbb', 'page_id' => 900 ) ), Agreements::matches( $g4_terms['version'], 'aaaa', array( 'version' => $g4_terms['version'], 'hash' => 'bbbb', 'page_id' => 901 ) ), Agreements::matches( $g4_terms['version'], 'legacy', array( 'version' => $g4_terms['version'], 'hash' => 'bbbb', 'page_id' => 901 ) ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_version' => 'g4-2' ) ) );
Settings::flush_cache();
PayoutService::blocked_reason( $g4_t, null, $g4_code );
check( 'R6-DD8 an older terms version is not enough for credit notes', 'terms', $g4_code );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_version' => $g4_terms['version'] ) ) );
Settings::flush_cache();
$g4_group = WPAffiliateCaptain\Affiliates\Groups::create( 'G4 Gruppe' );
$wpdb->insert( t( 'rules' ), array( 'scope' => 'group', 'scope_id' => $g4_group, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '30', 'active' => 1, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$g4_impact = WPAffiliateCaptain\Affiliates\Groups::delete( $g4_group );
check( 'R7-AD5 deleting a group deactivates its rules and reports them', array( 1, 0 ), array( $g4_impact['rules'], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'rules' ) . " WHERE scope = 'group' AND active = 1 AND scope_id = " . (int) $g4_group ) ) );

/* R6-DD12 / R6-MS3 / R6-DD13: document language, legal references, non-Western names. */
$g4_op = PayoutService::operator_snapshot();
check( 'R6-DD12 language and date format are frozen with the credit note', array( true, true ), array( isset( $g4_op['locale'] ), isset( $g4_op['date_format'] ) ) );
check( 'R6-MS3 non-Latin site languages fall back to English documents', array( 'en_US', 'pl_PL', 'de_DE' ), array( Statement::document_locale( 'ru_RU' ), Statement::document_locale( 'pl_PL' ), Statement::document_locale( 'de_DE' ) ) );
check( 'R6-DD13 no German law for an operator in France', array( false, false, true ), array( false !== strpos( Statement::vat_note( 'reverse_charge', 'FR' ), '13b' ), false !== strpos( Statement::vat_note( 'small_business', 'FR' ), '§19' ), false !== strpos( Statement::vat_note( 'reverse_charge', 'DE' ), '13b' ) ) );
$g4_fr       = clone $g4_p5;
$g4_frop     = json_decode( (string) $g4_fr->operator_snapshot, true );
$g4_frop['country'] = 'FR';
$g4_fr->operator_snapshot = wp_json_encode( $g4_frop );
$g4_pdf = Statement::build( $g4_fr );
check( 'R6-DD13 self-billing title and note without German law outside DACH', array( true, false, false ), array( false !== strpos( $g4_pdf, 'self-billing' ), false !== strpos( $g4_pdf, 'Gutschrift' ), false !== strpos( $g4_pdf, '14 Abs. 2' ) ) );
check( 'R6-DD7 reverse-charge block carries its own note', true, (bool) array_filter( Statement::vat_notes( 'small_business', array( 'small_business', 'reverse_charge' ), 'DE' ), static function ( $n ) { return false !== strpos( $n, 'Reverse charge' ); } ) );
$g4_pl = $g4_partner( array( 'tax_status' => 'private', 'legal_name' => 'Łukasz Wiśniewski' ) );
$g4_s  = sale( 'g4-7', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_pl ), array( 100000 ) );
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$g4_p7  = PayoutService::find( PayoutService::create_for_affiliate( $g4_pl, gmdate( 'Y-m-d' ), 'EUR' ) );
$g4_pdf = (string) file_get_contents( Statement::dir() . '/' . $g4_p7->statement_file );
check( 'R5-W45 name transliterated, never "?", original kept in the document properties', array( true, false, true ), array( false !== strpos( $g4_pdf, '(Lukasz Wisniewski)' ), false !== strpos( $g4_pdf, '(?ukasz' ), false !== strpos( $g4_pdf, '/Subject <FEFF' ) ) );

/* R6-QA14: long order numbers and descriptions wrap within their column. */
$wpdb->query( "UPDATE {$g4_ct} SET description = 'Sehr langer Produktname eines Segelkurses mit Zusatzpaket Wochenende inklusive Verpflegung', source_order_id = 'a3f1c2d4-5b6e-4f70-8a9b-0c1d2e3f4a5b' WHERE id = " . (int) $g4_s['commission_ids'][0] );
$g4_pdf = Statement::build( $g4_p7 );
check( 'R6-QA14 UUID order number broken inside the order column', array( true, false ), array( (bool) preg_match( '/120\.00 [0-9.]+ Td \(#a3f1c2d4/', $g4_pdf ), false !== strpos( $g4_pdf, 'a3f1c2d4-5b6e-4f70-8a9b-0c1d2e3f4a5b' ) ) );

/* R6-CC5: missing file on this server is built again from the stored data; folder filterable. */
$g4_file = Statement::dir() . '/' . $g4_p5->statement_file;
@unlink( $g4_file );
$g4_bytes = Statement::stored_bytes( PayoutService::find( $g4_p5->id ) );
check( 'R6-CC5 missing statement rebuilt byte for byte and stored again', array( true, true ), array( is_string( $g4_bytes ) && hash( 'sha256', $g4_bytes ) === $g4_p5->statement_hash, is_readable( $g4_file ) ) );
$g4_dir = sys_get_temp_dir() . '/wpac-g4a-shared';
add_filter( 'wpac_statement_dir', static function () use ( $g4_dir ) { return $g4_dir; } );
check( 'R6-CC5 statement folder can be set by filter', array( $g4_dir, true ), array( Statement::dir(), Statement::custom_dir() ) );
remove_all_filters( 'wpac_statement_dir' );

/* R5-W35: a cancellation document that could not be written is generated later. */
$g4_x = $g4_partner( array( 'tax_status' => 'private' ) );
$g4_s = sale( 'g4-8', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g4_x ), array( 100000 ) );
Ledger::transition( (int) $g4_s['commission_ids'][0], 'approved' );
$g4_p8 = PayoutService::create_for_affiliate( $g4_x, gmdate( 'Y-m-d' ), 'EUR' );
$g4_blocker = sys_get_temp_dir() . '/wpac-g4a-blocker';
@file_put_contents( $g4_blocker, 'x' );
add_filter( 'wpac_statement_dir', static function () use ( $g4_blocker ) { return $g4_blocker . '/sub'; } );
$g4_er = error_reporting( 0 );
PayoutService::cancel( $g4_p8, 'Test' );
error_reporting( $g4_er );
remove_all_filters( 'wpac_statement_dir' );
check( 'R5-W35 failed cancellation document is remembered for a retry', array( '', true ), array( (string) PayoutService::find( $g4_p8 )->storno_file, isset( Statement::storno_failures()[ $g4_p8 ] ) ) );
$g4_retry = get_option( Statement::STORNO_RETRY_OPTION );
$g4_retry[ $g4_p8 ]['next'] = 0;
update_option( Statement::STORNO_RETRY_OPTION, $g4_retry );
$g4_storno_event = array();
add_action( 'wpac_storno_generated', static function ( $id ) use ( &$g4_storno_event ) { $g4_storno_event[] = (int) $id; } );
PayoutService::generate_missing_statements();
remove_all_filters( 'wpac_storno_generated' );
check( 'R5-W35 hourly retry writes it and informs listeners (partner mail)', array( true, array( $g4_p8 ), false ), array( '' !== (string) PayoutService::find( $g4_p8 )->storno_file, $g4_storno_event, isset( Statement::storno_failures()[ $g4_p8 ] ) ) );
@unlink( $g4_blocker );

/* R7-AD8: the partner receives the credit note when it is issued. */
$GLOBALS['test_mails'] = array();
WPAffiliateCaptain\Affiliates\Notifications::credit_note_issued( (int) $g4_p7->id, Statement::dir() . '/' . $g4_p7->statement_file );
WPAffiliateCaptain\Affiliates\Notifications::credit_note_cancelled( $g4_p8, '' );
check( 'R7-AD8 credit note and cancellation are mailed to the partner', array( array( 'g4-' . ( $g4_n - 1 ) . '@example.de', '[Test Shop] Credit note ' . $g4_p7->number ), true ), array( isset( $GLOBALS['test_mails'][0] ) ? $GLOBALS['test_mails'][0] : null, isset( $GLOBALS['test_mails'][1] ) && false !== strpos( $GLOBALS['test_mails'][1][1], 'cancelled' ) ) );

/* R5-W14 / R5-O11: VIES error codes; result bound to the current VAT ID. */
add_filter( 'wpac_vies_enabled', static function () { return true; } );
$g4_v = $g4_partner( array( 'tax_status' => 'business', 'vat_id' => 'DE811111186' ) );
$GLOBALS['test_http'] = static function () { return array( 'body' => wp_json_encode( array( 'actionSucceed' => false, 'errorWrappers' => array( array( 'error' => 'INVALID_INPUT' ) ) ) ) ); };
check( 'R5-W14 INVALID_INPUT means invalid, not "try again"', array( 'invalid', 'INVALID_INPUT', 0 ), array( Vies::check( $g4_v )['status'], Vies::result( $g4_v )['error'], Vies::result( $g4_v )['tries'] ) );
$g4_calls = 0;
$GLOBALS['test_http'] = static function ( $url, $args ) use ( &$g4_calls ) {
	++$g4_calls;
	$body = json_decode( $args['body'], true );
	if ( isset( $body['requesterNumber'] ) ) {
		return array( 'body' => wp_json_encode( array( 'errorWrappers' => array( array( 'error' => 'INVALID_REQUESTER_INFO' ) ) ) ) );
	}
	return array( 'body' => wp_json_encode( array( 'valid' => true, 'name' => 'G4' ) ) );
};
$g4_vr = Vies::check( $g4_v );
check( 'R5-W14 own VAT ID refused: checked again without it and reported', array( 'valid', true, 2 ), array( $g4_vr['status'], ! empty( $g4_vr['requester_error'] ), $g4_calls ) );
unset( $GLOBALS['test_http'] );
check( 'R5-O11 VIES result only shown for the VAT ID it belongs to', array( true, false ), array( null !== Vies::result_for( $g4_v, array( 'DE 811111186' ) ), null !== Vies::result_for( $g4_v, array( 'DE811111194' ) ) ) );
delete_option( 'wpac_vies_' . (int) $g4_v );
add_filter( 'wpac_vies_daily_cap', static function () { return 100000; } );
$g4_before = Vies::recheck_stale();
AffiliateRepository::set_status( $g4_v, 'terminated', 'Ende' );
check( 'R5-W14 ended partnerships are not re-checked; cap filterable', $g4_before - 1, Vies::recheck_stale() );
remove_all_filters( 'wpac_vies_daily_cap' );
remove_all_filters( 'wpac_vies_enabled' );

/* R5-W16: a refused probe only counts as "protected" when a control file was reachable. */
check( 'R5-W16 probe verdicts', array( true, false, null, null, null ), array( Statement::probe_verdict( 200, 'wpac', false ), Statement::probe_verdict( 403, '', true ), Statement::probe_verdict( 403, '', false ), Statement::probe_verdict( 500, '', true ), Statement::probe_verdict( 401, '', false ) ) );

/* R7-AD17: credit notes to small businesses above the small business limit. */
$g4_sb = $g4_partner( array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67892' ) );
$wpdb->insert( t( 'payouts' ), array( 'number' => 'G4-SB-1', 'affiliate_id' => $g4_sb, 'period_end' => ( (int) wp_date( 'Y' ) - 1 ) . '-06-30', 'currency' => 'EUR', 'amount_minor' => 2600000, 'net_minor' => 2600000, 'status' => 'paid', 'created_at' => ( (int) wp_date( 'Y' ) - 1 ) . '-07-01 10:00:00' ) );
$g4_warn = array_values( array_filter( PayoutService::small_business_warnings(), static function ( $w ) use ( $g4_sb ) { return (int) $w[0] === (int) $g4_sb; } ) );
check( 'R7-AD17 small business above 25,000 € in the previous year is reported', array( 'previous', 2600000 ), $g4_warn ? array( $g4_warn[0][4], $g4_warn[0][2] ) : array() );

update_option( 'wpac_settings', $g4_settings );
Settings::flush_cache();
