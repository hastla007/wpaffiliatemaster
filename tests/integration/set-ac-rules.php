<?php
/**
 * SET-AC-22 (owner decision E6: validity is free in the core): commission rules with a cap per
 * line (max_amount_minor), a minimum base (min_base_minor) and a validity (valid_from /
 * valid_until, calendar days of the site's time zone, inclusive). Schema 17 with the online
 * migration 16 → 17, input checks, calculator (cap, minimum, validity boundaries), bookings in the
 * site's time zone, increases keep the cap, foreign currency. Included from run.php right before
 * r8-render.php (helpers check(), t(), q()); the preview is rendered in set-ac-rules-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Settings;

echo "SET-AC-22 rules: cap, minimum base, validity\n";
global $wpdb;

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz = (string) get_option( 'timezone_string', '' );
		return new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
	}
}
$s22_keep_settings = get_option( 'wpac_settings' );
$s22_keep_tz       = get_option( 'timezone_string', null );

/* Schema 17 and the online migration 16 → 17. */
$s22_cols = static function () use ( $wpdb ) {
	$out = array();
	foreach ( (array) $wpdb->get_results( "SELECT COLUMN_NAME n, DATA_TYPE t, IS_NULLABLE nl, COLUMN_DEFAULT d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . t( 'rules' ) . "' AND COLUMN_NAME IN ('max_amount_minor','min_base_minor','valid_from','valid_until') ORDER BY ORDINAL_POSITION" ) as $c ) {
		$out[ $c->n ] = strtolower( $c->t ) . ' ' . $c->nl . ' ' . ( null === $c->d || 'NULL' === $c->d ? 'null' : trim( (string) $c->d, "'" ) );
	}
	return $out;
};
$s22_expected_cols = array(
	'max_amount_minor' => 'bigint NO 0',
	'min_base_minor'   => 'bigint NO 0',
	'valid_from'       => 'date YES null',
	'valid_until'      => 'date YES null',
);
check( 'SET-AC-22 schema 17 everywhere, rule columns present, schema verified', array( 17, 17, 17, $s22_expected_cols, array() ), array( Installer::DB_VERSION, WPAC_DB_VERSION, Installer::db_version(), $s22_cols(), Installer::verify_schema() ) );

$s22_old = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'S22-OLD', 'rate_type' => 'percent', 'rate' => '7', 'active' => 0, 'note' => 'S22 before migration' ) ), 0, 'test' );
q( 'ALTER TABLE ' . t( 'rules' ) . ' DROP COLUMN max_amount_minor, DROP COLUMN min_base_minor, DROP COLUMN valid_from, DROP COLUMN valid_until' );
check( 'SET-AC-22 verify_schema() reports the missing rule columns', true, in_array( 'missing column ' . t( 'rules' ) . '.max_amount_minor', Installer::verify_schema(), true ) );
update_option( 'wpac_db_version', 16 );
$s22_queries = array();
$s22_log     = static function ( $q ) use ( &$s22_queries ) {
	if ( preg_match( '/^\s*ALTER TABLE\s+\S*rules\b/i', $q ) ) {
		$s22_queries[] = $q;
	}
	return $q;
};
add_filter( 'query', $s22_log );
Installer::install_schema();
remove_filter( 'query', $s22_log );
$s22_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'rules' ) . ' WHERE id = %d', $s22_old ), ARRAY_A );
check(
	'SET-AC-22 migration 16 → 17: one online ALTER adds all four columns, existing rule unchanged (no cap, no minimum, open validity), version 17',
	array( 1, true, $s22_expected_cols, array( '7', '0', '0', null, null, 'S22 before migration' ), 17, array() ),
	array( count( $s22_queries ), isset( $s22_queries[0] ) && false !== stripos( $s22_queries[0], 'ALGORITHM=INPLACE, LOCK=NONE' ) && 4 === substr_count( $s22_queries[0], 'ADD COLUMN' ), $s22_cols(), array( $s22_row['rate'], (string) $s22_row['max_amount_minor'], (string) $s22_row['min_base_minor'], $s22_row['valid_from'], $s22_row['valid_until'], $s22_row['note'] ), (int) get_option( 'wpac_db_version' ), Installer::verify_schema() )
);
RuleRepository::delete( $s22_old, 'test' );

