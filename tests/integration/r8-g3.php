<?php
/**
 * Integration checks of work package G3 (review rounds 5–7): sales contract, ledger, WooCommerce
 * order sync, refunds and rules. Included from tests/integration/run.php (uses its helpers).
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
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Sales\ContractApi;

echo "R8-G3 sales contract, ledger, WooCommerce sync, refunds, rules\n";

global $wpdb;
$g3c = Db::table( 'commissions' );
$g3j = Db::table( 'jobs' );

/* Minimal WooCommerce stand-ins (only what OrderSync needs). ------------------------------- */
class G3_Product {
	public $type = 'simple'; public $meta = array(); public $sku = '';
	public function __construct( $type = 'simple', $meta = array() ) { $this->type = $type; $this->meta = $meta; }
	public function get_type() { return $this->type; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function get_sku() { return $this->sku; }
}
class G3_Item {
	public $total; public $tax; public $meta; public $product; public $product_id = 0; public $qty = 1; public $name = 'Artikel';
	public function __construct( $total, $tax = '0.00', $meta = array(), $product = null ) { $this->total = $total; $this->tax = $tax; $this->meta = $meta; $this->product = $product; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function get_product() { return $this->product; }
	public function get_product_id() { return $this->product_id; }
	public function get_variation_id() { return 0; }
	public function get_quantity() { return $this->qty; }
	public function get_name() { return $this->name; }
}
// Tax lines have no get_total() (WC_Order_Item_Tax).
class G3_TaxItem {
	public $tax;
	public function __construct( $tax ) { $this->tax = $tax; }
	public function get_tax_total() { return $this->tax; }
	public function get_shipping_tax_total() { return '0.00'; }
}
class G3_Fee {
	public $total; public $tax;
	public function __construct( $total, $tax = '0.00' ) { $this->total = $total; $this->tax = $tax; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
}
class G3_Refund {
	public $id; public $lines = array(); public $tax = array(); public $amount; public $reason = 'Erstattung';
	public function __construct( $id, $amount, $lines = array(), $tax = array() ) { $this->id = $id; $this->amount = $amount; $this->lines = $lines; $this->tax = $tax; }
	public function get_id() { return $this->id; }
	public function get_items( $t = 'line_item' ) { return 'line_item' === $t ? $this->lines : $this->tax; }
	public function get_amount() { return $this->amount; }
	public function get_reason() { return $this->reason; }
}
class G3_Order {
	public $id; public $items; public $fees = array(); public $refunds = array(); public $meta = array(); public $paid = true; public $status = 'processing';
	public $total; public $tax = '0.00'; public $shipping = '0.00'; public $completed = null; public $notes = array();
	public function __construct( $id, $items, $total, $meta = array() ) { $this->id = $id; $this->items = $items; $this->total = $total; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save_meta_data() {}
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function is_paid() { return $this->paid; }
	public function get_currency() { return 'EUR'; }
	public function get_refunds() { return $this->refunds; }
	public function get_items( $t = 'line_item' ) { return 'line_item' === $t ? $this->items : ( 'fee' === $t ? $this->fees : array() ); }
	public function get_date_paid() { return null; }
	public function get_date_created() { return null; }
	public function get_date_completed() { return $this->completed ? new DateTimeImmutable( '@' . $this->completed ) : null; }
	public function get_coupon_codes() { return array(); }
	public function get_customer_id() { return 0; }
	public function get_billing_email() { return 'g3-kunde@example.de'; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
	public function get_shipping_total() { return $this->shipping; }
	public function get_status() { return $this->status; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function get_parent_id() { return 0; }
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) { return isset( $GLOBALS['za_orders'][ (int) $id ] ) ? $GLOBALS['za_orders'][ (int) $id ] : false; }
}
if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $id, $tax ) { return isset( $GLOBALS['g3_terms'][ (int) $id ][ $tax ] ) ? $GLOBALS['g3_terms'][ (int) $id ][ $tax ] : array(); }
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $id, $tax = '', $type = '' ) { return isset( $GLOBALS['g3_ancestors'][ (int) $id ] ) ? $GLOBALS['g3_ancestors'][ (int) $id ] : array(); }
}
if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed', 'shipped' ); }
}
$g3_set = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$g3_order = static function ( $id, $items, $total, $meta = array() ) {
	$o = new G3_Order( $id, $items, $total, $meta );
	$GLOBALS['za_orders'][ $id ] = $o;
	return $o;
};
$g3_sum = static function ( $order, $source = 'woocommerce' ) use ( $wpdb, $g3c ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$g3c} WHERE source = %s AND source_order_id = %s AND status <> 'rejected'", $source, (string) $order ) );
};
$g3_job = static function ( $key ) use ( $wpdb, $g3j ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT status, last_error_code, attempts FROM {$g3j} WHERE idempotency_key = %s", $key ) );
};

