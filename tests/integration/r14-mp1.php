<?php
/**
 * Feature mp1 (0.15.0, contract 1.5.0): extension points for several partner programs, variant A
 * "program = partner group" (docs/MULTI-PROGRAM.md, A1–A6). A second program (a partner group)
 * gets its own default rate, hold days, cookie days and minimum payout through the filter
 * wpac_setting; commissions and reports are filtered by program; a group change fires
 * wpac_affiliate_program_changed; without a callback nothing changes.
 * Included from run.php before r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Programs;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 mp1 several partner programs, variant A (A1–A6)\n";
global $wpdb;

unset( $GLOBALS['test_caps'] );
foreach ( array( 'wpac_setting', 'wpac_affiliate_program', 'wpac_program_groups', 'wpac_report_programs', 'wpac_portal_program_label', 'wpac_affiliate_program_changed', 'wpac_report_partners' ) as $mp1_hook ) {
	remove_all_filters( $mp1_hook );
}
// Only the program default must apply to the test sales: active rules of earlier files are paused
// (restored at the end).
$mp1_rules = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . t( 'rules' ) . ' WHERE active = 1' ) );
if ( $mp1_rules ) {
	$wpdb->query( 'UPDATE ' . t( 'rules' ) . ' SET active = 0 WHERE id IN (' . implode( ',', $mp1_rules ) . ')' );
}

$mp1_group  = (int) Groups::create( 'MP1 Schulen' );
$mp1_group2 = (int) Groups::create( 'MP1 Vereine' );
$mp1_partner = static function ( $slug, $group ) use ( $wpdb ) {
	$id = (int) AffiliateRepository::create( array( 'name' => 'MP1 ' . $slug, 'email' => 'mp1-' . $slug . '@example.de', 'status' => 'active', 'group_id' => (int) $group ) );
	ProfileRepository::save( $id, array( 'legal_name' => 'MP1 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'MP1 ' . $slug, 'iban' => 'DE89370400440532013000', 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$mp1_sale = static function ( $aff, $order, $net, $campaign = '' ) {
	$touch = '' !== $campaign ? array( 'code' => CodeRepository::main_code( $aff ), 'campaign' => $campaign, 'content_id' => 0, 'ts' => time(), 'method' => 'link' ) : null;
	$sale  = array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net, 'quantity' => 1 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'mp1-kunde-' . $order . '@example.de' ) );
	if ( $touch ) {
		$sale['touch'] = $touch;
	} else {
		$sale['partner_code'] = CodeRepository::main_code( $aff );
	}
	$r = wpac_record_sale( $sale );
	return isset( $r['commission_ids'][0] ) ? (int) $r['commission_ids'][0] : 0;
};
$mp1_row = static function ( $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'commissions' ) . ' WHERE id = %d', (int) $id ) );
};
$mp1_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
$mp1_days = static function ( $row ) {
	return (int) round( ( strtotime( $row->hold_until . ' UTC' ) - strtotime( $row->created_at . ' UTC' ) ) / DAY_IN_SECONDS );
};

$mp1_std  = $mp1_partner( 'standard', 0 );
$mp1_pro  = $mp1_partner( 'schule', $mp1_group );
$mp1_prog = 'group:' . $mp1_group;

/* ---------------------------------------------------------------------------------------------
 * A2: program of a partner.
 * ------------------------------------------------------------------------------------------- */
