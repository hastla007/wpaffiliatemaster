<?php
/**
 * SET-AC-02 (commission base): net or gross, shipping and fees as own positions; all
 * combinations incl. position-exact and amount-only partial refunds; the base is frozen per
 * order; orders booked before keep the former base; default settings calculate as before;
 * filters wpac_wc_base_cap and wpac_commission_base keep winning; other sales sources are not
 * affected. Included from run.php before r8-render.php (uses the stand-ins of r8-g3.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\CommissionBasis;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;

echo "SET-AC-02 commission base (net/gross, shipping, fees)\n";

global $wpdb;
$b2c = Db::table( 'commissions' );

/* Stand-ins with shipping lines, keyed fees and typed refund items. ------------------------- */
class B2_Order extends G3_Order {
	public $ship_items = array(); public $ship_tax = '0.00';
	public function get_items( $t = 'line_item' ) {
		if ( 'shipping' === $t ) { return $this->ship_items; }
		return parent::get_items( $t );
	}
	public function get_shipping_tax() { return $this->ship_tax; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
}
class B2_Refund extends G3_Refund {
	public $extra = array();
	public function get_items( $t = 'line_item' ) { return 'line_item' === $t ? $this->lines : $this->extra; }
}

$b2_saved = get_option( 'wpac_settings' );
$b2_set   = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$b2_combo = static function ( $basis, $shipping, $fees ) use ( $b2_set ) {
	$b2_set( array( 'commission_basis' => $basis, 'commission_include_shipping' => $shipping ? 1 : 0, 'commission_include_fees' => $fees ? 1 : 0 ) );
};
$b2a    = AffiliateRepository::create( array( 'name' => 'B2 Partner', 'email' => 'b2-partner@example.de', 'status' => 'active' ) );
$b2code = CodeRepository::main_code( $b2a );
$b2rule = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $b2a, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '10', 'priority' => 0, 'active' => 1 ) ), 0, 'b2' );

// Order: product 100,00 + 19,00 VAT, shipping 10,00 + 1,90, payment fee 5,00 + 0,95 = 136,85.
$b2_order = static function ( $id ) use ( $b2code ) {
	$o             = new B2_Order( $id, array( 1 => new G3_Item( '100.00', '19.00' ) ), '136.85', array( '_wpac_partner_code' => $b2code ) );
	$ship          = new G3_Item( '10.00', '1.90' );
	$ship->name    = 'Flat rate';
	$fee           = new G3_Item( '5.00', '0.95' );
	$fee->name     = 'PayPal';
	$o->ship_items = array( 5 => $ship );
	$o->fees       = array( 7 => $fee );
	$o->tax        = '21.85';
	$o->shipping   = '10.00';
	$o->ship_tax   = '1.90';
	$GLOBALS['za_orders'][ $id ] = $o;
	return $o;
};
$b2_lines = static function ( array $items ) {
	$out = array();
	foreach ( $items as $i ) {
		$out[ $i['item_id'] ] = (int) $i['net_minor'];
	}
	return $out;
};
$b2_sum = static function ( $order ) use ( $wpdb, $b2c ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$b2c} WHERE source = 'woocommerce' AND source_order_id = %s AND status <> 'rejected'", (string) $order ) );
};
// Partial refund: half of the product (50,00 + 9,50) and the whole shipping (10,00 + 1,90).
$b2_refund = static function ( $id, $order ) {
	$r        = new B2_Refund( $id, '71.40', array( new G3_Item( '-50.00', '-9.50', array( '_refunded_item_id' => 1 ) ) ) );
	$r->extra = array( new G3_Item( '-10.00', '-1.90', array( '_refunded_item_id' => 5 ) ) );
	$GLOBALS['za_orders'][ $id ] = $r;
	$order->refunds[]            = $r;
	return $r;
};

/* Register: three keys on the Commissions tab, defaults = the former fixed base. ------------- */
SettingsSchema::flush();
$b2_def = SettingsSchema::defaults();
check( 'SET-AC-02 defaults net / no shipping / no fees, legal mark on the base, Commissions tab', array( 'net', 0, 0, true, 'commissions', 'commissions', 'commissions' ), array( $b2_def['commission_basis'], $b2_def['commission_include_shipping'], $b2_def['commission_include_fees'], ! empty( SettingsSchema::field( 'commission_basis' )['legal'] ), SettingsSchema::field( 'commission_basis' )['tab'], SettingsSchema::field( 'commission_include_shipping' )['tab'], SettingsSchema::field( 'commission_include_fees' )['tab'] ) );
$b2_san = Settings::sanitize( array_merge( Settings::all(), array( 'commission_basis' => 'brutto', 'commission_include_shipping' => '1' ) ) );
check( 'SET-AC-02 unknown base falls back to net, switches are 0/1', array( 'net', 1, 0 ), array( $b2_san['commission_basis'], (int) $b2_san['commission_include_shipping'], (int) $b2_san['commission_include_fees'] ) );

