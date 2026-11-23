<?php
/**
 * SET-AC-19 / SET-AC-31 (owner decision E5 a): payout reminder (only a mail, no booking),
 * maximum payout per partner and currency (the rest stays payable), the former key
 * payout_period converted on upgrade and mapped on import. Included from run.php before
 * r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;
use WPAffiliateCaptain\Payouts\PayoutReminder;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "SET-AC-19/31 payout reminder, maximum payout, payout_period migration\n";
global $wpdb;
$pay_keep = get_option( 'wpac_settings' );
SettingsSchema::flush();
$pay_set = static function ( array $values ) use ( &$pay_keep ) {
	update_option( 'wpac_settings', array_merge( $pay_keep, $values ) );
	Settings::flush_cache();
};

// --- Register ---------------------------------------------------------------------------------
$pay_d = SettingsSchema::defaults();
check( 'SET-AC-19 defaults: reminder off, day 1, no maximum; payout_period gone (SET-AC-31)', array( 'off', 1, 0, false ), array( $pay_d['payout_reminder'], $pay_d['payout_reminder_day'], $pay_d['max_payout_minor'], array_key_exists( 'payout_period', $pay_d ) ) );
check( 'SET-AC-19 register: choices, day 1–28, all on the payouts tab', array( array( 'off', 'monthly', 'quarterly' ), 1, 28, array( 'payouts', 'payouts', 'payouts' ) ), array( SettingsSchema::field( 'payout_reminder' )['options'], SettingsSchema::int_min()['payout_reminder_day'], SettingsSchema::int_max()['payout_reminder_day'], array( SettingsSchema::field( 'payout_reminder' )['tab'], SettingsSchema::field( 'payout_reminder_day' )['tab'], SettingsSchema::field( 'max_payout_minor' )['tab'] ) ) );
check( 'SET-AC-31 payout_period nowhere in the code any more (settings, schema, monitor)', array( false, false, false ), array(
	false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Core/SettingsSchema.php' ), "'payout_period'" ),
	false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Core/Monitor.php' ), 'payout_period' ),
	false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Admin/Pages/SettingsPage.php' ), "'payout_period'" ),
) );

// --- Migration (upgrade): payout_period → payout_reminder ------------------------------------
$pay_old = $pay_keep;
unset( $pay_old['payout_reminder'], $pay_old['payout_reminder_day'], $pay_old['max_payout_minor'] );
$pay_old['payout_period'] = 'quarterly';
$pay_old['hold_days']     = 21;
unset( $GLOBALS['test_options'][ Settings::LEGACY_PAYOUT_PERIOD ] );
update_option( 'wpac_settings', $pay_old );
Settings::flush_cache();
$pay_all    = Settings::all();
$pay_stored = get_option( 'wpac_settings' );
check(
	'SET-AC-19 migration: payout_period (never had an effect) becomes reminder "off", is removed from the stored settings, the former value is kept as a note; other values unchanged',
	array( 'off', false, false, 'quarterly', 21, 'off' ),
	array( $pay_all['payout_reminder'], array_key_exists( 'payout_period', $pay_all ), array_key_exists( 'payout_period', $pay_stored ), get_option( Settings::LEGACY_PAYOUT_PERIOD ), (int) $pay_stored['hold_days'], $pay_stored['payout_reminder'] )
);
Settings::flush_cache();
check( 'SET-AC-19 migration runs once (second read changes nothing)', $pay_stored, ( Settings::all() && true ) ? get_option( 'wpac_settings' ) : null );
$pay_old2                    = $pay_old;
$pay_old2['payout_period']   = 'monthly';
$pay_old2['payout_reminder'] = 'monthly';
update_option( 'wpac_settings', $pay_old2 );
Settings::flush_cache();
check( 'SET-AC-19 migration keeps a reminder that is already set and does not overwrite the note', array( 'monthly', false, 'quarterly' ), array( Settings::get( 'payout_reminder' ), array_key_exists( 'payout_period', get_option( 'wpac_settings' ) ), get_option( Settings::LEGACY_PAYOUT_PERIOD ) ) );
unset( $GLOBALS['test_options'][ Settings::LEGACY_PAYOUT_PERIOD ] );

// --- Import of an old export with payout_period (SET-AC-31) ----------------------------------
$pay_set( array( 'payout_reminder' => 'monthly', 'payout_reminder_day' => 10 ) );
$pay_p1 = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'payout_period' => 'monthly', 'hold_days' => 14 ) ) );
check( 'SET-AC-31 import of an old export: payout_period accepted (not "ignored") and mapped to reminder off; other keys imported', array( false, array( 'monthly', 'off' ), 14, array() ), array( in_array( 'payout_period', $pay_p1['ignored'], true ), $pay_p1['changes']['payout_reminder'], $pay_p1['values']['hold_days'], $pay_p1['errors'] ) );
$pay_p2 = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'payout_period' => 'manual', 'payout_reminder' => 'quarterly' ) ) );
check( 'SET-AC-31 import: a file naming payout_reminder itself keeps its value', array( 'quarterly' ), array( $pay_p2['values']['payout_reminder'] ) );
check( 'SET-AC-31 export no longer contains payout_period, but the new keys', array( false, true, true, true ), array( array_key_exists( 'payout_period', SettingsTransfer::export()['settings'] ), array_key_exists( 'payout_reminder', SettingsTransfer::export()['settings'] ), array_key_exists( 'payout_reminder_day', SettingsTransfer::export()['settings'] ), array_key_exists( 'max_payout_minor', SettingsTransfer::export()['settings'] ) ) );

// --- Sanitize: day limits, maximum ≥ minimum --------------------------------------------------
$pay_set( array( 'min_payout_minor' => 1000, 'max_payout_minor' => 0, 'currency' => 'EUR' ) );
$pay_c1 = SettingsTransfer::check( array( 'payout_reminder_day' => 29 ), Settings::all() );
$pay_c2 = SettingsTransfer::check( array( 'payout_reminder_day' => 0 ), Settings::all() );
$pay_c3 = SettingsTransfer::check( array( 'max_payout_minor' => 500 ), Settings::all() );
$pay_c4 = SettingsTransfer::check( array( 'max_payout_minor' => 250000 ), Settings::all() );
$pay_c5 = SettingsTransfer::check( array( 'payout_reminder' => 'weekly' ), Settings::all() );
check( 'SET-AC-19 day 29 and 0 rejected, maximum below the minimum rejected, 2,500.00 accepted, unknown rhythm rejected', array( true, true, true, array( 'max_payout_minor' => 250000 ), true ), array( isset( $pay_c1['errors']['payout_reminder_day'] ), isset( $pay_c2['errors']['payout_reminder_day'] ), isset( $pay_c3['errors']['max_payout_minor'] ), $pay_c4['values'], isset( $pay_c5['errors']['payout_reminder'] ) ) );
$pay_set( array( 'min_payout_minor' => 1000, 'max_payout_minor' => 2000 ) );
$pay_c6 = Settings::sanitize( array( 'min_payout' => '30', 'max_payout' => '20' ), $pay_e6 );
check( 'SET-AC-19 minimum raised above the stored maximum: both keep their values', array( 1000, 2000, true ), array( $pay_c6['min_payout_minor'], $pay_c6['max_payout_minor'], isset( $pay_e6['min_payout'] ) || isset( $pay_e6['max_payout'] ) ) );
$pay_set( array( 'currency' => 'EUR', 'exchange_rates' => 'USD=0.5', 'min_payout_minor' => 1000, 'max_payout_minor' => 2000 ) );
$pay_c7 = SettingsTransfer::check( array( 'currency' => 'USD' ), Settings::all() );
check( 'SET-AC-19 a currency change converts the maximum like the minimum', array( 2000, 4000 ), array( $pay_c7['values']['min_payout_minor'] ?? null, $pay_c7['values']['max_payout_minor'] ?? null ) );

// --- Maximum payout: split per partner and currency -------------------------------------------
$pay_set( array( 'min_payout_minor' => 1000, 'max_payout_minor' => 0, 'currency' => 'EUR', 'exchange_rates' => '', 'admin_email' => 'ops@shop.example', 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Pay GmbH', 'vies_check' => 0, 'maintenance_mode' => 0, 'payout_reminder' => 'off', 'payout_requests' => 1, 'default_rate_type' => 'percent', 'default_rate' => '10' ) );
$pay_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'Pay ' . $slug, 'email' => 'pay-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'Pay ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'Pay ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$pay_earn = static function ( $aff, $order, $net ) use ( $wpdb ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	$cid = (int) $r['commission_ids'][0];
	// Distinct dates, oldest first (the order in which a capped payout pays them).
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'commissions' ) . ' SET created_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * ( 100 - (int) substr( (string) $order, -2 ) ) ), $cid ) );
	return $cid;
};
// Business partner in DE: 19 % VAT on top of each 10.00 commission → 11.90 gross each.
$pay_a  = $pay_partner( 'a', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789', 'tax_number' => '' ) );
$pay_a1 = $pay_earn( $pay_a, 'PAY-A-01', 10000 );
$pay_a2 = $pay_earn( $pay_a, 'PAY-A-02', 10000 );
$pay_a3 = $pay_earn( $pay_a, 'PAY-A-03', 10000 );
$pay_set( array( 'max_payout_minor' => 2500 ) ); // 25.00 €: two commissions (23.80) fit, three (35.70) do not.
$pay_row = null;
foreach ( PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' ) as $pay_r ) {
	if ( $pay_r['affiliate'] && (int) $pay_r['affiliate']->id === $pay_a ) {
		$pay_row = $pay_r;
	}
}
$pay_amounts = PayoutService::payable( $pay_a, 'EUR' );
check( 'SET-AC-19 preview and payable: full balance 35.70, one payout pays 23.80 (VAT included) and says so', array( true, 2380, true, 3570, 2380, 2380 ), array( $pay_row['eligible'], $pay_row['pay_minor'], $pay_row['capped'], $pay_amounts['gross'], $pay_amounts['payout_gross'], PayoutRequests::available( $pay_a, 'EUR' ) ) );
$pay_payouts_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) );
$pay_pid            = PayoutService::create_for_affiliate( $pay_a, gmdate( 'Y-m-d' ), 'EUR' );
$pay_p              = PayoutService::find( $pay_pid );
$pay_left           = PayoutService::payable( $pay_a, 'EUR' );
check(
	'SET-AC-19 payout run never pays more than the maximum: the two oldest commissions (23.80 gross), the newest stays payable',
	array( 2000, 2380, array( $pay_pid, $pay_pid, 0 ), 1, 1190 ),
	array( (int) $pay_p->net_minor, (int) $pay_p->amount_minor, array( (int) Ledger::find( $pay_a1 )->payout_id, (int) Ledger::find( $pay_a2 )->payout_id, (int) Ledger::find( $pay_a3 )->payout_id ), $pay_left['count'], $pay_left['gross'] )
);
check( 'SET-AC-19 audit marks the capped payout', true, false !== strpos( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'payout.created' AND object_id = %d ORDER BY id DESC LIMIT 1", $pay_pid ) ), 'capped' ) );

// One commission larger than the maximum: nothing is paid, the reason says why.
$pay_b  = $pay_partner( 'b', array( 'tax_status' => 'private', 'vat_id' => '', 'tax_number' => '' ) );
$pay_b1 = $pay_earn( $pay_b, 'PAY-B-04', 50000 ); // 50.00 > 25.00
$pay_brow = null;
foreach ( PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' ) as $pay_r ) {
	if ( $pay_r['affiliate'] && (int) $pay_r['affiliate']->id === $pay_b ) {
		$pay_brow = $pay_r;
	}
}
check( 'SET-AC-19 a single commission above the maximum: not eligible (code above_maximum), the payout pays nothing, the request is refused with a reason', array( false, 'above_maximum', 0, 0, true ), array( $pay_brow['eligible'], $pay_brow['code'], PayoutService::create_for_affiliate( $pay_b, gmdate( 'Y-m-d' ), 'EUR' ), (int) Ledger::find( $pay_b1 )->payout_id, is_string( PayoutRequests::can_request( AffiliateRepository::find( $pay_b ) ) ) ) );
$pay_set( array( 'max_payout_minor' => 0 ) );
check( 'SET-AC-19 maximum 0 = no limit: the whole balance is payable again', array( 5000, 5000 ), array( PayoutService::payable( $pay_b, 'EUR' )['payout_gross'], PayoutService::payable( $pay_b, 'EUR' )['gross'] ) );
// cap_rows(): negative rows are always included, positive rows oldest first.
$pay_rows  = array(
	(object) array( 'id' => 1, 'amount_minor' => 1000, 'created_at' => '2026-01-03 00:00:00' ),
	(object) array( 'id' => 2, 'amount_minor' => -300, 'created_at' => '2026-01-04 00:00:00' ),
	(object) array( 'id' => 3, 'amount_minor' => 1000, 'created_at' => '2026-01-01 00:00:00' ),
	(object) array( 'id' => 4, 'amount_minor' => 1000, 'created_at' => '2026-01-02 00:00:00' ),
);
$pay_treat = array_fill_keys( array( 1, 2, 3, 4 ), array( 'mode' => 'private', 'rate' => '0', 'ref' => 0 ) );
$pay_cap   = PayoutService::cap_rows( $pay_rows, $pay_treat, 2000 );
check( 'SET-AC-19 cap_rows: correction first, then the oldest commissions up to the maximum', array( array( 2, 3, 4 ), true ), array( wp_list_pluck( $pay_cap[0], 'id' ), $pay_cap[1] ) );
check( 'SET-AC-19 cap_rows without limit or within it: all rows, not capped', array( 4, false, 4, false ), array( count( PayoutService::cap_rows( $pay_rows, $pay_treat, 0 )[0] ), PayoutService::cap_rows( $pay_rows, $pay_treat, 0 )[1], count( PayoutService::cap_rows( $pay_rows, $pay_treat, 2700 )[0] ), PayoutService::cap_rows( $pay_rows, $pay_treat, 2700 )[1] ) );

// --- Reminder ---------------------------------------------------------------------------------
$pay_mails   = static function () {
	$n = 0;
	foreach ( (array) ( $GLOBALS['test_mails'] ?? array() ) as $m ) {
		$n += false !== strpos( (string) $m[1], 'Payout reminder' ) ? 1 : 0;
	}
	return $n;
};
$pay_snapshot = static function () use ( $wpdb ) {
	return array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE payout_id > 0' ), (int) $wpdb->get_var( 'SELECT COALESCE(SUM(balance_minor),0) FROM ' . t( 'balances' ) ) );
};
unset( $GLOBALS['test_options'][ PayoutReminder::SENT ] );
$pay_nov3  = gmmktime( 12, 0, 0, 11, 3, 2026 );
$pay_nov5  = gmmktime( 12, 0, 0, 11, 5, 2026 );
$pay_set( array( 'payout_reminder' => 'off', 'payout_reminder_day' => 5 ) );
$pay_m0 = $pay_mails();
check( 'SET-AC-19 reminder off: no period, nothing sent, no partner text', array( null, false, 0, '' ), array( PayoutReminder::period( $pay_nov5 ), PayoutReminder::send_due( $pay_nov5 ), $pay_mails() - $pay_m0, PayoutReminder::partner_text() ) );
$pay_set( array( 'payout_reminder' => 'monthly', 'payout_reminder_day' => 5 ) );
$pay_before = $pay_snapshot();
$pay_s1     = PayoutReminder::send_due( $pay_nov3 );
$pay_s2     = PayoutReminder::send_due( $pay_nov5 );
$pay_s3     = PayoutReminder::send_due( $pay_nov5 + 3 * DAY_IN_SECONDS );
check( 'SET-AC-19 monthly reminder: not before the day, sent on the day, once per month; nothing booked', array( false, true, false, 1, '2026-11', $pay_before ), array( $pay_s1, $pay_s2, $pay_s3, $pay_mails() - $pay_m0, get_option( PayoutReminder::SENT ), $pay_snapshot() ) );
check( 'SET-AC-19 a missed day is caught up later in the month, the next month is due again', array( true, '2026-12' ), array( PayoutReminder::period( gmmktime( 12, 0, 0, 11, 20, 2026 ) )['due'], PayoutReminder::period( gmmktime( 12, 0, 0, 12, 5, 2026 ) )['key'] ) );
$pay_set( array( 'payout_reminder' => 'quarterly', 'payout_reminder_day' => 5 ) );
check( 'SET-AC-19 quarterly: key per quarter, due from day 5 of January/April/July/October', array( array( '2026-Q4', false ), array( '2026-Q4', true ), array( '2026-Q4', true ), array( '2027-Q1', true ) ), array(
	array( PayoutReminder::period( gmmktime( 12, 0, 0, 10, 2, 2026 ) )['key'], PayoutReminder::period( gmmktime( 12, 0, 0, 10, 2, 2026 ) )['due'] ),
	array( PayoutReminder::period( gmmktime( 12, 0, 0, 10, 5, 2026 ) )['key'], PayoutReminder::period( gmmktime( 12, 0, 0, 10, 5, 2026 ) )['due'] ),
	array( PayoutReminder::period( $pay_nov3 )['key'], PayoutReminder::period( $pay_nov3 )['due'] ),
	array( PayoutReminder::period( gmmktime( 12, 0, 0, 1, 9, 2027 ) )['key'], PayoutReminder::period( gmmktime( 12, 0, 0, 1, 9, 2027 ) )['due'] ),
) );
$pay_msg = PayoutReminder::message(
	array(
		'EUR' => array( 'eligible' => 2, 'pay_minor' => 4760, 'capped' => 1, 'carried' => 3, 'blocked' => 1 ),
		'USD' => array( 'eligible' => 1, 'pay_minor' => 1000, 'capped' => 0, 'carried' => 0, 'blocked' => 0 ),
	),
	array( 'key' => '2026-Q4', 'due' => true, 'mode' => 'quarterly', 'day' => 5 )
);
check( 'SET-AC-19 reminder text: amounts per currency, capped/carried/blocked counts, link to the payout page, says nothing was booked', array( true, true, true, true, true, true, true ), array(
	false !== strpos( $pay_msg[0], 'Payout reminder' ),
	false !== strpos( $pay_msg[1], 'EUR: 2 partners can be paid now' ),
	false !== strpos( $pay_msg[1], 'USD: 1 partner can be paid now' ),
	false !== strpos( $pay_msg[1], '1 of them reaches the maximum payout' ) && false !== strpos( $pay_msg[1], '3 partners are below the minimum' ) && false !== strpos( $pay_msg[1], '1 partner cannot be paid yet' ),
	false !== strpos( $pay_msg[1], 'page=wpac-payouts' ),
	false !== strpos( $pay_msg[1], 'Nothing was booked' ),
	false !== strpos( $pay_msg[1], 'quarterly' ),
) );
check( 'SET-AC-19 nothing payable and nothing blocked: no mail', array( false, true ), array( PayoutReminder::worth_sending( array( 'EUR' => array( 'eligible' => 0, 'pay_minor' => 0, 'capped' => 0, 'carried' => 2, 'blocked' => 0 ) ) ), PayoutReminder::worth_sending( array( 'EUR' => array( 'eligible' => 0, 'pay_minor' => 0, 'capped' => 0, 'carried' => 0, 'blocked' => 1 ) ) ) ) );
check( 'SET-AC-19 partner text for monthly and quarterly', array( 'Payout on day 5 of each quarter (January, April, July, October)', 'Payout on day 12 of each month' ), array( PayoutReminder::partner_text(), ( $pay_set( array( 'payout_reminder' => 'monthly', 'payout_reminder_day' => 12 ) ) || true ) ? PayoutReminder::partner_text() : '' ) );
check( 'SET-AC-19 the reminder is a daily step that waits for maintenance and the current schema', array( true, true ), array( isset( WPAffiliateCaptain\Core\Cron::daily_steps()['payout_reminder'] ), WPAffiliateCaptain\Core\Cron::daily_steps()['payout_reminder'][1] ) );

unset( $GLOBALS['test_options'][ PayoutReminder::SENT ] );
update_option( 'wpac_settings', $pay_keep );
Settings::flush_cache();
SettingsSchema::flush();
