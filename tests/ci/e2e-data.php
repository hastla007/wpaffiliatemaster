<?php
/**
 * Extra data for the browser tests of the automatic partner coupon and the password confirmation
 * before money actions (tests/e2e/run.js, suites "autoCoupon" and "reauth"). Run after
 * install-wp.sh on the same site:
 *
 *   php tests/ci/e2e-data.php <wp-dir> <url> >> site.env
 *
 * Creates (idempotent per run):
 * - WooCommerce: a partner "E2E Coupon Partner" whose code is linked to a new 10 % coupon, a
 *   customer coupon with "individual use only" and a page with the classic cart shortcode;
 * - three pending payouts (bank transfer) of a partner "E2E Payout Partner".
 *
 * Prints KEY=value lines (E2E_*) for the browser tests.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$wp_dir = rtrim( $argv[1], '/' );
$parts  = parse_url( $argv[2] );
$_SERVER['HTTP_HOST']   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $wp_dir . '/wp-load.php';
wp_set_current_user( 1 );

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

function e2e_out( $k, $v ) {
	echo $k, '=', $v, "\n";
}
function e2e_fail( $msg ) {
	fwrite( STDERR, "e2e-data failed: $msg\n" );
	exit( 1 );
}
function e2e_partner( $name, $email ) {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . WPAffiliateCaptain\Core\Db::table( 'affiliates' ) . ' WHERE email = %s', $email ) );
	if ( ! $id ) {
		$id = (int) AffiliateRepository::create( array( 'name' => $name, 'email' => $email, 'status' => 'active' ) );
	}
	if ( ! $id ) {
		e2e_fail( 'partner ' . $name );
	}
	return $id;
}

if ( ! class_exists( 'WPAffiliateCaptain\\Plugin' ) ) {
	e2e_fail( 'Affiliate Captain not active' );
}
global $wpdb;

// -------------------------------------------------------------------------------------------
// Automatic partner coupon (WooCommerce only).
if ( class_exists( 'WooCommerce' ) ) {
	$coupon_aff = e2e_partner( 'E2E Coupon Partner', 'e2e-coupon-partner@example.test' );
	$row        = CodeRepository::main_row( $coupon_aff );
	if ( ! $row ) {
		e2e_fail( 'code of the coupon partner' );
	}
	$coupon = 'e2epartner10';
	if ( ! wc_get_coupon_id_by_code( $coupon ) ) {
		$r = WPAffiliateCaptain\Integrations\WooCommerce\CouponLink::create_for_partner(
			$coupon_aff,
			array(
				'code_id'        => (int) $row->id,
				'coupon_code'    => $coupon,
				'discount_type'  => 'percent',
				'amount'         => '10',
				'expires'        => '',
				'usage_limit'    => 0,
				'individual_use' => false,
			)
		);
		if ( is_wp_error( $r ) ) {
			e2e_fail( 'partner coupon: ' . $r->get_error_message() );
		}
	}
	$row = CodeRepository::find( (int) $row->id );
	if ( ! $row || $coupon !== CodeRepository::normalize_coupon( (string) $row->coupon_code ) ) {
		e2e_fail( 'partner coupon not linked to the code' );
	}
	// A coupon the customer enters themselves, "individual use only" (must never be removed).
	$customer = 'e2ekunde5';
	if ( ! wc_get_coupon_id_by_code( $customer ) ) {
		$c = new WC_Coupon();
		$c->set_code( $customer );
		$c->set_discount_type( 'percent' );
		$c->set_amount( '5' );
		$c->set_individual_use( true );
		$c->save();
	}
	// Classic cart (shortcode) next to the Cart/Checkout blocks pages that WooCommerce created.
	if ( ! get_page_by_path( 'e2e-classic-cart' ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'e2e-classic-cart', 'post_title' => 'Warenkorb klassisch', 'post_content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->', 'post_author' => 1 ) );
	}
	e2e_out( 'E2E_AUTO_CODE', (string) $row->code );
	e2e_out( 'E2E_AUTO_COUPON', $coupon );
	e2e_out( 'E2E_CUSTOMER_COUPON', $customer );
}

// -------------------------------------------------------------------------------------------
// Payouts for the password confirmation (mark as paid, transfer list download).
$pay_aff = e2e_partner( 'E2E Payout Partner', 'e2e-payout-partner@example.test' );
if ( ! ProfileRepository::get( $pay_aff ) || true !== ProfileRepository::missing( ProfileRepository::get( $pay_aff ) ) ) {
	ProfileRepository::save( $pay_aff, array( 'tax_status' => 'private', 'legal_name' => 'E2E Payout Partner', 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'E2E Payout Partner', 'iban' => 'DE89370400440532013000' ) );
	// Bank details older than the payouts (an export refuses details changed after the payout).
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . WPAffiliateCaptain\Core\Db::table( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = %d", $pay_aff ) );
}
$pay_code = CodeRepository::main_code( $pay_aff );
// Self-billing needs accepted partner terms; the CI site has none (setting restored below).
$self_billing = (int) Settings::get( 'self_billing' );
Settings::update( array( 'self_billing' => 0 ) );
$payouts = array();
$open    = $wpdb->get_results( $wpdb->prepare( 'SELECT id, number FROM ' . WPAffiliateCaptain\Core\Db::table( 'payouts' ) . " WHERE affiliate_id = %d AND status = 'pending' ORDER BY id ASC", $pay_aff ) );
foreach ( $open as $p ) {
	$payouts[] = $p;
}
for ( $i = count( $payouts ); $i < 3; $i++ ) {
	$order = 'e2e-payout-' . $i . '-' . time();
	$sale  = wpac_record_sale( array( 'source' => 'ci', 'order_id' => $order, 'currency' => 'EUR', 'partner_code' => $pay_code, 'items' => array( array( 'item_id' => '1', 'product_id' => 999001, 'name' => 'E2E Kurs', 'net_minor' => 100000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $order . '@example.test' ) ) );
	if ( is_wp_error( $sale ) || empty( $sale['commission_ids'] ) ) {
		e2e_fail( 'commission for payout ' . $i . ( is_wp_error( $sale ) ? ': ' . $sale->get_error_message() : '' ) );
	}
	foreach ( (array) $sale['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	try {
		$pid = (int) PayoutService::create_for_affiliate( $pay_aff, wp_date( 'Y-m-d' ), 'EUR' );
	} catch ( Throwable $e ) {
		e2e_fail( 'payout ' . $i . ': ' . $e->getMessage() );
	}
	if ( ! $pid ) {
		e2e_fail( 'payout ' . $i . ': nothing payable' );
	}
	$payouts[] = PayoutService::find( $pid );
}
Settings::update( array( 'self_billing' => $self_billing ) );
foreach ( array( 'A', 'B', 'C' ) as $n => $letter ) {
	e2e_out( 'E2E_PAYOUT_' . $letter, (int) $payouts[ $n ]->id );
	e2e_out( 'E2E_PAYOUT_' . $letter . '_NO', (string) $payouts[ $n ]->number );
}