$g3a    = AffiliateRepository::create( array( 'name' => 'G3 Partner', 'email' => 'g3-partner@example.de', 'status' => 'active' ) );
$g3code = CodeRepository::main_code( $g3a );
// 10 % for this partner, whatever global rules other checks leave behind.
$g3rule = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $g3a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'g3' );
$g3meta = array( '_wpac_partner_code' => $g3code );

/* R5-K1: a refund deducted in the booked sale is not deducted again by its waiting job. ------ */
$o = $g3_order( 83001, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
Jobs::enqueue( 'woo_order', 'woocommerce:83001', array( 'order_id' => 83001 ), 'db', 'first attempt failed' );
$o->refunds = array( new G3_Refund( 83101, '20.00', array( new G3_Item( '-20.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) ) );
$GLOBALS['za_orders'][83101] = $o->refunds[0];
OrderSync::sync_refund( 83001, 83101 );
check( 'R5-K1 refund waits behind the queued sale', 'queued', (string) $g3_job( 'refund:woocommerce:83001:83101' )->status );
OrderSync::sync_order( 83001 ); // e.g. the next status hook books the sale (refund already netted).
check( 'R5-K1 the waiting refund job is closed as already deducted', array( 'done', 'superseded' ), array( $g3_job( 'refund:woocommerce:83001:83101' )->status, $g3_job( 'refund:woocommerce:83001:83101' )->last_error_code ) );
$wpdb->query( "UPDATE {$g3j} SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key LIKE '%:83001%'" );
Jobs::run_due();
check( 'R5-K1 partial refund deducted once (base 80 €, 10 % = 8 €)', array( 800, 8000 ), array( $g3_sum( 83001 ), (int) $wpdb->get_var( "SELECT SUM(base_minor) FROM {$g3c} WHERE source_order_id = '83001' AND kind = 'sale'" ) ) );
check( 'R5-K1 the same refund reported again later is not deducted', 'superseded', wpac_record_refund( array( 'source' => 'woocommerce', 'order_id' => '83001', 'refund_id' => '83101', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) )['status'] );
// Other sources: refunds of an unknown order are parked; netted_refund_ids decides.
$g3p = wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'k1-a', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) );
check( 'R5-O18 refund before its sale is parked, not lost', 'parked', is_array( $g3p ) ? $g3p['status'] : 'error' );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'k1-a', 'currency' => 'EUR', 'partner_code' => $g3code, 'netted_refund_ids' => array(), 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
$wpdb->query( "UPDATE {$g3j} SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key LIKE '%:k1-a:%'" );
Jobs::run_due();
check( 'R5-O18 parked refund is booked once the sale arrives (not netted by the source)', 800, $g3_sum( 'k1-a', 'g3src' ) );
wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'k1-b', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'k1-b', 'currency' => 'EUR', 'partner_code' => $g3code, 'netted_refund_ids' => array( 'r1' ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 8000 ) ) ) );
wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'k1-c', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'k1-c', 'currency' => 'EUR', 'partner_code' => $g3code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 8000 ) ) ) );
$wpdb->query( "UPDATE {$g3j} SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key LIKE '%:k1-%'" );
Jobs::run_due();
check( 'R5-K1 netted refunds of other sources (listed, or contract 1.0–1.2 without the list) are not deducted twice', array( 800, 800 ), array( $g3_sum( 'k1-b', 'g3src' ), $g3_sum( 'k1-c', 'g3src' ) ) );

/* R7-PV1: a tax-only refund line (no get_total()) is no fatal error. ----------------------- */
$o = $g3_order( 83002, array( 1 => new G3_Item( '100.00', '19.00' ) ), '119.00', $g3meta );
$o->tax = '19.00';
$g3tax  = new G3_Refund( 83102, '19.00', array(), array( new G3_TaxItem( '-19.00' ) ) );
check( 'R7-PV1 tax-only refund: nothing to deduct, no fatal error', array(), OrderSync::refund_lines( $o, $g3tax ) );
$o->refunds = array( $g3tax );
check( 'R7-PV1 payload of an order with a tax refund still builds', 10000, OrderSync::payload( $o )['items'][0]['net_minor'] );

/* R7-PV4: amount-only refunds use the net line total, not the gross order total. ----------- */
$o = $g3_order( 83003, array( 1 => new G3_Item( '100.00', '19.00' ) ), '124.95', $g3meta );
$o->tax      = '19.95';
$o->shipping = '5.00';
check( 'R7-PV4 11,90 € refund (gross) = 10 € net of the line (old: 9,52 €)', array( array( 'item_id' => '1', 'net_minor' => 1000 ) ), OrderSync::refund_lines( $o, new G3_Refund( 83103, '11.90' ) ) );