/* Input checks. */
Settings::flush_cache();
$s22_cur = (string) Settings::get( 'currency' );
$s22_in  = array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'active' => 1 );
$s22_ok  = RuleRepository::sanitize( $s22_in + array( 'max_amount' => '25,50', 'min_base' => '100', 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-31' ) );
$s22_nil = RuleRepository::sanitize( $s22_in );
check(
	'SET-AC-22 sanitize: amounts in minor units of the program currency, dates as Y-m-d, empty = 0 / NULL',
	array( 2550, 10000, '2026-12-01', '2026-12-31', 0, 0, null, null ),
	array( $s22_ok['max_amount_minor'], $s22_ok['min_base_minor'], $s22_ok['valid_from'], $s22_ok['valid_until'], $s22_nil['max_amount_minor'], $s22_nil['min_base_minor'], $s22_nil['valid_from'], $s22_nil['valid_until'] )
);
$s22_err = static function ( array $extra ) use ( $s22_in ) {
	$r = RuleRepository::sanitize( $extra + $s22_in );
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
check(
	'SET-AC-22 sanitize rejects negative or invalid amounts, impossible dates and an end before the start; one-day validity is fine; exclusions have no cap',
	array( 'wpac_rule_max_amount', 'wpac_rule_min_base', 'wpac_rule_valid_from', 'wpac_rule_valid_until', 'wpac_rule_valid_until', 'ok', 0 ),
	array(
		$s22_err( array( 'max_amount' => '-1' ) ),
		$s22_err( array( 'min_base' => 'abc' ) ),
		$s22_err( array( 'valid_from' => '2026-02-30' ) ),
		$s22_err( array( 'valid_from' => '2026-12-02', 'valid_until' => '2026-12-01' ) ),
		$s22_err( array( 'valid_until' => '31.12.2026' ) ),
		$s22_err( array( 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-01' ) ),
		RuleRepository::sanitize( array( 'rate_type' => 'exclude', 'max_amount' => '5' ) + $s22_in )['max_amount_minor'],
	)
);
$s22_saved = RuleRepository::save( $s22_nil + array(), 0, 'test' );
$s22_srow  = RuleRepository::find( $s22_saved );
check( 'SET-AC-22 a rule without conditions is stored with NULL dates (not 0000-00-00)', array( null, null, '0', '0' ), array( $s22_srow->valid_from, $s22_srow->valid_until, (string) $s22_srow->max_amount_minor, (string) $s22_srow->min_base_minor ) );
RuleRepository::delete( $s22_saved, 'test' );

/* Calculator: cap per line. */
$s22_rule = static function ( $id, array $extra = array() ) {
	return $extra + array( 'id' => $id, 'scope' => 'global', 'scope_id' => 0, 'target' => 'all', 'target_value' => '', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1, 'max_amount_minor' => 0, 'min_base_minor' => 0, 'valid_from' => null, 'valid_until' => null );
};
$s22_item = static function ( $net, array $extra = array() ) {
	return $extra + array( 'product_id' => 5, 'parent_id' => 0, 'sku' => 'S22', 'category_ids' => array(), 'category_ancestor_ids' => array(), 'tag_ids' => array(), 'quantity' => 1, 'net_minor' => $net, 'sale_date' => '2026-12-10' );
};
$s22_def = array( 'rate_type' => 'percent', 'rate' => '5' );
$s22_c1  = Calculator::calculate( $s22_item( 50000 ), array( $s22_rule( 1, array( 'max_amount_minor' => 2000 ) ) ), 1, 0, $s22_def, false );
$s22_c2  = Calculator::calculate( $s22_item( 10000 ), array( $s22_rule( 1, array( 'max_amount_minor' => 2000 ) ) ), 1, 0, $s22_def, false );
$s22_c3  = Calculator::calculate( $s22_item( 10000, array( 'quantity' => 4 ) ), array( $s22_rule( 1, array( 'rate_type' => 'fixed', 'rate' => '1500', 'max_amount_minor' => 4000 ) ) ), 1, 0, $s22_def, false );
$s22_c4  = Calculator::calculate( $s22_item( 50000 ), array( $s22_rule( 1 ) ), 1, 0, $s22_def, false );
$s22_c5  = Calculator::calculate( $s22_item( 20000 ), array( $s22_rule( 1, array( 'max_amount_minor' => 2000 ) ) ), 1, 0, $s22_def, false );
check(
	'SET-AC-22 cap: 10 % of 500 = 50 capped at 20; 10 % of 100 stays 10 (not reached); fixed 15 × 4 capped at 40 per line; 0 = no cap; exactly at the cap is not "capped"',
	array( array( 2000, true, 5000, 2000, 1 ), array( 1000, false, 1000 ), array( 4000, true, 6000 ), array( 5000, false, 0 ), array( 2000, false ) ),
	array(
		array( $s22_c1['amount_minor'], $s22_c1['capped'], $s22_c1['uncapped_minor'], $s22_c1['cap_minor'], $s22_c1['rule_id'] ),
		array( $s22_c2['amount_minor'], $s22_c2['capped'], $s22_c2['uncapped_minor'] ),
		array( $s22_c3['amount_minor'], $s22_c3['capped'], $s22_c3['uncapped_minor'] ),
		array( $s22_c4['amount_minor'], $s22_c4['capped'], $s22_c4['cap_minor'] ),
		array( $s22_c5['amount_minor'], $s22_c5['capped'] ),
	)
);
add_filter( 'wpac_commission_amount', $s22_f = static function ( $a ) { return $a + 500; } );
$s22_c6 = Calculator::calculate( $s22_item( 50000 ), array( $s22_rule( 1, array( 'max_amount_minor' => 2000 ) ) ), 1, 0, $s22_def, true );
remove_filter( 'wpac_commission_amount', $s22_f );
check( 'SET-AC-22 cap: the amount filter runs after the cap (filters keep winning)', 2500, $s22_c6['amount_minor'] );
$s22_c7 = Calculator::calculate( $s22_item( 50000 ), array(), 1, 0, $s22_def, false );
check( 'SET-AC-22 the program default has no cap', array( 2500, 0, false ), array( $s22_c7['amount_minor'], $s22_c7['cap_minor'], $s22_c7['capped'] ) );

/* Calculator: minimum base. */
$s22_min   = array( $s22_rule( 1, array( 'rate' => '20', 'min_base_minor' => 10000 ) ), $s22_rule( 2, array( 'rate' => '8', 'target' => 'category', 'target_value' => '7', 'min_base_minor' => 20000 ) ) );
$s22_cat   = array( 'category_ids' => array( 7 ) );
check(
	'SET-AC-22 minimum base: below → next rule / program default, at the minimum → applies; compared with the base after wpac_commission_base (net_minor)',
	array( 0, 500, 1, 2000, 1, 3980, 2, 1600 ),
	array(
		Calculator::calculate( $s22_item( 9999 ), $s22_min, 1, 0, $s22_def, false )['rule_id'],
		Calculator::calculate( $s22_item( 9999 ), $s22_min, 1, 0, $s22_def, false )['amount_minor'],
		Calculator::calculate( $s22_item( 10000 ), $s22_min, 1, 0, $s22_def, false )['rule_id'],
		Calculator::calculate( $s22_item( 10000 ), $s22_min, 1, 0, $s22_def, false )['amount_minor'],
		Calculator::calculate( $s22_item( 19900, $s22_cat ), $s22_min, 1, 0, $s22_def, false )['rule_id'], // Category rule needs 200: the global rule applies.
		Calculator::calculate( $s22_item( 19900, $s22_cat ), $s22_min, 1, 0, $s22_def, false )['amount_minor'],
		Calculator::calculate( $s22_item( 20000, $s22_cat ), $s22_min, 1, 0, $s22_def, false )['rule_id'],
		Calculator::calculate( $s22_item( 20000, $s22_cat ), $s22_min, 1, 0, $s22_def, false )['amount_minor'],
	)
);
$s22_excl = array( $s22_rule( 3, array( 'rate_type' => 'exclude', 'rate' => '0', 'min_base_minor' => 100000 ) ) );
check( 'SET-AC-22 minimum base on an exclusion: only lines from the minimum are excluded', array( false, true ), array( Calculator::calculate( $s22_item( 99999 ), $s22_excl, 1, 0, $s22_def, false )['excluded'], Calculator::calculate( $s22_item( 100000 ), $s22_excl, 1, 0, $s22_def, false )['excluded'] ) );

/* Calculator: validity boundaries (calendar days, inclusive). */
$s22_valid = array( $s22_rule( 4, array( 'rate' => '30', 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-24' ) ) );
$s22_on    = static function ( $day ) use ( $s22_item, $s22_valid, $s22_def ) {
	return Calculator::calculate( $s22_item( 10000, array( 'sale_date' => $day ) ), $s22_valid, 1, 0, $s22_def, false )['rule_id'];
};
check( 'SET-AC-22 validity: day before the start no, first day yes, last day yes, day after no (→ program default)', array( 0, 4, 4, 4, 0 ), array( $s22_on( '2026-11-30' ), $s22_on( '2026-12-01' ), $s22_on( '2026-12-12' ), $s22_on( '2026-12-24' ), $s22_on( '2026-12-25' ) ) );
check(
	'SET-AC-22 validity: open start / open end, empty and zero dates count as open',
	array( true, false, true, false, true, true ),
	array(
		Calculator::valid_on( array( 'valid_until' => '2026-12-24' ), '2000-01-01' ),
		Calculator::valid_on( array( 'valid_until' => '2026-12-24' ), '2026-12-25' ),
		Calculator::valid_on( array( 'valid_from' => '2026-12-01' ), '2099-12-31' ),
		Calculator::valid_on( array( 'valid_from' => '2026-12-01' ), '2026-11-30' ),
		Calculator::valid_on( array( 'valid_from' => '', 'valid_until' => null ), '2026-11-30' ),
		Calculator::valid_on( array( 'valid_from' => '0000-00-00', 'valid_until' => '0000-00-00' ), '2026-11-30' ),
	)
);
$s22_valid2 = array( $s22_rule( 5, array( 'rate' => '30', 'priority' => 10, 'valid_from' => '2026-12-01' ) ), $s22_rule( 6, array( 'rate' => '12' ) ) );
check( 'SET-AC-22 validity: an expired / future specific rule leaves the line to the next rule', array( 6, 5 ), array( Calculator::calculate( $s22_item( 10000, array( 'sale_date' => '2026-11-30' ) ), $s22_valid2, 1, 0, $s22_def, false )['rule_id'], Calculator::calculate( $s22_item( 10000, array( 'sale_date' => '2026-12-01' ) ), $s22_valid2, 1, 0, $s22_def, false )['rule_id'] ) );

/* Time zone: today and the bookings use the site's calendar day. */
update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14: the local day is ahead of UTC.
$s22_kiri = Calculator::local_date( gmmktime( 12, 0, 0, 11, 30, 2026 ) );
update_option( 'timezone_string', 'America/Los_Angeles' );
$s22_la = Calculator::local_date( gmmktime( 3, 0, 0, 12, 1, 2026 ) );
check( 'SET-AC-22 local_date() follows the site time zone', array( '2026-12-01', '2026-11-30' ), array( $s22_kiri, $s22_la ) );
update_option( 'timezone_string', 'UTC' );
$s22_today = Calculator::calculate( $s22_item( 10000, array( 'sale_date' => '' ) ), array( $s22_rule( 7, array( 'valid_from' => gmdate( 'Y-m-d' ), 'valid_until' => gmdate( 'Y-m-d' ) ) ) ), 1, 0, $s22_def, false );
check( 'SET-AC-22 an item without sale_date is checked against today', 7, $s22_today['rule_id'] );

$s22_partner = static function ( $slug ) {
	return (int) AffiliateRepository::create( array( 'name' => 'S22 ' . $slug, 'email' => 's22-' . $slug . '@example.de', 'status' => 'active' ) );
};
$s22_sale = static function ( $aff, $order, $paid, $net, $currency = 'EUR', $qty = 1 ) {
	return wpac_record_sale( array( 'source' => 'test', 'order_id' => 's22-' . $order, 'currency' => $currency, 'paid_at' => $paid, 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 922001, 'sku' => 'S22-ITEM', 'name' => 'S22 Kurs', 'net_minor' => $net, 'quantity' => $qty ) ), 'customer' => array( 'user_id' => 0, 'email' => 's22-kunde-' . $order . '@example.de' ) ) );
};
$s22_c = static function ( $r ) use ( $wpdb ) {
	return ! empty( $r['commission_ids'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'commissions' ) . ' WHERE id = %d', (int) $r['commission_ids'][0] ) ) : null;
};
$s22_rid = static function ( $r ) use ( $s22_c ) {
	$c = $s22_c( $r );
	return $c ? (int) $c->rule_id : -1;
};

update_option( 'timezone_string', 'Europe/Berlin' );
$s22_a  = $s22_partner( 'from' );
$s22_ra = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $s22_a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '33', 'active' => 1, 'valid_from' => '2026-12-01' ) ), 0, 'test' );
$s22_b  = $s22_partner( 'until' );
$s22_rb = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $s22_b, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '33', 'active' => 1, 'valid_until' => '2026-11-30' ) ), 0, 'test' );
$s22_r1 = $s22_sale( $s22_a, 'a1', gmmktime( 22, 59, 0, 11, 30, 2026 ), 10000 ); // 23:59 in Berlin, 30 Nov.
$s22_r2 = $s22_sale( $s22_a, 'a2', gmmktime( 23, 0, 0, 11, 30, 2026 ), 10000 );  // 00:00 in Berlin, 1 Dec.
$s22_r3 = $s22_sale( $s22_b, 'b1', gmmktime( 22, 59, 0, 11, 30, 2026 ), 10000 );
$s22_r4 = $s22_sale( $s22_b, 'b2', gmmktime( 23, 0, 0, 11, 30, 2026 ), 10000 );
check(
	'SET-AC-22 bookings: the payment time counts as a calendar day in the site time zone (Europe/Berlin): 22:59 UTC on 30 Nov is still 30 Nov, 23:00 UTC is 1 Dec',
	array( false, true, true, false, 3300 ),
	array( $s22_ra === $s22_rid( $s22_r1 ), $s22_ra === $s22_rid( $s22_r2 ), $s22_rb === $s22_rid( $s22_r3 ), $s22_rb === $s22_rid( $s22_r4 ), $s22_c( $s22_r2 ) ? (int) $s22_c( $s22_r2 )->amount_minor : -1 )
);
update_option( 'timezone_string', 'UTC' );
$s22_r5 = $s22_sale( $s22_b, 'b3', gmmktime( 23, 0, 0, 11, 30, 2026 ), 10000 );
check( 'SET-AC-22 bookings: the same payment time in a UTC site is still 30 Nov (rule valid until 30 Nov applies)', true, $s22_rb === $s22_rid( $s22_r5 ) );

