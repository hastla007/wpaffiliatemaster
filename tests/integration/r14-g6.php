<?php
/**
 * R14-L1: electronic invoice (EN 16931 CII XML, XRechnung profile) of credit notes, correction
 * credit notes and cancellations. Included from run.php before r8-render.php (helpers check(),
 * t(), q() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\EInvoice;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R14 L1 e-invoice (EN 16931 / XRechnung CII) of credit notes\n";
global $wpdb;
$g6_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $g6_keep, array( 'min_payout_minor' => 1000, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop <GmbH> & Co. KG', 'admin_email' => 'buchhaltung@shop.example', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0, 'einvoice_mail' => 'auto', 'einvoice_buyer_reference' => '' ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );
// The partner mails and the e-invoice attachment (Plugin::boot() registers them in WordPress).
add_action( 'wpac_statement_generated', array( WPAffiliateCaptain\Affiliates\Notifications::class, 'credit_note_issued' ), 10, 2 );
add_action( 'wpac_storno_generated', array( WPAffiliateCaptain\Affiliates\Notifications::class, 'credit_note_cancelled' ), 10, 2 );
EInvoice::register_hooks();
$g6_attached = array();
add_filter(
	'wpac_mail',
	static function ( $mail, $type ) use ( &$g6_attached ) {
		if ( is_array( $mail ) ) {
			foreach ( (array) $mail['attachments'] as $file ) {
				if ( '.xml' === substr( (string) $file, -4 ) ) {
					$g6_attached[] = array( $type, basename( (string) $file ), (string) file_get_contents( $file ), $file );
				}
			}
		}
		return $mail;
	},
	20,
	2
);

$g6_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'G6 ' . $slug, 'email' => 'g6-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'G6 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'G6 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$g6_earn = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return (int) $r['commission_ids'][0];
};
// XPath reader of an XML.
$g6_x = static function ( $xml ) {
	$dom = new DOMDocument();
	$dom->loadXML( (string) $xml );
	$x = new DOMXPath( $dom );
	$x->registerNamespace( 'rsm', EInvoice::NS_RSM );
	$x->registerNamespace( 'ram', EInvoice::NS_RAM );
	$x->registerNamespace( 'udt', EInvoice::NS_UDT );
	$x->registerNamespace( 'qdt', EInvoice::NS_QDT );
	return static function ( $path, $all = false ) use ( $x ) {
		$out = array();
		foreach ( $x->query( $path ) as $n ) {
			$out[] = trim( (string) $n->textContent );
		}
		return $all ? $out : ( $out ? $out[0] : null );
	};
};
$g6_sum = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
// Mandatory leaves must never be empty (the empty delivery element is allowed by the syntax).
$g6_empty = static function ( $xml ) {
	$dom = new DOMDocument();
	$dom->loadXML( (string) $xml );
	$x   = new DOMXPath( $dom );
	$out = array();
	foreach ( $x->query( '//*[not(*) and normalize-space(.) = ""]' ) as $n ) {
		if ( 'ram:ApplicableHeaderTradeDelivery' !== $n->nodeName ) {
			$out[] = $n->nodeName;
		}
	}
	return $out;
};
// Amount as printed on the PDF (German number format, test runtime without number_format_i18n).
$g6_pdf_amount = static function ( $minor ) {
	return number_format( $minor / 100, 2, ',', '.' );
};

/* 1. Standard VAT (domestic VAT-registered partner): 389, category S 19 %, mail with XML. ---- */
$g6_a  = $g6_partner( 'std', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789', 'tax_number' => '' ) );
$g6_earn( $g6_a, 'g6-1', 123456 ); // 10 % = 123.46 € (VAT 23.46 €).
$g6_attached = array();
$g6_p        = PayoutService::create_for_affiliate( $g6_a, gmdate( 'Y-m-d' ), 'EUR' );
$g6_po       = PayoutService::find( $g6_p );
$g6_xml      = EInvoice::payout_xml( $g6_po );
$g6_v        = $g6_x( $g6_xml );
check( 'R14-L1 standard: XML passes the EN 16931 business rules, no empty mandatory field', array( array(), array() ), array( EInvoice::check( (string) $g6_xml ), $g6_empty( $g6_xml ) ) );
check(
	'R14-L1 standard: self-billed invoice 389, EN 16931 profile (no seller phone), number, currency, buyer reference = number',
	array( '389', EInvoice::EN16931, $g6_po->number, 'EUR', $g6_po->number ),
	array( $g6_v( '//rsm:ExchangedDocument/ram:TypeCode' ), $g6_v( '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ), $g6_v( '//rsm:ExchangedDocument/ram:ID' ), $g6_v( '//ram:InvoiceCurrencyCode' ), $g6_v( '//ram:BuyerReference' ) )
);
check(
	'R14-L1 standard: sums = PDF/payout (net, VAT 19 %, gross, amount due)',
	array( Money_g6( (int) $g6_po->net_minor ), Money_g6( (int) $g6_po->vat_minor ), Money_g6( (int) $g6_po->amount_minor ), Money_g6( (int) $g6_po->amount_minor ), 'S', '19', null ),
	array( $g6_v( $g6_sum . 'ram:TaxBasisTotalAmount' ), $g6_v( $g6_sum . 'ram:TaxTotalAmount' ), $g6_v( $g6_sum . 'ram:GrandTotalAmount' ), $g6_v( $g6_sum . 'ram:DuePayableAmount' ), $g6_v( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ), $g6_v( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' ), $g6_v( $g6_sum . 'ram:TotalPrepaidAmount' ) )
);
$g6_pdf = Statement::build( $g6_po );
check( 'R14-L1 standard: the PDF shows the same amounts', array( true, true, true ), array( false !== strpos( $g6_pdf, $g6_pdf_amount( (int) $g6_po->net_minor ) ), false !== strpos( $g6_pdf, $g6_pdf_amount( (int) $g6_po->vat_minor ) ), false !== strpos( $g6_pdf, $g6_pdf_amount( (int) $g6_po->amount_minor ) ) ) );
check(
	'R14-L1 seller = partner (frozen snapshot: legal name, street, postcode, city, country, VAT ID, e-mail), buyer = operator',
	array( 'G6 std', 'Kai 5', '24103', 'Kiel', 'DE', 'DE123456789', 'g6-std@example.de', (string) $g6_a, 'Shop <GmbH> & Co. KG', 'Hafen 1', '20457', 'Hamburg', 'DE', 'DE999999999', 'buchhaltung@shop.example' ),
	array( $g6_v( '//ram:SellerTradeParty/ram:Name' ), $g6_v( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne' ), $g6_v( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ), $g6_v( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName' ), $g6_v( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID' ), $g6_v( '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' ), $g6_v( '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID' ), $g6_v( '//ram:SellerTradeParty/ram:ID' ), $g6_v( '//ram:BuyerTradeParty/ram:Name' ), $g6_v( '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineOne' ), $g6_v( '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ), $g6_v( '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName' ), $g6_v( '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID' ), $g6_v( '//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' ), $g6_v( '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID' ) )
);
check( 'R14-L1 values are XML-escaped by DOM (no markup injection)', array( true, false ), array( false !== strpos( (string) $g6_xml, 'Shop &lt;GmbH&gt; &amp; Co. KG' ), false !== strpos( (string) $g6_xml, '<GmbH>' ) ) );
check(
	'R14-L1 payment: only the masked IBAN is frozen → no payment means 58 without account (BR-61), masked account as text; payment terms; "Gutschrift" note',
	array( 'ZZZ', true, null, true, true, true ),
	array( $g6_v( '//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode' ), false !== strpos( (string) $g6_v( '//ram:SpecifiedTradeSettlementPaymentMeans/ram:Information' ), '3000' ), $g6_v( '//ram:IBANID' ), '' !== (string) $g6_v( '//ram:SpecifiedTradePaymentTerms/ram:Description' ), in_array( 'Credit note (Gutschrift)', $g6_v( '//rsm:ExchangedDocument/ram:IncludedNote/ram:Content', true ), true ), false !== strpos( implode( ' ', $g6_v( '//rsm:ExchangedDocument/ram:IncludedNote/ram:Content', true ) ), 'electronic invoice (EN 16931)' ) )
);
check( 'R14-L1 auto: the credit note mail of a domestic VAT-registered partner carries the XML (same content, file named like the PDF)', array( 1, 'credit_note', $g6_po->number . '.xml', $g6_xml ), array( count( $g6_attached ), $g6_attached[0][0] ?? null, $g6_attached[0][1] ?? null, $g6_attached[0][2] ?? null ) );
check( 'R14-L1 the temporary attachment is removed after the mail', false, isset( $g6_attached[0][3] ) && file_exists( $g6_attached[0][3] ) );

// XRechnung profile once its extra data are known (an add-on adds the seller's phone).
$g6_phone = static function ( $d ) {
	$d['seller']['phone'] = '+49 431 123456';
	return $d;
};
add_filter( 'wpac_einvoice_data', $g6_phone );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_buyer_reference' => '04011000-12345-67' ) ) );
Settings::flush_cache();
$g6_xr = EInvoice::payout_xml( $g6_po );
$g6_xv = $g6_x( $g6_xr );
check( 'R14-L1 XRechnung 3.0 profile with phone, Leitweg-ID as buyer reference; passes the BR-DE checks', array( EInvoice::XRECHNUNG, '04011000-12345-67', '+49 431 123456', array() ), array( $g6_xv( '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ), $g6_xv( '//ram:BuyerReference' ), $g6_xv( '//ram:TelephoneUniversalCommunication/ram:CompleteNumber' ), EInvoice::check( (string) $g6_xr ) ) );
unset( $GLOBALS['test_hooks']['wpac_einvoice_data'] );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_buyer_reference' => '' ) ) );
Settings::flush_cache();
// Full IBAN supplied by an add-on → SEPA credit transfer 58 with the account.
add_filter(
	'wpac_einvoice_data',
	static function ( $d ) {
		$snap         = array( 'iban' => 'DE89 3704 0044 0532 0130 00', 'account_holder' => 'G6 std', 'bic' => 'COBADEFFXXX' );
		$d['payment'] = array( 'code' => '58', 'info' => 'SEPA', 'iban' => str_replace( ' ', '', $snap['iban'] ), 'holder' => $snap['account_holder'], 'bic' => $snap['bic'] );
		return $d;
	}
);
$g6_xi = $g6_x( EInvoice::payout_xml( $g6_po ) );
check( 'R14-L1 payment means 58 with the IBAN when known', array( '58', 'DE89370400440532013000', 'COBADEFFXXX' ), array( $g6_xi( '//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode' ), $g6_xi( '//ram:IBANID' ), $g6_xi( '//ram:BICID' ) ) );
unset( $GLOBALS['test_hooks']['wpac_einvoice_data'] );
// Filter for add-ons on the finished XML.
add_filter(
	'wpac_einvoice_xml',
	static function ( $xml, $document, $row ) {
		return str_replace( '</rsm:CrossIndustryInvoice>', '<!-- ' . $document . ' ' . $row->number . ' --></rsm:CrossIndustryInvoice>', $xml );
	},
	10,
	3
);
check( 'R14-L1 filter wpac_einvoice_xml gets the XML, the document kind and the payout', true, false !== strpos( (string) EInvoice::payout_xml( $g6_po ), '<!-- credit_note ' . $g6_po->number . ' -->' ) );
unset( $GLOBALS['test_hooks']['wpac_einvoice_xml'] );

/* 2. Reverse charge (business in Austria): AE with exemption reason, buyer VAT ID. ------------- */
$g6_b = $g6_partner( 'rc', array( 'tax_status' => 'business', 'vat_id' => 'ATU12345678', 'country' => 'AT', 'address' => "Ring 1\nA-1010 Wien" ) );
$g6_earn( $g6_b, 'g6-2', 50000 );
$g6_attached = array();
$g6_pb       = PayoutService::find( PayoutService::create_for_affiliate( $g6_b, gmdate( 'Y-m-d' ), 'EUR' ) );
$g6_xb       = EInvoice::payout_xml( $g6_pb );
$g6_vb       = $g6_x( $g6_xb );
check( 'R14-L1 reverse charge: XML passes the business rules (BR-AE-02/03/08/09/10)', array( array(), array() ), array( EInvoice::check( (string) $g6_xb ), $g6_empty( $g6_xb ) ) );
check(
	'R14-L1 reverse charge: category AE 0 %, VAT 0, exemption code VATEX-EU-AE and reason, seller AT VAT ID, Austrian postcode/city, due = payout',
	array( 'reverse_charge', 'AE', '0', '0.00', 'VATEX-EU-AE', true, 'ATU12345678', '1010', 'Wien', 'AT', Money_g6( (int) $g6_pb->amount_minor ) ),
	array( $g6_pb->vat_mode, $g6_vb( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ), $g6_vb( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' ), $g6_vb( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount' ), $g6_vb( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode' ), false !== strpos( (string) $g6_vb( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason' ), '13b' ), $g6_vb( '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' ), $g6_vb( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ), $g6_vb( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName' ), $g6_vb( '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID' ), $g6_vb( $g6_sum . 'ram:DuePayableAmount' ) )
);
check( 'R14-L1 auto: no XML attached for a reverse-charge partner (no German VAT on the credit note)', array(), $g6_attached );

/* 3. Small business (§19 UStG): E with the German exemption text, tax number. ----------------- */
$g6_c = $g6_partner( 'kl', array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890', 'vat_id' => '' ) );
$g6_earn( $g6_c, 'g6-3', 40000 );
$g6_attached = array();
$g6_pc       = PayoutService::find( PayoutService::create_for_affiliate( $g6_c, gmdate( 'Y-m-d' ), 'EUR' ) );
$g6_xc       = EInvoice::payout_xml( $g6_pc );
$g6_vc       = $g6_x( $g6_xc );
check( 'R14-L1 small business: XML passes the business rules (BR-E-02/08/09/10)', array( array(), array() ), array( EInvoice::check( (string) $g6_xc ), $g6_empty( $g6_xc ) ) );
check(
	'R14-L1 small business: category E 0 %, reason "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG", tax number (FC), no VAT',
	array( 'E', '0', 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG', '12/345/67890', '0.00', Money_g6( (int) $g6_pc->amount_minor ) ),
	array( $g6_vc( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ), $g6_vc( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' ), $g6_vc( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason' ), $g6_vc( '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]' ), $g6_vc( $g6_sum . 'ram:TaxTotalAmount' ), $g6_vc( $g6_sum . 'ram:DuePayableAmount' ) )
);
check( 'R14-L1 auto: no XML for a small business partner', array(), $g6_attached );

// Setting "always": the small business partner gets it too; "never": nobody.
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_mail' => 'on' ) ) );
Settings::flush_cache();
$g6_attached = array();
do_action( 'wpac_statement_generated', (int) $g6_pc->id, '' );
check( 'R14-L1 setting "always": XML attached for every self-billing partner', array( 1, $g6_pc->number . '.xml' ), array( count( $g6_attached ), $g6_attached[0][1] ?? null ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_mail' => 'off' ) ) );
Settings::flush_cache();
$g6_attached = array();
do_action( 'wpac_statement_generated', (int) $g6_po->id, '' );
check( 'R14-L1 setting "never": no XML attached (download only)', array(), $g6_attached );
$g6_settings_out = Settings::sanitize( array( 'einvoice_mail' => 'bogus', 'einvoice_buyer_reference' => ' <b>991-12345-06</b> ' ), $g6_settings_err );
check( 'R14-L1 settings sanitized (unknown choice: error, stored value stays (P1-03); reference as text)', array( 'off', true, '991-12345-06' ), array( $g6_settings_out['einvoice_mail'], isset( $g6_settings_err['einvoice_mail'] ), $g6_settings_out['einvoice_buyer_reference'] ) );

/* 4. Mixed VAT groups: S 19 %, S 7 %, AE, a repayment and a settled correction credit note. --- */
$g6_now  = Db::now();
$g6_comm = static function ( $aff, $kind, $amount, $desc, $order ) use ( $wpdb, $g6_now ) {
	$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $aff, 'source' => 'wpac' === $order ? 'wpac' : 'test', 'source_order_id' => 'wpac' === $order ? 'G6-' . wp_generate_password( 6, false ) : $order, 'kind' => $kind, 'description' => $desc, 'currency' => 'EUR', 'amount_minor' => $amount, 'status' => 'paid', 'idempotency_key' => 'g6-' . wp_generate_password( 12, false ), 'created_at' => $g6_now, 'updated_at' => $g6_now ) );
	return (int) $wpdb->insert_id;
};
$g6_manual = static function ( $aff, $number, array $items, array $totals, array $op_extra = array() ) use ( $wpdb, $g6_now, $g6_comm ) {
	$wpdb->insert(
		t( 'payouts' ),
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
			'statement_file'    => $number . '-test.pdf',
			'statement_hash'    => str_repeat( 'a', 64 ),
			'created_at'        => $g6_now,
		)
	);
	$pid = (int) $wpdb->insert_id;
	foreach ( $items as $it ) {
		$cid = $g6_comm( $aff, $it[0], $it[1], $it[4], $it[5] );
		$wpdb->insert( t( 'payout_items' ), array( 'payout_id' => $pid, 'commission_id' => $cid, 'amount_minor' => $it[1], 'vat_mode' => $it[2], 'vat_rate' => $it[3], 'ref_payout_id' => isset( $it[6] ) ? $it[6] : 0 ) );
	}
	return PayoutService::find( $pid );
};
// net 10000 (19 %) + 5000 (7 %) + 2000 (AE) − 1500 repayment + 2380 settled correction = 17880; VAT 1900 + 350.
$g6_pm = $g6_manual(
	$g6_a,
	'GS-G6-MIX',
	array(
		array( 'sale', 10000, 'standard', '19', 'Kurs A', 'o-1' ),
		array( 'sale', 5000, 'standard', '7.0', 'Buch B', 'o-2' ),
		array( 'sale', 2000, 'reverse_charge', '0', 'Kurs C', 'o-3' ),
		array( 'repayment', -1500, 'none', '0', 'Rückzahlung', 'wpac' ),
		array( 'correction', 2380, 'none', '0', 'KG-1 – Satz', 'wpac' ),
	),
	array( 17880, 2250 )
);
$g6_xm  = EInvoice::payout_xml( $g6_pm );
$g6_vm  = $g6_x( $g6_xm );
$g6_grp = array();
foreach ( $g6_vm( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', true ) as $g6_i => $g6_cat ) {
	$g6_grp[] = $g6_cat . '|' . $g6_vm( '(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax)[' . ( $g6_i + 1 ) . ']/ram:RateApplicablePercent' ) . '|' . $g6_vm( '(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax)[' . ( $g6_i + 1 ) . ']/ram:BasisAmount' ) . '|' . $g6_vm( '(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax)[' . ( $g6_i + 1 ) . ']/ram:CalculatedAmount' );
}
check( 'R14-L1 mixed: XML passes the business rules (BR-CO-10/13/14/15/16, BR-S-08 per rate, BR-AE-08)', array( array(), array() ), array( EInvoice::check( (string) $g6_xm ), $g6_empty( $g6_xm ) ) );
check( 'R14-L1 mixed: one breakdown per category and rate (19 % and 7.0 % → "7")', array( 'S|19|100.00|19.00', 'S|7|50.00|3.50', 'AE|0|20.00|0.00' ), $g6_grp );
check(
	'R14-L1 mixed: 3 taxable lines; repayment and settled correction credit note are the paid amount (−8.80), so the amount due is the payout (201.30)',
	array( 3, '170.00', '22.50', '192.50', '-8.80', '201.30', Money_g6( (int) $g6_pm->amount_minor ) ),
	array( count( $g6_vm( '//ram:IncludedSupplyChainTradeLineItem', true ) ), $g6_vm( $g6_sum . 'ram:LineTotalAmount' ), $g6_vm( $g6_sum . 'ram:TaxTotalAmount' ), $g6_vm( $g6_sum . 'ram:GrandTotalAmount' ), $g6_vm( $g6_sum . 'ram:TotalPrepaidAmount' ), $g6_vm( $g6_sum . 'ram:DuePayableAmount' ), $g6_vm( $g6_sum . 'ram:DuePayableAmount' ) )
);
$g6_mpdf = Statement::build( $g6_pm );
check( 'R14-L1 mixed: the PDF shows the same VAT per rate and total', array( true, true, true, true ), array( false !== strpos( $g6_mpdf, $g6_pdf_amount( 1900 ) ), false !== strpos( $g6_mpdf, $g6_pdf_amount( 350 ) ), false !== strpos( $g6_mpdf, $g6_pdf_amount( 20130 ) ), false !== strpos( $g6_mpdf, $g6_pdf_amount( 17000 ) ) ) );
check( 'R14-L1 mixed: order number in the line note, description as item name', array( 'Kurs A', 'Order #o-1' ), array( $g6_vm( '(//ram:IncludedSupplyChainTradeLineItem)[1]/ram:SpecifiedTradeProduct/ram:Name' ), $g6_vm( '(//ram:IncludedSupplyChainTradeLineItem)[1]/ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content' ) ) );

// Only settled correction credit notes: a payment notice, no invoice line → no e-invoice.
$g6_pk = $g6_manual( $g6_a, 'GS-G6-KG', array( array( 'correction', 2380, 'none', '0', 'KG-2', 'wpac' ) ), array( 2380, 0 ) );
check( 'R14-L1 credit note without taxable line: no e-invoice', null, EInvoice::payout_xml( $g6_pk ) );

// Correction document of a payout run (settled corrections of an earlier credit note): 384.
$g6_pr = $g6_manual(
	$g6_a,
	'GS-G6-SETTLE',
	array(
		array( 'refund', -2000, 'standard', '19', 'Erstattung', 'g6-1', (int) $g6_po->id ),
		array( 'repayment', 2380, 'none', '0', 'Rückzahlung', 'wpac' ),
	),
	array( 380, -380 ),
	array( 'document' => 'correction' )
);
$g6_xr2 = EInvoice::payout_xml( $g6_pr );
$g6_vr  = $g6_x( $g6_xr2 );
check(
	'R14-L1 correction document of a run: 384 with reference to the corrected credit note, negative line (quantity −1, price positive), due 0',
	array( array(), '384', $g6_po->number, '-1', '20.00', '-20.00', '-3.80', '0.00', true ),
	array( EInvoice::check( (string) $g6_xr2 ), $g6_vr( '//rsm:ExchangedDocument/ram:TypeCode' ), $g6_vr( '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID' ), $g6_vr( '//ram:BilledQuantity' ), $g6_vr( '//ram:NetPriceProductTradePrice/ram:ChargeAmount' ), $g6_vr( '//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount' ), $g6_vr( $g6_sum . 'ram:TaxTotalAmount' ), $g6_vr( $g6_sum . 'ram:DuePayableAmount' ), false !== strpos( (string) $g6_vr( '//ram:IncludedSupplyChainTradeLineItem//ram:IncludedNote/ram:Content' ), $g6_po->number ) )
);

/* 5. Correction credit note (Korrekturgutschrift): 384 with the difference. -------------------- */
PayoutService::mark_paid( $g6_p );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'einvoice_mail' => 'auto' ) ) );
Settings::flush_cache();
add_action( 'wpac_correction_generated', array( WPAffiliateCaptain\Affiliates\Notifications::class, 'credit_note_corrected' ), 10, 2 );
$g6_attached = array();
$g6_k  = Corrections::create( $g6_p, 15000, 'Satz 12 %', 'g6-k1', (int) $g6_po->net_minor );
$g6_kg = Corrections::find( is_wp_error( $g6_k ) ? 0 : $g6_k );
$g6_xk = $g6_kg ? EInvoice::correction_xml( $g6_kg ) : null;
$g6_vk = $g6_x( $g6_xk );
$g6_dn = (int) $g6_kg->net_minor - (int) $g6_kg->prev_net_minor;
$g6_dv = (int) $g6_kg->vat_minor - (int) $g6_kg->prev_vat_minor;
check( 'R14-L1 correction credit note: XML passes the business rules', array( array(), array() ), array( EInvoice::check( (string) $g6_xk ), $g6_empty( $g6_xk ) ) );
check(
	'R14-L1 correction credit note: 384, own number, reference to the credit note, line = net difference, VAT difference as on the PDF, due = gross difference',
	array( '384', $g6_kg->number, $g6_po->number, Money_g6( $g6_dn ), Money_g6( $g6_dv ), Money_g6( $g6_dn + $g6_dv ), true ),
	array( $g6_vk( '//rsm:ExchangedDocument/ram:TypeCode' ), $g6_vk( '//rsm:ExchangedDocument/ram:ID' ), $g6_vk( '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID' ), $g6_vk( $g6_sum . 'ram:LineTotalAmount' ), $g6_vk( $g6_sum . 'ram:TaxTotalAmount' ), $g6_vk( $g6_sum . 'ram:DuePayableAmount' ), in_array( 'Reason for the correction: Satz 12 %', $g6_vk( '//rsm:ExchangedDocument/ram:IncludedNote/ram:Content', true ), true ) )
);
check( 'R14-L1 correction credit note mail carries its XML (auto, domestic VAT)', array( 'credit_note_corrected', $g6_kg->number . '.xml', $g6_xk ), array( $g6_attached ? end( $g6_attached )[0] : null, $g6_attached ? end( $g6_attached )[1] : null, $g6_attached ? end( $g6_attached )[2] : null ) );
$g6_kpdf = Corrections::build( $g6_kg );
check( 'R14-L1 correction credit note: the PDF shows the same difference', array( true, true ), array( false !== strpos( $g6_kpdf, $g6_pdf_amount( $g6_dn ) ), false !== strpos( $g6_kpdf, $g6_pdf_amount( $g6_dn + $g6_dv ) ) ) );

/* 6. Cancellation (Storno): 381 referring to the credit note, amounts of the original. -------- */
$g6_d = $g6_partner( 'st', array( 'tax_status' => 'business', 'vat_id' => 'DE811111111' ) );
$g6_earn( $g6_d, 'g6-6', 80000 );
$g6_ps = PayoutService::create_for_affiliate( $g6_d, gmdate( 'Y-m-d' ), 'EUR' );
$g6_attached = array();
$g6_cr       = PayoutService::cancel( $g6_ps, 'Falscher Zeitraum', true );
$g6_pso      = PayoutService::find( $g6_ps );
$g6_xs       = EInvoice::payout_xml( $g6_pso, true );
$g6_vs       = $g6_x( $g6_xs );
check( 'R14-L1 cancellation: storno document issued', array( true, true ), array( ! is_wp_error( $g6_cr ), '' !== (string) $g6_pso->storno_file ) );
check( 'R14-L1 cancellation: XML passes the business rules', array( array(), array() ), array( EInvoice::check( (string) $g6_xs ), $g6_empty( $g6_xs ) ) );
check(
	'R14-L1 cancellation: 381 with the cancellation number, reference to the credit note, amounts of the credit note (the type reverses them)',
	array( '381', (string) $g6_pso->storno_number, $g6_pso->number, Money_g6( (int) $g6_pso->net_minor ), Money_g6( (int) $g6_pso->vat_minor ), Money_g6( (int) $g6_pso->amount_minor ) ),
	array( $g6_vs( '//rsm:ExchangedDocument/ram:TypeCode' ), $g6_vs( '//rsm:ExchangedDocument/ram:ID' ), $g6_vs( '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID' ), $g6_vs( $g6_sum . 'ram:TaxBasisTotalAmount' ), $g6_vs( $g6_sum . 'ram:TaxTotalAmount' ), $g6_vs( $g6_sum . 'ram:DuePayableAmount' ) )
);
check( 'R14-L1 cancellation mail carries the XML of the cancellation (auto, domestic VAT)', array( 'credit_note_cancelled', $g6_pso->storno_number . '.xml' ), array( $g6_attached ? end( $g6_attached )[0] : null, $g6_attached ? end( $g6_attached )[1] : null ) );

/* 7. Availability: no self-billing, deleted after the retention period. ----------------------- */
$g6_np                    = clone $g6_po;
$g6_np->operator_snapshot = wp_json_encode( array_merge( json_decode( (string) $g6_po->operator_snapshot, true ), array( 'self_billing' => false ) ) );
$g6_del                   = clone $g6_po;
$g6_del->statement_deleted_at = $g6_now;
$g6_del->statement_file   = '';
check( 'R14-L1 no e-invoice for statements without self-billing or after the retention deletion', array( false, null, false, null ), array( EInvoice::available( $g6_np ), EInvoice::payout_xml( $g6_np ), EInvoice::available( $g6_del ), EInvoice::payout_xml( $g6_del ) ) );

/* 8. Download URLs: the PDF links stay as they were, the XML link adds format=xml. ------------ */
$g6_pdf_url = WPAffiliateCaptain\Admin\Pages\PayoutsPage::statement_url( 5 );
$g6_xml_url = WPAffiliateCaptain\Admin\Pages\PayoutsPage::statement_url( 5, true, 'xml' );
check( 'R14-L1 admin download URLs: PDF unchanged (no format), XML with format=xml and the same nonce action', array( false, true, true, true ), array( false !== strpos( $g6_pdf_url, 'format' ), false !== strpos( $g6_xml_url, 'format=xml' ), false !== strpos( $g6_xml_url, 'storno=1' ), false !== strpos( WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_url( 7, 'xml' ), 'format=xml' ) ) );

/* 9. Pure parts: address split and the rule checker catches broken documents. ---------------- */
check(
	'R14-L1 address split (DE, AT prefix, NL, country line, unknown format)',
	array(
		array( array( 'Kai 5' ), '24103', 'Kiel' ),
		array( array( 'Ring 1' ), '1010', 'Wien' ),
		array( array( 'Damrak 1', 'c/o X' ), '1012 LG', 'Amsterdam' ),
		array( array( 'Hauptstr. 2' ), '80331', 'München' ),
		array( array( '10 Downing Street', 'London SW1A 2AA' ), '', '' ),
	),
	array_map(
		static function ( $a ) {
			$s = EInvoice::split_address( $a );
			return array( $s['lines'], $s['postcode'], $s['city'] );
		},
		array( "Kai 5\n24103 Kiel", "Ring 1\nA-1010 Wien", "Damrak 1\nc/o X\n1012 LG Amsterdam", "Hauptstr. 2\n80331 München\nDeutschland", "10 Downing Street\nLondon SW1A 2AA" )
	)
);
$g6_broken = str_replace( '<ram:TaxTotalAmount currencyID="EUR">23.46</ram:TaxTotalAmount>', '<ram:TaxTotalAmount currencyID="EUR">23.47</ram:TaxTotalAmount>', (string) $g6_xml );
$g6_broken = str_replace( '<ram:GrandTotalAmount>146.92</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>146.93</ram:GrandTotalAmount>', $g6_broken );
$g6_rules  = array_keys( EInvoice::check( $g6_broken ) );
check( 'R14-L1 checker: VAT total not the sum of the breakdown (BR-CO-14), due not total − paid (BR-CO-16)', array( true, true ), array( in_array( 'BR-CO-14', $g6_rules, true ), in_array( 'BR-CO-16', $g6_rules, true ) ) );
$g6_rules = array_keys( EInvoice::check( preg_replace( '#<ram:LineTotalAmount>123.46</ram:LineTotalAmount>(\s*</ram:SpecifiedTradeSettlementLineMonetarySummation>)#', '<ram:LineTotalAmount>120.00</ram:LineTotalAmount>$1', (string) $g6_xml, 1 ) ) );
check( 'R14-L1 checker: line sum (BR-CO-10) and taxable amount per rate (BR-S-08) detected', array( true, true ), array( in_array( 'BR-CO-10', $g6_rules, true ), in_array( 'BR-S-08', $g6_rules, true ) ) );
$g6_rules = array_keys( EInvoice::check( str_replace( '<ram:ExemptionReason>Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG</ram:ExemptionReason>', '', (string) $g6_xc ) ) );
check( 'R14-L1 checker: missing exemption reason of a small business (BR-E-10)', array( 'BR-E-10' ), $g6_rules );
$g6_rules = array_keys( EInvoice::check( str_replace( '<ram:ID schemeID="VA">DE999999999</ram:ID>', '', (string) $g6_xb ) ) );
check( 'R14-L1 checker: reverse charge without buyer VAT ID (BR-AE-03)', array( 'BR-AE-03' ), $g6_rules );
check( 'R14-L1 checker: XRechnung without phone/buyer reference flagged', true, in_array( 'BR-DE-6', array_keys( EInvoice::check( str_replace( EInvoice::EN16931 . '<', EInvoice::XRECHNUNG . '<', (string) $g6_xml ) ) ), true ) );
check( 'R14-L1 checker: not well-formed', array( 'XML' ), array_keys( EInvoice::check( '<a>' ) ) );
// Invalid XML characters in snapshot texts are dropped, the XML stays well-formed.
$g6_bad = EInvoice::xml(
	array(
		'number'          => "GS\x01-1",
		'type'            => '389',
		'issue_date'      => '20261115',
		'currency'        => 'EUR',
		'buyer_reference' => 'GS-1',
		'seller'          => array( 'id' => '1', 'name' => "Nähe\x0B GmbH", 'lines' => array(), 'postcode' => '', 'city' => '', 'country' => 'DE', 'vat_id' => 'DE123456789', 'tax_number' => '', 'email' => '', 'phone' => '' ),
		'buyer'           => array( 'name' => 'Shop', 'lines' => array(), 'postcode' => '', 'city' => '', 'country' => 'DE', 'vat_id' => 'DE999999999', 'email' => '' ),
		'lines'           => array( array( 'name' => 'Provision', 'note' => '', 'net' => 1000, 'category' => 'S', 'rate' => '19' ) ),
		'payment'         => array( 'code' => 'ZZZ', 'info' => 'x' ),
		'terms'           => 'x',
	)
);
check( 'R14-L1 control characters removed, XML well-formed and valid by the rules', array( array(), true ), array( EInvoice::check( $g6_bad ), false !== strpos( $g6_bad, 'Nähe GmbH' ) ) );

// Back to the defaults for the following files.
update_option( 'wpac_settings', $g6_keep );
Settings::flush_cache();

/**
 * Minor units as XML amount (EUR).
 *
 * @param int $minor Minor units.
 * @return string
 */
function Money_g6( $minor ) {
	return WPAffiliateCaptain\Core\Money::to_decimal( (int) $minor, 'EUR' );
}
