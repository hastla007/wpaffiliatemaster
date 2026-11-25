<?php
/**
 * P2-13 (owner decision 25.11.2026, "cap on the rest"): a partial refund of a line capped by its
 * rule recalculates the commission on the remaining base and applies the cap again – cap 50,
 * 10 % of 800 = 80 → 50; after refunding half 10 % of 400 = 40 stays (not 25). Repeated partial
 * refunds stay cumulative, a full refund ends at 0, lines without a cap (or below it) are still
 * reduced in proportion. Included from run.php right after r24-ac6.php (uses the WooCommerce
 * stand-ins of r8-g3.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;

echo "R25 p213: rule cap on the rest after partial refunds (P2-13)\n";

global $wpdb;
$p213_ct = Db::table( 'commissions' );

$p213_partner = static function ( $slug, array $rule ) {
	$aff = (int) AffiliateRepository::create( array( 'name' => 'P213 ' . $slug, 'email' => 'p213-' . $slug . '@example.de', 'status' => 'active' ) );
	RuleRepository::save( RuleRepository::sanitize( $rule + array( 'scope' => 'affiliate', 'scope_id' => $aff, 'target' => 'all', 'priority' => 0, 'active' => 1 ) ), 0, 'p213' );
	return $aff;
};
// Sale of one or more lines: [ net_minor, quantity ] per line.
$p213_sale = static function ( $aff, $order, array $lines, $currency = 'EUR' ) {
	$items = array();
	foreach ( $lines as $i => $l ) {
		$items[] = array( 'item_id' => (string) ( $i + 1 ), 'product_id' => 913000 + $i, 'name' => 'P213 Kurs ' . ( $i + 1 ), 'net_minor' => $l[0], 'quantity' => isset( $l[1] ) ? $l[1] : 1 );
	}
	return wpac_record_sale( array( 'source' => 'test', 'order_id' => 'p213-' . $order, 'currency' => $currency, 'partner_code' => CodeRepository::main_code( $aff ), 'items' => $items, 'customer' => array( 'user_id' => 0, 'email' => 'p213-kunde-' . $order . '@example.de' ) ) );
};
// Partial refund: item_id => net_minor; empty = full refund.
$p213_refund = static function ( $order, $refund_id, array $lines = array() ) {
	$p = array( 'source' => 'test', 'order_id' => 'p213-' . $order, 'refund_id' => $refund_id, 'type' => 'refund', 'reason' => 'p213' );
	if ( $lines ) {
		$p['items'] = array();
		foreach ( $lines as $item => $net ) {
			$p['items'][] = array( 'item_id' => (string) $item, 'net_minor' => $net );
		}
	} else {
		$p['full'] = true;
	}
	return wpac_record_refund( $p );
};
// Commission booked for an order (or one line of it): original + corrections, rejected rows left out.
$p213_sum = static function ( $order, $item = null, $source = 'test' ) use ( $wpdb, $p213_ct ) {
	$sql = $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p213_ct} WHERE source = %s AND source_order_id = %s AND status <> 'rejected'", $source, ( 'test' === $source ? 'p213-' : '' ) . $order );
	if ( null !== $item ) {
		$sql .= $wpdb->prepare( ' AND source_item_id = %s', (string) $item );
	}
	return (int) $wpdb->get_var( $sql );
};
$p213_rows = static function ( $order ) use ( $wpdb, $p213_ct ) {
	return array_map(
		static function ( $r ) {
			return array( (int) $r->amount_minor, (int) $r->base_minor );
		},
		(array) $wpdb->get_results( $wpdb->prepare( "SELECT amount_minor, base_minor FROM {$p213_ct} WHERE source = 'test' AND source_order_id = %s AND parent_id > 0 AND status <> 'rejected' ORDER BY id ASC", 'p213-' . $order ) )
	);
};
$p213_parent = static function ( $order, $item = '1' ) use ( $wpdb, $p213_ct ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p213_ct} WHERE source = 'test' AND source_order_id = %s AND source_item_id = %s AND parent_id = 0 ORDER BY id DESC LIMIT 1", 'p213-' . $order, (string) $item ) );
};

/* Calculator: the commission kept on the rest. ------------------------------------------------ */
check(
	'P213-01 capped_rest: 10 % of 800 capped at 50 keeps 50 at full base, 40 on 400, 50 on 600 (60 capped), 0 on nothing; null without cap or below it',
	array( 5000, 4000, 5000, 0, null, null ),
	array(
		Calculator::capped_rest( 'percent', '10', 5000, 80000, 80000 ),
		Calculator::capped_rest( 'percent', '10', 5000, 80000, 40000 ),
		Calculator::capped_rest( 'percent', '10', 5000, 80000, 60000 ),
		Calculator::capped_rest( 'percent', '10', 5000, 80000, 0 ),
		Calculator::capped_rest( 'percent', '10', 0, 80000, 40000 ),
		Calculator::capped_rest( 'percent', '10', 5000, 30000, 15000 ),
	)
);
check(
	'P213-02 capped_rest fixed: 15 × 4 = 60 capped at 40, half the line refunded keeps 30',
	array( 4000, 3000 ),
	array( Calculator::capped_rest( 'fixed', '1500', 4000, 10000, 10000, 4 ), Calculator::capped_rest( 'fixed', '1500', 4000, 10000, 5000, 4 ) )
);
check(
	'P213-03 refund_adjustment with the kept commission: 50 → 40 is −10; already at 40, kept 40 is 0; never more than remains',
	array( -1000, 0, -4000 ),
	array( Calculator::refund_adjustment( 5000, 80000, 0, 40000, false, 0, 4000 ), Calculator::refund_adjustment( 5000, 80000, -1000, 10000, false, 40000, 4000 ), Calculator::refund_adjustment( 5000, 80000, -1000, 40000, false, 40000, 0 ) )
);

