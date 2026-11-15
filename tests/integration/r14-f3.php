<?php
/**
 * Feature f3 (0.15.0, R14-P2): password confirmation before money-relevant admin actions –
 * action keys, the setting and the filters, the confirmation window in the login session
 * (expiry, other session token). The flows through Admin::dispatch() and the confirmation step
 * are in r14-f3-render.php (they need the wp_die()/wp_safe_redirect() stubs of r12-c.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Core\Settings;

echo "R14 f3: password confirmation (rules, session window)\n";

// Stand-ins of the WordPress session API (WP_User_Meta_Session_Tokens: sessions keyed by the
// hashed token) and of the password check.
if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	class WP_Session_Tokens {
		private $uid = 0;
		public static function get_instance( $uid ) { $i = new self(); $i->uid = (int) $uid; return $i; }
		public function get( $token ) { return $GLOBALS['f3_sessions'][ $this->uid ][ hash( 'sha256', (string) $token ) ] ?? null; }
		public function update( $token, $session ) { $GLOBALS['f3_sessions'][ $this->uid ][ hash( 'sha256', (string) $token ) ] = $session; }
	}
}
if ( ! function_exists( 'wp_get_session_token' ) ) {
	function wp_get_session_token() { return (string) ( $GLOBALS['f3_token'] ?? '' ); }
}
if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( $password, $hash, $user_id = '' ) { return hash_equals( (string) $hash, md5( (string) $password ) ); }
}
$GLOBALS['f3_sessions'] = array();
$GLOBALS['f3_token']    = '';

$f3_post = $_POST;
$f3_user = $GLOBALS['test_user'];

/* Action keys ------------------------------------------------------------------------------ */
$f3_key = static function ( $do, array $post ) {
	$_POST = $post;
	return Reauth::action_key( $do );
};
check(
	'f3 action keys: payout bulk actions per action, switching the confirmation off is its own key, other actions their name',
	array( 'payout_bulk_sepa', 'payout_bulk_paid', 'payout_bulk_cancel', 'settings_reauth_off', 'settings_save', 'settings_save', 'bank_approve' ),
	array(
		$f3_key( 'payout_bulk', array( 'bulk_action' => 'sepa' ) ),
		$f3_key( 'payout_bulk', array( 'bulk_action' => 'paid' ) ),
		$f3_key( 'payout_bulk', array( 'bulk_action' => 'cancel' ) ),
		$f3_key( 'settings_save', array( 'tab' => 'security', 'wpac' => array( 'rate_limit_per_minute' => '30' ) ) ),
		$f3_key( 'settings_save', array( 'tab' => 'security', 'wpac' => array( 'reauth_required' => '1' ) ) ),
		$f3_key( 'settings_save', array( 'tab' => 'emails', 'wpac' => array() ) ), // Other tab: the checkbox is not on it.
		$f3_key( 'bank_approve', array( 'id' => '5' ) ),
	)
);

/* Setting and filter ----------------------------------------------------------------------- */
check( 'f3 setting on by default (also after an update: defaults are merged)', 1, (int) Settings::get( 'reauth_required' ) );
$f3_req = array();
foreach ( array( 'bank_approve', 'user_link_manual', 'user_unlink', 'user_link_resend', 'profile_update', 'payout_bulk_sepa', 'payout_bulk_csv', 'payout_bulk_paypal', 'payout_bulk_paid', 'settings_reauth_off', 'payout_bulk_cancel', 'tax_approve', 'settings_save' ) as $f3_k ) {
	$f3_req[ $f3_k ] = Reauth::required( $f3_k );
}
check(
	'f3 money-relevant actions need the confirmation, others not',
	array( 'bank_approve' => true, 'user_link_manual' => true, 'user_unlink' => true, 'user_link_resend' => true, 'profile_update' => true, 'payout_bulk_sepa' => true, 'payout_bulk_csv' => true, 'payout_bulk_paypal' => true, 'payout_bulk_paid' => true, 'settings_reauth_off' => true, 'payout_bulk_cancel' => false, 'tax_approve' => false, 'settings_save' => false ),
	$f3_req
);
check( 'f3 settings sanitize keeps the flag as 0/1', array( 0, 1 ), array( Settings::sanitize( array() )['reauth_required'], Settings::sanitize( array( 'reauth_required' => 'on' ) )['reauth_required'] ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'reauth_required' => 0 ) ) );
Settings::flush_cache();
check( 'f3 setting off: no confirmation needed (and nothing to switch off)', array( false, false, 'settings_save' ), array( Reauth::required( 'bank_approve' ), Reauth::required( 'payout_bulk_sepa' ), $f3_key( 'settings_save', array( 'tab' => 'security', 'wpac' => array() ) ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'reauth_required' => 1 ) ) );
Settings::flush_cache();
add_filter(
	'wpac_reauth_required',
	static function ( $required, $key ) {
		if ( 'tax_approve' === $key ) {
			return true;
		}
		return 'payout_bulk_csv' === $key ? false : $required;
	},
	10,
	2
);
check( 'f3 filter wpac_reauth_required: add-ons protect further actions or exempt one', array( true, false, true ), array( Reauth::required( 'tax_approve' ), Reauth::required( 'payout_bulk_csv' ), Reauth::required( 'payout_bulk_sepa' ) ) );
remove_all_filters( 'wpac_reauth_required' );

/* Confirmation window in the login session -------------------------------------------------- */
$GLOBALS['test_user']   = 914301;
$GLOBALS['f3_token']    = 'session-a';
$f3_mgr                 = WP_Session_Tokens::get_instance( 914301 );
$f3_mgr->update( 'session-a', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$f3_mgr->update( 'session-b', array( 'expiration' => time() + DAY_IN_SECONDS ) );
$f3_c = array( Reauth::confirmed() );
$f3_mgr->update( 'session-a', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() - 60 ) );
$f3_c[] = Reauth::confirmed();
$GLOBALS['f3_token'] = 'session-b';
$f3_c[] = Reauth::confirmed(); // Other login session (other browser): not confirmed.
$GLOBALS['f3_token'] = '';
$f3_c[] = Reauth::confirmed(); // No session token at all.
$GLOBALS['f3_token'] = 'session-a';
$f3_mgr->update( 'session-a', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() - 15 * MINUTE_IN_SECONDS - 1 ) );
$f3_c[] = Reauth::confirmed(); // Expired after 15 minutes.
check( 'f3 window: none before, valid in the same session, not in another session or without a token, expired after 15 minutes', array( false, true, false, false, false ), $f3_c );
add_filter( 'wpac_reauth_ttl', static function () { return HOUR_IN_SECONDS; } );
$f3_long = array( Reauth::ttl(), Reauth::confirmed() );
remove_all_filters( 'wpac_reauth_ttl' );
add_filter( 'wpac_reauth_ttl', static function () { return 0; } );
$f3_mgr->update( 'session-a', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() ) );
$f3_zero = array( Reauth::ttl(), Reauth::confirmed() );
remove_all_filters( 'wpac_reauth_ttl' );
check( 'f3 filter wpac_reauth_ttl: longer window (1 hour) and 0 = every action asks', array( 3600, true, 0, false ), array_merge( $f3_long, $f3_zero ) );
$f3_mgr->update( 'session-a', array( 'expiration' => time() + DAY_IN_SECONDS, Reauth::SESSION_KEY => time() + 600 ) );
check( 'f3 a confirmation time in the future is not trusted', false, Reauth::confirmed() );

$GLOBALS['f3_sessions'] = array();
$GLOBALS['f3_token']    = '';
$GLOBALS['test_user']   = $f3_user;
$_POST                  = $f3_post;