/* Default settings: exactly as before (product only, net, capped like before). -------------- */
$b2_combo( 'net', false, false );
$o = $b2_order( 87001 );
check( 'SET-AC-02 default: only the product line, net 100 € (as before)', array( '1' => 10000 ), $b2_lines( OrderSync::payload( $o )['items'] ) );
check( 'SET-AC-02 default: partial refund deducts 50 € net of the product, shipping refund ignored (as before)', array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), OrderSync::refund_lines( $o, $b2_refund( 87101, $o ) ) );
check( 'SET-AC-02 default: amount-only refund 11,90 € = 10 € net (as before)', array( array( 'item_id' => '1', 'net_minor' => 1000 ) ), OrderSync::refund_lines( $o, new B2_Refund( 87102, '11.90' ) ) );

/* All combinations: payload, booking, position-exact partial refund, amount-only refund. ----- */
$b2_cases = array(
	// basis, shipping, fees, payload lines, commission booked, after the partial refund, amount-only
	// refund of 20 % of what the customer paid for the positions of the base (gross), its result.
	array( 'net', false, false, array( '1' => 10000 ), 1000, 500, '23.80', array( '1' => 2000 ) ),
	array( 'net', true, false, array( '1' => 10000, '5' => 1000 ), 1100, 500, '26.18', array( '1' => 2000, '5' => 200 ) ),
	array( 'net', false, true, array( '1' => 10000, '7' => 500 ), 1050, 550, '24.99', array( '1' => 2000, '7' => 100 ) ),
	array( 'net', true, true, array( '1' => 10000, '5' => 1000, '7' => 500 ), 1150, 550, '27.37', array( '1' => 2000, '5' => 200, '7' => 100 ) ),
	array( 'gross', false, false, array( '1' => 11900 ), 1190, 595, '23.80', array( '1' => 2380 ) ),
	array( 'gross', true, false, array( '1' => 11900, '5' => 1190 ), 1309, 595, '26.18', array( '1' => 2380, '5' => 238 ) ),
	array( 'gross', false, true, array( '1' => 11900, '7' => 595 ), 1250, 655, '24.99', array( '1' => 2380, '7' => 119 ) ),
	array( 'gross', true, true, array( '1' => 11900, '5' => 1190, '7' => 595 ), 1369, 655, '27.37', array( '1' => 2380, '5' => 238, '7' => 119 ) ),
);
foreach ( $b2_cases as $n => $case ) {
	list( $basis, $ship, $fees, $lines, $booked, $after, $amount, $amount_only ) = $case;
	$label = $basis . ( $ship ? ' + shipping' : '' ) . ( $fees ? ' + fees' : '' );
	$b2_combo( $basis, $ship, $fees );
	$id = 87010 + $n;
	$o  = $b2_order( $id );
	check( 'SET-AC-02 ' . $label . ': positions of the sale', $lines, $b2_lines( OrderSync::payload( $o )['items'] ) );
	check( 'SET-AC-02 ' . $label . ': amount-only refund of ' . $amount . ' € (20 % of the positions) in the same base', $amount_only, $b2_lines( OrderSync::refund_lines( $o, new B2_Refund( 87190 + $n, $amount ) ) ) );
	OrderSync::sync_order( $id );
	$frozen = $o->get_meta( CommissionBasis::META );
	check( 'SET-AC-02 ' . $label . ': booked commission (10 %), base frozen on the order', array( $booked, CommissionBasis::encode( array( 'basis' => $basis, 'shipping' => $ship, 'fees' => $fees ) ) ), array( $b2_sum( $id ), $frozen ) );
	// The settings change after the sale: the refund still uses the frozen base.
	$b2_combo( 'net' === $basis ? 'gross' : 'net', ! $ship, ! $fees );
	$r = $b2_refund( 87110 + $n, $o );
	OrderSync::sync_refund( $id, $r->get_id() );
	check( 'SET-AC-02 ' . $label . ': partial refund (half product, all shipping) deducted in the booked base', $after, $b2_sum( $id ) );
}

/* Orders booked before the setting existed keep the former base. ---------------------------- */
$b2_combo( 'net', false, false );
$o = $b2_order( 87030 );
OrderSync::sync_order( 87030 );
$o->delete_meta_data( CommissionBasis::META ); // As booked by 0.20.0.
$b2_combo( 'gross', true, true );
check( 'SET-AC-02 order booked before (no frozen base): former base net, product only', array( CommissionBasis::legacy(), array( '1' => 10000 ) ), array( OrderSync::basis( $o ), $b2_lines( OrderSync::payload( $o )['items'] ) ) );
$r = $b2_refund( 87130, $o );
OrderSync::sync_refund( 87030, $r->get_id() );
check( 'SET-AC-02 order booked before: partial refund deducts the net product share only (10 € → 5 €)', 500, $b2_sum( 87030 ) );
$o = $b2_order( 87031 );
check( 'SET-AC-02 a new order without booking uses the settings', array( 'basis' => 'gross', 'shipping' => true, 'fees' => true ), OrderSync::basis( $o ) );

