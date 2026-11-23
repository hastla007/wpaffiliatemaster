<?php
/**
 * Round 15, fix agent s: automatic partner coupon after a later link of another partner on a
 * page load (R15-S2), one partner coupon per cart (R15-S5) and the device check of the recruiter
 * (R15-S6) - with WooCommerce and session stand-ins (copied from r14-f1-isolated.php; coupon
 * validation through the filter "woocommerce_coupon_is_valid" like WooCommerce). Started by
 * r15-s.php in its own process with its own table prefix; prints the results as JSON on the last
 * line: name => [ expected, actual ].
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
require __DIR__ . '/bootstrap.php';

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\Tracker;

$s15 = array();
function s15( $name, $expected, $actual ) {
	global $s15;
	$s15[ $name ] = array( $expected, $actual );
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
	public function get_object() { return $this->cart; }
	// Like WC_Discounts::is_coupon_valid(): the filter "woocommerce_coupon_is_valid" may throw;
	// its message becomes the error.
	public function is_coupon_valid( $c ) {
		$v = $this->checks( $c );
		if ( true !== $v ) {
			return $v;
		}
		try {
			if ( ! apply_filters( 'woocommerce_coupon_is_valid', true, $c, $this ) ) {
				return new WP_Error( 'invalid', 'Coupon is not valid.' );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid', $e->getMessage() );
		}
		return true;
	}
	private function checks( $c ) {
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

// Login sessions of WordPress users (WP_User_Meta_Session_Tokens: ip and ua of each login).
class WP_Session_Tokens {
	private $uid = 0;
	public static function get_instance( $uid ) { $i = new self(); $i->uid = (int) $uid; return $i; }
	public function get( $token ) { return $GLOBALS['s15_sessions'][ $this->uid ][ $token ] ?? null; }
	public function update( $token, $session ) { $GLOBALS['s15_sessions'][ $this->uid ][ $token ] = $session; }
	public function get_all() { return array_values( $GLOBALS['s15_sessions'][ $this->uid ] ?? array() ); }
}
$GLOBALS['s15_sessions'] = array();

// Settings and helpers.
global $wpdb;
$s15_base = (array) get_option( 'wpac_settings', array() );
$set      = static function ( array $s ) use ( $s15_base ) {
	update_option( 'wpac_settings', array_merge( $s15_base, array( 'cookie_mode' => 'consent' ), $s ) );
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
$touch = static function ( $code, $ts ) {
	WC()->session->data[ Tracker::SESSION_KEY ] = array( 'code' => $code, 'campaign' => '', 'content_id' => 0, 'ts' => $ts );
};
$load = static function () {
	do_action( 'woocommerce_cart_loaded_from_session' ); // A page load with the cart.
	return WC()->cart->get_applied_coupons();
};
$errors = static function () {
	return array_map( static function ( $n ) { return $n['notice']; }, $GLOBALS['f1_notices']['error'] ?? array() );
};
$coupon = static function ( $code, $aff, array $data = array() ) {
	static $id = 8000;
	$GLOBALS['f1_coupons'][ $code ] = array_merge( array( 'id' => ++$id, 'type' => 'percent', 'amount' => '10' ), $data );
	return (int) CodeRepository::create( $aff, CodeRepository::code_for_coupon( $code ), array( 'coupon_code' => $code ) );
};
$GLOBALS['f1_coupons'] = array();
$GLOBALS['test_user']  = 0;
AutoCoupon::register_hooks();

$anna = AffiliateRepository::create( array( 'name' => 'Anna S15', 'email' => 'anna-s15@example.de', 'status' => 'active' ) );
$eve  = AffiliateRepository::create( array( 'name' => 'Eve S15', 'email' => 'eve-s15@example.de', 'status' => 'active' ) );
$finn = AffiliateRepository::create( array( 'name' => 'Finn S15', 'email' => 'finn-s15@example.de', 'status' => 'active' ) );
$coupon( 'anna10', $anna );
$coupon( 'anna20', $anna, array( 'amount' => '20' ) );
$coupon( 'eve15', $eve, array( 'amount' => '15' ) );
$coupon( 'finn5', $finn, array( 'amount' => '5' ) );
$GLOBALS['f1_coupons']['summer'] = array( 'id' => 8900, 'type' => 'percent', 'amount' => '5' ); // The shop's own coupon.
$code_of = static function ( $aff ) { return CodeRepository::main_code( $aff ); };
$set( array( 'auto_apply_coupon' => 1, 'coupon_priority' => 1 ) );

// ---------------------------------------------------------------------------------------------
// R15-S2: a later link of another partner replaces the automatic coupon on a page load.
$reset( $code_of( $anna ) );
$add();
$s2_first = WC()->cart->get_applied_coupons();
$touch( $code_of( $eve ), time() + 5 ); // Eve's link, no product added afterwards.
$s2_after = $load();
$s2_state = WC()->session->get( AutoCoupon::SESSION_KEY );
$s2_sale  = array( 'source' => 'woocommerce', 'coupon_codes' => WC()->cart->get_applied_coupons(), 'touch' => array( 'code' => $code_of( $eve ), 'method' => 'link' ), 'customer' => array( 'user_id' => 0, 'email' => 'buyer@example.org' ) );
$s2_attr  = AttributionResolver::resolve( $s2_sale )['attribution'];
s15(
	'R15-S2 repro: Anna\'s link + product (anna10), then Eve\'s link and only a page load: Eve\'s coupon replaces Anna\'s, the order goes to Eve (coupon and link agree)',
	array( array( 'anna10' ), array( 'eve15' ), $eve, 1, $eve, 'coupon' ),
	array( $s2_first, $s2_after, (int) $s2_state['affiliate_id'], (int) $s2_state['applied'], (int) $s2_attr['affiliate_id'], $s2_attr['method'] )
);

// Same partner again (another link of Eve, new time): nothing changes; the same referral costs no query.
$touch( $code_of( $eve ), time() + 10 );
$s2_same = $load();
$s2_q    = $wpdb->num_queries;
$s2_r    = AutoCoupon::maybe_apply( false );
s15( 'R15-S2 another link of the same partner keeps the coupon; page loads with the same referral run no query', array( array( 'eve15' ), 'done', 0 ), array( $s2_same, $s2_r, $wpdb->num_queries - $s2_q ) );

// The customer removed the automatic coupon: not applied again for the same partner …
$reset( $code_of( $anna ) );
$add();
WC()->cart->remove_coupon( 'anna10' );
$s2_removed = $load();
// … but a later link of another partner gives that partner's coupon (as with add to cart).
$touch( $code_of( $finn ), time() + 5 );
$s2_finn = $load();
s15( 'R15-S2 a coupon the customer removed stays removed for that partner; a later link of another partner applies that partner\'s coupon', array( array(), array( 'finn5' ) ), array( $s2_removed, $s2_finn ) );

// A coupon the customer typed is never removed by a later link.
$reset( $code_of( $anna ) );
$add();
WC()->cart->remove_coupon( 'anna10' );
WC()->cart->apply_coupon( 'summer' );
$touch( $code_of( $eve ), time() + 5 );
$s2_typed = $load();
s15( 'R15-S2 the customer\'s own coupon stays when the partner changes (Eve\'s coupon is added next to it)', array( 'summer', 'eve15' ), $s2_typed );

// No valid referral any more (unknown code): the coupon stays, decided once.
$reset( $code_of( $anna ) );
$add();
$touch( 'UNKNOWN-CODE', time() + 5 );
$s2_unknown = $load();
$s2_q       = $wpdb->num_queries;
$s2_r2      = AutoCoupon::maybe_apply( false );
s15( 'R15-S2 a later invalid referral leaves the coupon; checked once, then no query', array( array( 'anna10' ), 'done', 0 ), array( $s2_unknown, $s2_r2, $wpdb->num_queries - $s2_q ) );

// ---------------------------------------------------------------------------------------------
// R15-S5: at most one partner coupon per cart.
$reset( $code_of( $anna ) );
$add();
$s5_ok     = WC()->cart->apply_coupon( 'eve15' ); // Typed by the customer: replaces the automatic coupon.
$s5_cart   = WC()->cart->get_applied_coupons();
$s5_notice = $GLOBALS['f1_notices']['notice'][0]['notice'] ?? '';
$s5_state  = WC()->session->get( AutoCoupon::SESSION_KEY );
$s5_again  = $load();
s15(
	'R15-S5 a partner coupon typed by the customer replaces the automatic one (notice), which is not applied again',
	array( true, array( 'eve15' ), true, 0, 1, array( 'eve15' ) ),
	array( $s5_ok, $s5_cart, false !== strpos( $s5_notice, 'only one partner coupon can be used per order' ), (int) $s5_state['applied'], (int) $s5_state['removed'], $s5_again )
);
$GLOBALS['f1_notices'] = array();
$s5_second = WC()->cart->apply_coupon( 'finn5' );
$s5_err    = $errors();
$s5_same   = WC()->cart->apply_coupon( 'anna20' );
$s5_shop   = WC()->cart->apply_coupon( 'summer' ); // The shop's own coupons stay combinable.
s15(
	'R15-S5 a second partner coupon typed by the customer is refused with a message; the shop\'s own coupon is fine',
	array( false, array( 'Only one partner coupon can be used per order. Please remove the coupon eve15 first.' ), false, true, array( 'eve15', 'summer' ) ),
	array( $s5_second, array_slice( $s5_err, 0, 1 ), $s5_same, $s5_shop, WC()->cart->get_applied_coupons() )
);
// Checking the cart again (WooCommerce validates every applied coupon): the first stays, a later one goes.
WC()->cart->coupons = array( 'eve15', 'summer', 'finn5' );
$s5_check = array();
foreach ( WC()->cart->coupons as $c ) {
	$s5_check[ $c ] = true === ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $c ) );
}
s15( 'R15-S5 re-check of a cart with two partner coupons: only the later one is invalid', array( 'eve15' => true, 'summer' => true, 'finn5' => false ), $s5_check );
// Setting off and the filter wpac_single_partner_coupon: the rule is not tied to the automatic coupon.
$set( array( 'auto_apply_coupon' => 0 ) );
$reset();
WC()->cart->items[] = 50;
$s5_off_1 = WC()->cart->apply_coupon( 'eve15' );
$s5_off_2 = WC()->cart->apply_coupon( 'finn5' );
$s5_no    = static function () { return false; };
add_filter( 'wpac_single_partner_coupon', $s5_no );
$s5_filter = WC()->cart->apply_coupon( 'finn5' );
remove_filter( 'wpac_single_partner_coupon', $s5_no );
s15( 'R15-S5 also without the automatic coupon; the filter wpac_single_partner_coupon allows several', array( true, false, true ), array( $s5_off_1, $s5_off_2, $s5_filter ) );
$set( array( 'auto_apply_coupon' => 1 ) );
// The plugin itself never adds a second partner coupon (unchanged).
$reset( $code_of( $anna ) );
WC()->cart->items[] = 50;
WC()->cart->apply_coupon( 'finn5' );
$s5_auto = AutoCoupon::maybe_apply( true );
s15( 'R15-S5 with a partner coupon of the customer in the cart the automatic one is not added', array( 'present', array( 'finn5' ) ), array( $s5_auto, WC()->cart->get_applied_coupons() ) );

// ---------------------------------------------------------------------------------------------
// R15-S6: recruiter check by device (address and browser of a login session of the recruiter).
$set( array() );
$rita = AffiliateRepository::create( array( 'name' => 'Rita Recruiter', 'email' => 'rita-s15@example.de', 'status' => 'active', 'user_id' => 4711 ) );
$GLOBALS['test_users'][4711] = (object) array( 'ID' => 4711, 'user_email' => 'rita.private@example.org', 'display_name' => 'Rita', 'user_login' => 'rita' );
WP_Session_Tokens::get_instance( 4711 )->update( 'tok', array( 'expiration' => time() + DAY_IN_SECONDS, 'ip' => '93.184.216.34', 'ua' => 'Mozilla/5.0 (Rita)', 'login' => time() ) );
$param   = (string) Settings::get( 'referral_param' );
$recruit = static function ( $email, $ip, $ua ) use ( $param, $rita ) {
	$_GET                       = array( $param => CodeRepository::main_code( $rita ) );
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $ua;
	$r                          = Registration::recruiter( $email, 0 );
	$_GET                       = array();
	unset( $_SERVER['HTTP_USER_AGENT'] );
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	return $r;
};
s15(
	'R15-S6 recruiter: another device is recorded; the address and browser of a login of the recruiter not; same address with another browser and a private address are recorded; the address of the recruiter\'s WordPress account not',
	array( $rita, 0, $rita, $rita, 0 ),
	array(
		$recruit( 'neu1@example.com', '198.51.100.20', 'Mozilla/5.0 (Other)' ),
		$recruit( 'zweitkonto@example.com', '93.184.216.34', 'Mozilla/5.0 (Rita)' ),
		$recruit( 'kollege@example.com', '93.184.216.34', 'Mozilla/5.0 (Colleague)' ),
		$recruit( 'lan@example.com', '10.0.0.5', 'Mozilla/5.0 (Rita)' ),
		$recruit( 'Rita.Private@example.org', '198.51.100.21', 'Mozilla/5.0 (Other)' ),
	)
);
$set( array( 'trusted_proxies' => '93.184.216.0/24' ) );
$s6_proxy = $recruit( 'proxy@example.com', '93.184.216.34', 'Mozilla/5.0 (Rita)' );
$set( array() );
add_filter( 'wpac_is_self_recruitment', static function ( $self, $recruiter, $email ) { return $self || 'flagged@example.com' === $email; }, 10, 3 );
$s6_filter = $recruit( 'flagged@example.com', '198.51.100.22', 'Mozilla/5.0 (Other)' );
s15( 'R15-S6 a trusted proxy address never matches; the filter wpac_is_self_recruitment adds own signals', array( $rita, 0 ), array( $s6_proxy, $s6_filter ) );

echo "\n" . json_encode( $s15 ) . "\n";
