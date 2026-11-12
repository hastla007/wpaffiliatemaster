<?php
/**
 * Integration tests (ledger + payouts) against a real MySQL/MariaDB.
 *
 *   WPAC_TEST_DB_NAME=wpac_test WPAC_TEST_DB_USER=wpac WPAC_TEST_DB_PASS=wpac php tests/integration/run.php
 *
 * The database is wiped on every run.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

$fails = 0;
$count = 0;
function check( $name, $expected, $actual ) {
	global $fails, $count;
	++$count;
	if ( $expected !== $actual ) {
		++$fails;
		echo "FAIL  {$name}\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
	}
}
function q( $sql ) {
	global $wpdb;
	return $wpdb->query( $sql );
}
function t( $name ) {
	return Db::table( $name );
}
function sum_for( $aff, $status = null ) {
	global $wpdb;
	$w = $status ? $wpdb->prepare( ' AND status = %s', $status ) : " AND status <> 'rejected'";
	return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $aff . $w );
}
function sale( $order, $code, array $nets, $email = 'kunde@example.de' ) {
	$items = array();
	foreach ( $nets as $i => $net ) {
		$items[] = array( 'item_id' => (string) ( $i + 1 ), 'product_id' => 10 + $i, 'name' => 'Kurs ' . ( $i + 1 ), 'net_minor' => $net );
	}
	return wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => $code, 'items' => $items, 'customer' => array( 'user_id' => 0, 'email' => $email ) ) );
}
function refund( $order, $refund_id, array $items = array(), $type = 'refund' ) {
	$p = array( 'source' => 'test', 'order_id' => (string) $order, 'refund_id' => $refund_id, 'type' => $type, 'reason' => 'test' );
	if ( $items ) {
		$p['items'] = $items;
	} else {
		$p['full'] = true;
	}
	return wpac_record_refund( $p );
}
function hook_fired_outside_tx( $tag ) {
	$found = false;
	foreach ( $GLOBALS['test_fired'] as $f ) {
		if ( $f[0] === $tag ) {
			$found = true;
			if ( $f[2] ) {
				return false;
			}
		}
	}
	return $found;
}

update_option(
	'wpac_settings',
	array_merge(
		Settings::defaults(),
		array(
			'debtor_name' => 'Segelschule Test GmbH',
			'debtor_iban' => 'DE89370400440532013000',
			'operator_address' => "Hafenstraße 1\n24937 Flensburg",
			'operator_vat_id' => 'DE123456789',
			'fraud_check_domains' => 0,
		)
	)
);
Settings::flush_cache();

$aff  = AffiliateRepository::create( array( 'name' => 'Anna Partner', 'email' => 'anna@example.de', 'status' => 'active' ) );
$code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff );
check( 'affiliate created', true, is_int( $aff ) );
check( 'random default code', 1, preg_match( '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code ) );
check( 'code without hyphen resolves', $aff, wpac_resolve_partner_code( str_replace( '-', '', strtolower( $code ) ) )['affiliate_id'] );
ProfileRepository::save( $aff, array( 'tax_status' => 'private', 'legal_name' => 'Anna Partner', 'address' => "Hafen 1\n24937 Flensburg", 'country' => 'DE', 'account_holder' => 'Anna Partner', 'iban' => 'DE89370400440532013000' ) );

/* A: sale + idempotency ---------------------------------------------- */
$r = sale( 1001, $code, array( 100000, 50000 ) );
check( 'A sale created', 'created', $r['status'] );
check( 'A two commissions', 2, count( $r['commission_ids'] ) );
check( 'A 10% of 1500 €', 15000, sum_for( $aff ) );
check( 'A duplicate ignored', 'duplicate', sale( 1001, $code, array( 100000, 50000 ) )['status'] );
check( 'A created hook after commit', true, hook_fired_outside_tx( 'wpac_commission_created' ) );

