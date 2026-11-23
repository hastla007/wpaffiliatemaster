<?php
/**
 * SET-AC-20 render checks: the withholding box on the partner page (tab "Payout details": label,
 * legal mark, note, read only without the payout permission, hidden without the tax-data
 * permission) and the lines on the payout receipt. Included from run.php after the render files
 * (r_render() and template stubs of r8-render.php); fixtures from set-ac-wht.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Core\Capabilities;

echo "SET-AC-20 render: withholding on the partner page and the payout receipt\n";

$whr_get  = $_GET;
$whr_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$whr_fx = $GLOBALS['wht_fixture'];

$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $whr_fx['partner'], 'tab' => 'payout' );
$whr_a = r_render( array( AffiliatesPage::class, 'render' ) );
check(
	'SET-AC-20 partner page: box with percentage (15), note, legal mark, described fields, save button; no PHP problems',
	array( true, true, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $whr_a[0], 'name="withholding_percent" value="15"' ),
		false !== strpos( $whr_a[0], 'Quellensteuer nach §50a EStG' ),
		false !== strpos( $whr_a[0], '<span class="wpac-legal-mark">Check legally</span>' ),
		false !== strpos( $whr_a[0], 'aria-describedby="wpac-desc-withholding"' ) && false !== strpos( $whr_a[0], 'id="wpac-desc-withholding"' ),
		false !== strpos( $whr_a[0], 'value="withholding_update"' ) || false !== strpos( $whr_a[0], 'withholding_update' ),
		false !== strpos( $whr_a[0], 'Save withholding' ),
		$whr_a[1],
		$whr_a[2],
	)
);
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES, Capabilities::VIEW_TAX_DATA );
$whr_ro               = r_render( array( AffiliatesPage::class, 'render' ) );
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES );
$whr_no               = r_render( array( AffiliatesPage::class, 'render' ) );
unset( $GLOBALS['test_caps'] );
check(
	'SET-AC-20 partner page: read only without the payout permission, not shown without the tax-data permission',
	array( true, false, false ),
	array( false !== strpos( $whr_ro[0], 'name="withholding_percent" value="15" class="small-text" dir="ltr" aria-describedby="wpac-desc-withholding" readonly' ), false !== strpos( $whr_ro[0], 'Save withholding' ), false !== strpos( $whr_no[0], 'withholding_percent' ) )
);

$_GET  = array( 'page' => 'wpac-payouts', 'action' => 'receipt', 'id' => (string) $whr_fx['payout'] );
$whr_p = r_render( array( PayoutsPage::class, 'render' ) );
$_GET  = array( 'page' => 'wpac-payouts', 'action' => 'receipt', 'id' => (string) $whr_fx['zero'] );
$whr_z = r_render( array( PayoutsPage::class, 'render' ) );
check(
	'SET-AC-20 payout receipt: total credit note and withholding line with note; none at 0 %',
	array( true, true, true, false, array(), '' ),
	array( false !== strpos( $whr_p[0], 'Total credit note' ), false !== strpos( $whr_p[0], 'Withholding 15 %' ), false !== strpos( $whr_p[0], 'Quellensteuer' ), false !== strpos( $whr_z[0], 'Withholding' ), array_merge( $whr_p[1], $whr_z[1] ), $whr_p[2] . $whr_z[2] )
);

$_GET = $whr_get;
if ( null !== $whr_caps ) {
	$GLOBALS['test_caps'] = $whr_caps;
}
