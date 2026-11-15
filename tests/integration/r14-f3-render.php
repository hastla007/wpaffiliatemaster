<?php
/**
 * Feature f3 (0.15.0, R14-P2): password confirmation before money-relevant admin actions –
 * every protected route through Admin::dispatch() without and with a confirmation window, the
 * confirmation step (page, wrong password, rate limit, second factor, filter wpac_reauth_verify),
 * the kept request (encrypted, one-time, bound to the session, 5 minutes) and its replay.
 * Included after the render files (uses the stubs of r8-render.php and r12-c.php and the session
 * stand-ins of r14-f3.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "R14 f3 render: password confirmation flows\n";
global $wpdb;

$f3r_get  = $_GET;
$f3r_post = $_POST;
$f3r_user = $GLOBALS['test_user'];
$f3r_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] ); // Administrator: every capability.

// Generous limit per client address (all attempts of this file share one address); the limit
// per account is what the rate limit check below tests.
add_filter( 'wpac_rate_limit', static function ( $limit, $bucket ) { return 'reauth_ip' === $bucket ? 1000 : $limit; }, 999, 2 );

$f3r_reset = static function () {
	$p = new ReflectionProperty( Reauth::class, 'confirmed_now' );
	$p->setAccessible( true );
	$p->setValue( null, false );
};
$f3r_login = static function ( $uid, $token, $password = 'Geheim-123' ) use ( $f3r_reset ) {
	$GLOBALS['test_users'][ $uid ] = (object) array( 'ID' => $uid, 'user_email' => 'admin' . $uid . '@example.de', 'display_name' => 'Admin', 'user_login' => 'admin' . $uid, 'user_pass' => md5( $password ), 'roles' => array( 'administrator' ) );
	$GLOBALS['test_user']          = $uid;
	$GLOBALS['f3_token']           = $token;
	if ( null === WP_Session_Tokens::get_instance( $uid )->get( $token ) ) {
		WP_Session_Tokens::get_instance( $uid )->update( $token, array( 'expiration' => time() + DAY_IN_SECONDS ) );
	}
	$f3r_reset();
};
$f3r_dispatch = static function ( $do, array $post ) use ( $f3r_reset ) {
	$f3r_reset();
	$_GET     = array();
	$_POST    = array( 'action' => 'wpac', 'do' => $do, '_wpac_nonce' => 'nonce' ) + $post;
	$_REQUEST = $_POST;
	try {
		Admin::dispatch();
		$r = array( 'no halt', 0 );
	} catch ( R12C_Halt $e ) {
		$r = array( $e->getMessage(), $e->getCode() );
	}
	$f3r_reset();
	return $r;
};
$f3r_confirm = static function ( $token, $password, array $extra = array() ) use ( $f3r_reset ) {
	$f3r_reset();
	$_GET     = array();
	$_POST    = array( 'action' => 'wpac_reauth', '_wpac_nonce' => 'nonce', 'wpac_reauth' => $token, 'wpac_reauth_password' => $password ) + $extra;
	$_REQUEST = $_POST;
	try {
		Reauth::handle();
		$r = array( 'no halt', 0 );
	} catch ( R12C_Halt $e ) {
		$r = array( $e->getMessage(), $e->getCode() );
	}
	$f3r_reset();
	return $r;
};
$f3r_token = static function ( array $r ) {
	return 0 === strpos( $r[0], 'redirect:' ) && false !== strpos( $r[0], 'page=wpac-reauth' ) && preg_match( '/wpac_reauth=([a-f0-9]{32})/', $r[0], $m ) ? $m[1] : '';
};
$f3r_notice = static function () {
	$key = 'wpac_notice_' . get_current_user_id();
	$n   = get_transient( $key );
	delete_transient( $key );
	return is_array( $n ) ? array( $n['type'], $n['message'] ) : array( '', '' );
};
$f3r_audit = static function ( $action, $uid ) use ( $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( 'SELECT details FROM ' . WPAffiliateCaptain\Core\Db::table( 'audit' ) . ' WHERE action = %s AND object_id = %d ORDER BY id', $action, $uid ) );
};

/* Every protected route: without a window to the confirmation step, with a window to its handler */
$f3r_login( 914311, 'tok-routes' );
$f3r_routes = array(
	'bank_approve'       => array( 'bank_approve', array( 'id' => '999999' ) ),
	'user_link_manual'   => array( 'user_link_manual', array( 'id' => '999999', 'user' => 'nobody@example.de' ) ),
	'user_unlink'        => array( 'user_unlink', array( 'id' => '999999' ) ),
	'user_link_resend'   => array( 'user_link_resend', array( 'id' => '999999' ) ),
	'profile_update'     => array( 'profile_update', array( 'id' => '999999', 'iban' => 'DE89370400440532013099' ) ), // Wrong check sum: refused.
	'payout_bulk_sepa'   => array( 'payout_bulk', array( 'bulk_action' => 'sepa' ) ),
	'payout_bulk_csv'    => array( 'payout_bulk', array( 'bulk_action' => 'csv' ) ),
	'payout_bulk_paypal' => array( 'payout_bulk', array( 'bulk_action' => 'paypal' ) ),
	'payout_bulk_paid'   => array( 'payout_bulk', array( 'bulk_action' => 'paid' ) ),
);
$f3r_without = array();
$f3r_with    = array();
$f3r_tokens  = array();
foreach ( $f3r_routes as $f3r_key => $f3r_route ) {
	WP_Session_Tokens::get_instance( 914311 )->update( 'tok-routes', array( 'expiration' => time() + DAY_IN_SECONDS ) );
	$f3r_r                    = $f3r_dispatch( $f3r_route[0], $f3r_route[1] );
	$f3r_tokens[ $f3r_key ]   = $f3r_token( $f3r_r );
	$f3r_without[ $f3r_key ]  = '' !== $f3r_tokens[ $f3r_key ];
	WP_Session_Tokens::get_instance( 914311 )->update( 'tok-routes', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() - 60 ) );
	$f3r_r                    = $f3r_dispatch( $f3r_route[0], $f3r_route[1] );
	$f3r_with[ $f3r_key ]     = 0 === strpos( $f3r_r[0], 'redirect:' ) && false === strpos( $f3r_r[0], 'wpac-reauth' ) && 'error' === $f3r_notice()[0]; // The handler ran (and refused the made-up input).
}
check( 'f3 every protected route without a confirmation window goes to the confirmation step with a kept request', array_fill_keys( array_keys( $f3r_routes ), true ), $f3r_without );
check( 'f3 every protected route with a confirmation window reaches its handler', array_fill_keys( array_keys( $f3r_routes ), true ), $f3r_with );
WP_Session_Tokens::get_instance( 914311 )->update( 'tok-routes', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$f3r_free = $f3r_dispatch( 'payout_bulk', array( 'bulk_action' => 'cancel' ) );
check( 'f3 an action without money flow (cancel) is not asked', array( false, 'error' ), array( false !== strpos( $f3r_free[0], 'wpac-reauth' ), $f3r_notice()[0] ) );

// The kept request: encrypted (no IBAN in plain text), bound to user and session, 5 minutes.
$f3r_kept = get_transient( 'wpac_reauth_' . $f3r_tokens['profile_update'] );
check(
	'f3 kept request: encrypted, bound to user and session hash, expires after 5 minutes, token only in the URL',
	array( true, false, 914311, 64, true, 'profile_update' ),
	array(
		is_array( $f3r_kept ),
		false !== strpos( serialize( $f3r_kept ), 'DE89370400440532013099' ),
		$f3r_kept['user'],
		strlen( $f3r_kept['session'] ),
		$f3r_kept['expires'] > time() + 240 && $f3r_kept['expires'] <= time() + 300,
		$f3r_kept['action'],
	)
);

/* Switching the setting off needs a confirmation too; while it is off nothing is asked ------- */
$f3r_off = $f3r_dispatch( 'settings_save', array( 'tab' => 'security', 'wpac' => array( 'rate_limit_per_minute' => '30', 'trusted_proxies' => '' ) ) );
check( 'f3 switching the confirmation off goes to the confirmation step (setting unchanged)', array( true, 1 ), array( '' !== $f3r_token( $f3r_off ), (int) Settings::get( 'reauth_required' ) ) );
WP_Session_Tokens::get_instance( 914311 )->update( 'tok-routes', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() ) );
$f3r_dispatch( 'settings_save', array( 'tab' => 'security', 'wpac' => array( 'rate_limit_per_minute' => '30', 'trusted_proxies' => '' ) ) );
$f3r_notice();
Settings::flush_cache();
$f3r_off_state = (int) Settings::get( 'reauth_required' );
WP_Session_Tokens::get_instance( 914311 )->update( 'tok-routes', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$f3r_off_route = $f3r_dispatch( 'bank_approve', array( 'id' => '999999' ) );
$f3r_notice();
check( 'f3 with a confirmation the setting is switched off; then the routes are not asked', array( 0, false ), array( $f3r_off_state, false !== strpos( $f3r_off_route[0], 'wpac-reauth' ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'reauth_required' => 1 ) ) );
Settings::flush_cache();

/* Confirmation page ----------------------------------------------------------------------- */
$f3r_login( 914312, 'tok-page' );
$f3r_page_token = $f3r_token( $f3r_dispatch( 'payout_bulk', array( 'bulk_action' => 'sepa', 'ids' => array( '1' ) ) ) );
$_GET           = array( 'page' => Reauth::SLUG, 'wpac_reauth' => $f3r_page_token );
$f3r_page       = r_render( array( Reauth::class, 'render' ) );
check(
	'f3 confirmation page: password field (current-password), own nonce, token in a hidden field, the action named, no expiry warning',
	array( array(), '', true, true, true, true, false ),
	array(
		$f3r_page[1],
		$f3r_page[2],
		false !== strpos( $f3r_page[0], 'type="password" name="wpac_reauth_password" id="wpac-reauth-password" class="regular-text" autocomplete="current-password"' ),
		false !== strpos( $f3r_page[0], 'name="action" value="wpac_reauth"' ) && false !== strpos( $f3r_page[0], 'name="_wpac_nonce"' ),
		false !== strpos( $f3r_page[0], 'name="wpac_reauth" value="' . $f3r_page_token . '"' ),
		false !== strpos( $f3r_page[0], 'Action: Download SEPA XML' ),
		false !== strpos( $f3r_page[0], 'has expired' ),
	)
);

/* Other session token: the kept request is not offered and not carried out ------------------ */
$GLOBALS['f3_token'] = 'tok-page-other';
WP_Session_Tokens::get_instance( 914312 )->update( 'tok-page-other', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$f3r_page = r_render( array( Reauth::class, 'render' ) );
check( 'f3 another login session sees the kept request as expired (no token in the form)', array( true, true ), array( false !== strpos( $f3r_page[0], 'has expired' ), false !== strpos( $f3r_page[0], 'name="wpac_reauth" value=""' ) ) );
$f3r_r = $f3r_confirm( $f3r_page_token, 'Geheim-123' );
check(
	'f3 confirming in another session: confirmed there, but the request of the other session is not carried out and stays',
	array( true, 'warning', true, true, false ),
	array(
		false !== strpos( $f3r_r[0], 'page=wpac' ),
		$f3r_notice()[0],
		Reauth::confirmed(),
		is_array( get_transient( 'wpac_reauth_' . $f3r_page_token ) ),
		! empty( WP_Session_Tokens::get_instance( 914312 )->get( 'tok-page' )[ Reauth::SESSION_KEY ] ),
	)
);
$GLOBALS['f3_token'] = 'tok-page';
check( 'f3 the first session is still not confirmed', false, Reauth::confirmed() );

/* Wrong password, then success with replay: the payout is marked as paid -------------------- */
$f3r_aff = AffiliateRepository::create( array( 'name' => 'Fiete Reauth', 'email' => 'fiete-reauth@example.de', 'status' => 'active' ) );
ProfileRepository::save( $f3r_aff, array( 'tax_status' => 'private', 'legal_name' => 'Fiete Reauth', 'address' => "Kai 3\n24937 Flensburg", 'country' => 'DE', 'account_holder' => 'Fiete Reauth', 'iban' => 'DE89370400440532013000' ) );
$f3r_sale = sale( 914390, WPAffiliateCaptain\Codes\CodeRepository::main_code( $f3r_aff ), array( 500000 ) );
Ledger::transition( (int) $f3r_sale['commission_ids'][0], 'approved' );
$f3r_payout = PayoutService::create_for_affiliate( $f3r_aff, gmdate( 'Y-m-d' ), 'EUR' );
$f3r_login( 914313, 'tok-pay' );
$f3r_tok = $f3r_token( $f3r_dispatch( 'payout_bulk', array( 'bulk_action' => 'paid', 'ids' => array( (string) $f3r_payout ), 'confirm' => '1' ) ) );
check( 'f3 mark as paid without confirmation: kept, nothing paid yet', array( true, 'pending' ), array( '' !== $f3r_tok, PayoutService::find( $f3r_payout )->status ) );
$f3r_r = $f3r_confirm( $f3r_tok, 'falsch' );
check(
	'f3 wrong password: back to the confirmation step with an error, logged, request kept, not confirmed',
	array( true, array( 'error', 'The password is not correct.' ), 1, '{"action":"payout_bulk_paid","reason":"password"}', true, false, 'pending' ),
	array(
		false !== strpos( $f3r_r[0], 'page=wpac-reauth' ) && false !== strpos( $f3r_r[0], $f3r_tok ),
		$f3r_notice(),
		count( $f3r_audit( 'security.reauth_failed', 914313 ) ),
		$f3r_audit( 'security.reauth_failed', 914313 )[0] ?? '',
		is_array( get_transient( 'wpac_reauth_' . $f3r_tok ) ),
		Reauth::confirmed(),
		PayoutService::find( $f3r_payout )->status,
	)
);
$f3r_r = $f3r_confirm( $f3r_tok, 'Geheim-123' );
check(
	'f3 correct password: confirmation logged, kept request carried out once (payout paid), window stored in the session',
	array( 'paid', array( 'success', '1 payout marked as paid.' ), array( '{"action":"payout_bulk_paid"}' ), false, true ),
	array(
		PayoutService::find( $f3r_payout )->status,
		$f3r_notice(),
		$f3r_audit( 'security.reauth_confirmed', 914313 ),
		get_transient( 'wpac_reauth_' . $f3r_tok ),
		Reauth::confirmed(),
	)
);
$f3r_r = $f3r_confirm( $f3r_tok, 'Geheim-123' );
check( 'f3 the kept request runs only once (second submit: only the confirmation, a warning)', array( 'warning', 1 ), array( $f3r_notice()[0], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPAffiliateCaptain\Core\Db::table( 'audit' ) . " WHERE action = 'payout.paid' AND object_id = %d", $f3r_payout ) ) ) );

