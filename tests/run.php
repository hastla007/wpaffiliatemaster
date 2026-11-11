<?php
/**
 * Dependency-free unit tests for the pure (WordPress independent) core logic:
 * money math, IBAN, CSV safety, commission rules/calculation, refunds, SEPA XML.
 *
 * Run: php tests/run.php
 *
 * @package WPAffiliateCaptain
 */

// Pure classes only; the guard constant lets files with an ABSPATH check load.
define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPAffiliateCaptain\\';
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = __DIR__ . '/../src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\Tiers;
use WPAffiliateCaptain\Payouts\Pdf;
use WPAffiliateCaptain\Promotion\Qr;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Tracking\FraudCheck;
use WPAffiliateCaptain\Core\Iban;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Payouts\SepaXml;

$GLOBALS['wpac_failures'] = 0;
$GLOBALS['wpac_count']    = 0;

function check( $name, $expected, $actual ) {
	++$GLOBALS['wpac_count'];
	if ( $expected !== $actual ) {
		++$GLOBALS['wpac_failures'];
		echo "FAIL  {$name}\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
	}
}

function throws( $name, callable $fn ) {
	++$GLOBALS['wpac_count'];
	try {
		$fn();
		++$GLOBALS['wpac_failures'];
		echo "FAIL  {$name}: no exception\n";
	} catch ( \Throwable $e ) {
		// Expected.
	}
}

/* Money ---------------------------------------------------------------- */
check( 'decimals EUR', 2, Money::decimals( 'EUR' ) );
check( 'decimals JPY', 0, Money::decimals( 'jpy' ) );
check( 'normalize de', '1234.56', Money::normalize_decimal( '1.234,56' ) );
check( 'normalize en', '1234.56', Money::normalize_decimal( '1,234.56' ) );
check( 'normalize comma', '10.5', Money::normalize_decimal( '10,5' ) );
check( 'normalize invalid', null, Money::normalize_decimal( 'abc' ) );
check( 'normalize negative', '-3.2', Money::normalize_decimal( '-3,2' ) );
check( 'to_minor string', 1999, Money::to_minor( '19.99', 'EUR' ) );
check( 'to_minor float', 1999, Money::to_minor( 19.99, 'EUR' ) );
check( 'to_minor float 0.1+0.2', 30, Money::to_minor( 0.1 + 0.2, 'EUR' ) );
check( 'to_minor half up', 1235, Money::to_minor( '12.345', 'EUR' ) );
check( 'to_minor negative half', -1235, Money::to_minor( '-12.345', 'EUR' ) );
check( 'to_minor JPY', 1500, Money::to_minor( '1500', 'JPY' ) );
check( 'to_minor KWD', 1235, Money::to_minor( '1.2345', 'KWD' ) );
check( 'to_decimal', '12.05', Money::to_decimal( 1205, 'EUR' ) );
check( 'to_decimal small', '0.05', Money::to_decimal( 5, 'EUR' ) );
check( 'to_decimal negative', '-0.50', Money::to_decimal( -50, 'EUR' ) );
check( 'percent 10% of 99.99', 1000, Money::percent_of( 9999, '10' ) );
check( 'percent 12.5% of 80.00', 1000, Money::percent_of( 8000, '12.5' ) );
check( 'percent 7.25% of 33.33', 242, Money::percent_of( 3333, '7.25' ) );
check( 'prorate half', 500, Money::prorate( 1000, 50, 100 ) );
check( 'prorate rounding', 333, Money::prorate( 1000, 1, 3 ) );
check( 'prorate zero whole', 0, Money::prorate( 1000, 1, 0 ) );
check( 'round_div half away from zero', -3, Money::round_div( -5, 2 ) );
check( 'round_div negative below half', -2, Money::round_div( -9, 4 ) );

