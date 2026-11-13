<?php
/**
 * Test data for the CI site (called by setup.php after the plugins were activated).
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
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_set_current_user( 1 );

function out( $k, $v ) {
	echo $k, '=', $v, "\n";
}
function fail( $msg ) {
	fwrite( STDERR, "data failed: $msg\n" );
	exit( 1 );
}
function page( $slug, $title, $content ) {
	$p = get_page_by_path( $slug );
	if ( $p ) {
		return (int) $p->ID;
	}
	return (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_author' => 1 ) );
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WPAffiliateCaptain\\Plugin' ) ) {
	fail( 'WooCommerce or WP Affiliate Captain not active' );
}

// WooCommerce: pages (Blocks checkout), Germany/EUR, bank transfer, no onboarding.
WC_Install::create_pages();
update_option( 'woocommerce_default_country', 'DE:DE-SH' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Überweisung' ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );

// Product.
$product_id = (int) wc_get_product_id_by_sku( 'CI-KURS' );
if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Segelkurs CI' );
	$product->set_sku( 'CI-KURS' );
	$product->set_regular_price( '49' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
}

// Affiliate Captain: application + partner pages.
$register = page( 'become-a-partner', 'Partner werden', '<!-- wp:shortcode -->[wpac_affiliate_registration]<!-- /wp:shortcode -->' );
$portal   = page( 'partner-area', 'Partnerbereich', '<!-- wp:shortcode -->[wpac_affiliate_portal]<!-- /wp:shortcode -->' );
WPAffiliateCaptain\Core\Settings::update( array( 'registration_page_id' => $register, 'portal_page_id' => $portal, 'operator_address' => "Segelschule CI\nHafen 1\n24103 Kiel", 'operator_country' => 'DE', 'operator_vat_id' => 'DE123456789' ) );

// Partner with a login, an active code, a rule and a commission (lists are not empty).
$user = get_user_by( 'login', 'perf2' );
$uid  = $user ? (int) $user->ID : (int) wp_insert_user( array( 'user_login' => 'perf2', 'user_pass' => 'perf2-pass-123', 'user_email' => 'perf2@example.test', 'role' => 'customer', 'display_name' => 'Perf Partner' ) );
if ( is_wp_error( $uid ) || ! $uid ) {
	fail( 'partner user' );
}
$aff = WPAffiliateCaptain\Affiliates\AffiliateRepository::find_by_user( $uid );
$aid = $aff ? (int) $aff->id : (int) WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'user_id' => $uid, 'name' => 'Perf Partner', 'email' => 'perf2@example.test', 'status' => 'active' ) );
if ( ! $aid ) {
	fail( 'affiliate' );
}
$code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $aid );
if ( ! WPAffiliateCaptain\Commissions\RuleRepository::all() ) {
	WPAffiliateCaptain\Commissions\RuleRepository::save( WPAffiliateCaptain\Commissions\RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'product', 'target_value' => (string) $product_id, 'rate_type' => 'percent', 'rate' => '15', 'priority' => 0, 'active' => 1 ) ), 0, 'ci' );
}
wpac_record_sale( array( 'source' => 'ci', 'order_id' => 'ci-1', 'currency' => 'EUR', 'partner_code' => $code, 'items' => array( array( 'item_id' => '1', 'product_id' => $product_id, 'name' => 'Segelkurs CI', 'net_minor' => 4900 ) ) ) );
flush_rewrite_rules( false );

out( 'PRODUCT_ID', $product_id );
out( 'PARTNER_CODE', $code );
