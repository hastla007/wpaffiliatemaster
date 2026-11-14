<?php
/**
 * Integration checks of R5-W10 (rest): line items of a paid, attributed WooCommerce order raised
 * after the sale was booked (quantity or net total raised, line added) are booked automatically.
 * Included from tests/integration/run.php after r8-g3.php (uses its WooCommerce stand-ins).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;

echo "R10 W10 increases of paid orders\n";

global $wpdb;
$w10c = Db::table( 'commissions' );

$w10_order = static function ( $id, $items, $total, $meta ) {
	$o = new G3_Order( $id, $items, $total, $meta );
	$GLOBALS['za_orders'][ $id ] = $o;
	return $o;
};
$w10_sum = static function ( $order ) use ( $wpdb, $w10c ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$w10c} WHERE source = 'woocommerce' AND source_order_id = %s AND status <> 'rejected'", (string) $order ) );
};
$w10_rows = static function ( $order, $kind ) use ( $wpdb, $w10c ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$w10c} WHERE source = 'woocommerce' AND source_order_id = %s AND kind = %s ORDER BY id ASC", (string) $order, $kind ) );
};
$w10_item = static function ( $total, $qty = 1 ) {
	$i      = new G3_Item( $total );
	$i->qty = $qty;
	return $i;
};
$w10_refund = static function ( $o, $id, $amount ) {
	$r            = new G3_Refund( $id, $amount, array( new G3_Item( '-' . $amount, '0.00', array( '_refunded_item_id' => 1 ) ) ) );
	$o->refunds[] = $r;
	$GLOBALS['za_orders'][ $id ] = $r;
	OrderSync::sync_refund( $o->id, $id );
};
$w10_note = static function ( $o, $text ) {
	return false !== strpos( (string) end( $o->notes ), $text );
};

$w10a    = AffiliateRepository::create( array( 'name' => 'W10 Partner', 'email' => 'w10-partner@example.de', 'status' => 'active' ) );
$w10rule = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $w10a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'w10' );
$w10meta = array( '_wpac_partner_code' => CodeRepository::main_code( $w10a ) );

/* Quantity raised: increase row for the difference, same rate, hold and partner; idempotent. */
$o = $w10_order( 84001, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84001 );
check( 'W10 sale booked (10 % of 100 €)', 1000, $w10_sum( 84001 ) );
$o->items[1] = $w10_item( '200.00', 2 );
$o->total    = '200.00';
OrderSync::items_changed( 84001 );
OrderSync::items_changed( 84001 ); // Saving again books nothing.
$w10s = $w10_rows( 84001, 'sale' )[0];
$w10i = $w10_rows( 84001, 'increase' );
check( 'W10 quantity raised: one increase row of 10 € under the sale', array( 2000, 1, 10000, 1000, (int) $w10s->id, 'pending', $w10s->hold_until, (int) $w10a ), array( $w10_sum( 84001 ), count( $w10i ), (int) $w10i[0]->base_minor, (int) $w10i[0]->amount_minor, (int) $w10i[0]->parent_id, $w10i[0]->status, $w10i[0]->hold_until, (int) $w10i[0]->affiliate_id ) );
check( 'W10 order note says what was booked', true, $w10_note( $o, '10,00' ) && $w10_note( $o, 'was booked' ) );
$w10notes = count( $o->notes );
OrderSync::items_changed( 84001 );
check( 'W10 saving unchanged items adds no note', $w10notes, count( $o->notes ) );
// A refund after the increase deducts from original + increase (150 € left of 200 €: 15 €).
$w10_refund( $o, 84101, '50.00' );
check( 'W10 refund after the increase is pro rata on the raised base', 1500, $w10_sum( 84001 ) );
OrderSync::items_changed( 84001 );
check( 'W10 saving after the refund changes nothing', 1500, $w10_sum( 84001 ) );

/* Raised after a partial refund: only the part not booked yet. ----------------------------- */
$o = $w10_order( 84002, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84002 );
$w10_refund( $o, 84102, '20.00' );
check( 'W10 partial refund deducted', 800, $w10_sum( 84002 ) );
$o->items[1] = $w10_item( '150.00', 2 );
$o->total    = '150.00';
OrderSync::items_changed( 84002 );
check( 'W10 raised after a refund: 150 € − 20 € refunded = 130 € (+5 €)', array( 1300, 5000 ), array( $w10_sum( 84002 ), (int) $w10_rows( 84002, 'increase' )[0]->base_minor ) );
$w10_refund( $o, 84103, '30.00' );
check( 'W10 later refund: 100 € of 150 € left = 10 €', 1000, $w10_sum( 84002 ) );

/* Lowered, raised, lowered again to the same amount: each step booked. ---------------------- */
$o = $w10_order( 84003, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84003 );
$w10_steps = array();
foreach ( array( '60.00', '80.00', '60.00', '100.00' ) as $w10t ) {
	$o->items[1]->total = $w10t;
	$o->total           = $w10t;
	OrderSync::items_changed( 84003 );
	OrderSync::items_changed( 84003 );
	$w10_steps[] = $w10_sum( 84003 );
}
check( 'W10 lowered/raised several times: the commission follows every step once', array( 600, 800, 600, 1000 ), $w10_steps );