/* IBAN ----------------------------------------------------------------- */
check( 'iban valid DE', true, Iban::is_valid( 'DE89 3704 0044 0532 0130 00' ) );
check( 'iban valid AT', true, Iban::is_valid( 'AT611904300234573201' ) );
check( 'iban invalid checksum', false, Iban::is_valid( 'DE89370400440532013001' ) );
check( 'iban invalid format', false, Iban::is_valid( '1234' ) );
check( 'iban mask', 'DE •••• •••• 3000', Iban::mask( '3000', 'DE' ) );

/* CSV ------------------------------------------------------------------ */
check( 'csv formula', "'=HYPERLINK(1)", Csv::safe_cell( '=HYPERLINK(1)' ) );
check( 'csv plus', "'+1+1", Csv::safe_cell( '+1+1' ) );
check( 'csv at', "'@SUM", Csv::safe_cell( '@SUM' ) );
check( 'csv negative number kept', '-12.50', Csv::safe_cell( '-12.50' ) );
check( 'csv dash formula', "'-1+2", Csv::safe_cell( '-1+2' ) );
check( 'csv plain', 'abc', Csv::safe_cell( 'abc' ) );
$csv = Csv::build( array( 'a', 'b' ), array( array( '=1', 'x;y' ) ) );
check( 'csv build', "\xEF\xBB\xBFa;b\n'=1;\"x;y\"\n", $csv );

/* Calculator ------------------------------------------------------------ */
$default = array( 'rate_type' => 'percent', 'rate' => '10' );
$item    = array( 'product_id' => 5, 'parent_id' => 0, 'sku' => 'SBF-SEE', 'category_ids' => array( 3 ), 'quantity' => 1, 'net_minor' => 50000 );
$rule    = static function ( $id, $scope, $scope_id, $target, $value, $type, $rate, $prio = 0 ) {
	return array( 'id' => $id, 'scope' => $scope, 'scope_id' => $scope_id, 'target' => $target, 'target_value' => $value, 'rate_type' => $type, 'rate' => $rate, 'priority' => $prio, 'active' => 1 );
};

$r = Calculator::calculate( $item, array(), 7, 0, $default );
check( 'default 10%', 5000, $r['amount_minor'] );
check( 'default rule id', 0, $r['rule_id'] );

$rules = array(
	$rule( 1, 'global', 0, 'all', '', 'percent', '8' ),
	$rule( 2, 'global', 0, 'category', '3', 'percent', '12' ),
	$rule( 3, 'group', 2, 'all', '', 'percent', '15' ),
	$rule( 4, 'affiliate', 7, 'sku', 'SBF-*', 'fixed', '2500' ),
	$rule( 5, 'global', 0, 'sku', 'GROUP-*', 'exclude', '0' ),
);
check( 'global category beats global all', 2, Calculator::calculate( $item, array_slice( $rules, 0, 2 ), 9, 0, $default )['rule_id'] );
check( 'group beats global category', 3, Calculator::calculate( $item, array_slice( $rules, 0, 3 ), 9, 2, $default )['rule_id'] );
$r = Calculator::calculate( $item, $rules, 7, 2, $default );
check( 'affiliate sku wildcard wins', 4, $r['rule_id'] );
check( 'fixed amount', 2500, $r['amount_minor'] );

$group_item = array_merge( $item, array( 'sku' => 'GROUP-10', 'net_minor' => 100000 ) );
$r          = Calculator::calculate( $group_item, $rules, 7, 2, $default );
check( 'exclusion wins over affiliate rule', true, $r['excluded'] );
check( 'excluded amount', 0, $r['amount_minor'] );

$r = Calculator::calculate( array_merge( $item, array( 'quantity' => 3, 'net_minor' => 6000 ) ), array( $rule( 9, 'global', 0, 'all', '', 'fixed', '2500' ) ), 1, 0, $default );
check( 'fixed per unit capped by base', 6000, $r['amount_minor'] );

$r = Calculator::calculate( array_merge( $item, array( 'product_id' => 11, 'parent_id' => 10 ) ), array( $rule( 9, 'global', 0, 'product', '10, 20', 'percent', '20' ) ), 1, 0, $default );
check( 'variation matches parent product', 9, $r['rule_id'] );