/* Expired kept request (older than 5 minutes) ------------------------------------------------ */
$f3r_login( 914314, 'tok-exp' );
$f3r_tok  = $f3r_token( $f3r_dispatch( 'user_unlink', array( 'id' => '999999' ) ) );
$f3r_kept = get_transient( 'wpac_reauth_' . $f3r_tok );
$f3r_kept['expires'] = time() - 1;
set_transient( 'wpac_reauth_' . $f3r_tok, $f3r_kept );
$f3r_r = $f3r_confirm( $f3r_tok, 'Geheim-123' );
check( 'f3 expired kept request: password confirmed, the action is not carried out (warning: start it again)', array( true, 'warning' ), array( false !== strpos( $f3r_r[0], 'page=wpac' ) && false === strpos( $f3r_r[0], 'wpac-reauth' ), $f3r_notice()[0] ) );

/* Rate limit per account -------------------------------------------------------------------- */
$f3r_login( 914315, 'tok-rl' );
$f3r_tok = $f3r_token( $f3r_dispatch( 'bank_approve', array( 'id' => '999999' ) ) );
$f3r_msgs = array();
for ( $f3r_i = 0; $f3r_i < Reauth::ATTEMPTS; $f3r_i++ ) {
	$f3r_confirm( $f3r_tok, 'falsch-' . $f3r_i );
	$f3r_msgs[] = $f3r_notice()[1];
}
$f3r_r = $f3r_confirm( $f3r_tok, 'Geheim-123' ); // The right password after five wrong ones.
$f3r_rl = $f3r_audit( 'security.reauth_failed', 914315 );
check(
	'f3 rate limit: after 5 wrong passwords even the right one is refused for 15 minutes (logged)',
	array( array_fill( 0, 5, 'The password is not correct.' ), array( 'error', 'Too many attempts. Please wait 15 minutes and try again.' ), false, true ),
	array( $f3r_msgs, $f3r_notice(), Reauth::confirmed(), false !== strpos( (string) end( $f3r_rl ), 'rate_limited' ) )
);

