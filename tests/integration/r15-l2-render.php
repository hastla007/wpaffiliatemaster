<?php
/**
 * Package L2 (L2-PII), rendered partner area: the tabs of the partner who earned the
 * WooCommerce order with buyer data of r15-l2.php show no buyer e-mail, name or address.
 * Included from run.php after the render files (needs r_render() and the template stubs of
 * r8-render.php; partner and leak check from r15-l2.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Portal\Portal;

echo "R15 L2 render: no buyer data in the partner area\n";

$l2r_get  = $_GET;
$l2r_user = $GLOBALS['test_user'];
$l2r_leak = $GLOBALS['l2_leaks'];

$GLOBALS['test_user'] = (int) $GLOBALS['l2_portal_user'];
$l2r_html             = '';
$l2r_problems         = array();
foreach ( array( 'overview', 'statistics', 'links', 'commissions', 'payouts', 'account', 'privacy' ) as $l2r_tab ) {
	$_GET          = array( 'wpac_tab' => $l2r_tab );
	$l2r           = r_render( array( Portal::class, 'render' ) );
	$l2r_html     .= $l2r[0];
	$l2r_problems  = array_merge( $l2r_problems, (array) $l2r[1], '' === (string) $l2r[2] ? array() : array( $l2r_tab . ': ' . $l2r[2] ) );
}
check( 'L2-PII partner area rendered without PHP problems, the order is listed', array( array(), true ), array( $l2r_problems, false !== strpos( $l2r_html, '915401' ) ) );
check( 'L2-PII partner area (overview, statistics, links, commissions, payouts, account, privacy) shows no buyer data', array(), $l2r_leak( $l2r_html ) );

$_GET                 = $l2r_get;
$GLOBALS['test_user'] = $l2r_user;
