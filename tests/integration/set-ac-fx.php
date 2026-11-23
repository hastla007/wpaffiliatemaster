<?php
/**
 * SET-AC-13 (rest: dunning stages and payment deadlines as settings) and SET-AC-29 (exchange rates
 * as a table with "as of" date and minimum payout per currency). Included from run.php right
 * before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Fx;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC-13 dunning settings / SET-AC-29 exchange rate table\n";

$fx_saved = get_option( 'wpac_settings' );
$fx_set   = static function ( array $values ) use ( $fx_saved ) {
	update_option( 'wpac_settings', array_merge( is_array( $fx_saved ) ? $fx_saved : array(), $values ) );
	Settings::flush_cache();
};
SettingsSchema::flush();
$fx_today = wp_date( 'Y-m-d' );

// ---------------------------------------------------------------- SET-AC-13: dunning.
$fx_def = SettingsSchema::defaults();
$fx_reg = array();
foreach ( array( 'dunning_levels', 'dunning_days', 'dunning_final_days' ) as $fx_k ) {
	$fx_f            = SettingsSchema::field( $fx_k );
	$fx_reg[ $fx_k ] = array( $fx_def[ $fx_k ], $fx_f['min'], $fx_f['max'], ! empty( $fx_f['legal'] ), $fx_f['tab'] );
}
check(
	'SET-AC-13 register: defaults = former fixed values (3 stages, 14 / 7 days), limits, marked "check legally"',
	array( 'dunning_levels' => array( 3, 1, 5, true, 'payouts' ), 'dunning_days' => array( 14, 7, 60, true, 'payouts' ), 'dunning_final_days' => array( 7, 7, 60, true, 'payouts' ) ),
	$fx_reg
);
check( 'SET-AC-13 the deprecated constant keeps the former value', 3, Dunning::LEVELS );

$fx_set( array( 'dunning_levels' => 3, 'dunning_days' => 14, 'dunning_final_days' => 7 ) );
$fx_c = SettingsTransfer::check( array( 'dunning_levels' => '0', 'dunning_days' => '6', 'dunning_final_days' => '61' ), Settings::all() );
$fx_c2 = SettingsTransfer::check( array( 'dunning_levels' => '6' ), Settings::all() );
$fx_c3 = SettingsTransfer::check( array( 'dunning_levels' => '5', 'dunning_days' => '30', 'dunning_final_days' => '10' ), Settings::all() );
check(
	'SET-AC-13 limits: 0 / 6 stages, 6 and 61 days rejected; valid values accepted',
	array( true, true, true, true, array(), array( 'dunning_levels' => 5, 'dunning_days' => 30, 'dunning_final_days' => 10 ) ),
	array( isset( $fx_c['errors']['dunning_levels'] ), isset( $fx_c['errors']['dunning_days'] ), isset( $fx_c['errors']['dunning_final_days'] ), isset( $fx_c2['errors']['dunning_levels'] ), $fx_c['values'], $fx_c3['values'] )
);
check( 'SET-AC-13 a reset of the Payouts tab keeps the dunning values (legal)', array(), array_values( array_intersect( SettingsTransfer::reset_keys( 'payouts' ), array( 'dunning_levels', 'dunning_days', 'dunning_final_days' ) ) ) );

// Partner with a negative balance after the partnership ended.
global $wpdb;
$fx_ct   = Db::table( 'commissions' );
$fx_new  = static function ( $tag ) use ( $wpdb, $fx_ct ) {
	$aff = AffiliateRepository::create( array( 'name' => 'Dunning ' . $tag, 'email' => 'dun-' . $tag . '@example.de', 'status' => 'active' ) );
	$wpdb->insert( $fx_ct, array( 'affiliate_id' => $aff, 'source' => 'test', 'source_order_id' => 'fxd-' . $tag, 'kind' => 'refund', 'currency' => 'EUR', 'amount_minor' => -2500, 'status' => 'approved', 'idempotency_key' => 'fxd-' . $tag, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
	WPAffiliateCaptain\Commissions\Balance::post( $aff, 'EUR', 'approved', -2500, (int) $wpdb->insert_id, 0, 'test' );
	AffiliateRepository::set_status( $aff, 'terminated', 'Ende' );
	return $aff;
};
$fx_bank = array( 'debtor_name' => 'Firma GmbH', 'debtor_iban' => 'DE89370400440532013000' );
$fx_send = static function ( $aff, $times ) {
	$out = array();
	for ( $i = 0; $i < $times; $i++ ) {
		$GLOBALS['test_mails'] = array();
		$r     = Dunning::remind( $aff, 'EUR' );
		$out[] = is_wp_error( $r ) ? $r->get_error_code() : array( $r, isset( $GLOBALS['test_mails'][0] ) ? $GLOBALS['test_mails'][0][1] : '' );
	}
	return $out;
};
$fx_days = static function ( $aff ) {
	return array_map( static function ( $h ) { return (int) $h['days']; }, Dunning::history( $aff, 'EUR' ) );
};

// Defaults: exactly as before (request 14 days, reminder 14 days, final reminder 7 days, then done).
$fx_set( $fx_bank + array( 'dunning_levels' => 3, 'dunning_days' => 14, 'dunning_final_days' => 7 ) );
$fx_a = $fx_new( 'a' );
$fx_r = $fx_send( $fx_a, 4 );
check(
	'SET-AC-13 defaults: request, reminder, final reminder with 14 / 14 / 7 days, then no more (as before)',
	array( array( 1, 'repayment of commissions' ), array( 2, 'Reminder:' ), array( 3, 'Final reminder:' ), 'wpac_dunning_done', array( 14, 14, 7 ) ),
	array( array( $fx_r[0][0], false !== strpos( $fx_r[0][1], '] Repayment of commissions' ) ? 'repayment of commissions' : $fx_r[0][1] ), array( $fx_r[1][0], false !== strpos( $fx_r[1][1], 'Reminder:' ) && false === strpos( $fx_r[1][1], 'Final' ) ? 'Reminder:' : $fx_r[1][1] ), array( $fx_r[2][0], false !== strpos( $fx_r[2][1], 'Final reminder:' ) ? 'Final reminder:' : $fx_r[2][1] ), $fx_r[3], $fx_days( $fx_a ) )
);
$fx_h = Dunning::history( $fx_a, 'EUR' );
check( 'SET-AC-13 end of the payment deadline = sent + days; old entries without days have none', array( gmdate( 'Y-m-d H:i:s', strtotime( $fx_h[0]['at'] . ' UTC' ) + 14 * DAY_IN_SECONDS ), '' ), array( Dunning::deadline_end( $fx_h[0] ), Dunning::deadline_end( array( 'level' => 1, 'at' => Db::now(), 'amount' => -1 ) ) ) );

// Two stages, own deadlines.
$fx_set( $fx_bank + array( 'dunning_levels' => 2, 'dunning_days' => 21, 'dunning_final_days' => 10 ) );
$fx_b = $fx_new( 'b' );
$fx_r = $fx_send( $fx_b, 3 );
check(
	'SET-AC-13 two stages: request (21 days), final reminder (10 days), then done',
	array( 1, 2, true, 'wpac_dunning_done', array( 21, 10 ) ),
	array( $fx_r[0][0], $fx_r[1][0], false !== strpos( $fx_r[1][1], 'Final reminder:' ), $fx_r[2], $fx_days( $fx_b ) )
);

// One stage: only the payment request, with the deadline of the payment request.
$fx_set( $fx_bank + array( 'dunning_levels' => 1, 'dunning_days' => 30, 'dunning_final_days' => 7 ) );
$fx_c = $fx_new( 'c' );
$fx_r = $fx_send( $fx_c, 2 );
check( 'SET-AC-13 one stage: payment request with its own deadline, then done', array( 1, true, 'wpac_dunning_done', array( 30 ) ), array( $fx_r[0][0], false === strpos( $fx_r[0][1], 'reminder' ), $fx_r[1], $fx_days( $fx_c ) ) );

// Five stages: reminders in between; fewer stages later = done.
$fx_set( $fx_bank + array( 'dunning_levels' => 5, 'dunning_days' => 14, 'dunning_final_days' => 7 ) );
check( 'SET-AC-13 five stages: request, three reminders, final reminder', array( 'request', 'reminder', 'reminder', 'reminder', 'final' ), array_map( array( Dunning::class, 'stage' ), array( 1, 2, 3, 4, 5 ) ) );
$fx_d = $fx_new( 'd' );
$fx_send( $fx_d, 2 );
$fx_set( $fx_bank + array( 'dunning_levels' => 2, 'dunning_days' => 14, 'dunning_final_days' => 7 ) );
check( 'SET-AC-13 stages reduced below the sent ones: no further request', array( 'wpac_dunning_done' ), $fx_send( $fx_d, 1 ) );
$fx_set( $fx_bank + array( 'dunning_levels' => 3, 'dunning_days' => 14, 'dunning_final_days' => 7 ) );
$fx_e = $fx_new( 'e' );
$fx_send( $fx_e, 1 );
$fx_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$fx_bal = new ReflectionMethod( WPAffiliateCaptain\Admin\Pages\AffiliatesPage::class, 'render_balance' );
$fx_bal->setAccessible( true );
ob_start();
$fx_bal->invoke( null, $fx_e );
$fx_html = (string) ob_get_clean();
if ( null !== $fx_caps ) {
	$GLOBALS['test_caps'] = $fx_caps;
}
check( 'SET-AC-13 partner page: sent stage with the end of its payment deadline, hint to wait, button for stage 2', array( true, true, true ), array( false !== strpos( $fx_html, 'payment deadline until' ), false !== strpos( $fx_html, 'wpac-dunning-wait' ), false !== strpos( $fx_html, 'Send payment request (level 2)' ) ) );
$fx_set( $fx_bank + array( 'dunning_levels' => 99 ) );
check( 'SET-AC-13 an out-of-range stored value is clamped to 1…5', 5, Dunning::levels() );

// ---------------------------------------------------------------- SET-AC-29: table helpers.
check(
	'SET-AC-29 table rows → stored texts (empty rows skipped, minimum without rate, lower-case code)',
	array( "USD=0.92\nCHF=1,05", "CHF=45\nGBP=40" ),
	Fx::rows_to_text( array( array( 'currency' => 'usd', 'rate' => '0.92', 'min' => '' ), array( 'currency' => '', 'rate' => '', 'min' => '' ), array( 'currency' => 'CHF', 'rate' => '1,05', 'min' => '45' ), array( 'currency' => 'GBP', 'rate' => '', 'min' => '40' ), 'junk' ) )
);
check( 'SET-AC-29 a row with a currency but no values becomes a line the check rejects', array( 'JPY=', '' ), Fx::rows_to_text( array( array( 'currency' => 'JPY' ) ) ) );
check(
	'SET-AC-29 stored texts → table rows (rates first, then minimum-only currencies)',
	array( array( 'currency' => 'USD', 'rate' => '0.92', 'min' => '50.00' ), array( 'currency' => 'CHF', 'rate' => '1.05', 'min' => '' ), array( 'currency' => 'GBP', 'rate' => '', 'min' => '40.00' ) ),
	Fx::text_to_rows( "USD=0.92\nCHF=1.05", "GBP=40.00\nUSD=50.00" )
);
check( 'SET-AC-29 minimums parsed in minor units of their currency (array form of wpac_setting too)', array( array( 'USD' => 5000, 'JPY' => 1500 ), array( 'CHF' => 4550 ) ), array( Fx::parse_minimums( "USD=50.00\njpy=1500\nbad" ), Fx::parse_minimums( array( 'CHF' => '45.50' ) ) ) );

// ---------------------------------------------------------------- SET-AC-29: date and minimums (sanitize).
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.92', 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => '', 'min_payout_minor' => 5000 ) );
$fx_s1 = SettingsTransfer::check( array( 'exchange_rates' => "USD=0.92\nCHF=1.05" ), Settings::all() );
$fx_s2 = SettingsTransfer::check( array( 'exchange_rates' => 'USD=0.92' ), Settings::all() );
$fx_s3 = SettingsTransfer::check( array( 'exchange_rates' => '' ), Settings::all() );
$fx_s4 = SettingsTransfer::check( array( 'exchange_rates' => 'USD=0.95', 'exchange_rates_date' => '2026-02-01' ), Settings::all() );
$fx_s5 = SettingsTransfer::check( array( 'exchange_rates_date' => '2099-01-01' ), Settings::all() );
$fx_s6 = SettingsTransfer::check( array( 'exchange_rates_date' => '2026-02-30' ), Settings::all() );
check(
	'SET-AC-29 "as of": changed rates → today, unchanged → kept, no rates → empty, date given with the rates (import) kept, future / invalid dates rejected',
	array( $fx_today, array(), '', '2026-02-01', true, true ),
	array( $fx_s1['values']['exchange_rates_date'] ?? null, $fx_s2['values'], $fx_s3['values']['exchange_rates_date'] ?? null, $fx_s4['values']['exchange_rates_date'] ?? null, isset( $fx_s5['errors']['exchange_rates_date'] ), isset( $fx_s6['errors']['exchange_rates_date'] ) )
);
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.92', 'exchange_rates_date' => '' ) );
$fx_s7 = Settings::sanitize( array( 'exchange_rates' => 'USD=0.92', 'exchange_rates_date' => '' ) );
check( 'SET-AC-29 rates of an older version (no date) keep an unknown date until they change', '', $fx_s7['exchange_rates_date'] );

$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.92', 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => 'USD=40.00' ) );
$fx_m1 = SettingsTransfer::check( array( 'min_payout_currency' => "usd=50,00\nJPY=1.500\nGBP: 25" ), Settings::all() );
$fx_m2 = SettingsTransfer::check( array( 'min_payout_currency' => "USD=abc\nCHF=-5" ), Settings::all() );
$fx_m3 = Settings::sanitize( array_merge( Settings::all(), array( 'min_payout_currency' => "EUR=20\nUSD=50" ) ), $fx_m3e, $fx_m3n );
check(
	'SET-AC-29 minimum per currency: normalised, invalid lines rejected (old value kept), program currency dropped with a notice',
	array( "USD=50.00\nJPY=1500\nGBP=25.00", true, 'USD=40.00', 'USD=50.00', true ),
	array( $fx_m1['values']['min_payout_currency'] ?? null, isset( $fx_m2['errors']['min_payout_currency'] ), Settings::sanitize( array_merge( Settings::all(), array( 'min_payout_currency' => 'USD=abc' ) ) )['min_payout_currency'], $fx_m3['min_payout_currency'], isset( $fx_m3n['min_payout_currency'] ) )
);
check( 'SET-AC-29 a rates-only change does not touch the minimums; the date follows only the rates', array( false, false ), array( isset( $fx_m1['values']['exchange_rates_date'] ), isset( $fx_s1['values']['min_payout_currency'] ) ) );

// Currency change: rebased rates keep their date; a minimum of the new program currency is dropped.
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => "USD=0.92\nCHF=1.05", 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => "USD=50.00\nCHF=45.00", 'min_payout_minor' => 5000 ) );
$fx_cc = SettingsTransfer::check( array( 'currency' => 'USD' ), Settings::all() );
check( 'SET-AC-29 currency change: rates rebased, date kept, minimum of the new program currency dropped', array( 'USD', true, false, 'CHF=45.00' ), array( $fx_cc['values']['currency'] ?? '', isset( $fx_cc['values']['exchange_rates'] ), isset( $fx_cc['values']['exchange_rates_date'] ), $fx_cc['values']['min_payout_currency'] ?? null ) );

// ---------------------------------------------------------------- SET-AC-29: Fx::min_payout().
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => "USD=0.5\nCHF=1", 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => "USD=30.00\nGBP=20.00", 'min_payout_minor' => 5000 ) );
check(
	'SET-AC-29 min_payout(): program currency = program minimum, own minimum (also without a rate), else converted, else null',
	array( 5000, 3000, 2000, 5000, null ),
	array( Fx::min_payout( 'EUR' ), Fx::min_payout( 'USD' ), Fx::min_payout( 'GBP' ), Fx::min_payout( 'CHF' ), Fx::min_payout( 'SEK' ) )
);
check( 'SET-AC-29 sources of the minimum', array( 'program', 'currency', 'converted', 'none' ), array( Fx::min_payout_info( 'EUR' )['source'], Fx::min_payout_info( 'USD' )['source'], Fx::min_payout_info( 'CHF' )['source'], Fx::min_payout_info( 'SEK' )['source'] ) );
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.5', 'exchange_rates_date' => '', 'min_payout_currency' => '', 'min_payout_minor' => 5000 ) );
check( 'SET-AC-29 existing sites (no minimums per currency): unchanged conversion', array( 10000, 5000 ), array( Fx::min_payout( 'USD' ), Fx::min_payout( 'EUR' ) ) );

// Multi-program filter wpac_setting: a program with its own program minimum but no own minimums
// per currency gets its minimum converted; an add-on can also give a program own per-currency values.
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.5', 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => 'USD=30.00', 'min_payout_minor' => 5000 ) );
$fx_pa = AffiliateRepository::create( array( 'name' => 'Program A', 'email' => 'fx-pa@example.de', 'status' => 'active' ) );
$fx_pb = AffiliateRepository::create( array( 'name' => 'Program B', 'email' => 'fx-pb@example.de', 'status' => 'active' ) );
$fx_pc = AffiliateRepository::create( array( 'name' => 'Program C', 'email' => 'fx-pc@example.de', 'status' => 'active' ) );
$fx_filter = static function ( $value, $key, $context ) use ( $fx_pa, $fx_pb ) {
	if ( (int) $context['affiliate_id'] === (int) $fx_pa && 'min_payout_minor' === $key ) {
		return 2000; // Program A: own program minimum, no own per-currency values.
	}
	if ( (int) $context['affiliate_id'] === (int) $fx_pb && 'min_payout_currency' === $key ) {
		return array( 'USD' => '15' ); // Program B: own minimum in USD.
	}
	if ( 'schools' === $context['program'] && 'min_payout_minor' === $key ) {
		return 1000;
	}
	return $value;
};
add_filter( 'wpac_setting', $fx_filter, 10, 3 );
check(
	'SET-AC-29 wpac_setting: own program minimum → converted (not the general USD value); own per-currency value → used; other partners → general USD value; program context too',
	array( 4000, 1500, 3000, 3000, 2000, 'converted' ),
	array( Fx::min_payout( 'USD', $fx_pa ), Fx::min_payout( 'USD', $fx_pb ), Fx::min_payout( 'USD', $fx_pc ), Fx::min_payout( 'USD' ), Fx::min_payout_program( 'USD', 'schools' ), Fx::min_payout_info( 'USD', array( 'affiliate_id' => $fx_pa ) )['source'] )
);
remove_all_filters( 'wpac_setting' );

// Display: converted minimums and reports name the date of the rates.
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'CHF=1', 'exchange_rates_date' => '2026-01-15', 'min_payout_currency' => 'USD=30.00', 'min_payout_minor' => 5000 ) );
$fx_note = Fx::rates_note();
check(
	'SET-AC-29 "as of" shown with converted minimums, not with an own minimum; empty without a date',
	array( true, true, false ),
	array( '' !== $fx_note && false !== strpos( $fx_note, 'Reference exchange rates as of' ), false !== strpos( WPAffiliateCaptain\Admin\Pages\PayoutsPage::min_text( 'CHF' ), $fx_note ), false !== strpos( WPAffiliateCaptain\Admin\Pages\PayoutsPage::min_text( 'USD' ), 'converted' ) )
);
$fx_set( array( 'exchange_rates' => 'CHF=1', 'exchange_rates_date' => '' ) );
check( 'SET-AC-29 no note without a date (rates of older versions)', '', Fx::rates_note() );

// ---------------------------------------------------------------- SET-AC-29: export / import.
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => "USD=0.92\nCHF=1.05", 'exchange_rates_date' => '2026-03-01', 'min_payout_currency' => 'USD=50.00', 'min_payout_minor' => 5000 ) );
$fx_exp = SettingsTransfer::export();
check( 'SET-AC-29 export contains rates, their date and the minimums per currency', array( "USD=0.92\nCHF=1.05", '2026-03-01', 'USD=50.00' ), array( $fx_exp['settings']['exchange_rates'] ?? null, $fx_exp['settings']['exchange_rates_date'] ?? null, $fx_exp['settings']['min_payout_currency'] ?? null ) );
$fx_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.80', 'exchange_rates_date' => '2025-12-01', 'min_payout_currency' => '', 'min_payout_minor' => 5000 ) );
$fx_imp = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array_intersect_key( $fx_exp['settings'], array_flip( array( 'exchange_rates', 'exchange_rates_date', 'min_payout_currency' ) ) ) ) );
SettingsTransfer::apply( $fx_imp, 'settings.imported' );
check( 'SET-AC-29 import brings rates, date and minimums along unchanged (lossless round trip)', array( array(), "USD=0.92\nCHF=1.05", '2026-03-01', 'USD=50.00' ), array( $fx_imp['errors'], Settings::get( 'exchange_rates' ), Settings::get( 'exchange_rates_date' ), Settings::get( 'min_payout_currency' ) ) );
$fx_set( array( 'exchange_rates' => 'USD=0.80', 'exchange_rates_date' => '2025-12-01' ) );
$fx_old = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'exchange_rates' => "USD=0.9\nCHF=1.1" ) ) );
check( 'SET-AC-29 import of an export of 0.20 (rates only): rates taken, date = today', array( "USD=0.9\nCHF=1.1", $fx_today ), array( $fx_old['values']['exchange_rates'] ?? null, $fx_old['values']['exchange_rates_date'] ?? null ) );
$fx_bad = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'min_payout_currency' => 'USD=x' ) ) );
check( 'SET-AC-29 import of an invalid minimum is reported, nothing changed', array( true, array() ), array( isset( $fx_bad['errors']['min_payout_currency'] ), $fx_bad['values'] ) );
$fx_set( array( 'exchange_rates' => 'USD=0.80', 'exchange_rates_date' => '2025-12-01', 'min_payout_currency' => 'USD=50.00' ) );
$fx_rst = SettingsTransfer::reset_preview( SettingsTransfer::reset_keys( 'commissions' ) );
check( 'SET-AC-29 reset of the Commissions tab clears rates, date and minimums together', array( '', '', '' ), array( $fx_rst['values']['exchange_rates'] ?? null, $fx_rst['values']['exchange_rates_date'] ?? null, $fx_rst['values']['min_payout_currency'] ?? null ) );

$fx_set( array() );
SettingsSchema::flush();
