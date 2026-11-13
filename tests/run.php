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
use WPAffiliateCaptain\Payouts\Vat;
use WPAffiliateCaptain\Promotion\Qr;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Tracking\FraudCheck;
use WPAffiliateCaptain\Tracking\RateLimiter;
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
check( 'nothing left', 0, Calculator::refund_adjustment( 5000, 50000, -5000, 10000 ) );
check( 'full reversal remaining', -3000, Calculator::refund_adjustment( 5000, 50000, -2000, 0, true ) );
check( 'refund rounding', -333, Calculator::refund_adjustment( 1000, 3000, 0, 1000 ) );
$sum      = 0;
$refunded = 0;
$steps    = array();
foreach ( array( 1000, 1000, 1000 ) as $part ) {
	$adj      = Calculator::refund_adjustment( 1000, 3000, $sum, $part, false, $refunded );
	$steps[]  = $adj;
	$sum     += $adj;
	$refunded += $part;
}
check( 'three thirds: cumulative rounding', array( -333, -334, -333 ), $steps );
check( 'three thirds sum exactly original', -1000, $sum );
$sum = 0;
$ref = 0;
foreach ( array( 333, 333, 334 ) as $part ) {
	$sum += Calculator::refund_adjustment( 100, 1000, $sum, $part, false, $ref );
	$ref += $part;
}
check( 'no cent left after full refund in parts', -100, $sum );
check( 'refund beyond base capped', -2500, Calculator::refund_adjustment( 5000, 50000, -2500, 40000, false, 25000 ) );

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

/* SEPA execution date ---------------------------------------------------- */
check( 'easter 2026', '2026-04-05', gmdate( 'Y-m-d', SepaXml::easter( 2026 ) ) );
check( 'easter 2027', '2027-03-28', gmdate( 'Y-m-d', SepaXml::easter( 2027 ) ) );
check( 'friday → monday', '2026-11-16', SepaXml::next_business_day( gmmktime( 10, 0, 0, 11, 13, 2026 ) ) );
check( 'thursday before good friday → easter tuesday', '2026-04-07', SepaXml::next_business_day( gmmktime( 10, 0, 0, 4, 2, 2026 ) ) );
check( 'christmas eve → 28 dec', '2026-12-28', SepaXml::next_business_day( gmmktime( 10, 0, 0, 12, 24, 2026 ) ) );
check( 'normal weekday', '2026-11-12', SepaXml::next_business_day( gmmktime( 10, 0, 0, 11, 11, 2026 ) ) );