check( 'MP1-A2 wpac_affiliate_program(): group partner "group:<ID>", without group and unknown ""', array( $mp1_prog, '', '', '' ), array( wpac_affiliate_program( $mp1_pro ), wpac_affiliate_program( $mp1_std ), wpac_affiliate_program( 99999999 ), wpac_affiliate_program( 0 ) ) );
add_filter( 'wpac_affiliate_program', static function ( $program, $aff ) use ( $mp1_std ) { return (int) $aff === $mp1_std ? 'Bad key!' : $program; }, 10, 2 );
check( 'MP1-A2 an invalid key from the filter is ignored (default stays)', '', wpac_affiliate_program( $mp1_std ) );
remove_all_filters( 'wpac_affiliate_program' );
add_filter( 'wpac_affiliate_program', static function ( $program, $aff, $group ) use ( $mp1_std ) { return (int) $aff === $mp1_std ? 'basis' : $program; }, 10, 3 );
check( 'MP1-A2 the filter can map a partner to its own key', array( 'basis', $mp1_prog ), array( wpac_affiliate_program( $mp1_std ), wpac_affiliate_program( $mp1_pro ) ) );
remove_all_filters( 'wpac_affiliate_program' );
check( 'MP1-A2 Programs::clean(): keys up to 40 characters, no spaces, no arrays', array( '', 'group:3', null, null, null ), array( Programs::clean( '' ), Programs::clean( 'group:3' ), Programs::clean( 'a b' ), Programs::clean( str_repeat( 'x', 41 ) ), Programs::clean( array( 'x' ) ) ) );
check( 'MP1-A2 groups of a program: group key, default program, unknown key', array( array( $mp1_group ), array( 0 ), array(), array() ), array( Programs::groups_of( $mp1_prog ), Programs::groups_of( '' ), Programs::groups_of( 'pro' ), Programs::groups_of( 'group:0' ) ) );

/* ---------------------------------------------------------------------------------------------
 * A1 without a callback: nothing changes.
 * ------------------------------------------------------------------------------------------- */
$mp1_keys    = array( 'default_rate', 'default_rate_type', 'hold_days', 'cookie_days', 'min_payout_minor', 'touch_model', 'terms_page_id', 'terms_version' );
$mp1_plain   = array();
$mp1_context = array();
foreach ( $mp1_keys as $mp1_k ) {
	$mp1_plain[ $mp1_k ]   = Settings::get( $mp1_k );
	$mp1_context[ $mp1_k ] = Settings::get( $mp1_k, array( 'affiliate_id' => $mp1_pro ) );
}
check( 'MP1-A1 without a callback every setting with context equals the stored value', $mp1_plain, $mp1_context );
check( 'MP1-A1 without a callback: default rate, minimum payout and cookie days of the group partner are the program values', array( RuleRepository::default_rate(), Fx::min_payout( 'EUR' ), max( 1, (int) Settings::get( 'cookie_days' ) ) ), array( RuleRepository::default_rate( $mp1_pro ), Fx::min_payout( 'EUR', $mp1_pro ), Tracker::cookie_days( null, $mp1_pro ) ) );
check(
	'MP1-A1 context is completed: group and program of the partner, program of a group, explicit program wins',
	array(
		array( 'affiliate_id' => $mp1_pro, 'group_id' => $mp1_group, 'program' => $mp1_prog ),
		array( 'affiliate_id' => 0, 'group_id' => $mp1_group, 'program' => $mp1_prog ),
		array( 'affiliate_id' => $mp1_std, 'group_id' => 0, 'program' => 'x' ),
	),
	array( Settings::context( array( 'affiliate_id' => $mp1_pro ) ), Settings::context( array( 'group_id' => $mp1_group ) ), Settings::context( array( 'affiliate_id' => $mp1_std, 'program' => 'x' ) ) )
);

/* ---------------------------------------------------------------------------------------------
 * A1 with a callback: the second program has its own values.
 * ------------------------------------------------------------------------------------------- */
$mp1_seen = array();
$mp1_own  = array(
	'default_rate'      => '20',
	'default_rate_type' => 'percent',
	'hold_days'         => 45,
	'cookie_days'       => (int) Settings::get( 'cookie_days' ) + 60,
	'min_payout_minor'  => 1000000,
	'terms_version'     => '7.0-schulen',
);
add_filter(
	'wpac_setting',
	static function ( $value, $key, $context ) use ( $mp1_prog, $mp1_own, &$mp1_seen ) {
		$mp1_seen[ $key ][] = $context;
		return $mp1_prog === $context['program'] && array_key_exists( $key, $mp1_own ) ? $mp1_own[ $key ] : $value;
	},
	10,
	3
);
check( 'MP1-A1 Settings::get() without context ignores the filter', $mp1_plain['hold_days'], Settings::get( 'hold_days' ) );
check( 'MP1-A1 the default program keeps the stored values', array( $mp1_plain['hold_days'], RuleRepository::default_rate() ), array( Settings::for_affiliate( 'hold_days', $mp1_std ), RuleRepository::default_rate( $mp1_std ) ) );
check( 'MP1-A1 the second program gets its own default rate', array( 'rate_type' => 'percent', 'rate' => '20' ), RuleRepository::default_rate( $mp1_pro ) );

