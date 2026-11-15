<?php
/**
 * Audit round 14, fix agent g8: daily aggregation of the link visits (R14-D9) – table
 * visits_daily (schema 15), live upsert on each logged visit, resumable backfill with fallback to
 * the raw visits, equal counts in several time zones (:30 offsets, DST changes), erasure,
 * retention, uninstall lists, paged campaign statistics. Included from run.php before
 * r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Reports\VisitsDaily;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 g8 daily visit aggregate (visits_daily)\n";
global $wpdb;

$g8_root     = dirname( __DIR__, 2 );
$g8_src      = static function ( $file ) use ( $g8_root ) {
	return (string) file_get_contents( $g8_root . '/' . $file );
};
$g8_vt       = t( 'visits' );
$g8_dt       = t( 'visits_daily' );
$g8_settings = get_option( 'wpac_settings' );
$g8_addr     = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$g8_tz       = array_key_exists( 'timezone_string', $GLOBALS['test_options'] ) ? $GLOBALS['test_options']['timezone_string'] : null;
$g8_set      = static function ( array $s ) {
	$cur = get_option( 'wpac_settings' );
	update_option( 'wpac_settings', array_merge( is_array( $cur ) ? $cur : array(), $s ) );
	Settings::flush_cache();
};
$g8_reset = static function () use ( $wpdb, $g8_dt ) {
	foreach ( VisitsDaily::OPTIONS as $o ) {
		delete_option( $o );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", VisitsDaily::UPTO ) );
	q( "TRUNCATE TABLE {$g8_dt}" );
};
$g8_upto = static function () use ( $wpdb ) {
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", VisitsDaily::UPTO ) );
	return null === $v ? -1 : (int) $v;
};
$g8_sum = static function ( $where = '1=1' ) use ( $wpdb, $g8_dt ) {
	return (int) $wpdb->get_var( "SELECT COALESCE(SUM(visits), 0) FROM {$g8_dt} WHERE {$where}" );
};
// Queries of a block of code (the "query" filter of Test_WPDB).
$g8_log = array();
add_filter(
	'query',
	static function ( $sql ) use ( &$g8_log ) {
		if ( is_array( $g8_log ) && isset( $g8_log['on'] ) ) {
			$g8_log['q'][] = (string) $sql;
		}
		return $sql;
	}
);

/* Schema 15. ------------------------------------------------------------------------------- */
$g8_keys = static function () use ( $wpdb, $g8_dt ) {
	$out = array();
	foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$g8_dt}" ) as $r ) {
		$out[ $r->Key_name ][ (int) $r->Seq_in_index ] = strtolower( $r->Column_name );
		$out[ $r->Key_name ]['unique'] = ! (int) $r->Non_unique;
	}
	foreach ( $out as &$k ) {
		ksort( $k, SORT_STRING );
	}
	unset( $k );
	return $out;
};
$g8_k = $g8_keys();
check(
	'R14-D9 schema 15: visits_daily with unique (affiliate_id, day, campaign, sub_id) and a day index; listed in Db::TABLES',
	array( 15, 15, true, array( 1 => 'affiliate_id', 2 => 'day', 3 => 'campaign', 4 => 'sub_id', 'unique' => true ), array( 1 => 'day', 'unique' => false ), 'date' ),
	array( Installer::DB_VERSION, WPAC_DB_VERSION, in_array( 'visits_daily', Db::TABLES, true ), isset( $g8_k['affiliate_day'] ) ? $g8_k['affiliate_day'] : null, isset( $g8_k['day'] ) ? $g8_k['day'] : null, strtolower( (string) $wpdb->get_var( "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$g8_dt}' AND COLUMN_NAME = 'day'" ) ) )
);
q( "DROP TABLE {$g8_dt}" );
check( 'R14-D9 verify_schema reports a missing visits_daily table', array( 'missing table ' . $g8_dt ), Installer::verify_schema() );
update_option( Installer::DB_OPTION, 14 );
$GLOBALS['test_scheduled'] = array();
Installer::install_schema();
check( 'R14-D9 migration 14 → 15 creates the table and schedules the backfill', array( 15, strtolower( $g8_dt ), array(), true ), array( (int) get_option( Installer::DB_OPTION ), strtolower( (string) $wpdb->get_var( "SHOW TABLES LIKE '{$g8_dt}'" ) ), // lower_case_table_names=1 returns lower case.
 Installer::verify_schema(), in_array( VisitsDaily::HOOK, $GLOBALS['test_scheduled'], true ) ) );
