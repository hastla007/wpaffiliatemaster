<?php
/**
 * Render checks of audit panel round 13, part C: the payout method fields carry no "required"
 * without JavaScript (M5), error notices without a field get the focus marker (N7) and the
 * mobile column labels are aria-hidden spans (N7). Included from tests/integration/run.php after
 * the render files (uses the template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Portal\Portal;

echo "R13 C render: payout method without JavaScript, notices, mobile labels\n";

global $wpdb;
$r13cr_keep_user = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$r13cr_keep_get  = $_GET;
$r13cr_uid       = 913001;
$r13cr_aff       = AffiliateRepository::create( array( 'name' => 'R13C Render', 'email' => 'r13c-render@example.de', 'status' => 'active', 'user_id' => $r13cr_uid ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => $r13cr_uid ), array( 'id' => (int) $r13cr_aff ) );
$GLOBALS['test_users'][ $r13cr_uid ] = (object) array( 'ID' => $r13cr_uid, 'user_email' => 'r13c-render@example.de', 'display_name' => 'R13C', 'user_login' => 'r13c', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']                = $r13cr_uid;

$_GET  = array( 'wpac_tab' => 'profile' );
$r13cr = r_render( array( Portal::class, 'render' ) );
$html  = $r13cr[0];
preg_match( '/<input[^>]*id="wpac_account_holder"[^>]*>/', $html, $r13cr_holder );
preg_match( '/<input[^>]*id="wpac_iban"[^>]*>/', $html, $r13cr_iban );
preg_match( '/<textarea[^>]*id="wpac_payment_details"[^>]*>/', $html, $r13cr_details );
check(
	'R13-M5 partner without payout profile: account holder, IBAN and payment details are not "required" in the HTML (JS adds it for the chosen method)',
	array( true, false, false, false, true, array(), '' ),
	array(
		isset( $r13cr_holder[0], $r13cr_iban[0], $r13cr_details[0] ),
		isset( $r13cr_holder[0] ) && (bool) preg_match( '/\srequired[\s>]/', $r13cr_holder[0] ),
		isset( $r13cr_iban[0] ) && (bool) preg_match( '/\srequired[\s>]/', $r13cr_iban[0] ),
		isset( $r13cr_details[0] ) && (bool) preg_match( '/\srequired[\s>]/', $r13cr_details[0] ),
		false !== strpos( $html, '<label for="wpac_account_holder">Account holder *</label>' ),
		$r13cr[1],
		$r13cr[2],
	)
);

$r13cr_focus = array();
foreach ( array( 'maintenance', 'request_limit', 'link_failed', 'wpac_profile_db', 'saved' ) as $r13cr_msg ) {
	$_GET          = array( 'wpac_tab' => 'profile', 'wpac_msg' => $r13cr_msg );
	$r13cr         = r_render( array( Portal::class, 'render' ) );
	$r13cr_focus[] = (bool) preg_match( '/<div class="wpac-notice[^"]*" role="(alert|status)" id="wpac-portal-error" tabindex="-1" data-wpac-focus>/', $r13cr[0] );
}
check( 'R13-N7 error notices without a field of their own get the focus marker too (announced on load)', array( true, true, true, true, true ), $r13cr_focus );

$_GET  = array( 'wpac_tab' => 'payouts' );
$r13cr = r_render( array( Portal::class, 'render' ) );
check( 'R13-N7 portal tables: no rendering errors with the label spans', array( array(), '' ), array( $r13cr[1], $r13cr[2] ) );

$_GET                 = $r13cr_keep_get;
$GLOBALS['test_user'] = $r13cr_keep_user;