$mp1_c_std = $mp1_sale( $mp1_std, 'mp1-std-1', 100000, 'herbst' );
$mp1_c_pro = $mp1_sale( $mp1_pro, 'mp1-pro-1', 100000, 'herbst' );
$mp1_r_std = $mp1_row( $mp1_c_std );
$mp1_r_pro = $mp1_row( $mp1_c_pro );
check(
	'MP1-A1 commission: standard partner at the program default, school partner at 20 % (no rule)',
	array( (int) round( 100000 * (float) $mp1_plain['default_rate'] / 100 ), 20000, 0, 0, '20' ),
	array( (int) $mp1_r_std->amount_minor, (int) $mp1_r_pro->amount_minor, (int) $mp1_r_std->rule_id, (int) $mp1_r_pro->rule_id, (string) $mp1_r_pro->rate )
);
check( 'MP1-A1 hold period: stored hold days for the standard partner, 45 days for the school partner', array( (int) $mp1_plain['hold_days'], 45 ), array( $mp1_days( $mp1_r_std ), $mp1_days( $mp1_r_pro ) ) );
check( 'MP1-A1 the calculation passed the partner as context', true, in_array( array( 'affiliate_id' => $mp1_pro, 'group_id' => $mp1_group, 'program' => $mp1_prog ), isset( $mp1_seen['hold_days'] ) ? $mp1_seen['hold_days'] : array(), true ) );

// The hold period starts again (e.g. order completed): the program's hold days apply.
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'commissions' ) . " SET hold_until = '2099-01-01 00:00:00' WHERE id IN (%d, %d)", $mp1_c_std, $mp1_c_pro ) );
Ledger::restart_hold( 'test', 'mp1-pro-1', time() );
Ledger::restart_hold( 'test', 'mp1-std-1', time() );
check(
	'MP1-A1 restart of the hold period uses the hold days of the order partner\'s program',
	array( 45, (int) $mp1_plain['hold_days'] ),
	array( (int) round( ( strtotime( $mp1_row( $mp1_c_pro )->hold_until . ' UTC' ) - time() ) / DAY_IN_SECONDS ), (int) round( ( strtotime( $mp1_row( $mp1_c_std )->hold_until . ' UTC' ) - time() ) / DAY_IN_SECONDS ) )
);

// A broken value from a filter never reaches the calculator.
add_filter( 'wpac_setting', static function ( $value, $key, $context ) use ( $mp1_prog ) { return $mp1_prog === $context['program'] && 'default_rate_type' === $key ? 'bogus' : $value; }, 20, 3 );
check( 'MP1-A1 unusable rate type from a filter: the stored program default is used', array( 'rate_type' => $mp1_plain['default_rate_type'], 'rate' => (string) $mp1_plain['default_rate'] ), RuleRepository::default_rate( $mp1_pro ) );
unset( $GLOBALS['test_hooks']['wpac_setting'][20] );

