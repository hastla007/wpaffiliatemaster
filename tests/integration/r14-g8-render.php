<?php
/**
 * Render checks of audit panel round 14, fix agent g8: the campaign table of the partner area's
 * statistics tab is paged (25 rows, R14-D9). Included from tests/integration/run.php after the
 * render files (template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Portal\Portal;

echo "R14 g8 render: paged campaign statistics\n";

global $wpdb;
$g8r_keep_user = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$g8r_keep_get  = $_GET;
$g8r_uid       = 914801;
$g8r_aff       = AffiliateRepository::create( array( 'name' => 'G8 Render', 'email' => 'g8-render@example.de', 'status' => 'active' ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => $g8r_uid ), array( 'id' => (int) $g8r_aff ) );
AffiliateRepository::clear_cache( (int) $g8r_aff );
$GLOBALS['test_users'][ $g8r_uid ] = (object) array( 'ID' => $g8r_uid, 'user_email' => 'g8-render@example.de', 'display_name' => 'G8', 'user_login' => 'g8-render', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']              = $g8r_uid;
// 30 campaign/sub-ID combinations with 1 … 30 visits in the last days.
$g8r_values = array();
for ( $i = 1; $i <= 30; $i++ ) {
	for ( $n = 0; $n < $i; $n++ ) {
		$g8r_values[] = $wpdb->prepare( '(%d, %s, %s, %s)', $g8r_aff, 'g8camp', 'sub' . $i, Db::now( time() - 3600 - $n ) );
	}
}
q( 'INSERT INTO ' . t( 'visits' ) . ' (affiliate_id, campaign, sub_id, created_at) VALUES ' . implode( ',', $g8r_values ) );
$_GET  = array( 'wpac_tab' => 'statistics' );
$g8r_1 = r_render( array( Portal::class, 'render' ) );
$_GET  = array( 'wpac_tab' => 'statistics', 'wpac_page' => '2' );
$g8r_2 = r_render( array( Portal::class, 'render' ) );
$_GET  = array( 'wpac_tab' => 'statistics', 'wpac_page' => '9' );
$g8r_9 = r_render( array( Portal::class, 'render' ) );
$g8r_rows = static function ( $html ) {
	return preg_match_all( '/data-label="Sub-ID"><span class="wpac-cell-label" aria-hidden="true">Sub-ID: <\/span>sub\d+</', $html );
};
check(
	'R14-D9 statistics: campaign table with 25 rows per page and a pager; page 2 holds the rest; a page beyond the end shows the last page',
	array( 25, true, true, 5, true, 5, array(), '' ),
	array(
		$g8r_rows( $g8r_1[0] ),
		false !== strpos( $g8r_1[0], '<nav class="wpac-pager"' ),
		false !== strpos( $g8r_1[0], 'sub30<' ) && false === strpos( $g8r_1[0], '>sub1<' ),
		$g8r_rows( $g8r_2[0] ),
		false !== strpos( $g8r_2[0], '>sub1<' ),
		$g8r_rows( $g8r_9[0] ),
		array_merge( $g8r_1[1], $g8r_2[1], $g8r_9[1] ),
		$g8r_1[2] . $g8r_2[2] . $g8r_9[2],
	)
);
$_GET                 = $g8r_keep_get;
$GLOBALS['test_user'] = $g8r_keep_user;
