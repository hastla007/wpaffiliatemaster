<?php
/**
 * Render checks of audit panel round 12, part D: refused contract payloads in the integration
 * status, the edge cache hint and the block checkout hint in the settings, the portal blocks.
 * Included from tests/integration/run.php after r8-render.php (uses r_render() and its stubs).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\IntegrationsPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Sales\ContractApi;

echo "R12 D render: integration status, settings hints\n";

$r12r_get = $_GET;
$r12r_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );

// R12-M10: the refused payload of r12-d.php is listed in the integration status.
if ( ! ContractApi::rejected() ) {
	ContractApi::action_result( 'wpac_v1_record_sale', array( 'source' => 'r12addon', 'order_id' => 'R12-A1' ), new WP_Error( 'wpac_invalid_currency', 'currency must be an ISO 4217 code' ) );
}
$r12r_m = new ReflectionMethod( IntegrationsPage::class, 'render_rejected' );
$r12r_m->setAccessible( true );
$r = r_render( static function () use ( $r12r_m ) { $r12r_m->invoke( null ); } );
check( 'R12-M10 integration status lists refused action payloads', array( true, true, true, array(), '' ), array( false !== strpos( $r[0], 'Refused through the action hooks' ), false !== strpos( $r[0], 'R12-A1' ), false !== strpos( $r[0], 'wpac_invalid_currency' ), $r[1], $r[2] ) );
check( 'R12-M10 rendered from the status tab', true, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Pages/IntegrationsPage.php' ), "self::render_status();\n\t\t\tself::render_rejected();" ) );

// R12-M30: edge cache hint next to the beacon setting.
$_SERVER['HTTP_CF_RAY'] = 'r12-render';
$_GET                   = array( 'page' => 'wpac-settings', 'tab' => 'tracking' );
$r                      = r_render( array( SettingsPage::class, 'render' ) );
unset( $_SERVER['HTTP_CF_RAY'] );
check( 'R12-M30 settings (tracking) show the edge cache hint', array( true, array(), '' ), array( false !== strpos( $r[0], 'A page or edge cache was detected (Cloudflare)' ), $r[1], $r[2] ) );
// R12-N16: without WooCommerce 8.9 the block checkout hint (only when WooCommerce is active).
check( 'R12-N16 block checkout hint shown when WooCommerce lacks the 8.9 field API', class_exists( 'WooCommerce' ) && ! function_exists( 'woocommerce_register_additional_checkout_field' ), false !== strpos( $r[0], 'from WooCommerce 8.9 on' ) );

// R12-M19: the portal block still renders its markup (the stylesheet is enqueued by Portal::render()).
$r12r_user            = $GLOBALS['test_user'];
$GLOBALS['test_user'] = 0;
$r                    = r_render( array( WPAffiliateCaptain\Portal\Portal::class, 'render' ) );
$GLOBALS['test_user'] = $r12r_user;
check( 'R12-M19 portal renders without block.json style', array( true, array(), '' ), array( '' !== trim( $r[0] ), $r[1], $r[2] ) );

$_GET = $r12r_get;
if ( null !== $r12r_caps ) {
	$GLOBALS['test_caps'] = $r12r_caps;
}