$r = Calculator::calculate( $item, array( $rule( 1, 'global', 0, 'all', '', 'percent', '5', 0 ), $rule( 2, 'global', 0, 'all', '', 'percent', '6', 10 ) ), 1, 0, $default );
check( 'priority tiebreak', 2, $r['rule_id'] );

$inactive         = $rule( 1, 'global', 0, 'all', '', 'percent', '50' );
$inactive['active'] = 0;
check( 'inactive ignored', 0, Calculator::calculate( $item, array( $inactive ), 1, 0, $default )['rule_id'] );
check( 'group rule needs group', 0, Calculator::calculate( $item, array( $rule( 3, 'group', 2, 'all', '', 'percent', '15' ) ), 1, 0, $default )['rule_id'] );

/* Refund adjustments ---------------------------------------------------- */
check( 'partial refund 50%', -2500, Calculator::refund_adjustment( 5000, 50000, 0, 25000 ) );
check( 'second refund capped', -2500, Calculator::refund_adjustment( 5000, 50000, -2500, 40000 ) );
check( 'nothing left', 0, Calculator::refund_adjustment( 5000, 50000, -5000, 10000 ) );
check( 'full reversal remaining', -3000, Calculator::refund_adjustment( 5000, 50000, -2000, 0, true ) );
check( 'refund rounding', -333, Calculator::refund_adjustment( 1000, 3000, 0, 1000 ) );
$sum = 0;
foreach ( array( 1000, 1000, 1000 ) as $part ) {
	$sum += Calculator::refund_adjustment( 1000, 3000, $sum, $part );
}
check( 'three thirds never exceed original', -999, $sum );
check( 'final full reversal closes rounding gap', -1, Calculator::refund_adjustment( 1000, 3000, $sum, 0, true ) );

/* SEPA ------------------------------------------------------------------ */
$xml = SepaXml::build(
	array( 'name' => 'Segelschule Müller GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX' ),
	array(
		array( 'end_to_end_id' => 'AC-2026-00001', 'name' => 'Anna Österreicher', 'iban' => 'AT611904300234573201', 'bic' => '', 'amount_minor' => 12345, 'remittance' => 'Provision AC-2026-00001' ),
		array( 'end_to_end_id' => 'AC-2026-00002', 'name' => 'Ben', 'iban' => 'DE89370400440532013000', 'bic' => '', 'amount_minor' => 5000, 'remittance' => 'Provision AC-2026-00002' ),
	),
	'ACSEPA20261111',
	'2026-11-12',
	'2026-11-11T10:00:00'
);
$doc = new DOMDocument();
check( 'sepa well-formed', true, $doc->loadXML( $xml ) );
$xp = new DOMXPath( $doc );
$xp->registerNamespace( 'p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03' );
check( 'sepa ctrl sum', '173.45', $xp->evaluate( 'string(//p:GrpHdr/p:CtrlSum)' ) );
check( 'sepa nb tx', '2', $xp->evaluate( 'string(//p:GrpHdr/p:NbOfTxs)' ) );
check( 'sepa transliteration', 'Anna Oesterreicher', $xp->evaluate( 'string(//p:CdtTrfTxInf[1]/p:Cdtr/p:Nm)' ) );
check( 'sepa amount', '123.45', $xp->evaluate( 'string(//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt)' ) );
check( 'sepa currency attr', 'EUR', $xp->evaluate( 'string(//p:CdtTrfTxInf[1]/p:Amt/p:InstdAmt/@Ccy)' ) );
check( 'sepa debtor name', 'Segelschule Mueller GmbH', $xp->evaluate( 'string(//p:Dbtr/p:Nm)' ) );
throws( 'sepa rejects invalid creditor iban', static function () {
	SepaXml::build( array( 'name' => 'X', 'iban' => 'DE89370400440532013000', 'bic' => '' ), array( array( 'end_to_end_id' => '1', 'name' => 'A', 'iban' => 'DE00123', 'bic' => '', 'amount_minor' => 1, 'remittance' => '' ) ), 'M', '2026-01-01' );
} );
throws( 'sepa rejects non-positive amount', static function () {
	SepaXml::build( array( 'name' => 'X', 'iban' => 'DE89370400440532013000', 'bic' => '' ), array( array( 'end_to_end_id' => '1', 'name' => 'A', 'iban' => 'DE89370400440532013000', 'bic' => '', 'amount_minor' => -5, 'remittance' => '' ) ), 'M', '2026-01-01' );
} );

