<?php
/**
 * Render check of agent c16 (0.16.0): the dashboard KPI "Active partners" counts the partners of
 * the program chosen in the report selector (filter wpac_report_programs); without a selection,
 * and without an add-on, all active partners as before. Included from tests/integration/run.php
 * after the render files (template stubs of r8-render.php); fixtures from r14-c16.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;

echo "R14 c16 render: active partners of the selected program\n";

$c16r_keep_get = $_GET;
$c16r_caps     = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$c16r_fx  = $GLOBALS['c16_fixture'];
$c16r_kpi = static function ( $html ) {
	return preg_match( '#<span>Active partners</span><strong>([^<]*)</strong>#', $html, $m ) ? $m[1] : null;
};
$c16r_all = number_format_i18n( AffiliateRepository::count_by_status()['active'] );
$_GET     = array( 'page' => 'wpac', 'program' => 'p:' . $c16r_fx['program'] );
$c16r_off = r_render( array( DashboardPage::class, 'render' ) ); // No add-on: no selector, the field is ignored.
add_filter( 'wpac_report_programs', static function () use ( $c16r_fx ) { return array( '' => 'Standard', $c16r_fx['program'] => 'C16 Schulen' ); } );
$c16r_sel = r_render( array( DashboardPage::class, 'render' ) );
$_GET     = array( 'page' => 'wpac', 'program' => '' );
$c16r_any = r_render( array( DashboardPage::class, 'render' ) );
remove_all_filters( 'wpac_report_programs' );
check(
	'C16-2d dashboard: "Active partners" of the selected program; all programs and without add-on: all active partners',
	array( $c16r_all, '1', $c16r_all, array(), '' ),
	array( $c16r_kpi( $c16r_off[0] ), $c16r_kpi( $c16r_sel[0] ), $c16r_kpi( $c16r_any[0] ), array_merge( $c16r_off[1], $c16r_sel[1], $c16r_any[1] ), $c16r_off[2] . $c16r_sel[2] . $c16r_any[2] )
);
$_GET = $c16r_keep_get;
if ( null !== $c16r_caps ) {
	$GLOBALS['test_caps'] = $c16r_caps;
}