/* The owner's example and repeated partial refunds. ------------------------------------------ */
$p213_a = $p213_partner( 'cap', array( 'rate_type' => 'percent', 'rate' => '10', 'max_amount' => '50' ) );
$p213_sale( $p213_a, 'a1', array( array( 80000 ) ) );
check( 'P213-10 sale: 10 % of 800 = 80, capped at 50', 5000, $p213_sum( 'a1' ) );
$m = count( $GLOBALS['test_fired'] );
$r = $p213_refund( 'a1', 'a1-r1', array( 1 => 40000 ) );
check( 'P213-11 half the line refunded: 10 % of 400 = 40 is below the cap, 40 stays (not 25)', array( 'adjusted', 4000, array( array( -1000, -40000 ) ) ), array( $r['status'], $p213_sum( 'a1' ), $p213_rows( 'a1' ) ) );
$p213_hook = null;
foreach ( array_slice( $GLOBALS['test_fired'], $m ) as $f ) {
	if ( 'wpac_commission_adjusted' === $f[0] && 'p213-a1' === (string) $f[1][0]['order_id'] ) {
		$p213_hook = array( $f[1][0]['refund_id'], $f[1][0]['refund_key'], $f[1][1] === $r['adjustment_ids'], $f[2] );
	}
}
check( 'P213-12 wpac_commission_adjusted unchanged (AC-6): refund_id and refund_key, the correction rows, after COMMIT', array( 'a1-r1', 'a1-r1', true, 0 ), $p213_hook );
$r = $p213_refund( 'a1', 'a1-r1', array( 1 => 40000 ) );
check( 'P213-13 the same refund again changes nothing (idempotent)', array( 'unchanged', 4000 ), array( $r['status'], $p213_sum( 'a1' ) ) );
$p213_refund( 'a1', 'a1-r2', array( 1 => 20000 ) );
check( 'P213-14 a second partial refund counts cumulatively: 10 % of the remaining 200 = 20', array( 2000, array( array( -1000, -40000 ), array( -2000, -20000 ) ) ), array( $p213_sum( 'a1' ), $p213_rows( 'a1' ) ) );
$p213_refund( 'a1', 'a1-r3' );
check( 'P213-15 a full refund afterwards ends at 0', 0, $p213_sum( 'a1' ) );

