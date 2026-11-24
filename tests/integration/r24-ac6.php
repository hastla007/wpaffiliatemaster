<?php
/**
 * AC-6 (contract 1.11.0): a full WooCommerce refund booked via the order status names the
 * WooCommerce refund as refund_id in wpac_commission_adjusted; the booking key stays the
 * synthetic status key (refund_key). Included from run.php right before r8-render.php; uses the
 * WooCommerce stand-ins of r8-g3.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Sales\ContractApi;

echo "R24 ac6: WooCommerce refund ID in wpac_commission_adjusted for full refunds via the status (AC-6)\n";

global $wpdb;
$ac6_ct   = Db::table( 'commissions' );
$ac6_aff  = AffiliateRepository::create( array( 'name' => 'AC6 Partner', 'email' => 'ac6-partner@example.de', 'status' => 'active' ) );
$ac6_meta = array( '_wpac_partner_code' => CodeRepository::main_code( $ac6_aff ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $ac6_aff, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'ac6' );

$ac6_order = static function ( $id ) use ( $ac6_meta ) {
	$o = new G3_Order( $id, array( 1 => new G3_Item( '100.00' ) ), '100.00', $ac6_meta );
	$GLOBALS['za_orders'][ $id ] = $o;
	OrderSync::sync_order( $id );
	return $o;
};
// A full refund object of the whole line (as wc_create_refund() stores it).
$ac6_refund = static function ( $o, $id, $amount = '100.00' ) {
	$r = new G3_Refund( $id, $amount, array( new G3_Item( '-' . $amount, '0.00', array( '_refunded_item_id' => 1 ) ) ) );
	array_unshift( $o->refunds, $r ); // WooCommerce lists the newest refund first.
	$GLOBALS['za_orders'][ $id ] = $r;
	return $r;
};
$ac6_sum = static function ( $order, $source = 'woocommerce' ) use ( $wpdb, $ac6_ct ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$ac6_ct} WHERE source = %s AND source_order_id = %s AND status <> 'rejected'", $source, (string) $order ) );
};
// wpac_commission_adjusted of an order since a mark: [ refund_id, refund_key, type, full ].
$ac6_mark = static function () {
	return count( $GLOBALS['test_fired'] );
};
$ac6_adjusted = static function ( $order, $since, $source = 'woocommerce' ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['test_fired'], $since ) as $f ) {
		if ( 'wpac_commission_adjusted' === $f[0] && (string) $f[1][0]['order_id'] === (string) $order && $source === $f[1][0]['source'] ) {
			$r     = $f[1][0];
			$out[] = array( $r['refund_id'], isset( $r['refund_key'] ) ? $r['refund_key'] : null, $r['type'], ! empty( $r['full'] ), array_key_exists( 'source_refund_id', $r ) );
		}
	}
	return $out;
};

/* Shape of the hook refund. ----------------------------------------------------------------- */
check( 'AC6-01 hook refund without source refund: refund_id and refund_key are the key', array( 'r-1', 'r-1', false ), ( static function ( $h ) { return array( $h['refund_id'], $h['refund_key'], array_key_exists( 'source_refund_id', $h ) ); } )( Ledger::hook_refund( array( 'refund_id' => 'r-1', 'source_refund_id' => '' ) ) ) );
check( 'AC6-02 hook refund with source refund: refund_id is the source refund, refund_key the key', array( '815', 'status-refund' ), ( static function ( $h ) { return array( $h['refund_id'], $h['refund_key'] ); } )( Ledger::hook_refund( array( 'refund_id' => 'status-refund', 'source_refund_id' => '815' ) ) ) );
$ac6_norm = ContractApi::normalize_refund( array( 'source' => 'ac6', 'order_id' => '1', 'refund_id' => 'k', 'full' => true, 'source_refund_id' => 77 ) );
check( 'AC6-03 normalize_refund keeps source_refund_id as string (empty without)', array( '77', '' ), array( $ac6_norm['source_refund_id'], ContractApi::normalize_refund( array( 'source' => 'ac6', 'order_id' => '1', 'refund_id' => 'k', 'full' => true ) )['source_refund_id'] ) );

