<?php
/**
 * KoSIT: the CI job that validates the sample e-invoices (tests/einvoice) with the official KoSIT
 * validator – pinned and checksum-verified downloads, the report evaluation and its wiring.
 * Included from run.php before r8-render.php (helpers check(), t() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
echo "KoSIT e-invoice validation in CI\n";

$kosit_root = dirname( __DIR__, 2 );
$kosit_src  = static function ( $rel ) use ( $kosit_root ) {
	return (string) file_get_contents( $kosit_root . '/' . $rel );
};
$kosit_ci  = $kosit_src( '.github/workflows/ci.yml' );
$kosit_sh  = $kosit_src( 'tests/einvoice/validate.sh' );
$kosit_job = preg_match( '/\n  einvoice:\n(.*?)(?=\n  [a-z0-9-]+:\n)/s', $kosit_ci, $kosit_m ) ? $kosit_m[1] : '';
check(
	'KoSIT CI job: own job with Java (setup-java pinned to a commit SHA), samples generated from the integration runtime, validated by validate.sh',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $kosit_job, 'name: E-invoice (KoSIT validator)' ),
		1 === preg_match( '#uses: actions/setup-java@[0-9a-f]{40} \##', $kosit_job ),
		false !== strpos( $kosit_job, 'php tests/einvoice/generate.php "$RUNNER_TEMP/einvoice/samples"' ),
		false !== strpos( $kosit_job, 'tests/einvoice/validate.sh "$RUNNER_TEMP/einvoice/samples" "$RUNNER_TEMP/einvoice/work"' ),
		false === strpos( $kosit_job, 'continue-on-error' ),
	)
);
preg_match_all( '/^([A-Z_]+_SHA256)="([0-9a-f]*)"$/m', $kosit_sh, $kosit_sums );
check(
	'KoSIT downloads: validator and XRechnung configuration at fixed versions from the official repositories, each verified with a SHA-256 checksum, strict shell',
	array( 2, true, true, true, true, true ),
	array(
		count( array_filter( $kosit_sums[2], static function ( $s ) { return 64 === strlen( $s ); } ) ),
		1 === preg_match( '/^VALIDATOR_VERSION="\d+\.\d+\.\d+"$/m', $kosit_sh ),
		1 === preg_match( '/^CONFIG_TAG="v\d{4}-\d{2}-\d{2}"$/m', $kosit_sh ),
		false !== strpos( $kosit_sh, 'https://github.com/itplr-kosit/validator/releases/download/v${VALIDATOR_VERSION}/' ) && false !== strpos( $kosit_sh, 'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/${CONFIG_TAG}/' ),
		false !== strpos( $kosit_sh, 'sha256sum -c --quiet - || { echo "Checksum mismatch' ),
		false !== strpos( $kosit_sh, 'set -euo pipefail' ) && false === strpos( $kosit_sh, 'latest' ),
	)
);

// Report evaluation (check-reports.php) on synthetic validator reports.
$kosit_dir = sys_get_temp_dir() . '/wpac-kosit-' . getmypid();
@mkdir( $kosit_dir . '/samples', 0777, true );
@mkdir( $kosit_dir . '/reports', 0777, true );
$kosit_report = static function ( $name, $scenario, $valid, $accept, array $messages = array() ) use ( $kosit_dir ) {
	$msgs = '';
	foreach ( $messages as $m ) {
		$msgs .= '<rep:message level="' . $m[0] . '" code="' . $m[1] . '">[' . $m[1] . ']-text</rep:message>';
	}
	file_put_contents( $kosit_dir . '/samples/' . $name . '.xml', '<x/>' );
	file_put_contents(
		$kosit_dir . '/reports/' . $name . '-report.xml',
		'<?xml version="1.0"?><rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1" xmlns:s="http://www.xoev.de/de/validator/framework/1/scenarios" valid="' . ( $valid ? 'true' : 'false' ) . '">'
		. '<rep:scenarioMatched><s:scenario><s:name>' . $scenario . '</s:name></s:scenario></rep:scenarioMatched>'
		. '<rep:validationStepResult>' . $msgs . '</rep:validationStepResult>'
		. '<rep:assessment>' . ( $accept ? '<rep:accept/>' : '<rep:reject/>' ) . '</rep:assessment></rep:report>'
	);
};
$kosit_run = static function () use ( $kosit_dir ) {
	$out  = array();
	$code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/einvoice/check-reports.php' ) . ' ' . escapeshellarg( $kosit_dir . '/samples' ) . ' ' . escapeshellarg( $kosit_dir . '/reports' ) . ' 2>&1', $out, $code );
	return array( $code, implode( "\n", $out ) );
};
$kosit_clear = static function () use ( $kosit_dir ) {
	foreach ( array_merge( (array) glob( $kosit_dir . '/samples/*' ), (array) glob( $kosit_dir . '/reports/*' ) ) as $f ) {
		@unlink( (string) $f );
	}
};
$kosit_report( '01-credit-note', 'EN16931 (CII)', true, true, array( array( 'warning', 'BR-W-1' ) ) );
$kosit_report( '09-xrechnung-credit-note', 'EN16931 XRechnung (CII)', true, true );
$kosit_ok = $kosit_run();
check( 'KoSIT reports: accepted samples in the expected scenario pass; warnings are listed, not fatal', array( 0, true, true ), array( $kosit_ok[0], false !== strpos( $kosit_ok[1], 'warning BR-W-1' ), false !== strpos( $kosit_ok[1], '2 sample(s), 0 failed' ) ) );
$kosit_report( '02-error', 'EN16931 (CII)', true, true, array( array( 'error', 'BR-CO-15' ) ) );
$kosit_err = $kosit_run();
check( 'KoSIT reports: an error-level finding fails, even when the report accepts the document', array( 1, true ), array( $kosit_err[0], false !== strpos( $kosit_err[1], 'FAIL  02-error' ) && false !== strpos( $kosit_err[1], 'error BR-CO-15' ) ) );
$kosit_clear();
$kosit_report( '03-rejected', 'EN16931 (CII)', false, false );
$kosit_rej = $kosit_run();
$kosit_clear();
$kosit_report( '10-xrechnung-lost-profile', 'EN16931 (CII)', true, true );
$kosit_prof = $kosit_run();
$kosit_clear();
$kosit_report( '04-standard-in-xr-scenario', 'EN16931 XRechnung (CII)', true, true );
$kosit_prof2 = $kosit_run();
$kosit_clear();
file_put_contents( $kosit_dir . '/samples/05-no-report.xml', '<x/>' );
$kosit_none = $kosit_run();
$kosit_clear();
check(
	'KoSIT reports: a rejected document, a sample checked by the wrong scenario (either way) and a missing report fail',
	array( 1, 1, 1, 1, true ),
	array( $kosit_rej[0], $kosit_prof[0], $kosit_prof2[0], $kosit_none[0], false !== strpos( $kosit_prof[1], 'expected "EN16931 XRechnung (CII)"' ) )
);
$kosit_empty = $kosit_run();
check( 'KoSIT reports: no samples at all fail (nothing validated)', 1, $kosit_empty[0] );
@rmdir( $kosit_dir . '/samples' );
@rmdir( $kosit_dir . '/reports' );
@rmdir( $kosit_dir );