/* R5-W13 / R7-FR5: negative fees and gift cards lower the base; gift card purchases are left out. */
$o = $g3_order( 83004, array( 1 => new G3_Item( '100.00', '19.00' ), 2 => new G3_Item( '50.00', '0.00', array(), new G3_Product( 'pw-gift-card' ) ) ), '157.10', $g3meta );
$o->fees = array( new G3_Fee( '-10.00', '-1.90' ) );
$o->tax  = '17.10';
$g3pl    = OrderSync::payload( $o );
check( 'R5-W13 negative fee lowers the base, R7-FR5 gift card line left out', array( 1, '1', 9000 ), array( count( $g3pl['items'] ), $g3pl['items'][0]['item_id'], $g3pl['items'][0]['net_minor'] ) );
$o = $g3_order( 83005, array( 1 => new G3_Item( '100.00', '19.00' ) ), '69.00', $g3meta ); // 50 € paid with a gift card.
$o->tax = '19.00';
check( 'R7-FR5 a redeemed gift card caps the base at what was paid (net)', 5000, OrderSync::payload( $o )['items'][0]['net_minor'] );

/* R7-PV9: bundle parts are merged into the bundle line (its rule, a fixed rate per bundle). */
$o = $g3_order(
	83006,
	array(
		1 => new G3_Item( '0.00', '0.00', array( '_bundle_cart_key' => 'b1' ) ),
		2 => new G3_Item( '30.00', '0.00', array( '_bundled_by' => 'b1' ) ),
		3 => new G3_Item( '20.00', '0.00', array( '_bundled_by' => 'b1' ) ),
	),
	'50.00',
	$g3meta
);
$g3pl = OrderSync::payload( $o );
check( 'R7-PV9 bundle = one line with the amount of its parts', array( 1, '1', 5000 ), array( count( $g3pl['items'] ), $g3pl['items'][0]['item_id'], $g3pl['items'][0]['net_minor'] ) );
check( 'R7-PV9 refund of a part counts for the bundle line', array( array( 'item_id' => '1', 'net_minor' => 3000 ) ), OrderSync::refund_lines( $o, new G3_Refund( 83106, '30.00', array( new G3_Item( '-30.00', '0.00', array( '_refunded_item_id' => 2 ) ) ) ) ) );

/* R7-AD7: parent categories are part of the item; a rule on the parent category applies. */
$GLOBALS['g3_terms'][501]  = array( 'product_cat' => array( 12 ), 'product_tag' => array() );
$GLOBALS['g3_ancestors'][12] = array( 10 );
$g3it             = new G3_Item( '100.00' );
$g3it->product_id = 501;
$o                = $g3_order( 83007, array( 1 => $g3it ), '100.00', $g3meta );
$g3pl             = OrderSync::payload( $o );
check( 'R7-AD7 payload carries own and parent categories', array( array( 12 ), array( 10 ) ), array( $g3pl['items'][0]['category_ids'], $g3pl['items'][0]['category_ancestor_ids'] ) );
$g3norm = ContractApi::normalize_sale( $g3pl );
check( 'R7-AD7 exclusion of the parent category covers the subcategory', true, WPAffiliateCaptain\Commissions\Calculator::calculate( $g3norm['items'][0], array( array( 'id' => 1, 'scope' => 'global', 'scope_id' => 0, 'target' => 'category', 'target_value' => '10', 'rate_type' => 'exclude', 'rate' => '0', 'priority' => 0, 'active' => 1 ) ), 1, 0, array( 'rate_type' => 'percent', 'rate' => '10' ) )['excluded'] );

