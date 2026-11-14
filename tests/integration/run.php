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
			'terms_page_id' => 900,
		)
	)
);
Settings::flush_cache();
// Every test partner accepted the terms (with the self-billing agreement).
add_action( 'wpac_affiliate_created', static function ( $id ) { WPAffiliateCaptain\Affiliates\Agreements::record( (int) $id ); } );

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
// (A handler that throws: a missing handler no longer uses up attempts, see R5-O20.)
$GLOBALS['test_hooks']['wpac_run_job'][10][] = array( static function ( $handled, $type ) { if ( 'failing' === $type ) { throw new \RuntimeException( 'boom' ); } return $handled; }, 2 );
WPAffiliateCaptain\Core\Jobs::enqueue( 'failing', 'x1', array(), 'e', 'e' );
$jid = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'jobs' ) . " WHERE type = 'failing'" );
q( 'UPDATE ' . t( 'jobs' ) . " SET status = 'running', attempts = 3, next_attempt_at = '2000-01-01 00:00:00' WHERE id = {$jid}" );
check( 'R4 running job is not claimed again', false, WPAffiliateCaptain\Core\Jobs::run( $jid ) );
WPAffiliateCaptain\Core\Jobs::run_due();
$job = $wpdb->get_row( 'SELECT status, attempts, last_error_code FROM ' . t( 'jobs' ) . " WHERE id = {$jid}" );
// The dead run counts as an attempt (3 → requeued as aborted) and the job then runs again in the
// same pass and fails again (attempt 4).
check( 'R4 expired lease → the dead run and the new run are counted', array( 'queued', 4, 'exception' ), array( $job->status, (int) $job->attempts, $job->last_error_code ) );
WPAffiliateCaptain\Core\Jobs::enqueue( 'failing', 'x1', array(), 'e', 'e' );
check( 'R4 re-enqueue keeps the attempts (a job that keeps failing must still end as failed)', '4', (string) $wpdb->get_var( 'SELECT attempts FROM ' . t( 'jobs' ) . " WHERE id = {$jid}" ) );

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
check( 'S3 retention below the legal minimum raised (DE: 8 years)', 8, Settings::sanitize( array_merge( $keep, array( 'statement_retention_years' => 3, 'operator_country' => 'DE' ) ) )['statement_retention_years'] );
check( 'S3 legal minimum Austria 7, Switzerland 10', array( 7, 10 ), array( Settings::sanitize( array_merge( $keep, array( 'statement_retention_years' => 3, 'operator_country' => 'AT' ) ) )['statement_retention_years'], Settings::sanitize( array_merge( $keep, array( 'statement_retention_years' => 3, 'operator_country' => 'CH' ) ) )['statement_retention_years'] ) );
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
// The same person is a customer linked to another partner: that link must survive.
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $aff, 'customer_hash' => WPAffiliateCaptain\Tracking\CustomerLinks::hashes( 0, 'rejected@example.de' )[0], 'source' => 'test', 'source_order_id' => 's5', 'created_at' => Db::now() ) );
WPAffiliateCaptain\Privacy\Privacy::purge_rejected_applications();
check( 'S5 rejected applicant anonymised', 'deleted-' . $rej . '@invalid.invalid', (string) $wpdb->get_var( 'SELECT email FROM ' . t( 'affiliates' ) . " WHERE id = {$rej}" ) );
check( 'S5 applicant mode keeps the person\'s customer links to other partners', 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'customer_links' ) . " WHERE source_order_id = 's5'" ) );
check( 'S5 application agreements removed', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'agreements' ) . " WHERE affiliate_id = {$rej}" ) );

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

