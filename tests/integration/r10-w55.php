<?php
/**
 * R5-W55: correction credit notes for paid payouts (objection against a paid credit note).
 * Included from run.php (helpers check(), t(), q() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\Objections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R10 W55 correction credit notes for paid payouts\n";
global $wpdb;
$w55_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $w55_keep, array( 'min_payout_minor' => 5000, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );
// The partner mail of a correction credit note (Plugin::boot() registers it in WordPress).
add_action( 'wpac_correction_generated', array( WPAffiliateCaptain\Affiliates\Notifications::class, 'credit_note_corrected' ), 10, 2 );
if ( ! function_exists( 'wp_nonce_field' ) ) {
	// Same output as the template stub of r8-render.php (which then keeps this one).
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $ref = true, $echo = true ) { $r = '<input type="hidden" name="' . $n . '" value="nonce">'; if ( $echo ) { echo $r; } return $r; }
}

$w55_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'W55 ' . $slug, 'email' => 'w55-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'W55 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'W55 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$w55_earn = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return (int) $r['commission_ids'][0];
};
$w55_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};

// A VAT-registered domestic partner: 100 € commission + 19 % VAT, paid.
$w55_a  = $w55_partner( 'std', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$w55_c1 = $w55_earn( $w55_a, 'w55-1', 100000 );
$w55_p  = PayoutService::create_for_affiliate( $w55_a, gmdate( 'Y-m-d' ), 'EUR' );
$w55_po = PayoutService::find( $w55_p );
check( 'R5-W55 setup: payout 100 € net + 19 € VAT with a stored credit note', array( 10000, 1900, 11900, true ), array( (int) $w55_po->net_minor, (int) $w55_po->vat_minor, (int) $w55_po->amount_minor, '' !== (string) $w55_po->statement_file ) );

// Unpaid payouts are cancelled, not corrected.
check( 'R5-W55 unpaid payout cannot get a correction credit note', 'wpac_state', $w55_code( Corrections::create( $w55_p, 12000, 'x', 'w55-unpaid' ) ) );
PayoutService::mark_paid( $w55_p );
check( 'R5-W55 guidance for a paid credit note points to the correction credit note', array( true, false ), array( false !== strpos( Objections::guidance( PayoutService::find( $w55_p ) ), 'correction credit note' ), false !== strpos( Objections::guidance( PayoutService::find( $w55_p ) ), 'separate transfer' ) ) );
check( 'R5-W55 reason and a non-negative amount are required', array( 'wpac_reason_required', 'wpac_amount' ), array( $w55_code( Corrections::create( $w55_p, 12000, '  ' ) ), $w55_code( Corrections::create( $w55_p, -1, 'x' ) ) ) );
check( 'R5-W55 same amount: nothing to correct', 'wpac_same', $w55_code( Corrections::create( $w55_p, 10000, 'x', 'w55-same', 10000 ) ) );

// The partner objects; the operator issues a correction to 120 € net.
Objections::file( $w55_p, $w55_a, 'Vereinbart waren 12 %' );
$w55_bal0  = Balance::get( $w55_a, 'EUR' );
$w55_mails = count( (array) $GLOBALS['test_mails'] );
$w55_k1    = Corrections::create( $w55_p, 12000, 'Provisionssatz 12 % statt 10 %', 'w55-key-1', 10000 );
$w55_kg    = Corrections::find( is_wp_error( $w55_k1 ) ? 0 : $w55_k1 );
check( 'R5-W55 correction issued with its own gapless number series (KG-year-000001)', 'KG-' . gmdate( 'Y' ) . '-000001', $w55_kg ? $w55_kg->number : $w55_code( $w55_k1 ) );
check(
	'R5-W55 before/corrected amounts with the frozen VAT treatment of the credit note',
	array( 10000, 1900, 12000, 2280, 'standard', '19', $w55_po->number ),
	array( (int) $w55_kg->prev_net_minor, (int) $w55_kg->prev_vat_minor, (int) $w55_kg->net_minor, (int) $w55_kg->vat_minor, $w55_kg->vat_mode, $w55_kg->vat_rate, $w55_kg->base_number )
);
$w55_cm = Ledger::find( (int) $w55_kg->commission_id );
check( 'R5-W55 gross difference booked as an approved "correction" booking (payable with the next run)', array( 'correction', 'approved', 2380, 0, 2380 ), array( $w55_cm->kind, $w55_cm->status, (int) $w55_cm->amount_minor, (int) $w55_cm->payout_id, Balance::get( $w55_a, 'EUR' ) - $w55_bal0 ) );
$w55_entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'balance_entries' ) . ' WHERE commission_id = %d', (int) $w55_kg->commission_id ) );
check( 'R5-W55 account booking of type correction refers to the payout and the KG number', array( 'correction', (int) $w55_p, $w55_kg->number ), array( $w55_entry->type, (int) $w55_entry->payout_id, $w55_entry->reference ) );
check( 'R5-W55 PDF stored with its hash and rebuilt identically from the stored data', array( true, true, true ), array( '' !== (string) $w55_kg->file, is_readable( Statement::path( $w55_kg->file ) ), hash_equals( (string) $w55_kg->hash, hash( 'sha256', Corrections::build( $w55_kg ) ) ) ) );
check( 'R5-W55 stored bytes pass the integrity check', true, hash( 'sha256', (string) Corrections::stored_bytes( $w55_kg ) ) === $w55_kg->hash );
$w55_o = Objections::for_payout( $w55_p );
check( 'R5-W55 open objection resolved with a link to the correction credit note', array( 'resolved', (int) $w55_kg->id, true ), array( $w55_o['status'], isset( $w55_o['correction_id'] ) ? (int) $w55_o['correction_id'] : 0, false !== strpos( $w55_o['resolution'], $w55_kg->number ) ) );
check( 'R5-W55 partner gets the correction credit note and the resolution by e-mail', 2, count( (array) $GLOBALS['test_mails'] ) - $w55_mails );
check( 'R5-W55 issue is audited', 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.correction_issued' AND object_id = %d", $w55_p ) ) );

// Not double-bookable: the same form again, or a stale form after another correction.
check( 'R5-W55 same request key again returns the issued correction, nothing booked twice', array( (int) $w55_kg->id, 1, $w55_bal0 + 2380 ), array( $w55_code( Corrections::create( $w55_p, 12000, 'Provisionssatz 12 % statt 10 %', 'w55-key-1', 10000 ) ), count( Corrections::for_payout( $w55_p ) ), Balance::get( $w55_a, 'EUR' ) ) );
check( 'R5-W55 stale form (based on the original amount) is refused after a correction', 'wpac_changed', $w55_code( Corrections::create( $w55_p, 13000, 'nochmal', 'w55-key-2', 10000 ) ) );
check( 'R5-W55 the booking of a correction credit note cannot be rejected', 'wpac_state', $w55_code( Ledger::reject( (int) $w55_kg->commission_id, 'falsch' ) ) );

// A further correction starts from the first one: overpayment of 30 € net.
$w55_k2  = Corrections::create( $w55_p, 9000, 'Storno einer Bestellung', 'w55-key-3', 12000 );
$w55_kg2 = Corrections::find( is_wp_error( $w55_k2 ) ? 0 : $w55_k2 );
check( 'R5-W55 second correction replaces the first (before = KG 1), overpayment booked negative', array( 'KG-' . gmdate( 'Y' ) . '-000002', $w55_kg->number, 12000, 2280, 9000, 1710, -3570 ), $w55_kg2 ? array( $w55_kg2->number, $w55_kg2->base_number, (int) $w55_kg2->prev_net_minor, (int) $w55_kg2->prev_vat_minor, (int) $w55_kg2->net_minor, (int) $w55_kg2->vat_minor, (int) Ledger::find( (int) $w55_kg2->commission_id )->amount_minor ) : $w55_code( $w55_k2 ) );
check( 'R5-W55 account stays consistent with the commission ledger', array(), Balance::verify( $w55_a, 'EUR' ) );

// Next payout: the differences are settled without VAT a second time (shown on the KG documents).
$w55_earn( $w55_a, 'w55-2', 100000 );
$w55_p2  = PayoutService::create_for_affiliate( $w55_a, gmdate( 'Y-m-d' ), 'EUR' );
$w55_p2o = PayoutService::find( $w55_p2 );
$w55_kinds = array();
foreach ( PayoutService::items( $w55_p2 ) as $w55_it ) {
	$w55_kinds[ $w55_it->kind ][] = $w55_it->vat_mode;
}
check( 'R5-W55 next payout settles both corrections (no VAT on them) with the new commission', array( 10000 - 1190, 1900, 10000 - 1190 + 1900, array( 'none', 'none' ), true ), array( (int) $w55_p2o->net_minor, (int) $w55_p2o->vat_minor, (int) $w55_p2o->amount_minor, isset( $w55_kinds['correction'] ) ? $w55_kinds['correction'] : array(), '' !== (string) $w55_p2o->statement_file ) );
check( 'R5-W55 annual overview years include the correction', true, in_array( (int) gmdate( 'Y' ), Statement::annual_years( $w55_a ), true ) );
check( 'R5-W55 annual overview builds with corrections', true, 0 === strpos( Statement::annual( $w55_a, (int) gmdate( 'Y' ) ), '%PDF' ) );

// Reverse charge: the difference carries no VAT.
$w55_rc = $w55_partner( 'rc', array( 'tax_status' => 'business', 'vat_id' => 'ATU12345678', 'country' => 'AT' ) );
$w55_earn( $w55_rc, 'w55-rc', 100000 );
$w55_prc = PayoutService::create_for_affiliate( $w55_rc, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $w55_prc );
$w55_krc = Corrections::find( (int) Corrections::create( $w55_prc, 11000, 'Bonus vergessen', 'w55-rc' ) );
check( 'R5-W55 reverse charge: difference without VAT', array( 'reverse_charge', 0, 1000 ), $w55_krc ? array( $w55_krc->vat_mode, (int) $w55_krc->vat_minor, (int) Ledger::find( (int) $w55_krc->commission_id )->amount_minor ) : array() );

// Admin: form for payout managers with tax-data access only; download route needs tax data.
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_form( PayoutService::find( $w55_prc ) );
$w55_form = ob_get_clean();
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_PAYOUTS );
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_form( PayoutService::find( $w55_prc ) );
$w55_noform = ob_get_clean();
unset( $GLOBALS['test_caps'] );
check( 'R5-W55 admin form carries the base amount and a one-time key; hidden without tax-data permission', array( true, true, true, '' ), array( false !== strpos( $w55_form, 'name="base_net" value="11000"' ), false !== strpos( $w55_form, 'name="request_key"' ), false !== strpos( $w55_form, 'payout_correction' ), $w55_noform ) );
check( 'R5-W55 portal lists corrections per payout', array( 2 ), array( count( Corrections::for_payouts( array( $w55_p ) )[ $w55_p ] ) ) );

// Retention: the document and its partner snapshot go after the period, the amounts stay.
$w55_file = Statement::path( $w55_krc->file );
Corrections::purge_expired( gmdate( 'Y-m-d H:i:s', time() + 60 ) );
$w55_after = Corrections::find( (int) $w55_krc->id );
check( 'R5-W55 retention deletes the file and the snapshot, keeps the amounts', array( '', null, true, false, 11000 ), array( $w55_after->file, $w55_after->profile_snapshot, null !== $w55_after->file_deleted_at, file_exists( $w55_file ), (int) $w55_after->net_minor ) );
check( 'R5-W55 no correction for a payout whose credit note was deleted', 'wpac_state', ( function () use ( $wpdb, $w55_prc ) {
	$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET statement_deleted_at = '" . gmdate( 'Y-m-d H:i:s' ) . "' WHERE id = " . (int) $w55_prc );
	$r = Corrections::create( $w55_prc, 12000, 'x', 'w55-deleted' );
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
} )() );
check( 'R5-W55 schema check knows the corrections table', array(), WPAffiliateCaptain\Core\Installer::verify_schema() );

update_option( 'wpac_settings', $w55_keep );
Settings::flush_cache();