// Cookie days: the server-side window of a touch follows the partner's program.
$mp1_old_ts = time() - ( max( 1, (int) $mp1_plain['cookie_days'] ) + 5 ) * DAY_IN_SECONDS;
$mp1_t_pro  = array( 'code' => CodeRepository::main_code( $mp1_pro ), 'campaign' => '', 'content_id' => 0, 'ts' => $mp1_old_ts );
$mp1_t_std  = array( 'code' => CodeRepository::main_code( $mp1_std ), 'campaign' => '', 'content_id' => 0, 'ts' => $mp1_old_ts );
check( 'MP1-A1 cookie days: program value for the school partner, stored value for the standard partner', array( $mp1_own['cookie_days'], max( 1, (int) $mp1_plain['cookie_days'] ), $mp1_own['cookie_days'] ), array( Tracker::cookie_days( null, $mp1_pro ), Tracker::cookie_days( null, $mp1_std ), Tracker::cookie_days( $mp1_t_pro ) ) );
check( 'MP1-A1 a referral older than the stored cookie days stays valid only in the program with the longer window', array( true, false ), array( Tracker::touch_valid( $mp1_t_pro ), Tracker::touch_valid( $mp1_t_std ) ) );
check( 'MP1-A1 the signed cookie of that referral is accepted only for the school partner', array( true, false ), array( null !== Tracker::parse_cookie( Tracker::cookie_value( $mp1_t_pro ) ), null !== Tracker::parse_cookie( Tracker::cookie_value( $mp1_t_std ) ) ) );

// Minimum payout.
foreach ( array( $mp1_c_std, $mp1_c_pro ) as $mp1_c ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'commissions' ) . " SET hold_until = '2020-01-01 00:00:00' WHERE id = %d", $mp1_c ) );
	Ledger::transition( $mp1_c, 'approved' );
}
check( 'MP1-A1 minimum payout per program', array( 1000000, Fx::min_payout( 'EUR' ) ), array( Fx::min_payout( 'EUR', $mp1_pro ), Fx::min_payout( 'EUR', $mp1_std ) ) );
$mp1_codes = array();
foreach ( PayoutService::preview( wp_date( 'Y-m-d' ), 'EUR' ) as $mp1_p ) {
	if ( $mp1_p['affiliate'] && in_array( (int) $mp1_p['affiliate']->id, array( $mp1_std, $mp1_pro ), true ) ) {
		$mp1_codes[ (int) $mp1_p['affiliate']->id ] = (string) $mp1_p['code'];
	}
}
check( 'MP1-A1 payout run preview: the school partner is below its program minimum, the standard partner is not', array( 'below_minimum', true ), array( isset( $mp1_codes[ $mp1_pro ] ) ? $mp1_codes[ $mp1_pro ] : '?', isset( $mp1_codes[ $mp1_std ] ) && 'below_minimum' !== $mp1_codes[ $mp1_std ] ) );
check( 'MP1-A1 payout request: no currency reaches the program minimum of the school partner', '', \WPAffiliateCaptain\Payouts\PayoutRequests::request_currency( $mp1_pro ) );

// Terms (group context, no partner).
check( 'MP1-A1 terms version per program (group context), default program unchanged', array( '7.0-schulen', (string) $mp1_plain['terms_version'] ), array( Agreements::current( $mp1_group )['version'], Agreements::current( 0 )['version'] ) );

/* ---------------------------------------------------------------------------------------------
 * A3: field and list filter "program".
 * ------------------------------------------------------------------------------------------- */
