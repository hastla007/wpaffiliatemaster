<?php
/**
 * Agent c16 (0.16.0): (1) no VALUES(col) in INSERT … ON DUPLICATE KEY UPDATE (deprecated in
 * MySQL 8.0.20+, a warning on MySQL 9.7) – the daily visit aggregate uses a derived table instead,
 * checked on the generated SQL and with SHOW WARNINGS; (2) several partner programs (contract
 * 1.5.0, variant A): monthly bonus due date per program hold period, minimum payout per program in
 * the admin, program argument of the REST report route, "active partners" of the selected program,
 * cookie lifetime per program in the privacy texts – and no change without a wpac_setting callback.
 * Included from run.php before r8-render.php (helpers check(), t(), q(), sale()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Reports\VisitsDaily;
use WPAffiliateCaptain\Rest\RestApi;

echo "R14 c16: no VALUES() in upserts (MySQL 9.7), several partner programs: remaining spots\n";
global $wpdb;

unset( $GLOBALS['test_caps'] );
foreach ( array( 'wpac_setting', 'wpac_affiliate_program', 'wpac_program_groups', 'wpac_report_programs' ) as $c16_hook ) {
	remove_all_filters( $c16_hook );
}

/* ---------------------------------------------------------------------------------------------
 * 1. Upserts without VALUES(): generated SQL and server warnings.
 * ------------------------------------------------------------------------------------------- */