/* Tag rules -------------------------------------------------------------- */
$tag_item = array_merge( $item, array( 'tag_ids' => array( 42 ) ) );
$r        = Calculator::calculate( $tag_item, array( $rule( 1, 'global', 0, 'all', '', 'percent', '5' ), $rule( 2, 'global', 0, 'tag', '41,42', 'percent', '9' ) ), 1, 0, $default );
check( 'tag rule beats all', 2, $r['rule_id'] );
check( 'tag rule amount', 4500, $r['amount_minor'] );
check( 'tag rule needs tag', 1, Calculator::calculate( $item, array( $rule( 1, 'global', 0, 'all', '', 'percent', '5' ), $rule( 2, 'global', 0, 'tag', '42', 'percent', '9' ) ), 1, 0, $default )['rule_id'] );
check( 'tag exclusion', true, Calculator::calculate( $tag_item, array( $rule( 3, 'global', 0, 'tag', '42', 'exclude', '0' ) ), 1, 0, $default )['excluded'] );

/* Monthly tiers ---------------------------------------------------------- */
$steps = Tiers::parse_steps( "5000:3\n1000:2", 'revenue', 'EUR' );
check( 'tiers parse sorted', array( array( 'threshold' => 100000, 'rate' => '2' ), array( 'threshold' => 500000, 'rate' => '3' ) ), $steps );
check( 'tiers parse invalid', null, Tiers::parse_steps( 'abc', 'revenue', 'EUR' ) );
check( 'tiers parse rate > 100', null, Tiers::parse_steps( '10:150', 'revenue', 'EUR' ) );
$orders = array( array( 'revenue' => 300000 ), array( 'revenue' => 400000 ) ); // 7.000 €.
$r      = Tiers::bonus( $orders, $steps, 'revenue', 'retroactive' );
check( 'retroactive: 3% of 7000', 21000, $r['amount'] );
$r = Tiers::bonus( $orders, $steps, 'revenue', 'marginal' );
// 2% of (5000-1000) + 3% of (7000-5000) = 80 + 60 = 140 €.
check( 'marginal revenue bands', 14000, $r['amount'] );
check( 'below first step', 0, Tiers::bonus( array( array( 'revenue' => 50000 ) ), $steps, 'revenue', 'marginal' )['amount'] );
$ostep  = Tiers::parse_steps( "3:1\n5:2", 'orders', 'EUR' );
$orders = array_fill( 0, 6, array( 'revenue' => 10000 ) );
// Orders 3,4 → 1 %, orders 5,6 → 2 %: 2*100 + 2*200 = 600.
check( 'marginal orders', 600, Tiers::bonus( $orders, $ostep, 'orders', 'marginal' )['amount'] );
check( 'retroactive orders', 1200, Tiers::bonus( $orders, $ostep, 'orders', 'retroactive' )['amount'] );
$orders[1]['revenue'] = 0; // Fully refunded order does not count.
check( 'refunded order not counted', 5, Tiers::bonus( $orders, $ostep, 'orders', 'retroactive' )['orders'] );

