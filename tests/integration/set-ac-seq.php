<?php
/**
 * SET-AC-15 (owner decision E7, option a): start number and digits per number range (credit
 * notes, cancellations, correction credit notes). Year in the number and restart per year stay
 * fixed. Checks: format unchanged by default, start at or below the highest issued number refused,
 * raising continues gapless and unique, digits 4–10 (longer values never cut), year change,
 * documents already issued unchanged, concurrency on the locked counter row.
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Sequence;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC-15 number ranges: start number and digits\n";

global $wpdb;
$sq_saved = get_option( 'wpac_settings' );
SettingsSchema::flush();
Settings::flush_cache();
$sq_y   = (int) wp_date( 'Y' );
$sq_seq = t( 'sequences' );

// Documents issued before any change (credit notes, cancellations, correction credit notes).
$sq_docs = static function () use ( $wpdb ) {
	return array(
		$wpdb->get_results( 'SELECT id, number, storno_number FROM ' . t( 'payouts' ) . ' ORDER BY id', ARRAY_A ),
		$wpdb->get_results( 'SELECT id, number FROM ' . t( 'payout_corrections' ) . ' ORDER BY id', ARRAY_A ),
	);
};
$sq_before = $sq_docs();

/* Register: six keys, legal mark, defaults = former fixed format. */
$sq_keys = array();
foreach ( Sequence::RANGES as $r ) {
	foreach ( array( '_start', '_digits' ) as $suffix ) {
		$f                        = SettingsSchema::field( $r . $suffix );
		$sq_keys[ $r . $suffix ] = array( $f['type'], $f['tab'], $f['default'], $f['min'], ! empty( $f['legal'] ), '' !== $f['help'] );
	}
}
check(
	'SET-AC-15 register: start (min 1) and digits (4–10, default 6) per range, legal mark, help text',
	array(
		'credit_start'      => array( 'int', 'payouts', 1, 1, true, true ),
		'credit_digits'     => array( 'int', 'payouts', 6, 4, true, true ),
		'storno_start'      => array( 'int', 'payouts', 1, 1, true, true ),
		'storno_digits'     => array( 'int', 'payouts', 6, 4, true, true ),
		'correction_start'  => array( 'int', 'payouts', 1, 1, true, true ),
		'correction_digits' => array( 'int', 'payouts', 6, 4, true, true ),
	),
	$sq_keys
);
check( 'SET-AC-15 digits: upper limit 10', 10, SettingsSchema::int_max()['credit_digits'] );
check( 'SET-AC-15 default format unchanged (GS-2026-000001)', array( 'GS-2026-000001', 6 ), array( Sequence::format( 'GS', 2026, 1 ), Sequence::digits( 'credit' ) ) );
check( 'SET-AC-15 a value longer than the digits is never cut', array( 'GS-2026-1234567', 'GS-2026-12345' ), array( Sequence::format( 'GS', 2026, 1234567, 6 ), Sequence::format( 'GS', 2026, 12345, 4 ) ) );
check( 'SET-AC-15 other sequences keep 6 digits', 6, Sequence::digits( 'addon_range' ) );
check( 'SET-AC-15 legal keys are never reset', array(), array_values( array_intersect( array_keys( $sq_keys ), SettingsTransfer::reset_keys( 'payouts' ) ) ) );
check( 'SET-AC-15 fields on the Payouts tab', true, ! array_diff( array_keys( $sq_keys ), \WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['payouts'] ) );

/* Start number: refused at or below the highest issued number of the year. */
// Make sure the credit range has issued numbers this year.
Db::transaction( static function () use ( $sq_y ) { Sequence::number( 'GS', 'credit', $sq_y ); } );
$sq_h = Sequence::current( 'credit', $sq_y );
$sq_c1 = SettingsTransfer::check( array( 'credit_start' => $sq_h ), Settings::all() );
$sq_c2 = SettingsTransfer::check( array( 'credit_start' => max( 1, $sq_h - 1 ) ), Settings::all() );
$sq_c3 = SettingsTransfer::check( array( 'credit_start' => 0 ), Settings::all() );
check( 'SET-AC-15 start equal to / below the highest issued number or 0 is refused, nothing changes', array( true, true, true, array(), array(), array() ), array( isset( $sq_c1['errors']['credit_start'] ), isset( $sq_c2['errors']['credit_start'] ), isset( $sq_c3['errors']['credit_start'] ), $sq_c1['values'], $sq_c2['values'], $sq_c3['values'] ) );
check( 'SET-AC-15 the error names the highest number and the smallest allowed start', true, false !== strpos( $sq_c1['errors']['credit_start'], Sequence::format( 'GS', $sq_y, $sq_h ) ) && false !== strpos( $sq_c1['errors']['credit_start'], (string) ( $sq_h + 1 ) ) );
// The settings page reads the same check (sanitize): the stored value is kept.
$sq_err = array();
$sq_out = Settings::sanitize( array_merge( Settings::all(), array( 'credit_start' => (string) $sq_h ) ), $sq_err );
check( 'SET-AC-15 settings page: refused start keeps the stored value', array( true, 1 ), array( isset( $sq_err['credit_start'] ), $sq_out['credit_start'] ) );
Settings::sanitize( Settings::all(), $sq_err );
check( 'SET-AC-15 an unchanged start is not checked again (saving the tab works)', false, isset( $sq_err['credit_start'] ) );