check( 'R14-D9 visits_daily is not a financial table (dropped on uninstall/site deletion even when booking records are kept)', false, in_array( 'visits_daily', Installer::FINANCIAL_TABLES, true ) );

/* Test data: two partners, visits every 37 minutes over windows with DST changes, plus visits
   one second before and at local midnight of every day in every tested time zone. ---------- */
$g8_reset();
$g8_a = AffiliateRepository::create( array( 'name' => 'G8 Partner A', 'email' => 'g8-a@example.de', 'status' => 'active' ) );
$g8_b = AffiliateRepository::create( array( 'name' => 'G8 Partner B', 'email' => 'g8-b@example.de', 'status' => 'active' ) );
$g8_zones   = array( 'UTC', 'Europe/Berlin', 'Asia/Kolkata', 'America/St_Johns', 'Australia/Adelaide' );
$g8_windows = array( array( '2026-03-25', '2026-04-01' ), array( '2026-10-22', '2026-11-04' ) );
$g8_camps   = array( '', 'summer', 'creative-1-v1' );
$g8_subs    = array( '', 'yt', 'ig' );
$g8_values  = array();
$g8_i       = 0;
$g8_add     = static function ( $ts ) use ( &$g8_values, &$g8_i, $g8_a, $g8_b, $g8_camps, $g8_subs, $wpdb ) {
	++$g8_i;
	$g8_values[] = $wpdb->prepare( '(%d, %s, %s, %s)', 0 === $g8_i % 3 ? $g8_b : $g8_a, $g8_camps[ $g8_i % 3 ], $g8_subs[ (int) ( $g8_i / 3 ) % 3 ], gmdate( 'Y-m-d H:i:s', $ts ) );
};
$g8_days = array();
foreach ( $g8_windows as $w ) {
	for ( $ts = strtotime( $w[0] . ' 00:00:00 UTC' ) - 86400; $ts < strtotime( $w[1] . ' 00:00:00 UTC' ) + 2 * 86400; $ts += 37 * 60 ) {
		$g8_add( $ts );
	}
	for ( $d = strtotime( $w[0] . ' 12:00:00 UTC' ); $d <= strtotime( $w[1] . ' 12:00:00 UTC' ); $d += 86400 ) {
		$g8_days[] = gmdate( 'Y-m-d', $d );
		foreach ( $g8_zones as $z ) {
			$mid = ( new DateTimeImmutable( gmdate( 'Y-m-d', $d ) . ' 00:00:00', new DateTimeZone( $z ) ) )->getTimestamp();
			$g8_add( $mid - 1 );
			$g8_add( $mid );
		}
	}
}
foreach ( array_chunk( $g8_values, 400 ) as $chunk ) {
	q( "INSERT INTO {$g8_vt} (affiliate_id, campaign, sub_id, created_at) VALUES " . implode( ',', $chunk ) );
}
$g8_ranges = array( array( '2026-03-25', '2026-04-01' ), array( '2026-10-22', '2026-11-04' ), array( '2026-03-29', '2026-03-29' ), array( '2026-10-25', '2026-11-01' ) );
$g8_fig    = static function () use ( $g8_a, $g8_b, $g8_days, $g8_ranges ) {
	$out = array();
	foreach ( $g8_days as $d ) {
		$out[ 'summary ' . $d ] = array( Reports::partner_summary( $g8_a, $d, $d )['visits'], Reports::partner_summary( $g8_b, $d, $d )['visits'] );
	}
	foreach ( $g8_ranges as $r ) {
		$c = array();
		foreach ( Reports::partner_campaigns( $g8_a, $r[0], $r[1] ) as $row ) {
			$c[ $row['campaign'] . '|' . $row['sub_id'] ] = $row['visits'];
		}
		ksort( $c );
		$p = array();
		foreach ( Reports::partners( $r[0], $r[1], array( $g8_a, $g8_b ) ) as $row ) {
			$p[ $row['affiliate_id'] ] = $row['visits'];
		}
		ksort( $p );
		$dim = array();
		foreach ( Reports::by_dimension( 'campaign', $r[0], $r[1] ) as $row ) {
			if ( in_array( $row['affiliate_id'], array( $g8_a, $g8_b ), true ) ) {
				$dim[ $row['affiliate_id'] . '|' . $row['key'] ] = array( $row['visits'], $row['partners'] );
			}
		}
		ksort( $dim );
		$out[ 'range ' . $r[0] ] = array( $c, $p, $dim, Reports::active_partners( $r[0], $r[1], $g8_a - 1, 2 ) );
	}
	return $out;
};

