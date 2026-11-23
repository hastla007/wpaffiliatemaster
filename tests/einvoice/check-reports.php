<?php
/**
 * Evaluates the KoSIT validator reports (<sample>-report.xml) of the sample e-invoices:
 *
 * - every sample has a report, and the report says "valid" with an "accept" assessment;
 * - no message of level "error" (warnings and information are listed, they do not fail);
 * - the scenario that checked the sample is the expected one: "EN16931 XRechnung (CII)" for
 *   samples whose name contains "xrechnung" (CustomizationID of XRechnung 3.0), otherwise
 *   "EN16931 (CII)" (XML schema + EN 16931 CII schematron of the XRechnung configuration). A
 *   sample that silently loses or gains the XRechnung profile therefore fails too.
 *
 *   php tests/einvoice/check-reports.php <samples dir> <reports dir>
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
if ( PHP_SAPI !== 'cli' || $argc < 3 ) {
	fwrite( STDERR, "Usage: php tests/einvoice/check-reports.php <samples dir> <reports dir>\n" );
	exit( 2 );
}
$samples = glob( rtrim( $argv[1], '/' ) . '/*.xml' );
$reports = rtrim( $argv[2], '/' );
if ( ! $samples ) {
	fwrite( STDERR, "No samples in {$argv[1]}\n" );
	exit( 1 );
}
$fail = 0;
foreach ( $samples as $sample ) {
	$name   = basename( $sample, '.xml' );
	$file   = $reports . '/' . $name . '-report.xml';
	$expect = false !== strpos( $name, 'xrechnung' ) ? 'EN16931 XRechnung (CII)' : 'EN16931 (CII)';
	$dom    = new DOMDocument();
	if ( ! is_file( $file ) || ! @$dom->load( $file ) ) {
		echo "FAIL  {$name}: no readable report\n";
		++$fail;
		continue;
	}
	$x = new DOMXPath( $dom );
	$x->registerNamespace( 'rep', 'http://www.xoev.de/de/validator/varl/1' );
	$x->registerNamespace( 's', 'http://www.xoev.de/de/validator/framework/1/scenarios' );
	$scenario = trim( (string) $x->evaluate( 'string(/rep:report/rep:scenarioMatched/s:scenario/s:name)' ) );
	$valid    = 'true' === $dom->documentElement->getAttribute( 'valid' );
	$accept   = $x->query( '/rep:report/rep:assessment/rep:accept' )->length > 0;
	$errors   = array();
	$notes    = array();
	foreach ( $x->query( '//rep:message' ) as $m ) {
		$line = $m->getAttribute( 'level' ) . ' ' . $m->getAttribute( 'code' ) . ': ' . preg_replace( '/\s+/', ' ', trim( $m->textContent ) );
		if ( 'error' === $m->getAttribute( 'level' ) ) {
			$errors[] = $line;
		} else {
			$notes[] = $line;
		}
	}
	$problems = array();
	if ( $scenario !== $expect ) {
		$problems[] = 'checked by scenario "' . $scenario . '", expected "' . $expect . '"';
	}
	if ( ! $valid || ! $accept ) {
		$problems[] = 'not accepted (valid=' . ( $valid ? 'true' : 'false' ) . ')';
	}
	if ( $errors ) {
		$problems[] = count( $errors ) . ' error(s)';
	}
	echo ( $problems ? 'FAIL  ' : 'OK    ' ) . $name . ' [' . $scenario . ']' . ( $problems ? ' – ' . implode( '; ', $problems ) : '' ) . "\n";
	foreach ( array_merge( $errors, $notes ) as $line ) {
		echo '      ' . $line . "\n";
	}
	$fail += $problems ? 1 : 0;
}
echo count( $samples ) . ' sample(s), ' . $fail . " failed\n";
exit( $fail ? 1 : 0 );
