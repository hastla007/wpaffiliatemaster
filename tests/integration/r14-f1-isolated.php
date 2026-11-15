<?php
/**
 * Feature f1 (0.15.0): partner coupon applied automatically after a partner link – checks with
 * their own WooCommerce stand-ins (cart, session, coupons, notices). Started by r14-f1.php in its
 * own process with its own table prefix; prints the results as JSON on the last line:
 * name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\Tracker;

$f1 = array();
function f1( $name, $expected, $actual ) {
	global $f1;
	$f1[ $name ] = array( $expected, $actual );
}

// ---------------------------------------------------------------------------------------------
// Stand-ins.
function remove_filter( $tag, $cb, $prio = 10 ) {
	foreach ( $GLOBALS['test_hooks'][ $tag ][ $prio ] ?? array() as $i => $h ) {
		if ( $h[0] === $cb ) {
			unset( $GLOBALS['test_hooks'][ $tag ][ $prio ][ $i ] );
		}
	}
	return true;
}
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function wc_coupons_enabled() { return true; }
function wc_get_coupon_id_by_code( $code ) { return (int) ( $GLOBALS['f1_coupons'][ strtolower( trim( (string) $code ) ) ]['id'] ?? 0 ); }
function wc_get_notices() { return $GLOBALS['f1_notices']; }
function wc_set_notices( $n ) { $GLOBALS['f1_notices'] = $n; }
function wc_add_notice( $m, $t = 'success' ) { $GLOBALS['f1_notices'][ $t ][] = array( 'notice' => $m ); }
function wc_price( $a ) { return '<span class="amount">' . number_format( (float) $a, 2, ',', '.' ) . '&nbsp;&euro;</span>'; }
function wc_format_localized_decimal( $v ) { return str_replace( '.', ',', (string) $v ); }
if ( ! function_exists( 'shortcode_atts' ) ) { function shortcode_atts( $d, $a, $s = '' ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style() {} }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'is_rtl' ) ) { function is_rtl() { return false; } }
if ( ! function_exists( 'wp_style_add_data' ) ) { function wp_style_add_data() {} }

class WC_Coupon {
	const WC_COUPON_SUCCESS = 200;
	private $d;
	private $code;
	public function __construct( $code = '' ) {
		$this->code = strtolower( trim( (string) $code ) );
		if ( is_numeric( $code ) ) {
			foreach ( $GLOBALS['f1_coupons'] as $c => $d ) {
				if ( (int) $d['id'] === (int) $code ) {
					$this->code = $c;
				}
			}
		}
		$this->d = $GLOBALS['f1_coupons'][ $this->code ] ?? array();
	}
	public function get_id() { return (int) ( $this->d['id'] ?? 0 ); }
	public function get_code() { return $this->code; }
	public function get_meta( $k ) { return ''; }
	public function get_individual_use() { return ! empty( $this->d['individual'] ); }
	public function get_discount_type() { return $this->d['type'] ?? 'percent'; }
	public function get_amount() { return $this->d['amount'] ?? '0'; }
	public function get_usage_limit() { return (int) ( $this->d['limit'] ?? 0 ); }
	public function get_usage_count() { return (int) ( $this->d['count'] ?? 0 ); }
	public function get_date_expires() { return isset( $this->d['expires'] ) ? new DateTime( '@' . (int) $this->d['expires'] ) : null; }
	public function min_amount() { return (float) ( $this->d['min'] ?? 0 ); }
}
class WC_Discounts {
	private $cart;
	public function __construct( $cart ) { $this->cart = $cart; }
	public function is_coupon_valid( $c ) {
		if ( ! $c->get_id() ) {
			return new WP_Error( 'invalid', 'Coupon "' . $c->get_code() . '" does not exist!' );
		}
		$e = $c->get_date_expires();
		if ( $e && $e->getTimestamp() < time() ) {
			return new WP_Error( 'invalid', 'This coupon has expired.' );
		}
		if ( $c->get_usage_limit() && $c->get_usage_count() >= $c->get_usage_limit() ) {
			return new WP_Error( 'invalid', 'Coupon usage limit has been reached.' );
		}
		if ( $c->min_amount() > $this->cart->total() ) {
			return new WP_Error( 'invalid', 'The minimum spend for this coupon is …' );
		}
		return true;
	}
}
class F1_Cart {
	public $items   = array();
	public $coupons = array();
	public function is_empty() { return ! $this->items; }
	public function total() { return array_sum( $this->items ); }
	public function get_applied_coupons() { return $this->coupons; }
	public function get_cart_hash() { return md5( json_encode( $this->items ) ); }
	public function has_discount( $c ) { return in_array( strtolower( $c ), $this->coupons, true ); }
	// Like WC_Cart::apply_coupon(): validation errors become notices; an individual-use coupon
	// removes all others; success message through "woocommerce_coupon_message".
	public function apply_coupon( $code ) {
		$code = strtolower( $code );
		$c    = new WC_Coupon( $code );
		$v    = ( new WC_Discounts( $this ) )->is_coupon_valid( $c );
		if ( true !== $v ) {
			wc_add_notice( $v->get_error_message(), 'error' );
			return false;
		}
		if ( $this->has_discount( $code ) ) {
			wc_add_notice( 'Coupon code already applied!', 'error' );
			return false;
		}
		if ( $c->get_individual_use() ) {
			$this->coupons = array();
		}
		foreach ( $this->coupons as $o ) {
			if ( ( new WC_Coupon( $o ) )->get_individual_use() ) {
				wc_add_notice( 'Sorry, coupon "' . $o . '" has already been applied and cannot be used in conjunction with other coupons.', 'error' );
				return false;
			}
		}
		$this->coupons[] = $code;
		wc_add_notice( apply_filters( 'woocommerce_coupon_message', 'Coupon code applied successfully.', 200, $c ), 'success' );
		do_action( 'woocommerce_applied_coupon', $code );
		return true;
	}
	public function remove_coupon( $code ) {
		$this->coupons = array_values( array_diff( $this->coupons, array( strtolower( $code ) ) ) );
		do_action( 'woocommerce_removed_coupon', $code );
		return true;
	}
}
class F1_Session {
	public $data = array();
	public function has_session() { return true; }
	public function get( $k, $d = null ) { return array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d; }
	public function set( $k, $v ) { $this->data[ $k ] = $v; }
}
class F1_Customer {
	public $email = '';
	public function get_billing_email() { return $this->email; }
}
class F1_WC {
	public $cart;
	public $session;
	public $customer;
	public $rest = false;
	public function is_rest_api_request() { return $this->rest; }
}
class F1_Order {
	public $codes = array();
	public $meta  = array();
	public function get_coupon_codes() { return $this->codes; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
}
$GLOBALS['f1_wc'] = new F1_WC();
function WC() { return $GLOBALS['f1_wc']; }

// Settings and helpers.
global $wpdb;
$f1_base = (array) get_option( 'wpac_settings', array() );
$set     = static function ( array $s ) use ( $f1_base ) {
	update_option( 'wpac_settings', array_merge( $f1_base, array( 'cookie_mode' => 'consent' ), $s ) );
	Settings::flush_cache();
};
$reset = static function ( $touch_code = '' ) {
	$wc           = $GLOBALS['f1_wc'];
	$wc->cart     = new F1_Cart();
	$wc->session  = new F1_Session();
	$wc->customer = new F1_Customer();
	$wc->rest     = false;
	if ( '' !== $touch_code ) {
		$wc->session->data[ Tracker::SESSION_KEY ] = array( 'code' => $touch_code, 'campaign' => '', 'content_id' => 0, 'ts' => time() );
	}
	$GLOBALS['f1_notices'] = array();
	$GLOBALS['test_user']  = 0;
};
$add = static function ( $price = 50 ) {
	$GLOBALS['f1_wc']->cart->items[] = $price;
	do_action( 'woocommerce_add_to_cart' );
};
$notices = static function ( $type = null ) {
	if ( null === $type ) {
		return array_sum( array_map( 'count', $GLOBALS['f1_notices'] ) );
	}
	return count( $GLOBALS['f1_notices'][ $type ] ?? array() );
};
$coupon = static function ( $code, $aff, array $data ) {
	static $id = 7000;
	$GLOBALS['f1_coupons'][ $code ] = array_merge( array( 'id' => ++$id, 'type' => 'percent', 'amount' => '10' ), $data );
	$row = (int) CodeRepository::create( $aff, CodeRepository::code_for_coupon( $code ), array( 'coupon_code' => $code ) );
	return $row;
};
$GLOBALS['f1_coupons'] = array();
$GLOBALS['test_user']  = 0;

// Hooks as WooCommerce::register_hooks() registers them.
AutoCoupon::register_hooks();

// Partners: Anna (coupon anna10 linked to an extra code; link with her main code), Bert (expired
// coupon), Carl (individual-use coupon), Dora (coupon with minimum spend), Eve (partner account).
$anna = AffiliateRepository::create( array( 'name' => 'Anna Auto', 'email' => 'anna-auto@example.de', 'status' => 'active' ) );
$bert = AffiliateRepository::create( array( 'name' => 'Bert Auto', 'email' => 'bert-auto@example.de', 'status' => 'active' ) );
$carl = AffiliateRepository::create( array( 'name' => 'Carl Auto', 'email' => 'carl-auto@example.de', 'status' => 'active' ) );
$dora = AffiliateRepository::create( array( 'name' => 'Dora Auto', 'email' => 'dora-auto@example.de', 'status' => 'active' ) );
$eve  = AffiliateRepository::create( array( 'name' => 'Eve Auto', 'email' => 'eve-auto@example.de', 'status' => 'active', 'user_id' => 77 ) );
$coupon( 'anna10', $anna, array() );
$coupon( 'bert-old', $bert, array( 'expires' => time() - DAY_IN_SECONDS ) );
$coupon( 'carl-solo', $carl, array( 'individual' => 1, 'type' => 'fixed_cart', 'amount' => '5' ) );
$coupon( 'dora100', $dora, array( 'min' => 100 ) );
$coupon( 'eve15', $eve, array( 'amount' => '15' ) );
$GLOBALS['f1_coupons']['vip'] = array( 'id' => 7900, 'type' => 'percent', 'amount' => '20', 'individual' => 1 ); // Customer's own coupons (no partner).
$GLOBALS['f1_coupons']['summer'] = array( 'id' => 7901, 'type' => 'percent', 'amount' => '5' );
$code_of = static function ( $aff ) { return CodeRepository::main_code( $aff ); };

// ---------------------------------------------------------------------------------------------
// Setting off (default): nothing happens.
f1( 'F1 setting is off by default (existing and new installations)', array( 0, 0 ), array( (int) Settings::defaults()['auto_apply_coupon'], (int) Settings::initial()['auto_apply_coupon'] ) );
$set( array() );
$reset( $code_of( $anna ) );
$add();
f1( 'F1 setting off: no coupon, no notice', array( array(), 0, 'disabled' ), array( WC()->cart->get_applied_coupons(), $notices(), AutoCoupon::maybe_apply( true ) ) );

$set( array( 'auto_apply_coupon' => 1 ) );

// Applied once.
$reset( $code_of( $anna ) );
$add();
$first = array( WC()->cart->get_applied_coupons(), $notices( 'success' ), $notices( 'error' ) );
$add();
do_action( 'woocommerce_cart_loaded_from_session' );
f1(
	'F1 coupon applied once after the partner link, one notice (own wording), no duplicates on further products or page loads',
	array( array( array( 'anna10' ), 1, 0 ), array( 'anna10' ), 1, true ),
	array( $first, WC()->cart->get_applied_coupons(), $notices(), false !== strpos( (string) ( $GLOBALS['f1_notices']['success'][0]['notice'] ?? '' ), 'applied automatically' ) )
);
// The order remembers it.
$order        = new F1_Order();
$order->codes = array( 'ANNA10' );
do_action( 'woocommerce_checkout_create_order', $order, array() );
$order2 = new F1_Order();
AutoCoupon::mark_order( $order2 ); // Coupon no longer part of this order.
f1( 'F1 order remembers the automatic coupon (only when the order carries it)', array( array( 'coupon' => 'anna10', 'affiliate_id' => $anna ), array() ), array( $order->meta[ AutoCoupon::META_ORDER ] ?? null, $order2->meta ) );

// Attribution: coupon and link point to the same partner, with and without coupon priority.
$sale = array( 'source' => 'woocommerce', 'coupon_codes' => array( 'anna10' ), 'touch' => array( 'code' => $code_of( $anna ), 'method' => 'link' ), 'customer' => array( 'user_id' => 0, 'email' => 'buyer@example.org' ) );
$set( array( 'auto_apply_coupon' => 1, 'coupon_priority' => 1 ) );
$r1 = AttributionResolver::resolve( $sale );
$set( array( 'auto_apply_coupon' => 1, 'coupon_priority' => 0 ) );
$r2 = AttributionResolver::resolve( $sale );
$set( array( 'auto_apply_coupon' => 1 ) );
f1( 'F1 attribution unchanged: coupon first → coupon, link first → link, both the same partner', array( array( $anna, 'coupon' ), array( $anna, 'link' ) ), array( array( (int) $r1['attribution']['affiliate_id'], $r1['attribution']['method'] ), array( (int) $r2['attribution']['affiliate_id'], $r2['attribution']['method'] ) ) );

// Removed by the customer: not applied again in this session.
WC()->cart->remove_coupon( 'anna10' );
$add();
do_action( 'woocommerce_cart_loaded_from_session' );
f1( 'F1 coupon removed by the customer is not applied again', array(), WC()->cart->get_applied_coupons() );

// Carts created later in the same session (referral first, cart later) and an existing cart.
$reset( $code_of( $anna ) );
do_action( 'woocommerce_cart_loaded_from_session' ); // No cart yet.
$no_cart = WC()->cart->get_applied_coupons();
WC()->cart->items[] = 30; // Cart from an earlier visit, loaded from the session.
do_action( 'woocommerce_cart_loaded_from_session' );
f1( 'F1 empty cart: nothing; cart loaded later in the same session: applied', array( array(), array( 'anna10' ) ), array( $no_cart, WC()->cart->get_applied_coupons() ) );

// No referral.
$reset();
$add();
f1( 'F1 not applied without a referral in this session', array( array(), 0, 'no_referral' ), array( WC()->cart->get_applied_coupons(), $notices(), AutoCoupon::maybe_apply( true ) ) );

// Page loads after the add-to-cart without referral repeat no checks; a referral stored later is noticed.
$u1 = AutoCoupon::maybe_apply( false );
$u2 = AutoCoupon::maybe_apply( false );
WC()->session->data[ Tracker::SESSION_KEY ] = array( 'code' => $code_of( $anna ), 'campaign' => '', 'content_id' => 0, 'ts' => time() );
$u3 = AutoCoupon::maybe_apply( false );
f1( 'F1 page loads: unchanged cart and referral skipped, a link followed later with an existing cart applies the coupon', array( 'unchanged', 'unchanged', 'applied' ), array( $u1, $u2, $u3 ) );

// Invalid/expired coupon: silently ignored.
$reset( $code_of( $bert ) );
$add();
f1( 'F1 expired coupon ignored silently (no coupon, no error notice)', array( array(), 0, 'invalid' ), array( WC()->cart->get_applied_coupons(), $notices(), AutoCoupon::maybe_apply( true ) ) );
// Unknown coupon (deleted in WooCommerce): the stale link resolves to nobody.
unset( $GLOBALS['f1_coupons']['bert-old'] );
f1( 'F1 deleted coupon is not offered', '', AutoCoupon::coupon_for( $bert ) );

// Minimum spend: refused silently, tried again once the cart changed.
$reset( $code_of( $dora ) );
$add( 40 );
$before = WC()->cart->get_applied_coupons();
do_action( 'woocommerce_cart_loaded_from_session' );
$add( 70 );
f1( 'F1 coupon with minimum spend: silent while not reached, applied once the cart reaches it', array( array(), array( 'dora100' ), 0 ), array( $before, WC()->cart->get_applied_coupons(), $notices( 'error' ) ) );

// Individual-use coupon of the customer already in the cart.
$reset( $code_of( $anna ) );
WC()->cart->items[]   = 50;
WC()->cart->coupons[] = 'vip';
$add();
f1( 'F1 customer\'s individual-use coupon in the cart: kept, partner coupon not added, no notice', array( array( 'vip' ), 0 ), array( WC()->cart->get_applied_coupons(), $notices() ) );
// Partner coupon is individual use, customer entered another coupon: the customer's coupon stays.
$reset( $code_of( $carl ) );
WC()->cart->items[]   = 50;
WC()->cart->coupons[] = 'summer';
$add();
f1( 'F1 individual-use partner coupon never removes the customer\'s coupon', array( array( 'summer' ), 0 ), array( WC()->cart->get_applied_coupons(), $notices() ) );
// Combinable coupons: the partner coupon joins the customer's coupon.
$reset( $code_of( $anna ) );
WC()->cart->items[]   = 50;
WC()->cart->coupons[] = 'summer';
$add();
f1( 'F1 combinable customer coupon stays, partner coupon added', array( 'summer', 'anna10' ), WC()->cart->get_applied_coupons() );
// The customer typed the partner coupon himself: nothing applied, nothing marked.
$reset( $code_of( $anna ) );
WC()->cart->items[]   = 50;
WC()->cart->coupons[] = 'anna10';
$add();
$order        = new F1_Order();
$order->codes = array( 'anna10' );
AutoCoupon::mark_order( $order );
f1( 'F1 partner coupon typed by the customer: no second application, order not marked', array( array( 'anna10' ), 0, array() ), array( WC()->cart->get_applied_coupons(), $notices(), $order->meta ) );

// Blocked / paused partner.
$reset( $code_of( $carl ) );
$wpdb->query( 'UPDATE ' . Db::table( 'affiliates' ) . " SET status = 'paused' WHERE id = " . (int) $carl );
AffiliateRepository::clear_cache();
$add();
f1( 'F1 paused partner: no coupon', array( array(), 'no_referral' ), array( WC()->cart->get_applied_coupons(), AutoCoupon::maybe_apply( true ) ) );
$wpdb->query( 'UPDATE ' . Db::table( 'affiliates' ) . " SET status = 'active' WHERE id = " . (int) $carl );
AffiliateRepository::clear_cache();
// Coupon link blocked (code row blocked) while the partner is active: link still valid, no coupon.
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'blocked' WHERE coupon_code = 'carl-solo'" );
$reset( $code_of( $carl ) );
$add();
f1( 'F1 blocked coupon link: no coupon', array( array(), 'no_coupon' ), array( WC()->cart->get_applied_coupons(), AutoCoupon::maybe_apply( true ) ) );

// Self-referral: the partner's own account, or the partner's e-mail as the shop customer.
$reset( $code_of( $eve ) );
$GLOBALS['test_user'] = 77;
$add();
$by_account = WC()->cart->get_applied_coupons();
$reset( $code_of( $eve ) );
WC()->customer->email = 'Eve-Auto@example.de';
$add();
f1( 'F1 self-referral (own account / own e-mail): no coupon', array( array(), array(), 'self' ), array( $by_account, WC()->cart->get_applied_coupons(), AutoCoupon::maybe_apply( true ) ) );
$GLOBALS['test_user'] = 0;

// Filter.
$veto = static function ( $apply, $code, $aff ) use ( $eve ) { return $apply && ! ( 'eve15' === $code && $eve === $aff ); };
add_filter( 'wpac_auto_apply_coupon', $veto, 10, 3 );
$reset( $code_of( $eve ) );
$add();
f1( 'F1 filter wpac_auto_apply_coupon (bool, coupon, partner) can refuse', array( array(), 'filtered' ), array( WC()->cart->get_applied_coupons(), AutoCoupon::maybe_apply( true ) ) );
remove_filter( 'wpac_auto_apply_coupon', $veto, 10 );

// Link of another partner later in the session (last touch): the automatic coupon follows it.
$reset( $code_of( $anna ) );
$add();
WC()->session->data[ Tracker::SESSION_KEY ] = array( 'code' => $code_of( $eve ), 'campaign' => '', 'content_id' => 0, 'ts' => time() );
$add();
f1( 'F1 later link of another partner: the plugin\'s coupon is replaced (never two partner coupons)', array( 'eve15' ), WC()->cart->get_applied_coupons() );

// Store API (Cart/Checkout blocks): applied, but no notice left for a later page.
$reset( $code_of( $anna ) );
WC()->rest = true;
$add();
f1( 'F1 Store API request: applied without a stray notice', array( array( 'anna10' ), 0 ), array( WC()->cart->get_applied_coupons(), $notices() ) );

// Partner area and welcome box texts.
$set( array( 'auto_apply_coupon' => 1 ) );
$GLOBALS['f1_coupons']['carl-solo']['expires'] = null;
$wpdb->query( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'active' WHERE coupon_code = 'carl-solo'" );
$txt_anna = AutoCoupon::portal_text( $anna );
$txt_carl = AutoCoupon::portal_text( $carl );
$GLOBALS['f1_coupons']['anna10']['limit'] = 3;
$GLOBALS['f1_coupons']['anna10']['count'] = 3;
$txt_used = AutoCoupon::portal_text( $anna );
$GLOBALS['f1_coupons']['anna10']['limit'] = 0;
$set( array( 'auto_apply_coupon' => 0 ) );
$txt_off = AutoCoupon::portal_text( $anna );
f1(
	'F1 partner area: "Your link gives customers 10% / 5,00 € off", nothing when used up or setting off',
	array( true, true, true, '', '' ),
	array( false !== strpos( $txt_anna, '10% off' ) && false !== strpos( $txt_anna, 'ANNA10' ), false !== strpos( $txt_carl, "5,00\u{00A0}€ off" ), '' === AutoCoupon::portal_text( $bert ), $txt_used, $txt_off )
);
$set( array( 'auto_apply_coupon' => 1 ) );
$reset( $code_of( $anna ) );
$plain = Landing::welcome( array() );
$with  = Landing::welcome( array( 'discount' => '1' ) );
f1( 'F1 welcome box shows the discount only with discount="1"', array( false, true ), array( false !== strpos( $plain, 'wpac-welcome-discount' ), false !== strpos( $with, '10% off with the coupon ANNA10' ) ) );

echo "\n" . json_encode( $f1 ) . "\n";