/* Equal counts from the aggregate and from the raw visits in several time zones. ------------ */
foreach ( $g8_zones as $g8_zone ) {
	update_option( 'timezone_string', $g8_zone );
	$GLOBALS['test_visits_daily'] = false;
	$g8_raw  = $g8_fig();
	$g8_done = VisitsDaily::backfill( 0 );
	$GLOBALS['test_visits_daily'] = true;
	$g8_log = array( 'on' => 1, 'q' => array() );
	Reports::partner_summary( $g8_a, '2026-03-29', '2026-03-29' );
	$g8_reads = count( preg_grep( '/FROM ' . preg_quote( $g8_dt, '/' ) . ' WHERE affiliate_id/', $g8_log['q'] ) );
	$g8_log   = array();
	$g8_agg   = $g8_fig();
	check(
		"R14-D9 backfill complete in {$g8_zone} (rebuilt after the time zone change), reports read the aggregate, every visit counted once",
		array( true, true, $g8_zone, 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt}" ) ),
		array( $g8_done, VisitsDaily::ready(), get_option( VisitsDaily::TZ ), $g8_reads, $g8_sum() )
	);
	check( "R14-D9 counts from the aggregate == counts from the raw visits ({$g8_zone}: per local day, campaigns, partners, campaign report, active partners)", $g8_raw, $g8_agg );
}
check( 'R14-D9 test data reaches the local day boundaries (sanity: visits on 2026-03-29 in Adelaide)', true, array_sum( $g8_raw['summary 2026-03-29'] ) > 30 );