/* Raising: counter moves up, skipped numbers are logged, numbering continues gapless and unique. */
$sq_audit0 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'sequence.start_raised'" );
$sq_ok     = SettingsTransfer::check( array( 'credit_start' => $sq_h + 10 ), Settings::all() );
check( 'SET-AC-15 a start above the highest issued number is accepted', array( array(), array( 'credit_start' => $sq_h + 10 ) ), array( $sq_ok['errors'], $sq_ok['values'] ) );
check( 'SET-AC-15 the check alone (preview) does not move the counter', $sq_h, Sequence::current( 'credit', $sq_y ) );
SettingsTransfer::apply( $sq_ok, 'settings.cli' );
check( 'SET-AC-15 after saving the counter continues at the start number', array( $sq_h + 9, Sequence::format( 'GS', $sq_y, $sq_h + 10 ) ), array( Sequence::current( 'credit', $sq_y ), Sequence::preview( 'credit' ) ) );
$sq_audit = $wpdb->get_row( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'sequence.start_raised' ORDER BY id DESC LIMIT 1" );
$sq_ad    = $sq_audit ? json_decode( (string) $sq_audit->details, true ) : array();
check( 'SET-AC-15 audit log records the skipped numbers (explains the gap)', array( 1, 'credit', $sq_y, $sq_h, $sq_h + 10, 9 ), array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'sequence.start_raised'" ) - $sq_audit0, isset( $sq_ad['range'] ) ? $sq_ad['range'] : null, isset( $sq_ad['year'] ) ? $sq_ad['year'] : null, isset( $sq_ad['highest'] ) ? $sq_ad['highest'] : null, isset( $sq_ad['start'] ) ? $sq_ad['start'] : null, isset( $sq_ad['skipped'] ) ? $sq_ad['skipped'] : null ) );
$sq_nums = array();
for ( $i = 0; $i < 3; $i++ ) {
	$sq_nums[] = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $sq_y ); } );
}
check( 'SET-AC-15 after the change: gapless from the start number', array( Sequence::format( 'GS', $sq_y, $sq_h + 10 ), Sequence::format( 'GS', $sq_y, $sq_h + 11 ), Sequence::format( 'GS', $sq_y, $sq_h + 12 ) ), $sq_nums );
$sq_c4 = SettingsTransfer::check( array( 'credit_start' => $sq_h + 5 ), Settings::all() );
$sq_c5 = SettingsTransfer::check( array( 'credit_start' => $sq_h + 12 ), Settings::all() );
check( 'SET-AC-15 lowering the start again is refused (also between old start and counter)', array( true, true ), array( isset( $sq_c4['errors']['credit_start'] ), isset( $sq_c5['errors']['credit_start'] ) ) );
// A rollback after the raise still keeps the range gapless.
try {
	Db::transaction( static function () use ( $sq_y ) { Sequence::number( 'GS', 'credit', $sq_y ); throw new RuntimeException( 'abort' ); } );
} catch ( RuntimeException $e ) {
	// Expected.
}
check( 'SET-AC-15 rollback after the raise consumes no number', $sq_h + 12, Sequence::current( 'credit', $sq_y ) );
// The start of one range does not touch the others.
$sq_st = Sequence::current( 'storno', $sq_y );
$sq_kg = Sequence::current( 'correction', $sq_y );
check( 'SET-AC-15 other ranges unchanged by the credit note start', array( $sq_st, $sq_kg ), array( Sequence::current( 'storno', $sq_y ), Sequence::current( 'correction', $sq_y ) ) );
// Saving the tab again with the same start does nothing (no second raise, no audit entry).
$sq_audit1 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'sequence.start_raised'" );
Settings::update( array( 'credit_start' => $sq_h + 10 ) );
check( 'SET-AC-15 unchanged start: no raise, no audit entry', array( $sq_h + 12, $sq_audit1 ), array( Sequence::current( 'credit', $sq_y ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'sequence.start_raised'" ) ) );

/* Digits. */
$sq_d1 = SettingsTransfer::check( array( 'credit_digits' => 3 ), Settings::all() );
$sq_d2 = SettingsTransfer::check( array( 'credit_digits' => 11 ), Settings::all() );
$sq_d3 = SettingsTransfer::check( array( 'credit_digits' => 8 ), Settings::all() );
check( 'SET-AC-15 digits below 4 or above 10 refused, 8 accepted', array( true, true, array( 'credit_digits' => 8 ) ), array( isset( $sq_d1['errors']['credit_digits'] ), isset( $sq_d2['errors']['credit_digits'] ), $sq_d3['values'] ) );
SettingsTransfer::apply( $sq_d3, 'settings.cli' );
$sq_n8 = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $sq_y ); } );
check( 'SET-AC-15 eight digits: next number continues the same counter', sprintf( 'GS-%d-%08d', $sq_y, $sq_h + 13 ), $sq_n8 );
Settings::update( array( 'credit_digits' => 4 ) );
$sq_n4 = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $sq_y ); } );
check( 'SET-AC-15 four digits: next number continues the same counter', sprintf( 'GS-%d-%04d', $sq_y, $sq_h + 14 ), $sq_n4 );
$sq_vals = array_map( static function ( $n ) { return (int) substr( $n, strrpos( $n, '-' ) + 1 ); }, array_merge( $sq_nums, array( $sq_n8, $sq_n4 ) ) );
check( 'SET-AC-15 across the digit changes: one counter, consecutive, never twice', range( $sq_h + 10, $sq_h + 14 ), $sq_vals );
// A counter far beyond the digits is written in full.
q( "INSERT INTO {$sq_seq} (name, year, value) VALUES ('storno', 1999, 99999)" );
check( 'SET-AC-15 a counter beyond four digits is not cut', 'ST-1999-100000', Db::transaction( static function () { return Sequence::number( 'ST', 'storno', 1999 ); } ) );
q( "DELETE FROM {$sq_seq} WHERE year = 1999" );