/* B: partial refunds, cumulative rounding, idempotent ------------------ */
refund( 1001, 'r1', array( array( 'item_id' => '1', 'net_minor' => 33333 ) ) );
refund( 1001, 'r1', array( array( 'item_id' => '1', 'net_minor' => 33333 ) ) ); // repeated webhook.
refund( 1001, 'r2', array( array( 'item_id' => '1', 'net_minor' => 33333 ) ) );
refund( 1001, 'r3', array( array( 'item_id' => '1', 'net_minor' => 33334 ) ) );
$c1 = $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source_order_id = '1001' AND source_item_id = '1' AND kind = 'sale'" );
check( 'B item 1 fully reversed (no cent left)', -10000, Ledger::adjusted_sum( (int) $c1 ) );
check( 'B refunded base = full base', 100000, Ledger::refunded_base( (int) $c1 ) );
refund( 1001, 'cb', array(), 'chargeback' );
check( 'B chargeback after full refund adds only item 2', -15000, (int) $GLOBALS['wpdb']->get_var( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE source_order_id = '1001' AND kind <> 'sale'" ) );
check( 'B net zero', 0, sum_for( $aff ) );
check( 'B adjusted hook after commit', true, hook_fired_outside_tx( 'wpac_commission_adjusted' ) );

/* C: CAS transitions -------------------------------------------------- */
$r   = sale( 1002, $code, array( 200000 ) );
$cid = $r['commission_ids'][0];
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'rejected' WHERE id = {$cid}" ); // concurrent rejection.
q( 'UPDATE ' . t( 'commissions' ) . " SET hold_until = '2000-01-01' WHERE id = {$cid}" );
check( 'C rejected is never approved', false, Ledger::transition( $cid, 'approved' ) );
check( 'C paid → nothing', false, Ledger::transition( $cid, 'pending' ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'pending' WHERE id = {$cid}" );
check( 'C approve_due approves', 1, Ledger::approve_due() );
check( 'C status approved', 'approved', Ledger::find( $cid )->status );

/* D: chargeback after the cut-off is paid together with its sale ---- */
$r   = sale( 1003, $code, array( 300000 ) ); // 300 € commission.
$cid = $r['commission_ids'][0];
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2026-10-15 10:00:00', hold_until = '2026-10-16' WHERE id = {$cid}" );
Ledger::transition( $cid, 'approved' );
refund( 1003, 'cb', array(), 'chargeback' ); // booked "now" (after 31.10.).
$preview = PayoutService::preview( '2026-10-31', 'EUR' );
$row     = $preview ? $preview[0] : null;
// Only 1003 is before the cut-off (1002 is "now"): +300 € − 300 € = 0.
check( 'D sale + later chargeback netted in run', 0, $row ? $row['total_minor'] : null );
check( 'D nothing paid', false, $row ? $row['eligible'] : null );

/* E: parent with pending correction is held back ---------------------- */
$r   = sale( 1004, $code, array( 1000000 ) ); // 1.000 € commission, pending.
$cid = $r['commission_ids'][0];
refund( 1004, 'r1', array( array( 'item_id' => '1', 'net_minor' => 500000 ) ) ); // pending child.
Ledger::transition( $cid, 'approved' ); // only parent approved.
$ids = $GLOBALS['wpdb']->get_col( 'SELECT c.id FROM ' . t( 'commissions' ) . ' c' );
$p   = PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' );
check( 'E only 1002 (200 €) payable, 1004 held back', 20000, $p[0]['total_minor'] );
foreach ( $GLOBALS['wpdb']->get_col( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE parent_id = {$cid}" ) as $child ) {
	Ledger::transition( (int) $child, 'approved' );
}
$p = PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' );
check( 'E after child approved: 200 + 1000 − 500 + 300 − 300', 70000, $p[0]['total_minor'] );

/* F: payout, reject blocked, mark paid atomically --------------------- */
$payout = PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' );
check( 'F payout created', true, $payout > 0 );
check( 'F amount', 70000, (int) PayoutService::find( $payout )->amount_minor );
$c1002 = (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source_order_id = '1002'" );
check( 'F reject blocked while in payout', true, is_wp_error( Ledger::reject( $c1002, 'test' ) ) );
check( 'F second run finds nothing', 0, PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' ) );

/* G: SEPA export / re-export / cancel guard --------------------------- */
$x = PayoutService::sepa_export( array( $payout ) );
check( 'G export ok', true, is_array( $x ) );
check( 'G status exported', 'exported', PayoutService::find( $payout )->status );
check( 'G re-export refused', true, is_wp_error( PayoutService::sepa_export( array( $payout ) ) ) );
check( 'G re-export with opt-in', true, is_array( PayoutService::sepa_export( array( $payout ), true ) ) );
check( 'G cancel exported without confirmation refused', true, is_wp_error( PayoutService::cancel( $payout, 'test' ) ) );
check( 'G execution date is a weekday', true, (int) gmdate( 'N', strtotime( preg_replace( '/.*<ReqdExctnDt>([^<]+)<.*/s', '$1', $x[1] ) ) ) < 6 );

/* H: cancel releases items, new run re-uses the commissions ----------- */
check( 'H cancel with confirmation', true, PayoutService::cancel( $payout, 'Bank returned', true ) );
check( 'H items kept (released)', 5, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . t( 'payout_items' ) . " WHERE payout_id = {$payout} AND released_at IS NOT NULL" ) );
check( 'H mark paid on cancelled refused', true, is_wp_error( PayoutService::mark_paid( $payout ) ) );
$payout2 = PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' );
check( 'H new payout with same commissions', 70000, (int) PayoutService::find( $payout2 )->amount_minor );
check( 'H mark paid', true, PayoutService::mark_paid( $payout2 ) );
check( 'H all paid', 70000, sum_for( $aff, 'paid' ) );
check( 'H mark paid twice refused', true, is_wp_error( PayoutService::mark_paid( $payout2 ) ) );
check( 'H paid hook after commit', true, hook_fired_outside_tx( 'wpac_commission_paid' ) );
check( 'H statement generated', 64, strlen( (string) PayoutService::find( $payout2 )->statement_hash ) );

/* I: refund after payout → negative balance carried forward ----------- */
refund( 1002, 'late', array( array( 'item_id' => '1', 'net_minor' => 100000 ) ) );
$p = PayoutService::preview( gmdate( 'Y-m-d' ), 'EUR' );
check( 'I −100 € carried forward', -10000, $p[0]['total_minor'] );
check( 'I not payable', false, $p[0]['eligible'] );

/* K: credit notes – numbering, VAT, cancellation document ------------- */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'operator_address' => "Hafenstr. 1\n24103 Kiel", 'operator_vat_id' => 'DE123456789' ) ) );
Settings::flush_cache();
$biz = AffiliateRepository::create( array( 'name' => 'Segelschule Nord GmbH', 'email' => 'nord@example.de', 'status' => 'active' ) );
ProfileRepository::save( $biz, array( 'tax_status' => 'business', 'legal_name' => 'Segelschule Nord GmbH', 'country' => 'DE', 'vat_id' => 'DE999999999', 'account_holder' => 'Segelschule Nord GmbH', 'iban' => 'DE89370400440532013000', 'address' => "Kai 2\n24937 Flensburg" ) );
$bcode = WPAffiliateCaptain\Codes\CodeRepository::main_code( $biz );
$r     = sale( 2001, $bcode, array( 1000000 ) ); // 1.000 € commission (10 % of 10.000 €).
Ledger::transition( (int) $r['commission_ids'][0], 'approved' );
$bp = PayoutService::find( PayoutService::create_for_affiliate( $biz, gmdate( 'Y-m-d' ), 'EUR' ) );
check( 'K domestic business: VAT mode', 'standard', $bp->vat_mode );
check( 'K net', 100000, (int) $bp->net_minor );
check( 'K 19 % VAT', 19000, (int) $bp->vat_minor );
check( 'K payout = gross', 119000, (int) $bp->amount_minor );
$y = gmdate( 'Y' );
// Anna's two payouts (one cancelled) used numbers 1 and 2.
check( 'K gapless credit note number', 'GS-' . $y . '-000003', $bp->number );
check( 'K cancelled payout got a cancellation number', 'ST-' . $y . '-000001', (string) PayoutService::find( $payout )->storno_number );
check( 'K cancellation PDF stored', 64, strlen( (string) PayoutService::find( $payout )->storno_hash ) );
$pdf = file_get_contents( WPAffiliateCaptain\Payouts\Statement::dir() . '/' . $bp->statement_file );
check( 'K credit note PDF has VAT line', true, false !== strpos( $pdf, 'plus VAT 19' ) );
check( 'K credit note PDF has partner VAT ID', true, false !== strpos( $pdf, 'DE999999999' ) );

// Foreign business partner → reverse charge, private → no VAT.
$at = AffiliateRepository::create( array( 'name' => 'Wien Partner', 'email' => 'wien@example.at', 'status' => 'active' ) );
ProfileRepository::save( $at, array( 'tax_status' => 'business', 'legal_name' => 'Wien Partner', 'address' => "Ring 1\n1010 Wien", 'country' => 'AT', 'vat_id' => 'ATU12345678', 'account_holder' => 'Wien Partner', 'iban' => 'AT611904300234573201' ) );
$r = sale( 2002, WPAffiliateCaptain\Codes\CodeRepository::main_code( $at ), array( 1000000 ) );
Ledger::transition( (int) $r['commission_ids'][0], 'approved' );
$ap = PayoutService::find( PayoutService::create_for_affiliate( $at, gmdate( 'Y-m-d' ), 'EUR' ) );
check( 'K EU business: reverse charge', 'reverse_charge', $ap->vat_mode );
check( 'K reverse charge: no VAT added', 100000, (int) $ap->amount_minor );
check( 'K numbers unique', 'GS-' . $y . '-000004', $ap->number );

// A failing payout (rollback) must not consume a number.
$GLOBALS['wpdb']->query( 'SET @x = 1' );
try {
	WPAffiliateCaptain\Core\Db::transaction(
		static function () {
			WPAffiliateCaptain\Core\Sequence::number( 'GS', 'credit', (int) gmdate( 'Y' ) );
			throw new RuntimeException( 'abort' );
		}
	);
} catch ( RuntimeException $e ) {
	// Expected.
}
check( 'K rollback keeps sequence gapless', 4, (int) $GLOBALS['wpdb']->get_var( 'SELECT value FROM ' . t( 'sequences' ) . " WHERE name = 'credit'" ) );

/* N: balance ledger invariant + tamper detection ------------------------ */
$bad = WPAffiliateCaptain\Commissions\Balance::verify_all();
check( 'N all accounts consistent with commission ledger', array(), $bad );
check( 'N Anna balance = −100 € carried forward', -10000, WPAffiliateCaptain\Commissions\Balance::get( $aff, 'EUR' ) );
$types = $GLOBALS['wpdb']->get_col( 'SELECT DISTINCT type FROM ' . t( 'balance_entries' ) . ' ORDER BY type' );
check( 'N booking types used', array( 'approved', 'payout', 'payout_cancelled' ), $types );
$eid = (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$aff} ORDER BY id LIMIT 1" );
q( 'UPDATE ' . t( 'balance_entries' ) . " SET amount_minor = amount_minor + 1 WHERE id = {$eid}" );
check( 'N tampering detected', true, count( WPAffiliateCaptain\Commissions\Balance::verify( $aff, 'EUR' ) ) > 0 );
q( 'UPDATE ' . t( 'balance_entries' ) . " SET amount_minor = amount_minor - 1 WHERE id = {$eid}" );
check( 'N restored → consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify( $aff, 'EUR' ) );

/* M: monthly partner summaries in batches ------------------------------ */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'report_partners' => 1, 'report_admin' => 1 ) ) );
Settings::flush_cache();
$month = gmdate( 'Y-m' );
$GLOBALS['test_mails'] = array();
$r1 = WPAffiliateCaptain\Reports\MonthlyMail::send( $month, false, 0, 2 );
check( 'M first batch: 2 partner mails', 2, $r1['sent'] );
check( 'M one partner remaining', 1, $r1['remaining'] );
$r2 = WPAffiliateCaptain\Reports\MonthlyMail::send( $month, false, 2, 2 );
check( 'M second batch: last partner', array( 'sent' => 1, 'remaining' => 0 ), $r2 );
check( 'M admin summary separate', 1, WPAffiliateCaptain\Reports\MonthlyMail::send( $month, true ) );
update_option( 'wpac_report_progress', array( 'month' => $month, 'offset' => 0 ) );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Reports\MonthlyMail::batch();
check( 'M batch reschedules while partners remain', array(), $GLOBALS['test_scheduled'] ); // 3 partners < batch size 50 → done.
check( 'M progress cleared', false, get_option( 'wpac_report_progress' ) );

/* J: schema migration v2 → v3 (payout_items) -------------------------- */
$pi = t( 'payout_items' );
q( "DROP TABLE {$pi}" );
q( "CREATE TABLE {$pi} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, payout_id bigint(20) unsigned NOT NULL, commission_id bigint(20) unsigned NOT NULL, amount_minor bigint(20) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY commission_id (commission_id), KEY payout_id (payout_id) )" );
q( "INSERT INTO {$pi} (payout_id, commission_id, amount_minor) VALUES (1, 77, 500)" );
update_option( 'wpac_db_version', 2 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'J old unique index replaced', '1', (string) $GLOBALS['wpdb']->get_var( "SHOW INDEX FROM {$pi} WHERE Key_name = 'commission_id'" ) ? (string) $GLOBALS['wpdb']->get_row( "SHOW INDEX FROM {$pi} WHERE Key_name = 'commission_id'" )->Non_unique : 'missing' );
check( 'J new unique index', '0', (string) $GLOBALS['wpdb']->get_row( "SHOW INDEX FROM {$pi} WHERE Key_name = 'active_commission'" )->Non_unique );
check( 'J existing item marked active', '77', (string) $GLOBALS['wpdb']->get_var( "SELECT active_commission_id FROM {$pi} WHERE commission_id = 77" ) );
check( 'J version stored', WPAC_DB_VERSION, (int) get_option( 'wpac_db_version' ) );

/* L: schema migration v3 → v4 (payouts) ----------------------------------- */
$po = t( 'payouts' );
q( "ALTER TABLE {$po} DROP INDEX number, ADD KEY number (number), DROP INDEX storno_number, DROP COLUMN net_minor, DROP COLUMN vat_mode, DROP COLUMN vat_rate, DROP COLUMN vat_minor, DROP COLUMN storno_number, DROP COLUMN storno_file, DROP COLUMN storno_hash, DROP COLUMN storno_at" );
update_option( 'wpac_db_version', 3 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'L number unique again', '0', (string) $GLOBALS['wpdb']->get_row( "SHOW INDEX FROM {$po} WHERE Key_name = 'number'" )->Non_unique );
check( 'L storno column back', 'storno_number', (string) $GLOBALS['wpdb']->get_var( "SHOW COLUMNS FROM {$po} LIKE 'storno_number'" ) );
check( 'L old payouts: net = amount', 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$po} WHERE net_minor <> amount_minor" ) );

/* P: monthly tier bonus + correction after the month was closed ---------- */
WPAffiliateCaptain\Commissions\TierService::create( array( 'scope' => 'global', 'scope_id' => 0, 'basis' => 'revenue', 'mode' => 'retroactive', 'steps' => array( array( 'threshold' => 100000, 'rate' => '2' ) ) ) );
$tp    = AffiliateRepository::create( array( 'name' => 'Tier Partner', 'email' => 'tier@example.de', 'status' => 'active' ) );
$tcode = WPAffiliateCaptain\Codes\CodeRepository::main_code( $tp );
$last  = gmdate( 'Y-m', strtotime( 'first day of previous month' ) );
foreach ( array( 3001 => 80000, 3002 => 60000 ) as $order => $net ) {
	$r = sale( $order, $tcode, array( $net ) );
	q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$last}-10 10:00:00' WHERE id = " . (int) $r['commission_ids'][0] );
	Ledger::transition( (int) $r['commission_ids'][0], 'approved' );
}
check( 'P month closed with bonus', 1, WPAffiliateCaptain\Commissions\TierService::close_month( $last ) );
$bonus = (int) $GLOBALS['wpdb']->get_var( 'SELECT amount_minor FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$tp} AND kind = 'bonus'" );
check( 'P 2 % of 1.400 € = 28 €', 2800, $bonus );
refund( 3002, 'late-refund', array( array( 'item_id' => '1', 'net_minor' => 60000 ) ) ); // Revenue drops to 800 € < threshold.
check( 'P correction booked', 1, WPAffiliateCaptain\Commissions\TierService::recalculate_month( $last ) );
check( 'P bonus fully reversed', 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$tp} AND kind = 'bonus'" ) );
check( 'P recalculation idempotent', 0, WPAffiliateCaptain\Commissions\TierService::recalculate_month( $last ) );
check( 'P accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

/* O: migration v4 → v5 creates opening balances for existing data -------- */
$legacy = AffiliateRepository::create( array( 'name' => 'Legacy Partner', 'email' => 'legacy@example.de', 'status' => 'active' ) );
q( 'INSERT INTO ' . t( 'commissions' ) . " (affiliate_id, source, source_order_id, kind, currency, base_minor, amount_minor, status, idempotency_key, created_at, updated_at) VALUES ({$legacy}, 'test', 'legacy-1', 'sale', 'EUR', 50000, 5000, 'approved', 'legacy-1', NOW(), NOW())" );
update_option( 'wpac_db_version', 4 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'O opening balance booked', 5000, WPAffiliateCaptain\Commissions\Balance::get( $legacy, 'EUR' ) );
check( 'O opening type', 'opening', (string) $GLOBALS['wpdb']->get_var( 'SELECT type FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$legacy}" ) );
check( 'O existing accounts not booked twice', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "P consent (WP Consent API)\n";
if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); } }
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'cookie_mode' => 'consent' ) ) );
WPAffiliateCaptain\Core\Settings::flush_cache();
check( 'P no consent banner registered → no cookie (API alone is not a consent)', false, WPAffiliateCaptain\Tracking\Consent::cookie_allowed() );
$GLOBALS['test_consent_type'] = 'optin';
check( 'P opt-in without choice → no cookie', false, WPAffiliateCaptain\Tracking\Consent::cookie_allowed() );
$GLOBALS['test_consent_cookie'] = 'allow';
check( 'P opt-in with marketing consent → cookie', true, WPAffiliateCaptain\Tracking\Consent::cookie_allowed() );
$GLOBALS['test_consent_cookie'] = 'deny';
check( 'P opt-in, marketing denied → no cookie', false, WPAffiliateCaptain\Tracking\Consent::cookie_allowed() );