/* Standard WooCommerce flow: refund stored, woocommerce_order_fully_refunded, status, refunded. */
$o = $ac6_order( 86001 );
$ac6_refund( $o, 86101 );
$m = $ac6_mark();
OrderSync::fully_refunded( 86001, 86101 );
OrderSync::full_reversal_refunded( 86001 );
OrderSync::sync_refund( 86001, 86101 );
check( 'AC6-10 full refund via the status: hook names the WooCommerce refund, refund_key the status key', array( array( '86101', 'status-refund', 'refund', true, false ) ), $ac6_adjusted( 86001, $m ) );
check( 'AC6-11 the later sync_refund() of the same refund deducts nothing more', 0, $ac6_sum( 86001 ) );
check( 'AC6-12 booking key and history keep the status key', array( 1, 0 ), array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::table( 'commission_events' ) . " ev INNER JOIN {$ac6_ct} c ON c.id = ev.commission_id WHERE c.source_order_id = '86001' AND ev.event = 'created' AND ev.source_event_id = 'status-refund'" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::table( 'commission_events' ) . " ev INNER JOIN {$ac6_ct} c ON c.id = ev.commission_id WHERE c.source_order_id = '86001' AND ev.source_event_id = '86101'" ) ) );
// Deleting that refund gives nothing back: the full reversal is not booked under its ID.
OrderSync::refund_deleted( 86101, 86001 );
check( 'AC6-13 deleting the refund behind a status reversal gives nothing back', 0, $ac6_sum( 86001 ) );

/* Status "refunded" without the WooCommerce hook in this request: newest unbooked refund. ----- */
$o = $ac6_order( 86002 );
$ac6_refund( $o, 86102 );
$m = $ac6_mark();
OrderSync::full_reversal_refunded( 86002 );
check( 'AC6-20 without woocommerce_order_fully_refunded the newest refund of the order is named', array( array( '86102', 'status-refund', 'refund', true, false ) ), $ac6_adjusted( 86002, $m ) );

/* Pure status changes keep the synthetic key. ------------------------------------------------- */
$ac6_order( 86003 );
$m = $ac6_mark();
OrderSync::full_reversal_cancelled( 86003 );
check( 'AC6-30 cancellation without refund: synthetic key as refund_id and refund_key', array( array( 'status-cancellation', 'status-cancellation', 'cancellation', true, false ) ), $ac6_adjusted( 86003, $m ) );
$ac6_order( 86004 );
$m = $ac6_mark();
OrderSync::maybe_chargeback( 86004, 'processing', 'failed' );
check( 'AC6-31 chargeback status: synthetic key', array( array( 'status-chargeback', 'status-chargeback', 'chargeback', true, false ) ), $ac6_adjusted( 86004, $m ) );
$ac6_order( 86005 );
$m = $ac6_mark();
OrderSync::full_reversal_refunded( 86005 );
check( 'AC6-32 status "refunded" set by hand without a refund object: synthetic key', array( array( 'status-refund', 'status-refund', 'refund', true, false ) ), $ac6_adjusted( 86005, $m ) );
// A cancellation never borrows a refund of the order.
$o = $ac6_order( 86006 );
$o->refunds = array( new G3_Refund( 86106, '0.00' ) ); // e.g. a refund without lines, never booked.
$m = $ac6_mark();
OrderSync::full_reversal_cancelled( 86006 );
check( 'AC6-33 cancellation with an unbooked refund object keeps the synthetic key', array( array( 'status-cancellation', 'status-cancellation', 'cancellation', true, false ) ), $ac6_adjusted( 86006, $m ) );

/* Partial refunds unchanged; a refund already booked under its own ID is not named again. ---- */
$o = $ac6_order( 86007 );
$ac6_refund( $o, 86107, '20.00' );
$m = $ac6_mark();
OrderSync::sync_refund( 86007, 86107 );
check( 'AC6-40 partial refund: refund_id = refund_key = WooCommerce refund', array( array( '86107', '86107', 'refund', false, false ) ), $ac6_adjusted( 86007, $m ) );
check( 'AC6-41 partial refund deducted (100 € → 80 €, 10 %)', 800, $ac6_sum( 86007 ) );
$m = $ac6_mark();
OrderSync::full_reversal_refunded( 86007 ); // Status set by hand afterwards, no new refund object.
check( 'AC6-42 status "refunded" after a booked partial refund: synthetic key, not the partial refund', array( array( 'status-refund', 'status-refund', 'refund', true, false ) ), $ac6_adjusted( 86007, $m ) );
check( 'AC6-43 remaining commission reversed once', 0, $ac6_sum( 86007 ) );

