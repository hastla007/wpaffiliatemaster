<?php
/**
 * R8 package G8: performance, cron budgets, reports, indexes.
 * Included by run.php (shares its helpers).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Reports\Reports;

echo "R8-G8 performance, cron budgets, reports, indexes\n";
global $wpdb;
$g8_index = static function ( $table, $name ) {
	global $wpdb;
	return (bool) $wpdb->get_var( 'SHOW INDEX FROM ' . t( $table ) . " WHERE Key_name = '{$name}'" );
};

// R6-MS10: one query for the slug and its numbered variants; the first free number is used.
$g8_slugs = array();
foreach ( array( 1, 2, 3 ) as $g8_i ) {
	$g8_id      = AffiliateRepository::create( array( 'name' => 'Office G8', 'email' => "office{$g8_i}@g8.example", 'status' => 'active' ) );
	$g8_slugs[] = AffiliateRepository::find( $g8_id )->slug;
}
check( 'R6-MS10 numbered slugs for equal names', array( 'office-g8', 'office-g8-2', 'office-g8-3' ), $g8_slugs );
check( 'R6-MS10 next free slug', 'office-g8-4', AffiliateRepository::unique_slug( 'Office G8' ) );
$g8_src = file_get_contents( WPAC_DIR . 'src/Affiliates/AffiliateRepository.php' );
preg_match( '/function unique_slug.*?\n\t}\n/s', $g8_src, $g8_m );
check( 'R6-MS10 unique_slug() no longer checks candidates one by one', false, false !== strpos( $g8_m[0] ?? '', 'find_by_slug' ) );

// R5-O24 / R5-O30: partner selection without a fixed limit – search returns only the needed columns,
// entries are resolved by ID, "Name (slug) #ID", slug or e-mail.
$g8_aff  = AffiliateRepository::create( array( 'name' => 'Zebra Suche G8', 'email' => 'zebra@g8.example', 'status' => 'active' ) );
$g8_opts = AffiliateRepository::options( 'Zebra Suche', 'active', 20 );
check( 'R5-O30 partner search returns id, name, slug, status only', array( 1, array( 'id', 'name', 'slug', 'status' ) ), array( count( $g8_opts ), array_keys( (array) $g8_opts[0] ) ) );
$g8_row = AffiliateRepository::find( $g8_aff );
check(
	'R5-O24 partner entry resolved by ID, label, slug and e-mail',
	array( $g8_aff, $g8_aff, $g8_aff, $g8_aff, 0 ),
	array(
		AffiliateRepository::resolve_input( (string) $g8_aff ),
		AffiliateRepository::resolve_input( AffiliateRepository::option_label( $g8_row ) ),
		AffiliateRepository::resolve_input( $g8_row->slug ),
		AffiliateRepository::resolve_input( 'zebra@g8.example' ),
		AffiliateRepository::resolve_input( 'gibt-es-nicht-g8' ),
	)
);
// Payout requests: no fixed 200 limit (count and all rows for the privacy export).
$g8_reqs = array();
for ( $g8_i = 0; $g8_i < 205; $g8_i++ ) {
	$g8_reqs[] = '(' . (int) $g8_aff . ", 'EUR', 100, 'g8test', 0, '', NOW(), NULL, 0)";
}
q( 'INSERT INTO ' . t( 'payout_requests' ) . ' (affiliate_id, currency, amount_minor, status, payout_id, note, created_at, processed_at, processed_by) VALUES ' . implode( ',', $g8_reqs ) );
check(
	'R5-O24 payout requests: count and full list beyond 200, pages',
	array( 205, 205, 200, 5 ),
	array(
		WPAffiliateCaptain\Payouts\PayoutRequests::count( 'g8test' ),
		count( WPAffiliateCaptain\Payouts\PayoutRequests::query( 'g8test', $g8_aff, 0 ) ),
		count( WPAffiliateCaptain\Payouts\PayoutRequests::query( 'g8test' ) ),
		count( WPAffiliateCaptain\Payouts\PayoutRequests::query( 'g8test', 0, 100, 200 ) ),
	)
);
q( 'DELETE FROM ' . t( 'payout_requests' ) . " WHERE status = 'g8test'" );

// R5-W28: incremental reconciliation from a checkpoint, still detecting removed or changed bookings.
$g8_bal = AffiliateRepository::create( array( 'name' => 'Saldo G8', 'email' => 'saldo@g8.example', 'status' => 'active' ) );
Balance::post( $g8_bal, 'EUR', 'approved', 500, 0, 0, 'g8 a' );
Balance::post( $g8_bal, 'EUR', 'rejected', -500, 0, 0, 'g8 b' );
$g8_full  = Balance::verify( $g8_bal, 'EUR' );
$g8_acc   = $wpdb->get_row( 'SELECT * FROM ' . t( 'balances' ) . " WHERE affiliate_id = {$g8_bal}" );
$g8_last  = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$g8_bal}" );
check( 'R5-W28 consistent account gets a checkpoint', array( array(), $g8_last, $g8_acc->last_hash, true ), array( $g8_full, (int) $g8_acc->verified_id, $g8_acc->verified_hash, null !== $g8_acc->verified_at ) );
Balance::post( $g8_bal, 'EUR', 'approved', 300, 0, 0, 'g8 c' );
Balance::post( $g8_bal, 'EUR', 'rejected', -300, 0, 0, 'g8 d' );
check( 'R5-W28 incremental check of the new bookings', array(), Balance::verify( $g8_bal, 'EUR', true ) );
$g8_new = (int) $wpdb->get_var( 'SELECT verified_id FROM ' . t( 'balances' ) . " WHERE affiliate_id = {$g8_bal}" );
check( 'R5-W28 checkpoint moved to the newest booking', (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$g8_bal}" ), $g8_new );
$g8_first = $wpdb->get_row( 'SELECT * FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$g8_bal} ORDER BY id ASC LIMIT 1", ARRAY_A );
q( 'DELETE FROM ' . t( 'balance_entries' ) . ' WHERE id = ' . (int) $g8_first['id'] );
check( 'R5-W28 incremental check notices a removed older booking', true, count( Balance::verify( $g8_bal, 'EUR', true ) ) > 0 );
$wpdb->insert( t( 'balance_entries' ), $g8_first );
check( 'R5-W28 restored → consistent again', array(), Balance::verify( $g8_bal, 'EUR', true ) );
q( 'UPDATE ' . t( 'balance_entries' ) . " SET reference = 'manipuliert' WHERE id = {$g8_new}" );
check( 'R5-W28 changed checkpoint booking → full check reports it', true, count( Balance::verify( $g8_bal, 'EUR', true ) ) > 0 );
q( 'UPDATE ' . t( 'balance_entries' ) . " SET reference = 'g8 d' WHERE id = {$g8_new}" );
check( 'R5-W28 checkpoint booking restored', array(), Balance::verify( $g8_bal, 'EUR' ) );
// Daily pass with a time budget: continues in a separate event from the account reached.
add_filter( 'wpac_balance_check_budget', static function () { return 0.000001; } );
$GLOBALS['test_scheduled'] = array();
delete_option( Balance::CHECK_PASS );
$g8_pass = Balance::scheduled_check();
$g8_state = get_option( Balance::CHECK_PASS );
check( 'R5-W28 pass beyond the budget continues later (cursor, event)', array( null, true, true ), array( $g8_pass, is_array( $g8_state ) && $g8_state['id'] >= 0, in_array( Balance::CHECK_HOOK, $GLOBALS['test_scheduled'], true ) ) );
remove_all_filters( 'wpac_balance_check_budget' );
check( 'R5-W28 continuation finishes the pass', array( array(), false ), array( Balance::scheduled_check(), get_option( Balance::CHECK_PASS ) ) );

// R7-DB3 / R5-O6: visit retention driven by the backlog, with a time budget and a continuation event.
$g8_vt = t( 'visits' );
q( "DELETE FROM {$g8_vt}" );
for ( $g8_i = 0; $g8_i < 12; $g8_i++ ) {
	q( "INSERT INTO {$g8_vt} (affiliate_id, created_at) VALUES ({$g8_aff}, '2020-01-01 00:00:00')" );
}
q( "INSERT INTO {$g8_vt} (affiliate_id, created_at) VALUES ({$g8_aff}, NOW())" );
WPAffiliateCaptain\Core\Settings::update( array( 'visit_retention_days' => 30 ) );
add_filter( 'wpac_visit_purge_batch', static function () { return 5; } );
add_filter( 'wpac_visit_purge_budget', static function () { return 0.000001; } );
$GLOBALS['test_scheduled'] = array();
$g8_done = Cron::purge_visits();
check( 'R7-DB3 out of time: one batch deleted, continuation scheduled', array( false, 8, true ), array( $g8_done, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt}" ), in_array( Cron::VISITS_HOOK, $GLOBALS['test_scheduled'], true ) ) );
remove_all_filters( 'wpac_visit_purge_budget' );
$g8_done = Cron::purge_visits();
check( 'R7-DB3 continuation deletes the whole backlog (no daily row cap), recent visit kept', array( true, 1, false ), array( $g8_done, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt}" ), in_array( Cron::VISITS_HOOK, $GLOBALS['test_scheduled'], true ) ) );
remove_all_filters( 'wpac_visit_purge_batch' );
$g8_finished = null;
$g8_n = Cron::delete_in_batches( "DELETE FROM {$g8_vt} WHERE created_at < '2000-01-01'", 5, 0, microtime( true ) - 1, $g8_finished );
check( 'R5-O6 delete_in_batches reports whether the backlog is gone', array( 0, true ), array( $g8_n, $g8_finished ) );
// Audit clean-up walks the old rows once by ID and keeps booking-relevant entries.
$g8_at = t( 'audit' );
q( "INSERT INTO {$g8_at} (action, object_type, object_id, actor_id, details, created_at) VALUES ('g8.old', 'g8', 1, 0, '', '2001-01-01 00:00:00'), ('payout.g8', 'g8', 2, 0, '', DATE_SUB(NOW(), INTERVAL 2 YEAR)), ('g8.new', 'g8', 3, 0, '', NOW())" );
WPAffiliateCaptain\Core\Settings::update( array( 'audit_retention_days' => 365 ) );
Cron::purge_audit();
check( 'R5-O6 audit purge deletes old entries, keeps booking-relevant and recent ones', array( 'g8.new', 'payout.g8' ), $wpdb->get_col( "SELECT action FROM {$g8_at} WHERE object_type = 'g8' ORDER BY action" ) );
check( 'R5-O6 no audit cursor left after a complete run', false, get_option( Cron::AUDIT_CURSOR ) );
// A daily step that ran out of time continues in the rest of the run (after the others).
add_filter( 'wpac_daily_budget', static function () { return 0.000001; } );
add_filter( 'wpac_visit_purge_batch', static function () { return 1; } );
for ( $g8_i = 0; $g8_i < 3; $g8_i++ ) {
	q( "INSERT INTO {$g8_at} (action, object_type, object_id, actor_id, details, created_at) VALUES ('g8.old', 'g8', 9, 0, '', '2001-01-01 00:00:00')" );
}
update_option( Cron::DAILY_REST_OPTION, array( 'audit', 'jobs' ), false );
$GLOBALS['test_scheduled'] = array();
Cron::daily_rest();
check( 'R5-O6 budget used up → the rest is continued separately', array( true, true ), array( is_array( get_option( Cron::DAILY_REST_OPTION ) ), in_array( Cron::DAILY_REST, $GLOBALS['test_scheduled'], true ) ) );
remove_all_filters( 'wpac_daily_budget' );
remove_all_filters( 'wpac_visit_purge_batch' );
Cron::daily_rest();
check( 'R5-O6 the rest finishes', array( false, 0 ), array( get_option( Cron::DAILY_REST_OPTION ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_at} WHERE action = 'g8.old'" ) ) );

// R5-O29 / R5-O28 / R6-MS9: indexes.
check(
	'R5-O29 redundant indexes gone, R5-O28 visits(created_at, affiliate_id), R6-MS9 updated_at indexes',
	array( false, false, false, true, true, true ),
	array( $g8_index( 'visits', 'content_id' ), $g8_index( 'visits', 'created_at' ), $g8_index( 'attributions', 'affiliate_id' ), $g8_index( 'visits', 'created_affiliate' ), $g8_index( 'affiliates', 'updated_at' ), $g8_index( 'payout_profiles', 'updated_at' ) )
);
// The v11 migration drops the redundant indexes of an existing install and keeps closed bonus months in UTC.
q( 'ALTER TABLE ' . t( 'visits' ) . ' ADD KEY content_id (content_id), ADD KEY created_at (created_at)' );
q( 'ALTER TABLE ' . t( 'attributions' ) . ' ADD KEY affiliate_id (affiliate_id)' );
// A v10 schema also lacks the v11 columns of G8 (balance checkpoints) and G9 (sub-IDs, visit IDs).
q( 'ALTER TABLE ' . t( 'balances' ) . ' DROP COLUMN verified_id, DROP COLUMN verified_hash, DROP COLUMN verified_balance, DROP COLUMN verified_at' );
q( 'ALTER TABLE ' . t( 'visits' ) . ' DROP COLUMN sub_id' );
q( 'ALTER TABLE ' . t( 'attributions' ) . ' DROP COLUMN sub_id, DROP COLUMN visit_id' );
$g8_v10_problems = WPAffiliateCaptain\Core\Installer::verify_schema();
delete_option( TierService::LOCAL_FROM );
$g8_closed = WPAffiliateCaptain\Core\Settings::state( 'tier_month_closed' );
WPAffiliateCaptain\Core\Settings::set_state( 'tier_month_closed', '2024-05' );
update_option( 'wpac_db_version', 10 );
WPAffiliateCaptain\Core\Installer::install_schema();
check(
	'R5-O29 v11 migration drops prefix indexes; R5-O35 earlier months keep UTC',
	array( false, false, false, true, WPAC_DB_VERSION, '2024-06' ),
	array( $g8_index( 'visits', 'content_id' ), $g8_index( 'visits', 'created_at' ), $g8_index( 'attributions', 'affiliate_id' ), $g8_index( 'visits', 'created_affiliate' ), (int) get_option( 'wpac_db_version' ), get_option( TierService::LOCAL_FROM ) )
);
$g8_cols = static function ( $table ) use ( $wpdb ) { return array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . t( $table ) ) ); };
check(
	'R5-W28 / R7-AD16 v10 -> v11 adds the balance checkpoint and sub-ID/visit-ID columns; verify_schema() then reports nothing',
	array(
		array( 'missing column ' . t( 'balances' ) . '.verified_id', 'missing column ' . t( 'balances' ) . '.verified_hash', 'missing column ' . t( 'attributions' ) . '.sub_id', 'missing column ' . t( 'attributions' ) . '.visit_id', 'missing column ' . t( 'visits' ) . '.sub_id' ),
		array(),
		array(),
		array(),
		array(),
	),
	array(
		$g8_v10_problems,
		WPAffiliateCaptain\Core\Installer::verify_schema(),
		array_values( array_diff( array( 'verified_id', 'verified_hash', 'verified_balance', 'verified_at' ), $g8_cols( 'balances' ) ) ),
		array_values( array_diff( array( 'sub_id', 'visit_id' ), $g8_cols( 'attributions' ) ) ),
		array_values( array_diff( array( 'sub_id' ), $g8_cols( 'visits' ) ) ),
	)
);
WPAffiliateCaptain\Core\Settings::set_state( 'tier_month_closed', $g8_closed );
delete_option( TierService::LOCAL_FROM );

// R5-O35: local month and day boundaries (site time zone), stored times in UTC.
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz = (string) get_option( 'timezone_string' );
		return new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
	}
	$GLOBALS['g8_tz_stub'] = true;
}
if ( ! empty( $GLOBALS['g8_tz_stub'] ) ) {
	update_option( 'timezone_string', 'Europe/Berlin' );
	check(
		'R5-O35 local month in UTC (Berlin: winter and summer time)',
		array( array( '2026-10-31 23:00:00', '2026-11-30 22:59:59' ), array( '2026-06-30 22:00:00', '2026-07-31 21:59:59' ), '2026-11' ),
		array( Db::month_range( '2026-11' ), Db::month_range( '2026-07' ), Db::local_month( -1, strtotime( '2026-11-30 23:30:00 UTC' ) ) )
	);
	check( 'R5-O35 previous local month on 31 December 23:30 UTC (1 January in Berlin)', '2026-12', Db::local_month( -1, strtotime( '2026-12-31 23:30:00 UTC' ) ) );
	$g8_tier_range = TierService::range( '2026-11' );
	update_option( TierService::LOCAL_FROM, '2026-12' );
	check( 'R5-O35 bonus months: local from the switch, UTC before', array( array( '2026-10-31 23:00:00', '2026-11-30 22:59:59' ), array( '2026-11-01 00:00:00', '2026-11-30 23:59:59' ) ), array( $g8_tier_range, TierService::range( '2026-11' ) ) );
	delete_option( TierService::LOCAL_FROM );
	// A sale at 23:30 UTC on 31 October is a November sale in Berlin.
	$g8_rep = AffiliateRepository::create( array( 'name' => 'Report G8', 'email' => 'report@g8.example', 'status' => 'active' ) );
	$g8_r   = sale( 'g8-tz-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g8_rep ), array( 10000 ) );
	q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2025-10-31 23:30:00' WHERE id = " . (int) $g8_r['commission_ids'][0] );
	q( 'UPDATE ' . t( 'attributions' ) . " SET created_at = '2025-10-31 23:30:00' WHERE source_order_id = 'g8-tz-1'" );
	$g8_nov = Reports::partners( '2025-11-01', '2025-11-30', array( $g8_rep ) );
	$g8_oct = Reports::partners( '2025-10-01', '2025-10-31', array( $g8_rep ) );
	check( 'R5-O35 report months are local months', array( 1, 0 ), array( count( $g8_nov ), count( $g8_oct ) ) );
	update_option( 'timezone_string', '' );
}

// R5-W29: monthly mail batches report only their partners (keyset by partner ID).
$g8_mm = '2025-02';
$g8_mp = array();
foreach ( array( 1, 2, 3 ) as $g8_i ) {
	$g8_mp[ $g8_i ] = AffiliateRepository::create( array( 'name' => "Mail G8 {$g8_i}", 'email' => "mail{$g8_i}@g8.example", 'status' => 'active' ) );
	$g8_r           = sale( 'g8-mm-' . $g8_i, WPAffiliateCaptain\Codes\CodeRepository::main_code( $g8_mp[ $g8_i ] ), array( 5000 ) );
	q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$g8_mm}-10 10:00:00' WHERE id = " . (int) $g8_r['commission_ids'][0] );
	q( 'UPDATE ' . t( 'attributions' ) . " SET created_at = '{$g8_mm}-10 10:00:00' WHERE source_order_id = 'g8-mm-{$g8_i}'" );
}
check( 'R5-W29 partners of a period in ID order, after a cursor', array( $g8_mp[2], $g8_mp[3] ), Reports::active_partners( "{$g8_mm}-01", "{$g8_mm}-28", $g8_mp[1], 2 ) );
check( 'R5-W29 report for a list of partners', array( $g8_mp[1], $g8_mp[3] ), array_map( 'intval', wp_list_pluck( Reports::partners( "{$g8_mm}-01", "{$g8_mm}-28", array( $g8_mp[1], $g8_mp[3] ) ), 'affiliate_id' ) ) );
WPAffiliateCaptain\Core\Settings::update( array( 'report_partners' => 1 ) );
add_filter( 'wpac_monthly_mail_batch_size', static function () { return 1; } );
update_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS, array( 'month' => $g8_mm, 'after' => 0, 'touched' => time() ), false );
$GLOBALS['test_mails']     = array();
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Reports\MonthlyMail::batch();
$g8_prog = get_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS );
check( 'R5-W29 one partner per batch, position by partner ID, next batch scheduled', array( 1, $g8_mp[1], true ), array( count( $GLOBALS['test_mails'] ), (int) $g8_prog['after'], in_array( WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, $GLOBALS['test_scheduled'], true ) ) );
// Progress of an older version (offset) continues after the partner at that position.
update_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS, array( 'month' => $g8_mm, 'offset' => 2, 'touched' => time() ), false );
$GLOBALS['test_mails'] = array();
WPAffiliateCaptain\Reports\MonthlyMail::batch();
check( 'R5-W29 old offset progress continues with the third partner', array( 1, $g8_mp[3] ), array( count( $GLOBALS['test_mails'] ), (int) get_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS )['after'] ) );
remove_all_filters( 'wpac_monthly_mail_batch_size' );
delete_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS );

// R5-W32 / R5-O7: closing a month with a time limit stops after a partner and continues there.
$g8_tm = '2023-03';
$g8_tp = array();
foreach ( array( 1, 2 ) as $g8_i ) {
	$g8_tp[ $g8_i ] = AffiliateRepository::create( array( 'name' => "Tier G8 {$g8_i}", 'email' => "tier{$g8_i}@g8.example", 'status' => 'active' ) );
	$g8_r           = sale( 'g8-t-' . $g8_i, WPAffiliateCaptain\Codes\CodeRepository::main_code( $g8_tp[ $g8_i ] ), array( 150000 ) );
	q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$g8_tm}-10 10:00:00' WHERE id = " . (int) $g8_r['commission_ids'][0] );
	Ledger::transition( (int) $g8_r['commission_ids'][0], 'approved' );
}
// An older order of the same partner (sold before the month) is not part of the month.
$g8_r = sale( 'g8-t-old', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g8_tp[1] ), array( 900000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2023-01-10 10:00:00' WHERE id = " . (int) $g8_r['commission_ids'][0] );
Ledger::transition( (int) $g8_r['commission_ids'][0], 'approved' );
$GLOBALS['test_scheduled'] = array();
$g8_first = TierService::close_month( $g8_tm, microtime( true ) - 1 );
$g8_cur   = get_option( TierService::CURSOR );
check( 'R5-W32 month closing stops at the time limit after one partner (cursor, continuation event)', array( 1, $g8_tm, $g8_tp[1], true ), array( $g8_first, $g8_cur['month'] ?? '', (int) ( $g8_cur['after'] ?? 0 ), in_array( TierService::CONTINUE_HOOK, $GLOBALS['test_scheduled'], true ) ) );
check( 'R5-W32 the continuation books the second partner only', array( 1, false ), array( TierService::close_month( $g8_tm ), get_option( TierService::CURSOR ) ) );
$g8_base = (int) $wpdb->get_var( 'SELECT base_minor FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$g8_tp[1]} AND kind = 'bonus'" );
check( 'R5-W32 bonus counts only orders sold in the month', 150000, $g8_base );
// Recalculation beyond the budget: the rest follows in a continuation event, not the next day.
$g8_closed = WPAffiliateCaptain\Core\Settings::state( 'tier_month_closed' );
update_option( TierService::RECALC_PENDING, array( $g8_tm ), false );
$GLOBALS['test_scheduled'] = array();
TierService::recalculate_closed_months( 0.000001 );
check( 'R5-O7 recalculation beyond the budget schedules its continuation', true, in_array( TierService::CONTINUE_HOOK, $GLOBALS['test_scheduled'], true ) );
TierService::recalculate_closed_months( 0 );
check( 'R5-O7 continuation clears the remembered months', array( false, false ), array( get_option( TierService::RECALC_PENDING ), get_option( TierService::CURSOR ) ) );
WPAffiliateCaptain\Core\Settings::set_state( 'tier_month_closed', $g8_closed );

// R6-MS9: the blocked-payouts cache keeps only IDs and codes; the reasons are worded per user.
$g8_ref = new ReflectionMethod( WPAffiliateCaptain\Admin\Admin::class, 'blocked_state' );
$g8_ref->setAccessible( true );
set_transient( WPAffiliateCaptain\Admin\Admin::BLOCKED_CACHE, array( 'state' => $g8_ref->invoke( null ), 'ids' => array( array( $g8_aff, 'below_minimum' ) ) ), 3600 );
$g8_blocked = WPAffiliateCaptain\Admin\Admin::blocked_payouts();
check( 'R6-MS9 cached codes are put into words when shown', array( array( $g8_aff, 'Zebra Suche G8', 'Below minimum – carried forward', 'below_minimum' ) ), $g8_blocked );
delete_transient( WPAffiliateCaptain\Admin\Admin::BLOCKED_CACHE );
WPAffiliateCaptain\Admin\Admin::blocked_payouts();
$g8_cache = get_transient( WPAffiliateCaptain\Admin\Admin::BLOCKED_CACHE );
check( 'R6-MS9 rebuilt cache holds no translated text', array( array( 'state', 'ids' ), false ), array( array_keys( (array) $g8_cache ), false !== strpos( wp_json_encode( $g8_cache ), 'carried forward' ) ) );

// R5-O4: pages read the cached migration check instead of decrypting all IBANs on every view.
$g8_ms = WPAffiliateCaptain\Core\Monitor::check_migrations();
update_option( WPAffiliateCaptain\Core\Monitor::MIGRATION_STATUS, array( 'legacy' => 7, 'broken' => 0, 'broken_ids' => array(), 'misplaced' => 0, 'checked_at' => time() ), false );
check( 'R5-O4 migration status served from the daily check', 7, WPAffiliateCaptain\Core\Monitor::migration_status()['legacy'] );
update_option( WPAffiliateCaptain\Core\Monitor::MIGRATION_STATUS, array( 'legacy' => 7, 'broken' => 0, 'broken_ids' => array(), 'misplaced' => 0, 'checked_at' => time() - 2 * DAY_IN_SECONDS ), false );
check( 'R5-O4 outdated status is checked again', array( 0, true ), array( WPAffiliateCaptain\Core\Monitor::migration_status()['legacy'], is_array( get_option( WPAffiliateCaptain\Core\Monitor::MIGRATION_STATUS ) ) ) );

// R5-O28: unfiltered commission totals and the dashboard report are cached until the data change.
$g8_tot = Ledger::totals();
check( 'R5-O28 unfiltered totals cached', $g8_tot, Ledger::totals() );
$g8_rows = Reports::cached( 'partners', "{$g8_mm}-01", "{$g8_mm}-28" );
check( 'R5-O28 cached report equals the report', Reports::partners( "{$g8_mm}-01", "{$g8_mm}-28" ), $g8_rows );
$g8_r = sale( 'g8-mm-4', WPAffiliateCaptain\Codes\CodeRepository::main_code( $g8_mp[1] ), array( 1000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$g8_mm}-11 10:00:00' WHERE id = " . (int) $g8_r['commission_ids'][0] );
check( 'R5-O28 a new commission shows at once (new fingerprint)', Reports::partners( "{$g8_mm}-01", "{$g8_mm}-28" ), Reports::cached( 'partners', "{$g8_mm}-01", "{$g8_mm}-28" ) );
