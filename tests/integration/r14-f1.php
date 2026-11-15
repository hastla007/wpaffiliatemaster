<?php
/**
 * Feature f1 (0.15.0): partner coupon applied automatically after a partner link. Setting,
 * registration and documentation here; the behaviour with WooCommerce stand-ins (cart, session,
 * coupons, notices) runs in r14-f1-isolated.php in its own process.
 * Included from run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Settings;

echo "R14 F1 automatic partner coupon\n";
$f1_root = dirname( __DIR__, 2 );
$f1_src  = static function ( $file ) use ( $f1_root ) {
	return (string) file_get_contents( $f1_root . '/' . $file );
};

$f1_on  = Settings::sanitize( array( 'auto_apply_coupon' => '1' ) );
$f1_off = Settings::sanitize( array() );
check(
	'F1 setting "auto_apply_coupon": default off, saved as a flag, on the Promotion tab with a label',
	array( 0, 1, 0, true, true ),
	array( (int) Settings::defaults()['auto_apply_coupon'], $f1_on['auto_apply_coupon'], $f1_off['auto_apply_coupon'], in_array( 'auto_apply_coupon', SettingsPage::tab_keys()['promotion'], true ), isset( SettingsPage::setting_labels()['auto_apply_coupon'] ) )
);
check(
	'F1 hooks registered with the WooCommerce integration (add to cart, cart from session, coupon removed, classic and block checkout)',
	array( true, true, true, true, true, true ),
	array(
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce.php' ), 'AutoCoupon::register_hooks();' ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/AutoCoupon.php' ), "'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20" ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/AutoCoupon.php' ), "'woocommerce_cart_loaded_from_session'" ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/AutoCoupon.php' ), "'woocommerce_removed_coupon'" ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/AutoCoupon.php' ), "'woocommerce_checkout_create_order', array( __CLASS__, 'mark_order' )" ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/AutoCoupon.php' ), "'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mark_order' )" ),
	)
);
check(
	'F1 partner area shows the discount next to the partner link (overview and links tab), order box shows the automatic coupon',
	array( 2, true, true ),
	array(
		substr_count( $f1_src( 'src/Portal/Portal.php' ), 'echo self::link_discount( $aff, ' ),
		false !== strpos( $f1_src( 'src/Integrations/WooCommerce/OrderMetaBox.php' ), 'AutoCoupon::META_ORDER' ),
		false !== strpos( $f1_src( 'blocks/partner-welcome/block.json' ), '"showDiscount"' ) && false !== strpos( $f1_src( 'assets/js/blocks.js' ), 'showDiscount' ),
	)
);
check(
	'F1 filter documented in the contract and feature in the handbook; translations present',
	array( true, true, true ),
	array(
		false !== strpos( $f1_src( 'docs/CONTRACT.md' ), '`wpac_auto_apply_coupon`' ),
		false !== strpos( $f1_src( 'docs/HANDBOOK.md' ), 'Apply the partner\'s coupon automatically' ),
		false !== strpos( $f1_src( 'languages/de_DE.r14-f1.json' ), 'Your link gives customers %1$s off' ),
	)
);

// Behaviour (own process: WooCommerce stand-ins would clash with other files' stubs).
$f1_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
putenv( 'WPAC_TEST_DB_PREFIX=r14f1_' );
$f1_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r14-f1-isolated.php' ) . ' 2>&1', $f1_out, $f1_rc );
putenv( false === $f1_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $f1_prefix );
$f1_iso = json_decode( (string) end( $f1_out ), true );
if ( ! is_array( $f1_iso ) || 0 !== $f1_rc ) {
	check( 'R14 F1 isolated checks ran', 'json', implode( "\n", $f1_out ) );
} else {
	foreach ( $f1_iso as $f1_name => $f1_pair ) {
		check( $f1_name, $f1_pair[0], $f1_pair[1] );
	}
}
