<?php
/**
 * Integration checks of work package J1 (rest of R5-W10): a paid WooCommerce order that is
 * increased after its commission was booked earns the difference automatically. Included from
 * tests/integration/run.php after r8-g3.php (uses its WooCommerce stand-ins G3_*).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Privacy\Restriction;

echo "R10-J1 increased orders after payment\n";

global $wpdb;
$j1c = Db::table( 'commissions' );

$j1_order = static function ( $id, $items, $total, $meta ) {
	$o = new G3_Order( $id, $items, $total, $meta );
	$GLOBALS['za_orders'][ $id ] = $o;
	return $o;
};
$j1_item = static function ( $total, $product_id = 0, $qty = 1 ) {
	$i             = new G3_Item( $total );
	$i->product_id = $product_id;
	$i->qty        = $qty;
	return $i;
};
$j1_sum = static function ( $order ) use ( $wpdb, $j1c ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$j1c} WHERE source = 'woocommerce' AND source_order_id = %s AND status <> 'rejected'", (string) $order ) );
};
$j1_rows = static function ( $order ) use ( $wpdb, $j1c ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$j1c} WHERE source = 'woocommerce' AND source_order_id = %s AND kind = 'sale' ORDER BY id ASC", (string) $order ) );
};
$j1_note = static function ( $o, $text ) {
	return false !== strpos( (string) end( $o->notes ), $text );
};
$j1_notes = static function ( $o, $text ) {
	$n = 0;
	foreach ( $o->notes as $note ) {
		$n += false !== strpos( (string) $note, $text ) ? 1 : 0;
	}
	return $n;
};

$j1a    = AffiliateRepository::create( array( 'name' => 'J1 Partner', 'email' => 'j1-partner@example.de', 'status' => 'active' ) );
$j1meta = array( '_wpac_partner_code' => CodeRepository::main_code( $j1a ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $j1a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'j1' );
check( 'R5-W10 setting book_order_increase is on by default', 1, (int) Settings::get( 'book_order_increase' ) );

/* Increase of a line: the difference is booked once, linked to the line and partner. ------- */
$o = $j1_order( 101001, array( 1 => $j1_item( '100.00', 9101 ) ), '100.00', $j1meta );
OrderSync::sync_order( 101001 );
$o->items[1]->total = '150.00';
$o->items[1]->qty   = 3;
$o->total           = '150.00';
OrderSync::items_changed( 101001 );
$rows = $j1_rows( 101001 );
$snap = json_decode( (string) end( $rows )->rule_snapshot, true );
check( 'R5-W10 increased line: the difference is booked', 1500, $j1_sum( 101001 ) );
check(
	'R5-W10 increased line: own pending sale row of the same line and partner, linked to the original',
	array( 2, 'pending', 5000, 500, '1', $j1a, (int) $rows[0]->id, 1000 ),
	array( count( $rows ), end( $rows )->status, (int) end( $rows )->base_minor, (int) end( $rows )->amount_minor, (string) end( $rows )->source_item_id, (int) end( $rows )->affiliate_id, (int) $snap['increase_of'], (int) $rows[0]->amount_minor )
);
check( 'R5-W10 increased line: order note and audit entry', array( true, 1 ), array( $j1_note( $o, 'additional partner commission' ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'audit' ) . " WHERE action = 'commission.increased' AND object_id = %d", 101001 ) ) ) );
check( 'R5-W10 increased line: attribution unchanged', $j1a, (int) Ledger::attribution_for( 'woocommerce', '101001' )->affiliate_id );

/* Idempotence: saving again books nothing and adds no note. ------------------------------- */
$notes = count( $o->notes );
OrderSync::items_changed( 101001 );
OrderSync::items_changed( 101001 );
check( 'R5-W10 idempotent: saving the order again books nothing', array( 1500, 2, $notes ), array( $j1_sum( 101001 ), count( $j1_rows( 101001 ) ), count( $o->notes ) ) );