/* Client IP / proxies ----------------------------------------------------- */
$cf = array( '173.245.48.0/20', '2400:cb00::/32' );
check( 'no proxy: remote addr', '203.0.113.5', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), array() ) );
check( 'untrusted peer: XFF ignored', '203.0.113.5', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), $cf ) );
check( 'trusted CF: CF-Connecting-IP when enabled', '198.51.100.7', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '173.245.50.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ), $cf, array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) ) );
check( 'trusted: right-most untrusted XFF hop', '198.51.100.9', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9, 10.0.0.2' ), array( '10.0.0.0/8' ) ) );
check( 'cidr v4', true, RateLimiter::in_cidr( '173.245.63.255', '173.245.48.0/20' ) );
check( 'cidr v4 outside', false, RateLimiter::in_cidr( '173.245.64.0', '173.245.48.0/20' ) );
check( 'cidr v6', true, RateLimiter::in_cidr( '2400:cb00:2049::1', '2400:cb00::/32' ) );
check( 'cidr mixed families', false, RateLimiter::in_cidr( '10.0.0.1', '2400:cb00::/32' ) );
check( 'ipv6 /64 bucket', '2001:db8:1:2::/64', RateLimiter::bucket_ip( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) );
check( 'ipv4 bucket unchanged', '203.0.113.5', RateLimiter::bucket_ip( '203.0.113.5' ) );

/* VAT treatment of credit notes ------------------------------------------- */
check( 'vat domestic business', array( 'mode' => 'standard', 'rate' => '19' ), Vat::treatment( 'business', 'de', 'DE', '19' ) );
check( 'vat business without country = domestic', 'standard', Vat::treatment( 'business', '', 'DE', '19' )['mode'] );
check( 'vat foreign business', array( 'mode' => 'reverse_charge', 'rate' => '0' ), Vat::treatment( 'business', 'AT', 'DE', '19' ) );
check( 'vat small business', array( 'mode' => 'small_business', 'rate' => '0' ), Vat::treatment( 'small_business', 'DE', 'DE', '19' ) );
check( 'vat foreign small business = reverse charge', 'reverse_charge', Vat::treatment( 'small_business', 'AT', 'DE', '19' )['mode'] );
check( 'vat private', 'private', Vat::treatment( 'private', 'DE', 'DE', '19' )['mode'] );
check( 'vat amount 19 % of 100,00', 1900, Vat::amount( 10000, '19' ) );
check( 'vat amount rounding', 2, Vat::amount( 13, '19' ) );
check( 'vat amount zero rate', 0, Vat::amount( 10000, '0' ) );

/* ZE: CSV partner import (parsing only) and SEPA prerequisites ------------- */
$ze = \WPAffiliateCaptain\Migration\PartnerImport::parse( "\xEF\xBB\xBFName;E-Mail;Website;Status;Codes;Rate;Rate Type;Group;Foo\nAnna;anna@example.com;anna.example;active;ANNA10 ANNA-2;12,5;percent;Influencer;x\n\n\"Bob, Jr.\";bob@example.com;;inactive;;5,00 €;;;\n" );
check( 'ZE import: header aliases, BOM and ";" detected', array( 'name', 'email', 'website', 'status', 'codes', 'rate', 'rate_type', 'group' ), $ze['fields'] );
check( 'ZE import: unknown columns reported', array( 'Foo' ), $ze['unknown'] );
check( 'ZE import: rows keyed by file line, empty lines skipped', array( 2, 4 ), array_keys( $ze['rows'] ) );
check( 'ZE import: quoted cell', 'Bob, Jr.', $ze['rows'][4]['name'] );
$ze = \WPAffiliateCaptain\Migration\PartnerImport::parse( "affiliate_id,first_name,last_name,payment_email,status,rate,rate_type\n7,Kim,Kunde,kim@example.com,active,20,percentage\n" );
$ze_row = $ze['rows'][2];
ksort( $ze_row );
check( 'ZE import: AffiliateWP-style columns (first/last name, payment e-mail, "," separator)', array( 'email' => 'kim@example.com', 'name' => 'Kim Kunde', 'rate' => '20', 'rate_type' => 'percentage', 'status' => 'active' ), $ze_row );
check( 'ZE import: file without e-mail column refused', 'no_email_column', \WPAffiliateCaptain\Migration\PartnerImport::parse( "name;website\nA;b\n" )['error'] );
check( 'ZE import: empty file', 'empty', \WPAffiliateCaptain\Migration\PartnerImport::parse( '' )['error'] );
check( 'ZE import: formula protection of an export is removed', '=SUM', \WPAffiliateCaptain\Migration\PartnerImport::parse( "email;name\na@b.de;'=SUM\n" )['rows'][2]['name'] );
check( 'ZE import: codes split', array( 'A1B', 'C2D', 'E3F' ), \WPAffiliateCaptain\Migration\PartnerImport::split_codes( 'A1B, C2D|E3F  A1B' ) );
check( 'ZE import: percent rate', array( 'percent', '12.5' ), \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '12,5 %' ) );
check( 'ZE import: fixed rate by currency sign', array( 'fixed', '5.00' ), \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '5,00 €' ) );
check( 'ZE import: fixed rate by type (AffiliateWP "flat")', array( 'fixed', '7' ), \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '7', 'flat' ) );
check( 'ZE import: empty rate = no rule', array(), \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '' ) );
check( 'ZE import: percent over 100 refused', null, \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '120' ) );
check( 'ZE import: unknown rate type refused', null, \WPAffiliateCaptain\Migration\PartnerImport::parse_rate( '10', 'tiered' ) );
check( 'ZE SEPA: ext-dom available in the test run', true, SepaXml::dom_available() );

// A2: the free core plugin never contains license checks, gated features or its own updater
// (WordPress.org guidelines 5 and 8). Those belong to the separate premium add-on.
$wpac_guard_patterns = array(
	'LicenseClient'                          => '/LicenseClient/',
	'license server route wplc/v1'           => '#wplc/v1#',
	'wplc_ prefix'                           => '/\bwplc_/i',
	'Update URI header'                      => '/Update URI\s*:/i',
	'pre_set_site_transient_update_plugins'  => '/pre_set_site_transient_update_plugins/',
	'site_transient_update_plugins filter'   => '/[\'"]site_transient_update_plugins[\'"]/',
	'plugins_api filter'                     => '/[\'"]plugins_api[\'"]/',
	'entitlement checks'                     => '/allows_entitlement|entitlement/i',
	'license key handling'                   => '/license_key|licence_key/i',
);
$wpac_guard_files = array( 'affiliate-captain.php', 'uninstall.php' );
foreach ( array( 'src', 'assets', 'blocks', 'tools' ) as $wpac_guard_dir ) {
	$wpac_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/' . $wpac_guard_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $wpac_it as $wpac_f ) {
		if ( preg_match( '/\.(php|js|json)$/', $wpac_f->getFilename() ) ) {
			$wpac_guard_files[] = substr( $wpac_f->getPathname(), strlen( dirname( __DIR__ ) ) + 1 );
		}
	}
}
$wpac_guard_hits = array();
foreach ( $wpac_guard_files as $wpac_f ) {
	$wpac_src = (string) file_get_contents( dirname( __DIR__ ) . '/' . $wpac_f );
	foreach ( $wpac_guard_patterns as $wpac_label => $wpac_re ) {
		if ( preg_match( $wpac_re, $wpac_src ) ) {
			$wpac_guard_hits[] = $wpac_f . ': ' . $wpac_label;
		}
	}
}
check( 'A2 core plugin contains no license client, gating or own updater (' . count( $wpac_guard_files ) . ' files)', array(), $wpac_guard_hits );