/* Year change: the next year starts at 1 again (fixed), the raised start applies to its own year. */
$sq_next = $sq_y + 1;
q( "DELETE FROM {$sq_seq} WHERE year = {$sq_next}" );
$sq_ny = array();
for ( $i = 0; $i < 3; $i++ ) {
	$sq_ny[] = Db::transaction( static function () use ( $sq_next ) { return Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $sq_next ); } );
}
$sq_old = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $sq_y ); } );
check( 'SET-AC-15 year change: new year restarts at 1 with the set digits, gapless', array( "GS-{$sq_next}-0001", "GS-{$sq_next}-0002", "GS-{$sq_next}-0003" ), $sq_ny );
check( 'SET-AC-15 year change: the old year continues where it was (late document)', sprintf( 'GS-%d-%04d', $sq_y, $sq_h + 15 ), $sq_old );
check( 'SET-AC-15 year change: no number used twice', 4, count( array_unique( array_merge( $sq_ny, array( $sq_old ) ) ) ) );
// A start set "next year" is checked against that year's counter only.
$sq_c6 = Sequence::raise( 'credit', $sq_next, 50 );
check( 'SET-AC-15 raise in the new year: from its own counter (3) to 50; old year untouched', array( 3, 49, $sq_h + 15 ), array( $sq_c6, Sequence::current( 'credit', $sq_next ), Sequence::current( 'credit', $sq_y ) ) );
check( 'SET-AC-15 raise never lowers a counter', array( 49, 49 ), array( Sequence::raise( 'credit', $sq_next, 10 ), Sequence::current( 'credit', $sq_next ) ) );
q( "DELETE FROM {$sq_seq} WHERE year = {$sq_next}" );