/* A refund that leaves the line at the cap: base booked with 0, later refunds count from it. -- */
$p213_sale( $p213_a, 'a2', array( array( 80000 ) ) );
$r = $p213_refund( 'a2', 'a2-r1', array( 1 => 10000 ) );
check( 'P213-20 100 of 800 refunded: 10 % of 700 = 70 is still above the cap, 50 stays; the base is booked in a row with 0', array( 'adjusted', 5000, array( array( 0, -10000 ) ), 10000 ), array( $r['status'], $p213_sum( 'a2' ), $p213_rows( 'a2' ), Ledger::refunded_base( $p213_parent( 'a2' ) ) ) );
$p213_refund( 'a2', 'a2-r2', array( 1 => 30000 ) );
check( 'P213-21 another 300 refunded: 10 % of the remaining 400 = 40 (cumulative, not 10 % of 500)', array( 4000, 40000 ), array( $p213_sum( 'a2' ), Ledger::refunded_base( $p213_parent( 'a2' ) ) ) );
$p213_refund( 'a2', 'a2-r3', array( 1 => 40000 ) );
check( 'P213-22 the rest of the line refunded in parts ends at 0', array( 0, 80000 ), array( $p213_sum( 'a2' ), Ledger::refunded_base( $p213_parent( 'a2' ) ) ) );
$p213_refund( 'a2', 'a2-r4', array( 1 => 10000 ) );
check( 'P213-23 refunding more than the line changes nothing (no further row)', array( 0, 3 ), array( $p213_sum( 'a2' ), count( $p213_rows( 'a2' ) ) ) );

// Deleting a refund that left the line at the cap gives its base back (nothing to give back in money).
$p213_sale( $p213_a, 'a3', array( array( 80000 ) ) );
$p213_refund( 'a3', 'a3-r1', array( 1 => 10000 ) );
$rr = Ledger::reverse_refund( 'test', 'p213-a3', 'a3-r1', 'refund deleted' );
check( 'P213-24 deleting that refund gives its base back, the commission stays 50', array( 0, 5000 ), array( Ledger::refunded_base( $p213_parent( 'a3' ) ), $p213_sum( 'a3' ) ) );
$p213_refund( 'a3', 'a3-r2', array( 1 => 40000 ) );
check( 'P213-25 a later refund of half the line then keeps 40', 4000, $p213_sum( 'a3' ) );

/* Lines without a cap or below it: proportional as before. ----------------------------------- */
$p213_b = $p213_partner( 'nocap', array( 'rate_type' => 'percent', 'rate' => '10' ) );
$p213_sale( $p213_b, 'b1', array( array( 80000 ) ) );
$p213_refund( 'b1', 'b1-r1', array( 1 => 40000 ) );
$p213_refund( 'b1', 'b1-r2', array( 1 => 10000 ) );
check( 'P213-30 rule without cap: 80 → 40 → 30 (proportional, unchanged)', 3000, $p213_sum( 'b1' ) );
$p213_sale( $p213_a, 'b2', array( array( 30000 ) ) );
$p213_refund( 'b2', 'b2-r1', array( 1 => 10000 ) );
check( 'P213-31 line below the cap (30 of cap 50): 100 of 300 refunded leaves 20 (proportional, unchanged)', 2000, $p213_sum( 'b2' ) );

/* Several lines in one refund (one capped, one below the cap). --------------------------------- */
$p213_sale( $p213_a, 'c1', array( array( 80000 ), array( 20000 ) ) );
$p213_refund( 'c1', 'c1-r1', array( 1 => 50000, 2 => 10000 ) );
check( 'P213-40 two lines in one refund: capped line 10 % of 300 = 30, line below the cap 20 → 10', array( 3000, 1000, 4000 ), array( $p213_sum( 'c1', 1 ), $p213_sum( 'c1', 2 ), $p213_sum( 'c1' ) ) );
$p213_refund( 'c1', 'c1-r2' );
check( 'P213-41 full refund of the order ends both lines at 0', array( 0, 0 ), array( $p213_sum( 'c1', 1 ), $p213_sum( 'c1', 2 ) ) );

/* Fixed rate per piece with a cap. -------------------------------------------------------------- */
$p213_f = $p213_partner( 'fixed', array( 'rate_type' => 'fixed', 'rate' => '15', 'max_amount' => '40' ) );
$p213_sale( $p213_f, 'f1', array( array( 10000, 4 ) ) );
$p213_refund( 'f1', 'f1-r1', array( 1 => 5000 ) );
check( 'P213-50 fixed 15 × 4 = 60 capped at 40: half the line refunded keeps 30 (not 20)', 3000, $p213_sum( 'f1' ) );

