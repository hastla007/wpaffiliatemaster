<?php
/**
 * Integration checks of work package H3 (test gaps R5-O55, R6-LC9, R6-MS8): the WP-CLI import and
 * export commands (round trip, error cases) here; locale handling with WPML/Polylang, uninstall
 * (wizard pages, time budget) and the takeover of the old plugin copy on a network in their own
 * processes (r9-h3-isolated.php, r9-h3-takeover.php).
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Cli\ExportCommand;
use WPAffiliateCaptain\Cli\ImportCommand;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;

echo "R9 H3 CLI import/export, WPML/Polylang, uninstall, takeover\n";

// WP-CLI stand-in: error() ends the command (an exception here instead of exit), the rest is recorded.
if ( ! class_exists( 'WP_CLI', false ) ) {
	class H3_Cli_Halt extends RuntimeException {}
	class WP_CLI {
		public static $out = array();
		public static function error( $m ) { self::$out[] = array( 'error', (string) $m ); throw new H3_Cli_Halt( (string) $m ); }
		public static function warning( $m ) { self::$out[] = array( 'warning', (string) $m ); }
		public static function success( $m ) { self::$out[] = array( 'success', (string) $m ); }
		public static function log( $m ) { self::$out[] = array( 'log', (string) $m ); }
		public static function add_command( $n, $c ) { return true; }
	}
	eval( 'namespace WP_CLI\Utils; function format_items( $format, $items, $fields ) { \WP_CLI::$out[] = array( "items", $format, $items, $fields ); }' );
}
/** Run a command; returns the recorded output and whether it ended with an error. */
function h3_cli( callable $run ) {
	WP_CLI::$out = array();
	$halted      = false;
	try {
		$run();
	} catch ( H3_Cli_Halt $e ) {
		$halted = true;
	}
	return array( 'halted' => $halted, 'out' => WP_CLI::$out );
}
/** Last message of a kind ('error', 'warning', 'success', 'log'). */
function h3_msg( array $res, $kind ) {
	$found = '';
	foreach ( $res['out'] as $o ) {
		if ( $kind === $o[0] ) {
			$found = $o[1];
		}
	}
	return $found;
}
/** Rows passed to format_items. */
function h3_items( array $res ) {
	foreach ( $res['out'] as $o ) {
		if ( 'items' === $o[0] ) {
			return $o;
		}
	}
	return array( 'items', '', array(), array() );
}

global $wpdb;
$h3_dir = sys_get_temp_dir() . '/wpac-h3-' . getmypid();
@mkdir( $h3_dir, 0777, true );
$h3_count = static function () use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'affiliates' ) ); };

/* Export → import round trip ---------------------------------------------------------------- */
$h3_ids = array(
	AffiliateRepository::create( array( 'name' => 'H3 Anna Ölmann', 'email' => 'h3-anna@example.de', 'status' => 'active', 'website' => 'https://anna.example', 'channel' => 'Blog' ) ),
	AffiliateRepository::create( array( 'name' => 'H3 Bert', 'email' => 'h3-bert@example.de', 'status' => 'pending', 'website' => '', 'channel' => 'Instagram' ) ),
	AffiliateRepository::create( array( 'name' => 'H3 Cora; "Quote"', 'email' => 'h3-cora@example.de', 'status' => 'active', 'website' => 'https://cora.example', 'channel' => '' ) ),
);
AffiliateRepository::set_status( (int) $h3_ids[2], 'paused', 'H3' );
$h3_fields = static function () use ( $h3_ids ) {
	$out = array();
	AffiliateRepository::clear_cache();
	foreach ( array( 'h3-anna@example.de', 'h3-bert@example.de', 'h3-cora@example.de' ) as $email ) {
		$a     = AffiliateRepository::find_by_email( $email );
		$out[] = $a ? array( $a->name, $a->email, $a->website, $a->status, $a->channel ) : null;
	}
	return $out;
};
$h3_before = $h3_fields();
$h3_zip    = $h3_dir . '/program.zip';
$h3_res    = h3_cli( static function () use ( $h3_zip ) { ( new ExportCommand() )( array( $h3_zip ), array() ); } );
check( 'R5-O55 wp wpac export: success, row counts logged, file only readable by the owner', array( false, 'Exported to ' . $h3_zip, true, '0600' ), array( $h3_res['halted'], h3_msg( $h3_res, 'success' ), (bool) preg_match( '/^partners\.csv\s+\d+ rows$/m', implode( "\n", array_map( static function ( $o ) { return 'log' === $o[0] ? $o[1] : ''; }, $h3_res['out'] ) ) ), substr( sprintf( '%o', fileperms( $h3_zip ) ), -4 ) ) );
check( 'R5-O55 wp wpac export without --include-tax-data: no warning about bank data', '', h3_msg( $h3_res, 'warning' ) );

