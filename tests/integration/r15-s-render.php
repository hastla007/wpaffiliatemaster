<?php
/**
 * Round 15, fix agent s: admin flows through Admin::dispatch() and the confirmation step –
 * money-creating actions ask for the password (R15-S3), only failed confirmations use up the rate
 * limit (R15-S4), code_status refuses a requested code (R15-S6). Included after the render files
 * (uses the stubs of r8-render.php and r12-c.php and the session stand-ins of r14-f3.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Core\Settings;

echo "R15 s render: confirmation of money-creating actions, rate limit, code_status\n";
global $wpdb;

$sr_get      = $_GET;
$sr_post     = $_POST;
$sr_user     = $GLOBALS['test_user'];
$sr_caps     = $GLOBALS['test_caps'] ?? null;
$sr_settings = get_option( 'wpac_settings' );
unset( $GLOBALS['test_caps'] ); // Administrator: every capability.
update_option( 'wpac_settings', array_merge( (array) $sr_settings, array( 'reauth_required' => 1, 'code_requests' => 1 ) ) );
Settings::flush_cache();
add_filter( 'wpac_rate_limit', static function ( $limit, $bucket ) { return 'reauth_ip' === $bucket ? 1000 : $limit; }, 998, 2 );

$sr_reset = static function () {
	$p = new ReflectionProperty( Reauth::class, 'confirmed_now' );
	$p->setAccessible( true );
	$p->setValue( null, false );
};
$sr_login = static function ( $uid, $token, array $session = array() ) use ( $sr_reset ) {
	$GLOBALS['test_users'][ $uid ] = (object) array( 'ID' => $uid, 'user_email' => 'admin' . $uid . '@example.de', 'display_name' => 'Admin', 'user_login' => 'admin' . $uid, 'user_pass' => md5( 'Geheim-123' ), 'roles' => array( 'administrator' ) );
	$GLOBALS['test_user']          = $uid;
	$GLOBALS['f3_token']           = $token;
	WP_Session_Tokens::get_instance( $uid )->update( $token, array( 'expiration' => time() + DAY_IN_SECONDS ) + $session );
	$sr_reset();
};
$sr_dispatch = static function ( $do, array $post ) use ( $sr_reset ) {
	$sr_reset();
	$_GET     = array();
	$_POST    = array( 'action' => 'wpac', 'do' => $do, '_wpac_nonce' => 'nonce' ) + $post;
	$_REQUEST = $_POST;
	try {
		Admin::dispatch();
		$r = array( 'no halt', 0 );
	} catch ( R12C_Halt $e ) {
		$r = array( $e->getMessage(), $e->getCode() );
	}
	$sr_reset();
	return $r;
};
$sr_confirm = static function ( $token, $password ) use ( $sr_reset ) {
	$sr_reset();
	$_GET     = array();
	$_POST    = array( 'action' => 'wpac_reauth', '_wpac_nonce' => 'nonce', 'wpac_reauth' => $token, 'wpac_reauth_password' => $password );
	$_REQUEST = $_POST;
	try {
		Reauth::handle();
	} catch ( R12C_Halt $e ) {
		unset( $e );
	}
	$sr_reset();
};
$sr_asked = static function ( array $r ) {
	return 0 === strpos( $r[0], 'redirect:' ) && false !== strpos( $r[0], 'page=wpac-reauth' );
};
$sr_notice = static function () {
	$key = 'wpac_notice_' . get_current_user_id();
	$n   = get_transient( $key );
	delete_transient( $key );
	return is_array( $n ) ? array( $n['type'], $n['message'] ) : array( '', '' );
};

/* R15-S3: money-creating routes ask without a confirmation window, reach the handler with one -- */
$sr_aff = AffiliateRepository::create( array( 'name' => 'Sven Render', 'email' => 'sven.render.s15@example.de', 'status' => 'active' ) );
$sr_login( 915101, 'tok-s3' );
$sr_routes = array(
	'commission_manual'          => array( 'commission_manual', array( 'affiliate_id' => '999999', 'amount' => '9999', 'currency' => 'EUR', 'reason' => 'x' ) ),
	'commission_status_approved' => array( 'commission_status', array( 'to' => 'approved', 'id' => '99999999' ) ),
	'payout_run'                 => array( 'payout_run', array( 'period_end' => 'kein-datum', 'currency' => 'EUR' ) ),
	'payout_request_process'     => array( 'payout_request_process', array( 'id' => '99999999' ) ),
	'balance_writeoff'           => array( 'balance_writeoff', array( 'id' => '999999', 'currency' => 'EUR', 'reason' => 'x' ) ),
	'repayment'                  => array( 'repayment', array( 'id' => '999999', 'currency' => 'EUR', 'amount' => 'abc' ) ),
	'affiliate_email_change'     => array( 'affiliate_update', array( 'id' => (string) $sr_aff, 'name' => 'Sven Render', 'email' => 'sven.neu.s15@example.de' ) ),
);
$sr_without = array();
$sr_with    = array();
foreach ( $sr_routes as $sr_key => $sr_route ) {
	WP_Session_Tokens::get_instance( 915101 )->update( 'tok-s3', array( 'expiration' => time() + DAY_IN_SECONDS ) );
	$sr_r                  = $sr_dispatch( $sr_route[0], $sr_route[1] );
	$sr_kept               = preg_match( '/wpac_reauth=([a-f0-9]{32})/', $sr_r[0], $sr_m ) ? get_transient( 'wpac_reauth_' . $sr_m[1] ) : null;
	$sr_without[ $sr_key ] = $sr_asked( $sr_r ) && is_array( $sr_kept ) && $sr_key === $sr_kept['action'];
	WP_Session_Tokens::get_instance( 915101 )->update( 'tok-s3', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() - 60 ) );
	$sr_r                  = $sr_dispatch( $sr_route[0], $sr_route[1] );
	$sr_with[ $sr_key ]    = 0 === strpos( $sr_r[0], 'redirect:' ) && ! $sr_asked( $sr_r );
	$sr_notice();
}
check( 'R15-S3 money-creating routes and the e-mail change go to the confirmation step (kept request with its key)', array_fill_keys( array_keys( $sr_routes ), true ), $sr_without );
check( 'R15-S3 with a confirmation window they reach their handler', array_fill_keys( array_keys( $sr_routes ), true ), $sr_with );
AffiliateRepository::clear_cache( $sr_aff );
check( 'R15-S3 the e-mail address was changed only by the confirmed request', 'sven.neu.s15@example.de', (string) AffiliateRepository::find( $sr_aff )->email );
WP_Session_Tokens::get_instance( 915101 )->update( 'tok-s3', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$sr_free = array(
	$sr_asked( $sr_dispatch( 'commission_status', array( 'to' => 'rejected', 'id' => '99999999', 'reason' => 'x' ) ) ),
	$sr_asked( $sr_dispatch( 'affiliate_update', array( 'id' => (string) $sr_aff, 'name' => 'Sven R.', 'email' => 'SVEN.NEU.S15@example.de' ) ) ),
	$sr_asked( $sr_dispatch( 'payout_request_reject', array( 'id' => '99999999', 'reason' => 'x' ) ) ),
);
$sr_notice();
check( 'R15-S3 rejecting a commission, saving a partner without e-mail change and rejecting a payout request are not asked', array( false, false, false ), $sr_free );

/* R15-S4: only failed confirmations count -------------------------------------------------- */
$sr_zero = static function () { return 0; };
add_filter( 'wpac_reauth_ttl', $sr_zero ); // "Every action asks".
$sr_login( 915102, 'tok-s4' );
$sr_ok = array();
for ( $sr_i = 0; $sr_i < Reauth::ATTEMPTS + 3; $sr_i++ ) {
	$sr_confirm( '', 'Geheim-123' );
	$sr_ok[] = $sr_notice()[0];
}
check( 'R15-S4 with "every action asks" more confirmations than the limit in a row all succeed', array_fill( 0, Reauth::ATTEMPTS + 3, 'success' ), $sr_ok );
$sr_msgs = array();
for ( $sr_i = 0; $sr_i < Reauth::ATTEMPTS - 1; $sr_i++ ) {
	$sr_confirm( '', 'falsch-' . $sr_i );
	$sr_msgs[] = $sr_notice()[1];
}
$sr_confirm( '', 'Geheim-123' ); // A success between the failures does not count …
$sr_msgs[] = $sr_notice()[0];
$sr_confirm( '', 'falsch-x' );   // … so this is the fifth failure, still checked …
$sr_msgs[] = $sr_notice()[1];
$sr_confirm( '', 'Geheim-123' ); // … and only then the limit is reached.
$sr_msgs[] = $sr_notice()[1];
check(
	'R15-S4 four failures, a success, a fifth failure (still checked), then the limit',
	array_merge( array_fill( 0, Reauth::ATTEMPTS - 1, 'The password is not correct.' ), array( 'success', 'The password is not correct.', 'Too many attempts. Please wait 15 minutes and try again.' ) ),
	$sr_msgs
);
remove_filter( 'wpac_reauth_ttl', $sr_zero );

/* R15-S6: code_status refuses a requested code --------------------------------------------- */
$sr_login( 915103, 'tok-s6', array( Reauth::SESSION_KEY => time() ) );
$sr_req = CodeRequests::request( AffiliateRepository::find( $sr_aff ), 'SVENNEU' );
$sr_r   = $sr_dispatch( 'code_status', array( 'id' => (string) $sr_aff, 'code_id' => (string) $sr_req, 'status' => 'active' ) );
check(
	'R15-S6 code_status with a requested code: error notice, the request stays open',
	array( 'error', true, 'requested', 1 ),
	array( $sr_notice()[0], 0 === strpos( $sr_r[0], 'redirect:' ), CodeRepository::find( (int) $sr_req )->status, CodeRequests::open_count() >= 1 ? 1 : 0 )
);
CodeRequests::withdraw( AffiliateRepository::find( $sr_aff ) );

unset( $GLOBALS['test_hooks']['wpac_rate_limit'][998] );
update_option( 'wpac_settings', $sr_settings );
Settings::flush_cache();
$sr_reset();
$GLOBALS['f3_token']  = '';
$_GET                 = $sr_get;
$_POST                = $sr_post;
$_REQUEST             = array();
$GLOBALS['test_user'] = $sr_user;
if ( null !== $sr_caps ) {
	$GLOBALS['test_caps'] = $sr_caps;
}