/* Second factor: Two-Factor plugin and the filter wpac_reauth_verify ------------------------- */
if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class F3R_Totp {
		public function authentication_page( $user ) { echo '<input type="text" name="authcode" id="authcode" autocomplete="one-time-code">'; }
		public function validate_authentication( $user ) { return isset( $_POST['authcode'] ) && '123456' === $_POST['authcode']; }
	}
	class Two_Factor_Core {
		public static function is_user_using_two_factor( $user_id = null ) { return in_array( (int) $user_id, $GLOBALS['f3_2fa_users'] ?? array(), true ); }
		public static function get_primary_provider_for_user( $user = null ) { return new F3R_Totp(); }
	}
}
$GLOBALS['f3_2fa_users'] = array( 914316 );
$f3r_login( 914316, 'tok-2fa' );
$f3r_tok  = $f3r_token( $f3r_dispatch( 'user_link_resend', array( 'id' => '999999' ) ) );
$_GET     = array( 'page' => Reauth::SLUG, 'wpac_reauth' => $f3r_tok );
$f3r_page = r_render( array( Reauth::class, 'render' ) );
$f3r_r1   = $f3r_confirm( $f3r_tok, 'Geheim-123', array( 'authcode' => '000000' ) );
$f3r_n1   = $f3r_notice();
$f3r_c1   = Reauth::confirmed();
$f3r_r2   = $f3r_confirm( $f3r_tok, 'Geheim-123', array( 'authcode' => '123456' ) );
$f3r_n2   = $f3r_notice();
check(
	'f3 Two-Factor plugin: its code field on the page; wrong code refused, right code confirms and runs the action',
	array( true, array( 'error', 'The second factor (e.g. the code of your authenticator app) is not correct.' ), false, false, true, true ),
	array(
		false !== strpos( $f3r_page[0], 'name="authcode"' ),
		$f3r_n1,
		$f3r_c1,
		false !== strpos( $f3r_r2[0], 'wpac-reauth' ),
		'error' === $f3r_n2[0] && 'The second factor (e.g. the code of your authenticator app) is not correct.' !== $f3r_n2[1], // The handler ran (unknown partner).
		Reauth::confirmed(),
	)
);
$GLOBALS['f3_2fa_users'] = array();