echo "Q retention in batches\n";
global $wpdb;
$vt = WPAffiliateCaptain\Core\Db::table( 'visits' );
for ( $i = 0; $i < 12; $i++ ) { $wpdb->insert( $vt, array( 'affiliate_id' => 1, 'created_at' => '2020-01-01 00:00:00' ) ); }
$wpdb->insert( $vt, array( 'affiliate_id' => 1, 'created_at' => WPAffiliateCaptain\Core\Db::now() ) );
$deleted = WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$vt} WHERE created_at < %s", '2021-01-01 00:00:00' ), 5 );
check( 'Q old visits deleted in batches of 5', 12, $deleted );
check( 'Q recent visit kept', 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vt} WHERE created_at >= '2021-01-01'" ) );

echo "R audit fixes (money, refunds, jobs, tiers, privacy)\n";
global $wpdb;
$ct = t( 'commissions' );
$rp = AffiliateRepository::create( array( 'name' => 'Rita Fix', 'email' => 'rita@example.de', 'status' => 'active' ) );
$rc = WPAffiliateCaptain\Codes\CodeRepository::main_code( $rp );

// R1 a correction is not payable (and cannot be approved) while its original is still open.
$r  = sale( 5001, $rc, array( 100000 ) );
$p1 = (int) $r['commission_ids'][0];
refund( 5001, 'r1', array( array( 'item_id' => '1', 'net_minor' => 50000 ) ) );
$child = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE parent_id = {$p1}" );
check( 'R1 approving a correction of an open commission refused', 'wpac_parent_open', ( $e = Ledger::approve( $child, 'test' ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
q( "UPDATE {$ct} SET status = 'approved' WHERE id = {$child}" ); // e.g. approved by the hold cron
$prev = PayoutService::preview( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'EUR' );
$found = 0;
foreach ( $prev as $row ) { if ( $row['affiliate'] && (int) $row['affiliate']->id === $rp ) { $found = $row['total_minor']; } }
check( 'R1 correction of a pending commission is not paid out', 0, $found );
q( "UPDATE {$ct} SET status = 'pending' WHERE id = {$child}" );

// R2 transition books the balance from the status the row really has (stale read).
$r  = sale( 5002, $rc, array( 20000 ) );
$p2 = (int) $r['commission_ids'][0];
Ledger::transition( $p2, 'approved' );
q( "UPDATE {$ct} SET status = 'pending' WHERE id = {$p2}" ); // simulate the stale view: the row says approved below
q( "UPDATE {$ct} SET status = 'approved' WHERE id = {$p2}" );
check( 'R2 reject of approved books the reversal', true, Ledger::transition( $p2, 'rejected', 'x' ) );
check( 'R2 balances consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

// R3 refund while the sale is still queued is kept and applied after the sale.
WPAffiliateCaptain\Core\Jobs::enqueue( 'sale', 'test:5003', array( 'contract' => WPAC_CONTRACT_VERSION, 'source' => 'test', 'order_id' => '5003', 'currency' => 'EUR', 'partner_code' => $rc, 'items' => array( array( 'item_id' => '1', 'product_id' => 1, 'name' => 'Kurs', 'net_minor' => 20000 ) ) ), 'db', 'deadlock' );
$rr = refund( 5003, 'early', array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) );
check( 'R3 refund before the sale is queued', 'queued', is_array( $rr ) ? $rr['status'] : 'error' );
q( 'UPDATE ' . t( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00'" );
add_filter( 'wpac_run_job', array( 'WPAffiliateCaptain\Sales\ContractApi', 'run_job' ), 10, 3 );
WPAffiliateCaptain\Core\Jobs::run_due();
WPAffiliateCaptain\Core\Jobs::run_due(); // refund may run before the sale in the first pass
q( 'UPDATE ' . t( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = 'queued'" );
WPAffiliateCaptain\Core\Jobs::run_due();
check( 'R3 only the remaining half is commissioned', 1000, (int) $wpdb->get_var( "SELECT SUM(amount_minor) FROM {$ct} WHERE source_order_id = '5003' AND status <> 'rejected'" ) );

// R4 job claim: a running job is not run twice; an aborted run counts as an attempt.
WPAffiliateCaptain\Core\Jobs::enqueue( 'nohandler', 'x1', array(), 'e', 'e' );
$jid = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'jobs' ) . " WHERE type = 'nohandler'" );
q( 'UPDATE ' . t( 'jobs' ) . " SET status = 'running', attempts = 3, next_attempt_at = '2000-01-01 00:00:00' WHERE id = {$jid}" );
check( 'R4 running job is not claimed again', false, WPAffiliateCaptain\Core\Jobs::run( $jid ) );
WPAffiliateCaptain\Core\Jobs::run_due();
$job = $wpdb->get_row( 'SELECT status, attempts, last_error_code FROM ' . t( 'jobs' ) . " WHERE id = {$jid}" );
check( 'R4 expired lease → failed attempt counted', 'aborted', $job->last_error_code === 'aborted' || $job->attempts > 3 ? 'aborted' : $job->last_error_code );
WPAffiliateCaptain\Core\Jobs::enqueue( 'nohandler', 'x1', array(), 'e', 'e' );
check( 'R4 re-enqueue resets the attempts', '1', (string) $wpdb->get_var( 'SELECT attempts FROM ' . t( 'jobs' ) . " WHERE id = {$jid}" ) );

// R5 filtered commission base: a refund of half the line reverses half the commission.
$GLOBALS['test_hooks']['wpac_commission_base'][10][] = array( static function ( $base ) { return intdiv( $base, 2 ); }, 1 );
$r  = sale( 5005, $rc, array( 10000 ) ); // base 50 €, 10 % = 5 €
unset( $GLOBALS['test_hooks']['wpac_commission_base'] );
refund( 5005, 'half', array( array( 'item_id' => '1', 'net_minor' => 5000 ) ) );
check( 'R5 half of the line refunded → half of the commission left', 250, (int) $wpdb->get_var( "SELECT SUM(amount_minor) FROM {$ct} WHERE source_order_id = '5005'" ) );

// R6 several refund lines of one item add up.
sale( 5006, $rc, array( 10000 ) );
refund( 5006, 'two-lines', array( array( 'item_id' => '1', 'net_minor' => 3000 ), array( 'item_id' => '1', 'net_minor' => 2000 ) ) );
check( 'R6 both refund lines counted', 500, (int) $wpdb->get_var( "SELECT SUM(amount_minor) FROM {$ct} WHERE source_order_id = '5006'" ) );
check( 'R6 long keys are hashed, not truncated', true, Ledger::key( str_repeat( 'a', 200 ) . 'x' ) !== Ledger::key( str_repeat( 'a', 200 ) . 'y' ) && strlen( Ledger::key( str_repeat( 'a', 300 ) ) ) <= 191 );

// R7 foreign currency: a fixed rule is converted, never replaced by a broader percent rule.
$rule7 = WPAffiliateCaptain\Commissions\RuleRepository::save( WPAffiliateCaptain\Commissions\RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'product', 'target_value' => '77', 'rate_type' => 'fixed', 'rate' => '5', 'priority' => 10, 'active' => 1 ) ), 0, 'test' );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'exchange_rates' => '' ) ) );
Settings::flush_cache();
$usd = wpac_record_sale( array( 'source' => 'test', 'order_id' => '5007', 'currency' => 'USD', 'partner_code' => $rc, 'items' => array( array( 'item_id' => '1', 'product_id' => 77, 'name' => 'X', 'net_minor' => 10000 ) ) ) );
check( 'R7 no rate → the fixed rule still wins (no commission)', 0, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE source_order_id = '5007'" ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'exchange_rates' => 'USD=0.5' ) ) );
Settings::flush_cache();
wpac_record_sale( array( 'source' => 'test', 'order_id' => '5017', 'currency' => 'USD', 'partner_code' => $rc, 'items' => array( array( 'item_id' => '1', 'product_id' => 77, 'name' => 'X', 'net_minor' => 10000 ) ) ) );
check( 'R7 with a rate: 5 € fixed = 10 USD', 1000, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE source_order_id = '5017'" ) );
WPAffiliateCaptain\Commissions\RuleRepository::delete( $rule7, 'test' );

// R8 an open payout request is settled by a regular payout run.
$rq = AffiliateRepository::create( array( 'name' => 'Req Partner', 'email' => 'req@example.de', 'status' => 'active' ) );
ProfileRepository::save( $rq, array( 'tax_status' => 'private', 'legal_name' => 'Req Partner', 'address' => "Weg 1\n12345 Ort", 'country' => 'DE', 'iban' => 'DE89370400440532013000', 'account_holder' => 'Req Partner' ) );
$rs = sale( 5008, WPAffiliateCaptain\Codes\CodeRepository::main_code( $rq ), array( 500000 ) );
Ledger::transition( (int) $rs['commission_ids'][0], 'approved' );
q( "UPDATE {$ct} SET created_at = '2020-01-01 00:00:00' WHERE id = " . (int) $rs['commission_ids'][0] );
$wpdb->insert( t( 'payout_requests' ), array( 'affiliate_id' => $rq, 'currency' => 'EUR', 'amount_minor' => 50000, 'status' => 'requested', 'created_at' => Db::now() ) );
$rpid = PayoutService::create_for_affiliate( $rq, gmdate( 'Y-m-d' ), 'EUR' );
check( 'R8 payout created', true, $rpid > 0 );
check( 'R8 request marked processed', 'processed', (string) $wpdb->get_var( 'SELECT status FROM ' . t( 'payout_requests' ) . " WHERE affiliate_id = {$rq}" ) );

// R9 the eraser keeps bank details while a payout is still unpaid, and deletes them later.
check( 'R9 open payout counts as open', true, WPAffiliateCaptain\Privacy\Privacy::has_open_items( $rq ) );
update_option( 'wpac_erasure_pending', array( $rq ) );
WPAffiliateCaptain\Privacy\Privacy::finish_pending_erasures();
check( 'R9 profile kept while the payout is open', true, (bool) ProfileRepository::get( $rq ) );
PayoutService::mark_paid( $rpid );
WPAffiliateCaptain\Privacy\Privacy::finish_pending_erasures();
check( 'R9 profile deleted once settled', false, (bool) ProfileRepository::get( $rq ) );

// R10 a fully refunded first order removes the lifetime customer link.
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $rp, 'customer_hash' => str_repeat( 'b', 64 ), 'source' => 'test', 'source_order_id' => '5010', 'created_at' => Db::now() ) );
sale( 5010, $rc, array( 10000 ) );
refund( 5010, 'all' );
check( 'R10 link removed after full reversal', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'customer_links' ) . " WHERE source_order_id = '5010'" ) );