// The partners of the export, as a file for the import (header + the three partners).
$h3_z = new ZipArchive();
$h3_z->open( $h3_zip );
$h3_partners = (string) $h3_z->getFromName( 'partners.csv' );
$h3_z->close();
$h3_lines = array();
$h3_fh    = fopen( 'php://memory', 'w+' );
fwrite( $h3_fh, $h3_partners );
rewind( $h3_fh );
$h3_header = null;
$h3_out    = fopen( $h3_dir . '/partners.csv', 'w' );
while ( false !== ( $h3_row = fgetcsv( $h3_fh, 0, ';', '"', '' ) ) ) {
	if ( null === $h3_header ) {
		$h3_header = $h3_row;
		fputcsv( $h3_out, $h3_row, ';', '"', '' );
		continue;
	}
	$h3_assoc = array_combine( $h3_header, $h3_row );
	if ( 0 === strpos( (string) $h3_assoc['email'], 'h3-' ) ) {
		fputcsv( $h3_out, $h3_row, ';', '"', '' );
		$h3_lines[] = $h3_assoc['email'];
	}
}
fclose( $h3_out );
fclose( $h3_fh );
check( 'R5-O55 export: partners.csv holds the three partners (UTF-8 BOM, ";")', array( 3, true ), array( count( $h3_lines ), 0 === strpos( $h3_partners, "\xEF\xBB\xBFid;" ) ) );

// "New site": the partners are gone, the exported file is imported again.
$wpdb->query( 'DELETE FROM ' . Db::table( 'partner_codes' ) . ' WHERE affiliate_id IN (' . implode( ',', array_map( 'intval', $h3_ids ) ) . ')' );
$wpdb->query( 'DELETE FROM ' . Db::table( 'affiliates' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $h3_ids ) ) . ')' );
AffiliateRepository::clear_cache();
$h3_n   = $h3_count();
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/partners.csv' ), array( 'dry-run' => true ) ); } );
// The exported user_id of the old site does not exist here: a note, the partner is imported.
check( 'R5-O55 wp wpac import --dry-run: success, nothing saved, every row "ok"', array( false, $h3_n, true, array( true, true, true ) ), array( $h3_res['halted'], $h3_count(), false !== strpos( h3_msg( $h3_res, 'success' ), 'Dry run: 3 partners can be imported, 0 already exist, 0 rows have errors. Nothing was saved.' ), array_map( static function ( $r ) { return 0 === strpos( $r, 'ok' ); }, array_column( h3_items( $h3_res )[2], 'result' ) ) ) );
check( 'R5-O55 wp wpac import: database columns of the export that are not import columns are listed as ignored', true, false !== strpos( h3_msg( $h3_res, 'warning' ), 'Ignored columns: ' ) && false !== strpos( h3_msg( $h3_res, 'warning' ), 'slug' ) );
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/partners.csv' ), array( 'format' => 'json' ) ); } );
check( 'R5-O55 wp wpac import: 3 created, table in the requested format', array( false, $h3_n + 3, 'json', array( 'line', 'email', 'status', 'codes', 'result' ), true ), array( $h3_res['halted'], $h3_count(), h3_items( $h3_res )[1], h3_items( $h3_res )[3], (bool) preg_match( '/^3 partners created/', h3_msg( $h3_res, 'success' ) . h3_msg( $h3_res, 'warning' ) ) ) );
check( 'R5-O55 round trip: name (with umlaut, ";" and quotes), e-mail, website, status, channel unchanged', $h3_before, $h3_fields() );
$h3_anna = AffiliateRepository::find_by_email( 'h3-anna@example.de' );
check( 'R5-O55 round trip: an active partner without a codes column gets a code (codes are in codes.csv)', 1, count( CodeRepository::for_affiliate( (int) $h3_anna->id ) ) );
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/partners.csv' ), array() ); } );
check( 'R5-O55 wp wpac import of the same file again: all skipped, nothing new', array( false, $h3_n + 3, '0 partners created (0 with warnings), 3 already existed, 0 rows with errors.' ), array( $h3_res['halted'], $h3_count(), h3_msg( $h3_res, 'success' ) ) );