/* Reinstatement rounds: distinct events, money that went back deducted exactly once. -------- */
$o = $ac6_order( 86008 );
$ac6_refund( $o, 86108 );
$m = $ac6_mark();
OrderSync::fully_refunded( 86008, 86108 );
OrderSync::full_reversal_refunded( 86008 );
OrderSync::sync_refund( 86008, 86108 );
OrderSync::maybe_chargeback( 86008, 'refunded', 'processing' ); // Reinstated; the refund is applied again.
check( 'AC6-50 refunded → processing: the real refund stays deducted', 0, $ac6_sum( 86008 ) );
OrderSync::full_reversal_refunded( 86008 ); // Status "refunded" again (round 1), no new refund.
OrderSync::sync_refund( 86008, 86108 );
check( 'AC6-51 events: status reversal and the refund applied after the reinstatement, distinct keys; nothing booked twice', array( array( '86108', 'status-refund', 'refund', true, false ), array( '86108', '86108', 'refund', false, false ) ), $ac6_adjusted( 86008, $m ) );
check( 'AC6-52 still nothing left (no double deduction)', 0, $ac6_sum( 86008 ) );

$ac6_order( 86009 );
$m = $ac6_mark();
OrderSync::full_reversal_cancelled( 86009 );
OrderSync::maybe_chargeback( 86009, 'cancelled', 'processing' );
check( 'AC6-53 cancellation reinstated: commission back', 1000, $ac6_sum( 86009 ) );
$o = $GLOBALS['za_orders'][86009];
$ac6_refund( $o, 86109 );
OrderSync::fully_refunded( 86009, 86109 );
OrderSync::full_reversal_refunded( 86009 );
OrderSync::sync_refund( 86009, 86109 );
check( 'AC6-54 full refund after a reinstatement: new event with own key (round 1) and the WooCommerce refund', array( array( 'status-cancellation', 'status-cancellation', 'cancellation', true, false ), array( '86109', 'status-refund-1', 'refund', true, false ) ), $ac6_adjusted( 86009, $m ) );
check( 'AC6-55 deducted once', 0, $ac6_sum( 86009 ) );
OrderSync::maybe_chargeback( 86009, 'refunded', 'processing' );
OrderSync::sync_refund( 86009, 86109 );
check( 'AC6-56 second reinstatement: the refund stays deducted exactly once', array( 0, 1 ), array( $ac6_sum( 86009 ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::table( 'commission_events' ) . " ev INNER JOIN {$ac6_ct} c ON c.id = ev.commission_id WHERE c.source_order_id = '86009' AND ev.event = 'created' AND ev.source_event_id = '86109'" ) ) );

/* Queued path: the payload stored for the retry carries the WooCommerce refund. ------------- */
$o = new G3_Order( 86010, array( 1 => new G3_Item( '100.00' ) ), '100.00', $ac6_meta );
$GLOBALS['za_orders'][86010] = $o;
Jobs::enqueue( 'woo_order', 'woocommerce:86010', array( 'order_id' => 86010 ), 'db', 'first attempt failed' );
$ac6_refund( $o, 86110 );
OrderSync::fully_refunded( 86010, 86110 );
OrderSync::full_reversal_refunded( 86010 );
$ac6_job = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . Db::table( 'jobs' ) . ' WHERE idempotency_key = %s', 'refund:woocommerce:86010:status-refund' ) ), true );
check( 'AC6-60 queued full reversal keeps its key and stores the WooCommerce refund for the retry', array( 'status-refund', '86110' ), array( is_array( $ac6_job ) ? $ac6_job['refund_id'] : '', is_array( $ac6_job ) && isset( $ac6_job['source_refund_id'] ) ? $ac6_job['source_refund_id'] : '' ) );

// Other sources: a parked refund keeps source_refund_id until its sale arrives.
$m = $ac6_mark();
$ac6_p = wpac_record_refund( array( 'source' => 'ac6src', 'order_id' => 'o1', 'refund_id' => 'cancel-1', 'source_refund_id' => 'R-9', 'type' => 'cancellation', 'reason' => 'Storno' ) );
wpac_record_sale( array( 'source' => 'ac6src', 'order_id' => 'o1', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $ac6_aff ), 'netted_refund_ids' => array(), 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
$wpdb->query( 'UPDATE ' . Db::table( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key = 'refund:ac6src:o1:cancel-1'" );
Jobs::run_due();
check( 'AC6-61 parked refund of another source: hook after the retry names source_refund_id, refund_key the key', array( 'parked', array( array( 'R-9', 'cancel-1', 'cancellation', true, false ) ), 0 ), array( $ac6_p['status'], $ac6_adjusted( 'o1', $m, 'ac6src' ), $ac6_sum( 'o1', 'ac6src' ) ) );

check( 'AC6 accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );
