<?php
/**
 * Password confirmation before admin actions that move money or expose bank details
 * (R14-P2, since plugin 0.15.0).
 *
 * Approving held bank details, linking or unlinking a partner's WordPress account, changing a
 * partner's payout details or e-mail address, downloading payout files with IBANs, marking payouts
 * as paid and the actions that create money (manual bookings, approving commissions, creating
 * payouts, write-offs, repayments; since plugin 0.16.0) need a recent password confirmation of the
 * admin. A stolen session cookie alone is then not enough.
 *
 * - The confirmation is kept in the WordPress login session (WP_Session_Tokens, bound to
 *   wp_get_session_token()): another browser or a new login needs its own confirmation, a logout
 *   ends it. Default 15 minutes (filter wpac_reauth_ttl).
 * - With the Two-Factor plugin, its second factor is required too; other 2FA plugins can plug
 *   into the filter wpac_reauth_verify (and print their fields with the action wpac_reauth_form).
 * - The original form is kept server side (encrypted, one-time, 5 minutes, bound to user and
 *   session) under a random token and carried out after the confirmation – nothing of it is put
 *   into a URL.
 * - Only the admin screens (admin-post.php) are affected; WP-CLI and the REST API are not.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Crypto;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Reauth {

	/** Admin page of the confirmation step (not in the menu). */
	const SLUG = 'wpac-reauth';

	/** Key in the login session: Unix time of the last confirmation. */
	const SESSION_KEY = 'wpac_reauth_at';

	/** Seconds a kept form waits for the confirmation. */
	const PAYLOAD_TTL = 300;

	/** Largest kept form (bytes of JSON). */
	const PAYLOAD_MAX = 65536;

	/** Attempts per user within ATTEMPT_WINDOW (filter wpac_rate_limit, bucket "reauth"). */
	const ATTEMPTS = 5;

	/** Attempts per client address within ATTEMPT_WINDOW (bucket "reauth_ip"). */
	const ATTEMPTS_IP = 20;

	/** Seconds of the attempt window. */
	const ATTEMPT_WINDOW = 900;

	/** Actions (see action_key()) that need a confirmation by default. */
	const ACTIONS = array( 'bank_approve', 'user_link_manual', 'user_unlink', 'user_link_resend', 'profile_update', 'payout_bulk_sepa', 'payout_bulk_csv', 'payout_bulk_paypal', 'payout_bulk_paid', 'settings_reauth_off', 'commission_manual', 'commission_status_approved', 'payout_run', 'payout_request_process', 'balance_writeoff', 'repayment', 'affiliate_email_change', 'settings_import_apply', 'settings_export_private', 'settings_reset_money', 'roles_save' );

	/** @var bool Confirmed in this request (also without a stored session, e.g. custom logins). */
	private static $confirmed_now = false;

	/**
	 * Hooks (admin only).
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_wpac_reauth', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Register the confirmation page without a menu entry (parent options.php). Every logged-in
	 * user may open it: it only confirms the own password; the actions keep their capabilities.
	 */
	public static function menu() {
		add_submenu_page( 'options.php', __( 'Confirm your password', 'affiliate-captain' ), __( 'Confirm your password', 'affiliate-captain' ), 'read', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Key of an admin-post action for the confirmation rules: payout_bulk per bulk action
	 * (payout_bulk_sepa, …), settings_save that switches the confirmation off becomes
	 * settings_reauth_off, commission_status that approves commissions becomes
	 * commission_status_approved, affiliate_update that changes the partner's e-mail address
	 * becomes affiliate_email_change (since plugin 0.16.0), all others their "do" value.
	 *
	 * @param string $do Action.
	 * @return string
	 */
	public static function action_key( $do ) {
		$do = (string) $do;
		if ( 'payout_bulk' === $do ) {
			return 'payout_bulk_' . sanitize_key( (string) Admin::post( 'bulk_action' ) );
		}
		if ( 'commission_status' === $do && 'approved' === sanitize_key( (string) Admin::post( 'to' ) ) ) {
			return 'commission_status_approved'; // Approving makes commissions payable (R15-S3).
		}
		if ( 'affiliate_update' === $do ) {
			// The address decides which account can be linked and where security alerts go (R14-S1,
			// R15-S3); the same comparison as the handler.
			$email = sanitize_email( (string) Admin::post( 'email' ) );
			$aff   = '' !== $email ? \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( absint( Admin::post( 'id', 0 ) ) ) : null;
			if ( $aff && strtolower( $email ) !== strtolower( (string) $aff->email ) ) {
				return 'affiliate_email_change';
			}
		}
		if ( 'settings_export' === $do && ! empty( Admin::post( 'with_private' ) ) ) {
			return 'settings_export_private'; // Bank details and addresses leave the site (SET-AC-08).
		}
		if ( 'settings_reset_apply' === $do ) {
			$pending = get_transient( 'wpac_settings_pending_' . get_current_user_id() );
			if ( is_array( $pending ) && isset( $pending['tab'] ) && in_array( $pending['tab'], array( 'payouts', 'commissions', '' ), true ) ) {
				return 'settings_reset_money'; // Money tabs (SET-AC-09).
			}
		}
		if ( 'settings_save' === $do && (int) Settings::get( 'reauth_required' ) && 'security' === Pages\SettingsPage::tab_alias( sanitize_key( (string) Admin::post( 'tab' ) ) ) ) {
			$raw = Admin::post( 'wpac', array() );
			if ( ! is_array( $raw ) || empty( $raw['reauth_required'] ) ) {
				return 'settings_reauth_off';
			}
		}
		return $do;
	}

	/**
	 * Whether an action needs a recent password confirmation.
	 *
	 * @param string $key Action key (action_key()).
	 * @return bool
	 */
	public static function required( $key ) {
		$key     = (string) $key;
		$default = in_array( $key, self::ACTIONS, true ) && ( 'settings_reauth_off' === $key || (int) Settings::get( 'reauth_required' ) );
		/**
		 * Whether an admin action needs a recent password confirmation. Runs for every admin
		 * action of the plugin, so add-ons can protect further actions or exempt one.
		 *
		 * @since 0.15.0
		 * @param bool   $required Default: true for the money-relevant actions while the setting is on.
		 * @param string $key      Action key, e.g. bank_approve, payout_bulk_sepa, profile_update.
		 */
		return (bool) apply_filters( 'wpac_reauth_required', $default, $key );
	}

	/**
	 * Seconds a confirmation stays valid.
	 *
	 * @return int
	 */
	public static function ttl() {
		/**
		 * Seconds a password confirmation stays valid in the login session (0 = every action asks).
		 *
		 * @since 0.15.0
		 * @param int $ttl     Default 900 (15 minutes).
		 * @param int $user_id User.
		 */
		return max( 0, (int) apply_filters( 'wpac_reauth_ttl', (int) \WPAffiliateCaptain\Core\Settings::get( 'reauth_minutes' ) * MINUTE_IN_SECONDS, get_current_user_id() ) );
	}

	/**
	 * Whether the current user confirmed the password recently in this login session.
	 *
	 * @return bool
	 */
	public static function confirmed() {
		if ( self::$confirmed_now ) {
			return true;
		}
		$session = self::session();
		if ( ! $session || empty( $session[ self::SESSION_KEY ] ) ) {
			return false;
		}
		$at = (int) $session[ self::SESSION_KEY ];
		return $at <= time() && $at + self::ttl() > time();
	}

	/**
	 * Keep the current request and send the admin to the confirmation step (exits).
	 *
	 * @param string $key Action key.
	 */
	public static function challenge( $key ) {
		$token = self::store( (string) $key );
		wp_safe_redirect( Admin::url( self::SLUG, '' !== $token ? array( 'wpac_reauth' => $token ) : array() ) );
		exit;
	}

	/**
	 * admin-post "wpac_reauth": check the password (and the second factor), then carry out the
	 * kept request.
	 */
	public static function handle() {
		$nonce = isset( $_POST['_wpac_nonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpac_nonce'] ) ) : '';
		$uid   = (int) get_current_user_id();
		if ( ! $uid || ! wp_verify_nonce( $nonce, 'wpac_reauth' ) ) {
			wp_die( esc_html__( 'The link has expired. Please go back and try again.', 'affiliate-captain' ), 403 );
		}
		$token   = sanitize_key( (string) Admin::post( 'wpac_reauth' ) );
		$pending = self::payload( $token, false );
		$key     = $pending ? (string) $pending['action'] : '';
		$back    = Admin::url( self::SLUG, '' !== $token ? array( 'wpac_reauth' => $token ) : array() );
		// Counted before the check (parallel guesses cannot slip through), taken back after a
		// successful confirmation: only failed attempts use up the limit per account and per client
		// address (R15-S4), so admins who confirm often are never locked out by their own success.
		if ( ! RateLimiter::allow_for( 'reauth', $uid, self::ATTEMPTS, self::ATTEMPT_WINDOW ) || ! RateLimiter::allow( 'reauth_ip', self::ATTEMPTS_IP, self::ATTEMPT_WINDOW ) ) {
			self::failed( $uid, $key, 'rate_limited' );
			Admin::back( __( 'Too many attempts. Please wait 15 minutes and try again.', 'affiliate-captain' ), 'error', $back );
		}
		$user     = get_userdata( $uid );
		$password = Admin::post( 'wpac_reauth_password' ); // Compared as typed (unslashed), never stored or logged.
		if ( ! $user || ! is_string( $password ) || '' === $password || ! wp_check_password( $password, (string) $user->user_pass, $uid ) ) {
			self::failed( $uid, $key, 'password' );
			Admin::back( __( 'The password is not correct.', 'affiliate-captain' ), 'error', $back );
		}
		unset( $password );
		$second = self::second_factor( $user );
		if ( true !== $second ) {
			self::failed( $uid, $key, 'second_factor' );
			Admin::back( $second->get_error_message(), 'error', $back );
		}
		RateLimiter::refund_for( 'reauth', $uid, self::ATTEMPT_WINDOW );
		RateLimiter::refund( 'reauth_ip', self::ATTEMPT_WINDOW );
		self::grant();
		Audit::log( 'security.reauth_confirmed', 'user', $uid, array( 'action' => $key ) );
		$pending = '' !== $token ? self::payload( $token, true ) : null;
		if ( $pending ) {
			self::replay( $pending );
		}
		Admin::back(
			'' !== $token ? __( 'Password confirmed. The action you started had expired and was not carried out; please start it again.', 'affiliate-captain' ) : __( 'Password confirmed.', 'affiliate-captain' ),
			'' !== $token ? 'warning' : 'success',
			admin_url( 'admin.php?page=wpac' )
		);
	}

	/**
	 * The confirmation page.
	 */
	public static function render() {
		$uid     = (int) get_current_user_id();
		$user    = get_userdata( $uid );
		$token   = sanitize_key( Admin::get( 'wpac_reauth' ) );
		$pending = self::payload( $token, false );
		$cancel  = $pending && '' !== (string) $pending['return'] ? (string) $pending['return'] : admin_url( 'admin.php?page=wpac' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Confirm your password', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( '' !== $token && ! $pending ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The action you started has expired (after 5 minutes) or belongs to another login. Confirm your password and then start the action again.', 'affiliate-captain' ) . '</p></div>';
		} elseif ( ! $pending ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Confirm your password and then start the action again.', 'affiliate-captain' ) . '</p></div>';
		}
		if ( $pending ) {
			/* translators: %s: action, e.g. "Download SEPA file" */
			echo '<p><strong>' . esc_html( sprintf( __( 'Action: %s', 'affiliate-captain' ), self::action_label( (string) $pending['action'] ) ) ) . '</strong></p>';
		}
		$minutes = (int) ceil( self::ttl() / MINUTE_IN_SECONDS );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: minutes */
				_n( 'This action changes where money goes or exports bank details. For your protection, please enter your password again. Afterwards such actions work without asking again for %d minute in this login session.', 'This action changes where money goes or exports bank details. For your protection, please enter your password again. Afterwards such actions work without asking again for %d minutes in this login session.', $minutes, 'affiliate-captain' ),
				$minutes
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpac-reauth">';
		echo '<input type="hidden" name="action" value="wpac_reauth">';
		echo '<input type="hidden" name="wpac_reauth" value="' . esc_attr( $pending ? $token : '' ) . '">';
		wp_nonce_field( 'wpac_reauth', '_wpac_nonce', false );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="wpac-reauth-password">' . esc_html__( 'Password', 'affiliate-captain' ) . '</label></th><td>';
		echo '<input type="password" name="wpac_reauth_password" id="wpac-reauth-password" class="regular-text" autocomplete="current-password" spellcheck="false" required autofocus>';
		echo '</td></tr></table>';
		$provider = $user ? self::two_factor_provider( $user ) : null;
		if ( $provider && method_exists( $provider, 'authentication_page' ) ) {
			echo '<h2>' . esc_html__( 'Second factor', 'affiliate-captain' ) . '</h2><div class="wpac-reauth-2fa">';
			$provider->authentication_page( $user ); // Fields of the Two-Factor plugin (its own escaped output).
			echo '</div>';
		}
		if ( $user ) {
			/**
			 * Print extra fields of the confirmation form, e.g. the code field of a two-factor
			 * plugin that checks it in the filter wpac_reauth_verify.
			 *
			 * @since 0.15.0
			 * @param \WP_User $user Current user.
			 */
			do_action( 'wpac_reauth_form', $user );
		}
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Confirm and continue', 'affiliate-captain' ) . '</button> <a class="button" href="' . esc_url( $cancel ) . '">' . esc_html__( 'Back without confirming', 'affiliate-captain' ) . '</a></p>';
		echo '</form></div>';
	}

	/**
	 * Readable name of an action key.
	 *
	 * @param string $key Action key.
	 * @return string
	 */
	public static function action_label( $key ) {
		$labels = array(
			'bank_approve'        => __( 'Approve new bank details', 'affiliate-captain' ),
			'user_link_manual'    => __( 'Link a WordPress account to a partner', 'affiliate-captain' ),
			'user_unlink'         => __( 'Unlink the WordPress account of a partner', 'affiliate-captain' ),
			'user_link_resend'    => __( 'Send the link to connect the account again', 'affiliate-captain' ),
			'profile_update'      => __( 'Change the payout and tax details of a partner', 'affiliate-captain' ),
			'payout_bulk_sepa'    => __( 'Download SEPA XML', 'affiliate-captain' ),
			'payout_bulk_csv'     => __( 'Download transfer list (CSV, any currency)', 'affiliate-captain' ),
			'payout_bulk_paypal'  => __( 'Download PayPal payouts file (CSV)', 'affiliate-captain' ),
			'payout_bulk_paid'    => __( 'Mark as paid', 'affiliate-captain' ),
			'settings_reauth_off' => __( 'Switch off the password confirmation', 'affiliate-captain' ),
			'commission_manual'   => __( 'Book a manual commission or deduction', 'affiliate-captain' ),
			'commission_status_approved' => __( 'Approve commissions', 'affiliate-captain' ),
			'payout_run'          => __( 'Create payouts', 'affiliate-captain' ),
			'payout_request_process' => __( 'Create a payout from a payout request', 'affiliate-captain' ),
			'balance_writeoff'    => __( 'Write off a negative balance', 'affiliate-captain' ),
			'repayment'           => __( 'Book a repayment', 'affiliate-captain' ),
			'settings_import_apply'   => __( 'Import settings', 'affiliate-captain' ),
			'settings_export_private' => __( 'Export settings with bank details and addresses', 'affiliate-captain' ),
			'settings_reset_money'    => __( 'Reset commission or payout settings', 'affiliate-captain' ),
			'roles_save'              => __( 'Change the permissions of user roles', 'affiliate-captain' ),
			'affiliate_email_change' => __( 'Change the e-mail address of a partner', 'affiliate-captain' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;
	}

	/**
	 * Second factor of the Two-Factor plugin and of plugins on the filter wpac_reauth_verify.
	 *
	 * @param \WP_User|object $user User.
	 * @return true|\WP_Error
	 */
	private static function second_factor( $user ) {
		$result   = true;
		$provider = self::two_factor_provider( $user );
		if ( $provider && ! $provider->validate_authentication( $user ) ) {
			$result = new \WP_Error( 'wpac_reauth_2fa', __( 'The second factor (e.g. the code of your authenticator app) is not correct.', 'affiliate-captain' ) );
		}
		/**
		 * Check of a second factor for the password confirmation (e.g. Wordfence or miniOrange
		 * 2FA). Return true to accept, a WP_Error (its message is shown) or false to refuse. Runs
		 * after a correct password; the posted form fields are in $_POST.
		 *
		 * @since 0.15.0
		 * @param true|\WP_Error $result Result so far (the Two-Factor plugin's check, if active).
		 * @param \WP_User       $user   Current user.
		 */
		$result = apply_filters( 'wpac_reauth_verify', $result, $user );
		if ( true === $result ) {
			return true;
		}
		return is_wp_error( $result ) ? $result : new \WP_Error( 'wpac_reauth_verify', __( 'The second factor could not be confirmed.', 'affiliate-captain' ) );
	}

	/**
	 * Primary provider of the Two-Factor plugin for a user (null without the plugin or 2FA).
	 *
	 * @param \WP_User|object $user User.
	 * @return object|null
	 */
	private static function two_factor_provider( $user ) {
		$core = 'Two_Factor_Core';
		if ( ! class_exists( $core ) || ! is_callable( array( $core, 'get_primary_provider_for_user' ) ) ) {
			return null;
		}
		if ( is_callable( array( $core, 'is_user_using_two_factor' ) ) && ! call_user_func( array( $core, 'is_user_using_two_factor' ), (int) $user->ID ) ) {
			return null;
		}
		$provider = call_user_func( array( $core, 'get_primary_provider_for_user' ), $user );
		return is_object( $provider ) && method_exists( $provider, 'validate_authentication' ) ? $provider : null;
	}

	/**
	 * Remember the confirmation in the login session.
	 */
	private static function grant() {
		self::$confirmed_now = true;
		$token = self::session_token();
		if ( '' === $token || ! class_exists( 'WP_Session_Tokens' ) ) {
			return; // No session to bind to: valid for this request only.
		}
		$manager = \WP_Session_Tokens::get_instance( get_current_user_id() );
		$session = $manager->get( $token );
		if ( is_array( $session ) ) { // Never (re)create a session that ended.
			$session[ self::SESSION_KEY ] = time();
			$manager->update( $token, $session );
		}
	}

	/**
	 * Current login session (null without one).
	 *
	 * @return array|null
	 */
	private static function session() {
		$token = self::session_token();
		if ( '' === $token || ! class_exists( 'WP_Session_Tokens' ) ) {
			return null;
		}
		$session = \WP_Session_Tokens::get_instance( get_current_user_id() )->get( $token );
		return is_array( $session ) ? $session : null;
	}

	/**
	 * Session token of the login cookie ('' without one).
	 *
	 * @return string
	 */
	private static function session_token() {
		return function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
	}

	/**
	 * Keyed hash of the session token (bound into a kept form, the token itself is not stored).
	 *
	 * @return string
	 */
	private static function session_hash() {
		$token = self::session_token();
		return '' === $token ? '' : hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Keep the current request (POST and GET fields) for the confirmation, encrypted.
	 *
	 * @param string $key Action key.
	 * @return string Token ('' when it cannot be kept: uploads or too large).
	 */
	private static function store( $key ) {
		if ( ! empty( $_FILES ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
			return '';
		}
		// The superglobals as WordPress keeps them (slashed): restored as they were. Nonce verified in dispatch().
		$data = wp_json_encode( array( 'post' => $_POST, 'get' => $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_string( $data ) || strlen( $data ) > self::PAYLOAD_MAX ) {
			return '';
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			'wpac_reauth_' . $token,
			array(
				'user'    => (int) get_current_user_id(),
				'session' => self::session_hash(),
				'expires' => time() + self::PAYLOAD_TTL,
				'action'  => (string) $key,
				'return'  => (string) wpac_return_url(),
				'data'    => Crypto::encrypt( $data ),
			),
			self::PAYLOAD_TTL
		);
		return $token;
	}

	/**
	 * A kept request of the current user and session.
	 *
	 * @param string $token   Token.
	 * @param bool   $consume Take it (one-time) and decrypt the fields.
	 * @return array|null
	 */
	private static function payload( $token, $consume ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$key = 'wpac_reauth_' . $token;
		$p   = get_transient( $key );
		if ( ! is_array( $p ) || ! isset( $p['user'], $p['session'], $p['expires'], $p['action'], $p['return'], $p['data'] ) ) {
			return null;
		}
		if ( (int) $p['user'] !== (int) get_current_user_id() || ! hash_equals( (string) $p['session'], self::session_hash() ) || (int) $p['expires'] < time() ) {
			return null;
		}
		if ( ! $consume ) {
			return $p;
		}
		// Only the request that deletes it carries it out (a double submit runs it once).
		if ( ! delete_transient( $key ) ) {
			return null;
		}
		$data = json_decode( (string) Crypto::decrypt( (string) $p['data'] ), true );
		if ( ! is_array( $data ) || ! isset( $data['post'], $data['get'] ) || ! is_array( $data['post'] ) || ! is_array( $data['get'] ) ) {
			return null;
		}
		$p['data'] = $data;
		return $p;
	}

	/**
	 * Carry out a kept request: its fields replace those of the confirmation form (the password
	 * never reaches the action), then the normal routing checks its nonce and capability again.
	 *
	 * @param array $p Kept request (payload() with $consume).
	 */
	private static function replay( array $p ) {
		// phpcs:disable WordPress.Security.NonceVerification -- the kept nonce is verified by dispatch().
		$_GET     = $p['data']['get'];
		$_POST    = $p['data']['post'];
		$_REQUEST = array_merge( $_GET, $_POST );
		if ( '' !== (string) $p['return'] && empty( $_REQUEST['_wp_http_referer'] ) ) {
			$_SERVER['HTTP_REFERER'] = (string) $p['return'];
		}
		// phpcs:enable
		Admin::dispatch();
	}

	/**
	 * Log a refused confirmation.
	 *
	 * @param int    $uid    User.
	 * @param string $key    Action key.
	 * @param string $reason password, second_factor or rate_limited.
	 */
	private static function failed( $uid, $key, $reason ) {
		Audit::log(
			'security.reauth_failed',
			'user',
			(int) $uid,
			array(
				'action' => (string) $key,
				'reason' => (string) $reason,
			)
		);
	}
}