/* New line with the current rules, raised line with the rate of the sale. ------------------- */
$o = $w10_order( 84004, array( 1 => $w10_item( '100.00' ), 2 => $w10_item( '0.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84004 );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $w10a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '20', 'priority' => 0, 'active' => 1 ) ), $w10rule, 'w10 rate up' );
$o->items[1] = $w10_item( '200.00', 2 );
$o->items[3] = $w10_item( '50.00' );
$o->total    = '250.00';
OrderSync::items_changed( 84004 );
OrderSync::items_changed( 84004 );
$w10new = $w10_rows( 84004, 'sale' );
check( 'W10 raised line at the rate of the sale (10 %), new line at the current rate (20 %)', array( 3000, 1000, 2, '3', 1000, 'pending', $w10new[0]->hold_until ), array( $w10_sum( 84004 ), (int) $w10_rows( 84004, 'increase' )[0]->amount_minor, count( $w10new ), $w10new[1]->source_item_id, (int) $w10new[1]->amount_minor, $w10new[1]->status, $w10new[1]->hold_until ) );
$o->items[2] = $w10_item( '40.00' );
$o->total    = '290.00';
OrderSync::items_changed( 84004 );
check( 'W10 a line of the sale without commission stays without (not a new line)', 3000, $w10_sum( 84004 ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $w10a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), $w10rule, 'w10 rate back' );

/* Base capped at the order total (e.g. paid with a gift card). ------------------------------ */
$o = $w10_order( 84005, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84005 );
$o->items[1] = $w10_item( '200.00', 2 );
$o->total    = '120.00';
OrderSync::items_changed( 84005 );
check( 'W10 increase capped at the order total (120 €)', 1200, $w10_sum( 84005 ) );

/* Already paid out: the increase goes to the next payout. ----------------------------------- */
$o = $w10_order( 84006, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84006 );
$w10s = $w10_rows( 84006, 'sale' )[0];
$wpdb->update( $w10c, array( 'status' => 'paid', 'payout_id' => 99999, 'hold_until' => '2020-01-01 00:00:00' ), array( 'id' => $w10s->id ) );
$o->items[1] = $w10_item( '300.00', 3 );
$o->total    = '300.00';
OrderSync::items_changed( 84006 );
$w10i = $w10_rows( 84006, 'increase' )[0];
check( 'W10 paid-out commission: increase of 20 € waits for release, not in the old payout', array( 2000, 'pending', 0, '2020-01-01 00:00:00' ), array( (int) $w10i->amount_minor, $w10i->status, (int) $w10i->payout_id, $w10i->hold_until ) );
Ledger::approve_due();
check( 'W10 hold period over: the increase is approved for the next payout', array( 'approved', 0 ), array( Ledger::find( (int) $w10i->id )->status, (int) Ledger::find( (int) $w10i->id )->payout_id ) );

/* Rejected, under review or reversed: nothing booked, the note asks for a manual correction. */
$o = $w10_order( 84007, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84007 );
Ledger::reject( (int) $w10_rows( 84007, 'sale' )[0]->id, 'Betrugsverdacht' );
$o->items[1] = $w10_item( '200.00', 2 );
$o->total    = '200.00';
OrderSync::items_changed( 84007 );
check( 'W10 rejected commission: no increase, note kept', array( 0, 0, true ), array( $w10_sum( 84007 ), count( $w10_rows( 84007, 'increase' ) ), $w10_note( $o, 'not raised automatically' ) ) );
$o = $w10_order( 84008, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84008 );
$wpdb->update( $w10c, array( 'status' => 'review' ), array( 'source_order_id' => '84008' ) );
$o->items[1] = $w10_item( '200.00', 2 );
$o->total    = '200.00';
OrderSync::items_changed( 84008 );
check( 'W10 commission under review: no increase, note kept', array( 1000, true ), array( $w10_sum( 84008 ), $w10_note( $o, 'not raised automatically' ) ) );
$o = $w10_order( 84009, array( 1 => $w10_item( '100.00' ) ), '100.00', $w10meta );
OrderSync::sync_order( 84009 );
OrderSync::maybe_chargeback( 84009, 'processing', 'failed' );
$o->items[1] = $w10_item( '200.00', 2 );
$o->total    = '200.00';
OrderSync::items_changed( 84009 );
check( 'W10 fully reversed commission: no increase', array( 0, 0 ), array( $w10_sum( 84009 ), count( $w10_rows( 84009, 'increase' ) ) ) );

/* Fixed rate per piece: a higher price alone earns nothing more, another piece does. -------- */
$w10b = AffiliateRepository::create( array( 'name' => 'W10 Fix', 'email' => 'w10-fix@example.de', 'status' => 'active' ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $w10b, 'target' => 'all', 'rate_type' => 'fixed', 'rate' => '5', 'priority' => 0, 'active' => 1 ) ), 0, 'w10 fixed' );
$o = $w10_order( 84010, array( 1 => $w10_item( '100.00' ) ), '100.00', array( '_wpac_partner_code' => CodeRepository::main_code( $w10b ) ) );
OrderSync::sync_order( 84010 );
$w10f = array( $w10_sum( 84010 ) );
$o->items[1] = $w10_item( '150.00' );
$o->total    = '150.00';
OrderSync::items_changed( 84010 );
$w10f[]      = $w10_sum( 84010 );
$o->items[1] = $w10_item( '300.00', 2 );
$o->total    = '300.00';
OrderSync::items_changed( 84010 );
$w10f[] = $w10_sum( 84010 );
check( 'W10 fixed 5 € per piece: price raised +0, second piece +5 €', array( 500, 500, 1000 ), $w10f );

/* Reported in the history of the commission (kind label). ----------------------------------- */
check( 'W10 kind label of the increase row', 'Order increase', Ledger::kind_label( 'increase' ) );