check( 'MP1-A3 field program in wpac_get_affiliate() and wpac_get_commission()', array( $mp1_prog, '', $mp1_prog, '' ), array( wpac_get_affiliate( $mp1_pro )['program'], wpac_get_affiliate( $mp1_std )['program'], wpac_get_commission( $mp1_c_pro )['program'], wpac_get_commission( $mp1_c_std )['program'] ) );
$mp1_ids = static function ( $list, $key = 'id' ) {
	return is_array( $list ) && isset( $list['items'] ) ? array_map( 'intval', array_column( $list['items'], $key ) ) : array( $list );
};
$mp1_a_pro = $mp1_ids( wpac_get_affiliates( array( 'program' => $mp1_prog, 'per_page' => 100 ) ) );
$mp1_a_std = $mp1_ids( wpac_get_affiliates( array( 'program' => '', 'search' => 'MP1 ', 'per_page' => 100 ) ) );
check( 'MP1-A3 wpac_get_affiliates( program ): only the partners of that program', array( array( $mp1_pro ), true, false ), array( $mp1_a_pro, in_array( $mp1_std, $mp1_a_std, true ), in_array( $mp1_pro, $mp1_a_std, true ) ) );
$mp1_cl_pro = wpac_get_commissions( array( 'program' => $mp1_prog, 'per_page' => 100 ) );
$mp1_cl_std = $mp1_ids( wpac_get_commissions( array( 'program' => '', 'per_page' => 100, 'orderby' => 'id', 'order' => 'desc' ) ) );
check( 'MP1-A3 wpac_get_commissions( program ): commissions of the program\'s partners, each with that program', array( array( $mp1_c_pro ), array( $mp1_prog ), true, false ), array( $mp1_ids( $mp1_cl_pro ), array_values( array_unique( array_column( $mp1_cl_pro['items'], 'program' ) ) ), in_array( $mp1_c_std, $mp1_cl_std, true ), in_array( $mp1_c_pro, $mp1_cl_std, true ) ) );
check(
	'MP1-A3 invalid program → wpac_invalid_program; unknown program → empty list',
	array( 'wpac_invalid_program', 'wpac_invalid_program', 'wpac_invalid_program', 0, 0 ),
	array( $mp1_code( wpac_get_affiliates( array( 'program' => 'a b' ) ) ), $mp1_code( wpac_get_commissions( array( 'program' => array( 'x' ) ) ) ), $mp1_code( wpac_get_commissions( array( 'program' => str_repeat( 'x', 41 ) ) ) ), wpac_get_affiliates( array( 'program' => 'pro' ) )['total'], wpac_get_commissions( array( 'program' => 'pro' ) )['total'] )
);
add_filter( 'wpac_program_groups', static function ( $groups, $program ) use ( $mp1_group ) { return 'pro' === $program ? array( $mp1_group ) : $groups; }, 10, 2 );
check( 'MP1-A3 wpac_program_groups maps an own key to groups', array( array( $mp1_pro ), array( $mp1_c_pro ) ), array( $mp1_ids( wpac_get_affiliates( array( 'program' => 'pro' ) ) ), $mp1_ids( wpac_get_commissions( array( 'program' => 'pro' ) ) ) ) );
remove_all_filters( 'wpac_program_groups' );
check( 'MP1-A3 without program argument: all (as before)', true, wpac_get_commissions( array( 'per_page' => 100, 'orderby' => 'id', 'order' => 'desc' ) )['total'] >= 2 );

/* ---------------------------------------------------------------------------------------------
 * A4: reports per program.
 * ------------------------------------------------------------------------------------------- */
$mp1_from = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$mp1_to   = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
$mp1_rep  = static function ( $rows ) {
	return array_map( 'intval', array_column( $rows, 'affiliate_id' ) );
};
$mp1_args = array();
add_filter( 'wpac_report_partners', static function ( $rows, $from, $to, $aff, $program = 'unset' ) use ( &$mp1_args ) { $mp1_args[] = $program; return $rows; }, 10, 5 );
$mp1_p_pro = $mp1_rep( Reports::partners( $mp1_from, $mp1_to, 0, $mp1_prog ) );
$mp1_p_std = $mp1_rep( Reports::partners( $mp1_from, $mp1_to, 0, '' ) );
$mp1_p_all = $mp1_rep( Reports::partners( $mp1_from, $mp1_to ) );
check( 'MP1-A4 partner report per program: school program only the school partner, default program without it, all with both', array( array( $mp1_pro ), true, false, true, true ), array( $mp1_p_pro, in_array( $mp1_std, $mp1_p_std, true ), in_array( $mp1_pro, $mp1_p_std, true ), in_array( $mp1_pro, $mp1_p_all, true ), in_array( $mp1_std, $mp1_p_all, true ) ) );
check( 'MP1-A4 wpac_report_partners receives the program (null = all)', array( $mp1_prog, '', null ), $mp1_args );
remove_all_filters( 'wpac_report_partners' );
$mp1_d_pro = Reports::by_dimension( 'campaign', $mp1_from, $mp1_to, $mp1_prog );
check( 'MP1-A4 campaign report per program: only rows of the program\'s partners', array( array( $mp1_pro ), true ), array( array_values( array_unique( $mp1_rep( $mp1_d_pro ) ) ), in_array( 'herbst', array_column( $mp1_d_pro, 'key' ), true ) ) );
$mp1_d_content = Reports::by_dimension( 'content_id', $mp1_from, $mp1_to, 'pro' );
check( 'MP1-A4 content report of an unknown program is empty', array(), $mp1_d_content );
check( 'MP1-A4 cached report per program has its own cache entry', array( array( $mp1_pro ), true ), array( $mp1_rep( Reports::cached( 'partners', $mp1_from, $mp1_to, $mp1_prog ) ), in_array( $mp1_std, $mp1_rep( Reports::cached( 'partners', $mp1_from, $mp1_to ) ), true ) ) );
check( 'MP1-A4 cache names differ per program', true, Reports::cache_name( 'partners', $mp1_from, $mp1_to ) !== Reports::cache_name( 'partners', $mp1_from, $mp1_to, $mp1_prog ) && Reports::cache_name( 'partners', $mp1_from, $mp1_to, '' ) !== Reports::cache_name( 'partners', $mp1_from, $mp1_to, $mp1_prog ) );