$f3r_login( 914317, 'tok-filter' );
add_filter( 'wpac_reauth_verify', static function ( $result, $user ) { return isset( $_POST['otp'] ) && 'ok' === $_POST['otp'] ? $result : new WP_Error( 'otp', 'Wordfence code missing.' ); }, 10, 2 );
$f3r_confirm( '', 'Geheim-123' );
$f3r_n1 = $f3r_notice();
$f3r_c1 = Reauth::confirmed();
$f3r_confirm( '', 'Geheim-123', array( 'otp' => 'ok' ) );
$f3r_n2 = $f3r_notice();
remove_all_filters( 'wpac_reauth_verify' );
add_filter( 'wpac_reauth_verify', static function () { return false; } );
$f3r_login( 914318, 'tok-filter2' );
$f3r_confirm( '', 'Geheim-123' );
$f3r_n3 = $f3r_notice();
remove_all_filters( 'wpac_reauth_verify' );
check(
	'f3 filter wpac_reauth_verify: another 2FA plugin refuses (its message) or accepts; false refuses with a general message',
	array( array( 'error', 'Wordfence code missing.' ), false, array( 'success', 'Password confirmed.' ), array( 'error', 'The second factor could not be confirmed.' ) ),
	array( $f3r_n1, $f3r_c1, $f3r_n2, $f3r_n3 )
);
$GLOBALS['f3_token']  = 'tok-filter';
$GLOBALS['test_user'] = 914317;
check( 'f3 accepted second factor stores the window in the session', true, Reauth::confirmed() );