/* Error cases ---------------------------------------------------------------------------------- */
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/missing.csv' ), array() ); } );
check( 'R5-O55 wp wpac import: unreadable file is an error', array( true, 'File not readable: ' . $h3_dir . '/missing.csv' ), array( $h3_res['halted'], h3_msg( $h3_res, 'error' ) ) );
file_put_contents( $h3_dir . '/noemail.csv', "name;website\nA;a.example\n" );
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/noemail.csv' ), array() ); } );
check( 'R5-O55 wp wpac import: header without an e-mail column is an error', array( true, true ), array( $h3_res['halted'], '' !== h3_msg( $h3_res, 'error' ) ) );
file_put_contents( $h3_dir . '/bad.csv', "email;status\nkeine-adresse;active\nh3-bad@example.de;sleeping\n" );
$h3_n   = $h3_count();
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/bad.csv' ), array() ); } );
check( 'R5-O55 wp wpac import: only rows with errors → error with the summary, nothing saved', array( true, '0 partners created (0 with warnings), 0 already existed, 2 rows with errors.', $h3_n, array( true, true ) ), array( $h3_res['halted'], h3_msg( $h3_res, 'error' ), $h3_count(), array_map( static function ( $i ) { return 0 === strpos( $i['result'], 'error: ' ); }, h3_items( $h3_res )[2] ) ) );
file_put_contents( $h3_dir . '/mixed.csv', "email;status\nh3-mixed@example.de;active\nh3-bad@example.de;sleeping\n" );
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ImportCommand() )->partners( array( $h3_dir . '/mixed.csv' ), array() ); } );
check( 'R5-O55 wp wpac import: some rows with errors → warning, the good row is imported', array( false, '1 partners created (0 with warnings), 0 already existed, 1 rows with errors.', '', $h3_n + 1 ), array( $h3_res['halted'], h3_msg( $h3_res, 'warning' ), h3_msg( $h3_res, 'success' ), $h3_count() ) );

$GLOBALS['test_caps'] = array();
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ExportCommand() )( array( $h3_dir . '/tax.zip' ), array( 'include-tax-data' => true ) ); } );
unset( $GLOBALS['test_caps'] );
check( 'R5-O55 wp wpac export --include-tax-data without wpac_view_tax_data: error, no file', array( true, true, false ), array( $h3_res['halted'], false !== strpos( h3_msg( $h3_res, 'error' ), 'wpac_view_tax_data' ), file_exists( $h3_dir . '/tax.zip' ) ) );
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ExportCommand() )( array( $h3_dir . '/tax.zip' ), array( 'include-tax-data' => true ) ); } );
check( 'R5-O55 wp wpac export --include-tax-data with the capability: bank details included, warning shown', array( false, true, true ), array( $h3_res['halted'], false !== strpos( h3_msg( $h3_res, 'warning' ), 'decrypted bank details' ), (bool) preg_match( '/^payout_details\.csv/m', implode( "\n", array_column( $h3_res['out'], 1 ) ) ) ) );
set_error_handler( static function () { return true; }, E_WARNING ); // ZipArchive warns about the missing folder.
$h3_res = h3_cli( static function () use ( $h3_dir ) { ( new ExportCommand() )( array( $h3_dir . '/no/such/dir/x.zip' ), array() ); } );
restore_error_handler();
check( 'R5-O55 wp wpac export into a missing folder: error, nothing left behind', array( true, true, false ), array( $h3_res['halted'], '' !== h3_msg( $h3_res, 'error' ), file_exists( $h3_dir . '/no/such/dir/x.zip' ) ) );

foreach ( (array) glob( $h3_dir . '/*' ) as $h3_f ) {
	@unlink( $h3_f );
}
@rmdir( $h3_dir );

/* Own processes: WPML/Polylang locale, uninstall (wizard pages, time budget), takeover --------- */
$h3_run = static function ( $script, $prefix, array $args = array() ) {
	$old = getenv( 'WPAC_TEST_DB_PREFIX' );
	putenv( 'WPAC_TEST_DB_PREFIX=' . $prefix );
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $script ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $out );
	putenv( false === $old ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $old );
	$res = json_decode( (string) end( $out ), true );
	if ( ! is_array( $res ) ) {
		check( 'R9-H3 ' . $script . ' ' . implode( ' ', $args ) . ' ran', 'json', implode( "\n", $out ) );
		return;
	}
	foreach ( $res as $name => $pair ) {
		check( $name, $pair[0], $pair[1] );
	}
};
$h3_run( 'r9-h3-isolated.php', 'h3u_' );
$h3_run( 'r9-h3-takeover.php', 'h3t_', array( 'fresh' ) );
$h3_run( 'r9-h3-takeover.php', 'h3t_', array( 'preloaded' ) );