/* Increase after a refund: the cap counts what the line keeps. ------------------------------- */
$p213_sale( $p213_a, 'i1', array( array( 80000 ) ) );
$p213_refund( 'i1', 'i1-r1', array( 1 => 40000 ) );
$inc = Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'p213-i1', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 913000, 'name' => 'P213 Kurs 1', 'net_minor' => 80000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
check( 'P213-60 line raised back to 800 after the refund: increase up to the cap (40 → 50)', array( 1000, 5000 ), array( (int) $inc['amount_minor'], $p213_sum( 'i1' ) ) );
$p213_refund( 'i1', 'i1-r2', array( 1 => 40000 ) );
check( 'P213-61 half of it refunded again: 10 % of 400 = 40', 4000, $p213_sum( 'i1' ) );
$p213_refund( 'i1', 'i1-r3' );
check( 'P213-62 full refund with increase: 0', 0, $p213_sum( 'i1' ) );

/* A filter changed the amount: proportional, as before. --------------------------------------- */
add_filter( 'wpac_commission_amount', $p213_flt = static function ( $a ) { return $a + 1000; } );
$p213_sale( $p213_a, 'g1', array( array( 80000 ) ) );
remove_filter( 'wpac_commission_amount', $p213_flt );
$p213_g = $p213_sum( 'g1' );
$p213_refund( 'g1', 'g1-r1', array( 1 => 40000 ) );
check( 'P213-70 commission changed by wpac_commission_amount (60): half refunded is 30 (proportional)', array( 6000, 3000 ), array( $p213_g, $p213_sum( 'g1' ) ) );

/* Foreign currency: cap converted, rounding in minor units. ---------------------------------- */
$p213_settings = (array) get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $p213_settings, array( 'currency' => 'EUR', 'exchange_rates' => 'CHF=0.5' ) ) );
Settings::flush_cache();
$p213_x = $p213_partner( 'fx', array( 'rate_type' => 'percent', 'rate' => '7.5', 'max_amount' => '50' ) );
$p213_sale( $p213_x, 'x1', array( array( 200001 ) ), 'CHF' ); // 7.5 % of 2000.01 CHF = 150.00, cap 50 EUR = 100 CHF.
check( 'P213-80 CHF: 7.5 % of 2000.01 = 150.00 capped at 50 EUR = 100.00 CHF', 10000, $p213_sum( 'x1' ) );
$p213_refund( 'x1', 'x1-r1', array( 1 => 66667 ) );
check( 'P213-81 CHF: 666.67 refunded, 7.5 % of 1333.34 = 100.00 (rounded) stays at the cap', array( 10000, array( array( 0, -66667 ) ) ), array( $p213_sum( 'x1' ), $p213_rows( 'x1' ) ) );
$p213_refund( 'x1', 'x1-r2', array( 1 => 33333 ) );
check( 'P213-82 CHF: another 333.33, 7.5 % of 1000.01 = 75.00 (rounded to the centime)', 7500, $p213_sum( 'x1' ) );
$p213_refund( 'x1', 'x1-r3', array( 1 => 100001 ) );
check( 'P213-83 CHF: the rest refunded ends at 0', 0, $p213_sum( 'x1' ) );
update_option( 'wpac_settings', $p213_settings );
Settings::flush_cache();

/* WooCommerce: a partial refund of a capped line. -------------------------------------------- */
$p213_w    = $p213_partner( 'woo', array( 'rate_type' => 'percent', 'rate' => '10', 'max_amount' => '50' ) );
$p213_wo   = new G3_Order( 87001, array( 1 => new G3_Item( '800.00' ) ), '800.00', array( '_wpac_partner_code' => CodeRepository::main_code( $p213_w ) ) );
$GLOBALS['za_orders'][87001] = $p213_wo;
OrderSync::sync_order( 87001 );
$p213_ws = $p213_sum( '87001', null, 'woocommerce' );
$p213_wr = new G3_Refund( 87101, '400.00', array( new G3_Item( '-400.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) );
array_unshift( $p213_wo->refunds, $p213_wr );
$GLOBALS['za_orders'][87101] = $p213_wr;
OrderSync::sync_refund( 87001, 87101 );
check( 'P213-90 WooCommerce: 800 capped at 50, refund of 400 keeps 40', array( 5000, 4000 ), array( $p213_ws, $p213_sum( '87001', null, 'woocommerce' ) ) );
$p213_wr2 = new G3_Refund( 87102, '400.00', array( new G3_Item( '-400.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) );
array_unshift( $p213_wo->refunds, $p213_wr2 );
$GLOBALS['za_orders'][87102] = $p213_wr2;
OrderSync::sync_refund( 87001, 87102 );
check( 'P213-91 WooCommerce: the second refund of the rest ends at 0', 0, $p213_sum( '87001', null, 'woocommerce' ) );