echo "U TODO.md audit fixes\n";
// U1 atomic rate limit without an object cache.
$hits = array();
for ( $i = 0; $i < 5; $i++ ) { $hits[] = WPAffiliateCaptain\Tracking\RateLimiter::allow( 'u1-test', 3, 60 ); }
check( 'U1 rate limit: 3 allowed, then refused', array( true, true, true, false, false ), $hits );
// U2 a refund arriving while the sale is being booked is queued, not dropped.
$inflight = WPAffiliateCaptain\Core\Jobs::inflight_key( 'test', '7001' );
$owner    = WPAffiliateCaptain\Core\Db::lock( $inflight, 600 );
$rr = refund( 7001, 'parallel', array( array( 'item_id' => '1', 'net_minor' => 1000 ) ) );
check( 'U2 refund during an in-flight sale is queued', 'queued', is_array( $rr ) ? $rr['status'] : 'error' );
WPAffiliateCaptain\Core\Db::unlock( $inflight, $owner );
sale( 7002, $rc, array( 1000 ) );
check( 'U2 marker is removed after a sale was booked', null, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WPAffiliateCaptain\Core\Jobs::inflight_key( 'test', '7002' ) ) ) );
// U2b locks are exclusive, owned, and taken over only when expired.
$a = WPAffiliateCaptain\Core\Db::lock( 'wpac_test_lock', 600 );
$b = WPAffiliateCaptain\Core\Db::lock( 'wpac_test_lock', 600 );
check( 'U2b second lock attempt fails while held', array( true, '' ), array( '' !== $a, $b ) );
WPAffiliateCaptain\Core\Db::unlock( 'wpac_test_lock', 'not-the-owner' );
check( 'U2b a foreign owner cannot release it', '', WPAffiliateCaptain\Core\Db::lock( 'wpac_test_lock', 600 ) );
q( "UPDATE {$wpdb->options} SET option_value = '1:old' WHERE option_name = 'wpac_test_lock'" );
$c = WPAffiliateCaptain\Core\Db::lock( 'wpac_test_lock', 600 );
check( 'U2b expired lock is taken over', true, '' !== $c );
WPAffiliateCaptain\Core\Db::unlock( 'wpac_test_lock', $a ); // stale owner: must not delete the new lock
check( 'U2b the previous owner cannot delete the taken-over lock', '', WPAffiliateCaptain\Core\Db::lock( 'wpac_test_lock', 600 ) );
WPAffiliateCaptain\Core\Db::unlock( 'wpac_test_lock', $c );
// U3 enqueue reports whether the job was stored.
check( 'U3 enqueue returns true when stored', true, WPAffiliateCaptain\Core\Jobs::enqueue( 'nohandler', 'u3', array(), 'e', 'e' ) );
// U4 payout requests: no second open request; rejecting twice fails the second time.
$up = AffiliateRepository::create( array( 'name' => 'Antrag Partner', 'email' => 'antrag@example.de', 'status' => 'active' ) );
ProfileRepository::save( $up, array( 'tax_status' => 'private', 'legal_name' => 'Antrag', 'address' => "Weg 3\n12345 Ort", 'country' => 'DE', 'account_holder' => 'Antrag', 'iban' => 'DE89370400440532013000' ) );
$ur = sale( 7003, WPAffiliateCaptain\Codes\CodeRepository::main_code( $up ), array( 500000 ) );
Ledger::transition( (int) $ur['commission_ids'][0], 'approved' );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'payout_requests' => 1 ) ) );
Settings::flush_cache();
$req1 = WPAffiliateCaptain\Payouts\PayoutRequests::request( AffiliateRepository::find( $up ) );
$req2 = WPAffiliateCaptain\Payouts\PayoutRequests::request( AffiliateRepository::find( $up ) );
check( 'U4 first request stored', true, is_int( $req1 ) && $req1 > 0 );
check( 'U4 second open request refused', true, is_wp_error( $req2 ) );
check( 'U4 reject once', true, WPAffiliateCaptain\Payouts\PayoutRequests::reject( $req1, 'Test' ) );
check( 'U4 reject again refused (already handled)', true, is_wp_error( WPAffiliateCaptain\Payouts\PayoutRequests::reject( $req1, 'Test' ) ) );
// U5 schema verification finds nothing on a complete schema and reports a missing column.
check( 'U5 complete schema verifies', array(), WPAffiliateCaptain\Core\Installer::verify_schema() );
q( 'ALTER TABLE ' . t( 'payout_profiles' ) . ' DROP COLUMN tax_number' );
check( 'U5 missing column reported', true, in_array( 'missing column ' . t( 'payout_profiles' ) . '.tax_number', WPAffiliateCaptain\Core\Installer::verify_schema(), true ) );
$before_version = (int) get_option( 'wpac_db_version' );
update_option( 'wpac_db_version', 6 );
$GLOBALS['test_dbdelta_create_only'] = true; // dbDelta leaves the existing table unchanged → migration stays incomplete.
WPAffiliateCaptain\Core\Installer::install_schema();
unset( $GLOBALS['test_dbdelta_create_only'] );
check( 'U5 incomplete migration does not set the schema version', 6, (int) get_option( 'wpac_db_version' ) );
check( 'U5 problems stored for the admin notice', true, (bool) get_option( 'wpac_schema_problems' ) );
q( 'ALTER TABLE ' . t( 'payout_profiles' ) . " ADD COLUMN tax_number varchar(40) NOT NULL DEFAULT ''" );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'U5 complete again → version set, problems cleared', array( WPAC_DB_VERSION, false ), array( (int) get_option( 'wpac_db_version' ), get_option( 'wpac_schema_problems' ) ) );
// U6 a failed monthly mail is remembered and retried.
delete_option( WPAffiliateCaptain\Reports\MonthlyMail::FAILED );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'report_admin' => 1 ) ) );
Settings::flush_cache();
$GLOBALS['test_mail_fail'] = true;
WPAffiliateCaptain\Reports\MonthlyMail::send( gmdate( 'Y-m' ), true );
check( 'U6 failed operator mail remembered', true, ! empty( WPAffiliateCaptain\Reports\MonthlyMail::failures()[ gmdate( 'Y-m' ) ]['admin'] ) );
$GLOBALS['test_mail_fail'] = false;
WPAffiliateCaptain\Reports\MonthlyMail::retry_failed();
check( 'U6 retry sends it and clears the failure', false, get_option( WPAffiliateCaptain\Reports\MonthlyMail::FAILED ) );
check( 'U accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "V 5-expert audit fixes\n";
$jt = t( 'jobs' );
// V1 a refund waiting for its sale does not use up attempts; the booked sale wakes it; netted refunds are closed.
WPAffiliateCaptain\Core\Jobs::enqueue( 'sale', 'test:8001', array( 'x' => 1 ), 'e', 'e' );
$rj = refund( 8001, 'w1', array( array( 'item_id' => '1', 'net_minor' => 100 ) ) );
check( 'V1 refund queued behind the pending sale', 'queued', is_array( $rj ) ? $rj['status'] : 'error' );
$rid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'refund:test:8001:w1'" );
q( "UPDATE {$jt} SET next_attempt_at = NOW() - INTERVAL 1 MINUTE WHERE id = {$rid}" );
WPAffiliateCaptain\Core\Jobs::run( $rid );
check( 'V1 waiting does not count as an attempt', array( 'queued', 1 ), array( $wpdb->get_var( "SELECT status FROM {$jt} WHERE id = {$rid}" ), (int) $wpdb->get_var( "SELECT attempts FROM {$jt} WHERE id = {$rid}" ) ) );
q( "UPDATE {$jt} SET status = 'failed', next_attempt_at = NOW() + INTERVAL 1 DAY WHERE id = {$rid}" );
WPAffiliateCaptain\Core\Jobs::wake_refunds( 'test:8001' );
check( 'V1 booked sale wakes its (even failed) refund jobs', 'queued', $wpdb->get_var( "SELECT status FROM {$jt} WHERE id = {$rid} AND next_attempt_at <= NOW()" ) );
WPAffiliateCaptain\Core\Jobs::supersede_refunds( 'test', '8001', array( 'w1' ) );
check( 'V1 refunds already netted into a rebuilt sale are closed', array( 'done', 'superseded' ), array_values( (array) $wpdb->get_row( "SELECT status, last_error_code FROM {$jt} WHERE id = {$rid}", ARRAY_A ) ) );
q( "DELETE FROM {$jt} WHERE idempotency_key LIKE '%:8001%'" );

// V2 a rejected monthly bonus stays rejected; months before the first close get no bonus.
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE affiliate_id = {$tp} AND kind = 'bonus' AND status <> 'rejected'" ) as $vb ) {
	Ledger::reject( (int) $vb, 'Admin decision' );
}
$tr = sale( 8002, $tcode, array( 200000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$last}-11 10:00:00' WHERE id = " . (int) $tr['commission_ids'][0] );
Ledger::transition( (int) $tr['commission_ids'][0], 'approved' );
check( 'V2 rejected bonus month is not booked again', 0, WPAffiliateCaptain\Commissions\TierService::recalculate_month( $last ) );
$older = gmdate( 'Y-m', strtotime( $last . '-01 -5 month' ) );
$tr2   = sale( 8003, $tcode, array( 300000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$older}-11 10:00:00' WHERE id = " . (int) $tr2['commission_ids'][0] );
Ledger::transition( (int) $tr2['commission_ids'][0], 'approved' );
check( 'V2 a month that was never closed gets no retroactive bonus', 0, WPAffiliateCaptain\Commissions\TierService::recalculate_month( $older ) );
Ledger::reject( (int) $tr['commission_ids'][0], 'cleanup' );
Ledger::reject( (int) $tr2['commission_ids'][0], 'cleanup' );

// V3 foreign currency without a rate: a broad fixed rule no longer beats a specific percent rule.
$vf  = AffiliateRepository::create( array( 'name' => 'FX Partner', 'email' => 'fx@example.de', 'status' => 'active' ) );
$vr1 = WPAffiliateCaptain\Commissions\RuleRepository::save( WPAffiliateCaptain\Commissions\RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'fixed', 'rate' => '2', 'priority' => 0, 'active' => 1 ) ), 0, 'test' );
$vr2 = WPAffiliateCaptain\Commissions\RuleRepository::save( WPAffiliateCaptain\Commissions\RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $vf, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '15', 'priority' => 0, 'active' => 1 ) ), 0, 'test' );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'exchange_rates' => '' ) ) );
Settings::flush_cache();
wpac_record_sale( array( 'source' => 'test', 'order_id' => '8004', 'currency' => 'GBP', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $vf ), 'items' => array( array( 'item_id' => '1', 'product_id' => 5, 'name' => 'X', 'net_minor' => 10000 ) ) ) );
check( 'V3 the specific 15 % rule wins', 1500, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE source_order_id = '8004'" ) );
WPAffiliateCaptain\Commissions\RuleRepository::delete( $vr1, 'test' );
WPAffiliateCaptain\Commissions\RuleRepository::delete( $vr2, 'test' );

// V4 a payout in another currency does not settle the open request.
$vq = AffiliateRepository::create( array( 'name' => 'Cur Partner', 'email' => 'cur@example.de', 'status' => 'active' ) );
$wpdb->insert( t( 'payout_requests' ), array( 'affiliate_id' => $vq, 'amount_minor' => 5000, 'currency' => 'EUR', 'status' => 'requested', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$vqr = (int) $wpdb->insert_id;
WPAffiliateCaptain\Payouts\PayoutRequests::fulfil( $vq, 999, 'USD' );
check( 'V4 USD payout leaves the EUR request open', 'requested', $wpdb->get_var( 'SELECT status FROM ' . t( 'payout_requests' ) . " WHERE id = {$vqr}" ) );
WPAffiliateCaptain\Payouts\PayoutRequests::fulfil( $vq, 1000, 'EUR' );
check( 'V4 EUR payout settles it', 'processed', $wpdb->get_var( 'SELECT status FROM ' . t( 'payout_requests' ) . " WHERE id = {$vqr}" ) );

// V5 rejecting a commission whose correction sits in a payout is refused.
$vs = sale( 8005, $rc, array( 10000 ) );
refund( 8005, 'v5', array( array( 'item_id' => '1', 'net_minor' => 4000 ) ) );
$vchild = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE parent_id = " . (int) $vs['commission_ids'][0] );
q( "UPDATE {$ct} SET payout_id = 4242 WHERE id = {$vchild}" );
check( 'V5 reject refused while a correction is in a payout', true, is_wp_error( Ledger::reject( (int) $vs['commission_ids'][0], 'Betrug' ) ) );
q( "UPDATE {$ct} SET payout_id = 0 WHERE id = {$vchild}" );
check( 'V5 reject works once the correction is free', true, true === Ledger::reject( (int) $vs['commission_ids'][0], 'Betrug' ) );

// V6 a write-off closes the open balance for erasure purposes (net per currency).
$vw = AffiliateRepository::create( array( 'name' => 'Off Partner', 'email' => 'off@example.de', 'status' => 'active' ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $vw, 'source' => 'test', 'source_order_id' => 'v6', 'kind' => 'refund', 'currency' => 'EUR', 'amount_minor' => -700, 'status' => 'approved', 'idempotency_key' => 'v6-a', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
check( 'V6 negative balance is an open item', true, WPAffiliateCaptain\Privacy\Privacy::has_open_items( $vw ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $vw, 'source' => 'wpac', 'source_order_id' => 'v6', 'kind' => 'writeoff', 'currency' => 'EUR', 'amount_minor' => 700, 'status' => 'approved', 'idempotency_key' => 'v6-b', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
check( 'V6 after the write-off nothing is open', false, WPAffiliateCaptain\Privacy\Privacy::has_open_items( $vw ) );
q( "DELETE FROM {$ct} WHERE affiliate_id = {$vw}" );

// V7 a foreign small business gets reverse charge.
check( 'V7 foreign small business → reverse charge', 'reverse_charge', WPAffiliateCaptain\Payouts\Vat::treatment( 'small_business', 'AT', 'DE', '19' )['mode'] );
check( 'V accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "W VAT per item, corrections of earlier credit notes, operator snapshot, blocks\n";
// W1 small business paid at 0 %, later VAT-registered: the correction keeps 0 % and refers to the old credit note.
$wa = AffiliateRepository::create( array( 'name' => 'Wandel Partner', 'email' => 'wandel@example.de', 'status' => 'active' ) );
ProfileRepository::save( $wa, array( 'tax_status' => 'small_business', 'legal_name' => 'Wandel', 'address' => "Weg 9\n24103 Kiel", 'country' => 'DE', 'tax_number' => '12/345/67890', 'account_holder' => 'Wandel', 'iban' => 'DE89370400440532013000' ) );
$ws = sale( 9001, WPAffiliateCaptain\Codes\CodeRepository::main_code( $wa ), array( 1000000 ) ); // 1.000 € net → 100 € commission.
Ledger::transition( (int) $ws['commission_ids'][0], 'approved' );
$w1 = PayoutService::create_for_affiliate( $wa, gmdate( 'Y-m-d' ), 'EUR' );
check( 'W1 first credit note without VAT', array( 'small_business', 0 ), array( PayoutService::find( $w1 )->vat_mode, (int) PayoutService::find( $w1 )->vat_minor ) );
check( 'W1 item stores its VAT treatment', array( 'small_business', '0', '0' ), array_values( (array) $wpdb->get_row( 'SELECT vat_mode, vat_rate, ref_payout_id FROM ' . t( 'payout_items' ) . " WHERE payout_id = {$w1}", ARRAY_A ) ) );
PayoutService::mark_paid( $w1 );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'operator_address' => "Neue Adresse 5\n24937 Flensburg" ) ) );
Settings::flush_cache();
ProfileRepository::save( $wa, array( 'tax_status' => 'business', 'legal_name' => 'Wandel', 'address' => "Weg 9\n24103 Kiel", 'country' => 'DE', 'vat_id' => 'DE811111111', 'account_holder' => 'Wandel', 'iban' => '' ) );
refund( 9001, 'w1', array( array( 'item_id' => '1', 'net_minor' => 400000 ) ) ); // 40 % refunded → −40 € correction.
$ws2 = sale( 9002, WPAffiliateCaptain\Codes\CodeRepository::main_code( $wa ), array( 1000000 ) ); // 100 € new commission.
Ledger::transition( (int) $ws2['commission_ids'][0], 'approved' );
foreach ( $wpdb->get_col( "SELECT id FROM {$ct} WHERE parent_id = " . (int) $ws['commission_ids'][0] ) as $child ) {
	Ledger::transition( (int) $child, 'approved' );
}
$w2  = PayoutService::create_for_affiliate( $wa, gmdate( 'Y-m-d' ), 'EUR' );
$w2p = PayoutService::find( $w2 );
check( 'W3 VAT only on the new commission (19 % of 100 €), not on the 0 % correction', array( 60000, 19000, 79000 ), array( (int) $w2p->net_minor, (int) $w2p->vat_minor, (int) $w2p->amount_minor ) );
check( 'W2 correction refers to the original credit note and keeps 0 %', array( 'small_business', '0', (string) $w1 ), array_values( (array) $wpdb->get_row( 'SELECT i.vat_mode, i.vat_rate, i.ref_payout_id FROM ' . t( 'payout_items' ) . " i JOIN {$ct} c ON c.id = i.commission_id WHERE i.payout_id = {$w2} AND c.parent_id > 0", ARRAY_A ) ) );
$wpdf = WPAffiliateCaptain\Payouts\Statement::build( $w2p );
check( 'W4 credit note shows the correction block with the original number', true, false !== strpos( $wpdf, 'Correction of credit note ' . PayoutService::find( $w1 )->number ) );
check( 'W4 VAT lines per rate', true, false !== strpos( $wpdf, 'VAT 19 % on' ) && false !== strpos( $wpdf, 'VAT 0 % on' ) );
// W5 operator data frozen per payout: the first credit note still shows the old address.
check( 'W5 new payout shows the new operator address', true, false !== strpos( $wpdf, 'Neue Adresse 5' ) );
check( 'W5 old credit note keeps the address it was issued with', true, false !== strpos( WPAffiliateCaptain\Payouts\Statement::build( PayoutService::find( $w1 ) ), 'Hafenstr. 1' ) && false === strpos( WPAffiliateCaptain\Payouts\Statement::build( PayoutService::find( $w1 ) ), 'Neue Adresse 5' ) );
// W6 reverse charge without the operator's VAT ID is blocked.
$wr = AffiliateRepository::create( array( 'name' => 'RC Partner', 'email' => 'rc@example.at', 'status' => 'active' ) );
ProfileRepository::save( $wr, array( 'tax_status' => 'business', 'legal_name' => 'RC', 'address' => "Ring 2\n1010 Wien", 'country' => 'AT', 'vat_id' => 'ATU22222222', 'account_holder' => 'RC', 'iban' => 'AT611904300234573201' ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'operator_vat_id' => '', 'operator_tax_number' => '12/000/00000' ) ) );
Settings::flush_cache();
check( 'W6 reverse charge needs the operator VAT ID', true, false !== strpos( PayoutService::blocked_reason( $wr ), 'VAT ID' ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'operator_vat_id' => 'DE123456789' ) ) );
// W7 self-billing without a terms page is blocked.
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_page_id' => 0 ) ) );
Settings::flush_cache();
check( 'W7 self-billing without partner terms is blocked', true, false !== strpos( PayoutService::blocked_reason( $wr ), 'partner terms' ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_page_id' => 900 ) ) );
Settings::flush_cache();
check( 'W7 with terms page and accepted terms: not blocked', '', PayoutService::blocked_reason( $wr ) );
check( 'W accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "X monthly mails: failures per month, locked retry, watchdog\n";
delete_option( WPAffiliateCaptain\Reports\MonthlyMail::FAILED );
$GLOBALS['test_mail_fail'] = true;
WPAffiliateCaptain\Reports\MonthlyMail::send( '2026-08', true );
WPAffiliateCaptain\Reports\MonthlyMail::send( '2026-09', true );
check( 'X1 failures of two months are both kept', array( '2026-08', '2026-09' ), array_keys( WPAffiliateCaptain\Reports\MonthlyMail::failures() ) );
$lk = WPAffiliateCaptain\Core\Db::lock( 'wpac_report_retry_lock', 600 );
$GLOBALS['test_mail_fail'] = false;
WPAffiliateCaptain\Reports\MonthlyMail::retry_failed();
check( 'X2 a second retry run waits while one is running', 2, count( WPAffiliateCaptain\Reports\MonthlyMail::failures() ) );
WPAffiliateCaptain\Core\Db::unlock( 'wpac_report_retry_lock', $lk );
$GLOBALS['test_mail_fail'] = true;
WPAffiliateCaptain\Reports\MonthlyMail::retry_failed();
check( 'X2 a failed retry counts the attempt per month', array( 1, 1 ), array_values( array_map( static function ( $f ) { return (int) $f['tries']; }, WPAffiliateCaptain\Reports\MonthlyMail::failures() ) ) );
$GLOBALS['test_mail_fail'] = false;
WPAffiliateCaptain\Reports\MonthlyMail::retry_failed();
check( 'X2 successful retry clears both months', array(), WPAffiliateCaptain\Reports\MonthlyMail::failures() );
update_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS, array( 'month' => '2026-09', 'offset' => 50, 'touched' => time() - 3600 ), false );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Reports\MonthlyMail::watchdog();
check( 'X3 watchdog restarts a stalled batch chain', true, in_array( WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, $GLOBALS['test_scheduled'], true ) );
delete_option( WPAffiliateCaptain\Reports\MonthlyMail::PROGRESS );
// X4 reports count write-offs.
$xw = AffiliateRepository::create( array( 'name' => 'Report Partner', 'email' => 'report@example.de', 'status' => 'active' ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $xw, 'source' => 'wpac', 'source_order_id' => 'x4', 'kind' => 'writeoff', 'currency' => 'EUR', 'amount_minor' => 1234, 'status' => 'approved', 'idempotency_key' => 'x4', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$xr = array_values( array_filter( WPAffiliateCaptain\Reports\Reports::partners( gmdate( 'Y-m-01' ), gmdate( 'Y-m-t' ) ), static function ( $r ) use ( $xw ) { return (int) $r['affiliate_id'] === (int) $xw; } ) );
check( 'X4 partner report shows the write-off', 1234, $xr ? (int) $xr[0]['writeoffs'] : null );
q( "DELETE FROM {$ct} WHERE idempotency_key = 'x4'" );

echo "Y erasure: restriction instead of changed booking records, retention per country\n";
$ye = AffiliateRepository::create( array( 'name' => 'Erase Partner', 'email' => 'erase@example.de', 'status' => 'active' ) );
$yc = WPAffiliateCaptain\Codes\CodeRepository::main_code( $ye );
$ys = sale( 9101, $yc, array( 100000 ) );
refund( 9101, 'y1', array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) );
$ycid = (int) $ys['commission_ids'][0];
q( 'UPDATE ' . t( 'commission_events' ) . " SET reason = 'Kunde Max Muster beschwerte sich' WHERE commission_id = {$ycid}" );
q( 'UPDATE ' . t( 'attributions' ) . " SET note = 'Hinweis von Max Muster' WHERE affiliate_id = {$ye}" );
$wpdb->insert( t( 'payouts' ), array( 'number' => 'GS-Y-1', 'affiliate_id' => $ye, 'period_end' => gmdate( 'Y-m-d' ), 'currency' => 'EUR', 'status' => 'cancelled', 'note' => 'Storno wegen Max Muster', 'created_at' => Db::now() ) );
$ypid = (int) $wpdb->insert_id;
WPAffiliateCaptain\Core\Audit::log( 'payout.cancelled', 'payout', $ypid, array( 'reason' => 'Max Muster' ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $ye, 'source' => 'wpac', 'source_order_id' => 'y-wo', 'kind' => 'writeoff', 'description' => 'Max Muster zahlt nicht', 'currency' => 'EUR', 'amount_minor' => 0, 'status' => 'approved', 'idempotency_key' => 'y-wo', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
set_transient( 'wpac_email_change_' . $ye, array( 'email' => 'neu@example.de', 'token' => 'x' ), DAY_IN_SECONDS );
$yx = WPAffiliateCaptain\Privacy\Privacy::export( 'erase@example.de' );
$groups = array_unique( wp_list_pluck( $yx['data'], 'group_id' ) );
check( 'Y20 export includes attributions, visits/details and commission details', true, in_array( 'wpac-attributions', $groups, true ) && in_array( 'wpac-affiliate-details', $groups, true ) );
check( 'Y20 export shows the pending e-mail change', true, false !== strpos( wp_json_encode( $yx ), 'neu@example.de' ) );
WPAffiliateCaptain\Privacy\Privacy::erase( 'erase@example.de' );
check( 'Y1 commission event reasons stay unchanged during retention', 'Kunde Max Muster beschwerte sich', $wpdb->get_var( 'SELECT reason FROM ' . t( 'commission_events' ) . " WHERE commission_id = {$ycid} AND reason <> '' LIMIT 1" ) );
check( 'Y2 attribution note stays unchanged during retention', 'Hinweis von Max Muster', $wpdb->get_var( 'SELECT note FROM ' . t( 'attributions' ) . " WHERE affiliate_id = {$ye} LIMIT 1" ) );
check( 'Y3 processing is restricted', true, WPAffiliateCaptain\Privacy\Restriction::is_restricted( $ye ) );
check( 'Y21 pending e-mail change removed by the erasure', false, get_transient( 'wpac_email_change_' . $ye ) );

check( 'Y3 the restricted partner cannot be reactivated', true, is_wp_error( AffiliateRepository::set_status( $ye, 'active' ) ) );
// Booking records past the retention period → free texts deleted, agreements removed, restriction lifted.
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 8, 'operator_country' => 'DE', 'retention_hold' => 1 ) ) );
Settings::flush_cache();
q( "UPDATE {$ct} SET created_at = '2014-03-01 10:00:00', updated_at = '2014-03-01 10:00:00' WHERE affiliate_id = {$ye}" );
q( 'UPDATE ' . t( 'payouts' ) . " SET created_at = '2014-03-01 10:00:00' WHERE affiliate_id = {$ye}" );
check( 'Y16 tax audit / legal hold: nothing is deleted', 0, WPAffiliateCaptain\Privacy\Retention::purge_erased() );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'retention_hold' => 0 ) ) );
Settings::flush_cache();
check( 'Y3 after the retention period the erased partner is cleaned up', true, WPAffiliateCaptain\Privacy\Retention::purge_erased() >= 1 );
check( 'Y1 event reasons deleted', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commission_events' ) . " WHERE commission_id = {$ycid} AND reason <> ''" ) );
check( 'Y2 attribution note deleted', '', $wpdb->get_var( 'SELECT note FROM ' . t( 'attributions' ) . " WHERE affiliate_id = {$ye} LIMIT 1" ) );
check( 'Y4 payout note deleted', '', $wpdb->get_var( 'SELECT note FROM ' . t( 'payouts' ) . " WHERE id = {$ypid}" ) );
check( 'Y5 payout change log details deleted', null, $wpdb->get_var( 'SELECT details FROM ' . t( 'audit' ) . " WHERE object_type = 'payout' AND object_id = {$ypid} AND action = 'payout.cancelled'" ) );
check( 'Y6 write-off reason deleted', '', $wpdb->get_var( "SELECT description FROM {$ct} WHERE idempotency_key = 'y-wo'" ) );
check( 'Y18 acceptance records deleted with the booking records', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'agreements' ) . " WHERE affiliate_id = {$ye}" ) );
check( 'Y3 restriction lifted after the clean-up', false, WPAffiliateCaptain\Privacy\Restriction::is_restricted( $ye ) );
delete_option( 'wpac_terms_text_' . WPAffiliateCaptain\Affiliates\Agreements::current()['hash'] );
check( 'Y19 terms text kept when shown (guests: before the e-mail confirmation)', true, WPAffiliateCaptain\Affiliates\Agreements::store_text( WPAffiliateCaptain\Affiliates\Agreements::current() ) && false !== get_option( 'wpac_terms_text_' . WPAffiliateCaptain\Affiliates\Agreements::current()['hash'] ) );
// Y23 public name/website changes of approved partners wait for review.
$yp = AffiliateRepository::create( array( 'name' => 'Public Partner', 'email' => 'public@example.de', 'status' => 'active', 'website' => 'https://old.example' ) );
check( 'Y23 change of an approved partner is pending', 'pending', AffiliateRepository::request_public_change( $yp, 'Ruf mich an 0170-123', 'https://scam.example' ) );
check( 'Y23 the public name stays until approved', 'Public Partner', AffiliateRepository::find( $yp )->name );
AffiliateRepository::resolve_public_change( $yp, false );
check( 'Y23 rejected change is dropped', array( 'Public Partner', null ), array( AffiliateRepository::find( $yp )->name, AffiliateRepository::pending_public_change( $yp ) ) );
AffiliateRepository::request_public_change( $yp, 'Segelschule Nord', 'https://nord.example' );
AffiliateRepository::resolve_public_change( $yp, true );
check( 'Y23 approved change is applied', 'Segelschule Nord', AffiliateRepository::find( $yp )->name );
// Y26 manual restriction (Art. 18): codes stop attributing, no reactivation, lift restores.
$ypc = WPAffiliateCaptain\Codes\CodeRepository::main_code( $yp );
WPAffiliateCaptain\Privacy\Restriction::add( $yp, WPAffiliateCaptain\Privacy\Restriction::REQUEST, 'Richtigkeit bestritten' );
check( 'Y26 restricted partner code attributes nothing', null, wpac_resolve_partner_code( $ypc, array( 'context' => 'link' ) ) );
WPAffiliateCaptain\Privacy\Restriction::remove( $yp );
check( 'Y26 lifted restriction: code works again', true, is_array( wpac_resolve_partner_code( $ypc, array( 'context' => 'link' ) ) ) );
// Y24 objection to a credit note: filed by the owner, refused for others, resolved by the operator.
check( 'Y24 credit note text no longer names a 14-day deadline', false, false !== strpos( WPAffiliateCaptain\Payouts\Statement::build( PayoutService::find( $w2 ) ), '14 days' ) );
check( 'Y24 another partner cannot object', true, is_wp_error( WPAffiliateCaptain\Payouts\Objections::file( $w2, $yp, 'falsch' ) ) );
check( 'Y24 owner objects', true, true === WPAffiliateCaptain\Payouts\Objections::file( $w2, $wa, 'Bestellung 9002 fehlt' ) );
check( 'Y24 objection is open for the operator', true, isset( WPAffiliateCaptain\Payouts\Objections::open()[ $w2 ] ) );
check( 'Y24 resolved objection is closed', array( true, false ), array( WPAffiliateCaptain\Payouts\Objections::resolve( $w2, 'Storniert und neu ausgestellt.' ), isset( WPAffiliateCaptain\Payouts\Objections::open()[ $w2 ] ) ) );
// Y25 VIES: invalid VAT IDs block reverse charge; VIES outage blocks nothing.
Settings::update( array( 'vies_check' => 1 ) ); // Off by default (external request), see ZB.
$GLOBALS['test_http'] = static function ( $url, $args ) { $b = json_decode( $args['body'], true ); return array( 'body' => wp_json_encode( array( 'valid' => 'U22222222' !== $b['vatNumber'] ? true : false, 'requestIdentifier' => 'WAPIAAAA123', 'name' => 'RC GmbH' ) ) ); };
$vr = WPAffiliateCaptain\Payouts\Vies::check( $wr );
check( 'Y25 VIES result stored with the consultation number', array( 'invalid', 'WAPIAAAA123' ), array( $vr['status'], $vr['consultation'] ) );
check( 'Y25 invalid VAT ID blocks reverse charge', true, false !== strpos( PayoutService::blocked_reason( $wr ), 'VIES' ) );
$GLOBALS['test_http'] = static function () { return array( 'body' => wp_json_encode( array( 'errorWrappers' => array( array( 'error' => 'MS_UNAVAILABLE' ) ) ) ) ); };
WPAffiliateCaptain\Payouts\Vies::check( $wr );
check( 'Y25 VIES unavailable: not blocked, checked again later', array( 'unavailable', '' ), array( WPAffiliateCaptain\Payouts\Vies::result( $wr )['status'], PayoutService::blocked_reason( $wr ) ) );
check( 'Y25 Greek VAT IDs use EL, Swiss ones are not checked', array( array( 'EL', '123456789' ), null ), array( WPAffiliateCaptain\Payouts\Vies::split( 'GR123456789' ), WPAffiliateCaptain\Payouts\Vies::split( 'CHE123456789' ) ) );
unset( $GLOBALS['test_http'] );
check( 'Y27 record of processing activities filled with the settings', true, false !== strpos( WPAffiliateCaptain\Privacy\Privacy::ropa(), 'Segelschule Test GmbH' ) && false === strpos( WPAffiliateCaptain\Privacy\Privacy::ropa(), '{retention_years}' ) );
$annual = WPAffiliateCaptain\Payouts\Statement::annual( $wa, (int) gmdate( 'Y' ) );
check( 'Y29 annual overview lists the credit notes of the year with totals', true, 0 === strpos( $annual, '%PDF' ) && false !== strpos( $annual, PayoutService::find( $w2 )->number ) && false !== strpos( $annual, 'Total EUR' ) );
// Jobs: attempts are kept on re-enqueue, "retry all failed", time budget.
WPAffiliateCaptain\Core\Jobs::enqueue( 'nohandler', 'b11', array(), 'e', 'e' );
q( "UPDATE {$jt} SET attempts = 5 WHERE idempotency_key = 'nohandler:b11'" );
WPAffiliateCaptain\Core\Jobs::enqueue( 'nohandler', 'b11', array(), 'e2', 'again' );
check( 'B11 re-enqueue keeps the attempt count', 5, (int) $wpdb->get_var( "SELECT attempts FROM {$jt} WHERE idempotency_key = 'nohandler:b11'" ) );
q( "UPDATE {$jt} SET status = 'failed', attempts = 8 WHERE idempotency_key = 'nohandler:b11'" );
check( 'A05 retry all failed jobs', true, WPAffiliateCaptain\Core\Jobs::retry_all_failed() >= 1 );
check( 'A05 retried job gets a short new series', array( 'queued', 5 ), array( $wpdb->get_var( "SELECT status FROM {$jt} WHERE idempotency_key = 'nohandler:b11'" ), (int) $wpdb->get_var( "SELECT attempts FROM {$jt} WHERE idempotency_key = 'nohandler:b11'" ) ) );
q( "UPDATE {$jt} SET next_attempt_at = NOW() - INTERVAL 1 MINUTE WHERE idempotency_key = 'nohandler:b11'" );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Core\Jobs::run_due( 25, 0.000001 );
check( 'B10 an exhausted time budget schedules the rest in 5 minutes', true, in_array( WPAffiliateCaptain\Core\Jobs::DRAIN_HOOK, $GLOBALS['test_scheduled'], true ) );
q( "DELETE FROM {$jt} WHERE idempotency_key = 'nohandler:b11'" );
// BETR: schema check without case, only own tables, engine as warning; hourly isolation; keyset; claims; markers.
check( 'B01 table names compared without case (own tables found)', array(), WPAffiliateCaptain\Core\Installer::verify_schema() );
q( 'ALTER TABLE ' . t( 'tiers' ) . ' ENGINE=MyISAM' );
check( 'B03 a wrong engine is a warning, not a blocker', array( array(), true ), array( WPAffiliateCaptain\Core\Installer::verify_schema(), (bool) get_option( 'wpac_schema_warnings' ) ) );
q( 'ALTER TABLE ' . t( 'tiers' ) . ' ENGINE=InnoDB' );
WPAffiliateCaptain\Core\Installer::verify_schema();
check( 'B05 alarm only after an hour of failing', true, ( function () { update_option( 'wpac_schema_problems', array( 'x' ) ); update_option( 'wpac_schema_problems_since', time() - 7200 ); delete_transient( 'wpac_schema_alerted' ); $n = count( $GLOBALS['test_mails'] ?? array() ); WPAffiliateCaptain\Core\Installer::alert_if_stuck(); $sent = (bool) get_transient( 'wpac_schema_alerted' ); delete_option( 'wpac_schema_problems' ); delete_option( 'wpac_schema_problems_since' ); return $sent; } )() );
// B12 a second generator of the same credit note waits (lock) – no second file.
$bk = WPAffiliateCaptain\Core\Db::lock( 'wpac_stmt_' . $wr, 600 );
check( 'B12 statement generation is claimed per payout', false, WPAffiliateCaptain\Payouts\Statement::generate( $wr ) );
WPAffiliateCaptain\Core\Db::unlock( 'wpac_stmt_' . $wr, $bk );
// B13 stale markers are removed.
$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpac_inflight_stale_test', 'option_value' => ( time() - 2 * DAY_IN_SECONDS ) . ':x', 'autoload' => 'off' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpac_inflight_fresh_test', 'option_value' => time() . ':y', 'autoload' => 'off' ) );
WPAffiliateCaptain\Core\Cron::purge_inflight();
check( 'B13 stale in-flight markers purged, fresh kept', array( false, true ), array( (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->options} WHERE option_name = 'wpac_inflight_stale_test'" ), (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->options} WHERE option_name = 'wpac_inflight_fresh_test'" ) ) );
q( "DELETE FROM {$wpdb->options} WHERE option_name = 'wpac_inflight_fresh_test'" );
// B14 rate limits use their own table.
check( 'B14 rate-limit counters live in their own table', true, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'rate_limits' ) ) > 0 );
// B08 keyset: a commission that cannot be approved does not stop the others.
$kb = AffiliateRepository::create( array( 'name' => 'Keyset Partner', 'email' => 'keyset@example.de', 'status' => 'active' ) );
$k1 = sale( 9201, WPAffiliateCaptain\Codes\CodeRepository::main_code( $kb ), array( 10000 ) );
refund( 9201, 'k', array( array( 'item_id' => '1', 'net_minor' => 1000 ) ) ); // pending child of a pending parent
$k2 = sale( 9202, WPAffiliateCaptain\Codes\CodeRepository::main_code( $kb ), array( 10000 ) );
q( "UPDATE {$ct} SET hold_until = '2000-01-01 00:00:00' WHERE affiliate_id = {$kb}" );
q( "UPDATE {$ct} SET status = 'review' WHERE id = " . (int) $k1['commission_ids'][0] ); // parent not approvable → child stays
WPAffiliateCaptain\Commissions\Ledger::approve_due();
check( 'B08 later commissions are approved even if earlier ones cannot be', 'approved', $wpdb->get_var( "SELECT status FROM {$ct} WHERE id = " . (int) $k2['commission_ids'][0] ) );
// Monitoring, Site Health, maintenance.
$mc = WPAffiliateCaptain\Core\Monitor::checks();
check( 'B28 Site Health checks cover schema, cron, queue, balances, key', true, isset( $mc['schema'], $mc['cron'], $mc['jobs'], $mc['balances'], $mc['key'] ) );
check( 'B28 Site Health result has a status', true, in_array( WPAffiliateCaptain\Core\Monitor::site_health_result()['status'], array( 'good', 'recommended', 'critical' ), true ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'maintenance_mode' => 1 ) ) );
Settings::flush_cache();
check( 'B33 maintenance mode blocks payouts with a clear reason', true, false !== strpos( PayoutService::blocked_reason( $wa ), 'Maintenance' ) );
check( 'B33 maintenance mode is reported', true, isset( WPAffiliateCaptain\Core\Monitor::checks()['maintenance'] ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
delete_transient( 'wpac_monitor_alerted' );
WPAffiliateCaptain\Core\Monitor::daily_alert();
check( 'B30 daily alarm only once a day when something is critical', true, ! WPAffiliateCaptain\Core\Monitor::problems( true ) || (bool) get_transient( 'wpac_monitor_alerted' ) );
// ABL-02/03 reinstatement after a won chargeback / reactivated order.
$ri = sale( 9301, $rc, array( 50000 ) );
$rid = (int) $ri['commission_ids'][0];
refund( 9301, 'status-chargeback', array(), 'chargeback' );
$sumr = static function () use ( $wpdb, $ct ) { return (int) $wpdb->get_var( "SELECT SUM(amount_minor) FROM {$ct} WHERE source_order_id = '9301' AND status <> 'rejected'" ); };
check( 'A02 chargeback reverses the commission', 0, $sumr() );
check( 'A02 chargeback won → open reversal rejected, commission back', array( 1, 5000 ), array( Ledger::reinstate( 'test', '9301', 'won' ), $sumr() ) );
check( 'A02 reinstatement is idempotent', 0, Ledger::reinstate( 'test', '9301', 'won' ) );
// Reversal already deducted in a payout → positive reinstatement booking.
$ri2 = sale( 9302, $rc, array( 50000 ) );
Ledger::transition( (int) $ri2['commission_ids'][0], 'approved' );
refund( 9302, 'status-cancellation', array(), 'cancellation' );
q( "UPDATE {$ct} SET status = 'paid', payout_id = 777 WHERE source_order_id = '9302'" );
check( 'A03 reactivated order: reversal in a paid payout gets a reinstatement', array( 1, 'reinstatement', 5000 ), array( Ledger::reinstate( 'test', '9302', 'paid again' ), $wpdb->get_var( "SELECT kind FROM {$ct} WHERE source_order_id = '9302' ORDER BY id DESC LIMIT 1" ), (int) $wpdb->get_var( "SELECT amount_minor FROM {$ct} WHERE source_order_id = '9302' ORDER BY id DESC LIMIT 1" ) ) );
check( 'A02 partial refunds are not reinstated', 0, ( function () use ( $rc ) { $p = sale( 9303, $rc, array( 50000 ) ); refund( 9303, 'r-partial', array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ); return Ledger::reinstate( 'test', '9303', 'x' ); } )() );
// ABL-04 re-attribution: A reversed, B booked, refunds afterwards only hit B.
$ra = AffiliateRepository::create( array( 'name' => 'Partner A', 'email' => 'pa@example.de', 'status' => 'active' ) );
$rb = AffiliateRepository::create( array( 'name' => 'Partner B', 'email' => 'pb@example.de', 'status' => 'active' ) );
sale( 9401, WPAffiliateCaptain\Codes\CodeRepository::main_code( $ra ), array( 100000 ) );
$rs = WPAffiliateCaptain\Sales\ContractApi::normalize_sale( array( 'source' => 'test', 'order_id' => '9401', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs 1', 'net_minor' => 100000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde@example.de' ) ) );
$rr = Ledger::reassign( $rs, $rb, 'Partner B hat den Kunden nachweislich vermittelt' );
$net = static function ( $aff ) use ( $wpdb, $ct ) { return (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ct} WHERE source_order_id = '9401' AND affiliate_id = {$aff} AND status <> 'rejected'" ); };
check( 'A04 reassigned: A reversed to 0, B booked 100 €', array( 'created', 0, 10000 ), array( is_array( $rr ) ? $rr['status'] : 'error', $net( $ra ), $net( $rb ) ) );
check( 'A04 attribution now points to B', (int) $rb, (int) Ledger::attribution_for( 'test', '9401' )->affiliate_id );
refund( 9401, 'after', array( array( 'item_id' => '1', 'net_minor' => 50000 ) ) );
check( 'A04 a later refund only reduces B', array( 0, 5000 ), array( $net( $ra ), $net( $rb ) ) );
check( 'A04 reason required; same partner refused', array( true, true ), array( is_wp_error( Ledger::reassign( $rs, $ra, '' ) ), is_wp_error( Ledger::reassign( $rs, $rb, 'x' ) ) ) );
// ABL-06 a refund after the payout was created (not exported) is flagged; rebuild deducts it.
$cb = AffiliateRepository::create( array( 'name' => 'Claw Partner', 'email' => 'claw@example.de', 'status' => 'active' ) );
ProfileRepository::save( $cb, array( 'tax_status' => 'private', 'legal_name' => 'Claw', 'address' => "Weg 1\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'Claw', 'iban' => 'DE89370400440532013000' ) );
$cs = sale( 9501, WPAffiliateCaptain\Codes\CodeRepository::main_code( $cb ), array( 1000000 ) );
Ledger::transition( (int) $cs['commission_ids'][0], 'approved' );
$cp = PayoutService::create_for_affiliate( $cb, gmdate( 'Y-m-d' ), 'EUR' );
refund( 9501, 'late', array( array( 'item_id' => '1', 'net_minor' => 400000 ) ) );
foreach ( $wpdb->get_col( "SELECT id FROM {$ct} WHERE parent_id = " . (int) $cs['commission_ids'][0] ) as $child ) { Ledger::transition( (int) $child, 'approved' ); }
check( 'A06 refund after the payout run is flagged on the unexported payout', true, isset( PayoutService::clawbacks()[ $cp ] ) );
$cp2 = PayoutService::rebuild( $cp );
check( 'A06 rebuilt payout pays 600 € instead of 1.000 €', array( 'cancelled', 60000 ), array( PayoutService::find( $cp )->status, (int) PayoutService::find( $cp2 )->net_minor ) );
// ABL-07 claim back a negative balance of a terminated partner.
$dn = AffiliateRepository::create( array( 'name' => 'Ended Partner', 'email' => 'ended@example.de', 'status' => 'active' ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $dn, 'source' => 'test', 'source_order_id' => 'd1', 'kind' => 'refund', 'currency' => 'EUR', 'amount_minor' => -2500, 'status' => 'approved', 'idempotency_key' => 'd1', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
WPAffiliateCaptain\Commissions\Balance::post( $dn, 'EUR', 'approved', -2500, (int) $wpdb->insert_id, 0, 'test' );
AffiliateRepository::set_status( $dn, 'terminated', 'Ende' );
check( 'A07 terminated partner with negative balance is listed', true, (bool) array_filter( WPAffiliateCaptain\Commissions\Dunning::candidates(), static function ( $c ) use ( $dn ) { return $c['affiliate'] && (int) $c['affiliate']->id === (int) $dn; } ) );
check( 'A07 payment request levels 1, 2, 3, then no more', array( 1, 2, 3, true ), array( WPAffiliateCaptain\Commissions\Dunning::remind( $dn, 'EUR' ), WPAffiliateCaptain\Commissions\Dunning::remind( $dn, 'EUR' ), WPAffiliateCaptain\Commissions\Dunning::remind( $dn, 'EUR' ), is_wp_error( WPAffiliateCaptain\Commissions\Dunning::remind( $dn, 'EUR' ) ) ) );
check( 'A07 repayment larger than the balance refused', true, is_wp_error( WPAffiliateCaptain\Commissions\Dunning::record_repayment( $dn, 'EUR', 3000, 'x' ) ) );
WPAffiliateCaptain\Commissions\Dunning::record_repayment( $dn, 'EUR', 2500, 'Überweisung' );
check( 'A07 repayment settles the balance and ends the dunning', array( 0, array() ), array( WPAffiliateCaptain\Commissions\Dunning::open( $dn, 'EUR' ), WPAffiliateCaptain\Commissions\Dunning::history( $dn, 'EUR' ) ) );
// ABL-09 transfer list for any currency.
$tl = PayoutService::transfer_csv( array( $cp2 ) );
check( 'A09 transfer list has the IBAN and marks the payout exported', array( true, 'exported' ), array( is_array( $tl ) && 'DE89370400440532013000' === $tl[1][0][2], PayoutService::find( $cp2 )->status ) );
check( 'A09 no second export without "export again"', true, is_wp_error( PayoutService::transfer_csv( array( $cp2 ) ) ) );
check( 'A accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );
check( 'Y15 retention per country', array( 8, 7, 10, 10 ), array( WPAffiliateCaptain\Privacy\Retention::minimum_years( 'DE' ), WPAffiliateCaptain\Privacy\Retention::minimum_years( 'AT' ), WPAffiliateCaptain\Privacy\Retention::minimum_years( 'CH' ), WPAffiliateCaptain\Privacy\Retention::minimum_years( 'FR' ) ) );
// Audit log clean-up: commission entries are kept like the other booking records; nothing during a hold.
q( 'DELETE FROM ' . t( 'audit' ) );
$wpdb->insert( t( 'audit' ), array( 'action' => 'commission.adjusted', 'object_type' => 'commission', 'object_id' => 1, 'actor_id' => 0, 'created_at' => '2024-01-01 00:00:00' ) );
$wpdb->insert( t( 'audit' ), array( 'action' => 'code.created', 'object_type' => 'code', 'object_id' => 1, 'actor_id' => 0, 'created_at' => '2024-01-01 00:00:00' ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'audit_retention_days' => 30 ) ) );
Settings::flush_cache();
WPAffiliateCaptain\Core\Cron::daily();
check( 'Y17 commission.* audit entries are kept as booking records', array( 'commission.adjusted' ), $wpdb->get_col( 'SELECT action FROM ' . t( 'audit' ) . " WHERE created_at = '2024-01-01 00:00:00'" ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 10 ) ) );
Settings::flush_cache();

echo "ZC review round 4 (agent C: abuse limits, tokens, opt-in, beacon)\n";
// ZC1 limits per subject (partner, address) do not depend on the client IP.
$zc_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$zc_hits = array( WPAffiliateCaptain\Tracking\RateLimiter::allow_for( 'zc-subj', 42, 2, 60 ), WPAffiliateCaptain\Tracking\RateLimiter::allow_for( 'zc-subj', 42, 2, 60 ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.11';
$zc_hits[] = WPAffiliateCaptain\Tracking\RateLimiter::allow_for( 'zc-subj', 42, 2, 60 );
$zc_hits[] = WPAffiliateCaptain\Tracking\RateLimiter::allow_for( 'zc-subj', 43, 2, 60 );
check( 'ZC1 per-subject limit holds across IPs, other subjects unaffected', array( true, true, false, true ), $zc_hits );
check( 'ZC1 dedup: first hit true, second false', array( true, false ), array( WPAffiliateCaptain\Tracking\RateLimiter::first( 'zc-dedup', 'x', 60 ), WPAffiliateCaptain\Tracking\RateLimiter::first( 'zc-dedup', 'x', 60 ) ) );
check( 'ZC1 dedup counters live in the rate-limit table, not in wp_options', 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%wpac\\_vd\\_%'" ) );
// ZC2 W11/O1: opt-in mails limited per recipient address (case-insensitive), fields capped.
check( 'ZC2 two opt-in mails per address and day, then none', array( true, true, false, true ), array( WPAffiliateCaptain\Affiliates\Registration::mail_allowed( 'zc-target@example.de' ), WPAffiliateCaptain\Affiliates\Registration::mail_allowed( 'ZC-Target@example.de' ), WPAffiliateCaptain\Affiliates\Registration::mail_allowed( 'zc-target@example.de ' ), WPAffiliateCaptain\Affiliates\Registration::mail_allowed( 'zc-other@example.de' ) ) );
check( 'ZC2 application fields are capped', array( 100, 2000 ), array( mb_strlen( WPAffiliateCaptain\Affiliates\Registration::cap( str_repeat( 'ä', 500 ), WPAffiliateCaptain\Affiliates\Registration::MAX_NAME ) ), mb_strlen( WPAffiliateCaptain\Affiliates\Registration::cap( str_repeat( 'x', 9000 ), WPAffiliateCaptain\Affiliates\Registration::MAX_NOTE ) ) ) );
check( 'ZC2 opt-in mail takes no applicant text (address + link only)', 2, ( new ReflectionMethod( 'WPAffiliateCaptain\Affiliates\Notifications', 'confirm_application' ) )->getNumberOfParameters() );
$GLOBALS['test_mails'] = array();
WPAffiliateCaptain\Affiliates\Notifications::confirm_application( 'zc-target@example.de', 'https://example.de/?wpac_confirm=abc' );
check( 'ZC2 opt-in mail goes to the address', 'zc-target@example.de', $GLOBALS['test_mails'][0][0] ?? '' );
// ZC3 W33: confirmation token states (GET shows a button; a second click shows success).
check( 'ZC3 unknown token is expired', 'expired', WPAffiliateCaptain\Affiliates\Registration::token_state( 'zc-unknown' ) );
set_transient( 'wpac_app_' . hash( 'sha256', 'zc-token' ), array( 'email' => 'zc@example.de', 'user_id' => 0 ), 3600 );
check( 'ZC3 stored application is pending (viewing does not consume it)', array( 'pending', 'pending' ), array( WPAffiliateCaptain\Affiliates\Registration::token_state( 'zc-token' ), WPAffiliateCaptain\Affiliates\Registration::token_state( 'zc-token' ) ) );
delete_transient( 'wpac_app_' . hash( 'sha256', 'zc-token' ) );
set_transient( 'wpac_appdone_' . hash( 'sha256', 'zc-token' ), 1, 3600 );
check( 'ZC3 confirmed token shows success again', 'done', WPAffiliateCaptain\Affiliates\Registration::token_state( 'zc-token' ) );
// ZC4 O3: e-mail change token stored only as a hash; tokens stored by older versions still work.
$zc_hash = WPAffiliateCaptain\Portal\Portal::email_token_hash( 'AbcDef123' );
check( 'ZC4 hash is not the token', false, false !== strpos( $zc_hash, 'abcdef123' ) );
check( 'ZC4 hashed token matches (case-insensitive like the link), wrong token not', array( true, true, false, false ), array( WPAffiliateCaptain\Portal\Portal::email_token_matches( array( 'token_hash' => $zc_hash ), 'abcdef123' ), WPAffiliateCaptain\Portal\Portal::email_token_matches( array( 'token' => 'abcdef123' ), 'abcdef123' ), WPAffiliateCaptain\Portal\Portal::email_token_matches( array( 'token_hash' => $zc_hash ), 'abcdef124' ), WPAffiliateCaptain\Portal\Portal::email_token_matches( array( 'token_hash' => $zc_hash ), '' ) ) );
// ZC5 O3: monitoring token hashed; plaintext of older versions is hashed on first read.
$zc_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $zc_settings, array( 'monitor_token' => 'PlainToken12345678901' ) ) );
Settings::flush_cache();
check( 'ZC5 legacy plaintext token hashed on read and still accepted', array( true, true, false ), array( 0 === strpos( (string) Settings::get( 'monitor_token' ), Settings::MONITOR_HASH ), Settings::monitor_token_matches( 'PlainToken12345678901' ), Settings::monitor_token_matches( 'PlainToken12345678902' ) ) );
check( 'ZC5 hash persisted', true, 0 === strpos( (string) get_option( 'wpac_settings' )['monitor_token'], Settings::MONITOR_HASH ) );
$zc_new = Settings::sanitize( array( 'monitor_token' => 'NewToken1234567890123' ) );
check( 'ZC5 new token stored as hash', array( true, false ), array( $zc_new['monitor_token'] === Settings::monitor_token_hash( 'NewToken1234567890123' ), false !== strpos( $zc_new['monitor_token'], 'NewToken' ) ) );
check( 'ZC5 empty field keeps the token, "remove" clears it', array( Settings::get( 'monitor_token' ), '' ), array( Settings::sanitize( array( 'monitor_token' => '' ) )['monitor_token'], Settings::sanitize( array( 'monitor_token' => '', 'monitor_token_clear' => '1' ) )['monitor_token'] ) );
update_option( 'wpac_settings', $zc_settings );
Settings::flush_cache();
// ZC6 O10: unsigned (legacy/forged) cookies are never accepted.
update_option( 'wpac_cookie_signed_since', time() );
check( 'ZC6 unsigned cookie rejected even with the old transition option', null, WPAffiliateCaptain\Tracking\Tracker::parse_cookie( 'ABCD-EFGH||0|' . time() ) );
delete_option( 'wpac_cookie_signed_since' );
// ZC7 O14: beacon script is the same on every request; per-request values only in the data block.
$zc_cfg = array( 'url' => 'https://example.de/wp-json/wpac/v1/touch', 'param' => 'partner', 'ref' => 0 );
check( 'ZC7 static beacon script without "gen"', array( true, false ), array( WPAffiliateCaptain\Tracking\Tracker::beacon_script( $zc_cfg ) === WPAffiliateCaptain\Tracking\Tracker::beacon_script( $zc_cfg ), false !== strpos( WPAffiliateCaptain\Tracking\Tracker::beacon_script( $zc_cfg ), '"gen"' ) ) );
check( 'ZC7 time only on pages that captured the referral', array( array( 'content' => 5 ), array( 'content' => 5, 'cap' => 1, 'gen' => 1000 ) ), array( WPAffiliateCaptain\Tracking\Tracker::beacon_data( 5, false, 1000 ), WPAffiliateCaptain\Tracking\Tracker::beacon_data( 5, true, 1000 ) ) );
// ZC8 W10: block checkout code check is rate limited (same limit as the classic checkout).
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$zc_last = true;
for ( $zc_i = 0; $zc_i < 22; $zc_i++ ) {
	$zc_last = WPAffiliateCaptain\Integrations\WooCommerce\CheckoutField::blocks_validate( 'ZCNOPE' . $zc_i );
}
check( 'ZC8 block checkout refuses after 20 code checks per minute', 'Too many attempts. Please wait a minute and try again.', $zc_last instanceof WP_Error ? $zc_last->get_error_message() : 'no error' );
check( 'ZC8 empty block field is valid', true, WPAffiliateCaptain\Integrations\WooCommerce\CheckoutField::blocks_validate( '' ) );
// ZC9 W13: without WPML/Polylang the page itself is used; with WPML its translation.
check( 'ZC9 page id unchanged without a multilingual plugin', 12, WPAffiliateCaptain\Core\Locale::page_id( 12, 'de_DE' ) );
check( 'ZC9 partner locale falls back to the site language', 'en_US', WPAffiliateCaptain\Core\Locale::for_affiliate( (object) array( 'user_id' => 0 ) ) );
if ( null === $zc_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $zc_ip; }
// ---------------------------------------------------------------------------------------------
// ZA: review round 4 (reassignment, restriction, renewals, payouts, dunning, payout methods).
// ---------------------------------------------------------------------------------------------
echo "ZA review round 4 fixes (ledger, renewals, payouts, payout methods)\n";
function za_set( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
}
$za1 = AffiliateRepository::create( array( 'name' => 'ZA Eins', 'email' => 'za1@example.de', 'status' => 'active' ) );
$za2 = AffiliateRepository::create( array( 'name' => 'ZA Zwei', 'email' => 'za2@example.de', 'status' => 'active' ) );
$za3 = AffiliateRepository::create( array( 'name' => 'ZA Drei', 'email' => 'za3@example.de', 'status' => 'active' ) );

// W2: refunds after a reassignment belong to the new partner.
$zs    = sale( 'za-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $za1 ), array( 10000 ), 'kaeufer-za@example.de' );
$zsale = WPAffiliateCaptain\Sales\ContractApi::normalize_sale( array( 'source' => 'test', 'order_id' => 'za-1', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs 1', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kaeufer-za@example.de' ) ) );
$zr    = Ledger::reassign( $zsale, $za2, 'Kunde hat sich geirrt' );
check( 'ZA01 reassignment books the order for the new partner', array( 'created', 0, true ), array( is_array( $zr ) ? $zr['status'] : 'error', sum_for( $za1 ), sum_for( $za2 ) > 0 ) );
$za2_before = sum_for( $za2 );
refund( 'za-1', 'za-r1', array( array( 'item_id' => '1', 'net_minor' => 5000 ) ) );
check( 'ZA02 a later refund is booked for the new partner only', array( 0, intdiv( $za2_before, 2 ) ), array( sum_for( $za1 ), sum_for( $za2 ) ) );
$za2_after = sum_for( $za2 );
// W2: the new booking fails → nothing changes for the current partner.
add_filter( 'wpac_commission_base', static function () { throw new RuntimeException( 'boom' ); } );
$zr2 = Ledger::reassign( $zsale, $za3, 'Test Fehler' );
remove_all_filters( 'wpac_commission_base' );
check( 'ZA03 failed reassignment is an error and rolls the reversal back', array( true, $za2_after, 0, $za2 ), array( is_wp_error( $zr2 ), sum_for( $za2 ), sum_for( $za3 ), (int) Ledger::attribution_for( 'test', 'za-1' )->affiliate_id ) );
// W2: self-referral of the new partner is refused before anything is reversed.
$zr3 = Ledger::reassign( array_merge( $zsale, array( 'customer' => array( 'user_id' => 0, 'email' => 'za3@example.de' ) ) ), $za3, 'Selbst' );
check( 'ZA04 reassignment to the buyer (self-referral) is refused, nothing reversed', array( true, $za2_after ), array( is_wp_error( $zr3 ), sum_for( $za2 ) ) );

// W5: restricted partners earn nothing new, also via affiliate_id (integrations, renewals).
WPAffiliateCaptain\Privacy\Restriction::add( $za3, WPAffiliateCaptain\Privacy\Restriction::REQUEST, 'Test' );
$zw5 = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'za-5', 'currency' => 'EUR', 'affiliate_id' => $za3, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'k5@example.de' ) ) );
check( 'ZA05 restricted partner gets no commission via affiliate_id', 'unattributed', is_array( $zw5 ) ? $zw5['status'] : 'error' );
check( 'ZA06 reassignment to a restricted partner refused', true, is_wp_error( Ledger::reassign( $zsale, $za3, 'x' ) ) );
WPAffiliateCaptain\Privacy\Restriction::remove( $za3 );

// W29: readable kind and method labels.
check( 'ZA07 kind labels for every kind', array( 'Reinstatement', 'Repayment by the partner', 'Partner code' ), array( Ledger::kind_label( 'reinstatement' ), Ledger::kind_label( 'repayment' ), Ledger::method_label( 'code' ) ) );

// W3/W4: renewals – copied partner code/touch are ignored, the subscription's partner counts, also on retry.
class ZA_Order {
	public $id;
	public $meta;
	public $paid   = true;
	public $parent = 0;
	public function __construct( $id, $meta = array() ) { $this->id = $id; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save_meta_data() {}
	public function add_order_note( $n ) { $GLOBALS['za_notes'][] = $n; }
	public function is_paid() { return $this->paid; }
	public function get_currency() { return 'EUR'; }
	public function get_refunds() { return array(); }
	public function get_items( $t = 'line_item' ) { return 'line_item' === $t ? array( 1 => new ZA_Item() ) : array(); }
	public function get_date_paid() { return null; }
	public function get_date_created() { return null; }
	public function get_coupon_codes() { return array(); }
	public function get_customer_id() { return 0; }
	public function get_billing_email() { return 'abo@example.de'; }
	public function get_parent_id() { return $this->parent; }
	public function get_total() { return '50.00'; }
	public function get_total_tax() { return '0.00'; }
	public function get_shipping_total() { return '0.00'; }
	public function get_status() { return 'processing'; }
	public function has_status( $s ) { return in_array( 'processing', (array) $s, true ); }
}
class ZA_Item {
	public function get_meta( $k ) { return ''; }
	public function get_total_tax() { return '0.00'; }
	public function get_product() { return null; }
	public function get_variation_id() { return 0; }
	public function get_product_id() { return 0; }
	public function get_name() { return 'Abo'; }
	public function get_quantity() { return 1; }
	public function get_total() { return '50.00'; }
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) { return isset( $GLOBALS['za_orders'][ (int) $id ] ) ? $GLOBALS['za_orders'][ (int) $id ] : false; }
}
wpac_record_sale( array( 'source' => 'woocommerce', 'order_id' => '7001', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $za1 ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'abo@example.de' ) ) );
$zsub         = new ZA_Order( 7002 );
$zsub->parent = 7001;
$zcode2       = WPAffiliateCaptain\Codes\CodeRepository::main_code( $za2 );
$zrenewal     = new ZA_Order( 7003, array( '_subscription_renewal' => 7002, '_wpac_partner_code' => $zcode2, '_wpac_touch' => array( 'code' => $zcode2, 'method' => 'link' ), '_wpac_resolved' => 'none' ) );
$GLOBALS['za_orders'] = array( 7002 => $zsub, 7003 => $zrenewal );
$zp = WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::payload( $zrenewal );
check( 'ZA08 renewal payload ignores the copied code/touch and uses the subscription partner', array( '', null, $za1, 'renewal' ), array( $zp['partner_code'], $zp['touch'], $zp['affiliate_id'], isset( $zp['attribution_method'] ) ? $zp['attribution_method'] : '' ) );
WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::run_job( false, 'woo_order', array( 'order_id' => 7003 ) );
$za_attr = Ledger::attribution_for( 'woocommerce', '7003' );
check( 'ZA09 retried renewal job keeps the subscription partner', array( $za1, 'renewal' ), array( $za_attr ? (int) $za_attr->affiliate_id : 0, $za_attr ? $za_attr->method : '' ) );
$zcopied = WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::exclude_subscription_meta( array( '_wpac_partner_code' => 'X', '_wpac_touch' => array(), '_wc_other/wp-affiliate-captain/partner-code' => 'X', '_billing_email' => 'a@b.de' ) );
$zrows   = WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::exclude_subscription_meta( array( array( 'meta_key' => '_wpac_resolved', 'meta_value' => 'none' ), array( 'meta_key' => '_order_key', 'meta_value' => 'k' ) ) );
check( 'ZA10 subscription copies leave out the plugin meta (both filter shapes)', array( array( '_billing_email' ), array( array( 'meta_key' => '_order_key', 'meta_value' => 'k' ) ) ), array( array_keys( $zcopied ), $zrows ) );
$zrenewal2 = new ZA_Order( 7004, array( '_subscription_renewal' => 7002, '_wpac_manual_affiliate' => $za2 ) );
$zrenewal3 = new ZA_Order( 7005, array( '_subscription_renewal' => 7002, '_wpac_manual_affiliate' => $za2, '_wpac_manual_own' => 7005 ) );
check( 'ZA11 manual assignment copied to a renewal does not count, one made on the renewal does', array( 0, $za2 ), array( WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::manual_affiliate( $zrenewal2 ), WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::manual_affiliate( $zrenewal3 ) ) );
// O9: the reinstatement retry job is handled (idempotent, nothing to do here).
$GLOBALS['za_orders'][7001] = new ZA_Order( 7001 );
check( 'ZA12 reinstatement retry job is handled', true, WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::run_job( false, 'woo_reinstate', array( 'order_id' => 7001 ) ) );

// W8: rebuild never leaves a cancellation behind when the payout cannot be created again.
$zb = AffiliateRepository::create( array( 'name' => 'ZA Rebuild', 'email' => 'zarebuild@example.de', 'status' => 'active' ) );
ProfileRepository::save( $zb, array( 'tax_status' => 'private', 'legal_name' => 'ZA Rebuild', 'address' => "Weg 2\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'ZA Rebuild', 'iban' => 'DE89370400440532013000' ) );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $zb );
$zbs = sale( 'za-8', WPAffiliateCaptain\Codes\CodeRepository::main_code( $zb ), array( 1000000 ) );
Ledger::transition( (int) $zbs['commission_ids'][0], 'approved' );
$zbp = PayoutService::create_for_affiliate( $zb, gmdate( 'Y-m-d' ), 'EUR' );
za_set( array( 'maintenance_mode' => 1 ) );
$zbr = PayoutService::rebuild( $zbp );
za_set( array( 'maintenance_mode' => 0 ) );
check( 'ZA13 blocked rebuild is refused before cancelling', array( true, 'pending', '' ), array( is_wp_error( $zbr ), PayoutService::find( $zbp )->status, (string) PayoutService::find( $zbp )->storno_number ) );
refund( 'za-8', 'za-8r', array(), 'refund' );
foreach ( $wpdb->get_col( "SELECT id FROM {$ct} WHERE parent_id = " . (int) $zbs['commission_ids'][0] ) as $zchild ) {
	Ledger::transition( (int) $zchild, 'approved' );
}
$zstorno_before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(value),0) FROM ' . t( 'sequences' ) . " WHERE name = 'storno'" );
$zbr2           = PayoutService::rebuild( $zbp );
check( 'ZA14 rebuild with nothing payable any more rolls back (no cancellation, no number used)', array( true, 'pending', $zstorno_before ), array( is_wp_error( $zbr2 ), PayoutService::find( $zbp )->status, (int) $wpdb->get_var( 'SELECT COALESCE(MAX(value),0) FROM ' . t( 'sequences' ) . " WHERE name = 'storno'" ) ) );

// W9: failure entries of cancelled payouts are dropped.
PayoutService::cancel( $zbp, 'Test' );
update_option( WPAffiliateCaptain\Payouts\Statement::RETRY_OPTION, array( $zbp => array( 'n' => 3, 'next' => time() + 3600 ) ) );
check( 'ZA15 statement failures of cancelled payouts no longer alarm', array( array(), array() ), array( WPAffiliateCaptain\Payouts\Statement::waiting(), get_option( WPAffiliateCaptain\Payouts\Statement::RETRY_OPTION ) ) );

// O7: annual overview years, including years with only a cancellation.
$wpdb->update( t( 'payouts' ), array( 'created_at' => '2023-12-30 10:00:00', 'statement_file' => 'za.pdf', 'storno_number' => 'ST-2024-000099', 'storno_at' => '2024-01-02 10:00:00' ), array( 'id' => $zbp ) );
check( 'ZA16 annual overview years include the cancellation year', array( 2024, 2023 ), WPAffiliateCaptain\Payouts\Statement::annual_years( $zb ) );

// W6: statements follow the same retention as the booking records (DE: 8 years, not 10).
za_set( array( 'operator_country' => 'DE', 'statement_retention_years' => 8 ) );
$zold = (int) gmdate( 'Y' ) - 10;
$wpdb->update( t( 'payouts' ), array( 'created_at' => $zold . '-06-01 10:00:00', 'storno_at' => $zold . '-07-01 10:00:00', 'statement_file' => '', 'storno_file' => '' ), array( 'id' => $zbp ) );
WPAffiliateCaptain\Payouts\Statement::purge_expired();
check( 'ZA17 statement retention uses Retention::cutoff() (8 years in DE)', true, null !== PayoutService::find( $zbp )->statement_deleted_at );
za_set( array( 'statement_retention_years' => 10 ) );

// W30 / O10: no payment request without the company account or to erased/restricted partners.
$zd = AffiliateRepository::create( array( 'name' => 'ZA Ende', 'email' => 'zaende@example.de', 'status' => 'active' ) );
$wpdb->insert( $ct, array( 'affiliate_id' => $zd, 'source' => 'test', 'source_order_id' => 'zd1', 'kind' => 'refund', 'currency' => 'EUR', 'amount_minor' => -1500, 'status' => 'approved', 'idempotency_key' => 'zd1', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
WPAffiliateCaptain\Commissions\Balance::post( $zd, 'EUR', 'approved', -1500, (int) $wpdb->insert_id, 0, 'test' );
AffiliateRepository::set_status( $zd, 'terminated', 'Ende' );
$ziban = Settings::get( 'debtor_iban' );
za_set( array( 'debtor_iban' => '' ) );
$zdr = WPAffiliateCaptain\Commissions\Dunning::remind( $zd, 'EUR' );
za_set( array( 'debtor_iban' => $ziban ) );
check( 'ZA18 payment request without company IBAN refused', array( true, 'wpac_dunning_blocked', array() ), array( is_wp_error( $zdr ), is_wp_error( $zdr ) ? $zdr->get_error_code() : '', WPAffiliateCaptain\Commissions\Dunning::history( $zd, 'EUR' ) ) );
WPAffiliateCaptain\Privacy\Restriction::add( $zd, WPAffiliateCaptain\Privacy\Restriction::ERASURE );
$zlisted = (bool) array_filter( WPAffiliateCaptain\Commissions\Dunning::candidates(), static function ( $c ) use ( $zd ) { return (int) $c['affiliate']->id === (int) $zd; } );
check( 'ZA19 erased/restricted partners are neither listed nor reminded', array( false, true ), array( $zlisted, is_wp_error( WPAffiliateCaptain\Commissions\Dunning::remind( $zd, 'EUR' ) ) ) );
WPAffiliateCaptain\Privacy\Restriction::remove( $zd );

// W37: second payout method "manual" (e.g. PayPal).
$zm    = AffiliateRepository::create( array( 'name' => 'ZA PayPal', 'email' => 'zapaypal@example.de', 'status' => 'active' ) );
$zbase = array( 'tax_status' => 'private', 'legal_name' => 'ZA PayPal', 'address' => "Weg 3\n24103 Kiel", 'country' => 'DE' );
$zerr  = ProfileRepository::save( $zm, $zbase + array( 'payout_method' => 'manual' ) );
check( 'ZA20 manual method needs payment details', 'wpac_payment_details', is_wp_error( $zerr ) ? $zerr->get_error_code() : '' );
ProfileRepository::save( $zm, $zbase + array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: zapaypal@example.de' ) );
$zmp = ProfileRepository::get( $zm );
check( 'ZA21 manual profile: complete without IBAN, details encrypted, no IBAN', array( 'manual', true, 'PayPal: zapaypal@example.de', null, false ), array( ProfileRepository::method( $zmp ), ProfileRepository::is_complete( $zmp ), ProfileRepository::payment_details( $zmp ), ProfileRepository::iban( $zmp ), false !== strpos( (string) $zmp->iban_encrypted, 'PayPal' ) ) );
ProfileRepository::save( $zm, $zbase );
check( 'ZA22 saving without a method keeps the method and details', array( 'manual', 'PayPal: zapaypal@example.de' ), array( ProfileRepository::method( ProfileRepository::get( $zm ) ), ProfileRepository::payment_details( ProfileRepository::get( $zm ) ) ) );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $zm );
$zms = sale( 'za-37', WPAffiliateCaptain\Codes\CodeRepository::main_code( $zm ), array( 100000 ) );
Ledger::transition( (int) $zms['commission_ids'][0], 'approved' );
$zmpay = PayoutService::create_for_affiliate( $zm, gmdate( 'Y-m-d' ), 'EUR' );
$zsnap = json_decode( (string) PayoutService::find( $zmpay )->profile_snapshot, true );
check( 'ZA23 payout and credit note for the manual method (no details in the snapshot)', array( true, 'manual', false ), array( $zmpay > 0, $zsnap['payout_method'], false !== strpos( (string) PayoutService::find( $zmpay )->profile_snapshot, 'zapaypal@' ) ) );
$zsepa = PayoutService::sepa_export( array( $zmpay ) );
check( 'ZA24 manual payouts are left out of the SEPA file', array( true, 'pending' ), array( is_wp_error( $zsepa ), PayoutService::find( $zmpay )->status ) );
$ztl = PayoutService::transfer_csv( array( $zmpay ) );
check( 'ZA25 transfer list carries the payment details', array( '', 'manual', 'PayPal: zapaypal@example.de' ), is_array( $ztl ) ? array( $ztl[1][0][2], $ztl[1][0][7], $ztl[1][0][8] ) : array() );
check( 'ZA26 manual payout can be marked as paid', array( true, 'paid' ), array( true === PayoutService::mark_paid( $zmpay ), PayoutService::find( $zmpay )->status ) );
$zerr = ProfileRepository::save( $zm, $zbase + array( 'account_holder' => 'ZA', 'payout_method' => 'sepa' ) );
check( 'ZA27 switching back to SEPA needs an IBAN', 'wpac_iban_required', is_wp_error( $zerr ) ? $zerr->get_error_code() : '' );
ProfileRepository::save( $zm, $zbase + array( 'account_holder' => 'ZA', 'payout_method' => 'sepa', 'iban' => 'DE89370400440532013000' ) );
check( 'ZA28 back to SEPA with IBAN', array( 'sepa', 'DE89370400440532013000', null ), array( ProfileRepository::method( ProfileRepository::get( $zm ) ), ProfileRepository::iban( ProfileRepository::get( $zm ) ), ProfileRepository::payment_details( ProfileRepository::get( $zm ) ) ) );
check( 'ZA accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );
/* ZB: scheduler, cron, migrations, runtime state, VIES switch, option lists (review round 4, agent B) */
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $r ) { return null; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['test_scheduled'][] = $hook; return true; }
}
// ZB-W17 runtime state lives in its own options; the cron never writes the settings array back.
Settings::update( array( 'tier_month_closed' => '2020-01' ) );
check( 'ZB-W17 runtime state is stored in its own option', array( '2020-01', false ), array( get_option( 'wpac_tier_month_closed' ), array_key_exists( 'tier_month_closed', (array) get_option( 'wpac_settings' ) ) ) );
check( 'ZB-W17 Settings::get still reads the runtime state', '2020-01', Settings::get( 'tier_month_closed' ) );
delete_option( 'wpac_tier_month_first' );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'tier_month_first' => '2019-05' ) ) );
Settings::flush_cache();
check( 'ZB-W17 value of an older version is still read from the settings', '2019-05', Settings::get( 'tier_month_first' ) );
update_option( 'wpac_db_version', 8 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'ZB-W17 v9 migration moves the runtime state out of the settings', array( '2019-05', false, WPAC_DB_VERSION ), array( get_option( 'wpac_tier_month_first' ), array_key_exists( 'tier_month_first', (array) get_option( 'wpac_settings' ) ), (int) get_option( 'wpac_db_version' ) ) );
update_option( 'wpac_tier_month_closed', gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -3 month' ) ) );
$zb_settings = get_option( 'wpac_settings' );
WPAffiliateCaptain\Commissions\TierService::close_due_months();
check( 'ZB-W17 closing months leaves the settings array untouched', $zb_settings, get_option( 'wpac_settings' ) );
// ZB-O13 recalculation of closed months has a time budget; the rest comes first next time.
WPAffiliateCaptain\Commissions\TierService::recalculate_closed_months( 0.000001 );
$zb_pending = get_option( WPAffiliateCaptain\Commissions\TierService::RECALC_PENDING );
check( 'ZB-O13 months beyond the time budget are remembered', true, is_array( $zb_pending ) && count( $zb_pending ) >= 2 );
WPAffiliateCaptain\Commissions\TierService::recalculate_closed_months( 0 );
check( 'ZB-O13 remembered months are done in the next run', false, get_option( WPAffiliateCaptain\Commissions\TierService::RECALC_PENDING ) );
check( 'ZB-O13 index on commissions.updated_at', true, (bool) $wpdb->get_var( 'SHOW INDEX FROM ' . t( 'commissions' ) . " WHERE Key_name = 'updated_at'" ) );

// ZB-K4 VIES check is a setting: off → nothing is sent, nothing is blocked.
$zb_http = 0;
$GLOBALS['test_http'] = static function () use ( &$zb_http ) { ++$zb_http; return array( 'body' => wp_json_encode( array( 'valid' => false, 'requestIdentifier' => 'X' ) ) ); };
Settings::update( array( 'vies_check' => 0 ) );
$zb_rc = AffiliateRepository::create( array( 'name' => 'ZB VIES', 'email' => 'zb-vies@example.at', 'status' => 'active' ) );
ProfileRepository::save( $zb_rc, array( 'tax_status' => 'business', 'legal_name' => 'ZB', 'address' => "Ring 3\n1010 Wien", 'country' => 'AT', 'vat_id' => 'ATU33333333', 'account_holder' => 'ZB', 'iban' => 'AT611904300234573201' ) );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Payouts\Vies::schedule( $zb_rc );
check( 'ZB-K4 VIES off: check sends nothing, schedules nothing', array( null, 0, array(), 0 ), array( WPAffiliateCaptain\Payouts\Vies::check( $zb_rc ), $zb_http, $GLOBALS['test_scheduled'], WPAffiliateCaptain\Payouts\Vies::recheck_stale() ) );
update_option( 'wpac_vies_' . $zb_rc, array( 'vat_id' => 'ATU33333333', 'status' => 'invalid', 'name' => '', 'consultation' => '', 'checked_at' => Db::now() ) );
check( 'ZB-K4 VIES off: an old "invalid" result does not block', false, WPAffiliateCaptain\Payouts\Vies::is_invalid( $zb_rc, 'ATU33333333' ) );
check( 'ZB-K4 VIES check is off for new installs', 0, Settings::defaults()['vies_check'] );
$zb_s = get_option( 'wpac_settings' );
unset( $zb_s['vies_check'] );
update_option( 'wpac_settings', $zb_s );
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 'a:0:{}', 'off')", 'wpac_vies_' . $zb_rc ) );
update_option( 'wpac_db_version', 8 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'ZB-K4 upgrade with existing VIES results keeps the check on', 1, (int) Settings::get( 'vies_check' ) );
// ZB-W14 VIES re-checks run in the background, "unavailable" with growing pauses.
$zb_un = array( 'vat_id' => 'ATU33333333', 'status' => 'unavailable', 'name' => '', 'consultation' => '', 'checked_at' => Db::now( time() - 2 * DAY_IN_SECONDS ), 'tries' => 3 );
update_option( 'wpac_vies_' . $zb_rc, $zb_un );
check( 'ZB-W14 third outage: next try after 4 days, not daily', time() + 2 * DAY_IN_SECONDS, WPAffiliateCaptain\Payouts\Vies::next_check( $zb_un ) );
$zb_un['tries'] = 1;
update_option( 'wpac_vies_' . $zb_rc, $zb_un );
$GLOBALS['test_scheduled'] = array();
$zb_http                   = 0;
$zb_n                      = WPAffiliateCaptain\Payouts\Vies::recheck_stale();
check( 'ZB-W14 due re-checks are scheduled, not run inline', array( true, 0, true ), array( $zb_n >= 1, $zb_http, in_array( WPAffiliateCaptain\Payouts\Vies::HOOK, $GLOBALS['test_scheduled'], true ) ) );
WPAffiliateCaptain\Payouts\Vies::check( $zb_rc );
$GLOBALS['test_http'] = static function () { return array( 'body' => wp_json_encode( array( 'errorWrappers' => array( array( 'error' => 'MS_UNAVAILABLE' ) ) ) ) ); };
WPAffiliateCaptain\Payouts\Vies::check( $zb_rc );
WPAffiliateCaptain\Payouts\Vies::check( $zb_rc );
check( 'ZB-W14 consecutive outages are counted', array( 'unavailable', 2 ), array( WPAffiliateCaptain\Payouts\Vies::result( $zb_rc )['status'], WPAffiliateCaptain\Payouts\Vies::result( $zb_rc )['tries'] ) );
unset( $GLOBALS['test_http'] );
// ZB-W14 daily run: privacy first, the rest continues separately when the budget is used up.
add_filter( 'wpac_daily_budget', static function () { return 0.000001; } );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Core\Cron::daily();
$zb_rest = get_option( WPAffiliateCaptain\Core\Cron::DAILY_REST_OPTION );
check( 'ZB-W14 daily run starts with the privacy clean-up and hands the rest on', array( 'visits', false, true ), array( array_keys( WPAffiliateCaptain\Core\Cron::daily_steps() )[0], in_array( 'visits', (array) $zb_rest, true ), in_array( WPAffiliateCaptain\Core\Cron::DAILY_REST, $GLOBALS['test_scheduled'], true ) ) );
remove_all_filters( 'wpac_daily_budget' );
WPAffiliateCaptain\Core\Cron::daily_rest();
check( 'ZB-W14 the rest of the daily run is done', false, get_option( WPAffiliateCaptain\Core\Cron::DAILY_REST_OPTION ) );

// ZB-W20 personal options go with the erasure; expired ones are purged daily.
$zb_er = AffiliateRepository::create( array( 'name' => 'ZB Erase', 'email' => 'zb-erase@example.de', 'status' => 'active' ) );
foreach ( array( 'wpac_vies_' . $zb_er, 'wpac_public_change_' . $zb_er, 'wpac_dunning_' . $zb_er . '_EUR' ) as $zb_o ) {
	update_option( $zb_o, array( 'x' => 1 ) );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 'x', 'off')", $zb_o ) );
}
WPAffiliateCaptain\Privacy\Privacy::erase( 'zb-erase@example.de' );
check( 'ZB-W20 erasure removes VIES result, public change request and payment requests', array( false, false, false ), array( get_option( 'wpac_vies_' . $zb_er ), get_option( 'wpac_public_change_' . $zb_er ), get_option( 'wpac_dunning_' . $zb_er . '_EUR' ) ) );
foreach ( array( 'wpac_prev_email_9101' => array( 'email' => 'old@example.de', 'until' => time() - 10 ), 'wpac_prev_email_9102' => array( 'email' => 'new@example.de', 'until' => time() + 999 ), 'wpac_user_link_9103' => array( 'hash' => 'h', 'expires' => time() - 10 ) ) as $zb_o => $zb_v ) {
	update_option( $zb_o, $zb_v );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 'x', 'off')", $zb_o ) );
}
check( 'ZB-W20 expired previous addresses and link tokens are purged', array( 2, false, true, false ), array( WPAffiliateCaptain\Privacy\Privacy::purge_expired_options(), get_option( 'wpac_prev_email_9101' ), is_array( get_option( 'wpac_prev_email_9102' ) ), get_option( 'wpac_user_link_9103' ) ) );