// R11 tiers: a month that has not ended (plus hold) cannot be closed.
check( 'R11 current month not due', false, WPAffiliateCaptain\Commissions\TierService::is_due( gmdate( 'Y-m' ) ) );
check( 'R11 old month due', true, WPAffiliateCaptain\Commissions\TierService::is_due( '2020-01' ) );

// R12 referral cookie: signed values verify, forged ones do not, expired clicks are ignored.
$sig = WPAffiliateCaptain\Tracking\Tracker::sign( 'ABCD-EFGH||0|' . time() );
check( 'R12 signed cookie accepted', 'ABCD-EFGH', WPAffiliateCaptain\Tracking\Tracker::parse_cookie( $sig )['code'] ?? null );
check( 'R12 forged cookie rejected', null, WPAffiliateCaptain\Tracking\Tracker::parse_cookie( 'ABCD-EFGH||0|1|0000000000000000' ) );
check( 'R12 click older than the window rejected', null, WPAffiliateCaptain\Tracking\Tracker::parse_cookie( WPAffiliateCaptain\Tracking\Tracker::sign( 'ABCD-EFGH||0|1' ) ) );
// R13 vanity slugs resolve only for links, not as typed codes (checkout/REST).
$slug = AffiliateRepository::find( $rp )->slug;
check( 'R13 slug resolves for a link', $rp, wpac_resolve_partner_code( $slug, array( 'context' => 'link' ) )['affiliate_id'] ?? null );
check( 'R13 slug is not accepted at checkout', null, wpac_resolve_partner_code( $slug, array( 'context' => 'checkout' ) ) );
check( 'R13 slug is not accepted via REST', null, wpac_resolve_partner_code( $slug, array( 'context' => 'rest' ) ) );
check( 'R balances consistent after all fixes', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "S audit fixes (legal: credit notes, retention, privacy)\n";
// S1 credit notes need name and address; small businesses a tax number or VAT ID.
$sp = AffiliateRepository::create( array( 'name' => 'Klein Partner', 'email' => 'klein@example.de', 'status' => 'active' ) );
check( 'S1 small business without tax number refused', 'wpac_tax_number', ( $e = ProfileRepository::save( $sp, array( 'tax_status' => 'small_business', 'legal_name' => 'Klein', 'address' => "Weg 2\n12345 Ort", 'country' => 'DE', 'account_holder' => 'Klein', 'iban' => 'DE89370400440532013000' ) ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
ProfileRepository::save( $sp, array( 'tax_status' => 'private', 'legal_name' => 'Klein', 'country' => 'DE', 'account_holder' => 'Klein', 'iban' => 'DE89370400440532013000' ) );
check( 'S1 profile without address is incomplete', 'address', ProfileRepository::missing( ProfileRepository::get( $sp ) ) );
ProfileRepository::save( $sp, array( 'tax_status' => 'small_business', 'legal_name' => 'Klein', 'address' => "Weg 2\n12345 Ort", 'country' => 'DE', 'tax_number' => '12/345/67890', 'account_holder' => 'Klein', 'iban' => 'DE89370400440532013000' ) );
check( 'S1 complete with tax number', true, ProfileRepository::missing( ProfileRepository::get( $sp ) ) );
check( 'S1 tax number in the snapshot', '12/345/67890', ProfileRepository::snapshot( ProfileRepository::get( $sp ) )['tax_number'] );

// S2 without operator address/VAT ID no credit note is created.
$keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $keep, array( 'operator_address' => '' ) ) );
Settings::flush_cache();
check( 'S2 operator data missing → reason', true, '' !== PayoutService::operator_missing() );
$thrown = false;
try { PayoutService::create_for_affiliate( $sp, gmdate( 'Y-m-d' ), 'EUR' ); } catch ( \Throwable $e ) { $thrown = 'operator_data' === $e->error_code; }
check( 'S2 payout refused without operator data', true, $thrown );
update_option( 'wpac_settings', $keep );
Settings::flush_cache();

// S3 retention: at least 10 years; legal hold stops the purge; cancellations count from their own date.
check( 'S3 retention below 10 years raised to 10', 10, Settings::sanitize( array_merge( $keep, array( 'statement_retention_years' => 3 ) ) )['statement_retention_years'] );
check( 'S3 0 = forever stays', 0, Settings::sanitize( array_merge( $keep, array( 'statement_retention_years' => 0 ) ) )['statement_retention_years'] );
$old = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'payouts' ) . ' ORDER BY id ASC LIMIT 1' );
q( 'UPDATE ' . t( 'payouts' ) . " SET created_at = '2001-03-01 10:00:00', storno_at = '2025-01-10 10:00:00' WHERE id = {$old}" );
WPAffiliateCaptain\Payouts\Statement::purge_expired();
check( 'S3 cancelled recently → original kept', null, $wpdb->get_var( 'SELECT statement_deleted_at FROM ' . t( 'payouts' ) . " WHERE id = {$old}" ) );
q( 'UPDATE ' . t( 'payouts' ) . " SET storno_at = NULL WHERE id = {$old}" );
update_option( 'wpac_settings', array_merge( $keep, array( 'retention_hold' => 1 ) ) );
Settings::flush_cache();
check( 'S3 legal hold: nothing deleted', 0, WPAffiliateCaptain\Payouts\Statement::purge_expired() );
update_option( 'wpac_settings', $keep );
Settings::flush_cache();
check( 'S3 after the period: deleted', 1, WPAffiliateCaptain\Payouts\Statement::purge_expired() );