/* Live writes: +1 per logged visit above the backfill limit, through the tracker. ------------ */
update_option( 'timezone_string', 'Europe/Berlin' );
VisitsDaily::backfill( 0 );
$g8_now = Db::now();
$wpdb->insert( $g8_vt, array( 'affiliate_id' => $g8_a, 'campaign' => 'live', 'sub_id' => 's1', 'created_at' => $g8_now ) );
$g8_live_id = (int) $wpdb->insert_id;
VisitsDaily::record( $g8_live_id, $g8_a, 'live', 's1', $g8_now );
VisitsDaily::record( 1, $g8_a, 'below-limit', '', $g8_now );
check(
	'R14-D9 live upsert for a visit above the backfill limit (local day), none for a visit the backfill covers',
	array( 1, 0, Reports::local_time( $g8_now, 'Y-m-d' ) ),
	array( $g8_sum( "campaign = 'live'" ), $g8_sum( "campaign = 'below-limit'" ), (string) $wpdb->get_var( "SELECT day FROM {$g8_dt} WHERE campaign = 'live'" ) )
);
$g8_code = CodeRepository::main_code( $g8_b );
$g8_set( array( 'visit_log_percent' => 100 ) );
$g8_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/140.0';
$_SERVER['REMOTE_ADDR'] = '198.51.100.181';
Tracker::capture_code( $g8_code, 'g8-tracked', 0, array( 'sub_id' => 'tk' ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.182';
Tracker::capture_code( $g8_code, 'g8-tracked', 0, array( 'sub_id' => 'tk' ) );
$g8_set( array( 'visit_log_percent' => 0 ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.183';
Tracker::capture_code( $g8_code, 'g8-unlogged', 0, array( 'sub_id' => 'tk' ) );
$g8_set( array( 'visit_log_percent' => 100 ) );
if ( null === $g8_ua ) {
	unset( $_SERVER['HTTP_USER_AGENT'] );
} else {
	$_SERVER['HTTP_USER_AGENT'] = $g8_ua;
}
check(
	'R14-D9 tracker: each logged visit adds 1 (same as the raw log); a visit the sampling leaves out adds nothing',
	array( 2, 2, 0, 0 ),
	array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt} WHERE campaign = 'g8-tracked'" ), $g8_sum( "campaign = 'g8-tracked' AND sub_id = 'tk' AND affiliate_id = " . (int) $g8_b ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt} WHERE campaign = 'g8-unlogged'" ), $g8_sum( "campaign = 'g8-unlogged'" ) )
);
$g8_today = Reports::local_time( $g8_now, 'Y-m-d' );
$GLOBALS['test_visits_daily'] = false;
$g8_raw_today = array( Reports::partner_summary( $g8_a, $g8_today, $g8_today )['visits'], Reports::partner_summary( $g8_b, $g8_today, $g8_today )['visits'] );
$GLOBALS['test_visits_daily'] = true;
check( 'R14-D9 today after live writes: aggregate == raw', $g8_raw_today, array( Reports::partner_summary( $g8_a, $g8_today, $g8_today )['visits'], Reports::partner_summary( $g8_b, $g8_today, $g8_today )['visits'] ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", VisitsDaily::UPTO ) );
VisitsDaily::record( $g8_live_id + 1000, $g8_a, 'unarmed', '', $g8_now );
check( 'R14-D9 no live write before the backfill was started (no limit row)', 0, $g8_sum( "campaign = 'unarmed'" ) );

/* Resumable backfill; fallback to the raw visits until it is complete. ---------------------- */
$g8_reset();
// A gap of deleted visits in the middle (retention, erasure): the backfill jumps over it.
$g8_min = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$g8_vt} WHERE affiliate_id IN (%d, %d)", $g8_a, $g8_b ) );
q( "DELETE FROM {$g8_vt} WHERE id > " . ( $g8_min + 150 ) . ' AND id <= ' . ( $g8_min + 450 ) );
$g8_batch = static function () {
	return 100;
};
add_filter( 'wpac_visits_daily_batch', $g8_batch );
$GLOBALS['test_scheduled'] = array();
$g8_first  = VisitsDaily::backfill( 0.000001 );
$g8_cursor = (int) get_option( VisitsDaily::CURSOR );
$g8_limit  = $g8_upto();
check(
	'R14-D9 backfill stops after its time budget: cursor saved, not complete, continued by a single event',
	array( false, true, '', false, true ),
	array( $g8_first, $g8_cursor > 0 && $g8_cursor < $g8_limit, (string) get_option( VisitsDaily::DONE, '' ), VisitsDaily::ready(), in_array( VisitsDaily::HOOK, $GLOBALS['test_scheduled'], true ) )
);
list( $g8_s, $g8_e ) = Db::local_range( '2026-10-25', '2026-10-25' );
$g8_rawn = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$g8_vt} WHERE affiliate_id = %d AND created_at BETWEEN %s AND %s", $g8_a, $g8_s, $g8_e ) );
check(
	'R14-D9 fallback: before the backfill is complete the reports count the raw visits (the partial aggregate has fewer)',
	array( $g8_rawn, true ),
	array( Reports::partner_summary( $g8_a, '2026-10-25', '2026-10-25' )['visits'], $g8_sum( "affiliate_id = {$g8_a} AND day = '2026-10-25'" ) < $g8_rawn )
);
$g8_held = Db::lock( VisitsDaily::LOCK, 300 );
check( 'R14-D9 a second run at the same time does nothing (lock)', array( false, $g8_cursor ), array( VisitsDaily::backfill( 0 ), (int) get_option( VisitsDaily::CURSOR ) ) );
Db::unlock( VisitsDaily::LOCK, $g8_held );
$g8_runs = 1;
while ( ! VisitsDaily::backfill( 0.000001 ) && $g8_runs < 2000 ) {
	++$g8_runs;
}
remove_all_filters( 'wpac_visits_daily_batch' );
$GLOBALS['test_visits_daily'] = false;
$g8_raw = $g8_fig();
$GLOBALS['test_visits_daily'] = true;
check(
	'R14-D9 resumed runs complete the backfill: every remaining visit counted exactly once, counts == raw',
	array( true, true, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt}" ), $g8_raw ),
	array( $g8_runs > 3, VisitsDaily::ready(), $g8_sum(), $g8_fig() )
);
check( 'R14-D9 a further run changes nothing', array( true, $g8_sum() ), array( VisitsDaily::backfill( 0 ), $g8_sum() ) );
check( 'R14-D9 hourly maintenance is a no-op once complete (no visit read)', 0, ( static function () use ( &$g8_log, $g8_vt ) {
	$g8_log = array( 'on' => 1, 'q' => array() );
	VisitsDaily::maintain();
	$n      = count( preg_grep( '/FROM ' . preg_quote( $g8_vt, '/' ) . ' WHERE id/', $g8_log['q'] ) );
	$g8_log = array();
	return $n;
} )() );

/* Paged campaign statistics of the partner area (25 rows, cached per page). ----------------- */
$g8_all  = Reports::partner_campaigns( $g8_a, '2026-03-25', '2026-04-01' );
$g8_p1   = Reports::partner_campaigns_page( $g8_a, '2026-03-25', '2026-04-01', 1, 4 );
$g8_p2   = Reports::partner_campaigns_page( $g8_a, '2026-03-25', '2026-04-01', 2, 4 );
$g8_p3   = Reports::partner_campaigns_page( $g8_a, '2026-03-25', '2026-04-01', 3, 4 );
check(
	'R14-D9 campaign statistics in pages: slices of the full list, total of all rows',
	array( count( $g8_all ), $g8_all, 4, true ),
	array( $g8_p1['total'], array_merge( $g8_p1['rows'], $g8_p2['rows'], $g8_p3['rows'] ), count( $g8_p1['rows'] ), count( $g8_all ) > 4 && count( $g8_all ) <= 12 )
);
check(
	'R14-D9 the partner area pages the campaign table (25 rows, existing pager)',
	array( 2, 1 ),
	array( substr_count( $g8_src( 'src/Portal/Portal.php' ), "Reports::partner_campaigns_page( (int) \$aff->id, \$period['from'], \$period['to'], \$page, 25 )" ), substr_count( $g8_src( 'src/Portal/Portal.php' ), "self::pager( \$page, \$total, 25 );\n\t}\n\n\t/**\n\t * A URL the link generator accepts" ) )
);

/* Erasure and retention. --------------------------------------------------------------------- */
$g8_b_rows = $g8_sum( "affiliate_id = {$g8_b}" );
check(
	'R14-D9 partner erasure deletes the partner\'s aggregate rows (other partners kept)',
	array( true, 0, 0, $g8_b_rows ),
	array( Privacy::delete_partner_visits( $g8_a, 0 ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_dt} WHERE affiliate_id = {$g8_a}" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt} WHERE affiliate_id = {$g8_a}" ), $g8_sum( "affiliate_id = {$g8_b}" ) )
);
$g8_reset();
update_option( Privacy::VISITS_ERASE_OPTION, array( $g8_b ) );
VisitsDaily::backfill( 0 );
check( 'R14-D9 the backfill leaves out partners whose visits are being erased', array( 0, true ), array( $g8_sum( "affiliate_id = {$g8_b}" ), $g8_sum() > 0 || ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt} WHERE affiliate_id <> {$g8_b}" ) ) );
delete_option( Privacy::VISITS_ERASE_OPTION );
$g8_reset();
VisitsDaily::backfill( 0 );
$g8_keep_days = (int) ceil( ( time() - strtotime( '2026-06-01 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
if ( $g8_keep_days > 0 ) {
	$g8_set( array( 'visit_retention_days' => $g8_keep_days ) );
	$g8_oct = $g8_sum( "affiliate_id = {$g8_b} AND day >= '2026-10-01'" );
	$g8_cut = Reports::local_time( Db::now( time() - $g8_keep_days * DAY_IN_SECONDS ), 'Y-m-d' );
	check(
		'R14-D9 visit retention applies to the aggregate (days before the local cutoff day go, later days stay)',
		array( true, 0, 0, $g8_oct, true ),
		array( Cron::purge_visits(), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_vt} WHERE affiliate_id = {$g8_b} AND created_at < '2026-05-01'" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$g8_dt} WHERE day < '{$g8_cut}'" ), $g8_sum( "affiliate_id = {$g8_b} AND day >= '2026-10-01'" ), $g8_oct > 0 )
	);
}

/* Lists kept in sync: uninstall, deactivation, hooks. ---------------------------------------- */
$g8_un = $g8_src( 'uninstall.php' );
preg_match( '/function wpac_uninstall_tables\(\)\s*\{\s*return array\((.*?)\);/s', $g8_un, $g8_t );
preg_match( '/function wpac_uninstall_financial_tables\(\)\s*\{\s*return array\((.*?)\);/s', $g8_un, $g8_f );
preg_match( '/function wpac_uninstall_hooks\(\)\s*\{\s*return array\((.*?)\);/s', $g8_un, $g8_h );
$g8_missing_opts = array();
foreach ( VisitsDaily::OPTIONS as $o ) {
	if ( false === strpos( $g8_un, "'" . $o . "'" ) ) {
		$g8_missing_opts[] = $o;
	}
}
check(
	'R14-D9 uninstall drops visits_daily (also when booking records are kept, and for deleted network sites), removes its event and options; deactivation unschedules it',
	array( true, false, true, array(), true, true ),
	array( false !== strpos( $g8_t[1] ?? '', "'visits_daily'" ), false !== strpos( $g8_f[1] ?? '', "'visits_daily'" ), false !== strpos( $g8_h[1] ?? '', "'" . VisitsDaily::HOOK . "'" ), $g8_missing_opts, false !== strpos( $g8_src( 'src/Core/Cron.php' ), 'VisitsDaily::HOOK ) as $hook' ), false !== strpos( $g8_src( 'src/Core/Cron.php' ), "'hourly.visits_daily'" ) )
);

/* Clean up: later tests write visits straight into the table and read the raw visits. ------- */
$g8_reset();
$GLOBALS['test_visits_daily'] = false;
update_option( 'wpac_settings', $g8_settings );
Settings::flush_cache();
if ( null === $g8_tz ) {
	unset( $GLOBALS['test_options']['timezone_string'] );
} else {
	update_option( 'timezone_string', $g8_tz );
}
if ( null === $g8_addr ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $g8_addr;
}