// ZB-O17 option lists change under a lock and are cleaned up.
WPAffiliateCaptain\Privacy\Restriction::add( 987654, WPAffiliateCaptain\Privacy\Restriction::REQUEST, 'x' );
check( 'ZB-O17 restriction stored, lock released', array( true, null ), array( WPAffiliateCaptain\Privacy\Restriction::is_restricted( 987654 ), $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpac_restricted_lock'" ) ) );
check( 'ZB-O17 restrictions of partners that no longer exist are dropped', array( true, false ), array( WPAffiliateCaptain\Privacy\Restriction::purge() >= 1, WPAffiliateCaptain\Privacy\Restriction::is_restricted( 987654 ) ) );
update_option( 'wpac_objections', array_replace( WPAffiliateCaptain\Payouts\Objections::all(), array( 987654 => array( 'affiliate_id' => 1, 'at' => Db::now(), 'reason' => 'x', 'status' => 'open', 'resolution' => '', 'resolved_at' => '' ) ) ) );
check( 'ZB-O17 objections of deleted payouts are dropped', array( true, false ), array( WPAffiliateCaptain\Payouts\Objections::purge() >= 1, isset( WPAffiliateCaptain\Payouts\Objections::all()[987654] ) ) );
// A lock held by a request that died is taken over after its time-to-live.
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('wpac_zb_lock', %s, 'off')", ( time() - 5 ) . ':dead' ) );
check( 'ZB-O17 a stale lock is taken over (waits up to its TTL)', 'ran', Db::with_lock( 'wpac_zb_lock', static function () { return 'ran'; }, 3 ) );

// ZB-O12 expired customer links are deleted in batches (index on expires_at).
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => 1, 'customer_hash' => str_repeat( 'z', 64 ), 'source' => 'test', 'source_order_id' => 'zb', 'created_at' => Db::now(), 'expires_at' => Db::now( time() - 60 ) ) );
check( 'ZB-O12 expired customer link deleted', array( 1, true ), array( WPAffiliateCaptain\Tracking\CustomerLinks::purge_expired(), (bool) $wpdb->get_var( 'SHOW INDEX FROM ' . t( 'customer_links' ) . " WHERE Key_name = 'expires_at'" ) ) );