/* Cap and minimum in the booking, the snapshot and an increase of the line. */
$s22_d  = $s22_partner( 'cap' );
$s22_rd = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $s22_d, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '20', 'active' => 1, 'max_amount' => '30', 'min_base' => '50' ) ), 0, 'test' );
$s22_r6 = $s22_sale( $s22_d, 'd1', time(), 10000 );  // 20 % of 100 = 20 (below the cap).
$s22_r7 = $s22_sale( $s22_d, 'd2', time(), 4999 );   // Below the minimum: not this rule.
$s22_r8 = $s22_sale( $s22_d, 'd3', time(), 100000 ); // 20 % of 1000 = 200, capped at 30.
$s22_c8 = $s22_c( $s22_r8 );
$s22_s8 = $s22_c8 ? json_decode( (string) $s22_c8->rule_snapshot, true ) : array();
check(
	'SET-AC-22 bookings: cap and minimum apply; the snapshot keeps the cap of the rule',
	array( array( $s22_rd, 2000 ), true, array( $s22_rd, 3000, 100000 ), '3000' ),
	array( array( $s22_rid( $s22_r6 ), (int) $s22_c( $s22_r6 )->amount_minor ), $s22_rd !== $s22_rid( $s22_r7 ), array( (int) $s22_c8->rule_id, (int) $s22_c8->amount_minor, (int) $s22_c8->base_minor ), (string) ( $s22_s8['rule']['max_amount_minor'] ?? '' ) )
);
$s22_i1 = Ledger::record_increase( array( 'source' => 'test', 'order_id' => 's22-d1', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 922001, 'name' => 'S22 Kurs', 'net_minor' => 20000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
$s22_i2 = Ledger::record_increase( array( 'source' => 'test', 'order_id' => 's22-d3', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 922001, 'name' => 'S22 Kurs', 'net_minor' => 150000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
check(
	'SET-AC-22 increase of a line: up to the cap (20 → 30, not 40); a line already at the cap earns nothing more',
	array( 1000, 0 ),
	array( (int) $s22_i1['amount_minor'], (int) $s22_i2['amount_minor'] )
);

/* Foreign currency: cap and minimum are converted like fixed rates; without a rate the minimum is not reached. */
$s22_e  = $s22_partner( 'fx' );
$s22_re = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $s22_e, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '50', 'active' => 1, 'max_amount' => '10', 'min_base' => '20' ) ), 0, 'test' );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'currency' => 'EUR', 'exchange_rates' => 'CHF=0.5' ) ) );
Settings::flush_cache();
$s22_fx_ok = \WPAffiliateCaptain\Core\Fx::from_program( 1000, 'CHF', 'EUR', \WPAffiliateCaptain\Core\Fx::rates() );
if ( null !== $s22_fx_ok ) {
	$s22_r9  = $s22_sale( $s22_e, 'e1', time(), 10000, 'CHF' ); // 50 % of 100 CHF = 50, cap 10 EUR = 20 CHF.
	$s22_r10 = $s22_sale( $s22_e, 'e2', time(), 3999, 'CHF' );  // Minimum 20 EUR = 40 CHF: not reached.
	check( 'SET-AC-22 foreign currency: cap and minimum converted with the configured rate', array( $s22_re, 2000, 2000, true ), array( $s22_rid( $s22_r9 ), (int) $s22_fx_ok, $s22_c( $s22_r9 ) ? (int) $s22_c( $s22_r9 )->amount_minor : -1, $s22_re !== $s22_rid( $s22_r10 ) ) );
} else {
	check( 'SET-AC-22 foreign currency: test rate configured', 'rate', 'none' );
}
$s22_r11 = $s22_sale( $s22_e, 'e3', time(), 100000, 'JPY' );
check( 'SET-AC-22 foreign currency without a rate: the rule with a minimum does not apply', true, $s22_re !== $s22_rid( $s22_r11 ) );