/* PDF -------------------------------------------------------------------- */
$pdf = new Pdf( array( 'title' => 'Test', 'created' => 0 ) );
$pdf->text( 50, 50, 'Größe (1) \\ 12,00 €' );
$pdf->add_page();
$out = $pdf->output();
check( 'pdf header', '%PDF-1.4', substr( $out, 0, 8 ) );
check( 'pdf eof', "%%EOF\n", substr( $out, -6 ) );
check( 'pdf escaping + winansi', true, false !== strpos( $out, '(Gr' . "\xF6\xDF" . 'e \\(1\\) \\\\ 12,00 ' . "\x80" . ')' ) );
check( 'pdf page count', true, false !== strpos( $out, '/Count 2' ) );
preg_match( '/startxref\n(\d+)/', $out, $m );
check( 'pdf xref offset', 'xref', substr( $out, (int) $m[1], 4 ) );
check( 'pdf wrap', array( 'aaaa bbbb', 'cccc' ), Pdf::wrap( 'aaaa bbbb cccc', 50, 10 ) );

/* QR (structure; decoding is verified with tests/qr_decode_check.py) ------ */
$q = Qr::matrix( 'https://example.com/?partner=ANNA' );
check( 'qr version 3 size', 29, count( $q ) );
$finder = static function ( $m, $x0, $y0 ) {
	$ok = true;
	for ( $i = 0; $i < 7; $i++ ) {
		$ok = $ok && $m[ $y0 ][ $x0 + $i ] && $m[ $y0 + 6 ][ $x0 + $i ] && $m[ $y0 + $i ][ $x0 ] && $m[ $y0 + $i ][ $x0 + 6 ];
	}
	return $ok && $m[ $y0 + 3 ][ $x0 + 3 ] && ! $m[ $y0 + 1 ][ $x0 + 1 ];
};
check( 'qr finder top-left', true, $finder( $q, 0, 0 ) );
check( 'qr finder top-right', true, $finder( $q, 22, 0 ) );
check( 'qr finder bottom-left', true, $finder( $q, 0, 22 ) );
check( 'qr v10 for 213 bytes', 57, count( Qr::matrix( str_repeat( 'x', 213 ) ) ) );
throws( 'qr too long', static function () {
	Qr::matrix( str_repeat( 'x', 214 ) );
} );
check( 'qr svg', 0, strpos( Qr::svg( 'A' ), '<svg' ) );

/* Multi-currency -------------------------------------------------------- */
$rates = Fx::parse( "usd = 0,92\nCHF:1.05\nbad line\nJPY=0.0062" );
check( 'fx parse', array( 'USD' => '0.92', 'CHF' => '1.05', 'JPY' => '0.0062' ), $rates );
check( 'fx same currency', 1234, Fx::to_program( 1234, 'EUR', 'EUR', $rates ) );
check( 'fx usd→eur', 9200, Fx::to_program( 10000, 'USD', 'EUR', $rates ) );
check( 'fx jpy (0 decimals)→eur', 620, Fx::to_program( 1000, 'JPY', 'EUR', $rates ) );
check( 'fx unknown currency', null, Fx::to_program( 100, 'GBP', 'EUR', $rates ) );
check( 'fx min payout in usd', 5435, Fx::from_program( 5000, 'USD', 'EUR', $rates ) );
check( 'fx min payout in jpy', 8065, Fx::from_program( 5000, 'JPY', 'EUR', $rates ) );

/* Fraud: e-mail domains -------------------------------------------------- */
check( 'disposable domain', true, FraudCheck::domain_blocked( 'x@Mailinator.com' ) );
check( 'subdomain of blocked', true, FraudCheck::domain_blocked( 'x@mx.yopmail.com' ) );
check( 'custom blocked', true, FraudCheck::domain_blocked( 'a@spam.example', array( 'spam.example' ) ) );
check( 'normal domain', false, FraudCheck::domain_blocked( 'kunde@segelschule.de' ) );
check( 'no false suffix match', false, FraudCheck::domain_blocked( 'a@notmailinator.com' ) );

echo "\n{$GLOBALS['wpac_count']} checks, {$GLOBALS['wpac_failures']} failures\n";
exit( $GLOBALS['wpac_failures'] ? 1 : 0 );