// Program selector of the dashboard: empty without a callback; only offered keys are accepted.
$mp1_pick = new ReflectionMethod( \WPAffiliateCaptain\Admin\Pages\DashboardPage::class, 'program' );
$mp1_pick->setAccessible( true );
check( 'MP1-A4 without wpac_report_programs no selector and no program', array( array(), null ), array( Programs::report_choices(), $mp1_pick->invoke( null, 'p:' . $mp1_prog ) ) );
add_filter( 'wpac_report_programs', static function () use ( $mp1_prog ) { return array( '' => 'Standard', $mp1_prog => 'Schulen', 'bad key' => 'X', 'leer' => '' ); } );
check( 'MP1-A4 wpac_report_programs: valid keys with a label', array( '' => 'Standard', $mp1_prog => 'Schulen' ), Programs::report_choices() );
check( 'MP1-A4 selector value: offered program, default program, not offered, all', array( $mp1_prog, '', null, null ), array( $mp1_pick->invoke( null, 'p:' . $mp1_prog ), $mp1_pick->invoke( null, 'p:' ), $mp1_pick->invoke( null, 'p:group:999' ), $mp1_pick->invoke( null, '' ) ) );
remove_all_filters( 'wpac_report_programs' );

/* ---------------------------------------------------------------------------------------------
 * A5: partner area.
 * ------------------------------------------------------------------------------------------- */
$mp1_cond = Portal::conditions( AffiliateRepository::find( $mp1_pro ) );
check( 'MP1-A5 conditions of the school partner: own rate, cookie days, hold days, minimum; no program line without the filter', array( true, true, true, true, false ), array( false !== strpos( (string) $mp1_cond['Your commission'], '20' ), false !== strpos( (string) $mp1_cond['Referral valid for'], (string) $mp1_own['cookie_days'] ), false !== strpos( (string) $mp1_cond['Hold period'], '45' ), false !== strpos( (string) $mp1_cond['Minimum payout amount'], '10' ), isset( $mp1_cond['Partner program'] ) ) );
add_filter( 'wpac_portal_program_label', static function ( $label, $program ) use ( $mp1_prog ) { return $mp1_prog === $program ? '<b>Schulprogramm</b>' : $label; }, 10, 2 );
$mp1_cond = Portal::conditions( AffiliateRepository::find( $mp1_pro ) );
check( 'MP1-A5 wpac_portal_program_label: program line (text only); none for the default program', array( 'Schulprogramm', false ), array( isset( $mp1_cond['Partner program'] ) ? $mp1_cond['Partner program'] : '', isset( Portal::conditions( AffiliateRepository::find( $mp1_std ) )['Partner program'] ) ) );
remove_all_filters( 'wpac_portal_program_label' );
$mp1_labels = new ReflectionMethod( Portal::class, 'labels' );
$mp1_labels->setAccessible( true );
add_filter( 'wpac_portal_labels', static function ( $labels, $aff, $program = 'unset' ) { return array( 'nav' => 'Programm ' . $program ); }, 10, 3 );
check( 'MP1-A5 wpac_portal_labels receives the program as third parameter', array( 'nav' => 'Programm ' . $mp1_prog ), $mp1_labels->invoke( null, AffiliateRepository::find( $mp1_pro ) ) );
remove_all_filters( 'wpac_portal_labels' );

