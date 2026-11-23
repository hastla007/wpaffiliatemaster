<?php
/**
 * Writes sample e-invoices (EN 16931 CII, XRechnung 3.0 when all its data are known) of every
 * kind src/Payouts/EInvoice.php produces, for the official KoSIT validator (CI job "E-invoice
 * (KoSIT validator)", tests/einvoice/validate.sh).
 *
 *   WPAC_TEST_DB_HOST=127.0.0.1 WPAC_TEST_DB_NAME=wpac_test php tests/einvoice/generate.php <dir>
 *
 * Uses the runtime and the database of the integration tests (tests/integration/bootstrap.php –
 * the database is wiped). The documents go through the real code paths: partner profile, sale,
 * payout run, correction credit note, cancellation; only the mixed and the run-correction
 * documents insert their payout rows directly (as tests/integration/r14-g6.php does).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$out_dir = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/' ) : '';
if ( '' === $out_dir ) {
	fwrite( STDERR, "Usage: php tests/einvoice/generate.php <output directory>\n" );
	exit( 2 );
}
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create {$out_dir}\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/integration/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\EInvoice;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

global $wpdb;

update_option(
	'wpac_settings',
	array_merge(
		Settings::defaults(),
		array(
			'min_payout_minor'         => 1000,
			'self_billing'             => 1,
			'vat_rate'                 => '19',
			'operator_country'         => 'DE',
			'operator_address'         => "Hafen 1\n20457 Hamburg",
			'operator_vat_id'          => 'DE999999999',
			'debtor_name'              => 'Segelschule Test GmbH',
			'debtor_iban'              => 'DE89370400440532013000',
			'admin_email'              => 'buchhaltung@shop.example',
			'terms_page_id'            => 900,
			'vies_check'               => 0,
			'fraud_check_domains'      => 0,
			'einvoice_mail'            => 'off',
			'einvoice_buyer_reference' => '',
		)
	)
);
Settings::flush_cache();
// Every partner accepted the terms (with the self-billing agreement), as in run.php.
add_action(
	'wpac_affiliate_created',
	static function ( $id ) {
		WPAffiliateCaptain\Affiliates\Agreements::record( (int) $id );
	}
);

$failed  = 0;
$written = array();
$partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'Partner ' . $slug, 'email' => 'partner-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'Partner ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'Partner ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . Db::table( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return (int) $id;
};
$earn    = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Segelkurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
};
$payout  = static function ( $aff ) {
	return PayoutService::find( PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' ) );
};
$write   = static function ( $name, $xml ) use ( $out_dir, &$failed, &$written ) {
	if ( ! is_string( $xml ) || '' === $xml ) {
		fwrite( STDERR, "FAIL  {$name}: no XML produced\n" );
		++$failed;
		return;
	}
	file_put_contents( $out_dir . '/' . $name . '.xml', $xml );
	$problems = EInvoice::check( $xml );
	$profile  = false !== strpos( $xml, EInvoice::XRECHNUNG ) ? 'XRechnung 3.0' : 'EN 16931';
	echo $name . '.xml (' . $profile . ')' . ( $problems ? ' – built-in check: ' . implode( ', ', array_keys( $problems ) ) : '' ) . "\n";
	$written[] = $name;
};
// Payout row inserted directly (several VAT groups in one credit note, run correction document).
$now    = Db::now();
$manual = static function ( $aff, $number, array $items, array $totals, array $op_extra = array() ) use ( $wpdb, $now ) {
	$wpdb->insert(
		Db::table( 'payouts' ),
		array(
			'number'            => $number,
			'affiliate_id'      => $aff,
			'period_end'        => gmdate( 'Y-m-d' ),
			'currency'          => 'EUR',
			'net_minor'         => $totals[0],
			'vat_mode'          => 'standard',
			'vat_rate'          => '19',
			'vat_minor'         => $totals[1],
			'amount_minor'      => $totals[0] + $totals[1],
			'status'            => 'paid',
			'profile_snapshot'  => wp_json_encode( ProfileRepository::snapshot( ProfileRepository::get( $aff ) ) ),
			'operator_snapshot' => wp_json_encode( array_merge( PayoutService::operator_snapshot(), $op_extra ) ),
			'statement_file'    => $number . '-sample.pdf',
			'statement_hash'    => str_repeat( 'a', 64 ),
			'created_at'        => $now,
		)
	);
	$pid = (int) $wpdb->insert_id;
	foreach ( $items as $it ) {
		$wpdb->insert( Db::table( 'commissions' ), array( 'affiliate_id' => $aff, 'source' => 'wpac' === $it[5] ? 'wpac' : 'test', 'source_order_id' => 'wpac' === $it[5] ? 'S-' . wp_generate_password( 6, false ) : $it[5], 'kind' => $it[0], 'description' => $it[4], 'currency' => 'EUR', 'amount_minor' => $it[1], 'status' => 'paid', 'idempotency_key' => 'sample-' . wp_generate_password( 12, false ), 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( Db::table( 'payout_items' ), array( 'payout_id' => $pid, 'commission_id' => (int) $wpdb->insert_id, 'amount_minor' => $it[1], 'vat_mode' => $it[2], 'vat_rate' => $it[3], 'ref_payout_id' => isset( $it[6] ) ? $it[6] : 0 ) );
	}
	return PayoutService::find( $pid );
};

/* 1. Standard VAT (domestic VAT-registered business): 389, category S 19 %. */
$std = $partner( 'standard', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$earn( $std, 'o-1', 123456 );
$p_std = $payout( $std );
$write( '01-credit-note-standard-S', EInvoice::payout_xml( $p_std ) );

/* 2. Reverse charge (business in Austria): AE. */
$rc = $partner( 'reverse-charge', array( 'tax_status' => 'business', 'vat_id' => 'ATU12345678', 'country' => 'AT', 'address' => "Ring 1\nA-1010 Wien" ) );
$earn( $rc, 'o-2', 50000 );
$write( '02-credit-note-reverse-charge-AE', EInvoice::payout_xml( $payout( $rc ) ) );

/* 3. Small business (§ 19 UStG): E. */
$sb = $partner( 'small-business', array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890' ) );
$earn( $sb, 'o-3', 40000 );
$write( '03-credit-note-small-business-E', EInvoice::payout_xml( $payout( $sb ) ) );

/* 4. Private person (not subject to VAT): O. */
$pp = $partner( 'private', array( 'tax_status' => 'private' ) );
$earn( $pp, 'o-4', 30000 );
$write( '04-credit-note-private-O', EInvoice::payout_xml( $payout( $pp ) ) );

/* 5. Mixed VAT groups: S 19 %, S 7 %, AE, a repayment and a settled correction credit note. */
$write(
	'05-credit-note-mixed-groups',
	EInvoice::payout_xml(
		$manual(
			$std,
			'GS-SAMPLE-MIX',
			array(
				array( 'sale', 10000, 'standard', '19', 'Kurs A', 'o-5a' ),
				array( 'sale', 5000, 'standard', '7.0', 'Buch B', 'o-5b' ),
				array( 'sale', 2000, 'reverse_charge', '0', 'Kurs C', 'o-5c' ),
				array( 'repayment', -1500, 'none', '0', 'Rückzahlung', 'wpac' ),
				array( 'correction', 2380, 'none', '0', 'KG-1', 'wpac' ),
			),
			array( 17880, 2250 )
		)
	)
);

/* 6. Correction credit note (Korrekturgutschrift): 384 with the difference. */
PayoutService::mark_paid( (int) $p_std->id );
$kg = Corrections::create( (int) $p_std->id, 15000, 'Satz 12 %', 'sample-k1', (int) $p_std->net_minor );
$kg = is_wp_error( $kg ) ? null : Corrections::find( (int) $kg );
$write( '06-correction-credit-note-384', $kg ? EInvoice::correction_xml( $kg ) : null );

/* 7. Correction document of a payout run (settled corrections of an earlier credit note): 384. */
$write(
	'07-run-correction-384',
	EInvoice::payout_xml(
		$manual(
			$std,
			'GS-SAMPLE-SETTLE',
			array(
				array( 'refund', -2000, 'standard', '19', 'Erstattung', 'o-1', (int) $p_std->id ),
				array( 'repayment', 2380, 'none', '0', 'Rückzahlung', 'wpac' ),
			),
			array( 380, -380 ),
			array( 'document' => 'correction' )
		)
	)
);

/* 8. Cancellation (Storno): 381 referring to the cancelled credit note. */
$st = $partner( 'storno', array( 'tax_status' => 'business', 'vat_id' => 'DE811111111' ) );
$earn( $st, 'o-8', 80000 );
$p_st = $payout( $st );
PayoutService::cancel( (int) $p_st->id, 'Falscher Zeitraum', true );
$p_st = PayoutService::find( (int) $p_st->id );
$write( '08-cancellation-381', EInvoice::payout_xml( $p_st, true ) );

/* Edge cases: a correction that lowers the credit note (negative line, VAT and amount due); a
 * business outside the EU (Swiss UID, reverse charge); a small business outside the EU with a tax
 * number only and an address without a known postcode format (reverse charge). */
$kg2 = Corrections::create( (int) $p_std->id, 9000, 'Satz 9 %', 'sample-k2' );
$kg2 = is_wp_error( $kg2 ) ? null : Corrections::find( (int) $kg2 );
$write( '13-correction-credit-note-decrease-384', $kg2 ? EInvoice::correction_xml( $kg2 ) : null );
$ch = $partner( 'non-eu', array( 'tax_status' => 'business', 'vat_id' => 'CHE-123.456.789 MWST', 'country' => 'CH', 'address' => "Bahnhofstrasse 1\n8001 Zürich" ) );
$earn( $ch, 'o-14', 60000 );
$write( '14-credit-note-non-eu-business-AE', EInvoice::payout_xml( $payout( $ch ) ) );
$us = $partner( 'small-business-us', array( 'tax_status' => 'small_business', 'tax_number' => '12-3456789', 'country' => 'US', 'address' => "1 Market Street\nSan Francisco, CA 94105" ) );
$earn( $us, 'o-15', 20000 );
$write( '15-credit-note-foreign-small-business-AE', EInvoice::payout_xml( $payout( $us ) ) );

/* 16. Withholding per partner (SET-AC-20): 15 % of the net withheld, shown as paid amount
 * (BT-113) with a note; no allowance, no VAT line. */
$wh = $partner( 'withholding', array( 'tax_status' => 'business', 'vat_id' => 'DE822222222' ) );
WPAffiliateCaptain\Payouts\Withholding::save( $wh, '15', 'Quellensteuer nach § 50a EStG, an das Finanzamt abgeführt.' );
$earn( $wh, 'o-16', 100000 );
$p_wh = $payout( $wh );
$write( '16-credit-note-withholding-S', EInvoice::payout_xml( $p_wh ) );

/* 9. XRechnung 3.0: seller phone from an add-on (filter wpac_einvoice_data), Leitweg-ID. */
add_filter(
	'wpac_einvoice_data',
	static function ( $d ) {
		$d['seller']['phone'] = '+49 431 123456';
		return $d;
	}
);
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_buyer_reference' => '04011000-12345-67' ) ) );
Settings::flush_cache();
$write( '09-xrechnung-credit-note-standard-S', EInvoice::payout_xml( PayoutService::find( (int) $p_std->id ) ) );
$write( '10-xrechnung-correction-credit-note-384', $kg ? EInvoice::correction_xml( $kg ) : null );
$write( '11-xrechnung-cancellation-381', EInvoice::payout_xml( $p_st, true ) );
$write( '17-xrechnung-credit-note-withholding-S', EInvoice::payout_xml( PayoutService::find( (int) $p_wh->id ) ) );
$sb2 = $partner( 'small-business-xr', array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67891' ) );
$earn( $sb2, 'o-12', 40000 );
$write( '12-xrechnung-credit-note-small-business-E', EInvoice::payout_xml( $payout( $sb2 ) ) );

echo count( $written ) . " sample(s) written to {$out_dir}\n";
exit( $failed ? 1 : 0 );