/* New line: booked with the rules of today; an existing line keeps its stored rule. -------- */
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $j1a, 'target' => 'product', 'target_value' => '9102', 'rate_type' => 'percent', 'rate' => '20', 'priority' => 0, 'active' => 1 ) ), 0, 'j1' );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $j1a, 'target' => 'product', 'target_value' => '9101', 'rate_type' => 'percent', 'rate' => '30', 'priority' => 0, 'active' => 1 ) ), 0, 'j1' );
$o->items[2] = $j1_item( '50.00', 9102 );
$o->total    = '200.00';
OrderSync::items_changed( 101001 );
$rows = $j1_rows( 101001 );
check( 'R5-W10 new line after payment: booked like a sale line (product rule 20 %)', array( 2500, 3, '2', 1000, 'sale:woocommerce:101001:2' ), array( $j1_sum( 101001 ), count( $rows ), (string) end( $rows )->source_item_id, (int) end( $rows )->amount_minor, (string) end( $rows )->idempotency_key ) );
$o->items[1]->total = '160.00';
$o->total           = '210.00';
OrderSync::items_changed( 101001 );
check( 'R5-W10 increase of an existing line keeps the rate stored with it (10 %, not the new 30 % rule)', 2600, $j1_sum( 101001 ) );

/* A refund after an increase is taken from the line (increases first), not lost. ----------- */
$o->refunds = array( new G3_Refund( 101101, '30.00', array( new G3_Item( '-30.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) ) );
$GLOBALS['za_orders'][101101] = $o->refunds[0];
OrderSync::sync_refund( 101001, 101101 );
check( 'R5-W10 refund after an increase deducts 10 % of 30.00', 2300, $j1_sum( 101001 ) );
OrderSync::items_changed( 101001 );
check( 'R5-W10 saving after the refund books nothing (refund already deducted)', 2300, $j1_sum( 101001 ) );
OrderSync::order_trashed( 101001 );
check( 'R5-W10 full reversal covers the rows of the increase', 0, $j1_sum( 101001 ) );
OrderSync::items_changed( 101001 );
check( 'R5-W10 no increase booked for a reversed order', 0, $j1_sum( 101001 ) );
OrderSync::order_untrashed( 101001 );
check( 'R5-W10 restored order: all rows reinstated, refund stays deducted', 2300, $j1_sum( 101001 ) );

/* Paid original: never modified; the difference is a new pending commission. -------------- */
$o = $j1_order( 101002, array( 1 => $j1_item( '100.00', 9201 ) ), '100.00', $j1meta );
OrderSync::sync_order( 101002 );
$orig = $j1_rows( 101002 )[0];
$wpdb->update( $j1c, array( 'status' => 'paid', 'payout_id' => 990001 ), array( 'id' => (int) $orig->id ) );
$o->items[1]->total = '120.00';
$o->total           = '120.00';
OrderSync::items_changed( 101002 );
$rows = $j1_rows( 101002 );
check(
	'R5-W10 paid original: unchanged, the difference is a new pending commission with a hold period',
	array( 'paid', 1000, 2, 'pending', 200, true ),
	array( Ledger::find( (int) $orig->id )->status, (int) Ledger::find( (int) $orig->id )->amount_minor, count( $rows ), end( $rows )->status, (int) end( $rows )->amount_minor, strtotime( end( $rows )->hold_until . ' UTC' ) > time() + DAY_IN_SECONDS )
);

/* Under review: the difference is under review as well; rejecting the line rejects it. ----- */
$o = $j1_order( 101003, array( 1 => $j1_item( '100.00', 9301 ) ), '100.00', $j1meta );
OrderSync::sync_order( 101003 );
$orig = $j1_rows( 101003 )[0];
$wpdb->update( $j1c, array( 'status' => 'review' ), array( 'id' => (int) $orig->id ) );
$o->items[1]->total = '200.00';
$o->total           = '200.00';
OrderSync::items_changed( 101003 );
$rows = $j1_rows( 101003 );
check( 'R5-W10 original under review: the difference is under review', array( 2000, 'review' ), array( $j1_sum( 101003 ), end( $rows )->status ) );
Ledger::reject( (int) $orig->id, 'Betrug' );
check( 'R5-W10 rejecting the original rejects the additional commission', array( 'rejected', 0 ), array( Ledger::find( (int) end( $rows )->id )->status, $j1_sum( 101003 ) ) );
$o->items[1]->total = '250.00';
$o->total           = '250.00';
OrderSync::items_changed( 101003 );
check( 'R5-W10 rejected order: no increase booked', 0, $j1_sum( 101003 ) );

/* Partner no longer eligible: no booking, note with the reason (once). -------------------- */
$j1b = AffiliateRepository::create( array( 'name' => 'J1 Partner B', 'email' => 'j1-partner-b@example.de', 'status' => 'active' ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $j1b, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'j1' );
$o = $j1_order( 101004, array( 1 => $j1_item( '100.00' ) ), '100.00', array( '_wpac_partner_code' => CodeRepository::main_code( $j1b ) ) );
OrderSync::sync_order( 101004 );
$wpdb->update( Db::table( 'affiliates' ), array( 'status' => 'paused' ), array( 'id' => $j1b ) );
AffiliateRepository::clear_cache( $j1b );
$o->items[1]->total = '150.00';
$o->total           = '150.00';
OrderSync::items_changed( 101004 );
OrderSync::items_changed( 101004 );
check( 'R5-W10 inactive partner: no booking, one note with the reason', array( 1000, 1 ), array( $j1_sum( 101004 ), $j1_notes( $o, 'no longer active' ) ) );
$wpdb->update( Db::table( 'affiliates' ), array( 'status' => 'active' ), array( 'id' => $j1b ) );
AffiliateRepository::clear_cache( $j1b );
Restriction::add( $j1b, 'request', 'J1' );
OrderSync::items_changed( 101004 );
check( 'R5-W10 restricted partner: no booking, note with the reason', array( 1000, true ), array( $j1_sum( 101004 ), $j1_note( $o, 'restricted' ) ) );
Restriction::remove( $j1b, '', false );
OrderSync::items_changed( 101004 );
check( 'R5-W10 eligible again: the difference is booked', 1500, $j1_sum( 101004 ) );

/* Switched off (filter): only a note. ----------------------------------------------------- */
$o = $j1_order( 101005, array( 1 => $j1_item( '100.00' ) ), '100.00', $j1meta );
OrderSync::sync_order( 101005 );
add_filter( 'wpac_book_order_increase', $j1_off = static function () { return false; } );
$o->items[1]->total = '150.00';
$o->total           = '150.00';
OrderSync::items_changed( 101005 );
remove_filter( 'wpac_book_order_increase', $j1_off );
check( 'R5-W10 switched off via wpac_book_order_increase: only a note', array( 1000, true ), array( $j1_sum( 101005 ), $j1_note( $o, 'not raised automatically' ) ) );

/* Database error: queued and booked by the retry. --------------------------------------------- */
add_filter( 'wpac_book_order_increase', $j1_fail = static function () { throw new \WPAffiliateCaptain\Core\IntegrationException( 'db_test', 'J1 test failure' ); } );
OrderSync::items_changed( 101005 );
remove_filter( 'wpac_book_order_increase', $j1_fail );
$j1job = $wpdb->get_row( "SELECT * FROM " . Db::table( 'jobs' ) . " WHERE idempotency_key = 'woo_increase:woocommerce:101005'" );
check( 'R5-W10 database error: queued for retry with a note', array( 'queued', 1000, true ), array( $j1job ? $j1job->status : '', $j1_sum( 101005 ), $j1_note( $o, 'retried automatically' ) ) );
check( 'R5-W10 the retry job books the difference', array( true, 1500 ), array( OrderSync::run_job( false, 'woo_increase', array( 'order_id' => 101005 ) ), $j1_sum( 101005 ) ) );

/* Decrease, increase, decrease again, increase again: always the current order. ----------- */
$o = $j1_order( 101006, array( 1 => $j1_item( '100.00' ) ), '100.00', $j1meta );
OrderSync::sync_order( 101006 );
$j1_seq = array();
foreach ( array( '60.00', '90.00', '50.00', '100.00', '100.00' ) as $total ) {
	$o->items[1]->total = $total;
	$o->total           = $total;
	OrderSync::items_changed( 101006 );
	$j1_seq[] = $j1_sum( 101006 );
}
check( 'R5-W10 decrease then increase (and again) follows the order', array( 600, 900, 500, 1000, 1000 ), $j1_seq );
$o->items[1]->total = '60.00';
$o->total           = '60.00';
OrderSync::items_changed( 101006 );
check( 'R5-W10 the same lower amount a second time is corrected again', 600, $j1_sum( 101006 ) );
OrderSync::order_deleted( 101006 );
check( 'R5-W10 deleted after increases: everything reversed', 0, $j1_sum( 101006 ) );
