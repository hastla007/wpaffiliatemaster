<?php
/**
 * Evaluates the JSON output of `wp plugin check <slug> --format=json` (official Plugin Check):
 * prints a summary, lists warnings, and fails on every ERROR that is not in
 * tests/ci/plugin-check-ignore.txt.
 *
 *   wp plugin check wp-affiliate-captain --format=json > pcp.json
 *   php tests/ci/plugin-check.php pcp.json
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$file = $argv[1] ?? '';
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php tests/ci/plugin-check.php <plugin-check-output.json>\n" );
	exit( 2 );
}
$ignore = array();
foreach ( file( __DIR__ . '/plugin-check-ignore.txt', FILE_IGNORE_NEW_LINES ) as $line ) {
	$line = trim( preg_replace( '/#.*$/', '', $line ) );
	if ( '' === $line ) {
		continue;
	}
	$parts    = preg_split( '/\s+/', $line );
	$ignore[] = array( $parts[0], $parts[1] ?? '' );
}

// Output: "FILE: <path>" followed by a JSON array of findings, per file.
$current  = '';
$findings = array();
foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
	if ( 0 === strpos( $line, 'FILE: ' ) ) {
		$current = trim( substr( $line, 6 ) );
		continue;
	}
	$items = json_decode( $line, true );
	if ( ! is_array( $items ) ) {
		continue;
	}
	foreach ( $items as $item ) {
		$item['file'] = $current;
		$findings[]   = $item;
	}
}
$raw = (string) file_get_contents( $file );
if ( ! $findings ) {
	// Plugin Check prints "Success: Checks complete. No errors found." when nothing is found;
	// anything else (empty output, a PHP error) must not pass as "no findings".
	if ( false !== stripos( $raw, 'No errors found' ) ) {
		echo "Plugin Check: no findings.\n";
		exit( 0 );
	}
	fwrite( STDERR, "Plugin Check produced no readable result:\n" . substr( $raw, 0, 2000 ) . "\n" );
	exit( 1 );
}

$errors  = array();
$ignored = array();
$warn    = array();
foreach ( $findings as $f ) {
	$where = $f['file'] . ':' . ( $f['line'] ?? 0 );
	if ( 'ERROR' !== ( $f['type'] ?? '' ) ) {
		$warn[ $f['code'] ] = ( $warn[ $f['code'] ] ?? 0 ) + 1;
		continue;
	}
	foreach ( $ignore as $rule ) {
		if ( $rule[0] === $f['code'] && ( '' === $rule[1] || 0 === strpos( $f['file'], $rule[1] ) ) ) {
			$ignored[ $f['code'] ] = ( $ignored[ $f['code'] ] ?? 0 ) + 1;
			continue 2;
		}
	}
	$errors[] = $where . '  ' . $f['code'] . '  ' . $f['message'];
}
ksort( $warn );
echo "Plugin Check warnings (not failing the build):\n";
foreach ( $warn as $code => $n ) {
	printf( "  %4d  %s\n", $n, $code );
}
echo "Ignored errors (tests/ci/plugin-check-ignore.txt):\n";
foreach ( $ignored as $code => $n ) {
	printf( "  %4d  %s\n", $n, $code );
}
if ( $errors ) {
	echo "\nERRORS:\n  " . implode( "\n  ", $errors ) . "\n";
	echo "\nFix them, or – for a proven false positive – add the code with a reason to tests/ci/plugin-check-ignore.txt.\n";
	exit( 1 );
}
echo "\nPlugin Check: no errors.\n";
exit( 0 );
