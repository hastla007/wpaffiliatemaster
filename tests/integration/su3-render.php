<?php
/**
 * AFF-SU-03 render checks (agent su3): the review quality box on the partner page (overview tab
 * only, only with a review plugin), the review column of the campaign report, and nothing in
 * the partner area (the review plugin is not even asked). Included from run.php after the
 * render files (r_render() and template stubs of r8-render.php); fixtures from su3.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Portal\Portal;

echo "SU3 render: review quality on admin screens only\n";
global $wpdb;

$su3r_get  = $_GET;
$su3r_user = $GLOBALS['test_user'];
$su3r_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$su3r_a = (int) $GLOBALS['su3_fixture']['a'];

remove_all_filters( 'wpac_review_aggregates' );
$GLOBALS['su3_queries'] = array();
$su3r_answer            = array(
	'a:' . $su3r_a               => array( 'reviews' => 12, 'orders' => 7, 'stars' => array( 1 => 1, 2 => 1, 3 => 1, 4 => 4, 5 => 5 ) ),
	'a:' . $su3r_a . '|c:spring' => array( 'reviews' => 10, 'orders' => 5, 'stars' => array( 1 => 0, 2 => 0, 3 => 2, 4 => 4, 5 => 4 ) ),
);
$su3r_provider = static function ( $agg, $query ) use ( $su3r_answer ) {
	$GLOBALS['su3_queries'][] = $query;
	return $su3r_answer;
};

// Partner page without a review plugin: no box.
$_GET    = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $su3r_a );
$su3r_no = r_render( array( AffiliatesPage::class, 'render' ) );

add_filter( 'wpac_review_aggregates', $su3r_provider, 10, 2 );
$su3r_ov = r_render( array( AffiliatesPage::class, 'render' ) );
// Average (12): (1+2+3+16+25)/12 = 3.9; 75 % 4–5 stars, 17 % 1–2 stars.
check(
	'SU3-R1 partner page: box only with a review plugin; figures 12 reviews, 7 orders, 3,9 / 5, 75 %, 17 %; no PHP problems',
	array( false, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $su3r_no[0], 'Review quality' ),
		false !== strpos( $su3r_ov[0], 'Review quality' ),
		false !== strpos( $su3r_ov[0], '<th scope="row">Reviews</th><td>12</td>' ) && false !== strpos( $su3r_ov[0], '<td>7</td>' ),
		false !== strpos( $su3r_ov[0], '3,9 / 5' ),
		false !== strpos( $su3r_ov[0], '75 %' ) && false !== strpos( $su3r_ov[0], '17 %' ),
		array_merge( $su3r_no[1], $su3r_ov[1] ),
		$su3r_no[2] . $su3r_ov[2],
	)
);
$_GET           = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $su3r_a, 'tab' => 'overview', 'review_days' => '365' );
$su3r_365       = r_render( array( AffiliatesPage::class, 'render' ) );
$su3r_365_query = end( $GLOBALS['su3_queries'] );
$GLOBALS['su3_queries'] = array();
$_GET           = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $su3r_a, 'tab' => 'profile' );
$su3r_prof      = r_render( array( AffiliatesPage::class, 'render' ) );
check(
	'SU3-R1 period links (30/90/365 days), review_days=365 asks for 365 days; other tabs do not ask the review plugin',
	array( 365, true, 0, false ),
	array( $su3r_365_query['days'], false !== strpos( $su3r_365[0], 'review_days=30' ) && false !== strpos( $su3r_365[0], '<strong>365 days</strong>' ), count( $GLOBALS['su3_queries'] ), false !== strpos( $su3r_prof[0], 'Review quality' ) )
);

// Below the minimum: explanation, no figures.
remove_all_filters( 'wpac_review_aggregates' );
add_filter( 'wpac_review_aggregates', static function () use ( $su3r_a ) { return array( 'a:' . $su3r_a => array( 'reviews' => 9, 'orders' => 9, 'stars' => array( 5 => 9 ) ) ); }, 10, 2 );
$_GET      = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $su3r_a );
$su3r_few  = r_render( array( AffiliatesPage::class, 'render' ) );
check( 'SU3-R2 below the minimum: explanation, no figures', array( true, false ), array( false !== strpos( $su3r_few[0], 'Not enough reviews in this period (at least 10 reviews from 5 different orders)' ), false !== strpos( $su3r_few[0], '<th scope="row">Reviews</th>' ) ) );

// Campaign report.
remove_all_filters( 'wpac_review_aggregates' );
$_GET    = array( 'page' => 'wpac', 'view' => 'campaign' );
$su3r_c0 = r_render( array( DashboardPage::class, 'render' ) );
add_filter( 'wpac_review_aggregates', $su3r_provider, 10, 2 );
$GLOBALS['su3_queries'] = array();
$su3r_c1 = r_render( array( DashboardPage::class, 'render' ) );
$su3r_cq = $GLOBALS['su3_queries'];
check(
	'SU3-R3 campaign report: review column only with a review plugin, one query for all rows, spring 4,2 / 5 (10), autumn "–" with explanation',
	array( false, true, 1, true, true, true, array(), '' ),
	array(
		false !== strpos( $su3r_c0[0], 'Reviews (90 days)' ),
		false !== strpos( $su3r_c1[0], 'Reviews (90 days)' ),
		count( $su3r_cq ),
		isset( $su3r_cq[0]['refs'][ 'a:' . $su3r_a . '|c:spring' ], $su3r_cq[0]['refs'][ 'a:' . $su3r_a . '|c:autumn' ] ),
		false !== strpos( $su3r_c1[0], '4,2 / 5 (10 reviews)' ),
		1 === preg_match( '#<td class="wpac-review-quality" title="Not enough reviews[^"]*">–</td>#u', $su3r_c1[0] ),
		array_merge( $su3r_c0[1], $su3r_c1[1] ),
		$su3r_c0[2] . $su3r_c1[2],
	)
);
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_PROGRAM, Capabilities::MANAGE_PAYOUTS );
$GLOBALS['su3_queries'] = array();
$su3r_c2 = r_render( array( DashboardPage::class, 'render' ) );
unset( $GLOBALS['test_caps'] );
check( 'SU3-R3 without wpac_manage_affiliates: no column, the review plugin is not asked', array( false, 0 ), array( false !== strpos( $su3r_c2[0], 'Reviews (90 days)' ), count( $GLOBALS['su3_queries'] ) ) );

// Partner area: nothing, the review plugin is not asked.
$su3r_uid = 915931;
$GLOBALS['test_users'][ $su3r_uid ] = (object) array( 'ID' => $su3r_uid, 'user_email' => 'su3-anna@example.de', 'display_name' => 'SU3 Anna', 'user_login' => 'su3anna', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']               = $su3r_uid;
$GLOBALS['su3_queries']             = array();
$su3r_portal                        = '';
$su3r_problems                      = array();
foreach ( array( 'overview', 'statistics', 'links', 'commissions', 'payouts', 'account', 'privacy' ) as $su3r_tab ) {
	$_GET          = array( 'wpac_tab' => $su3r_tab );
	$su3r_p        = r_render( array( Portal::class, 'render' ) );
	$su3r_portal  .= $su3r_p[0];
	$su3r_problems = array_merge( $su3r_problems, (array) $su3r_p[1], '' === (string) $su3r_p[2] ? array() : array( $su3r_tab . ': ' . $su3r_p[2] ) );
}
check(
	'SU3-R4 partner area (all tabs) with a review plugin: rendered, the partner is shown, no review figures, the review plugin is not asked',
	array( array(), true, false, 0 ),
	array( $su3r_problems, false !== strpos( $su3r_portal, 'su3-' ) || false !== strpos( $su3r_portal, 'SU3 Anna' ), (bool) preg_match( '#Review quality|Reviews \(|/ 5 \(#', $su3r_portal ), count( $GLOBALS['su3_queries'] ) )
);

remove_all_filters( 'wpac_review_aggregates' );
$_GET                 = $su3r_get;
$GLOBALS['test_user'] = $su3r_user;
if ( null !== $su3r_caps ) {
	$GLOBALS['test_caps'] = $su3r_caps;
}