/* R7-PV3 / R5-W9: reinstatement on any way back to a paid status; refunds stay deducted. -- */
$o = $g3_order( 83008, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83008 );
OrderSync::maybe_chargeback( 83008, 'processing', 'failed' );
OrderSync::maybe_chargeback( 83008, 'failed', 'on-hold' );
check( 'R7-PV3 chargeback reversed the commission', 0, $g3_sum( 83008 ) );
OrderSync::maybe_chargeback( 83008, 'on-hold', 'completed' );
check( 'R7-PV3 on-hold → completed reinstates (not only failed/cancelled/refunded → paid)', 1000, $g3_sum( 83008 ) );
$o = $g3_order( 83009, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
$o->status = 'shipped';
OrderSync::maybe_chargeback( 83009, 'pending', 'shipped' );
check( 'R7-PV3 custom paid status (wc_get_is_paid_statuses) books the sale', 1000, $g3_sum( 83009 ) );
$o = $g3_order( 83010, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83010 );
// wc_create_refund(): status "refunded" first, then the refund lines.
$o->refunds = array( new G3_Refund( 83110, '100.00', array( new G3_Item( '-100.00', '0.00', array( '_refunded_item_id' => 1 ) ) ) ) );
$GLOBALS['za_orders'][83110] = $o->refunds[0];
OrderSync::full_reversal_refunded( 83010 );
OrderSync::sync_refund( 83010, 83110 );
OrderSync::maybe_chargeback( 83010, 'refunded', 'completed' );
check( 'R5-W9 refunded → completed does not give back the commission of a refunded order', 0, $g3_sum( 83010 ) );

/* R5-W10: trash, restore, delete and item changes after payment. -------------------------- */
$o = $g3_order( 83011, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83011 );
OrderSync::order_trashed( 83011 );
check( 'R5-W10 trashed order: commission reversed', 0, $g3_sum( 83011 ) );
OrderSync::order_untrashed( 83011 );
check( 'R5-W10 restored order: commission reinstated', 1000, $g3_sum( 83011 ) );
$o->items[1]->total = '60.00';
$o->total           = '60.00';
OrderSync::items_changed( 83011 );
OrderSync::items_changed( 83011 ); // Saving again changes nothing.
check( 'R5-W10 lowered line after payment reduces the commission (once)', 600, $g3_sum( 83011 ) );
$o->items[1]->total = '80.00';
$o->total           = '80.00';
OrderSync::items_changed( 83011 );
check( 'R5-W10 a higher line is not booked automatically, the order gets a note', array( 600, true ), array( $g3_sum( 83011 ), false !== strpos( (string) end( $o->notes ), 'increased' ) ) );
OrderSync::order_deleted( 83011 );
check( 'R5-W10 deleted order: commission reversed', 0, $g3_sum( 83011 ) );

/* R5-W11: errors of the contract leave a note and a log entry (once). --------------------- */
$o = $g3_order( 83012, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
add_filter( 'wpac_wc_sale_payload', $g3_bad = static function ( $p ) { $p['items'][0]['net_minor'] = 12.5; return $p; } );
OrderSync::sync_order( 83012 );
OrderSync::sync_order( 83012 );
remove_all_filters( 'wpac_wc_sale_payload' );
check( 'R5-W11 refused sale: one order note with the reason', array( 1, 'sale:wpac_invalid_amount' ), array( count( $o->notes ), $o->get_meta( OrderSync::META_LAST_ERROR ) ) );

/* R5-W41: the reason for "not attributed" is stored and readable. ------------------------- */
$o = $g3_order( 83013, array( 1 => new G3_Item( '100.00' ) ), '100.00', array( '_wpac_partner_code' => 'GIBT-ESNICHT' ) );
OrderSync::sync_order( 83013 );
check( 'R5-W41 unknown partner code is the stored reason', 'code_not_found', $o->get_meta( OrderSync::META_UNRESOLVED_REASON ) );
$g3paused = AffiliateRepository::create( array( 'name' => 'G3 Pause', 'email' => 'g3-pause@example.de', 'status' => 'paused' ) );
check( 'R5-W41 partner not active is told apart from "no referral"', array( 'partner_not_eligible', 'no_candidate' ), array( wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'w41-a', 'currency' => 'EUR', 'affiliate_id' => $g3paused, 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) )['reason'], wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'w41-b', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) )['reason'] ) );

/* R7-PV2: no release while the WooCommerce order is on hold (dispute). -------------------- */
add_filter( 'wpac_hold_release', array( OrderSync::class, 'hold_release' ), 10, 3 );
$o = $g3_order( 83014, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83014 );
// Other checks' due commissions are left alone (moved out of the way and back).
$g3due = $wpdb->get_results( "SELECT id, hold_until FROM {$g3c} WHERE status = 'pending' AND hold_until <= UTC_TIMESTAMP()" );
$wpdb->query( "UPDATE {$g3c} SET hold_until = '2099-01-01 00:00:00' WHERE status = 'pending' AND hold_until <= UTC_TIMESTAMP()" );
$wpdb->query( "UPDATE {$g3c} SET hold_until = '2000-01-01 00:00:00' WHERE source_order_id = '83014'" );
$o->status = 'on-hold';
Ledger::approve_due();
$g3st = (string) $wpdb->get_var( "SELECT status FROM {$g3c} WHERE source_order_id = '83014' AND kind = 'sale'" );
$o->status = 'processing';
Ledger::approve_due();
check( 'R7-PV2 on-hold order: commission stays pending, released once processing again', array( 'pending', 'approved' ), array( $g3st, (string) $wpdb->get_var( "SELECT status FROM {$g3c} WHERE source_order_id = '83014' AND kind = 'sale'" ) ) );
foreach ( $g3due as $g3d ) {
	$wpdb->update( $g3c, array( 'hold_until' => $g3d->hold_until ), array( 'id' => (int) $g3d->id ) );
}
remove_all_filters( 'wpac_hold_release' );

