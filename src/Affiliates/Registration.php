<?php
/**
 * Public application form: [wpac_affiliate_registration].
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Registration {

	const SHORTCODE = 'wpac_affiliate_registration';

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_submit' ) );
		add_action( 'init', array( __CLASS__, 'handle_confirm' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 2 );
		add_action( 'save_post', array( __CLASS__, 'record_group_approval' ), 20, 2 );
	}

	/** Post meta: private groups whose forms a partner manager approved in this post's content. */
	const META_GROUPS = '_wpac_form_groups';

	/** Maximum lengths of the application fields (characters). */
	const MAX_NAME    = 100;
	const MAX_EMAIL   = 100;
	const MAX_URL     = 500;
	const MAX_CHANNEL = 100;
	const MAX_TEXT    = 255;
	const MAX_NOTE    = 2000;

	/** Opt-in mails per recipient address and day. */
	const MAILS_PER_ADDRESS = 2;

	/**
	 * Pages with the form carry a nonce (valid for 24 hours) and the confirmation step
	 * is personal: they must not be served from a page cache. Decided before output,
	 * because shortcodes run after the headers were sent.
	 */
	public static function no_cache() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$confirm = ! empty( $_GET['wpac_confirm'] ) || ! empty( $_GET['wpac_reg'] ) || ! empty( $_GET['wpac_err'] );
		$page    = (int) Settings::get( 'registration_page_id' );
		if ( $confirm || ( $page && is_page( $page ) ) || \WPAffiliateCaptain\Promotion\Landing::current_post_has( self::SHORTCODE, 'wpac/registration' ) ) {
			\WPAffiliateCaptain\Promotion\Landing::no_cache();
		}
	}

	/**
	 * Shorten a value to a maximum length.
	 *
	 * @param string $value Value.
	 * @param int    $max   Characters.
	 * @return string
	 */
	public static function cap( $value, $max ) {
		return Db::cut( $value, $max ); // One helper for character-safe truncation.
	}

	/**
	 * Whether another opt-in/notice mail may go to this address today. Keyed by a salted
	 * hash of the address (not by IP), so the form cannot be used to flood one inbox.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function mail_allowed( $email ) {
		return RateLimiter::allow_for( 'register_mail', strtolower( trim( (string) $email ) ), self::MAILS_PER_ADDRESS, DAY_IN_SECONDS );
	}

	/**
	 * Process the form (POST-redirect-GET).
	 */
	public static function handle_submit() {
		if ( empty( $_POST['wpac_register'] ) ) {
			return;
		}
		$redirect = wpac_return_url();
		$redirect = remove_query_arg( array( 'wpac_reg', 'wpac_err', 'wpac_fi' ), $redirect );
		// Which form on the page was sent (several forms must not share messages and entries).
		self::$form_id = isset( $_POST['wpac_form_id'] ) && is_string( $_POST['wpac_form_id'] ) ? substr( sanitize_key( wp_unslash( $_POST['wpac_form_id'] ) ), 0, 40 ) : '';

		// Checks first: nothing is stored for requests that fail them (no storage abuse).
		if ( ! isset( $_POST['_wpac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_nonce'] ) ), 'wpac_register' ) ) {
			self::back( $redirect, 'nonce' );
		}
		// Honeypot.
		if ( ! empty( $_POST['wpac_url_confirm'] ) ) {
			self::back( $redirect, 'spam' );
		}
		if ( ! RateLimiter::allow( 'register', 5, HOUR_IN_SECONDS ) ) {
			self::back( $redirect, 'rate' );
		}

		// Keep the entries for the form if something has to be corrected (bounded).
		foreach ( $_POST as $k => $v ) {
			if ( count( self::$values ) >= 40 ) {
				break;
			}
			if ( is_string( $v ) && 0 === strpos( (string) $k, 'wpac_' ) && strlen( (string) $k ) <= 64 && ! in_array( $k, array( 'wpac_register', 'wpac_url_confirm', 'wpac_group_sig', 'wpac_group_post', 'wpac_form_id' ), true ) ) {
				self::$values[ sanitize_key( $k ) ] = Db::cut( sanitize_textarea_field( wp_unslash( $v ) ), 2000 );
			}
		}

		$group_id = isset( $_POST['wpac_group'] ) ? absint( $_POST['wpac_group'] ) : 0;
		$group    = $group_id ? Groups::find( $group_id ) : null;
		$sig      = isset( $_POST['wpac_group_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_group_sig'] ) ) : '';
		$post_id  = isset( $_POST['wpac_group_post'] ) ? absint( $_POST['wpac_group_post'] ) : 0;
		// Only public groups or the group fixed by the shortcode/block of a published page (signed) can be chosen.
		if ( $group_id && ( ! $group || 'active' !== $group->status || ( ! (int) $group->public && ! self::fixed_group_valid( $group_id, $post_id, $sig ) ) ) ) {
			self::back( $redirect, 'invalid' );
		}

		// Every invalid field is reported at once (R12-M26): the form lists all of them, each field
		// is marked; the focus goes to the first one.
		$invalid          = array();
		$application_data = array();
		if ( ! isset( $_POST['wpac_name'] ) || ! is_string( $_POST['wpac_name'] ) || '' === trim( sanitize_text_field( wp_unslash( $_POST['wpac_name'] ) ) ) ) {
			$invalid['wpac_name'] = __( 'Please enter your name or company.', 'affiliate-captain' );
		}
		$user  = wp_get_current_user();
		$email = $user && $user->exists() ? $user->user_email : ( isset( $_POST['wpac_email'] ) && is_string( $_POST['wpac_email'] ) ? sanitize_email( wp_unslash( $_POST['wpac_email'] ) ) : '' );
		if ( strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) {
			$invalid['wpac_email'] = __( 'Please enter a valid e-mail address.', 'affiliate-captain' );
		}
		foreach ( Groups::fields( $group ) as $field ) {
			$name  = 'wpac_f_' . $field['key'];
			$value = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			switch ( $field['type'] ) {
				case 'textarea':
					$value = self::cap( sanitize_textarea_field( is_string( $value ) ? $value : '' ), self::MAX_NOTE );
					break;
				case 'url':
					$value = esc_url_raw( self::cap( is_string( $value ) ? $value : '', self::MAX_URL ) );
					break;
				case 'number':
					$value = is_string( $value ) && is_numeric( $value ) && strlen( $value ) <= 30 ? (string) ( 0 + $value ) : '';
					break;
				case 'checkbox':
					$value = $value ? '1' : '';
					break;
				default:
					$value = self::cap( sanitize_text_field( is_string( $value ) ? $value : '' ), self::MAX_TEXT );
			}
			if ( $field['required'] && '' === $value ) {
				/* translators: %s: field label */
				$invalid[ 'wpac_f_' . $field['key'] ] = sprintf( __( 'Please fill in "%s".', 'affiliate-captain' ), $field['label'] );
				continue;
			}
			$application_data[ $field['key'] ] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		$terms = Agreements::current( $group_id );
		if ( $terms['page_id'] && empty( $_POST['wpac_terms'] ) ) {
			$invalid['wpac_terms'] = __( 'Please accept the partner program terms.', 'affiliate-captain' );
		}
		if ( $invalid ) {
			self::$invalid = $invalid;
			// The first invalid field in the order of the form gets the focus.
			$order       = array_merge( array( 'wpac_name', 'wpac_email' ), array_keys( array_diff_key( $invalid, array_flip( array( 'wpac_name', 'wpac_email', 'wpac_terms' ) ) ) ), array( 'wpac_terms' ) );
			self::$field = (string) current( array_intersect( $order, array_keys( $invalid ) ) );
			$code        = array( 'wpac_terms' ) === array_keys( $invalid ) ? 'terms' : ( isset( $invalid['wpac_email'] ) ? 'invalid' : 'required' );
			self::back( $redirect, $code );
		}
		if ( $terms['page_id'] ) {
			// The text accepted now is kept even if the page changes before the e-mail confirmation.
			Agreements::store_text( $terms );
		}

		if ( $user && $user->exists() && AffiliateRepository::find_by_user( $user->ID ) ) {
			self::back( $redirect, 'exists' );
		}

		$data = array(
			'user_id'          => $user && $user->exists() ? $user->ID : 0,
			// Bounded lengths: the data waits in an option (Core\Pending) until it is confirmed.
			'name'             => isset( $_POST['wpac_name'] ) && is_string( $_POST['wpac_name'] ) ? self::cap( sanitize_text_field( wp_unslash( $_POST['wpac_name'] ) ), self::MAX_NAME ) : '',
			'email'            => $email,
			'website'          => isset( $_POST['wpac_website'] ) && is_string( $_POST['wpac_website'] ) ? esc_url_raw( self::cap( wp_unslash( $_POST['wpac_website'] ), self::MAX_URL ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw().
			'channel'          => isset( $_POST['wpac_channel'] ) && is_string( $_POST['wpac_channel'] ) ? self::cap( sanitize_text_field( wp_unslash( $_POST['wpac_channel'] ) ), self::MAX_CHANNEL ) : '',
			'application_note' => isset( $_POST['wpac_note'] ) && is_string( $_POST['wpac_note'] ) ? self::cap( sanitize_textarea_field( wp_unslash( $_POST['wpac_note'] ) ), self::MAX_NOTE ) : '',
			'application_data' => $application_data,
			'group_id'         => $group_id,
			'referred_by'      => self::recruiter( $email, $user && $user->exists() ? (int) $user->ID : 0 ),
			'terms'            => $terms['page_id'] ? $terms + array( 'accepted_at' => Db::now() ) : null, // Version + hash accepted now.
			'redirect'         => $redirect,
		);

		// Double opt-in for everyone, also logged-in users: WordPress never verifies an account
		// e-mail, so someone could apply under another person's address (impersonation).
		// Nothing is created until the e-mail address is confirmed.
		// The response is identical whether or not the address is already known (no enumeration),
		// and also when the per-address mail limit is reached (no mail is sent then).
		if ( ! self::mail_allowed( $email ) ) {
			self::back( $redirect, '', 'confirm' );
		}
		if ( AffiliateRepository::find_by_email( $email ) ) {
			Notifications::already_registered( $email );
		} else {
			$token = wp_generate_password( 32, false );
			if ( ! \WPAffiliateCaptain\Core\Pending::set( 'app_' . hash( 'sha256', $token ), $data, 2 * DAY_IN_SECONDS ) ) {
				self::back( $redirect, 'error' );
			}
			Notifications::confirm_application( $email, add_query_arg( 'wpac_confirm', rawurlencode( $token ), $redirect ) );
		}
		self::back( $redirect, '', 'confirm' );
	}

	/**
	 * A pending application or confirmation marker (Core\Pending; older versions kept them in
	 * transients, which are still read until they expire).
	 *
	 * @param string $kind app_|appdone_.
	 * @param string $hash Token hash.
	 * @return mixed|null
	 */
	private static function stored( $kind, $hash ) {
		$value = \WPAffiliateCaptain\Core\Pending::get( $kind . $hash );
		if ( null === $value ) {
			$value = get_transient( 'wpac_' . $kind . $hash );
		}
		return false === $value ? null : $value;
	}

	/**
	 * Hash of a confirmation token (key part).
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function token_hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * State of a confirmation token: 'pending' (can be confirmed), 'done' (already
	 * confirmed – a second click shows success), 'expired'.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function token_state( $token ) {
		if ( '' === (string) $token ) {
			return 'expired';
		}
		$hash = self::token_hash( $token );
		if ( is_array( self::stored( 'app_', $hash ) ) ) {
			return 'pending';
		}
		return self::stored( 'appdone_', $hash ) ? 'done' : 'expired';
	}

	/**
	 * Whether the current visitor may confirm an application bound to a WordPress account:
	 * only while logged in as that account and while its e-mail address is still the one
	 * the confirmation went to. WordPress (and WooCommerce's account page) let users change
	 * their address without verifying it, so the link alone would let someone attach a
	 * partner account with another person's address to their own login.
	 *
	 * @param array $data Application data.
	 * @return bool
	 */
	private static function account_matches( array $data ) {
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( ! $user_id ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && get_current_user_id() === $user_id && 0 === strcasecmp( (string) $user->user_email, (string) $data['email'] );
	}

	/**
	 * Confirmation from the opt-in e-mail. Opening the link (GET) only shows a
	 * "Confirm application" button, so mail scanners that open links (Safe Links etc.)
	 * do not consume it; the button posts the token. The token itself is the secret.
	 */
	public static function handle_confirm() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the token itself is the secret (CSRF-proof).
		if ( empty( $_POST['wpac_confirm_app'] ) || empty( $_POST['wpac_confirm'] ) || ! is_string( $_POST['wpac_confirm'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_POST['wpac_confirm'] ) );
		// phpcs:enable
		$hash  = self::token_hash( $token );
		$back  = wpac_return_url();
		$state = self::token_state( $token );
		if ( 'done' === $state ) {
			self::back( $back, '', 'ok' );
		}
		if ( 'pending' !== $state ) {
			self::back( $back, 'expired' );
		}
		// One confirmation at a time (double click, two tabs): the other request shows success.
		$lock = Db::lock( 'wpac_appconf_' . substr( $hash, 0, 32 ), MINUTE_IN_SECONDS );
		if ( '' === $lock ) {
			self::back( $back, '', 'ok' );
		}
		$data = self::stored( 'app_', $hash );
		if ( ! is_array( $data ) ) {
			Db::unlock( 'wpac_appconf_' . substr( $hash, 0, 32 ), $lock );
			self::back( $back, self::stored( 'appdone_', $hash ) ? '' : 'expired', 'ok' );
		}
		if ( ! self::account_matches( $data ) ) {
			Db::unlock( 'wpac_appconf_' . substr( $hash, 0, 32 ), $lock );
			// Back to the confirmation step, which asks to log in with the right account.
			wp_safe_redirect( add_query_arg( 'wpac_confirm', rawurlencode( $token ), remove_query_arg( array( 'wpac_reg', 'wpac_err', 'wpac_confirm', 'wpac_form' ), $back ) ) );
			exit;
		}
		$result = self::finalize( $data );
		if ( ! is_wp_error( $result ) ) {
			// Repeated clicks within the link's lifetime show success instead of "expired".
			\WPAffiliateCaptain\Core\Pending::set( 'appdone_' . $hash, 1, 2 * DAY_IN_SECONDS );
		}
		\WPAffiliateCaptain\Core\Pending::delete( 'app_' . $hash );
		delete_transient( 'wpac_app_' . $hash ); // Stored by an older version.
		Db::unlock( 'wpac_appconf_' . substr( $hash, 0, 32 ), $lock );
		$target = ! empty( $data['redirect'] ) ? $data['redirect'] : $back;
		self::back( $target, is_wp_error( $result ) ? 'exists' : '', 'ok' );
	}

	/**
	 * Confirmation step shown for ?wpac_confirm=TOKEN (GET).
	 *
	 * @param string $token Token.
	 * @return string HTML.
	 */
	private static function render_confirm( $token ) {
		$state = self::token_state( $token );
		if ( 'done' === $state ) {
			return self::success_notice();
		}
		if ( 'pending' !== $state ) {
			return '<div class="wpac-notice wpac-notice--error" role="alert">' . esc_html__( 'The confirmation link is invalid or has expired. Please apply again.', 'affiliate-captain' ) . '</div>';
		}
		$data = self::stored( 'app_', self::token_hash( $token ) );
		$data = is_array( $data ) ? $data : array();
		if ( ! self::account_matches( $data ) ) {
			$here = add_query_arg( 'wpac_confirm', rawurlencode( $token ), get_permalink() ? get_permalink() : home_url( '/' ) );
			return '<div class="wpac-notice">' . esc_html__( 'You applied while logged in. Please log in with the same user account to confirm your application.', 'affiliate-captain' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Log in', 'affiliate-captain' ) . '</a></div>';
		}
		return '<form class="wpac-form" method="post">'
			. '<p>' . esc_html__( 'Please confirm your partner application with the button below.', 'affiliate-captain' ) . '</p>'
			. wp_referer_field( false )
			. '<input type="hidden" name="wpac_confirm_app" value="1">'
			. '<input type="hidden" name="wpac_confirm" value="' . esc_attr( $token ) . '">'
			. '<p><button type="submit" class="wpac-button">' . esc_html__( 'Confirm application', 'affiliate-captain' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Success message after the confirmation.
	 *
	 * @return string HTML.
	 */
	private static function success_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wpac_review (Form Captain hand-over): message only.
		$msg = Settings::get( 'auto_approve' ) && empty( $_GET['wpac_review'] )
			? __( 'Thank you! Your partner account is active. Check your e-mail for login details.', 'affiliate-captain' )
			: __( 'Thank you for your application! We will review it and get back to you by e-mail.', 'affiliate-captain' );
		return '<div class="wpac-notice wpac-notice--success" role="status">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * Recruiting partner of an application (sub-affiliate add-ons, wpac_affiliate_recruited): a
	 * partner code on the registration URL (referral parameter, e.g. ?partner=CODE; the form posts
	 * to the same URL, else the page it was sent from), otherwise the captured referral of the
	 * visitor (session or consented cookie, like an order). Only active partners, never the
	 * applicant themselves.
	 *
	 * @param string $email   Applicant e-mail address.
	 * @param int    $user_id Logged-in WordPress user of the applicant (0 = none).
	 * @return int Partner ID, 0 = none.
	 */
	public static function recruiter( $email, $user_id ) {
		$param = (string) Settings::get( 'referral_param' );
		$code  = '';
		// phpcs:disable WordPress.Security.NonceVerification -- read-only; the form nonce was checked by the caller.
		if ( '' !== $param && isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) {
			$code = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
		}
		// phpcs:enable
		if ( '' === $code && '' !== $param ) {
			$query = wp_parse_url( wpac_return_url(), PHP_URL_QUERY );
			if ( is_string( $query ) ) {
				parse_str( $query, $vars );
				$code = isset( $vars[ $param ] ) && is_string( $vars[ $param ] ) ? sanitize_text_field( $vars[ $param ] ) : '';
			}
		}
		$resolved = '' !== $code ? wpac_resolve_partner_code( Db::cut( $code, 100 ), array( 'context' => 'link' ) ) : null;
		if ( ! is_array( $resolved ) ) {
			$touch    = \WPAffiliateCaptain\Tracking\Tracker::current_touch();
			$resolved = $touch && ! empty( $touch['code'] ) ? wpac_resolve_partner_code( (string) $touch['code'], array( 'context' => 'touch' ) ) : null;
		}
		$recruiter = is_array( $resolved ) && ! empty( $resolved['affiliate_id'] ) ? (int) $resolved['affiliate_id'] : 0;
		if ( ! $recruiter || ! self::recruiter_valid( $recruiter, (string) $email, (int) $user_id ) ) {
			return 0;
		}
		$aff  = AffiliateRepository::find( $recruiter );
		$self = $aff && self::same_device( (int) $aff->user_id );
		/**
		 * Whether an applicant is the recruiting partner themselves (a second partner account, e.g.
		 * with another e-mail address): then no recruiter is recorded. The default checks the
		 * device: the application comes from the address and browser of a login session of the
		 * recruiter's WordPress account. Fraud plugins can add their own signals.
		 *
		 * @since 0.16.0
		 * @param bool   $self      Default: same device as a login session of the recruiter.
		 * @param int    $recruiter Recruiting partner.
		 * @param string $email     Applicant e-mail address.
		 * @param int    $user_id   Applicant WordPress user (0 = none).
		 */
		return apply_filters( 'wpac_is_self_recruitment', $self, $recruiter, (string) $email, (int) $user_id ) ? 0 : $recruiter;
	}

	/**
	 * Whether the current request comes from the device of a login session of a WordPress user:
	 * the same client address (as WordPress stores it in the session) and the same browser (user
	 * agent). Read from the user's sessions for this comparison only, nothing is stored (R15-S6).
	 * Addresses of a shared proxy (private or reserved ranges, trusted proxies) never match.
	 *
	 * @param int $user_id WordPress user (0 = none).
	 * @return bool
	 */
	private static function same_device( $user_id ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared only, never output or stored.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		// phpcs:enable
		if ( ! $user_id || '' === $ip || '' === $ua || ! class_exists( 'WP_Session_Tokens' ) ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false; // A proxy or a local network: every visitor would share the address.
		}
		foreach ( preg_split( '/[\s,]+/', (string) Settings::get( 'trusted_proxies' ), -1, PREG_SPLIT_NO_EMPTY ) as $proxy ) {
			if ( RateLimiter::in_cidr( $ip, $proxy ) ) {
				return false;
			}
		}
		foreach ( (array) \WP_Session_Tokens::get_instance( (int) $user_id )->get_all() as $session ) {
			if ( is_array( $session ) && isset( $session['ip'], $session['ua'] ) && (string) $session['ip'] === $ip && hash_equals( (string) $session['ua'], $ua ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a partner can be recorded as the recruiter of an applicant: active, processing not
	 * restricted, and not the applicant (same WordPress user, or the same e-mail address as the
	 * partner or the partner's WordPress account; compared like the self-referral check).
	 *
	 * @param int    $recruiter Partner.
	 * @param string $email     Applicant e-mail address.
	 * @param int    $user_id   Applicant WordPress user (0 = none).
	 * @return bool
	 */
	public static function recruiter_valid( $recruiter, $email, $user_id ) {
		$aff = AffiliateRepository::find( (int) $recruiter );
		if ( ! $aff || 'active' !== $aff->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) ) {
			return false;
		}
		if ( (int) $user_id && (int) $aff->user_id === (int) $user_id ) {
			return false;
		}
		$email = AffiliateRepository::comparable_email( (string) $email );
		if ( AffiliateRepository::comparable_email( (string) $aff->email ) === $email ) {
			return false;
		}
		// Also the address of the recruiter's WordPress account, like the self-referral check of
		// orders (AttributionResolver::is_self_referral(), R15-S6).
		$user = $aff->user_id ? get_userdata( (int) $aff->user_id ) : null;
		return ! ( $user && '' !== $email && AffiliateRepository::comparable_email( (string) $user->user_email ) === $email );
	}

	/**
	 * Create the application (after verification).
	 *
	 * @param array $data Application data.
	 * @return int|\WP_Error
	 */
	private static function finalize( array $data ) {
		$terms = isset( $data['terms'] ) ? $data['terms'] : null;
		// Hand-overs from Form Captain (AFF-SU-01) always wait for a decision, also with auto_approve.
		$status = Settings::get( 'auto_approve' ) && empty( $data['pending_only'] ) ? 'active' : 'pending';
		$result = AffiliateRepository::create(
			array(
				'user_id'          => (int) $data['user_id'],
				'name'             => $data['name'],
				'email'            => $data['email'],
				'website'          => $data['website'],
				'channel'          => $data['channel'],
				'application_note' => $data['application_note'],
				'application_data' => $data['application_data'],
				'group_id'         => (int) $data['group_id'],
				'status'           => $status,
				// Checked again: the recruiter may have been paused since the application.
				'referred_by'      => ! empty( $data['referred_by'] ) && self::recruiter_valid( (int) $data['referred_by'], (string) $data['email'], (int) $data['user_id'] ) ? (int) $data['referred_by'] : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $terms ) {
			Agreements::record( $result, 'accepted', $terms );
		}
		if ( ! empty( $data['handover'] ) ) {
			\WPAffiliateCaptain\Integrations\FormCaptain\Handover::confirmed( (string) $data['handover'], (int) $result );
		}
		if ( 'pending' === $status ) {
			Notifications::application_received( $result );
		} else {
			// Approved right away: the partner gets the welcome mail with the portal link.
			Notifications::application_approved( $result );
		}
		return $result;
	}

	/**
	 * Redirect back with an error or a success state.
	 *
	 * @param string $url     URL.
	 * @param string $code    Error code ('' = none).
	 * @param string $success Success state (ok|confirm).
	 */
	private static function back( $url, $code, $success = '' ) {
		$url = remove_query_arg( array( 'wpac_reg', 'wpac_err', 'wpac_confirm', 'wpac_form', 'wpac_fi' ), $url );
		if ( '' !== $code && '' !== self::$form_id ) {
			$url = add_query_arg( 'wpac_fi', self::$form_id, $url );
		}
		if ( '' !== $code && self::$values ) {
			$token = wp_generate_password( 20, false );
			set_transient(
				self::KEPT_PREFIX . $token,
				array(
					'values' => self::$values,
					'field'  => self::$field,
					'errors' => self::$invalid,
				),
				15 * MINUTE_IN_SECONDS
			);
			$url = add_query_arg( 'wpac_form', $token, $url );
		}
		wp_safe_redirect( '' !== $code ? add_query_arg( 'wpac_err', $code, $url ) : add_query_arg( 'wpac_reg', $success, $url ) );
		exit;
	}

	/**
	 * Transient prefix of entries kept for correction after an error (own namespace: never the
	 * admin's wpac_form_* transients; R12-M18).
	 */
	const KEPT_PREFIX = 'wpac_regkeep_';

	/**
	 * Entries kept for correction by the token in the address: only a token of exactly the
	 * generated format (20 letters/digits) is looked up.
	 *
	 * @param string $token Token from the address.
	 * @return array
	 */
	public static function kept_entries( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', (string) $token ) ) {
			return array();
		}
		$form = get_transient( self::KEPT_PREFIX . $token );
		return is_array( $form ) ? $form : array();
	}

	/** @var array Submitted values (kept for correction). */
	private static $values = array();

	/** @var string Field with the error. */
	private static $field = '';

	/** @var array<string,string> Invalid fields of the submission: field name => message. */
	private static $invalid = array();

	/** @var string Form that was submitted (form_key()). */
	private static $form_id = '';

	/** @var int Forms rendered in this request (unique element IDs). */
	private static $instances = 0;

	/** @var string Suffix of the element IDs of the form being rendered. */
	private static $suffix = '';

	/**
	 * Attributes for a form control: kept value, aria-invalid/describedby.
	 *
	 * @param array  $form   Kept form state.
	 * @param string $name   Field name.
	 * @param bool   $value  Output a value attribute.
	 * @return string
	 */
	private static function attrs( array $form, $name, $value = true ) {
		$out = '';
		if ( $value && isset( $form['values'][ $name ] ) ) {
			$out .= ' value="' . esc_attr( $form['values'][ $name ] ) . '"';
		}
		$errors = self::form_errors( $form );
		if ( isset( $errors[ $name ] ) ) {
			// Linked to its own message below the field, or (older kept state) to the form message.
			$described = '' !== $errors[ $name ] ? self::error_id( $name ) : 'wpac-form-error' . self::$suffix;
			$out      .= ' aria-invalid="true" aria-describedby="' . esc_attr( $described ) . '"' . ( '' === self::$suffix && isset( $form['field'] ) && $form['field'] === $name ? ' autofocus' : '' );
		}
		return $out;
	}

	/**
	 * What follows the error message of the form: the list of invalid fields (each a link to its
	 * field), or for an existing account the way to log in or reset the password (R12-M26, N7).
	 *
	 * @param string $err  Error code.
	 * @param array  $form Kept form state.
	 * @return string
	 */
	private static function error_extras( $err, array $form ) {
		if ( 'exists' === $err ) {
			$portal = (int) Settings::get( 'portal_page_id' ) ? Notifications::portal_url() : home_url( '/' );
			return ' <a href="' . esc_url( wp_login_url( $portal ) ) . '">' . esc_html__( 'Log in to the partner area', 'affiliate-captain' ) . '</a> · <a href="' . esc_url( wp_lostpassword_url( $portal ) ) . '">' . esc_html__( 'Forgot your password?', 'affiliate-captain' ) . '</a>';
		}
		$errors = array_filter( self::form_errors( $form ) );
		if ( ! $errors || ( 'terms' === $err && 1 === count( $errors ) ) ) {
			return '';
		}
		$out = '<ul class="wpac-error-list">';
		foreach ( $errors as $name => $message ) {
			$out .= '<li><a href="#' . esc_attr( self::field_id( $name ) ) . '">' . esc_html( $message ) . '</a></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Invalid fields of the kept form state: field name => message ('' = no own message).
	 *
	 * @param array $form Kept form state.
	 * @return array<string,string>
	 */
	private static function form_errors( array $form ) {
		$errors = isset( $form['errors'] ) && is_array( $form['errors'] ) ? array_map( 'strval', $form['errors'] ) : array();
		if ( ! $errors && ! empty( $form['field'] ) ) {
			$errors = array( (string) $form['field'] => '' );
		}
		return $errors;
	}

	/**
	 * Element ID of a field (with the suffix of the form being rendered).
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function field_id( $name ) {
		return $name . self::$suffix;
	}

	/**
	 * Element ID of the message of an invalid field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function error_id( $name ) {
		return 'wpac-err-' . $name . self::$suffix;
	}

	/**
	 * Message below an invalid field (empty when the field is valid).
	 *
	 * @param array  $form Kept form state.
	 * @param string $name Field name.
	 * @return string
	 */
	private static function field_error( array $form, $name ) {
		$errors = self::form_errors( $form );
		if ( empty( $errors[ $name ] ) ) {
			return '';
		}
		return '<span class="wpac-field-error" id="' . esc_attr( self::error_id( $name ) ) . '">' . esc_html( $errors[ $name ] ) . '</span>';
	}

	/**
	 * Signature for a group fixed via shortcode attribute or block, bound to the page and to
	 * the approval of the group on that page (copies of an older version stop working when the
	 * approval of the page's groups changes).
	 *
	 * @param int         $group_id Group.
	 * @param object|null $post     Page that shows the form.
	 * @return string
	 */
	private static function group_sig( $group_id, $post ) {
		if ( ! $post ) {
			return '';
		}
		$approval = get_post_meta( (int) $post->ID, self::META_GROUPS, true );
		$stamp    = is_array( $approval ) && isset( $approval['at'] ) ? (string) $approval['at'] : ( isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '' );
		return wp_hash( 'wpac_group_' . (int) $group_id . '_' . (int) $post->ID . '_' . $stamp );
	}

	/**
	 * Partner groups fixed by application forms in a content (shortcode attribute group="ID"
	 * and the block attribute "group", also in nested blocks).
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function content_groups( $content ) {
		$content = (string) $content;
		$out     = array();
		if ( preg_match_all( '/\[' . self::SHORTCODE . '\b([^\]]*)\]/', $content, $m ) ) {
			foreach ( $m[1] as $atts ) {
				if ( preg_match( '/\bgroup\s*=\s*(?:"|\'|&quot;|&#0?39;|&#8221;|&#8243;)?\s*(\d+)/', $atts, $g ) ) {
					$out[] = (int) $g[1];
				}
			}
		}
		if ( preg_match_all( '/<!--\s+wp:wpac\/registration\s+(\{.*?\})\s+\/?-->/s', $content, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && isset( $attrs['group'] ) && is_numeric( $attrs['group'] ) ) {
					$out[] = (int) $attrs['group'];
				}
			}
		}
		$out = array_values( array_unique( array_filter( $out ) ) );
		sort( $out );
		return $out;
	}

	/**
	 * Groups a form in the content may open after a save: a partner manager approves all groups
	 * of the content; anybody else keeps only groups approved before (editing the text of an
	 * approved page keeps its invitation, setting another group does not open it).
	 *
	 * @param int[] $found      Groups in the new content.
	 * @param int[] $previous   Groups approved before.
	 * @param bool  $can_manage Whether the saving user manages partners.
	 * @return int[]
	 */
	public static function approved_groups( array $found, array $previous, $can_manage ) {
		$found = array_map( 'intval', $found );
		$out   = $can_manage ? $found : array_intersect( $found, array_map( 'intval', $previous ) );
		$out   = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Record which fixed groups of a saved post are approved (save_post).
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public static function record_group_approval( $post_id, $post ) {
		if ( ! $post || ( function_exists( 'wp_is_post_revision' ) && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) ) {
			return;
		}
		$found    = self::content_groups( isset( $post->post_content ) ? $post->post_content : '' );
		$stored   = get_post_meta( (int) $post_id, self::META_GROUPS, true );
		$previous = is_array( $stored ) && isset( $stored['groups'] ) ? array_map( 'intval', (array) $stored['groups'] ) : array();
		if ( ! $found ) {
			if ( is_array( $stored ) ) {
				delete_post_meta( (int) $post_id, self::META_GROUPS );
			}
			return;
		}
		$groups = self::approved_groups( $found, $previous, current_user_can( Capabilities::MANAGE_AFFILIATES ) );
		if ( is_array( $stored ) && $groups === $previous ) {
			return; // Unchanged: open forms keep their signature.
		}
		update_post_meta(
			(int) $post_id,
			self::META_GROUPS,
			array(
				'groups' => $groups,
				'at'     => time() . '-' . wp_generate_password( 6, false ),
			)
		);
	}

	/**
	 * Whether a published page may show a form for a non-public group: the group must be fixed
	 * by a form in the page's own content and approved by a partner manager (record_group_approval();
	 * pages saved before that was recorded: last edited by a partner manager). A preview of a
	 * draft, a post by an author or a group set by an editor cannot open a private group.
	 *
	 * @param object|null $post     Page.
	 * @param int         $group_id Group.
	 * @return bool
	 */
	public static function post_opens_group( $post, $group_id ) {
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( ! in_array( (int) $group_id, self::content_groups( isset( $post->post_content ) ? $post->post_content : '' ), true ) ) {
			return false;
		}
		$approval = get_post_meta( (int) $post->ID, self::META_GROUPS, true );
		if ( is_array( $approval ) ) {
			return in_array( (int) $group_id, array_map( 'intval', isset( $approval['groups'] ) ? (array) $approval['groups'] : array() ), true );
		}
		$editor = (int) get_post_meta( (int) $post->ID, '_edit_last', true );
		return user_can( $editor ? $editor : (int) $post->post_author, Capabilities::MANAGE_AFFILIATES );
	}

	/**
	 * A non-public group is only accepted from a page that may open it (signed form).
	 *
	 * @param int    $group_id Group.
	 * @param int    $post_id  Page that showed the form.
	 * @param string $sig      Signature.
	 * @return bool
	 */
	public static function fixed_group_valid( $group_id, $post_id, $sig ) {
		$post = $post_id ? get_post( $post_id ) : null;
		return $post && self::post_opens_group( $post, $group_id ) && hash_equals( self::group_sig( $group_id, $post ), (string) $sig );
	}

	/**
	 * Signature a form on the page would carry (tests, diagnostics).
	 *
	 * @param int $group_id Group.
	 * @param int $post_id  Page.
	 * @return string
	 */
	public static function signature_for( $group_id, $post_id ) {
		return self::group_sig( $group_id, get_post( $post_id ) );
	}

	/**
	 * Why a form with a fixed group cannot be sent ('' = it can).
	 *
	 * @param object|null $group    Group.
	 * @param int         $group_id Group ID.
	 * @param int         $post_id  Page showing the form.
	 * @return string Reason for partner managers.
	 */
	public static function fixed_group_problem( $group, $group_id, $post_id ) {
		if ( ! $group ) {
			/* translators: %d: group ID */
			return sprintf( __( 'The partner group #%d set in this form no longer exists.', 'affiliate-captain' ), (int) $group_id );
		}
		if ( 'active' !== $group->status ) {
			/* translators: %s: group name */
			return sprintf( __( 'The partner group "%s" set in this form is inactive.', 'affiliate-captain' ), $group->name );
		}
		if ( ! (int) $group->public && ! self::post_opens_group( $post_id ? get_post( $post_id ) : null, (int) $group_id ) ) {
			/* translators: %s: group name */
			return sprintf( __( 'The private partner group "%s" opens only from a form in the content of a published page last saved by a partner manager.', 'affiliate-captain' ), $group->name );
		}
		return '';
	}

	/**
	 * Key of a form on a page (keeps messages and entries with the form that was sent).
	 *
	 * @param int $group_id Fixed group.
	 * @param int $post_id  Page.
	 * @return string
	 */
	public static function form_key( $group_id, $post_id ) {
		return 'f' . (int) $group_id . 'p' . (int) $post_id;
	}

	/**
	 * Nonce and referer fields without element IDs (several forms on one page).
	 *
	 * @return string HTML.
	 */
	private static function nonce_field() {
		return '<input type="hidden" name="_wpac_nonce" value="' . esc_attr( wp_create_nonce( 'wpac_register' ) ) . '">' . wp_referer_field( false );
	}

	/**
	 * Notice instead of a form that could never be sent (group deleted, inactive or not
	 * approved on this page). Partner managers see the reason.
	 *
	 * @param string $reason Reason for partner managers.
	 * @return string HTML.
	 */
	private static function unavailable( $reason ) {
		$text = __( 'Applications via this form are currently not possible.', 'affiliate-captain' );
		if ( current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			$text .= ' ' . $reason;
		}
		return '<div class="wpac-notice" role="status">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Shortcode output. Attribute group="ID" fixes the partner group; otherwise
	 * applicants choose one of the public groups (if any).
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Unused (shortcode content).
	 * @param string $tag     Unused (shortcode tag).
	 * @param int    $post_id Page that shows the form (block context); default: current post.
	 * @return string
	 */
	public static function render( $atts = array(), $content = null, $tag = '', $post_id = 0 ) {
		$atts = shortcode_atts( array( 'group' => 0 ), (array) $atts, self::SHORTCODE );
		// The form carries a nonce: never from a page cache, also where the page check before
		// output cannot see it (builder templates, template parts, synced patterns, widgets).
		\WPAffiliateCaptain\Promotion\Landing::no_cache();
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		\WPAffiliateCaptain\Core\Locale::enqueue_rtl( 'wpac-portal', 'portal' ); // R6-MS11.

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['wpac_confirm'] ) && is_string( $_GET['wpac_confirm'] ) ) {
			return self::render_confirm( sanitize_text_field( wp_unslash( $_GET['wpac_confirm'] ) ) );
		}
		if ( isset( $_GET['wpac_reg'] ) && 'confirm' === $_GET['wpac_reg'] ) {
			return '<div class="wpac-notice wpac-notice--success" role="status">' . esc_html__( 'Almost done! We have sent you an e-mail. Please confirm your e-mail address with the link in it to complete your application.', 'affiliate-captain' ) . '</div>';
		}
		if ( isset( $_GET['wpac_reg'] ) ) {
			return self::success_notice();
		}

		$user = wp_get_current_user();
		if ( $user->exists() ) {
			$aff = AffiliateRepository::find_by_user( $user->ID );
			if ( $aff ) {
				return self::account_notice( $aff );
			}
		}

		$errors = array(
			'nonce'   => __( 'The form expired. Please try again.', 'affiliate-captain' ),
			'spam'    => __( 'Your submission could not be processed. Please reload the page and send the form again; if this happens again, contact us directly.', 'affiliate-captain' ),
			'rate'    => __( 'Too many attempts. Please try again later.', 'affiliate-captain' ),
			'terms'   => __( 'Please accept the partner program terms.', 'affiliate-captain' ),
			'exists'  => __( 'An affiliate account with this e-mail address already exists.', 'affiliate-captain' ),
			'required' => __( 'Please fill in all required fields.', 'affiliate-captain' ),
			'expired'  => __( 'The confirmation link is invalid or has expired. Please apply again.', 'affiliate-captain' ),
			'invalid' => __( 'Please check your entries.', 'affiliate-captain' ),
			'error'   => __( 'Your application could not be saved. Please try again later.', 'affiliate-captain' ),
		);
		$group_id = absint( $atts['group'] );
		$fixed    = (bool) $group_id;
		$post_id  = $post_id ? (int) $post_id : (int) get_the_ID();
		$key      = self::form_key( $group_id, $post_id );
		// Messages and kept entries belong to the form that was sent (several forms on a page).
		$mine = ! isset( $_GET['wpac_fi'] ) || sanitize_key( wp_unslash( $_GET['wpac_fi'] ) ) === $key;
		$err  = $mine && isset( $_GET['wpac_err'] ) ? sanitize_key( wp_unslash( $_GET['wpac_err'] ) ) : '';
		$form = $mine ? self::kept_entries( isset( $_GET['wpac_form'] ) && is_string( $_GET['wpac_form'] ) ? sanitize_text_field( wp_unslash( $_GET['wpac_form'] ) ) : '' ) : array();
		$form = is_array( $form ) ? $form : array();
		$kept = static function ( $name ) use ( $form ) {
			return isset( $form['values'][ $name ] ) ? (string) $form['values'][ $name ] : '';
		};
		// phpcs:enable

		if ( ! $group_id && isset( $_GET['wpac_group'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$group_id = absint( $_GET['wpac_group'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$group = $group_id ? Groups::find( $group_id ) : null;
		if ( $fixed ) {
			// A form that could never be sent is not shown (and does not silently fall back to the group choice).
			$reason = self::fixed_group_problem( $group, $group_id, $post_id );
			if ( '' !== $reason ) {
				return self::unavailable( $reason );
			}
		} elseif ( $group && ( ! (int) $group->public || 'active' !== $group->status ) ) {
			$group    = null;
			$group_id = 0;
		}
		$public = Groups::public_groups();
		if ( ! $group && $public ) {
			// Step 1: choose the partner type.
			$out = '<div class="wpac-form"><p>' . esc_html__( 'Which kind of partner are you?', 'affiliate-captain' ) . '</p><ul class="wpac-group-choice">';
			foreach ( $public as $g ) {
				$out .= '<li><a class="wpac-button" href="' . esc_url( add_query_arg( 'wpac_group', (int) $g->id ) ) . '">' . esc_html( $g->name ) . '</a>' . ( $g->description ? '<br><span class="wpac-muted">' . esc_html( $g->description ) . '</span>' : '' ) . '</li>';
			}
			return $out . '</ul></div>';
		}
		$terms = Agreements::current( $group_id );
		++self::$instances;
		self::$suffix = 1 === self::$instances ? '' : '-' . self::$instances;
		$sfx          = self::$suffix;

		ob_start();
		?>
		<form class="wpac-form" method="post">
			<?php if ( $err && isset( $errors[ $err ] ) ) : ?>
				<div class="wpac-notice wpac-notice--error" role="alert" id="wpac-form-error<?php echo esc_attr( $sfx ); ?>"><?php echo esc_html( $errors[ $err ] ); ?><?php echo self::error_extras( $err, $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
			<?php endif; ?>
			<?php echo self::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<input type="hidden" name="wpac_register" value="1">
			<input type="hidden" name="wpac_form_id" value="<?php echo esc_attr( $key ); ?>">
			<p class="wpac-required-note"><?php esc_html_e( 'Fields marked with * are required.', 'affiliate-captain' ); ?></p>
			<input type="hidden" name="wpac_group" value="<?php echo (int) $group_id; ?>">
			<?php if ( $fixed ) : ?>
				<input type="hidden" name="wpac_group_post" value="<?php echo (int) $post_id; ?>">
				<?php if ( ! (int) $group->public ) : ?>
				<input type="hidden" name="wpac_group_sig" value="<?php echo esc_attr( self::group_sig( $group_id, get_post( $post_id ) ) ); ?>">
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $group ) : ?>
				<p class="wpac-muted"><?php echo esc_html( sprintf( /* translators: %s: group */ __( 'Application as: %s', 'affiliate-captain' ), $group->name ) ); ?>
				<?php if ( ! $fixed && $public ) : ?>
					<a href="<?php echo esc_url( remove_query_arg( array( 'wpac_group', 'wpac_err', 'wpac_form', 'wpac_fi' ) ) ); ?>"><?php esc_html_e( 'Choose another partner type', 'affiliate-captain' ); ?></a>
				<?php endif; ?>
				</p>
			<?php endif; ?>
			<p class="wpac-hp" aria-hidden="true"><label>URL <input type="text" name="wpac_url_confirm" tabindex="-1" autocomplete="off"></label></p>
			<p>
				<label for="wpac_name<?php echo esc_attr( $sfx ); ?>"><?php esc_html_e( 'Name / company', 'affiliate-captain' ); ?> *</label>
				<input type="text" id="wpac_name<?php echo esc_attr( $sfx ); ?>" name="wpac_name" required autocomplete="name" value="<?php echo esc_attr( '' !== $kept( 'wpac_name' ) ? $kept( 'wpac_name' ) : ( $user->exists() ? $user->display_name : '' ) ); ?>"<?php echo self::attrs( $form, 'wpac_name', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php echo self::field_error( $form, 'wpac_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</p>
			<?php if ( ! $user->exists() ) : ?>
			<p>
				<label for="wpac_email<?php echo esc_attr( $sfx ); ?>"><?php esc_html_e( 'E-mail', 'affiliate-captain' ); ?> *</label>
				<input type="email" id="wpac_email<?php echo esc_attr( $sfx ); ?>" name="wpac_email" required autocomplete="email"<?php echo self::attrs( $form, 'wpac_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php echo self::field_error( $form, 'wpac_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</p>
			<?php endif; ?>
			<p>
				<label for="wpac_website<?php echo esc_attr( $sfx ); ?>"><?php esc_html_e( 'Website', 'affiliate-captain' ); ?></label>
				<input type="url" id="wpac_website<?php echo esc_attr( $sfx ); ?>" name="wpac_website" placeholder="https://" autocomplete="url"<?php echo self::attrs( $form, 'wpac_website' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<p>
				<label for="wpac_channel<?php echo esc_attr( $sfx ); ?>"><?php esc_html_e( 'Channel (e.g. Instagram, YouTube, newsletter, school)', 'affiliate-captain' ); ?></label>
				<input type="text" id="wpac_channel<?php echo esc_attr( $sfx ); ?>" name="wpac_channel"<?php echo self::attrs( $form, 'wpac_channel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<p>
				<label for="wpac_note<?php echo esc_attr( $sfx ); ?>"><?php esc_html_e( 'How do you want to promote us?', 'affiliate-captain' ); ?></label>
				<textarea id="wpac_note<?php echo esc_attr( $sfx ); ?>" name="wpac_note" rows="4"><?php echo esc_textarea( $kept( 'wpac_note' ) ); ?></textarea>
			</p>
			<?php foreach ( Groups::fields( $group ) as $field ) : ?>
				<?php
				$fid      = 'wpac_f_' . $field['key'];
				$wpac_fel = $fid . $sfx;
				?>
				<p class="<?php echo 'checkbox' === $field['type'] ? 'wpac-check' : ''; ?>">
				<?php if ( 'checkbox' === $field['type'] ) : ?>
					<label><input type="checkbox" id="<?php echo esc_attr( $wpac_fel ); ?>" name="<?php echo esc_attr( $fid ); ?>" value="1" <?php echo $field['required'] ? 'required' : ''; ?> <?php checked( '1', $kept( $fid ) ); ?><?php echo self::attrs( $form, $fid, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>> <?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' *' : ''; ?></label>
				<?php else : ?>
					<label for="<?php echo esc_attr( $wpac_fel ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' *' : ''; ?></label>
					<?php if ( 'textarea' === $field['type'] ) : ?>
						<textarea id="<?php echo esc_attr( $wpac_fel ); ?>" name="<?php echo esc_attr( $fid ); ?>" rows="3" <?php echo $field['required'] ? 'required' : ''; ?><?php echo self::attrs( $form, $fid, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $kept( $fid ) ); ?></textarea>
					<?php else : ?>
						<input type="<?php echo esc_attr( 'url' === $field['type'] ? 'url' : ( 'number' === $field['type'] ? 'number' : 'text' ) ); ?>" id="<?php echo esc_attr( $wpac_fel ); ?>" name="<?php echo esc_attr( $fid ); ?>" <?php echo $field['required'] ? 'required' : ''; ?><?php echo self::attrs( $form, $fid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php endif; ?>
				<?php endif; ?>
				<?php echo self::field_error( $form, $fid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</p>
			<?php endforeach; ?>
			<?php if ( $terms['page_id'] ) : ?>
			<p class="wpac-check">
				<label>
					<input type="checkbox" id="wpac_terms<?php echo esc_attr( $sfx ); ?>" name="wpac_terms" value="1" required<?php echo self::attrs( $form, 'wpac_terms', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php
					printf(
						/* translators: 1: link to terms, 2: version */
						esc_html__( 'I accept the %1$s (version %2$s).', 'affiliate-captain' ),
						'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'affiliate-captain' ) . '<span class="wpac-sr"> ' . esc_html__( '(opens in a new tab)', 'affiliate-captain' ) . '</span></a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $terms['version'] )
					);
					?>
				</label>
				<?php echo self::field_error( $form, 'wpac_terms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</p>
			<?php endif; ?>
			<p class="wpac-muted wpac-privacy-note">
				<?php
				// Information at collection (Art. 13 GDPR): purpose, legal basis, storage, link to the policy.
				$wpac_privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
				echo esc_html__( 'We use your details to review your application and, if approved, to run the partner program with you (contract, Art. 6(1)(b) GDPR). Rejected applications are deleted after the period stated in our privacy policy.', 'affiliate-captain' );
				if ( $wpac_privacy ) {
					echo ' <a href="' . esc_url( $wpac_privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'affiliate-captain' ) . '<span class="wpac-sr"> ' . esc_html__( '(opens in a new tab)', 'affiliate-captain' ) . '</span></a>';
				}
				?>
			</p>
			<p><button type="submit" class="wpac-button"><?php esc_html_e( 'Apply now', 'affiliate-captain' ); ?></button></p>
		</form>
		<?php
		self::$suffix = '';
		return (string) ob_get_clean();
	}

	/**
	 * Notice for a logged-in user who already has a partner account, by its status (R14-U7):
	 * an application under review or a rejected one is not "a partner account", and a rejected
	 * applicant gets no link to a partner area they cannot use.
	 *
	 * @param object $aff Partner.
	 * @return string HTML.
	 */
	public static function account_notice( $aff ) {
		$portal = (int) Settings::get( 'portal_page_id' ) ? ' <a href="' . esc_url( Notifications::portal_url() ) . '">' . esc_html__( 'Go to partner area', 'affiliate-captain' ) . '</a>' : '';
		$admin  = (string) Settings::get( 'admin_email' );
		$admin  = is_email( $admin ) ? $admin : (string) get_option( 'admin_email' );
		/* translators: %s: e-mail link */
		$contact = ' ' . sprintf( esc_html__( 'Questions? Contact us at %s.', 'affiliate-captain' ), '<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>' );
		switch ( (string) $aff->status ) {
			case 'pending':
				return '<div class="wpac-notice" role="status">' . esc_html__( 'Your application is being reviewed. We will notify you by e-mail.', 'affiliate-captain' ) . '</div>';
			case 'rejected':
				return '<div class="wpac-notice">' . esc_html__( 'Your application was not accepted.', 'affiliate-captain' ) . $contact . '</div>';
			case 'terminated':
				return '<div class="wpac-notice">' . esc_html__( 'Your partnership has ended. You can still see your commissions and payouts and download your credit notes.', 'affiliate-captain' ) . $portal . $contact . '</div>';
			default:
				return '<div class="wpac-notice">' . esc_html__( 'You already have a partner account.', 'affiliate-captain' ) . $portal . '</div>';
		}
	}
}