/* Program export (admin page and wp wpac export): rules.csv carries the new columns. */
if ( \WPAffiliateCaptain\Migration\ProgramExport::available() ) {
	$s22_zip = tempnam( sys_get_temp_dir(), 's22' ) . '.zip';
	\WPAffiliateCaptain\Migration\ProgramExport::write( $s22_zip );
	$s22_z = new ZipArchive();
	$s22_z->open( $s22_zip );
	$s22_csv = (string) $s22_z->getFromName( 'rules.csv' );
	$s22_z->close();
	@unlink( $s22_zip );
	$s22_head = str_getcsv( (string) strtok( ltrim( $s22_csv, "\xEF\xBB\xBF" ), "\r\n" ), false !== strpos( (string) strtok( $s22_csv, "\n" ), ';' ) ? ';' : ',' );
	check( 'SET-AC-22 program export: rules.csv has max_amount_minor, min_base_minor, valid_from, valid_until', array(), array_values( array_diff( array( 'max_amount_minor', 'min_base_minor', 'valid_from', 'valid_until' ), $s22_head ) ) );
}

foreach ( array( $s22_ra, $s22_rb, $s22_rd, $s22_re ) as $s22_id ) {
	RuleRepository::delete( $s22_id, 'test' );
}
update_option( 'wpac_settings', $s22_keep_settings );
Settings::flush_cache();
if ( null === $s22_keep_tz ) {
	delete_option( 'timezone_string' );
} else {
	update_option( 'timezone_string', $s22_keep_tz );
}
