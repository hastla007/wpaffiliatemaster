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
	}

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
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, (int) $max ) : substr( (string) $value, 0, (int) $max );
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
		$redirect = remove_query_arg( array( 'wpac_reg', 'wpac_err' ), $redirect );

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
			if ( is_string( $v ) && 0 === strpos( (string) $k, 'wpac_' ) && strlen( (string) $k ) <= 64 && ! in_array( $k, array( 'wpac_register', 'wpac_url_confirm', 'wpac_group_sig', 'wpac_group_post' ), true ) ) {
				self::$values[ sanitize_key( $k ) ] = mb_substr( sanitize_textarea_field( wp_unslash( $v ) ), 0, 2000 );
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

		$application_data = array();
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
				self::$field = 'wpac_f_' . $field['key'];
				self::back( $redirect, 'required' );
			}
			$application_data[ $field['key'] ] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		$terms = Agreements::current( $group_id );
		if ( $terms['page_id'] && empty( $_POST['wpac_terms'] ) ) {
			self::$field = 'wpac_terms';
			self::back( $redirect, 'terms' );
		}
		if ( $terms['page_id'] ) {
			// The text accepted now is kept even if the page changes before the e-mail confirmation.
			Agreements::store_text( $terms );
		}

		$user  = wp_get_current_user();
		$email = $user && $user->exists() ? $user->user_email : ( isset( $_POST['wpac_email'] ) && is_string( $_POST['wpac_email'] ) ? sanitize_email( wp_unslash( $_POST['wpac_email'] ) ) : '' );
		if ( strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) {
			self::$field = 'wpac_email';
			self::back( $redirect, 'invalid' );
		}
		if ( $user && $user->exists() && AffiliateRepository::find_by_user( $user->ID ) ) {
			self::back( $redirect, 'exists' );
		}

		$data = array(
			'user_id'          => $user && $user->exists() ? $user->ID : 0,
			// Bounded lengths: the data waits in a transient (wp_options) until it is confirmed.
			'name'             => isset( $_POST['wpac_name'] ) && is_string( $_POST['wpac_name'] ) ? self::cap( sanitize_text_field( wp_unslash( $_POST['wpac_name'] ) ), self::MAX_NAME ) : '',
			'email'            => $email,
			'website'          => isset( $_POST['wpac_website'] ) && is_string( $_POST['wpac_website'] ) ? esc_url_raw( self::cap( wp_unslash( $_POST['wpac_website'] ), self::MAX_URL ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw().
			'channel'          => isset( $_POST['wpac_channel'] ) && is_string( $_POST['wpac_channel'] ) ? self::cap( sanitize_text_field( wp_unslash( $_POST['wpac_channel'] ) ), self::MAX_CHANNEL ) : '',
			'application_note' => isset( $_POST['wpac_note'] ) && is_string( $_POST['wpac_note'] ) ? self::cap( sanitize_textarea_field( wp_unslash( $_POST['wpac_note'] ) ), self::MAX_NOTE ) : '',
			'application_data' => $application_data,
			'group_id'         => $group_id,
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
			set_transient( 'wpac_app_' . hash( 'sha256', $token ), $data, 2 * DAY_IN_SECONDS );
			Notifications::confirm_application( $email, add_query_arg( 'wpac_confirm', rawurlencode( $token ), $redirect ) );
		}
		self::back( $redirect, '', 'confirm' );
	}

	/**
	 * Hash of a confirmation token (transient key part).
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
		if ( is_array( get_transient( 'wpac_app_' . $hash ) ) ) {
			return 'pending';
		}
		return get_transient( 'wpac_appdone_' . $hash ) ? 'done' : 'expired';
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
		$key   = 'wpac_app_' . $hash;
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
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			Db::unlock( 'wpac_appconf_' . substr( $hash, 0, 32 ), $lock );
			self::back( $back, get_transient( 'wpac_appdone_' . $hash ) ? '' : 'expired', 'ok' );
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
			set_transient( 'wpac_appdone_' . $hash, 1, 2 * DAY_IN_SECONDS );
		}
		delete_transient( $key );
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
			return '<div class="wpac-notice wpac-notice--error" role="alert">' . esc_html__( 'The confirmation link is invalid or has expired. Please apply again.', 'wp-affiliate-captain' ) . '</div>';
		}
		$data = get_transient( 'wpac_app_' . self::token_hash( $token ) );
		$data = is_array( $data ) ? $data : array();
		if ( ! self::account_matches( $data ) ) {
			$here = add_query_arg( 'wpac_confirm', rawurlencode( $token ), get_permalink() ? get_permalink() : home_url( '/' ) );
			return '<div class="wpac-notice">' . esc_html__( 'You applied while logged in. Please log in with the same user account to confirm your application.', 'wp-affiliate-captain' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Log in', 'wp-affiliate-captain' ) . '</a></div>';
		}
		return '<form class="wpac-form" method="post">'
			. '<p>' . esc_html__( 'Please confirm your partner application with the button below.', 'wp-affiliate-captain' ) . '</p>'
			. wp_referer_field( false )
			. '<input type="hidden" name="wpac_confirm_app" value="1">'
			. '<input type="hidden" name="wpac_confirm" value="' . esc_attr( $token ) . '">'
			. '<p><button type="submit" class="wpac-button">' . esc_html__( 'Confirm application', 'wp-affiliate-captain' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Success message after the confirmation.
	 *
	 * @return string HTML.
	 */
	private static function success_notice() {
		$msg = Settings::get( 'auto_approve' )
			? __( 'Thank you! Your partner account is active. Check your e-mail for login details.', 'wp-affiliate-captain' )
			: __( 'Thank you for your application! We will review it and get back to you by e-mail.', 'wp-affiliate-captain' );
		return '<div class="wpac-notice wpac-notice--success" role="status">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * Create the application (after verification).
	 *
	 * @param array $data Application data.
	 * @return int|\WP_Error
	 */
	private static function finalize( array $data ) {
		$terms  = isset( $data['terms'] ) ? $data['terms'] : null;
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
				'status'           => Settings::get( 'auto_approve' ) ? 'active' : 'pending',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $terms ) {
			Agreements::record( $result, 'accepted', $terms );
		}
		if ( ! Settings::get( 'auto_approve' ) ) {
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
		$url = remove_query_arg( array( 'wpac_reg', 'wpac_err', 'wpac_confirm', 'wpac_form' ), $url );
		if ( '' !== $code && self::$values ) {
			$token = wp_generate_password( 20, false );
			set_transient(
				'wpac_form_' . $token,
				array(
					'values' => self::$values,
					'field'  => self::$field,
				),
				15 * MINUTE_IN_SECONDS
			);
			$url = add_query_arg( 'wpac_form', $token, $url );
		}
		wp_safe_redirect( '' !== $code ? add_query_arg( 'wpac_err', $code, $url ) : add_query_arg( 'wpac_reg', $success, $url ) );
		exit;
	}

	/** @var array Submitted values (kept for correction). */
	private static $values = array();

	/** @var string Field with the error. */
	private static $field = '';

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
		if ( isset( $form['field'] ) && $form['field'] === $name ) {
			$out .= ' aria-invalid="true" aria-describedby="wpac-form-error" autofocus';
		}
		return $out;
	}

	/**
	 * Signature for a group fixed via shortcode attribute.
	 *
	 * @param int $group_id Group.
	 * @return string
	 */
	private static function group_sig( $group_id, $post_id ) {
		return wp_hash( 'wpac_group_' . (int) $group_id . '_' . (int) $post_id );
	}

	/**
	 * A non-public group is only accepted from a published page of a partner manager
	 * (a preview of a draft or a post by an author cannot be used to join a private group).
	 *
	 * @param int    $group_id Group.
	 * @param int    $post_id  Page that showed the form.
	 * @param string $sig      Signature.
	 * @return bool
	 */
	private static function fixed_group_valid( $group_id, $post_id, $sig ) {
		if ( ! $post_id || ! hash_equals( self::group_sig( $group_id, $post_id ), (string) $sig ) ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		// Only pages whose author manages partners (or the configured application page) may open a
		// private group: an author could otherwise publish the shortcode with any group. No content
		// check, so the form also works from widgets, patterns and templates on such pages.
		return (int) $post->ID === (int) Settings::get( 'registration_page_id' ) || user_can( (int) $post->post_author, Capabilities::MANAGE_AFFILIATES );
	}

	/**
	 * Shortcode output. Attribute group="ID" fixes the partner group; otherwise
	 * applicants choose one of the public groups (if any).
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'group' => 0 ), (array) $atts, self::SHORTCODE );
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['wpac_confirm'] ) && is_string( $_GET['wpac_confirm'] ) ) {
			return self::render_confirm( sanitize_text_field( wp_unslash( $_GET['wpac_confirm'] ) ) );
		}
		if ( isset( $_GET['wpac_reg'] ) && 'confirm' === $_GET['wpac_reg'] ) {
			return '<div class="wpac-notice wpac-notice--success" role="status">' . esc_html__( 'Almost done! We have sent you an e-mail. Please confirm your e-mail address with the link in it to complete your application.', 'wp-affiliate-captain' ) . '</div>';
		}
		if ( isset( $_GET['wpac_reg'] ) ) {
			return self::success_notice();
		}

		$user = wp_get_current_user();
		if ( $user->exists() ) {
			$aff = AffiliateRepository::find_by_user( $user->ID );
			if ( $aff ) {
				$portal = (int) Settings::get( 'portal_page_id' );
				$link   = $portal ? ' <a href="' . esc_url( Notifications::portal_url() ) . '">' . esc_html__( 'Go to partner area', 'wp-affiliate-captain' ) . '</a>' : '';
				return '<div class="wpac-notice">' . esc_html__( 'You already have a partner account.', 'wp-affiliate-captain' ) . $link . '</div>';
			}
		}

		$errors = array(
			'nonce'   => __( 'The form expired. Please try again.', 'wp-affiliate-captain' ),
			'spam'    => __( 'Your submission could not be processed.', 'wp-affiliate-captain' ),
			'rate'    => __( 'Too many attempts. Please try again later.', 'wp-affiliate-captain' ),
			'terms'   => __( 'Please accept the partner program terms.', 'wp-affiliate-captain' ),
			'exists'  => __( 'An affiliate account with this e-mail address already exists.', 'wp-affiliate-captain' ),
			'required' => __( 'Please fill in all required fields.', 'wp-affiliate-captain' ),
			'expired'  => __( 'The confirmation link is invalid or has expired. Please apply again.', 'wp-affiliate-captain' ),
			'invalid' => __( 'Please check your entries.', 'wp-affiliate-captain' ),
		);
		$err    = isset( $_GET['wpac_err'] ) ? sanitize_key( wp_unslash( $_GET['wpac_err'] ) ) : '';
		$form   = isset( $_GET['wpac_form'] ) ? get_transient( 'wpac_form_' . sanitize_text_field( wp_unslash( $_GET['wpac_form'] ) ) ) : array();
		$form   = is_array( $form ) ? $form : array();
		$kept   = static function ( $name ) use ( $form ) {
			return isset( $form['values'][ $name ] ) ? (string) $form['values'][ $name ] : '';
		};
		// phpcs:enable

		$group_id = absint( $atts['group'] );
		$fixed    = (bool) $group_id;
		if ( ! $group_id && isset( $_GET['wpac_group'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$group_id = absint( $_GET['wpac_group'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$group = $group_id ? Groups::find( $group_id ) : null;
		if ( $group && ! $fixed && ! (int) $group->public ) {
			$group    = null;
			$group_id = 0;
		}
		$public = Groups::public_groups();
		if ( ! $group && $public ) {
			// Step 1: choose the partner type.
			$out = '<div class="wpac-form"><p>' . esc_html__( 'Which kind of partner are you?', 'wp-affiliate-captain' ) . '</p><ul class="wpac-group-choice">';
			foreach ( $public as $g ) {
				$out .= '<li><a class="wpac-button" href="' . esc_url( add_query_arg( 'wpac_group', (int) $g->id ) ) . '">' . esc_html( $g->name ) . '</a>' . ( $g->description ? '<br><span class="wpac-muted">' . esc_html( $g->description ) . '</span>' : '' ) . '</li>';
			}
			return $out . '</ul></div>';
		}
		$terms = Agreements::current( $group_id );

		ob_start();
		?>
		<form class="wpac-form" method="post">
			<?php if ( $err && isset( $errors[ $err ] ) ) : ?>
				<div class="wpac-notice wpac-notice--error" role="alert" id="wpac-form-error"><?php echo esc_html( $errors[ $err ] ); ?></div>
			<?php endif; ?>
			<?php wp_nonce_field( 'wpac_register', '_wpac_nonce' ); ?>
			<input type="hidden" name="wpac_register" value="1">
			<p class="wpac-required-note"><?php esc_html_e( 'Fields marked with * are required.', 'wp-affiliate-captain' ); ?></p>
			<input type="hidden" name="wpac_group" value="<?php echo (int) $group_id; ?>">
			<?php if ( $fixed ) : ?>
				<?php $wpac_post = (int) get_the_ID(); ?>
				<input type="hidden" name="wpac_group_post" value="<?php echo (int) $wpac_post; ?>">
				<input type="hidden" name="wpac_group_sig" value="<?php echo esc_attr( self::group_sig( $group_id, $wpac_post ) ); ?>">
			<?php endif; ?>
			<?php if ( $group ) : ?>
				<p class="wpac-muted"><?php echo esc_html( sprintf( /* translators: %s: group */ __( 'Application as: %s', 'wp-affiliate-captain' ), $group->name ) ); ?></p>
			<?php endif; ?>
			<p class="wpac-hp" aria-hidden="true"><label>URL <input type="text" name="wpac_url_confirm" tabindex="-1" autocomplete="off"></label></p>
			<p>
				<label for="wpac_name"><?php esc_html_e( 'Name / company', 'wp-affiliate-captain' ); ?> *</label>
				<input type="text" id="wpac_name" name="wpac_name" required autocomplete="name" value="<?php echo esc_attr( '' !== $kept( 'wpac_name' ) ? $kept( 'wpac_name' ) : ( $user->exists() ? $user->display_name : '' ) ); ?>"<?php echo self::attrs( $form, 'wpac_name', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<?php if ( ! $user->exists() ) : ?>
			<p>
				<label for="wpac_email"><?php esc_html_e( 'E-mail', 'wp-affiliate-captain' ); ?> *</label>
				<input type="email" id="wpac_email" name="wpac_email" required autocomplete="email"<?php echo self::attrs( $form, 'wpac_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<?php endif; ?>
			<p>
				<label for="wpac_website"><?php esc_html_e( 'Website', 'wp-affiliate-captain' ); ?></label>
				<input type="url" id="wpac_website" name="wpac_website" placeholder="https://" autocomplete="url"<?php echo self::attrs( $form, 'wpac_website' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<p>
				<label for="wpac_channel"><?php esc_html_e( 'Channel (e.g. Instagram, YouTube, newsletter, school)', 'wp-affiliate-captain' ); ?></label>
				<input type="text" id="wpac_channel" name="wpac_channel"<?php echo self::attrs( $form, 'wpac_channel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			</p>
			<p>
				<label for="wpac_note"><?php esc_html_e( 'How do you want to promote us?', 'wp-affiliate-captain' ); ?></label>
				<textarea id="wpac_note" name="wpac_note" rows="4"><?php echo esc_textarea( $kept( 'wpac_note' ) ); ?></textarea>
			</p>
			<?php foreach ( Groups::fields( $group ) as $field ) : ?>
				<?php $fid = 'wpac_f_' . $field['key']; ?>
				<p class="<?php echo 'checkbox' === $field['type'] ? 'wpac-check' : ''; ?>">
				<?php if ( 'checkbox' === $field['type'] ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $fid ); ?>" value="1" <?php echo $field['required'] ? 'required' : ''; ?> <?php checked( '1', $kept( $fid ) ); ?><?php echo self::attrs( $form, $fid, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>> <?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' *' : ''; ?></label>
				<?php else : ?>
					<label for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' *' : ''; ?></label>
					<?php if ( 'textarea' === $field['type'] ) : ?>
						<textarea id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $fid ); ?>" rows="3" <?php echo $field['required'] ? 'required' : ''; ?><?php echo self::attrs( $form, $fid, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $kept( $fid ) ); ?></textarea>
					<?php else : ?>
						<input type="<?php echo esc_attr( 'url' === $field['type'] ? 'url' : ( 'number' === $field['type'] ? 'number' : 'text' ) ); ?>" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $fid ); ?>" <?php echo $field['required'] ? 'required' : ''; ?><?php echo self::attrs( $form, $fid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php endif; ?>
				<?php endif; ?>
				</p>
			<?php endforeach; ?>
			<?php if ( $terms['page_id'] ) : ?>
			<p class="wpac-check">
				<label>
					<input type="checkbox" name="wpac_terms" value="1" required<?php echo self::attrs( $form, 'wpac_terms', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php
					printf(
						/* translators: 1: link to terms, 2: version */
						esc_html__( 'I accept the %1$s (version %2$s).', 'wp-affiliate-captain' ),
						'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'wp-affiliate-captain' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $terms['version'] )
					);
					?>
				</label>
			</p>
			<?php endif; ?>
			<p class="wpac-muted wpac-privacy-note">
				<?php
				// Information at collection (Art. 13 GDPR): purpose, legal basis, storage, link to the policy.
				$wpac_privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
				echo esc_html__( 'We use your details to review your application and, if approved, to run the partner program with you (contract, Art. 6(1)(b) GDPR). Rejected applications are deleted after the period stated in our privacy policy.', 'wp-affiliate-captain' );
				if ( $wpac_privacy ) {
					echo ' <a href="' . esc_url( $wpac_privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'wp-affiliate-captain' ) . '</a>';
				}
				?>
			</p>
			<p><button type="submit" class="wpac-button"><?php esc_html_e( 'Apply now', 'wp-affiliate-captain' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
