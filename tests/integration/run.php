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
ProfileRepository::save( $aff, array( 'tax_status' => 'private', 'country' => 'DE', 'account_holder' => 'Anna Partner', 'iban' => 'DE89370400440532013000' ) );

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
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'approved', created_at = '2026-10-15 10:00:00', hold_until = '2026-10-16' WHERE id = {$cid}" );
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
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'approved' WHERE parent_id = {$cid}" );
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
ProfileRepository::save( $biz, array( 'tax_status' => 'business', 'country' => 'DE', 'vat_id' => 'DE999999999', 'account_holder' => 'Segelschule Nord GmbH', 'iban' => 'DE89370400440532013000', 'address' => "Kai 2\n24937 Flensburg" ) );
$bcode = WPAffiliateCaptain\Codes\CodeRepository::main_code( $biz );
$r     = sale( 2001, $bcode, array( 1000000 ) ); // 1.000 € commission (10 % of 10.000 €).
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'approved' WHERE id = " . (int) $r['commission_ids'][0] );
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
ProfileRepository::save( $at, array( 'tax_status' => 'business', 'country' => 'AT', 'vat_id' => 'ATU12345678', 'account_holder' => 'Wien Partner', 'iban' => 'AT611904300234573201' ) );
$r = sale( 2002, WPAffiliateCaptain\Codes\CodeRepository::main_code( $at ), array( 1000000 ) );
q( 'UPDATE ' . t( 'commissions' ) . " SET status = 'approved' WHERE id = " . (int) $r['commission_ids'][0] );
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
check( 'J version stored', 4, (int) get_option( 'wpac_db_version' ) );

/* L: schema migration v3 → v4 (payouts) ----------------------------------- */
$po = t( 'payouts' );
q( "ALTER TABLE {$po} DROP INDEX number, ADD KEY number (number), DROP INDEX storno_number, DROP COLUMN net_minor, DROP COLUMN vat_mode, DROP COLUMN vat_rate, DROP COLUMN vat_minor, DROP COLUMN storno_number, DROP COLUMN storno_file, DROP COLUMN storno_hash, DROP COLUMN storno_at" );
update_option( 'wpac_db_version', 3 );
WPAffiliateCaptain\Core\Installer::install_schema();
check( 'L number unique again', '0', (string) $GLOBALS['wpdb']->get_row( "SHOW INDEX FROM {$po} WHERE Key_name = 'number'" )->Non_unique );
check( 'L storno column back', 'storno_number', (string) $GLOBALS['wpdb']->get_var( "SHOW COLUMNS FROM {$po} LIKE 'storno_number'" ) );
check( 'L old payouts: net = amount', 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$po} WHERE net_minor <> amount_minor" ) );

echo "\n{$count} integration checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