/* R7-PV7: hold period from completion. ---------------------------------------------------- */
add_filter( 'wpac_hold_until', array( OrderSync::class, 'hold_until' ), 10, 2 );
$g3_set( array( 'hold_start' => 'completed' ) );
$o = $g3_order( 83015, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83015 );
$g3h1         = (string) $wpdb->get_var( "SELECT hold_until FROM {$g3c} WHERE source_order_id = '83015'" );
$o->status    = 'completed';
$o->completed = strtotime( '2030-03-01 12:00:00 UTC' );
OrderSync::order_completed( 83015 );
check( 'R7-PV7 hold until completion, then completion + hold days', array( '2100-01-01 00:00:00', gmdate( 'Y-m-d H:i:s', $o->completed + (int) Settings::get( 'hold_days' ) * DAY_IN_SECONDS ) ), array( $g3h1, (string) $wpdb->get_var( "SELECT hold_until FROM {$g3c} WHERE source_order_id = '83015'" ) ) );
$g3_set( array( 'hold_start' => 'paid' ) );
remove_all_filters( 'wpac_hold_until' );

/* R7-AD6: renewals do not pay for rejected/reversed first orders; review and limit carry over. */
$g3sub         = new ZA_Order( 83016 );
$g3sub->parent = 83017;
$GLOBALS['za_orders'][83016] = $g3sub;
$GLOBALS['za_orders'][83017] = $g3_order( 83017, array( 1 => new G3_Item( '100.00' ) ), '100.00', $g3meta );
OrderSync::sync_order( 83017 );
$g3ren = new ZA_Order( 83018, array( '_subscription_renewal' => 83016 ) );
check( 'R7-AD6 renewal of a standing first order keeps the partner', $g3a, (int) OrderSync::payload( $g3ren )['affiliate_id'] );
$g3_set( array( 'renewal_limit_months' => 1 ) );
$wpdb->query( "UPDATE " . Db::table( 'attributions' ) . " SET created_at = '2020-01-01 00:00:00' WHERE source_order_id = '83017'" );
check( 'R7-AD6 renewal after the renewal period earns nothing', 0, (int) OrderSync::payload( $g3ren )['affiliate_id'] );
$g3_set( array( 'renewal_limit_months' => 0 ) );
$wpdb->query( "UPDATE " . Db::table( 'attributions' ) . " SET status = 'review' WHERE source_order_id = '83017'" );
check( 'R7-AD6 renewal of a first order under review is reviewed as well', true, ! empty( OrderSync::payload( $g3ren )['review'] ) );
Ledger::reject( (int) $wpdb->get_var( "SELECT id FROM {$g3c} WHERE source_order_id = '83017' AND kind = 'sale'" ), 'Betrug' );
check( 'R7-AD6 renewal of a rejected first order is not attributed', 0, (int) OrderSync::payload( $g3ren )['affiliate_id'] );

/* R6-QA3 / R6-QA4: UTF-8 at the cut position never breaks bookings. ----------------------- */
$g3long = str_repeat( 'a', 190 ) . 'äöü Übergröße';
$g3s    = wpac_record_sale( array( 'source' => 'g3src', 'order_id' => str_repeat( 'b', 63 ) . 'ä', 'currency' => 'EUR', 'partner_code' => $g3code, 'touch' => null, 'items' => array( array( 'item_id' => str_repeat( 'c', 63 ) . 'ö', 'name' => str_repeat( 'n', 254 ) . 'ß', 'net_minor' => 10000 ) ) ) );
check( 'R6-QA4 multibyte order/item ID and name at the column limit are booked', 'created', is_array( $g3s ) ? $g3s['status'] : $g3s->get_error_code() );
$g3r = wpac_record_refund( array( 'source' => 'g3src', 'order_id' => str_repeat( 'b', 63 ) . 'ä', 'refund_id' => 'qa4', 'reason' => str_repeat( 'r', 254 ) . 'é', 'items' => array( array( 'item_id' => str_repeat( 'c', 63 ) . 'ö', 'net_minor' => 1000 ) ) ) );
check( 'R6-QA4 refund with a multibyte reason at the limit is booked', 'adjusted', is_array( $g3r ) ? $g3r['status'] : $g3r->get_error_code() );
$g3cid = (int) $g3s['commission_ids'][0];
Ledger::transition( $g3cid, 'approved' );
check( 'R6-QA3 rejecting an approved commission with a long umlaut reason works', array( true, 'rejected' ), array( Ledger::reject( $g3cid, $g3long ), Ledger::find( $g3cid )->status ) );
check( 'R6-QA3 balance reference is valid UTF-8', true, mb_check_encoding( (string) $wpdb->get_var( 'SELECT reference FROM ' . Db::table( 'balance_entries' ) . " WHERE commission_id = {$g3cid} AND type = 'rejected'" ), 'UTF-8' ) );
check( 'R6-QA3 Db::cut keeps whole characters', array( 'aä', 'ab' ), array( Db::cut( 'aäb', 2 ), Db::cut( 'ab', 5 ) ) );