/* ---------------------------------------------------------------------------------------------
 * A6: program change.
 * ------------------------------------------------------------------------------------------- */
$mp1_changes = array();
add_action( 'wpac_affiliate_program_changed', static function ( $aff, $from, $to ) use ( &$mp1_changes ) { $mp1_changes[] = array( $aff, $from, $to ); }, 10, 3 );
wpac_update_affiliate( $mp1_pro, array( 'name' => 'MP1 Schule neu' ) );
wpac_update_affiliate( $mp1_pro, array( 'group' => $mp1_group ) );
check( 'MP1-A6 no event without a group change', array(), $mp1_changes );
check( 'MP1-A6 group change via wpac_update_affiliate()', 'ok', $mp1_code( wpac_update_affiliate( $mp1_pro, array( 'group' => $mp1_group2 ) ) ) );
check( 'MP1-A6 wpac_affiliate_program_changed( partner, from, to ) after the change', array( array( $mp1_pro, $mp1_prog, 'group:' . $mp1_group2 ) ), $mp1_changes );
check( 'MP1-A6 the commission now reports the new program (variant A: current program)', 'group:' . $mp1_group2, wpac_get_commission( $mp1_c_pro )['program'] );
check( 'MP1-A6 the second program\'s values no longer apply after the change', $mp1_plain['hold_days'], Settings::for_affiliate( 'hold_days', $mp1_pro ) );
$mp1_changes = array();
Groups::delete( $mp1_group2 );
check( 'MP1-A6 deleting the group moves its partners to the default program (event)', array( array( $mp1_pro, 'group:' . $mp1_group2, '' ) ), $mp1_changes );
$mp1_changes = array();
AffiliateRepository::update( $mp1_pro, array( 'group_id' => $mp1_group ) );
check( 'MP1-A6 group change in the admin (AffiliateRepository::update) fires the event', array( array( $mp1_pro, '', $mp1_prog ) ), $mp1_changes );
$mp1_changes = array();
add_filter( 'wpac_affiliate_program', static function () { return 'alle'; } );
AffiliateRepository::update( $mp1_pro, array( 'group_id' => 0 ) );
check( 'MP1-A6 no event when the program key stays the same (filter maps both groups to one program)', array(), $mp1_changes );
remove_all_filters( 'wpac_affiliate_program' );
AffiliateRepository::update( $mp1_pro, array( 'group_id' => $mp1_group ) );
check( 'MP1-A6 new partners fire no event', array(), ( static function () use ( &$mp1_changes, $mp1_partner, $mp1_group ) { $mp1_changes = array(); $mp1_partner( 'neu', $mp1_group ); return $mp1_changes; } )() );

/* ---------------------------------------------------------------------------------------------
 * Clean-up.
 * ------------------------------------------------------------------------------------------- */
foreach ( array( 'wpac_setting', 'wpac_affiliate_program', 'wpac_program_groups', 'wpac_report_programs', 'wpac_portal_program_label', 'wpac_affiliate_program_changed', 'wpac_report_partners', 'wpac_portal_labels' ) as $mp1_hook ) {
	remove_all_filters( $mp1_hook );
}
check( 'MP1 after removing the callbacks the school partner uses the stored values again', array( RuleRepository::default_rate(), $mp1_plain['hold_days'] ), array( RuleRepository::default_rate( $mp1_pro ), Settings::for_affiliate( 'hold_days', $mp1_pro ) ) );
if ( $mp1_rules ) {
	$wpdb->query( 'UPDATE ' . t( 'rules' ) . ' SET active = 1 WHERE id IN (' . implode( ',', $mp1_rules ) . ')' );
}
