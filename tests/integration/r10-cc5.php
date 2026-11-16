<?php
/**
 * R6-CC5 rest: retention deletion of credit notes reaches every app server. Two nodes are
 * simulated with two statement folders (filter wpac_statement_dir) sharing one database.
 * Included from run.php (helpers check(), sale(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\StatementSweep;

echo "R10 CC5 retention deletion on several app servers\n";
global $wpdb;

$cc5_settings = get_option( 'wpac_settings' );
update_option(
	'wpac_settings',
	array_merge(
		(array) $cc5_settings,
		array(
			'operator_country'          => 'DE',
			'self_billing'              => 1,
			'maintenance_mode'          => 0,
			'min_payout_minor'          => 5000,
			'vies_check'                => 0,
			'statement_retention_years' => 8,
			'retention_hold'            => 0,
		)
	)
);
Settings::flush_cache();

$cc5_base = sys_get_temp_dir() . '/wpac-cc5-' . getmypid();
$cc5_a    = $cc5_base . '/node-a';
$cc5_b    = $cc5_base . '/node-b';
$cc5_node = static function ( $dir ) {
	remove_all_filters( 'wpac_statement_dir' );
	add_filter( 'wpac_statement_dir', static function () use ( $dir ) { return $dir; } );
};
$cc5_n       = 0;
$cc5_payout  = static function () use ( &$cc5_n ) {
	++$cc5_n;
	$id = AffiliateRepository::create( array( 'name' => 'CC5 Partner ' . $cc5_n, 'email' => 'cc5-' . $cc5_n . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'tax_status' => 'private', 'legal_name' => 'CC5 Partner ' . $cc5_n, 'address' => "Weg {$cc5_n}\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'CC5 Partner', 'iban' => 'DE89370400440532013000' ) );
	$s = sale( 'cc5-' . $cc5_n, WPAffiliateCaptain\Codes\CodeRepository::main_code( $id ), array( 100000 ) );
	Ledger::transition( (int) $s['commission_ids'][0], 'approved' );
	return PayoutService::find( PayoutService::create_for_affiliate( $id, gmdate( 'Y-m-d' ), 'EUR' ) );
};

// Node A issues three credit notes (one of them cancelled); node B serves downloads of them and
// rebuilds the missing files from the data (no shared storage).
$cc5_node( $cc5_a );
$cc5_old   = $cc5_payout(); // Past the retention period: swept on B.
$cc5_old2  = $cc5_payout(); // Past the retention period: download on B after the deletion.
$cc5_live  = $cc5_payout(); // Within the period: stays everywhere.
$cc5_canc  = $cc5_payout(); // Cancelled and past the period: cancellation document swept too.
PayoutService::cancel( (int) $cc5_canc->id, 'Test' );
$cc5_canc = PayoutService::find( (int) $cc5_canc->id );
check( 'R6-CC5 node A holds the credit notes and the cancellation', array( true, true, true, true, true ), array( is_file( $cc5_a . '/' . $cc5_old->statement_file ), is_file( $cc5_a . '/' . $cc5_old2->statement_file ), is_file( $cc5_a . '/' . $cc5_live->statement_file ), is_file( $cc5_a . '/' . $cc5_canc->statement_file ), '' !== (string) $cc5_canc->storno_file && is_file( $cc5_a . '/' . $cc5_canc->storno_file ) ) );

$cc5_node( $cc5_b );
$cc5_storno = clone $cc5_canc;
$cc5_storno->statement_file = $cc5_canc->storno_file;
$cc5_storno->statement_hash = $cc5_canc->storno_hash;
$cc5_got = array(
	Statement::stored_bytes( $cc5_old ),
	Statement::stored_bytes( $cc5_old2 ),
	Statement::stored_bytes( $cc5_live ),
	Statement::stored_bytes( $cc5_canc ),
	Statement::stored_bytes( $cc5_storno, true ),
);
check( 'R6-CC5 node B rebuilt every document byte for byte and stored it locally', array( true, true, true, true, true ), array_map( 'is_string', $cc5_got ) );
check( 'R6-CC5 node B files present', array( true, true, true, true, true ), array( is_file( $cc5_b . '/' . $cc5_old->statement_file ), is_file( $cc5_b . '/' . $cc5_old2->statement_file ), is_file( $cc5_b . '/' . $cc5_live->statement_file ), is_file( $cc5_b . '/' . $cc5_canc->statement_file ), is_file( $cc5_b . '/' . $cc5_canc->storno_file ) ) );
// An unrelated PDF (e.g. another installation's) and a temporary file on node B: never touched.
file_put_contents( $cc5_b . '/foreign-0001-abcdefghijklmnop.pdf', '%PDF-1.4 foreign' );
file_put_contents( $cc5_b . '/wpac-export-AbC123', 'tmp' );

// Retention deletion on node A (daily cron). Only the test payouts are made old.
$cc5_ids = implode( ',', array( (int) $cc5_old->id, (int) $cc5_old2->id, (int) $cc5_canc->id ) );
$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET created_at = '2001-03-01 10:00:00', storno_at = IF(storno_at IS NULL, NULL, '2001-04-01 10:00:00') WHERE id IN ({$cc5_ids})" );
$cc5_node( $cc5_a );
Statement::purge_expired();
$cc5_rows = $wpdb->get_results( 'SELECT id, statement_file, storno_file, statement_deleted_at FROM ' . t( 'payouts' ) . " WHERE id IN ({$cc5_ids}) ORDER BY id", OBJECT_K );
check( 'R6-CC5 retention deletion on node A: rows marked deleted, files on A gone', array( true, true, true, false, false, false ), array( null !== $cc5_rows[ $cc5_old->id ]->statement_deleted_at, null !== $cc5_rows[ $cc5_old2->id ]->statement_deleted_at, null !== $cc5_rows[ $cc5_canc->id ]->statement_deleted_at, is_file( $cc5_a . '/' . $cc5_old->statement_file ), is_file( $cc5_a . '/' . $cc5_canc->statement_file ), is_file( $cc5_a . '/' . $cc5_canc->storno_file ) ) );
check( 'R6-CC5 copies on node B are still there before its sweep', array( true, true ), array( is_file( $cc5_b . '/' . $cc5_old->statement_file ), is_file( $cc5_b . '/' . $cc5_canc->storno_file ) ) );

// Download on node B of a document deleted on A: refused, not rebuilt, the local copy goes.
$cc5_node( $cc5_b );
check( 'R6-CC5 download on node B after the deletion: refused, local copy deleted', array( null, false ), array( Statement::stored_bytes( $cc5_old2 ), is_file( $cc5_b . '/' . $cc5_old2->statement_file ) ) );
check( 'R6-CC5 download on node B with the deletion row: nothing rebuilt', array( null, false ), array( Statement::stored_bytes( PayoutService::find( (int) $cc5_old2->id ) ), is_file( $cc5_b . '/' . $cc5_old2->statement_file ) ) );
check( 'R6-CC5 a deleted credit note is never issued again', array( false, '' ), array( Statement::generate( (int) $cc5_old->id ), (string) PayoutService::find( (int) $cc5_old->id )->statement_file ) );

// Sweep on node B: removes the copies of deleted documents, keeps live and unknown files.
@unlink( $cc5_b . '/' . StatementSweep::MARKER );
$cc5_stats = StatementSweep::run( 0 );
check(
	'R6-CC5 sweep on node B deletes the deleted credit note and the cancellation document',
	array( 3, false, false, false, true ),
	array( $cc5_stats['deleted'], is_file( $cc5_b . '/' . $cc5_old->statement_file ), is_file( $cc5_b . '/' . $cc5_canc->statement_file ), is_file( $cc5_b . '/' . $cc5_canc->storno_file ), $cc5_stats['complete'] )
);
// Files of earlier folders (here the test's WPAC_STATEMENT_DIR) are only looked at, never deleted unless deleted documents.
$cc5_on_b = static function ( array $paths ) use ( $cc5_b ) {
	return array_values( array_filter( $paths, static function ( $p ) use ( $cc5_b ) { return 0 === strpos( $p, $cc5_b . '/' ); } ) );
};
check( 'R6-CC5 sweep keeps the live credit note, unknown and temporary files', array( true, true, true, array( $cc5_b . '/foreign-0001-abcdefghijklmnop.pdf' ) ), array( is_file( $cc5_b . '/' . $cc5_live->statement_file ), is_file( $cc5_b . '/foreign-0001-abcdefghijklmnop.pdf' ), is_file( $cc5_b . '/wpac-export-AbC123' ), $cc5_on_b( $cc5_stats['unknown'] ) ) );
check( 'R6-CC5 sweep is logged; marker file is local to the node', array( true, true, false ), array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'statement.retention_swept'" ) >= 1, is_file( $cc5_b . '/' . StatementSweep::MARKER ), is_file( $cc5_a . '/' . StatementSweep::MARKER ) ) );
check( 'R6-CC5 live credit note still downloads on node B', true, is_string( Statement::stored_bytes( PayoutService::find( (int) $cc5_live->id ) ) ) );

// Request path: throttled by the node's marker; due again once the marker is older than the interval.
$cc5_copy = $cc5_b . '/' . $cc5_old->statement_file;
file_put_contents( $cc5_copy, $cc5_got[0] ); // E.g. restored from a node backup.
StatementSweep::maybe_run();
check( 'R6-CC5 request sweep throttled while the marker is fresh', true, is_file( $cc5_copy ) );
touch( $cc5_b . '/' . StatementSweep::MARKER, time() - 7 * HOUR_IN_SECONDS );
StatementSweep::maybe_run();
check( 'R6-CC5 request sweep runs once the marker is older than the interval', array( false, true ), array( is_file( $cc5_copy ), time() - StatementSweep::last_run() < 60 ) );
$cc5_unknown = count( StatementSweep::run( 0 )['unknown'] );
check( 'R6-CC5 unknown files remembered: not hashed again on the next sweep, again with --full', array( 0, true, $cc5_unknown ), array( StatementSweep::run( 0 )['candidates'], $cc5_unknown >= 1, StatementSweep::run( 0, true )['candidates'] ) );

// Concurrent sweeps on one node: the second one waits for the next time.
$cc5_lock = fopen( $cc5_b . '/' . StatementSweep::MARKER, 'c+' );
flock( $cc5_lock, LOCK_EX );
check( 'R6-CC5 second sweep on the same node at the same time does nothing', true, StatementSweep::run( 0 )['locked'] );
flock( $cc5_lock, LOCK_UN );
fclose( $cc5_lock );

// Race: node A deletes while node B is rebuilding the file for a download.
$cc5_node( $cc5_a );
$cc5_race = $cc5_payout();
$cc5_node( $cc5_b );
$cc5_hook = static function ( $note ) use ( $wpdb, $cc5_race ) {
	$wpdb->query( 'UPDATE ' . t( 'payouts' ) . " SET statement_file = '', statement_deleted_at = '2030-01-01 00:00:00' WHERE id = " . (int) $cc5_race->id );
	return $note;
};
add_filter( 'wpac_statement_note', $cc5_hook );
$cc5_race_bytes = Statement::stored_bytes( $cc5_race );
remove_all_filters( 'wpac_statement_note' );
check( 'R6-CC5 deletion during a rebuild on node B: nothing served, no copy left', array( null, false ), array( $cc5_race_bytes, is_file( $cc5_b . '/' . $cc5_race->statement_file ) ) );

check( 'R6-CC5 forget() refuses a file with another hash', false, StatementSweep::forget( $cc5_b . '/foreign-0001-abcdefghijklmnop.pdf', str_repeat( 'a', 64 ) ) );
check(
	'R6-CC5 documentation: sweep command, handbook and README',
	array( true, true, true ),
	array(
		method_exists( WPAffiliateCaptain\Cli\Command::class, 'statements' ),
		false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/HANDBOOK.md' ), 'wp wpac statements sweep' ),
		false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' ), 'wp wpac statements sweep' ),
	)
);

remove_all_filters( 'wpac_statement_dir' );
update_option( 'wpac_settings', $cc5_settings );
Settings::flush_cache();
foreach ( array( $cc5_a, $cc5_b ) as $cc5_dir ) {
	foreach ( (array) glob( $cc5_dir . '/{,.}*', GLOB_BRACE ) as $cc5_f ) {
		if ( is_file( $cc5_f ) ) {
			@unlink( $cc5_f );
		}
	}
	@rmdir( $cc5_dir );
}
@rmdir( $cc5_base );