/* Concurrency on the locked counter row (the existing lock: the row stays locked until COMMIT). */
$sq_host = getenv( 'WPAC_TEST_DB_HOST' ) ?: 'localhost';
$sq_port = null;
if ( false !== strpos( $sq_host, ':' ) ) {
	list( $sq_host, $sq_port ) = explode( ':', $sq_host, 2 );
	$sq_port                   = (int) $sq_port;
}
$sq_o = new mysqli( $sq_host, getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test', $sq_port );
$sq_cur = Sequence::current( 'credit', $sq_y );
// 1. Another request holds the row (document being stored): raising and numbering wait for it.
$sq_o->query( 'START TRANSACTION' );
$sq_o->query( "UPDATE {$sq_seq} SET value = value + 1 WHERE name = 'credit' AND year = {$sq_y}" );
$wpdb->real_errors = true;
$wpdb->db->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
check( 'SET-AC-15 lock: a raise waits for the open document (gives up after the lock wait, changes nothing)', false, Sequence::raise( 'credit', $sq_y, $sq_cur + 100 ) );
$sq_blocked = null;
try {
	Db::transaction( static function () use ( $sq_y ) { Sequence::number( 'GS', 'credit', $sq_y ); } );
} catch ( Throwable $e ) {
	$sq_blocked = 'blocked';
}
check( 'SET-AC-15 lock: a second document waits for the open one (no number taken twice)', 'blocked', $sq_blocked );
// The other request rolls back: its number is free again, nothing was skipped.
$sq_o->query( 'ROLLBACK' );
$wpdb->db->query( 'SET SESSION innodb_lock_wait_timeout = 50' );
$wpdb->real_errors = false;
check( 'SET-AC-15 lock: after the rollback of the other request the counter is unchanged', $sq_cur, Sequence::current( 'credit', $sq_y ) );
// 2. Real parallel run: another session stores a document (holds the row 1.5 s), meanwhile the
//    start number is raised and a document numbered here – both wait and continue correctly.
$sq_cli = trim( (string) shell_exec( 'command -v mysql 2>/dev/null' ) );
if ( '' !== $sq_cli ) {
	$sq_cmd = static function ( $sql ) use ( $sq_cli, $sq_host, $sq_port ) {
		return escapeshellarg( $sq_cli ) . ' -h' . escapeshellarg( $sq_host ) . ( $sq_port ? ' -P' . escapeshellarg( (string) $sq_port ) : '' ) . ' -u' . escapeshellarg( getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac' ) . ' -p' . escapeshellarg( getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac' ) . ' ' . escapeshellarg( getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test' ) . ' -e ' . escapeshellarg( $sql );
	};
	$sq_log = sys_get_temp_dir() . '/set-ac-seq-' . getmypid() . '.log';
	exec( $sq_cmd( "START TRANSACTION; UPDATE {$sq_seq} SET value = value + 1 WHERE name = 'credit' AND year = {$sq_y}; SELECT SLEEP(1.5); COMMIT;" ) . ' > ' . escapeshellarg( $sq_log ) . ' 2>&1 &' );
	usleep( 500000 );
	$sq_t0     = microtime( true );
	$sq_before_raise = Sequence::raise( 'credit', $sq_y, $sq_cur + 5 );
	$sq_waited = microtime( true ) - $sq_t0;
	check( 'SET-AC-15 parallel: the raise waited for the other document and saw its number', array( true, $sq_cur + 1 ), array( $sq_waited > 0.5, $sq_before_raise ) );
	$sq_p1 = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( 'GS', 'credit', $sq_y ); } );
	check( 'SET-AC-15 parallel: next number after the raise is the start number', Sequence::format( 'GS', $sq_y, $sq_cur + 5, 4 ), $sq_p1 );
	// The other way round: a document is numbered here while another session raises the start.
	exec( $sq_cmd( "START TRANSACTION; UPDATE {$sq_seq} SET value = GREATEST(value, " . ( $sq_cur + 19 ) . ") WHERE name = 'credit' AND year = {$sq_y}; SELECT SLEEP(1.5); COMMIT;" ) . ' > ' . escapeshellarg( $sq_log ) . ' 2>&1 &' );
	usleep( 500000 );
	$sq_p2 = Db::transaction( static function () use ( $sq_y ) { return Sequence::number( 'GS', 'credit', $sq_y ); } );
	check( 'SET-AC-15 parallel: a document numbered during a raise gets the start number (no duplicate)', Sequence::format( 'GS', $sq_y, $sq_cur + 20, 4 ), $sq_p2 );
	// A raise below the counter reached meanwhile is a no-op (never lowers, never duplicates).
	exec( $sq_cmd( "START TRANSACTION; UPDATE {$sq_seq} SET value = value + 10 WHERE name = 'credit' AND year = {$sq_y}; SELECT SLEEP(1.5); COMMIT;" ) . ' > ' . escapeshellarg( $sq_log ) . ' 2>&1 &' );
	usleep( 500000 );
	Sequence::raise( 'credit', $sq_y, $sq_cur + 25 );
	check( 'SET-AC-15 parallel: a start overtaken by other documents changes nothing', $sq_cur + 30, Sequence::current( 'credit', $sq_y ) );
	sleep( 1 );
	check( 'SET-AC-15 parallel: no deadlock or error on the other side', false, false !== stripos( (string) @file_get_contents( $sq_log ), 'error' ) );
	@unlink( $sq_log );
}
$sq_o->close();

/* Documents already issued are never changed. */
check( 'SET-AC-15 documents issued before the changes keep their numbers', $sq_before, $sq_docs() );

update_option( 'wpac_settings', $sq_saved );
Settings::flush_cache();
SettingsSchema::flush();