/* R5-O22 / R6-QA13 / R6-QA16 / R5-W12: payload validation. -------------------------------- */
$g3err = static function ( $p ) {
	$r = wpac_record_sale( $p + array( 'source' => 'g3src', 'currency' => 'EUR', 'partner_code' => '', 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) );
	return is_wp_error( $r ) ? $r->get_error_code() : ( is_array( $r ) ? $r['status'] : 'x' );
};
check( 'R6-QA16 PHP_INT_MAX, "1e3" and an array as order ID are refused', array( 'wpac_invalid_amount', 'wpac_invalid_amount', 'wpac_invalid_reference' ), array( $g3err( array( 'order_id' => 'q16a', 'items' => array( array( 'item_id' => '1', 'net_minor' => PHP_INT_MAX ) ) ) ), $g3err( array( 'order_id' => 'q16b', 'items' => array( array( 'item_id' => '1', 'net_minor' => '1e3' ) ) ) ), $g3err( array( 'order_id' => array( 'x' ) ) ) ) );
check( 'R6-QA16 whole floats from filters still work', 'unattributed', $g3err( array( 'order_id' => 'q16c', 'items' => array( array( 'item_id' => '1', 'net_minor' => 1000.0 ) ) ) ) );
check( 'R5-O22 unknown currency, too many items are refused', array( 'wpac_invalid_currency', 'wpac_too_many_items' ), array( $g3err( array( 'order_id' => 'o22a', 'currency' => 'XYZ' ) ), $g3err( array( 'order_id' => 'o22b', 'items' => array_fill( 0, 501, array( 'net_minor' => 1 ) ) ) ) ) );
check( 'R5-W12 duplicate item_id is refused instead of failing forever', 'wpac_duplicate_item', $g3err( array( 'order_id' => 'w12', 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ), array( 'item_id' => '1', 'net_minor' => 200 ) ) ) ) );
check( 'R6-QA13 milliseconds and Ymd are read as dates, 1970 is refused', array( gmdate( 'Y-m-d H:i:s', 1763000000 ), '2026-11-11 00:00:00', 'wpac_invalid_date' ), array( ContractApi::datetime( 1763000000000 ), ContractApi::datetime( '20261111' ), $g3err( array( 'order_id' => 'q13', 'paid_at' => 1 ) ) ) );
$g3n = ContractApi::normalize_sale( array( 'source' => 'g3src', 'order_id' => 'q13b', 'currency' => 'EUR', 'paid_at' => 1763000000000, 'items' => array( array( 'net_minor' => 1 ) ) ) );
check( 'R6-QA13 hold period counts from the real payment date', 1763000000 + (int) Settings::get( 'hold_days' ) * DAY_IN_SECONDS, Ledger::hold_until( $g3n ) );

/* R5-W15: REST status codes. -------------------------------------------------------------- */
$g3res = new ReflectionMethod( WPAffiliateCaptain\Rest\RestApi::class, 'result' );
$g3res->setAccessible( true );
$g3_503 = $g3res->invoke( null, new WP_Error( 'wpac_not_queued', 'down' ) );
$g3_202 = $g3res->invoke( null, array( 'status' => 'queued', 'adjustment_ids' => array() ) );
check( 'R5-W15 not stored = 503 + Retry-After; queued refund = 202 like a queued sale', array( 503, '300', 202 ), array( $g3_503->status, $g3_503->headers['Retry-After'], $g3_202->status ) );
check( 'R5-O22 /sales has an args schema', true, isset( $GLOBALS['test_routes']['wpac/v1/sales']['args']['items']['maxItems'] ) );

/* R5-O19: refund of an unknown item is reported. ------------------------------------------ */
$g3u = wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'k1-a', 'refund_id' => 'o19', 'items' => array( array( 'item_id' => '99', 'net_minor' => 100 ) ) ) );
check( 'R5-O19 unknown item_id: status unknown_items and audit entry', array( 'unknown_items', array( '99' ), true ), array( $g3u['status'], $g3u['unknown_items'], (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Db::table( 'audit' ) . " WHERE action = 'refund.unknown_items' LIMIT 1" ) ) );

