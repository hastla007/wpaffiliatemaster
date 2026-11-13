<?php
/**
 * Affiliate portal: [wpac_affiliate_portal].
 *
 * Shows only minimized data: order reference, date, product, amount and status.
 * No customer names, e-mail addresses or other customer data.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Portal;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Tracking\RateLimiter;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Iban;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Promotion\Creatives;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Promotion\Qr;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Portal {

	const SHORTCODE = 'wpac_affiliate_portal';

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_post' ) );
		add_action( 'init', array( __CLASS__, 'statement_download' ) );
		add_action( 'init', array( __CLASS__, 'confirm_email' ) );
		add_action( 'init', array( __CLASS__, 'confirm_account_link' ) );
		add_action( 'init', array( __CLASS__, 'annual_download' ) );
	}

	/**
	 * Connect the partner to the existing WordPress account (link from the mail; must be logged in to that account).
	 */
	public static function confirm_account_link() {
		if ( empty( $_GET['wpac_link_account'] ) || empty( $_GET['wpac_aff'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return; // Logged out: the page shows the login form, the link works after logging in.
		}
		$ok   = AffiliateRepository::confirm_user_link( absint( $_GET['wpac_aff'] ), sanitize_key( wp_unslash( $_GET['wpac_link_account'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back = remove_query_arg( array( 'wpac_link_account', 'wpac_aff' ) );
		wp_safe_redirect( add_query_arg( 'wpac_msg', $ok ? 'account_linked' : 'email_link_invalid', $back ) );
		exit;
	}

	/**
	 * Confirm a new contact e-mail (link from the confirmation mail; the partner must be logged in).
	 */
	public static function confirm_email() {
		if ( empty( $_GET['wpac_email_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['wpac_email_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aff   = self::current();
		$back  = remove_query_arg( 'wpac_email_confirm' );
		if ( ! $aff ) {
			return; // The portal shows the login form; the link works after logging in.
		}
		$pending = get_transient( 'wpac_email_change_' . (int) $aff->id );
		$msg     = 'email_link_invalid';
		if ( is_array( $pending ) && hash_equals( (string) $pending['token'], $token ) ) {
			$result = AffiliateRepository::set_email( (int) $aff->id, (string) $pending['email'] );
			delete_transient( 'wpac_email_change_' . (int) $aff->id );
			$msg = is_wp_error( $result ) ? $result->get_error_code() : 'email_changed';
		}
		wp_safe_redirect( add_query_arg( array( 'wpac_msg' => $msg, 'wpac_tab' => 'account' ), $back ) );
		exit;
	}

	/**
	 * Annual overview download (own payouts only).
	 */
	public static function annual_download() {
		if ( empty( $_GET['wpac_annual'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$year = absint( $_GET['wpac_annual'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wpac_annual_' . $year ) ) {
			wp_die( esc_html__( 'The link has expired.', 'wp-affiliate-captain' ), 403 );
		}
		$aff = self::current();
		if ( ! $aff || $year < 2000 || $year > (int) gmdate( 'Y' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wp-affiliate-captain' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="annual-overview-' . $year . '.pdf"' );
		echo Statement::annual( (int) $aff->id, $year ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
		exit;
	}

	/**
	 * PDF statement download for the partner's own payouts.
	 */
	public static function statement_download() {
		if ( empty( $_GET['wpac_statement'] ) ) {
			return;
		}
		$id = absint( $_GET['wpac_statement'] );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wpac_statement_' . $id ) ) {
			wp_die( esc_html__( 'The link has expired.', 'wp-affiliate-captain' ), 403 );
		}
		$aff = self::current();
		$p   = PayoutService::find( $id );
		if ( ! $aff || ! $p || (int) $p->affiliate_id !== (int) $aff->id ) {
			wp_die( esc_html__( 'Not allowed.', 'wp-affiliate-captain' ), 403 );
		}
		if ( ! empty( $_GET['storno'] ) ) {
			Statement::send_storno( $p );
		}
		Statement::send( $p );
	}

	/**
	 * Current affiliate of the logged-in user.
	 *
	 * @return object|null
	 */
	private static function current() {
		return is_user_logged_in() ? AffiliateRepository::find_by_user( get_current_user_id() ) : null;
	}

	/**
	 * Profile and terms forms.
	 */
	public static function handle_post() {
		if ( empty( $_POST['wpac_portal_action'] ) || ! isset( $_POST['_wpac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_nonce'] ) ), 'wpac_portal' ) ) {
			return;
		}
		$aff = self::current();
		$action = sanitize_key( wp_unslash( $_POST['wpac_portal_action'] ) );
		if ( 'resend_link' === $action && ! $aff && is_user_logged_in() ) {
			// Logged-in customer whose e-mail belongs to a partner waiting for the account link.
			$partner = AffiliateRepository::find_by_email( wp_get_current_user()->user_email );
			$result  = $partner ? AffiliateRepository::resend_user_link( (int) $partner->id ) : new \WP_Error( 'wpac_none', '' );
			wp_safe_redirect( add_query_arg( 'wpac_msg', is_wp_error( $result ) ? ( 'wpac_rate_limited' === $result->get_error_code() ? 'link_wait' : 'link_failed' ) : 'link_sent', remove_query_arg( 'wpac_msg', wpac_return_url() ) ) );
			exit;
		}
		if ( Settings::get( 'maintenance_mode' ) && ! in_array( $action, array( 'privacy', 'objection' ), true ) ) {
			// Maintenance: no changes (privacy requests and objections to credit notes always work).
			wp_safe_redirect( add_query_arg( 'wpac_msg', 'maintenance', remove_query_arg( 'wpac_msg', wpac_return_url() ) ) );
			exit;
		}
		// Privacy requests and objections also work after the partnership ended.
		if ( ! $aff || ( ! in_array( $aff->status, array( 'active', 'paused' ), true ) && ! in_array( $action, array( 'privacy', 'objection' ), true ) ) ) {
			return;
		}
		$redirect = remove_query_arg( array( 'wpac_msg' ), wpac_return_url() );

		if ( 'account' === $action ) {
			// Name, website and channel directly; a new e-mail only after confirmation.
			// The channel is internal; name and website are public and wait for the operator's review.
			AffiliateRepository::update(
				(int) $aff->id,
				array(
					'channel' => isset( $_POST['wpac_acc_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_acc_channel'] ) ) : $aff->channel,
				)
			);
			$public = AffiliateRepository::request_public_change(
				(int) $aff->id,
				isset( $_POST['wpac_acc_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_acc_name'] ) ) : $aff->name,
				isset( $_POST['wpac_acc_website'] ) ? esc_url_raw( wp_unslash( $_POST['wpac_acc_website'] ) ) : $aff->website
			);
			$msg   = 'pending' === $public ? 'change_review' : 'saved';
			$email = isset( $_POST['wpac_acc_email'] ) ? sanitize_email( wp_unslash( $_POST['wpac_acc_email'] ) ) : '';
			if ( '' !== $email && strtolower( $email ) !== strtolower( (string) $aff->email ) ) {
				$other = AffiliateRepository::find_by_email( $email );
				if ( ! is_email( $email ) ) {
					$msg = 'wpac_email';
				} elseif ( $other && (int) $other->id !== (int) $aff->id ) {
					$msg = 'wpac_email_taken';
				} elseif ( ! RateLimiter::allow( 'email_change_' . (int) $aff->id, 3, DAY_IN_SECONDS ) ) {
					$msg = 'email_change_limit';
				} else {
					$token = wp_generate_password( 32, false );
					set_transient( 'wpac_email_change_' . (int) $aff->id, array( 'email' => $email, 'token' => strtolower( $token ) ), DAY_IN_SECONDS );
					$link = add_query_arg( 'wpac_email_confirm', strtolower( $token ), add_query_arg( 'wpac_tab', 'account', wpac_return_url() ) );
					wp_mail(
						$email,
						/* translators: %s: site name */
						sprintf( __( '[%s] Please confirm your new e-mail address', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
						/* translators: %s: link */
						sprintf( __( "Hello,\n\nplease confirm your new e-mail address for the partner program (valid for 24 hours):\n%s\n\nIf you did not request this, ignore this e-mail.", 'wp-affiliate-captain' ), $link )
					);
					$msg = 'email_confirm_sent';
				}
			}
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => $msg, 'wpac_tab' => 'account' ), $redirect ) );
			exit;
		}

		if ( 'privacy' === $action ) {
			$type = isset( $_POST['wpac_privacy_type'] ) ? sanitize_key( wp_unslash( $_POST['wpac_privacy_type'] ) ) : '';
			$note = isset( $_POST['wpac_privacy_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpac_privacy_note'] ) ) : '';
			$msg  = \WPAffiliateCaptain\Privacy\Privacy::partner_request( $aff, $type, $note );
			if ( 'privacy_failed' === $msg ) {
				// Say what is missing and keep what the partner wrote.
				$msg = in_array( $type, array( 'restrict', 'object' ), true ) && '' === trim( $note ) ? 'privacy_note_missing' : $msg;
				set_transient(
					'wpac_privacy_form_' . get_current_user_id(),
					array(
						'type' => $type,
						'note' => substr( $note, 0, 2000 ),
					),
					15 * MINUTE_IN_SECONDS
				);
			}
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => $msg, 'wpac_tab' => 'privacy' ), $redirect ) );
			exit;
		}

		if ( 'objection' === $action ) {
			$payout = isset( $_POST['wpac_obj_payout'] ) ? absint( $_POST['wpac_obj_payout'] ) : 0;
			$reason = isset( $_POST['wpac_obj_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpac_obj_reason'] ) ) : '';
			$result = \WPAffiliateCaptain\Payouts\Objections::file( $payout, (int) $aff->id, $reason );
			if ( is_wp_error( $result ) ) {
				// The actual reason, and the text the partner wrote stays in the form.
				set_transient( 'wpac_portal_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
				set_transient(
					'wpac_objection_form_' . get_current_user_id(),
					array(
						'payout' => $payout,
						'reason' => substr( $reason, 0, 2000 ),
					),
					15 * MINUTE_IN_SECONDS
				);
			}
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => is_wp_error( $result ) ? 'objection_failed' : 'objection_sent', 'wpac_tab' => 'payouts' ), $redirect ) . '#wpac-objection' );
			exit;
		}

		if ( 'profile' === $action ) {
			$fields = array( 'tax_status', 'legal_name', 'address', 'country', 'vat_id', 'tax_number', 'account_holder', 'iban', 'bic' );
			$in     = array();
			foreach ( $fields as $f ) {
				$in[ $f ] = isset( $_POST[ 'wpac_' . $f ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'wpac_' . $f ] ) ) : '';
			}
			$result = ProfileRepository::save( (int) $aff->id, $in );
			$msg    = is_wp_error( $result ) ? $result->get_error_code() : 'saved';
			if ( is_wp_error( $result ) ) {
				// Keep what the partner typed (never the IBAN) and mark the field with the error.
				unset( $in['iban'] );
				$fields = array(
					'wpac_iban'       => 'iban',
					'wpac_vat'        => 'vat_id',
					'wpac_tax_number' => 'tax_number',
				);
				set_transient(
					'wpac_profile_form_' . get_current_user_id(),
					array(
						'values' => $in,
						'field'  => isset( $fields[ $msg ] ) ? $fields[ $msg ] : '',
					),
					15 * MINUTE_IN_SECONDS
				);
			}
			wp_safe_redirect( add_query_arg( 'wpac_msg', $msg, $redirect ) );
			exit;
		}
		if ( 'payout_request' === $action ) {
			$result = PayoutRequests::request( $aff );
			if ( is_wp_error( $result ) ) {
				set_transient( 'wpac_portal_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			}
			wp_safe_redirect( add_query_arg( 'wpac_msg', is_wp_error( $result ) ? 'request_failed' : 'requested', $redirect ) );
			exit;
		}
		if ( 'accept_terms' === $action && ! empty( $_POST['wpac_terms'] ) ) {
			// Only the version that was displayed can be accepted (it may have changed meanwhile).
			$terms = Agreements::current( (int) $aff->group_id );
			$shown = isset( $_POST['wpac_terms_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_terms_hash'] ) ) : '';
			if ( ! hash_equals( (string) $terms['hash'], $shown ) ) {
				wp_safe_redirect( add_query_arg( 'wpac_msg', 'terms_changed', $redirect ) );
				exit;
			}
			Agreements::record( (int) $aff->id, 'accepted', $terms );
			wp_safe_redirect( add_query_arg( 'wpac_msg', 'terms', $redirect ) );
			exit;
		}
	}

	/**
	 * Shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		wp_enqueue_script( 'wpac-portal', WPAC_URL . 'assets/js/portal.js', array(), WPAC_VERSION, true );
		wp_localize_script(
			'wpac-portal',
			'wpacPortal',
			array(
				'copied' => __( 'Copied to the clipboard.', 'wp-affiliate-captain' ),
				'failed' => __( 'Please copy the text manually.', 'wp-affiliate-captain' ),
			)
		);

		if ( ! is_user_logged_in() ) {
			$reg = (int) Settings::get( 'registration_page_id' );
			$out = '<div class="wpac-portal"><p>' . esc_html__( 'Please log in to access your partner area.', 'wp-affiliate-captain' ) . '</p>';
			$out .= wp_login_form( array( 'echo' => false ) );
			$out .= '<p><a href="' . esc_url( wp_lostpassword_url( get_permalink() ) ) . '">' . esc_html__( 'Forgot your password or no password set yet?', 'wp-affiliate-captain' ) . '</a></p>';
			if ( $reg ) {
				$out .= '<p><a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Not a partner yet? Apply now.', 'wp-affiliate-captain' ) . '</a></p>';
			}
			return $out . '</div>';
		}

		$aff = self::current();
		if ( ! $aff ) {
			$by_mail = AffiliateRepository::find_by_email( wp_get_current_user()->user_email );
			if ( $by_mail && ! (int) $by_mail->user_id && in_array( $by_mail->status, array( 'active', 'paused' ), true ) ) {
				// Approved partner with an existing customer account: the account is connected by the link mail.
				ob_start();
				echo '<div class="wpac-portal">';
				self::messages();
				echo '<div class="wpac-notice"><p>' . esc_html__( 'Your partner account is ready. Check your e-mail to link your partner account to this customer account: we have sent you a link (valid for 7 days). Open it while you are logged in.', 'wp-affiliate-captain' ) . '</p>';
				echo '<form method="post">';
				wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
				echo '<input type="hidden" name="wpac_portal_action" value="resend_link"><button type="submit" class="wpac-button">' . esc_html__( 'Send the link again', 'wp-affiliate-captain' ) . '</button></form></div></div>';
				return (string) ob_get_clean();
			}
			$reg = (int) Settings::get( 'registration_page_id' );
			return '<div class="wpac-notice">' . esc_html__( 'You do not have a partner account yet.', 'wp-affiliate-captain' ) . ( $reg ? ' <a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Apply now', 'wp-affiliate-captain' ) . '</a>' : '' ) . '</div>';
		}
		if ( 'pending' === $aff->status ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your application is being reviewed. We will notify you by e-mail.', 'wp-affiliate-captain' ) . '</div>';
		}
		$closed = in_array( $aff->status, array( 'rejected', 'terminated' ), true );
		$reason = 'privacy_erasure' !== (string) $aff->status_reason ? trim( (string) $aff->status_reason ) : '';
		$admin  = (string) Settings::get( 'admin_email' );
		$admin  = is_email( $admin ) ? $admin : (string) get_option( 'admin_email' );
		if ( 'rejected' === $aff->status ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your application was not accepted.', 'wp-affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'wp-affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . ' ' . sprintf( /* translators: %s: e-mail link */ esc_html__( 'Questions? Contact us at %s.', 'wp-affiliate-captain' ), '<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>' ) . '</div>';
		}

		$tabs = array(
			'overview'    => __( 'Overview', 'wp-affiliate-captain' ),
			'links'       => __( 'Links & codes', 'wp-affiliate-captain' ),
			'promotion'   => __( 'Creatives', 'wp-affiliate-captain' ),
			'commissions' => __( 'Commissions', 'wp-affiliate-captain' ),
			'payouts'     => __( 'Payouts', 'wp-affiliate-captain' ),
			'profile'     => __( 'Payout details', 'wp-affiliate-captain' ),
			'account'     => __( 'My account', 'wp-affiliate-captain' ),
			'privacy'     => __( 'Privacy', 'wp-affiliate-captain' ),
		);
		if ( $closed ) {
			// Terminated partners keep read access to commissions, payouts and their credit
			// notes (open commissions are still paid out; the documents are needed for taxes).
			$tabs = array_intersect_key( $tabs, array_flip( array( 'commissions', 'payouts', 'privacy' ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['wpac_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpac_tab'] ) ) : key( $tabs );
		$tab = isset( $tabs[ $tab ] ) ? $tab : key( $tabs );

		ob_start();
		echo '<div class="wpac-portal">';
		self::messages();
		if ( Settings::get( 'maintenance_mode' ) ) {
			echo '<div class="wpac-notice">' . esc_html__( 'The partner area is being maintained. You can look at everything, but changes are possible again shortly.', 'wp-affiliate-captain' ) . '</div>';
		}
		if ( 'paused' === $aff->status ) {
			echo '<div class="wpac-notice">' . esc_html__( 'Your partner account is paused. New sales are currently not attributed.', 'wp-affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'wp-affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . '</div>';
		}
		if ( $closed ) {
			echo '<div class="wpac-notice">' . esc_html__( 'Your partnership has ended. You can still see your commissions and payouts and download your credit notes.', 'wp-affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'wp-affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . ' ' . sprintf( /* translators: %s: e-mail link */ esc_html__( 'Questions? Contact us at %s.', 'wp-affiliate-captain' ), '<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>' ) . '</div>';
		}
		$terms = Agreements::current( (int) $aff->group_id );
		if ( ! $closed && $terms['page_id'] && ! Agreements::has_current( (int) $aff->id ) ) {
			self::terms_notice( $terms, (bool) Agreements::latest( (int) $aff->id ) );
		}
		echo '<nav class="wpac-tabs" aria-label="' . esc_attr__( 'Partner area', 'wp-affiliate-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( self::tab_url( $key ) ),
				$key === $tab ? 'is-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div class="wpac-tab"><p class="wpac-sr" aria-live="polite" id="wpac-live"></p>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $aff );
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * URL of a portal tab.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		return add_query_arg( 'wpac_tab', $tab, remove_query_arg( array( 'wpac_msg', 'target', 'campaign', 'wpac_page' ) ) );
	}

	/**
	 * Read-only text with label and copy button.
	 *
	 * @param string $value Value.
	 * @param string $label Accessible label.
	 * @param bool   $multi Multi-line.
	 * @return string
	 */
	private static function copy_field( $value, $label, $multi = false ) {
		$id = 'wpac-copy-' . substr( md5( $value . $label ), 0, 8 );
		$field = $multi
			? '<textarea readonly class="wpac-copy" rows="4" id="' . esc_attr( $id ) . '">' . esc_textarea( $value ) . '</textarea>'
			: '<input type="text" readonly class="wpac-copy" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '">';
		return '<label class="wpac-sr" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="wpac-copy-row">' . $field . '<button type="button" class="wpac-copy-btn" data-wpac-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'wp-affiliate-captain' ) . '</button></div>';
	}

	/**
	 * Empty state with a next step.
	 *
	 * @param string $text   Text.
	 * @param string $action Link text.
	 * @param string $tab    Target tab.
	 * @return string
	 */
	private static function empty_state( $text, $action = '', $tab = '' ) {
		return '<div class="wpac-empty"><p>' . esc_html( $text ) . '</p>' . ( $action ? '<p><a class="wpac-button" href="' . esc_url( self::tab_url( $tab ) ) . '">' . esc_html( $action ) . '</a></p>' : '' ) . '</div>';
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 * @param string $label  Label.
	 * @return string
	 */
	private static function status( $status, $label ) {
		return '<span class="wpac-status wpac-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Flash messages.
	 */
	private static function messages() {
		$messages = array(
			'saved'     => array( 'success', __( 'Your details have been saved.', 'wp-affiliate-captain' ) ),
			'requested' => array( 'success', __( 'Your payout request was sent.', 'wp-affiliate-captain' ) ),
			'request_failed' => array( 'error', __( 'The payout request could not be created.', 'wp-affiliate-captain' ) ),
			'terms'     => array( 'success', __( 'Thank you for accepting the terms.', 'wp-affiliate-captain' ) ),
			'terms_changed' => array( 'error', __( 'The terms were changed in the meantime. Please read the current version and accept it again.', 'wp-affiliate-captain' ) ),
			'wpac_iban' => array( 'error', __( 'The IBAN is not valid.', 'wp-affiliate-captain' ) ),
			'wpac_vat'  => array( 'error', __( 'Please enter your VAT ID.', 'wp-affiliate-captain' ) ),
			'wpac_tax_number' => array( 'error', __( 'Please enter your tax number or VAT ID (required on credit notes).', 'wp-affiliate-captain' ) ),
			'wpac_email'         => array( 'error', __( 'Please enter a valid e-mail address.', 'wp-affiliate-captain' ) ),
			'wpac_email_taken'   => array( 'error', __( 'This e-mail address is already used by another partner account.', 'wp-affiliate-captain' ) ),
			'email_confirm_sent' => array( 'success', __( 'Saved. We have sent a confirmation link to your new e-mail address; it is used once you confirm it.', 'wp-affiliate-captain' ) ),
			'email_changed'      => array( 'success', __( 'Your new e-mail address is confirmed.', 'wp-affiliate-captain' ) ),
			'email_link_invalid' => array( 'error', __( 'The confirmation link is invalid or has expired.', 'wp-affiliate-captain' ) ),
			'email_change_limit' => array( 'error', __( 'Too many e-mail changes today. Please try again tomorrow.', 'wp-affiliate-captain' ) ),
			'account_linked'     => array( 'success', __( 'Your customer account is now connected to the partner program.', 'wp-affiliate-captain' ) ),
			'privacy_confirm'    => array( 'success', __( 'Please confirm your request with the link we have sent you by e-mail.', 'wp-affiliate-captain' ) ),
			'privacy_sent'       => array( 'success', __( 'Your request was sent. We will get back to you within one month.', 'wp-affiliate-captain' ) ),
			'privacy_failed'     => array( 'error', __( 'The request could not be sent. Please try again later or write to us by e-mail.', 'wp-affiliate-captain' ) ),
			'privacy_note_missing' => array( 'error', __( 'For a restriction or an objection, please describe your reason in the details field.', 'wp-affiliate-captain' ) ),
			'objection_sent'     => array( 'success', __( 'Thank you. Your objection was sent; we will review the credit note.', 'wp-affiliate-captain' ) ),
			'objection_failed'   => array( 'error', __( 'The objection could not be filed.', 'wp-affiliate-captain' ) ),
			'link_sent'          => array( 'success', __( 'We have sent you a new link to connect your account. Please check your e-mail (also the spam folder).', 'wp-affiliate-captain' ) ),
			'link_wait'          => array( 'error', __( 'The link was sent a few minutes ago. Please check your e-mail or try again in 10 minutes.', 'wp-affiliate-captain' ) ),
			'link_failed'        => array( 'error', __( 'The link could not be sent. Please contact us.', 'wp-affiliate-captain' ) ),
			'maintenance'        => array( 'error', __( 'The partner area is being maintained; changes are possible again shortly.', 'wp-affiliate-captain' ) ),
			'change_review'      => array( 'success', __( 'Saved. Your new name or website is shown after our review.', 'wp-affiliate-captain' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['wpac_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpac_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			$text = $messages[ $key ][1];
			if ( in_array( $key, array( 'request_failed', 'objection_failed' ), true ) ) {
				// The actual reason (e.g. below the minimum, profile incomplete).
				$detail = get_transient( 'wpac_portal_error_' . get_current_user_id() );
				if ( $detail ) {
					$text .= ' ' . $detail;
					delete_transient( 'wpac_portal_error_' . get_current_user_id() );
				}
			}
			printf( '<div class="wpac-notice wpac-notice--%s" role="%s" id="wpac-portal-error">%s</div>', esc_attr( $messages[ $key ][0] ), 'error' === $messages[ $key ][0] ? 'alert' : 'status', esc_html( $text ) );
		}
	}

	/**
	 * Re-acceptance of changed terms.
	 *
	 * @param array $terms   Current terms of the partner's group.
	 * @param bool  $updated Whether an earlier version was accepted (else: first acceptance).
	 */
	private static function terms_notice( array $terms, $updated = true ) {
		echo '<form method="post" class="wpac-notice">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="accept_terms">';
		echo '<input type="hidden" name="wpac_terms_hash" value="' . esc_attr( $terms['hash'] ) . '">';
		echo '<p>' . esc_html( $updated ? __( 'Our partner program terms have been updated.', 'wp-affiliate-captain' ) : __( 'Welcome! Please read and accept our partner program terms. We need your acceptance to issue credit notes and pay out your commissions.', 'wp-affiliate-captain' ) ) . '</p>';
		echo '<label><input type="checkbox" name="wpac_terms" value="1" required> ';
		printf(
			/* translators: 1: link, 2: version */
			esc_html__( 'I accept the %1$s (version %2$s).', 'wp-affiliate-captain' ),
			'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'wp-affiliate-captain' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $terms['version'] )
		);
		echo '</label> <button type="submit" class="wpac-button">' . esc_html__( 'Accept', 'wp-affiliate-captain' ) . '</button></form>';
	}

	/**
	 * Overview tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_overview( $aff ) {
		global $wpdb;
		$currency = (string) Settings::get( 'currency' );
		$totals   = Ledger::totals( array( 'affiliate_id' => (int) $aff->id ) );
		$sum      = static function ( $status ) use ( $totals, $currency ) {
			return isset( $totals[ $status ][ $currency ] ) ? (int) $totals[ $status ][ $currency ] : 0;
		};
		$t     = Db::table( 'attributions' );
		$since = Db::now( time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conversions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE affiliate_id = %d AND created_at >= %s", $aff->id, $since ) );

		$cards = array(
			__( 'Link visits (30 days)', 'wp-affiliate-captain' )       => number_format_i18n( Tracker::count_visits( (int) $aff->id, 30 ) ),
			__( 'Sales (30 days)', 'wp-affiliate-captain' )             => number_format_i18n( $conversions ),
			__( 'Pending (hold period)', 'wp-affiliate-captain' )       => Money::format( $sum( 'pending' ) + $sum( 'review' ), $currency ),
			__( 'Approved, not yet paid', 'wp-affiliate-captain' )      => Money::format( $sum( 'approved' ), $currency ),
			__( 'Paid in total', 'wp-affiliate-captain' )               => Money::format( $sum( 'paid' ), $currency ),
			__( 'Minimum payout amount', 'wp-affiliate-captain' )       => Money::format( (int) Settings::get( 'min_payout_minor' ), $currency ),
		);
		$code    = CodeRepository::main_code( (int) $aff->id );
		$profile = ProfileRepository::get( (int) $aff->id );
		$terms   = Agreements::current( (int) $aff->group_id );
		$steps   = array(
			array( '' !== $code, __( 'Partner code received', 'wp-affiliate-captain' ), 'links' ),
			array( ! $terms['page_id'] || Agreements::has_current( (int) $aff->id ), __( 'Current terms accepted', 'wp-affiliate-captain' ), 'overview' ),
			array( ProfileRepository::is_complete( $profile ), __( 'Payout details completed', 'wp-affiliate-captain' ), 'profile' ),
			array( Tracker::has_visits( (int) $aff->id ), __( 'First visitor via your link', 'wp-affiliate-captain' ), 'promotion' ),
			array( $conversions > 0 || $sum( 'approved' ) + $sum( 'paid' ) > 0, __( 'First sale', 'wp-affiliate-captain' ), 'links' ),
		);
		echo '<section class="wpac-hero" aria-labelledby="wpac-hero-title"><h3 id="wpac-hero-title">' . esc_html__( 'Share your partner link', 'wp-affiliate-captain' ) . '</h3>';
		if ( $code ) {
			echo self::copy_field( add_query_arg( (string) Settings::get( 'referral_param' ), $code, home_url( '/' ) ), __( 'Your partner link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: %s: code */
			echo '<p>' . esc_html( sprintf( __( 'Or tell customers your code %s – they enter it at checkout, no cookies needed.', 'wp-affiliate-captain' ), $code ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Your partner code is being prepared. Please contact us if it does not appear soon.', 'wp-affiliate-captain' ) . '</p>';
		}
		$open = array_filter(
			$steps,
			static function ( $st ) {
				return ! $st[0];
			}
		);
		if ( $open ) {
			echo '<h4>' . esc_html__( 'Your next steps', 'wp-affiliate-captain' ) . '</h4><ul class="wpac-steps">';
			foreach ( $steps as $st ) {
				echo '<li class="' . ( $st[0] ? 'done' : 'todo' ) . '">' . ( $st[0] ? esc_html( $st[1] ) : '<a href="' . esc_url( self::tab_url( $st[2] ) ) . '">' . esc_html( $st[1] ) . '</a>' ) . '<span class="wpac-sr"> – ' . esc_html( $st[0] ? __( 'done', 'wp-affiliate-captain' ) : __( 'open', 'wp-affiliate-captain' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<div class="wpac-cards">';
		foreach ( $cards as $label => $value ) {
			printf( '<div class="wpac-card"><span class="wpac-card__label">%s</span><strong class="wpac-card__value">%s</strong></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
	}

	/**
	 * Links & codes tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_links( $aff ) {
		$codes = CodeRepository::for_affiliate( (int) $aff->id );
		$main  = CodeRepository::main_code( (int) $aff->id );
		$param = (string) Settings::get( 'referral_param' );

		if ( $main ) {
			$link = add_query_arg( $param, rawurlencode( $main ), home_url( '/' ) );
			echo '<h3>' . esc_html__( 'Your partner link', 'wp-affiliate-captain' ) . '</h3>';
			echo self::copy_field( $link, __( 'Your partner link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html__( 'Your partner code', 'wp-affiliate-captain' ) . ': <strong class="wpac-code">' . esc_html( $main ) . '</strong> ';
			echo '<span class="wpac-muted">' . esc_html__( 'Customers can enter it at checkout – it works without cookies.', 'wp-affiliate-captain' ) . '</span></p>';

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$target   = isset( $_GET['target'] ) ? esc_url_raw( wp_unslash( $_GET['target'] ) ) : '';
			$campaign = isset( $_GET['campaign'] ) ? sanitize_title( wp_unslash( $_GET['campaign'] ) ) : '';
			// phpcs:enable
			echo '<h3>' . esc_html__( 'Link generator', 'wp-affiliate-captain' ) . '</h3>';
			echo '<form method="get" class="wpac-form wpac-form--inline">';
			// Keep other query args (e.g. page_id on plain permalinks).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $k => $v ) {
				if ( ! in_array( $k, array( 'target', 'campaign' ), true ) && is_string( $v ) ) {
					echo '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '">';
				}
			}
			echo '<label>' . esc_html__( 'Target page on our website', 'wp-affiliate-captain' ) . ' <input type="url" name="target" value="' . esc_attr( $target ) . '" placeholder="' . esc_attr( home_url( '/' ) ) . '"></label> ';
			echo '<label>' . esc_html__( 'Campaign (optional)', 'wp-affiliate-captain' ) . ' <input type="text" name="campaign" value="' . esc_attr( $campaign ) . '"></label> ';
			echo '<button type="submit" class="wpac-button">' . esc_html__( 'Generate', 'wp-affiliate-captain' ) . '</button></form>';
			if ( $target ) {
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( wp_parse_url( $target, PHP_URL_HOST ) !== $home_host ) {
					echo '<div class="wpac-notice wpac-notice--error">' . esc_html__( 'Only pages of this website can be linked.', 'wp-affiliate-captain' ) . '</div>';
				} else {
					$args = array( $param => $main );
					if ( $campaign ) {
						$args['utm_campaign'] = $campaign;
						$args['utm_source']   = 'partner';
						$args['utm_medium']   = 'affiliate';
					}
					echo self::copy_field( add_query_arg( $args, $target ), __( 'Generated link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		}

		echo \WPAffiliateCaptain\Content\Editor::render_targets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		echo '<h3>' . esc_html__( 'Your codes', 'wp-affiliate-captain' ) . '</h3>';
		if ( ! $codes ) {
			echo '<p>' . esc_html__( 'No codes assigned yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html__( 'Code', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Coupon', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Used', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Valid until', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $codes as $c ) {
			$valid = (bool) CodeRepository::validate( $c );
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $c->code ),
				esc_html( $c->coupon_code ? strtoupper( $c->coupon_code ) : '—' ),
				esc_html( (int) $c->usage_count . ( (int) $c->usage_limit ? ' / ' . (int) $c->usage_limit : '' ) ),
				esc_html( $c->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $c->expires_at . ' UTC' ) ) : '—' ),
				esc_html( $valid ? __( 'Active', 'wp-affiliate-captain' ) : __( 'Inactive', 'wp-affiliate-captain' ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Creatives tab: landing page, QR code, guidelines, approved creatives.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_promotion( $aff ) {
		$code = CodeRepository::main_code( (int) $aff->id );
		if ( '' === $code ) {
			echo '<p>' . esc_html__( 'No active partner code yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		$landing = Landing::url( $aff );
		echo '<h3>' . esc_html__( 'Your landing page', 'wp-affiliate-captain' ) . '</h3>';
		echo self::copy_field( $landing, __( 'Your landing page', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h3>' . esc_html__( 'QR code', 'wp-affiliate-captain' ) . '</h3>';
		try {
			$svg = Qr::svg( $landing, 6, 4 );
			echo '<div class="wpac-qr">' . $svg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG, no user input in markup.
			echo '<p><a class="wpac-button" download="qr-' . esc_attr( $aff->slug ) . '.svg" href="data:image/svg+xml;base64,' . esc_attr( base64_encode( $svg ) ) . '">' . esc_html__( 'Download QR code (SVG)', 'wp-affiliate-captain' ) . '</a></p>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \InvalidArgumentException $e ) {
			echo '<p>' . esc_html__( 'The link is too long for a QR code.', 'wp-affiliate-captain' ) . '</p>';
		}

		$guidelines = (string) Settings::get( 'promotion_guidelines' );
		if ( '' !== trim( $guidelines ) ) {
			echo '<h3>' . esc_html__( 'Promotion guidelines', 'wp-affiliate-captain' ) . '</h3><div class="wpac-guidelines">' . wp_kses_post( wpautop( $guidelines ) ) . '</div>';
		}

		$creatives = Creatives::approved_for_group( (int) $aff->group_id );
		echo '<h3>' . esc_html__( 'Approved creatives', 'wp-affiliate-captain' ) . '</h3>';
		if ( ! $creatives ) {
			echo self::empty_state( __( 'No creatives available yet. You can already use your partner link and QR code above.', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		foreach ( $creatives as $c ) {
			echo '<div class="wpac-creative"><h4>' . esc_html( $c->title ) . ' <small class="wpac-muted">v' . (int) $c->version . '</small></h4>';
			/* translators: %s: disclosure note */
			echo '<p class="wpac-disclosure">' . esc_html( sprintf( __( 'Mandatory label: "%s" – clearly visible at the beginning of your post.', 'wp-affiliate-captain' ), $c->disclosure ) ) . '</p>';
			if ( 'image' === $c->type ) {
				$src = wp_get_attachment_image_url( (int) $c->attachment_id, 'full' );
				echo wp_get_attachment_image( (int) $c->attachment_id, 'medium' );
				if ( $src ) {
					echo '<p><a href="' . esc_url( $src ) . '" download>' . esc_html__( 'Download image', 'wp-affiliate-captain' ) . '</a></p>';
				}
				echo self::copy_field( Creatives::link( $c, $code ), __( 'Link', 'wp-affiliate-captain' ) . ': ' . $c->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $c->content ) {
				echo self::copy_field( Creatives::personalize( $c, $code, $aff->name ), __( 'Text', 'wp-affiliate-captain' ) . ': ' . $c->title, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
	}

	/**
	 * Commissions tab (minimized data).
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_commissions( $aff ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page          = isset( $_GET['wpac_page'] ) ? max( 1, (int) $_GET['wpac_page'] ) : 1;
		list( $rows, $total ) = Ledger::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 25,
				'offset'       => ( $page - 1 ) * 25,
			)
		);
		if ( ! $rows ) {
			echo self::empty_state( __( 'No commissions yet. As soon as a customer buys via your link or code, the commission appears here.', 'wp-affiliate-captain' ), __( 'Get your link', 'wp-affiliate-captain' ), 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$labels  = Ledger::status_labels();
		$reasons = self::rejection_reasons( $rows );
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Your commissions', 'wp-affiliate-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Order', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Product', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Available from', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$product = in_array( $r->kind, array( 'sale', 'bonus' ), true ) ? $r->description : sprintf( '%s (%s)', __( 'Correction', 'wp-affiliate-captain' ), self::kind_label( $r->kind ) );
			$status  = self::status( $r->status, isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status );
			if ( 'rejected' === $r->status && ! empty( $reasons[ (int) $r->id ] ) ) {
				// Partners see why a commission was rejected.
				$status .= '<br><small class="wpac-muted">' . esc_html( $reasons[ (int) $r->id ] ) . '</small>';
			}
			printf(
				'<tr><td data-label="%7$s">%1$s</td><td data-label="%8$s">%2$s</td><td data-label="%9$s">%3$s</td><td data-label="%10$s" class="%4$s">%5$s</td><td data-label="%11$s">%6$s</td><td data-label="%12$s">%13$s</td></tr>',
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r->created_at . ' UTC' ) ) ),
				esc_html( 'wpac' === $r->source ? '—' : '#' . $r->source_order_id ),
				esc_html( $product ),
				(int) $r->amount_minor < 0 ? 'wpac-negative' : '',
				esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ),
				$status, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr__( 'Date', 'wp-affiliate-captain' ),
				esc_attr__( 'Order', 'wp-affiliate-captain' ),
				esc_attr__( 'Product', 'wp-affiliate-captain' ),
				esc_attr__( 'Amount', 'wp-affiliate-captain' ),
				esc_attr__( 'Status', 'wp-affiliate-captain' ),
				esc_attr__( 'Available from', 'wp-affiliate-captain' ),
				esc_html( in_array( $r->status, array( 'pending', 'review' ), true ) && $r->hold_until ? date_i18n( get_option( 'date_format' ), strtotime( $r->hold_until . ' UTC' ) ) : '—' )
			);
		}
		echo '</tbody></table>';
		self::pager( $page, $total, 25 );
	}

	/**
	 * Kind label.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function kind_label( $kind ) {
		$map = array(
			'refund'       => __( 'refund', 'wp-affiliate-captain' ),
			'cancellation' => __( 'cancellation', 'wp-affiliate-captain' ),
			'chargeback'   => __( 'chargeback', 'wp-affiliate-captain' ),
			'writeoff'     => __( 'write-off', 'wp-affiliate-captain' ),
		);
		return isset( $map[ $kind ] ) ? $map[ $kind ] : $kind;
	}

	/**
	 * Payouts tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_payouts( $aff ) {
		if ( Settings::get( 'payout_requests' ) ) {
			$open      = PayoutRequests::open_for( (int) $aff->id );
			$available = PayoutRequests::available( (int) $aff->id );
			/* translators: %s: amount */
			echo '<p>' . esc_html( sprintf( __( 'Available for payout: %s', 'wp-affiliate-captain' ), Money::format( $available, Settings::get( 'currency' ) ) ) ) . '</p>';
			if ( $open ) {
				/* translators: %s: date */
				echo '<div class="wpac-notice">' . esc_html( sprintf( __( 'Payout requested on %s – in progress.', 'wp-affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $open->created_at . ' UTC' ) ) ) ) . '</div>';
			} else {
				$last = PayoutRequests::last_rejected( (int) $aff->id );
				if ( $last ) {
					/* translators: 1: date, 2: reason */
					echo '<div class="wpac-notice wpac-notice--error">' . esc_html( sprintf( __( 'Your payout request of %1$s was not processed: %2$s', 'wp-affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $last->created_at . ' UTC' ) ), $last->note ) ) . '</div>';
				}
				$can = PayoutRequests::can_request( $aff );
				if ( true === $can ) {
					echo '<form method="post">';
					wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
					/* translators: %s: amount */
					echo '<input type="hidden" name="wpac_portal_action" value="payout_request"><button class="wpac-button">' . esc_html( sprintf( __( 'Request payout of %s', 'wp-affiliate-captain' ), Money::format( $available, Settings::get( 'currency' ) ) ) ) . '</button></form>';
				} else {
					echo '<p class="wpac-muted">' . esc_html( $can ) . '</p>';
				}
			}
		}
		list( $rows ) = PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 100,
			)
		);
		if ( ! $rows ) {
			echo self::empty_state( __( 'No payouts yet. Approved commissions are paid out once the minimum amount is reached.', 'wp-affiliate-captain' ), ProfileRepository::is_complete( ProfileRepository::get( (int) $aff->id ) ) ? '' : __( 'Complete payout details', 'wp-affiliate-captain' ), 'profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$labels = PayoutService::status_labels();
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Your payouts', 'wp-affiliate-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html_x( 'Number', 'document number', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Period until', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Credit note', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			if ( 'cancelled' === $p->status && ! $p->storno_file ) {
				continue;
			}
			$url = static function ( $storno ) use ( $p ) {
				return wp_nonce_url( add_query_arg( array( 'wpac_statement' => (int) $p->id, 'storno' => $storno ? 1 : 0 ), home_url( '/' ) ), 'wpac_statement_' . (int) $p->id );
			};
			$pdf = $p->statement_file ? '<a href="' . esc_url( $url( false ) ) . '">' . esc_html__( 'Credit note', 'wp-affiliate-captain' ) . '</a>' : '—';
			if ( $p->storno_file ) {
				$pdf .= ' · <a href="' . esc_url( $url( true ) ) . '">' . esc_html__( 'Cancellation', 'wp-affiliate-captain' ) . '</a>';
			}
			printf(
				'<tr><td data-label="%6$s">%1$s</td><td data-label="%7$s">%2$s</td><td data-label="%8$s">%3$s</td><td data-label="%9$s">%4$s</td><td data-label="%10$s">%5$s</td></tr>',
				esc_html( $p->number ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->period_end ) ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				self::payout_status( $p, $labels ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$pdf, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_attr_x( 'Number', 'document number', 'wp-affiliate-captain' ),
				esc_attr__( 'Period until', 'wp-affiliate-captain' ),
				esc_attr__( 'Amount', 'wp-affiliate-captain' ),
				esc_attr__( 'Status', 'wp-affiliate-captain' ),
				esc_attr__( 'Credit note', 'wp-affiliate-captain' )
			);
		}
		echo '</tbody></table>';
		// Annual overviews for the partner's tax return.
		$years = array();
		foreach ( $rows as $p ) {
			$years[ (int) substr( (string) $p->created_at, 0, 4 ) ] = true;
		}
		krsort( $years );
		if ( $years ) {
			echo '<p>' . esc_html__( 'Annual overview:', 'wp-affiliate-captain' ) . ' ';
			$links = array();
			foreach ( array_keys( $years ) as $yr ) {
				$links[] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'wpac_annual', $yr, home_url( '/' ) ), 'wpac_annual_' . $yr ) ) . '">' . esc_html( (string) $yr ) . '</a>';
			}
			echo implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		// Objection to a credit note (§14 Abs. 2 S. 3 UStG).
		$objectable = array();
		foreach ( $rows as $p ) {
			if ( 'cancelled' !== $p->status && $p->statement_file ) {
				$objectable[] = $p;
			}
		}
		foreach ( $rows as $p ) {
			$o = \WPAffiliateCaptain\Payouts\Objections::for_payout( (int) $p->id );
			if ( $o ) {
				/* translators: 1: number, 2: status */
				echo '<p class="wpac-muted">' . esc_html( sprintf( __( 'Objection to %1$s: %2$s', 'wp-affiliate-captain' ), $p->number, 'open' === $o['status'] ? __( 'being reviewed', 'wp-affiliate-captain' ) : $o['resolution'] ) ) . '</p>';
			}
		}
		if ( Settings::get( 'self_billing' ) && $objectable ) {
			$kept = get_transient( 'wpac_objection_form_' . get_current_user_id() );
			if ( is_array( $kept ) ) {
				delete_transient( 'wpac_objection_form_' . get_current_user_id() );
			}
			echo '<details class="wpac-objection" id="wpac-objection"' . ( is_array( $kept ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Something is wrong with a credit note?', 'wp-affiliate-captain' ) . '</summary><form method="post" class="wpac-form">';
			wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
			echo '<input type="hidden" name="wpac_portal_action" value="objection"><p><label for="wpac_obj_payout">' . esc_html__( 'Credit note', 'wp-affiliate-captain' ) . '</label><select id="wpac_obj_payout" name="wpac_obj_payout">';
			foreach ( $objectable as $p ) {
				echo '<option value="' . (int) $p->id . '"' . selected( is_array( $kept ) ? (int) $kept['payout'] : 0, (int) $p->id, false ) . '>' . esc_html( $p->number ) . '</option>';
			}
			echo '</select></p><p><label for="wpac_obj_reason">' . esc_html__( 'What is incorrect?', 'wp-affiliate-captain' ) . '</label><textarea id="wpac_obj_reason" name="wpac_obj_reason" rows="3" required' . ( is_array( $kept ) ? ' aria-describedby="wpac-portal-error"' : '' ) . '>' . esc_textarea( is_array( $kept ) ? (string) $kept['reason'] : '' ) . '</textarea></p><p><button type="submit" class="wpac-button">' . esc_html__( 'Object to the credit note', 'wp-affiliate-captain' ) . '</button></p></form></details>';
		}
	}

	/**
	 * Privacy tab: what is stored, how long, and the partner's rights (Art. 13–21 GDPR).
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_privacy( $aff ) {
		$years  = \WPAffiliateCaptain\Privacy\Retention::years();
		$policy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		echo '<h3>' . esc_html__( 'What we store about you', 'wp-affiliate-captain' ) . '</h3><ul>';
		foreach (
			array(
				__( 'Your account: name or company, e-mail, website, channel and your application.', 'wp-affiliate-captain' ),
				__( 'Your partner codes and the accepted terms (version, time and the accepted text).', 'wp-affiliate-captain' ),
				__( 'Visits through your links (without IP address) and the orders attributed to you (order number and amount, never the customer\'s name).', 'wp-affiliate-captain' ),
				__( 'For payouts: legal name, address, tax status, VAT ID or tax number and bank details (the IBAN is stored encrypted), your commissions, payouts and credit notes.', 'wp-affiliate-captain' ),
			) as $line
		) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html(
			$years > 0
				/* translators: %d: years */
				? sprintf( __( 'Commission records and credit notes are kept for %d years after the end of the calendar year (statutory retention), everything else only as long as you are a partner.', 'wp-affiliate-captain' ), $years )
				: __( 'Commission records and credit notes are kept for the statutory retention period, everything else only as long as you are a partner.', 'wp-affiliate-captain' )
		) . '</p>';
		if ( $policy ) {
			echo '<p><a href="' . esc_url( $policy ) . '">' . esc_html__( 'Full privacy policy', 'wp-affiliate-captain' ) . '</a></p>';
		}
		echo '<h3>' . esc_html__( 'Your rights', 'wp-affiliate-captain' ) . '</h3><p>' . esc_html__( 'You can request a copy of your data, its erasure, the restriction of its processing, or object to processing based on our legitimate interest. Copy and erasure are confirmed by e-mail first. Records we must keep by law are restricted instead of deleted until the retention period ends.', 'wp-affiliate-captain' ) . '</p>';
		$kept = get_transient( 'wpac_privacy_form_' . get_current_user_id() );
		if ( is_array( $kept ) ) {
			delete_transient( 'wpac_privacy_form_' . get_current_user_id() );
		}
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="privacy"><p><label for="wpac_privacy_type">' . esc_html__( 'Request', 'wp-affiliate-captain' ) . '</label><select id="wpac_privacy_type" name="wpac_privacy_type">';
		foreach (
			array(
				'export'   => __( 'Copy of my data (export)', 'wp-affiliate-captain' ),
				'erase'    => __( 'Erase my data', 'wp-affiliate-captain' ),
				'restrict' => __( 'Restrict processing', 'wp-affiliate-captain' ),
				'object'   => __( 'Object to processing', 'wp-affiliate-captain' ),
			) as $k => $l
		) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( is_array( $kept ) ? (string) $kept['type'] : '', $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p><label for="wpac_privacy_note">' . esc_html__( 'Details (optional; required for restriction and objection)', 'wp-affiliate-captain' ) . '</label><textarea id="wpac_privacy_note" name="wpac_privacy_note" rows="3"' . ( is_array( $kept ) ? ' aria-describedby="wpac-portal-error"' : '' ) . '>' . esc_textarea( is_array( $kept ) ? (string) $kept['note'] : '' ) . '</textarea></p><p><button type="submit" class="wpac-button">' . esc_html__( 'Send request', 'wp-affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * Payout details tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_profile( $aff ) {
		$p    = ProfileRepository::get( (int) $aff->id );
		$kept = get_transient( 'wpac_profile_form_' . get_current_user_id() );
		if ( is_array( $kept ) ) {
			delete_transient( 'wpac_profile_form_' . get_current_user_id() );
			self::$invalid = (string) $kept['field'];
		}
		$v = static function ( $key ) use ( $p, $kept ) {
			if ( is_array( $kept ) && isset( $kept['values'][ $key ] ) ) {
				return (string) $kept['values'][ $key ];
			}
			return $p && isset( $p->$key ) ? (string) $p->$key : '';
		};
		$labels = ProfileRepository::tax_status_labels();
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="profile">';
		echo '<p><label for="wpac_tax_status">' . esc_html__( 'Tax status', 'wp-affiliate-captain' ) . ' *</label><select id="wpac_tax_status" name="wpac_tax_status" required><option value="">—</option>';
		foreach ( $labels as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $v( 'tax_status' ), $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		self::input( 'legal_name', __( 'Name / company for the statement', 'wp-affiliate-captain' ), $v( 'legal_name' ), true );
		echo '<p><label for="wpac_address">' . esc_html__( 'Address', 'wp-affiliate-captain' ) . ' *</label><textarea id="wpac_address" name="wpac_address" rows="3" required>' . esc_textarea( $v( 'address' ) ) . '</textarea></p>';
		self::input( 'country', __( 'Country (ISO code, e.g. DE)', 'wp-affiliate-captain' ), $v( 'country' ), true, 'maxlength="2"' );
		self::input( 'vat_id', __( 'VAT ID (businesses)', 'wp-affiliate-captain' ), $v( 'vat_id' ) );
		$vies = $p && '' !== (string) $p->vat_id ? \WPAffiliateCaptain\Payouts\Vies::result( (int) $aff->id ) : null;
		if ( $vies && strtoupper( (string) $p->vat_id ) === (string) $vies['vat_id'] ) {
			if ( 'invalid' === $vies['status'] ) {
				echo '<div class="wpac-notice wpac-notice--error" role="alert">' . esc_html__( 'According to the EU VIES database, this VAT ID is not valid. Please check it (country prefix, digits) and save it again. Until it is confirmed, we cannot pay out commissions with reverse charge.', 'wp-affiliate-captain' ) . '</div>';
			} elseif ( 'valid' === $vies['status'] ) {
				echo '<p class="wpac-muted">✔ ' . esc_html__( 'VAT ID confirmed by the EU VIES database.', 'wp-affiliate-captain' ) . '</p>';
			} elseif ( 'unavailable' === $vies['status'] ) {
				echo '<p class="wpac-muted">' . esc_html__( 'The EU VIES database could not be reached to check your VAT ID; it is checked again automatically.', 'wp-affiliate-captain' ) . '</p>';
			}
		}
		self::input( 'tax_number', __( 'Tax number (small businesses without VAT ID)', 'wp-affiliate-captain' ), $v( 'tax_number' ) );
		self::input( 'account_holder', __( 'Account holder', 'wp-affiliate-captain' ), $v( 'account_holder' ), true );
		$masked = $p ? Iban::mask( $p->iban_last4, $p->iban_country ) : '';
		self::input( 'iban', __( 'IBAN', 'wp-affiliate-captain' ) . ( $masked ? ' (' . __( 'stored', 'wp-affiliate-captain' ) . ': ' . $masked . ')' : '' ), '', ! $masked, 'autocomplete="off" placeholder="' . esc_attr( $masked ? __( 'Leave empty to keep', 'wp-affiliate-captain' ) : 'DE00 0000 0000 0000 0000 00' ) . '"' );
		self::input( 'bic', __( 'BIC (optional)', 'wp-affiliate-captain' ), $v( 'bic' ) );
		echo '<p class="wpac-muted">' . esc_html__( 'Your bank details are stored encrypted and are only used for commission payouts.', 'wp-affiliate-captain' ) . '</p>';
		echo '<p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'wp-affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * Text input helper.
	 *
	 * @param string $name     Name.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param bool   $required Required.
	 * @param string $extra    Extra attributes (already escaped).
	 */
	private static function input( $name, $label, $value, $required = false, $extra = '' ) {
		if ( self::$invalid === $name ) {
			$extra .= ' aria-invalid="true" aria-describedby="wpac-portal-error" autofocus';
		}
		printf(
			'<p><label for="wpac_%1$s">%2$s%3$s</label><input type="text" id="wpac_%1$s" name="wpac_%1$s" value="%4$s" %5$s %6$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' *' : '',
			esc_attr( $value ),
			$required ? 'required' : '',
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Tab "My account": contact data the partner can change.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_account( $aff ) {
		$pending = get_transient( 'wpac_email_change_' . (int) $aff->id );
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="account">';
		self::input( 'acc_name', __( 'Name / company', 'wp-affiliate-captain' ), (string) $aff->name, true );
		printf( '<p><label for="wpac_acc_email">%1$s *</label><input type="email" id="wpac_acc_email" name="wpac_acc_email" value="%2$s" required autocomplete="email"></p>', esc_html__( 'E-mail for notifications', 'wp-affiliate-captain' ), esc_attr( (string) $aff->email ) );
		if ( is_array( $pending ) ) {
			/* translators: %s: e-mail */
			echo '<p class="wpac-muted">' . esc_html( sprintf( __( 'Waiting for confirmation of %s.', 'wp-affiliate-captain' ), $pending['email'] ) ) . '</p>';
		}
		printf( '<p><label for="wpac_acc_website">%1$s</label><input type="url" id="wpac_acc_website" name="wpac_acc_website" value="%2$s"></p>', esc_html__( 'Website', 'wp-affiliate-captain' ), esc_attr( (string) $aff->website ) );
		self::input( 'acc_channel', __( 'Channel (e.g. blog, Instagram, sailing school)', 'wp-affiliate-captain' ), (string) $aff->channel );
		echo '<p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'wp-affiliate-captain' ) . '</button></p></form>';
		$admin = (string) Settings::get( 'admin_email' );
		$admin = is_email( $admin ) ? $admin : (string) get_option( 'admin_email' );
		echo '<p class="wpac-muted">' . sprintf(
			/* translators: 1: link to the privacy tab, 2: e-mail link */
			esc_html__( 'To delete your data or get a copy, use the %1$s tab. To end the partnership, write to %2$s.', 'wp-affiliate-captain' ),
			'<a href="' . esc_url( self::tab_url( 'privacy' ) ) . '">' . esc_html__( 'Privacy', 'wp-affiliate-captain' ) . '</a>',
			'<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>'
		) . '</p>';
	}

	/** @var string Field with the last validation error (profile form). */
	private static $invalid = '';

	/**
	 * Simple pager.
	 *
	 * @param int $page  Page.
	 * @param int $total Total.
	 * @param int $per   Per page.
	 */
	private static function pager( $page, $total, $per ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="wpac-pager" aria-label="' . esc_attr__( 'Pages', 'wp-affiliate-captain' ) . '">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			printf( '<a href="%s" class="%s"%s>%d</a> ', esc_url( add_query_arg( 'wpac_page', $i ) ), $i === $page ? 'is-active' : '', $i === $page ? ' aria-current="page"' : '', (int) $i );
		}
		echo '</nav>';
	}

	/**
	 * Partner-facing payout status: "in progress" (created or transfer prepared) or
	 * "transferred on <date>".
	 *
	 * @param object $p      Payout.
	 * @param array  $labels Admin labels.
	 * @return string HTML.
	 */
	private static function payout_status( $p, array $labels ) {
		if ( 'paid' === $p->status && $p->paid_at ) {
			/* translators: %s: date */
			return self::status( 'paid', sprintf( __( 'Transferred on %s', 'wp-affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $p->paid_at . ' UTC' ) ) ) );
		}
		if ( in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
			return self::status( 'pending', __( 'In progress', 'wp-affiliate-captain' ) );
		}
		return self::status( $p->status, isset( $labels[ $p->status ] ) ? $labels[ $p->status ] : $p->status );
	}

	/**
	 * Latest rejection reason per rejected commission of a page.
	 *
	 * @param array $rows Commission rows.
	 * @return array id => reason
	 */
	private static function rejection_reasons( array $rows ) {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $r ) {
			if ( 'rejected' === $r->status ) {
				$ids[] = (int) $r->id;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$t   = \WPAffiliateCaptain\Core\Db::table( 'commission_events' );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %d placeholders built above.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT commission_id, reason FROM {$t} WHERE to_status = 'rejected' AND reason <> '' AND commission_id IN ({$in}) ORDER BY id ASC", $ids ) ) as $e ) {
			// Internal fraud notes start with "review:" and are not shown.
			if ( 0 !== strpos( (string) $e->reason, 'review:' ) ) {
				$out[ (int) $e->commission_id ] = (string) $e->reason;
			}
		}
		return $out;
	}
}
