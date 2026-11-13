<?php
/**
 * Smoke test of a CI test site (see install-wp.sh) without a browser: activation and schema,
 * a WooCommerce order with a partner code and its refund (classic checkout path), front-end pages,
 * the public REST routes and every admin page of the plugin over HTTP. Used by the job
 * "Minimum versions" (oldest WordPress/WooCommerce/PHP the plugin declares) and the browser job.
 *
 *   php tests/ci/smoke.php <wp-dir> <url>
 *
 * Needs the running web server (php -S … tests/ci/router.php) and the admin account of setup.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$wp_dir = rtrim( $argv[1] ?? '', '/' );
$url    = rtrim( $argv[2] ?? '', '/' );
if ( ! is_file( $wp_dir . '/wp-load.php' ) || '' === $url ) {
	fwrite( STDERR, "Usage: php tests/ci/smoke.php <wp-dir> <url>\n" );
	exit( 2 );
}
$parts                  = parse_url( $url );
$_SERVER['HTTP_HOST']   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $wp_dir . '/wp-load.php';
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true' );
// Only log lines written during this run are checked (other test steps may have written before).
$log_start = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (int) filesize( WP_CONTENT_DIR . '/debug.log' ) : 0;

$ok   = 0;
$fail = 0;
function chk( $name, $cond, $info = '' ) {
	global $ok, $fail;
	if ( $cond ) {
		++$ok;
		echo "  ok   $name\n";
	} else {
		++$fail;
		echo "  FAIL $name" . ( '' !== (string) $info ? "  [$info]" : '' ) . "\n";
	}
}
function fetch( $url, array $args = array() ) {
	$r = wp_remote_request( $url, $args + array( 'timeout' => 60, 'redirection' => 0 ) );
	return is_wp_error( $r ) ? array( 0, $r->get_error_message(), array() ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), wp_remote_retrieve_cookies( $r ) );
}
function is_plugin_active_for_smoke( $plugin ) {
	return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
}
function broken( $body ) {
	return false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'There has been a critical error' ) || false !== stripos( $body, 'Es gab einen kritischen Fehler' );
}

global $wp_version, $wpdb;
echo 'WordPress ' . $wp_version . ', WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '-' ) . ', PHP ' . PHP_VERSION . ', Affiliate Captain ' . ( defined( 'WPAC_VERSION' ) ? WPAC_VERSION : '-' ) . "\n";

echo "1. Activation\n";
chk( 'plugin active', class_exists( 'WPAffiliateCaptain\\Plugin' ) && is_plugin_active_for_smoke( 'affiliate-captain/affiliate-captain.php' ) );
chk( 'database schema current and complete', (int) get_option( WPAffiliateCaptain\Core\Installer::DB_OPTION ) === WPAC_DB_VERSION && array() === WPAffiliateCaptain\Core\Installer::verify_schema(), json_encode( WPAffiliateCaptain\Core\Installer::verify_schema() ) );
chk( 'capabilities granted to administrators', current_user_can( WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM ) );
chk( 'scheduled tasks registered', (bool) wp_next_scheduled( WPAffiliateCaptain\Core\Cron::HOURLY ) && (bool) wp_next_scheduled( WPAffiliateCaptain\Core\Cron::DAILY ) );

echo "2. WooCommerce order with partner code, then refund\n";
$tag  = substr( md5( (string) microtime( true ) ), 0, 6 );
$aff  = WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'Smoke ' . $tag, 'email' => "smoke-$tag@example.test", 'status' => 'active' ) );
$code = WPAffiliateCaptain\Codes\CodeRepository::main_code( (int) $aff );
chk( 'partner with code created', (int) $aff > 0 && '' !== $code );
$product = new WC_Product_Simple();
$product->set_name( 'Smoke ' . $tag );
$product->set_regular_price( '100' );
$product->set_virtual( true );
$product->save();
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_email( "kunde-$tag@example.test" );
$order->update_meta_data( '_wpac_partner_code', $code );
$order->set_payment_method( 'bacs' );
$order->calculate_totals();
$order->save();
$order->payment_complete();
$order->update_status( 'processing' );
$rows = static function () use ( $wpdb, $order ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT kind, amount_minor, status FROM {$wpdb->prefix}wpac_commissions WHERE source = 'woocommerce' AND source_order_id = %s ORDER BY id", (string) $order->get_id() ), ARRAY_A );
};
$sale = $rows();
chk( 'commission booked for the order', 1 === count( $sale ) && (int) $sale[0]['amount_minor'] > 0, json_encode( $sale ) );
$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'smoke', 'line_items' => array() ) );
$sum    = 0;
foreach ( $rows() as $r ) {
	$sum += 'rejected' === $r['status'] ? 0 : (int) $r['amount_minor'];
}
chk( 'full refund reverses the commission', ! is_wp_error( $refund ) && 0 === $sum, json_encode( $rows() ) );
chk( 'partner accounts consistent', array() === WPAffiliateCaptain\Commissions\Balance::verify_all(), json_encode( WPAffiliateCaptain\Commissions\Balance::verify_all() ) );

echo "3. Front end and REST\n";
$param = (string) WPAffiliateCaptain\Core\Settings::get( 'referral_param' );
list( $status, $body ) = fetch( $url . '/?' . rawurlencode( $param ) . '=' . rawurlencode( $code ) );
chk( 'referral link answers without errors', in_array( $status, array( 200, 302 ), true ) && ! broken( $body ), "HTTP $status" );
foreach ( array( 'registration_page_id' => 'wpac', 'portal_page_id' => 'wpac' ) as $setting => $marker ) {
	$page = (int) WPAffiliateCaptain\Core\Settings::get( $setting );
	if ( ! $page ) {
		chk( $setting . ' configured', false );
		continue;
	}
	list( $status, $body ) = fetch( get_permalink( $page ) );
	chk( $setting . ' page renders', 200 === $status && ! broken( $body ) && false !== strpos( $body, $marker ), "HTTP $status" );
}
list( $status, $body ) = fetch( $url . '/?rest_route=/wpac/v1/contract' );
$json = json_decode( $body, true );
chk( 'REST contract', 200 === $status && is_array( $json ) && WPAC_CONTRACT_VERSION === ( $json['contract'] ?? '' ), "HTTP $status " . substr( $body, 0, 200 ) );
list( $status, $body ) = fetch( $url . '/?rest_route=/wpac/v1/codes/' . rawurlencode( $code ) );
$json = json_decode( $body, true );
chk( 'REST code check knows the partner code', 200 === $status && ! empty( $json['valid'] ), "HTTP $status " . substr( $body, 0, 200 ) );
list( $status ) = fetch( $url . '/?rest_route=/wpac/v1/affiliates' );
chk( 'REST partner list refused without login', in_array( $status, array( 401, 403 ), true ), "HTTP $status" );
list( $status ) = fetch( $url . '/?rest_route=/wpac/v1/health' );
chk( 'REST health refused without token', 403 === $status, "HTTP $status" );

echo "4. Admin pages\n";
list( $status, $body, $cookies ) = fetch(
	$url . '/wp-login.php',
	array(
		'method'  => 'POST',
		'body'    => array( 'log' => getenv( 'ADMIN_USER' ) ?: 'admin', 'pwd' => getenv( 'ADMIN_PASS' ) ?: 'admin-pass-123', 'testcookie' => '1', 'redirect_to' => $url . '/wp-admin/' ),
		'cookies' => array( new WP_Http_Cookie( array( 'name' => 'wordpress_test_cookie', 'value' => 'WP Cookie check' ) ) ),
	)
);
$logged_in = false;
foreach ( $cookies as $c ) {
	$logged_in = $logged_in || 0 === strpos( $c->name, 'wordpress_logged_in_' );
}
chk( 'admin login', $logged_in, "HTTP $status" );
$admin_pages = array( 'wpac', 'wpac-affiliates', 'wpac-commissions', 'wpac-payouts', 'wpac-creatives', 'wpac-rules', 'wpac-integrations', 'wpac-audit', 'wpac-settings', 'wpac-import' );
foreach ( $admin_pages as $slug ) {
	list( $status, $body ) = fetch( $url . '/wp-admin/admin.php?page=' . $slug, array( 'cookies' => $cookies ) );
	chk( "admin page $slug", 200 === $status && ! broken( $body ) && false !== strpos( $body, 'class="wrap' ), "HTTP $status" );
}
$aff_row = WPAffiliateCaptain\Affiliates\AffiliateRepository::find( (int) $aff );
list( $status, $body ) = fetch( $url . '/wp-admin/admin.php?page=wpac-affiliates&action=edit&id=' . (int) $aff, array( 'cookies' => $cookies ) );
chk( 'partner detail page', 200 === $status && ! broken( $body ) && $aff_row && false !== strpos( $body, esc_html( $aff_row->name ) ), "HTTP $status" );

echo "5. PHP log\n";
$log      = WP_CONTENT_DIR . '/debug.log';
$problems = array();
$own      = rtrim( (string) realpath( WPAC_DIR ), '/' ) . '/'; // The plugin may be a symlink to the checkout.
if ( is_readable( $log ) ) {
	clearstatcache();
	$new = (string) file_get_contents( $log, false, null, $log_start );
	foreach ( explode( "\n", $new ) as $line ) {
		if ( preg_match( '/PHP (Fatal error|Warning|Parse error)/', $line ) && ( false !== strpos( $line, $own ) || false !== strpos( $line, 'plugins/affiliate-captain/' ) ) ) {
			$problems[] = trim( $line );
		}
	}
}
chk( 'no PHP errors or warnings from the plugin in debug.log during this run', ! $problems, implode( ' | ', array_slice( array_unique( $problems ), 0, 5 ) ) );

echo "\n$ok ok, $fail failed\n";
exit( $fail ? 1 : 0 );