/* R5-O20 / R5-O21: queue handling. -------------------------------------------------------- */
Jobs::enqueue( 'g3nohandler', 'o20', array(), 'e', 'e' );
$g3jid = (int) $wpdb->get_var( "SELECT id FROM {$g3j} WHERE idempotency_key = 'g3nohandler:o20'" );
$wpdb->query( "UPDATE {$g3j} SET next_attempt_at = '2000-01-01 00:00:00' WHERE id = {$g3jid}" );
Jobs::run( $g3jid );
check( 'R5-O20 missing handler waits without using up attempts', array( 'queued', 1, 'no_handler' ), array( $g3_job( 'g3nohandler:o20' )->status, (int) $g3_job( 'g3nohandler:o20' )->attempts, $g3_job( 'g3nohandler:o20' )->last_error_code ) );
$wpdb->query( "UPDATE {$g3j} SET status = 'running' WHERE id = {$g3jid}" );
check( 'R5-O21 "retry now" leaves a running job alone', array( false, 'running' ), array( Jobs::retry( $g3jid ), $g3_job( 'g3nohandler:o20' )->status ) );
$wpdb->query( "DELETE FROM {$g3j} WHERE id = {$g3jid}" );

/* R5-W40: failed sales are not purged silently. ------------------------------------------- */
Jobs::enqueue( 'sale', 'g3src:w40', array(), 'e', 'e' );
Jobs::enqueue( 'g3other', 'w40', array(), 'e', 'e' );
$wpdb->query( "UPDATE {$g3j} SET status = 'failed', updated_at = '2020-01-01 00:00:00' WHERE idempotency_key IN ('sale:g3src:w40','g3other:w40')" );
WPAffiliateCaptain\Core\Cron::purge_jobs();
check( 'R5-W40 failed sale kept, other failed job purged with audit entry', array( true, false, true ), array( (bool) $g3_job( 'sale:g3src:w40' ), (bool) $g3_job( 'g3other:w40' ), (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Db::table( 'audit' ) . " WHERE action = 'job.purged' LIMIT 1" ) ) );
$wpdb->query( "DELETE FROM {$g3j} WHERE idempotency_key = 'sale:g3src:w40'" );

/* R5-O3: amount filter once per line and never above the base. ---------------------------- */
$GLOBALS['g3_calls'] = 0;
add_filter( 'wpac_commission_base', static function ( $b ) { return intdiv( $b, 2 ); } );
add_filter( 'wpac_commission_amount', static function ( $a ) { ++$GLOBALS['g3_calls']; return 999999; } );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'o3', 'currency' => 'EUR', 'partner_code' => $g3code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
remove_all_filters( 'wpac_commission_base' );
remove_all_filters( 'wpac_commission_amount' );
check( 'R5-O3 amount filter runs once and is capped at the base', array( 1, 5000 ), array( $GLOBALS['g3_calls'], $g3_sum( 'o3', 'g3src' ) ) );

/* R5-O27: only rules that can apply to the partner are loaded. ---------------------------- */
$g3other = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $g3paused, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '50', 'active' => 1 ) ), 0, 'g3' );
$g3ids   = array_map( 'intval', wp_list_pluck( RuleRepository::active_for( $g3a, 0 ), 'id' ) );
check( 'R5-O27 rules of other partners are not loaded', array( true, false ), array( in_array( (int) $g3rule, $g3ids, true ), in_array( (int) $g3other, $g3ids, true ) ) );
RuleRepository::delete( $g3other, 'g3' );

/* R5-W7: reassignment fires its notifications only after the COMMIT. ---------------------- */
$g3b = AffiliateRepository::create( array( 'name' => 'G3 Neu', 'email' => 'g3-neu@example.de', 'status' => 'active' ) );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'w7', 'currency' => 'EUR', 'partner_code' => $g3code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
$GLOBALS['test_fired'] = array();
$g3re = Ledger::reassign( ContractApi::normalize_sale( array( 'source' => 'g3src', 'order_id' => 'w7', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) ), $g3b, 'Falscher Partner' );
check( 'R5-W7 reassignment: created/adjusted/attributed hooks fire outside the transaction', array( 'created', true, true, true ), array( is_array( $g3re ) ? $g3re['status'] : 'error', hook_fired_outside_tx( 'wpac_commission_created' ), hook_fired_outside_tx( 'wpac_commission_adjusted' ), hook_fired_outside_tx( 'wpac_affiliate_attributed' ) ) );
// The check before the transaction finds the partner, the booking inside it does not.
$GLOBALS['g3_rc'] = 0;
add_filter( 'wpac_attribution_candidates', $g3drop = static function ( $c, $s ) { return ! empty( $s['reassign_round'] ) && 1 < ++$GLOBALS['g3_rc'] ? array() : $c; }, 10, 2 );
$GLOBALS['test_fired'] = array();
$g3re2 = Ledger::reassign( ContractApi::normalize_sale( array( 'source' => 'g3src', 'order_id' => 'w7', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) ), $g3a, 'Zurück' );
remove_all_filters( 'wpac_attribution_candidates' );
$g3fired = array_filter( $GLOBALS['test_fired'], static function ( $f ) { return 'wpac_commission_adjusted' === $f[0]; } );
check( 'R5-W7 failed reassignment: no hook for the rolled back reversal, no "database error"', array( true, array(), false ), array( is_wp_error( $g3re2 ), array_values( $g3fired ), is_wp_error( $g3re2 ) && false !== strpos( $g3re2->get_error_message(), 'database error' ) ) );