$c16_sql = array();
add_filter(
	'query',
	static function ( $sql ) use ( &$c16_sql ) {
		if ( is_string( $sql ) && preg_match( '/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $sql ) ) {
			$c16_sql[] = $sql;
		}
		return $sql;
	}
);
$c16_add = new ReflectionMethod( VisitsDaily::class, 'add' );
$c16_add->setAccessible( true );
$c16_vd  = VisitsDaily::table();
$c16_fa  = 99160001; // No real partner: rows are removed again below.
$c16_day = gmdate( 'Y-m-d' );
// Two rows with the same key in one statement and a second statement: the counts add up.
$c16_add->invoke( null, array( array( $c16_fa, $c16_day, 'c16', 's', 2 ), array( $c16_fa, $c16_day, 'c16', 's', 3 ), array( $c16_fa, $c16_day, "c16 'quote'", '', 1 ) ) );
$c16_add->invoke( null, array( array( $c16_fa, $c16_day, 'c16', 's', 4 ) ) );
$c16_visits = static function ( $campaign ) use ( $wpdb, $c16_vd, $c16_fa ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visits), 0) FROM {$c16_vd} WHERE affiliate_id = %d AND campaign = %s", $c16_fa, $campaign ) );
};
check( 'C16-1 aggregate upsert adds the counts (same key twice in one statement, then again)', array( 9, 1, '' ), array( $c16_visits( 'c16' ), $c16_visits( "c16 'quote'" ), (string) $wpdb->last_error ) );
// The re-arming of the backfill (reset): its upsert sets the limit to MAX(id) of the visits.
$c16_reset = new ReflectionMethod( VisitsDaily::class, 'reset' );
$c16_reset->setAccessible( true );
$c16_keep  = array();
foreach ( VisitsDaily::OPTIONS as $c16_o ) {
	$c16_keep[ $c16_o ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $c16_o ) );
}
$c16_reset->invoke( null, VisitsDaily::tz() );
$c16_upto = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", VisitsDaily::UPTO ) );
check( 'C16-1 reset arms the backfill with MAX(id) of the visits', (string) (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . t( 'visits' ) ), (string) $c16_upto );
// Bring the aggregate back (the later files read it).
$c16_done = VisitsDaily::backfill( 0 );
check( 'C16-1 backfill after the reset completes (aggregate rebuilt with the new upsert)', array( true, VisitsDaily::tz() ), array( $c16_done, (string) get_option( VisitsDaily::DONE, '' ) ) );
$c16_with_values = array_filter(
	$c16_sql,
	static function ( $sql ) {
		$pos = stripos( $sql, 'ON DUPLICATE KEY UPDATE' );
		return false !== $pos && preg_match( '/\bVALUES\s*\(/i', substr( $sql, $pos ) );
	}
);
check( 'C16-1 generated upserts (aggregate, reset) contain no VALUES() after ON DUPLICATE KEY UPDATE', array( true, array() ), array( count( $c16_sql ) >= 3, array_values( $c16_with_values ) ) );
// Run the captured statements again inside a rolled-back transaction and read the server's
// warnings: MySQL 9.7 reports VALUES() as deprecated (code 1287); MariaDB never does.
$c16_warn = array();
foreach ( array_slice( $c16_sql, 0, 5 ) as $c16_s ) {
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( $c16_s );
	foreach ( (array) $wpdb->get_results( 'SHOW WARNINGS' ) as $c16_w ) {
		if ( 1287 === (int) $c16_w->Code ) {
			$c16_warn[] = (string) $c16_w->Message;
		}
	}
	$wpdb->query( 'ROLLBACK' );
}
// Control: the old form does warn on MySQL 8.0.20+ (so the check above can fail at all).
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$c16_vd} (affiliate_id, day, campaign, sub_id, visits) VALUES (%d, %s, 'c16', 's', 1) ON DUPLICATE KEY UPDATE visits = visits + VALUES(visits)", $c16_fa, $c16_day ) );
$c16_old_warns = (bool) array_filter(
	(array) $wpdb->get_results( 'SHOW WARNINGS' ),
	static function ( $w ) {
		return 1287 === (int) $w->Code;
	}
);
$wpdb->query( 'ROLLBACK' );
$c16_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
$c16_mariadb = false !== stripos( $c16_version, 'mariadb' );
check( 'C16-1 no deprecation warning (1287) from the new upserts; the old form warns on MySQL only (' . $c16_version . ')', array( array(), ! $c16_mariadb && version_compare( $c16_version, '8.0.20', '>=' ) ), array( $c16_warn, $c16_old_warns ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$c16_vd} WHERE affiliate_id = %d", $c16_fa ) );
remove_all_filters( 'query' );
foreach ( $c16_keep as $c16_o => $c16_v ) {
	if ( null !== $c16_v ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = %s", $c16_o, $c16_v, $c16_v ) );
	}
}

/* ---------------------------------------------------------------------------------------------
 * Fixtures: a program (partner group) with its own values, a partner in it and one without.
 * ------------------------------------------------------------------------------------------- */
$c16_settings = get_option( 'wpac_settings' );
Settings::update( array( 'hold_days' => 0, 'cookie_days' => 30, 'min_payout_minor' => 5000, 'cookie_mode' => 'consent' ) );
Settings::flush_cache();
$c16_group = (int) Groups::create( 'C16 Schulen' );
$c16_prog  = 'group:' . $c16_group;
$c16_a     = (int) AffiliateRepository::create( array( 'name' => 'C16 Schule', 'email' => 'c16-schule@example.de', 'status' => 'active', 'group_id' => $c16_group ) );
$c16_b     = (int) AffiliateRepository::create( array( 'name' => 'C16 Standard', 'email' => 'c16-standard@example.de', 'status' => 'active' ) );
$c16_own   = array(
	'hold_days'        => 5000,
	'min_payout_minor' => 2500,
	'cookie_days'      => 90,
);
$c16_filter = static function ( $value, $key, $context ) use ( &$c16_own, $c16_prog ) {
	return $c16_prog === $context['program'] && isset( $c16_own[ $key ] ) ? $c16_own[ $key ] : $value;
};

/* ---------------------------------------------------------------------------------------------
 * 2a. Monthly bonus: due per program hold period; deferred partners follow in the recalculation.
 * ------------------------------------------------------------------------------------------- */
$c16_month  = '2024-02';
$c16_state  = array( Settings::state( 'tier_month_closed' ), Settings::state( 'tier_month_first' ) );
$c16_plans  = array();
foreach ( array( $c16_a, $c16_b ) as $c16_p ) {
	$c16_plans[] = (int) TierService::create( array( 'scope' => 'affiliate', 'scope_id' => $c16_p, 'basis' => 'orders', 'mode' => 'retroactive', 'steps' => array( array( 'threshold' => 1, 'rate' => '5' ) ) ) );
	$c16_r       = sale( 'c16-tier-' . $c16_p, CodeRepository::main_code( $c16_p ), array( 20000 ) );
	q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$c16_month}-10 10:00:00' WHERE id = " . (int) $c16_r['commission_ids'][0] );
	Ledger::transition( (int) $c16_r['commission_ids'][0], 'approved' );
}
$c16_bonus = static function ( $aff ) use ( $wpdb, $c16_month ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor), 0) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = %d AND kind = 'bonus' AND source_order_id = %s", $aff, 'tier-' . $c16_month ) );
};
$c16_cur = Db::local_month( 0 );
check(
	'C16-2a without wpac_setting callback: is_due() of a partner = is_due() of the month (old, current month)',
	array( true, true, false, false ),
	array( TierService::is_due( $c16_month ), TierService::is_due( $c16_month, $c16_a ), TierService::is_due( $c16_cur ), TierService::is_due( $c16_cur, $c16_a ) )
);
add_filter( 'wpac_setting', $c16_filter, 10, 3 );
check( 'C16-2a hold period of the program: the month is due, not yet for the program partner', array( true, false, true ), array( TierService::is_due( $c16_month ), TierService::is_due( $c16_month, $c16_a ), TierService::is_due( $c16_month, $c16_b ) ) );
delete_option( TierService::DEFERRED );
TierService::close_month( $c16_month );
check( 'C16-2a closing books the default partner only and remembers the month', array( 1000, 0, array( $c16_month ) ), array( $c16_bonus( $c16_b ), $c16_bonus( $c16_a ), TierService::deferred_months() ) );
TierService::recalculate_month( $c16_month );
check( 'C16-2a recalculation before the program hold period ends: still no bonus, month stays deferred', array( 0, array( $c16_month ), 1000 ), array( $c16_bonus( $c16_a ), TierService::deferred_months(), $c16_bonus( $c16_b ) ) );
// Hold period over: the daily recalculation (last closed months far later) picks the deferred month up.
$c16_own['hold_days'] = 0;
Settings::set_state( 'tier_month_closed', Db::local_month( -1 ) );
delete_option( TierService::RECALC_PENDING );
delete_option( TierService::CURSOR );
TierService::recalculate_closed_months( 0 );
check( 'C16-2a once due, the recalculation books the deferred bonus (key of a normal bonus) and forgets the month', array( 1000, 1, array(), 1000 ), array( $c16_bonus( $c16_a ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE idempotency_key = %s', 'bonus:tier:' . $c16_a . ':' . $c16_month ) ), TierService::deferred_months(), $c16_bonus( $c16_b ) ) );
TierService::recalculate_closed_months( 0 );
check( 'C16-2a a further run books nothing more', array( 1000, 1000 ), array( $c16_bonus( $c16_a ), $c16_bonus( $c16_b ) ) );
$c16_own['hold_days'] = 5000;
remove_all_filters( 'wpac_setting' );
foreach ( $c16_plans as $c16_plan ) {
	TierService::deactivate( $c16_plan, 'C16 test done' );
}
Settings::set_state( 'tier_month_closed', $c16_state[0] );
Settings::set_state( 'tier_month_first', $c16_state[1] );
delete_option( TierService::DEFERRED );

/* ---------------------------------------------------------------------------------------------
 * 2b. Minimum payout in the admin: per program and on the partner page.
 * ------------------------------------------------------------------------------------------- */
$c16_general = Money::format( 5000, 'EUR' );
$c16_balance = new ReflectionMethod( WPAffiliateCaptain\Admin\Pages\AffiliatesPage::class, 'render_balance' );
$c16_balance->setAccessible( true );
$c16_page = static function ( $aff ) use ( $c16_balance ) {
	ob_start();
	$c16_balance->invoke( null, $aff );
	return (string) ob_get_clean();
};
add_filter( 'wpac_report_programs', static function () use ( $c16_prog ) { return array( '' => 'Standard', $c16_prog => 'C16 Schulen' ); } );
check(
	'C16-2b without wpac_setting callback: one general minimum, also for a partner; no line on the partner page',
	array( $c16_general, $c16_general, false ),
	array( PayoutsPage::min_text( 'EUR' ), PayoutsPage::min_text( 'EUR', $c16_a ), false !== strpos( $c16_page( $c16_a ), 'wpac-partner-min-payout' ) )
);
add_filter( 'wpac_setting', $c16_filter, 10, 3 );
check(
	'C16-2b with a program minimum: general text names the differing program, the partner value is the program value',
	array( $c16_general . ' (C16 Schulen: ' . Money::format( 2500, 'EUR' ) . ')', Money::format( 2500, 'EUR' ), $c16_general, 2500 ),
	array( PayoutsPage::min_text( 'EUR' ), PayoutsPage::min_text( 'EUR', $c16_a ), PayoutsPage::min_text( 'EUR', $c16_b ), Fx::min_payout_program( 'EUR', $c16_prog ) )
);
$c16_html_a = $c16_page( $c16_a );
check( 'C16-2b partner page shows the program minimum (only where it differs)', array( true, true, false ), array( false !== strpos( $c16_html_a, 'wpac-partner-min-payout' ), false !== strpos( $c16_html_a, esc_html( Money::format( 2500, 'EUR' ) ) ), false !== strpos( $c16_page( $c16_b ), 'wpac-partner-min-payout' ) ) );
remove_all_filters( 'wpac_report_programs' );
check( 'C16-2b without wpac_report_programs the general text stays plain', $c16_general, PayoutsPage::min_text( 'EUR' ) );
remove_all_filters( 'wpac_setting' );

/* ---------------------------------------------------------------------------------------------
 * 2c. REST report route: argument program.
 * ------------------------------------------------------------------------------------------- */
sale( 'c16-rest-a', CodeRepository::main_code( $c16_a ), array( 10000 ) );
sale( 'c16-rest-b', CodeRepository::main_code( $c16_b ), array( 10000 ) );
$c16_today = wp_date( 'Y-m-d' );
$c16_ids   = static function ( $r ) {
	return is_array( $r ) ? array_map( 'intval', array_column( $r['rows'], 'affiliate_id' ) ) : array();
};
$c16_all   = RestApi::report( new WP_REST_Request( array( 'view' => 'partners', 'from' => $c16_today, 'to' => $c16_today ) ) );
$c16_pr    = RestApi::report( new WP_REST_Request( array( 'view' => 'partners', 'from' => $c16_today, 'to' => $c16_today, 'program' => $c16_prog ) ) );
$c16_def   = RestApi::report( new WP_REST_Request( array( 'view' => 'partners', 'from' => $c16_today, 'to' => $c16_today, 'program' => '' ) ) );
$c16_cmp   = RestApi::report( new WP_REST_Request( array( 'view' => 'campaign', 'from' => $c16_today, 'to' => $c16_today, 'program' => 'group:99999999' ) ) );
check(
	'C16-2c REST report: without program all partners (no "program" field), with program only its partners, "" = default program, unknown program = no rows',
	array( true, true, false, array( $c16_a ), $c16_prog, true, false, '', array() ),
	array( in_array( $c16_a, $c16_ids( $c16_all ), true ) && in_array( $c16_b, $c16_ids( $c16_all ), true ), is_array( $c16_all ), isset( $c16_all['program'] ), $c16_ids( $c16_pr ), $c16_pr['program'] ?? null, in_array( $c16_b, $c16_ids( $c16_def ), true ), in_array( $c16_a, $c16_ids( $c16_def ), true ), $c16_def['program'] ?? null, $c16_cmp['rows'] ?? null )
);
$c16_err = static function ( $program ) {
	$r = RestApi::report( new WP_REST_Request( array( 'view' => 'partners', 'program' => $program ) ) );
	if ( ! is_wp_error( $r ) ) { return 'ok'; }
	return $r->get_error_code(); // (status 400 is set in the source; the test stub of WP_Error drops the data)
};
check( 'C16-2c REST report: invalid program → wpac_invalid_program, like the list functions', array( 'wpac_invalid_program', 'wpac_invalid_program', 'wpac_invalid_program' ), array( $c16_err( 'a b' ), $c16_err( array( 'x' ) ), $c16_err( str_repeat( 'x', 41 ) ) ) );

/* ---------------------------------------------------------------------------------------------
 * 2d. Active partners of a program (dashboard KPI; rendered in r14-c16-render.php).
 * ------------------------------------------------------------------------------------------- */
$c16_counts = AffiliateRepository::count_by_status();
check( 'C16-2d count_by_status( program ): only the partners of the program; null = all; unknown = 0', array( 1, true, 0, $c16_counts ), array( AffiliateRepository::count_by_status( $c16_prog )['active'], AffiliateRepository::count_by_status( '' )['active'] >= 1 && AffiliateRepository::count_by_status( '' )['active'] < $c16_counts['active'], AffiliateRepository::count_by_status( 'pro' )['active'], AffiliateRepository::count_by_status( null ) ) );
add_filter( 'wpac_program_groups', static function ( $groups, $program ) use ( $c16_group ) { return 'pro' === $program ? array( $c16_group ) : $groups; }, 10, 2 );
check( 'C16-2d count_by_status() follows wpac_program_groups', 1, AffiliateRepository::count_by_status( 'pro' )['active'] );
remove_all_filters( 'wpac_program_groups' );
$GLOBALS['c16_fixture'] = array( 'group' => $c16_group, 'program' => $c16_prog, 'a' => $c16_a, 'b' => $c16_b );

/* ---------------------------------------------------------------------------------------------
 * 2e. Privacy texts: cookie lifetime per program only when a callback changes it.
 * ------------------------------------------------------------------------------------------- */
$c16_policy = static function () {
	unset( $GLOBALS['g4_policy'] );
	Privacy::policy_text();
	return (string) ( $GLOBALS['g4_policy'] ?? '' );
};
$c16_plain      = $c16_policy();
$c16_plain_ropa = Privacy::ropa( 'de' );
add_filter( 'wpac_setting', static function ( $value, $key ) { return 'default_rate' === $key ? '7' : $value; }, 10, 3 );
$c16_rate_only = $c16_policy();
check( 'C16-2e callback without own cookie lifetime: text unchanged; no range', array( $c16_plain, null, false ), array( $c16_rate_only, Privacy::program_cookie_days(), false !== strpos( $c16_plain, 'depending on the partner program' ) || false !== strpos( $c16_plain, 'partner program of the partner' ) ) );
add_filter( 'wpac_setting', $c16_filter, 10, 3 );
$c16_multi = $c16_policy();
check(
	'C16-2e own cookie lifetime of a program: range in the cookie paragraph, the storage periods and the record of processing',
	array( array( 30, 90 ), true, true, false, true ),
	array(
		Privacy::program_cookie_days(),
		false !== strpos( $c16_multi, 'depends on the partner program of the partner whose link you followed: between 30 and 90 days' ),
		false !== strpos( $c16_multi, 'affiliate cookie: 30 to 90 days depending on the partner program' ),
		false !== strpos( $c16_multi, 'affiliate cookie: 30 days' ),
		false !== strpos( Privacy::ropa( 'de' ), 'Cookie 30–90 Tage' ),
	)
);
// A program that is only known to the report selector (no group) counts too.
$c16_own['cookie_days'] = 30;
add_filter( 'wpac_report_programs', static function () { return array( 'vip' => 'VIP' ); } );
add_filter( 'wpac_setting', static function ( $value, $key, $context ) { return 'vip' === $context['program'] && 'cookie_days' === $key ? 14 : $value; }, 20, 3 );
check( 'C16-2e programs of wpac_report_programs count (shorter lifetime)', array( 14, 30 ), Privacy::program_cookie_days() );
remove_all_filters( 'wpac_setting' );
remove_all_filters( 'wpac_report_programs' );
$c16_own['cookie_days'] = 90;
check( 'C16-2e without callback: policy and record of processing exactly as before', array( $c16_plain, $c16_plain_ropa, null ), array( $c16_policy(), Privacy::ropa( 'de' ), Privacy::program_cookie_days() ) );

update_option( 'wpac_settings', $c16_settings );
Settings::flush_cache();