// S4 audit: the change log of payouts/statements/bank details outlives the general audit retention.
$wpdb->insert( t( 'audit' ), array( 'action' => 'payout.created', 'object_type' => 'payout', 'object_id' => 1, 'created_at' => '2022-01-01 00:00:00' ) );
$wpdb->insert( t( 'audit' ), array( 'action' => 'rule.updated', 'object_type' => 'rule', 'object_id' => 1, 'created_at' => '2022-01-01 00:00:00' ) );
WPAffiliateCaptain\Core\Cron::daily();
check( 'S4 financial audit entry kept', 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.created' AND created_at = '2022-01-01 00:00:00'" ) );
check( 'S4 other old audit entry deleted', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'rule.updated' AND created_at = '2022-01-01 00:00:00'" ) );

// S5 rejected applicants without commissions are anonymised after the retention period.
$rej = AffiliateRepository::create( array( 'name' => 'Abgelehnt', 'email' => 'rejected@example.de', 'status' => 'rejected' ) );
q( 'UPDATE ' . t( 'affiliates' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE id = {$rej}" );
WPAffiliateCaptain\Privacy\Privacy::purge_rejected_applications();
check( 'S5 rejected applicant anonymised', 'deleted-' . $rej . '@invalid.invalid', (string) $wpdb->get_var( 'SELECT email FROM ' . t( 'affiliates' ) . " WHERE id = {$rej}" ) );

echo "T 0.9.0 features\n";
// T1 a negative balance can be written off; the account is consistent afterwards.
$wo = AffiliateRepository::create( array( 'name' => 'Minus Partner', 'email' => 'minus@example.de', 'status' => 'active' ) );
$wr = sale( 6001, WPAffiliateCaptain\Codes\CodeRepository::main_code( $wo ), array( 10000 ) );
Ledger::transition( (int) $wr['commission_ids'][0], 'approved' );
q( "UPDATE {$ct} SET payout_id = 999999, status = 'paid' WHERE id = " . (int) $wr['commission_ids'][0] ); // already paid out
WPAffiliateCaptain\Commissions\Balance::post( $wo, 'EUR', 'payout', -1000, 0, 999999, 'test payout' );
refund( 6001, 'late', array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ); // clawback -10 €
$neg = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE affiliate_id = {$wo} AND status = 'approved' AND payout_id = 0" );
check( 'T1 balance is negative', true, $neg < 0 );
check( 'T1 write-off needs a reason', true, is_wp_error( Ledger::write_off( $wo, 'EUR', '' ) ) );
check( 'T1 write-off booked', true, is_int( Ledger::write_off( $wo, 'EUR', 'Partner beendet, Forderung erlassen' ) ) );
check( 'T1 balance settled', 0, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE affiliate_id = {$wo} AND status = 'approved' AND payout_id = 0" ) );
check( 'T1 nothing to write off twice', true, is_wp_error( Ledger::write_off( $wo, 'EUR', 'again' ) ) );
check( 'T1 accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify( $wo, 'EUR' ) );

// T2 contact e-mail change checks duplicates.
check( 'T2 e-mail of another partner refused', 'wpac_email_taken', ( $e = AffiliateRepository::set_email( $wo, 'anna@example.de' ) ) instanceof WP_Error ? $e->get_error_code() : 'ok' );
check( 'T2 new e-mail stored', true, AffiliateRepository::set_email( $wo, 'neu@example.de' ) );
check( 'T2 found by new e-mail', $wo, (int) AffiliateRepository::find_by_email( 'neu@example.de' )->id );

echo "\n{$count} integration checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