/* R7-PV6: other sources can withdraw a chargeback; a reassignment is never reinstated. ---- */
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'pv6', 'currency' => 'EUR', 'partner_code' => $g3code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'pv6', 'refund_id' => 'cb-1', 'type' => 'chargeback' ) );
$g3ri = wpac_record_reinstatement( array( 'source' => 'g3src', 'order_id' => 'pv6', 'refund_id' => 'cb-1', 'reason' => 'Streitfall gewonnen' ) );
check( 'R7-PV6 wpac_record_reinstatement() reinstates a chargeback of another source', array( 'reinstated', 1000 ), array( $g3ri['status'], $g3_sum( 'pv6', 'g3src' ) ) );
check( 'R7-PV6 the reversal of a reassignment is not reinstated', 0, Ledger::reinstate( 'g3src', 'w7', 'x' ) );
check( 'R7-PV6 REST route /reinstatements exists', true, isset( $GLOBALS['test_routes']['wpac/v1/reinstatements'] ) );

/* R7-PU4: manual bookings, reopening a rejection. ----------------------------------------- */
$g3m = Ledger::manual_adjustment( $g3a, 'EUR', 2500, 'Bonus Messeaktion' );
check( 'R7-PU4 manual booking: approved at once, in the balance', array( 'approved', 'adjustment', 2500 ), array( Ledger::find( $g3m )->status, Ledger::find( $g3m )->kind, (int) Ledger::find( $g3m )->amount_minor ) );
check( 'R7-PU4 manual booking needs a reason and an amount', array( 'wpac_reason_required', 'wpac_amount' ), array( Ledger::manual_adjustment( $g3a, 'EUR', 100, '' )->get_error_code(), Ledger::manual_adjustment( $g3a, 'EUR', 0, 'x' )->get_error_code() ) );
$g3rej = (int) $wpdb->get_var( "SELECT id FROM {$g3c} WHERE source_order_id = '83017' AND kind = 'sale'" );
check( 'R7-PU4 a rejection can be reopened for review', array( true, 'review' ), array( Ledger::reopen( $g3rej, 'Kein Betrug' ), Ledger::find( $g3rej )->status ) );

/* R7-DB9: booking a sale writes no marker row into wp_options. ---------------------------- */
$g3opts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_inflight\\_%'" );
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'db9', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'net_minor' => 100 ) ) ) );
Jobs::begin_inflight( 'g3src', 'db9-x' );
$g3pending = Jobs::sale_pending( 'g3src', 'db9-x' );
Jobs::end_inflight( 'g3src', 'db9-x' );
check( 'R7-DB9 in-flight marker is a MySQL lock, not an option row', array( $g3opts, true, false ), array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wpac\\_inflight\\_%'" ), $g3pending, Jobs::sale_pending( 'g3src', 'db9-x' ) ) );

/* R5-W33: a late refund marks the month of the sale for recalculation. -------------------- */
wpac_record_sale( array( 'source' => 'g3src', 'order_id' => 'w33', 'currency' => 'EUR', 'partner_code' => $g3code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
$g3month = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-15' ) . ' -4 month' ) );
$wpdb->query( "UPDATE {$g3c} SET created_at = '{$g3month}-15 10:00:00', updated_at = '{$g3month}-15 10:00:00' WHERE source_order_id = 'w33'" );
wpac_record_refund( array( 'source' => 'g3src', 'order_id' => 'w33', 'refund_id' => 'late', 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ) ) );
check( 'R5-W33 late refund: the month of the sale is recalculated', true, in_array( $g3month, WPAffiliateCaptain\Commissions\TierService::changed_months( gmdate( 'Y-m', strtotime( gmdate( 'Y-m-15' ) . ' -1 month' ) ) ), true ) );

RuleRepository::delete( $g3rule, 'g3' );
check( 'R8-G3 accounts consistent', array(), WPAffiliateCaptain\Commissions\Balance::verify_all() );