// ZB-O16 queue drain and report batches wait during maintenance.
$wpdb->insert( t( 'jobs' ), array( 'type' => 'zb', 'idempotency_key' => 'zb:1', 'payload' => '{}', 'status' => 'queued', 'attempts' => 1, 'next_attempt_at' => Db::now( time() - 60 ), 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$zb_job = (int) $wpdb->insert_id;
Settings::update( array( 'maintenance_mode' => 1 ) );
WPAffiliateCaptain\Core\Jobs::drain();
update_option( 'wpac_report_progress', array( 'month' => '2026-01', 'offset' => 0, 'touched' => time() ) );
$zb_mails                  = count( $GLOBALS['test_mails'] );
$GLOBALS['test_scheduled'] = array();
WPAffiliateCaptain\Reports\MonthlyMail::batch();
check( 'ZB-O16 maintenance: queue untouched, report batch postponed', array( 'queued', $zb_mails, 0, true ), array( $wpdb->get_var( 'SELECT status FROM ' . t( 'jobs' ) . " WHERE id = {$zb_job}" ), count( $GLOBALS['test_mails'] ), (int) get_option( 'wpac_report_progress' )['offset'], in_array( WPAffiliateCaptain\Reports\MonthlyMail::BATCH_HOOK, $GLOBALS['test_scheduled'], true ) ) );
Settings::update( array( 'maintenance_mode' => 0 ) );
delete_option( 'wpac_report_progress' );
$wpdb->delete( t( 'jobs' ), array( 'id' => $zb_job ) );

// ZB-O20 a downgrade is detected and reported.
update_option( 'wpac_db_version', WPAC_DB_VERSION + 1 );
check( 'ZB-O20 downgrade detected (critical in Site Health)', array( WPAC_DB_VERSION + 1, 'critical' ), array( WPAffiliateCaptain\Core\Installer::downgraded_from(), WPAffiliateCaptain\Core\Monitor::checks()['schema'][0] ) );
update_option( 'wpac_db_version', WPAC_DB_VERSION );
check( 'ZB-O20 no downgrade on the current schema', 0, WPAffiliateCaptain\Core\Installer::downgraded_from() );

// ZB-W7 one migration at a time (also WP-CLI); the lock can be extended in the same second.
$zb_other = Db::lock( 'wpac_upgrade_lock', 900 );
check( 'ZB-W7 no migration while another request holds the lock', false, WPAffiliateCaptain\Core\Installer::upgrade() );
check( 'ZB-W7 lock refresh in the same second keeps ownership', $zb_other, Db::refresh( 'wpac_upgrade_lock', $zb_other ) );
Db::unlock( 'wpac_upgrade_lock', $zb_other );
check( 'ZB-W7 migration runs and releases the lock', array( true, null ), array( WPAffiliateCaptain\Core\Installer::upgrade(), $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpac_upgrade_lock'" ) ) );

// ZB-W15 deactivation does not save rewrite rules (rebuilt without the plugin on the next request).
update_option( 'rewrite_rules', array( '^partner/([^/]+)/?$' => 'index.php?x=$matches[1]' ) );
WPAffiliateCaptain\Core\Installer::deactivate_site();
check( 'ZB-W15 deactivation drops the stored rewrite rules', false, get_option( 'rewrite_rules' ) );
WPAffiliateCaptain\Core\Cron::schedule();

// ZB isolated: Action Scheduler, multisite statement folders and uninstall run in their own process.
$zb_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
putenv( 'WPAC_TEST_DB_PREFIX=zbu_' );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zb-isolated.php' ) . ' 2>&1', $zb_out, $zb_rc );
putenv( false === $zb_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $zb_prefix );
$zb_res = json_decode( (string) end( $zb_out ), true );
if ( ! is_array( $zb_res ) ) {
	check( 'ZB isolated checks ran', 'json', implode( "\n", $zb_out ) );
} else {
	foreach ( $zb_res as $zb_name => $zb_pair ) {
		check( $zb_name, $zb_pair[0], $zb_pair[1] );
	}
}
// ZD round-4 fixes (settings validation, encryption key, terms, payouts, labels).
if ( ! function_exists( 'wp_lostpassword_url' ) ) { function wp_lostpassword_url( $r = '' ) { return 'http://example.test/wp-login.php?action=lostpassword'; } }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }
echo "ZD round 4: settings, keys, terms, payouts\n";
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_page_id' => 900, 'self_billing' => 1 ) ) );
Settings::flush_cache();
$zd_keep = get_option( 'wpac_settings' );
// K2: common inputs are understood.
$zd = Settings::sanitize( array( 'default_rate_type' => 'percent', 'default_rate' => '15 %', 'vat_rate' => '20 %', 'lifetime_rate' => '15,5', 'min_payout' => '1.000,00 €', 'fraud_max_order' => '1.000 €' ), $zd_err );
check( 'ZD K2 "15 %", "20 %", "15,5", "1.000,00 €" and "1.000 €" are accepted', array( '15', '20', '15.5', 100000, 100000, array() ), array( $zd['default_rate'], $zd['vat_rate'], $zd['lifetime_rate'], $zd['min_payout_minor'], $zd['fraud_max_order_minor'], $zd_err ) );
$zd = Settings::sanitize( array( 'default_rate_type' => 'fixed', 'default_rate' => '25 €' ), $zd_err );
check( 'ZD K2 fixed default rate "25 €" = 2500 minor units', array( 'fixed', '2500', array() ), array( $zd['default_rate_type'], $zd['default_rate'], $zd_err ) );
check( 'ZD K2 amount parser: "€ 25,50", "1,000.00", "EUR 7"', array( '25.50', '1000.00', '7' ), array( Settings::parse_amount( '€ 25,50' ), Settings::parse_amount( '1,000.00' ), Settings::parse_amount( 'EUR 7' ) ) );
// Invalid input: error per field, stored value kept (not a default).
update_option( 'wpac_settings', array_merge( $zd_keep, array( 'vat_rate' => '7.7', 'default_rate' => '12', 'default_rate_type' => 'percent', 'operator_country' => 'AT', 'monitor_token' => str_repeat( 'a', 30 ), 'debtor_iban' => 'DE89370400440532013000' ) ) );
Settings::flush_cache();
$zd = Settings::sanitize( array( 'vat_rate' => 'abc', 'default_rate_type' => 'percent', 'default_rate' => '150 %', 'operator_country' => 'XX', 'monitor_token' => 'short', 'debtor_iban' => 'DE00123', 'cookie_days' => 'zehn' ), $zd_err, $zd_notes );
check( 'ZD K2 invalid values keep the stored value', array( '7.7', '12', 'AT', Settings::monitor_token_hash( str_repeat( 'a', 30 ) ), 'DE89370400440532013000', 30 ), array( $zd['vat_rate'], $zd['default_rate'], $zd['operator_country'], $zd['monitor_token'], $zd['debtor_iban'], $zd['cookie_days'] ) );
check( 'ZD K2 every rejected field has its own message', array( 'cookie_days', 'debtor_iban', 'default_rate', 'monitor_token', 'operator_country', 'vat_rate' ), ( function ( $e ) { $k = array_keys( $e ); sort( $k ); return $k; } )( $zd_err ) );
$zd = Settings::sanitize( array( 'statement_retention_years' => '3', 'operator_country' => 'CH' ), $zd_err, $zd_notes );
check( 'ZD K2 retention raised to the legal minimum is reported, not silent', array( 10, true, array() ), array( $zd['statement_retention_years'], isset( $zd_notes['statement_retention_years'] ), $zd_err ) );
check( 'ZD K2 country list has the EU, CH, GB and US', array( true, true, true, true, true ), array( isset( Settings::countries()['DE'] ), isset( Settings::countries()['FR'] ), isset( Settings::countries()['CH'] ), isset( Settings::countries()['GB'] ), isset( Settings::countries()['US'] ) ) );
update_option( 'wpac_settings', $zd_keep );
Settings::flush_cache();

// W25: only the version decides whether the terms must be accepted again.
$zd_a = AffiliateRepository::create( array( 'name' => 'ZD Terms', 'email' => 'zd-terms@example.de', 'status' => 'active' ) );
q( 'DELETE FROM ' . t( 'agreements' ) . ' WHERE affiliate_id = ' . (int) $zd_a ); // The fixture hook accepts automatically.
check( 'ZD W25 admin-created partner without acceptance is listed as missing', true, in_array( $zd_a, WPAffiliateCaptain\Affiliates\Agreements::missing_current()[0], true ) );
$zd_terms         = WPAffiliateCaptain\Affiliates\Agreements::current( 0 );
$zd_terms['hash'] = hash( 'sha256', 'older page text' ); // Page edited after the acceptance.
WPAffiliateCaptain\Affiliates\Agreements::record( $zd_a, 'accepted', $zd_terms );
check( 'ZD W25 editing the page without a new version keeps the acceptance', array( true, false ), array( WPAffiliateCaptain\Affiliates\Agreements::has_current( $zd_a ), in_array( $zd_a, WPAffiliateCaptain\Affiliates\Agreements::missing_current()[0], true ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_version' => '2.0' ) ) );
Settings::flush_cache();
check( 'ZD W25 a new version asks for acceptance again', false, WPAffiliateCaptain\Affiliates\Agreements::has_current( $zd_a ) );
delete_option( WPAffiliateCaptain\Affiliates\Agreements::REMIND_OPTION );
$GLOBALS['test_mails'] = array();
$zd_n = WPAffiliateCaptain\Affiliates\Agreements::start_reminders();
$zd_mails = array_filter( $GLOBALS['test_mails'], static function ( $m ) { return 'zd-terms@example.de' === $m[0]; } );
check( 'ZD W25 reminder run mails partners without the current acceptance', array( true, 1 ), array( is_int( $zd_n ) && $zd_n >= 1, count( $zd_mails ) ) );
check( 'ZD W25 a second run within 24 hours is refused', 'wpac_rate_limited', ( function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : $r; } )( WPAffiliateCaptain\Affiliates\Agreements::start_reminders() ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'terms_version' => $zd_keep['terms_version'] ) ) );
Settings::flush_cache();

// W26: the request button is not offered when the payout would be refused (terms missing).
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'payout_requests' => 1, 'self_billing' => 1 ) ) );
Settings::flush_cache();
$zd_b = AffiliateRepository::create( array( 'name' => 'ZD Request', 'email' => 'zd-request@example.de', 'status' => 'active' ) );
q( 'DELETE FROM ' . t( 'agreements' ) . ' WHERE affiliate_id = ' . (int) $zd_b );
ProfileRepository::save( $zd_b, array( 'tax_status' => 'private', 'legal_name' => 'ZD', 'address' => "Weg 2\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'ZD', 'iban' => 'DE89370400440532013000' ) );
$zd_can = WPAffiliateCaptain\Payouts\PayoutRequests::can_request( AffiliateRepository::find( $zd_b ) );
check( 'ZD W26 no request without accepted terms; the partner is told why', true, is_string( $zd_can ) && false !== strpos( $zd_can, 'terms' ) );
$zd_code = '';
PayoutService::blocked_reason( $zd_b, null, $zd_code );
check( 'ZD W23 blocked reason has a code for the fix link', 'terms', $zd_code );
check( 'ZD W23 missing profile field is named', true, false !== strpos( PayoutService::blocked_reason( $zd_a ), 'missing' ) );
update_option( 'wpac_settings', $zd_keep );
Settings::flush_cache();

// W27: the payout run reports per partner why it was not paid.
$zd_run = PayoutService::create_run( gmdate( 'Y-m-d' ), 'EUR' );
check( 'ZD W27 payout run returns details for partners that were not paid', true, isset( $zd_run[2] ) && is_array( $zd_run[2] ) && count( $zd_run[2] ) === $zd_run[1] );

// W21: link requests and manual linking refuse what is not possible, with a reason.
$zd_r = AffiliateRepository::resend_user_link( $zd_b );
check( 'ZD W21 resend refused when there is no account with the e-mail', 'wpac_no_user', is_wp_error( $zd_r ) ? $zd_r->get_error_code() : $zd_r );
$zd_r = AffiliateRepository::link_user( $zd_b, 'nobody@example.de' );
check( 'ZD W21 manual link to an unknown user is refused', 'wpac_no_user', is_wp_error( $zd_r ) ? $zd_r->get_error_code() : $zd_r );
check( 'ZD O30 no set-password link without a created account', '', AffiliateRepository::set_password_url( $zd_b ) );
$GLOBALS['test_mails'] = array();
WPAffiliateCaptain\Affiliates\Notifications::partner_created( $zd_b );
check( 'ZD W22 admin-created partner gets one welcome mail', array( array( 'zd-request@example.de', '[Test Shop] Welcome to our partner program' ) ), $GLOBALS['test_mails'] );

// O28: readable labels.
check( 'ZD O28 month in the monthly mail is "October 2026", not "2026-10"', 'October 2026', WPAffiliateCaptain\Reports\MonthlyMail::month_label( '2026-10' ) );
check( 'ZD O28 audit actions and details are readable', array( 'Status changed', 'from: Application pending; to: Active; Reason: ok' ), array( WPAffiliateCaptain\Admin\Pages\AuditPage::action_label( 'affiliate.status' ), WPAffiliateCaptain\Admin\Pages\AuditPage::details_text( '{"from":"pending","to":"active","reason":"ok"}' ) ) );

// K3: a dedicated key set later keeps old bank details readable; re-encryption moves them over.
$zd_old_cipher = WPAffiliateCaptain\Core\Crypto::encrypt( 'DE89370400440532013000' ); // Salt-derived key.
$wpdb->update( t( 'payout_profiles' ), array( 'iban_encrypted' => $zd_old_cipher ), array( 'affiliate_id' => $zd_b ) );
define( 'WPAC_ENCRYPTION_KEY', str_repeat( 'ab', 32 ) );
check( 'ZD K3 old value still readable after WPAC_ENCRYPTION_KEY was set', array( 'DE89370400440532013000', true ), array( WPAffiliateCaptain\Core\Crypto::decrypt( $zd_old_cipher ), WPAffiliateCaptain\Core\Crypto::needs_reencrypt( $zd_old_cipher ) ) );
check( 'ZD K3 legacy values are counted', true, WPAffiliateCaptain\Core\Crypto::legacy_status()[0] >= 1 );
delete_option( 'wpac_reencrypt_cursor' );
WPAffiliateCaptain\Core\Crypto::reencrypt_batch( 500 );
$zd_new_cipher = (string) $wpdb->get_var( 'SELECT iban_encrypted FROM ' . t( 'payout_profiles' ) . ' WHERE affiliate_id = ' . (int) $zd_b );
check( 'ZD K3 re-encrypted with the new key, still the same IBAN', array( false, 'DE89370400440532013000', 0 ), array( WPAffiliateCaptain\Core\Crypto::needs_reencrypt( $zd_new_cipher ), WPAffiliateCaptain\Core\Crypto::decrypt( $zd_new_cipher ), WPAffiliateCaptain\Core\Crypto::legacy_status()[0] ) );
// K3: statements in the old folder are still found after WPAC_STATEMENT_DIR was set, and can be moved.
$zd_up = sys_get_temp_dir() . '/wpac-uploads/wpac-statements';
@mkdir( $zd_up, 0777, true );
file_put_contents( $zd_up . '/zd-test.pdf', '%PDF zd' );
$zd_pid = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'payouts' ) . " WHERE statement_file <> '' ORDER BY id DESC LIMIT 1" );
$zd_old_file = (string) $wpdb->get_var( 'SELECT statement_file FROM ' . t( 'payouts' ) . ' WHERE id = ' . $zd_pid );
$wpdb->update( t( 'payouts' ), array( 'statement_file' => 'zd-test.pdf' ), array( 'id' => $zd_pid ) );
$zd_dir = sys_get_temp_dir() . '/wpac-zd-statements-' . getmypid();
define( 'WPAC_STATEMENT_DIR', $zd_dir );
check( 'ZD K3 statement in the old folder is still found', $zd_up . '/zd-test.pdf', WPAffiliateCaptain\Payouts\Statement::path( 'zd-test.pdf' ) );
check( 'ZD K3 misplaced statements are listed', true, (bool) array_filter( WPAffiliateCaptain\Payouts\Statement::misplaced(), static function ( $m ) { return 'zd-test.pdf' === $m[1]; } ) );
WPAffiliateCaptain\Payouts\Statement::move_misplaced();
check( 'ZD K3 statements moved to the new folder', array( true, false ), array( is_readable( $zd_dir . '/zd-test.pdf' ), file_exists( $zd_up . '/zd-test.pdf' ) ) );
$wpdb->update( t( 'payouts' ), array( 'statement_file' => $zd_old_file ), array( 'id' => $zd_pid ) );
foreach ( (array) glob( $zd_dir . '/*' ) as $zd_f ) { @unlink( $zd_f ); }
@unlink( $zd_dir . '/.htaccess' );
@rmdir( $zd_dir );
// ZE: CSV import, program export, REST permission callbacks.
require __DIR__ . '/ze-import-rest.php';

// ---------------------------------------------------------------------------------------------
// ZF: free core (K5/A4/A5) – add-on extension points and no network access in core flows.
// ---------------------------------------------------------------------------------------------
echo "ZF free core: add-on hooks, core works alone without network\n";
$GLOBALS['test_http_calls'] = array();
unset( $GLOBALS['test_http'] ); // Every outgoing request fails ("offline").
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'min_payout_minor' => 0, 'vies_check' => 0 ) ) );
Settings::flush_cache();
$zf = AffiliateRepository::create( array( 'name' => 'ZF Partner', 'email' => 'zf@example.de', 'status' => 'active' ) );
ProfileRepository::save( $zf, array( 'tax_status' => 'private', 'legal_name' => 'ZF Partner', 'address' => "Mole 3\n24937 Flensburg", 'country' => 'DE', 'account_holder' => 'ZF Partner', 'iban' => 'DE89370400440532013000' ) );
$zf_code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $zf );
sale( 'zf-1', $zf_code, array( 10000 ), 'kaeufer-zf1@example.de' );
$zf_plain = sum_for( $zf );
add_filter(
	'wpac_commission_amount',
	static function ( $amount, $item, $affiliate_id ) use ( $zf ) {
		return $affiliate_id === $zf ? $amount + 123 : $amount;
	},
	10,
	3
);
sale( 'zf-2', $zf_code, array( 10000 ), 'kaeufer-zf2@example.de' );
remove_all_filters( 'wpac_commission_amount' );
check( 'ZF1 wpac_commission_amount changes the commission of an add-on rule', 2 * $zf_plain + 123, sum_for( $zf ) );
foreach ( $GLOBALS['wpdb']->get_col( 'SELECT id FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $zf ) as $zf_cid ) {
	Ledger::transition( (int) $zf_cid, 'approved' );
}
$zf_created = array();
add_action(
	'wpac_payout_created',
	static function ( $payout_id, $affiliate_id ) use ( &$zf_created ) {
		$zf_created[] = array( $payout_id, $affiliate_id );
	},
	10,
	2
);
$zf_pid = PayoutService::create_for_affiliate( $zf, gmdate( 'Y-m-d' ), 'EUR' );
remove_all_filters( 'wpac_payout_created' );
check( 'ZF2 wpac_payout_created fires once with payout and partner', array( array( $zf_pid, $zf ) ), $zf_created );
add_filter(
	'wpac_transfer_rows',
	static function ( $rows ) {
		foreach ( $rows as $i => $row ) {
			$rows[ $i ][] = 'X-' . $i;
		}
		return $rows;
	}
);
$zf_csv = PayoutService::transfer_csv( array( $zf_pid ) );
remove_all_filters( 'wpac_transfer_rows' );
check( 'ZF3 wpac_transfer_rows adds a column to the transfer list', array( 1, 'X-0' ), is_array( $zf_csv ) ? array( count( $zf_csv[1] ), end( $zf_csv[1][0] ) ) : array() );
add_filter(
	'wpac_report_partners',
	static function ( $rows ) {
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['addon'] = 'yes';
		}
		return $rows;
	}
);
$zf_rep = WPAffiliateCaptain\Reports\Reports::partners( gmdate( 'Y-m-01', strtotime( '-1 year' ) ), gmdate( 'Y-m-d', strtotime( '+1 day' ) ), $zf );
remove_all_filters( 'wpac_report_partners' );
check( 'ZF4 wpac_report_partners extends the partner report', 'yes', isset( $zf_rep[0]['addon'] ) ? $zf_rep[0]['addon'] : '' );
if ( WPAffiliateCaptain\Migration\ProgramExport::available() ) {
	add_filter(
		'wpac_program_export_files',
		static function ( $files ) {
			$files['addon.csv']   = "id;value\n1;a\n2;b\n";
			$files['partners.csv'] = 'must not replace a core file';
			return $files;
		}
	);
	$zf_zip    = sys_get_temp_dir() . '/wpac-zf-export.zip';
	$zf_counts = WPAffiliateCaptain\Migration\ProgramExport::write( $zf_zip, false );
	remove_all_filters( 'wpac_program_export_files' );
	$zf_z = new ZipArchive();
	$zf_z->open( $zf_zip );
	check( 'ZF5 wpac_program_export_files adds files, core files stay', array( 2, "id;value\n1;a\n2;b\n", false ), array( is_array( $zf_counts ) ? $zf_counts['addon.csv'] : null, $zf_z->getFromName( 'addon.csv' ), 'must not replace a core file' === $zf_z->getFromName( 'partners.csv' ) ) );
	$zf_z->close();
	@unlink( $zf_zip );
}
// A5: refunds, payouts, statements, exports and the daily maintenance run without any network
// access; the core never contacts a license server or any other external host.
refund( 'zf-2', 'zf-r1', array( array( 'item_id' => '1', 'net_minor' => 5000 ) ) );
WPAffiliateCaptain\Core\Cron::daily();
$zf_hosts = array_values( array_unique( array_map( static function ( $u ) { return (string) parse_url( (string) $u, PHP_URL_HOST ); }, $GLOBALS['test_http_calls'] ) ) );
check( 'ZF6 core flows make no outgoing requests (no license server, VIES off)', array(), $zf_hosts );
require __DIR__ . '/r8-g2.php';
require __DIR__ . '/r8-g1.php';
require __DIR__ . '/r8-g6.php';
require __DIR__ . '/r8-g4b.php';
require __DIR__ . '/r8-g4a.php';
require __DIR__ . '/r8-g5.php';
require __DIR__ . '/r8-g3.php';
require __DIR__ . '/r8-g11.php';
require __DIR__ . '/r8-g9.php';
require __DIR__ . '/r8-g8.php';
require __DIR__ . '/r8-g7.php';
require __DIR__ . '/r8-g10.php';
require __DIR__ . '/r9-h1.php';
require __DIR__ . '/r9-h2.php';
require __DIR__ . '/r9-h3.php';
require __DIR__ . '/r10-j1.php';
require __DIR__ . '/r8-render.php'; // Last: its template stubs must not pre-empt the stubs of the files above.
check( 'ZF accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );

echo "\n{$count} integration checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