// R6-CC3: behind a trusted proxy only X-Forwarded-For is read by default; CF-Connecting-IP and
// X-Real-IP (any client can send them) only when enabled.
check( 'R6-CC3 forged CF-Connecting-IP via a trusted non-Cloudflare proxy ignored', '198.51.100.9', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ), array( '10.0.0.0/8' ) ) );
check( 'R6-CC3 forged X-Real-IP ignored, peer address used', '10.0.0.1', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '6.6.6.6' ), array( '10.0.0.0/8' ) ) );
check( 'R6-CC3 X-Real-IP when enabled', '198.51.100.3', RateLimiter::client_ip( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '198.51.100.3' ), array( '10.0.0.0/8' ), array( 'HTTP_X_REAL_IP' ) ) );
// R7-TR5: prefetch/prerender requests are recognised.
check(
	'R7-TR5 speculative requests (Sec-Purpose, Purpose, X-Moz) vs. normal navigation',
	array( true, true, true, true, false ),
	array(
		WPAffiliateCaptain\Tracking\Tracker::is_speculative( array( 'HTTP_SEC_PURPOSE' => 'prefetch' ) ),
		WPAffiliateCaptain\Tracking\Tracker::is_speculative( array( 'HTTP_SEC_PURPOSE' => 'prefetch;prerender' ) ),
		WPAffiliateCaptain\Tracking\Tracker::is_speculative( array( 'HTTP_PURPOSE' => 'prefetch' ) ),
		WPAffiliateCaptain\Tracking\Tracker::is_speculative( array( 'HTTP_X_MOZ' => 'prefetch' ) ),
		WPAffiliateCaptain\Tracking\Tracker::is_speculative( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0' ) ),
	)
);
/* G6: CI and release hygiene --------------------------------------------- */
$g6_root = dirname( __DIR__ );
// R5-K2: every CI step that pipes into tee runs with pipefail (otherwise the step cannot fail).
$g6_ci    = (string) file_get_contents( $g6_root . '/.github/workflows/ci.yml' );
$g6_steps = preg_split( '/\n\s+- (?=name:|uses:|run:)/', $g6_ci );
$g6_bad   = array();
foreach ( $g6_steps as $g6_step ) {
	if ( preg_match( '/\|\s*tee\b/', $g6_step ) && false === strpos( $g6_step, 'set -o pipefail' ) && ! preg_match( '/shell:\s*bash\s*$/m', $g6_step ) ) {
		$g6_bad[] = strtok( trim( $g6_step ), "\n" );
	}
}
check( 'R5-K2 CI steps piping into tee use pipefail', array(), $g6_bad );
// R5-K2: two phpcs:ignore comments for the same line (one above, one trailing) – the second one
// replaces the first, the first code is reported again (rename_rename in Installer.php).
$g6_double = array();
$g6_it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $g6_root . '/src', FilesystemIterator::SKIP_DOTS ) );
$g6_files  = array( $g6_root . '/affiliate-captain.php', $g6_root . '/uninstall.php' );
foreach ( $g6_it as $g6_file ) {
	if ( '.php' === substr( (string) $g6_file, -4 ) ) {
		$g6_files[] = (string) $g6_file;
	}
}
foreach ( $g6_files as $g6_file ) {
	$g6_lines = file( $g6_file );
	foreach ( $g6_lines as $g6_i => $g6_line ) {
		if ( $g6_i > 0 && preg_match( '#^\s*// phpcs:ignore#', $g6_lines[ $g6_i - 1 ] ) && preg_match( '#\S.*// phpcs:ignore#', $g6_line ) && ! preg_match( '#^\s*//#', $g6_line ) ) {
			$g6_double[] = substr( $g6_file, strlen( $g6_root ) + 1 ) . ':' . ( $g6_i + 1 );
		}
	}
}
check( 'R5-K2 no line with two phpcs:ignore comments', array(), $g6_double );
// R5-W5: ExceptionNotEscaped is not ignored – the WordPress.org upload check does not know our lists.
check( 'R5-W5 plugin check ignore list without ExceptionNotEscaped', false, (bool) preg_match( '/^\s*WordPress\.Security\.EscapeOutput\.ExceptionNotEscaped/m', (string) file_get_contents( $g6_root . '/tests/ci/plugin-check-ignore.txt' ) ) );
check( 'R5-W5 phpcs.xml.dist does not exclude ExceptionNotEscaped', false, false !== strpos( (string) file_get_contents( $g6_root . '/phpcs.xml.dist' ), 'ExceptionNotEscaped"' ) );
// R5-W5: the message of every exception thrown in src/ is a literal or escaped.
$g6_unescaped = array();
foreach ( $g6_files as $g6_file ) {
	$g6_tokens = token_get_all( (string) file_get_contents( $g6_file ) );
	$g6_n      = count( $g6_tokens );
	for ( $g6_i = 0; $g6_i < $g6_n; $g6_i++ ) {
		if ( ! is_array( $g6_tokens[ $g6_i ] ) || T_THROW !== $g6_tokens[ $g6_i ][0] ) {
			continue;
		}
		// throw new Class( args ): collect the top-level arguments.
		$g6_args  = array( '' );
		$g6_depth = 0;
		$g6_class = '';
		$g6_line  = $g6_tokens[ $g6_i ][2];
		for ( $g6_j = $g6_i + 1; $g6_j < $g6_n; $g6_j++ ) {
			$g6_t = $g6_tokens[ $g6_j ];
			$g6_s = is_array( $g6_t ) ? $g6_t[1] : $g6_t;
			if ( 0 === $g6_depth && ';' === $g6_s ) {
				break;
			}
			if ( '(' === $g6_s ) {
				++$g6_depth;
				if ( 1 === $g6_depth ) {
					continue;
				}
			} elseif ( ')' === $g6_s ) {
				--$g6_depth;
				if ( 0 === $g6_depth ) {
					break;
				}
			}
			if ( 0 === $g6_depth ) {
				$g6_class .= $g6_s;
			} elseif ( 1 === $g6_depth && ',' === $g6_s ) {
				$g6_args[] = '';
			} elseif ( ! is_array( $g6_t ) || ! in_array( $g6_t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				$g6_args[ count( $g6_args ) - 1 ] .= $g6_s;
			}
		}
		if ( false === strpos( $g6_class, 'new' ) ) {
			continue; // Rethrow of a caught exception.
		}
		$g6_msg = false !== strpos( $g6_class, 'IntegrationException' ) ? ( isset( $g6_args[1] ) ? $g6_args[1] : '' ) : $g6_args[0];
		$g6_msg = preg_replace( '/^\$\w+\?(?=esc_html)/', '', $g6_msg ); // $cond ? esc_html__() : esc_html__().
		if ( '' === $g6_msg || preg_match( "/^('[^']*'|esc_html(__)?\\(|function_exists\\('esc_html(__)?'\\)\\?esc_html)/", $g6_msg ) ) {
			continue;
		}
		$g6_unescaped[] = substr( $g6_file, strlen( $g6_root ) + 1 ) . ':' . $g6_line;
	}
}
check( 'R5-W5 exception messages are literals or escaped', array(), $g6_unescaped );

// R5-W4: every Upgrade Notice entry fits the 300 characters WordPress.org shows.
$g6_readme = (string) file_get_contents( $g6_root . '/readme.txt' );
$g6_long   = array();
if ( preg_match( '/== Upgrade Notice ==\s*(.*?)(?:\n== |\z)/s', $g6_readme, $g6_m ) ) {
	foreach ( preg_split( '/^= (.+?) =\s*$/m', $g6_m[1], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $g6_i => $g6_part ) {
		if ( 1 === $g6_i % 2 && 300 < mb_strlen( trim( $g6_part ) ) ) {
			$g6_long[] = mb_strlen( trim( $g6_part ) );
		}
	}
}
check( 'R5-W4 upgrade notices have at most 300 characters', array(), $g6_long );
// R5-W3: the release notes are read from the Changelog section only (the Upgrade Notice has the
// same "= x.y.z =" headings and would otherwise become the release text).
check( 'R5-W3 release notes awk reads only the Changelog section', true, (bool) preg_match( '/notes="\$\(awk[^\n]*Changelog/', $g6_ci ) );

echo "\n{$GLOBALS['wpac_count']} checks, {$GLOBALS['wpac_failures']} failures\n";
exit( $GLOBALS['wpac_failures'] ? 1 : 0 );
