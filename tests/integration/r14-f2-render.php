<?php
/**
 * Render checks of feature f2 (0.15.0, partner code requests): the box in the partner area tab
 * "Links & codes" (form, open request, rejection reason, limit) and the list of open requests
 * in the admin. Included from tests/integration/run.php after the render files (template stubs
 * of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;

echo "R14 f2 render: code requests in the partner area and the admin\n";

global $wpdb;
$f2r_keep_user = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$f2r_keep_get  = $_GET;
$f2r_uid       = 914402;
$f2r_aff       = AffiliateRepository::create( array( 'name' => 'F2 Render & Co', 'email' => 'f2-render@example.de', 'status' => 'active' ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => $f2r_uid ), array( 'id' => (int) $f2r_aff ) );
AffiliateRepository::clear_cache( (int) $f2r_aff );
$GLOBALS['test_users'][ $f2r_uid ] = (object) array( 'ID' => $f2r_uid, 'user_email' => 'f2-render@example.de', 'display_name' => 'F2', 'user_login' => 'f2-render', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']              = $f2r_uid;
$_GET                              = array( 'wpac_tab' => 'links' );

$f2r = r_render( array( Portal::class, 'render' ) );
check(
	'F2 links tab: "Request your own code" form with label, help text, pattern and length, nonce, no errors',
	array( true, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $f2r[0], '<h3 id="wpac-code-request-title">Request your own code</h3>' ),
		false !== strpos( $f2r[0], '<label for="wpac_code_wish">Desired code</label>' ),
		false !== strpos( $f2r[0], 'maxlength="20"' ) && false !== strpos( $f2r[0], 'aria-describedby="wpac_code_wish_help"' ),
		false !== strpos( $f2r[0], 'name="wpac_portal_action" value="code_request"' ),
		false !== strpos( $f2r[0], 'name="_wpac_nonce"' ),
		$f2r[1],
		$f2r[2],
	)
);

$f2r_req = CodeRequests::request( AffiliateRepository::find( $f2r_aff ), 'F2RENDER1' );
$f2r     = r_render( array( Portal::class, 'render' ) );
check(
	'F2 links tab with an open request: status and withdraw button instead of the form; the code is not in the code table',
	array( true, true, false, 1, array(), '' ),
	array(
		false !== strpos( $f2r[0], 'Your request for the code F2RENDER1' ),
		false !== strpos( $f2r[0], 'value="code_request_withdraw"' ),
		false !== strpos( $f2r[0], 'name="wpac_code_wish"' ),
		substr_count( $f2r[0], 'F2RENDER1' ),
		$f2r[1],
		$f2r[2],
	)
);

// Admin: list of open requests (escaped partner name, approve and reject forms with nonces).
$f2r_m = new ReflectionMethod( WPAffiliateCaptain\Admin\Pages\AffiliatesPage::class, 'render_code_requests' );
$f2r_m->setAccessible( true );
$f2r_a = r_render(
	static function () use ( $f2r_m ) {
		$f2r_m->invoke( null, 0 );
	}
);
check(
	'F2 admin: open requests listed with escaped name, approve (main code option) and reject (required reason) forms',
	array( true, true, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $f2r_a[0], 'id="wpac-code-requests"' ),
		false !== strpos( $f2r_a[0], 'F2 Render &amp; Co' ),
		false !== strpos( $f2r_a[0], '<code>F2RENDER1</code>' ),
		false !== strpos( $f2r_a[0], 'name="make_main" value="1"' ),
		(bool) preg_match( '/name="reason" id="wpac-cr-reason-\d+" required/', $f2r_a[0] ),
		substr_count( $f2r_a[0], 'name="do" value="code_request"' ) >= 2,
		$f2r_a[1],
		$f2r_a[2],
	)
);

CodeRequests::reject( (int) $f2r_req, 'Zu ähnlich & "kurz"' );
$f2r = r_render( array( Portal::class, 'render' ) );
check(
	'F2 links tab after a rejection: escaped reason shown, the form is offered again',
	array( true, true, array(), '' ),
	array(
		false !== strpos( $f2r[0], 'Reason: Zu ähnlich &amp; &quot;kurz&quot;' ),
		false !== strpos( $f2r[0], 'name="wpac_code_wish"' ),
		$f2r[1],
		$f2r[2],
	)
);

update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'code_requests' => 0 ) ) );
Settings::flush_cache();
$f2r = r_render( array( Portal::class, 'render' ) );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'code_requests' => 1 ) ) );
Settings::flush_cache();
check( 'F2 links tab: no request box when switched off', false, false !== strpos( $f2r[0], 'wpac-code-request' ) );

$_GET                 = $f2r_keep_get;
$GLOBALS['test_user'] = $f2r_keep_user;