/* An unattributed order does not keep a frozen base. ---------------------------------------- */
$o       = $b2_order( 87032 );
$o->meta = array();
OrderSync::sync_order( 87032 );
check( 'SET-AC-02 unattributed order: no frozen base left behind', '', (string) $o->get_meta( CommissionBasis::META ) );

/* Filters keep winning. --------------------------------------------------------------------- */
$o = $b2_order( 87033 );
add_filter( 'wpac_wc_base_cap', static function ( $cap, $order = null, $basis = null ) { return is_array( $basis ) && 'gross' === $basis['basis'] ? 6845 : $cap; }, 10, 3 );
check( 'SET-AC-02 wpac_wc_base_cap gets the base and wins (gross cap 68,45 €, scaled pro rata)', array( '1' => 5952, '5' => 595, '7' => 298 ), $b2_lines( OrderSync::payload( $o )['items'] ) );
remove_all_filters( 'wpac_wc_base_cap' );
add_filter( 'wpac_commission_base', static function ( $b ) { return intdiv( $b, 2 ); } );
OrderSync::sync_order( 87033 );
remove_all_filters( 'wpac_commission_base' );
check( 'SET-AC-02 wpac_commission_base still wins over the chosen base (half of 13,69 €)', 685, $b2_sum( 87033 ) );

/* Fixed rates: shipping and fee positions are no unit sold. --------------------------------- */
$b2_fixed = array( 'id' => 1, 'scope' => 'global', 'scope_id' => 0, 'target' => 'all', 'target_value' => '', 'rate_type' => 'fixed', 'rate' => '500', 'priority' => 0, 'active' => 1 );
$b2_c1    = Calculator::calculate( array( 'product_id' => 0, 'sku' => '', 'quantity' => 1, 'net_minor' => 1000, 'meta' => array( 'wpac_position' => 'shipping' ) ), array( $b2_fixed ), 1, 0, array( 'rate_type' => 'percent', 'rate' => '10' ), false );
$b2_c2    = Calculator::calculate( array( 'product_id' => 0, 'sku' => '', 'quantity' => 1, 'net_minor' => 1000, 'meta' => array() ), array( $b2_fixed ), 1, 0, array( 'rate_type' => 'percent', 'rate' => '10' ), false );
check( 'SET-AC-02 fixed rate: 0 for a shipping position, unchanged for a product line', array( 0, 500 ), array( $b2_c1['amount_minor'], $b2_c2['amount_minor'] ) );

/* Other sales sources: amounts of the payload as reported, whatever the setting. ------------ */
$b2_set( array( 'commission_basis' => 'gross', 'commission_include_shipping' => 1, 'commission_include_fees' => 0 ) );
wpac_record_sale( array( 'source' => 'b2src', 'order_id' => 'b2-1', 'currency' => 'EUR', 'partner_code' => $b2code, 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ) ) );
check( 'SET-AC-02 contract sales (wpac_record_sale) are not affected by the WooCommerce base', 1000, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$b2c} WHERE source = 'b2src' AND source_order_id = 'b2-1'" ) );

/* Labels follow the choice. ----------------------------------------------------------------- */
$b2_gross_labels = array( CommissionBasis::percent_label(), CommissionBasis::portal_suffix() );
$b2_combo( 'net', false, false );
check( 'SET-AC-02 labels: "Percent of net" / "of the net order value" by default, gross and extras when chosen', array( 'Percent of net', 'of the net order value', 'Percent of gross', 'of the gross order value (incl. VAT), including shipping, without fees', 'Basis is the net line amount after discount, excluding tax and shipping.' ), array( CommissionBasis::percent_label(), CommissionBasis::portal_suffix(), $b2_gross_labels[0], $b2_gross_labels[1], CommissionBasis::description() ) );
check( 'SET-AC-02 gross warning names VAT and the EU', array( true, true ), array( false !== strpos( WPAffiliateCaptain\Admin\Pages\SettingsPage::gross_basis_warning(), 'VAT' ), false !== strpos( WPAffiliateCaptain\Admin\Pages\SettingsPage::gross_basis_warning(), 'EU' ) ) );

RuleRepository::delete( $b2rule );
update_option( 'wpac_settings', $b2_saved );
Settings::flush_cache();