/* Audit labels and source wiring ----------------------------------------------------------- */
$f3r_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Admin.php' );
check(
	'f3 audit labels; dispatch() asks after the capability check and before the handler',
	array( 'Password confirmed for a money-relevant action', 'Password confirmation failed', true ),
	array(
		WPAffiliateCaptain\Admin\Pages\AuditPage::action_label( 'security.reauth_confirmed' ),
		WPAffiliateCaptain\Admin\Pages\AuditPage::action_label( 'security.reauth_failed' ),
		strpos( $f3r_src, "wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );\n\t\t}\n\t\t// Money-relevant" ) < strpos( $f3r_src, 'Reauth::challenge( $reauth );' ) && strpos( $f3r_src, 'Reauth::challenge( $reauth );' ) < strpos( $f3r_src, "call_user_func( array( \$class, 'handle' ), \$do );" ),
	)
);

unset( $GLOBALS['test_hooks']['wpac_rate_limit'][999] );
$f3r_reset();
$GLOBALS['f3_sessions'] = array();
$GLOBALS['f3_token']    = '';
$_GET                   = $f3r_get;
$_POST                  = $f3r_post;
$_REQUEST               = array();
$GLOBALS['test_user']   = $f3r_user;
if ( null === $f3r_caps ) {
	unset( $GLOBALS['test_caps'] );
} else {
	$GLOBALS['test_caps'] = $f3r_caps;
}
