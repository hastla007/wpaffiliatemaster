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

	/** Privacy requests or objections per partner and day (each kind). */
	const REQUESTS_PER_DAY = 5;

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_post' ) );
		add_action( 'init', array( __CLASS__, 'statement_download' ) );
		add_action( 'init', array( __CLASS__, 'confirm_email' ) );
		add_action( 'init', array( __CLASS__, 'confirm_account_link' ) );
		add_action( 'init', array( __CLASS__, 'annual_download' ) );
		add_action( 'init', array( __CLASS__, 'csv_download' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ), 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'wc_login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'account_menu_url' ), 10, 2 );
	}

	/** Key of the partner area entry in WooCommerce "My account". */
	const ACCOUNT_ITEM = 'wpac-partner-area';

	/**
	 * Register the portal stylesheet and script (also used by the blocks, see block.json "style").
	 */
	public static function register_assets() {
		wp_register_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		wp_register_script( 'wpac-portal', WPAC_URL . 'assets/js/portal.js', array(), WPAC_VERSION, true );
	}

	/**
	 * Page with the partner area (before any output): never from a page cache (balance and status
	 * are personal; logged-in caches like WP Rocket or LiteSpeed would show stale values), and the
	 * stylesheet in the head instead of the footer (no unstyled flash).
	 */
	public static function prepare_page() {
		if ( ! Landing::current_post_has( self::SHORTCODE, 'wpac/portal' ) ) {
			return;
		}
		Landing::no_cache();
		wp_enqueue_style( 'wpac-portal' );
	}

	/**
	 * After the login (also the first one after setting the password) a partner lands in the
	 * partner area instead of the dashboard or profile; an explicit other target is kept.
	 *
	 * @param string           $redirect  Target.
	 * @param string           $requested Requested target.
	 * @param \WP_User|\WP_Error $user      User.
	 * @return string
	 */
	public static function login_redirect( $redirect, $requested = '', $user = null ) {
		if ( ! is_object( $user ) || is_wp_error( $user ) || empty( $user->ID ) ) {
			return $redirect;
		}
		$requested = (string) $requested;
		$defaults  = array( '', admin_url(), admin_url( '/' ), admin_url( 'profile.php' ) );
		if ( ! in_array( $requested, $defaults, true ) || ! (int) Settings::get( 'portal_page_id' ) ) {
			return $redirect;
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return $redirect; // Editors and admins keep their dashboard.
		}
		$aff = AffiliateRepository::find_by_user( (int) $user->ID );
		if ( ! $aff || ! in_array( $aff->status, array( 'active', 'paused', 'terminated' ), true ) ) {
			return $redirect;
		}
		return \WPAffiliateCaptain\Affiliates\Notifications::portal_url( $aff );
	}

	/**
	 * WooCommerce login form ("My account"): partners land in the partner area, unless the form
	 * asked for another page.
	 *
	 * @param string   $redirect Target.
	 * @param \WP_User $user     User.
	 * @return string
	 */
	public static function wc_login_redirect( $redirect, $user = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the login form.
		if ( ! empty( $_POST['redirect'] ) ) {
			return $redirect;
		}
		return self::login_redirect( $redirect, '', $user );
	}

	/**
	 * "Partner area" entry in WooCommerce "My account" for partners.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function account_menu_items( $items ) {
		$aff = self::current();
		if ( ! $aff || ! (int) Settings::get( 'portal_page_id' ) || ! is_array( $items ) ) {
			return $items;
		}
		$entry = array( self::ACCOUNT_ITEM => __( 'Partner area', 'affiliate-captain' ) );
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
			return $items + $entry + $logout;
		}
		return $items + $entry;
	}

	/**
	 * URL of the "Partner area" entry: the portal page.
	 *
	 * @param string $url      URL.
	 * @param string $endpoint Endpoint.
	 * @return string
	 */
	public static function account_menu_url( $url, $endpoint = '' ) {
		return self::ACCOUNT_ITEM === $endpoint ? \WPAffiliateCaptain\Affiliates\Notifications::portal_url( self::current() ) : $url;
	}

	/**
	 * CSV export of the partner's own commissions or payouts (filters of the list apply).
	 */
	public static function csv_download() {
		if ( empty( $_GET['wpac_csv'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$kind = sanitize_key( wp_unslash( $_GET['wpac_csv'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wpac_csv_' . $kind ) ) {
			wp_die( esc_html__( 'The link has expired.', 'affiliate-captain' ), 403 );
		}
		$aff = self::current();
		if ( ! $aff || ! in_array( $kind, array( 'commissions', 'payouts' ), true ) ) {
			wp_die( esc_html__( 'Not allowed.', 'affiliate-captain' ), 403 );
		}
		list( $header, $rows ) = self::export_rows( $aff, $kind, self::list_filters( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in list_filters().
		\WPAffiliateCaptain\Core\Csv::download( $kind . '-' . gmdate( 'Y-m-d' ) . '.csv', $header, $rows );
	}

	/**
	 * Filters of the commission/payout lists from the request: status, from, to (Y-m-d).
	 *
	 * @param array $in Raw query (unslashed).
	 * @return array status, from, to
	 */
	public static function list_filters( array $in ) {
		$date = static function ( $key ) use ( $in ) {
			$v = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? trim( $in[ $key ] ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && checkdate( (int) substr( $v, 5, 2 ), (int) substr( $v, 8, 2 ), (int) substr( $v, 0, 4 ) ) ? $v : '';
		};
		return array(
			'status' => isset( $in['wpac_status'] ) && is_string( $in['wpac_status'] ) ? sanitize_key( $in['wpac_status'] ) : '',
			'from'   => $date( 'wpac_from' ),
			'to'     => $date( 'wpac_to' ),
		);
	}

	/**
	 * Rows of the partner's CSV export (minimized like the portal tables).
	 *
	 * @param object $aff     Partner.
	 * @param string $kind    commissions|payouts.
	 * @param array  $filters status, from, to.
	 * @return array [ header, rows ]
	 */
	public static function export_rows( $aff, $kind, array $filters ) {
		$rows = array();
		if ( 'payouts' === $kind ) {
			list( $list ) = PayoutService::query(
				array(
					'affiliate_id' => (int) $aff->id,
					'status'       => in_array( $filters['status'], array_keys( PayoutService::status_labels() ), true ) ? $filters['status'] : '',
					'limit'        => 5000,
				)
			);
			foreach ( $list as $p ) {
				$day = self::day( $p->created_at );
				if ( ( '' !== $filters['from'] && $day < $filters['from'] ) || ( '' !== $filters['to'] && $day > $filters['to'] ) ) {
					continue;
				}
				$rows[] = array( $p->number, self::day( $p->period_end ), Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->currency, $p->status, $p->paid_at ? self::day( $p->paid_at ) : '' );
			}
			return array( array( 'number', 'period_end', 'amount', 'currency', 'status', 'paid_at' ), $rows );
		}
		list( $list ) = Ledger::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'status'       => $filters['status'],
				'from'         => $filters['from'],
				'to'           => $filters['to'],
				'limit'        => 5000,
			)
		);
		foreach ( $list as $r ) {
			$rows[] = array( self::day( $r->created_at ), 'wpac' === $r->source ? '' : (string) $r->source_order_id, $r->kind, $r->description, Money::to_decimal( (int) $r->base_minor, $r->currency ), $r->rate_type . ':' . $r->rate, Money::to_decimal( (int) $r->amount_minor, $r->currency ), $r->currency, $r->status, 'pending' === $r->status && $r->hold_until ? self::day( $r->hold_until ) : '' );
		}
		return array( array( 'date', 'order', 'kind', 'product', 'base', 'rate', 'amount', 'currency', 'status', 'available_from' ), $rows );
	}

	/**
	 * Day (Y-m-d) of a MySQL datetime.
	 *
	 * @param string|null $datetime Datetime.
	 * @return string
	 */
	private static function day( $datetime ) {
		return (string) substr( (string) $datetime, 0, 10 );
	}

	/**
	 * Store the mail preferences of a partner: the optional mail types not in $keep are switched off.
	 *
	 * @param int      $affiliate_id Partner.
	 * @param string[] $keep         Types the partner wants to receive.
	 */
	public static function save_mail_preferences( $affiliate_id, array $keep ) {
		foreach ( \WPAffiliateCaptain\Affiliates\Notifications::OPTOUT_TYPES as $type ) {
			$out = ! in_array( $type, $keep, true );
			if ( $out !== \WPAffiliateCaptain\Affiliates\Notifications::opted_out( (int) $affiliate_id, $type ) ) {
				\WPAffiliateCaptain\Affiliates\Notifications::set_opt_out( (int) $affiliate_id, $type, $out );
			}
		}
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
		$pending = \WPAffiliateCaptain\Core\Pending::get( 'email_change_' . (int) $aff->id );
		$pending = null !== $pending ? $pending : get_transient( 'wpac_email_change_' . (int) $aff->id ); // Older version.
		$msg     = 'email_link_invalid';
		if ( is_array( $pending ) && self::email_token_matches( $pending, $token ) ) {
			$result = AffiliateRepository::set_email( (int) $aff->id, (string) $pending['email'] );
			\WPAffiliateCaptain\Core\Pending::delete( 'email_change_' . (int) $aff->id );
			delete_transient( 'wpac_email_change_' . (int) $aff->id );
			$msg = is_wp_error( $result ) ? $result->get_error_code() : 'email_changed';
		}
		wp_safe_redirect( add_query_arg( array( 'wpac_msg' => $msg, 'wpac_tab' => 'account' ), $back ) );
		exit;
	}

	/**
	 * Hash of an e-mail change token: only the hash is stored, so a database dump or
	 * backup does not contain usable confirmation links.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function email_token_hash( $token ) {
		return hash_hmac( 'sha256', strtolower( (string) $token ), wp_salt( 'auth' ) . 'wpac_email_change' );
	}

	/**
	 * Whether a token from the link matches the pending change (hash; plaintext tokens
	 * stored by older versions are still accepted until they expire after 24 hours).
	 *
	 * @param array  $pending Pending change.
	 * @param string $token   Token from the link.
	 * @return bool
	 */
	public static function email_token_matches( array $pending, $token ) {
		if ( '' === (string) $token ) {
			return false;
		}
		if ( isset( $pending['token_hash'] ) ) {
			return hash_equals( (string) $pending['token_hash'], self::email_token_hash( $token ) );
		}
		return isset( $pending['token'] ) && '' !== (string) $pending['token'] && hash_equals( (string) $pending['token'], strtolower( (string) $token ) );
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
			wp_die( esc_html__( 'The link has expired.', 'affiliate-captain' ), 403 );
		}
		$aff = self::current();
		if ( ! $aff || $year < 2000 || $year > (int) gmdate( 'Y' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'affiliate-captain' ), 403 );
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
			wp_die( esc_html__( 'The link has expired.', 'affiliate-captain' ), 403 );
		}
		$aff = self::current();
		$p   = PayoutService::find( $id );
		if ( ! $aff || ! $p || (int) $p->affiliate_id !== (int) $aff->id ) {
			wp_die( esc_html__( 'Not allowed.', 'affiliate-captain' ), 403 );
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
		if ( 'resend_link' === $action ) {
			if ( $aff || ! is_user_logged_in() ) {
				return;
			}
			wp_safe_redirect( add_query_arg( 'wpac_msg', Settings::get( 'maintenance_mode' ) ? 'maintenance' : self::resend_link( wp_get_current_user() ), remove_query_arg( 'wpac_msg', wpac_return_url() ) ) );
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
				} elseif ( ! RateLimiter::allow_for( 'email_change', (int) $aff->id, 3, DAY_IN_SECONDS ) ) {
					// Per partner (not per IP): changing the IP must not allow more confirmation mails.
					$msg = 'email_change_limit';
				} else {
					$token = wp_generate_password( 32, false );
					\WPAffiliateCaptain\Core\Pending::set(
						'email_change_' . (int) $aff->id,
						array(
							'email'      => $email,
							'token_hash' => self::email_token_hash( $token ),
						),
						DAY_IN_SECONDS
					);
					$link = add_query_arg( 'wpac_email_confirm', strtolower( $token ), add_query_arg( 'wpac_tab', 'account', wpac_return_url() ) );
					\WPAffiliateCaptain\Affiliates\Notifications::send(
						'email_confirm',
						$email,
						/* translators: %s: site name */
						sprintf( __( '[%s] Please confirm your new e-mail address', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
						/* translators: %s: link */
						sprintf( __( "Hello,\n\nplease confirm your new e-mail address for the partner program (valid for 24 hours):\n%s\n\nIf you did not request this, ignore this e-mail.", 'affiliate-captain' ), $link ),
						array( 'affiliate_id' => (int) $aff->id )
					);
					$msg = 'email_confirm_sent';
				}
			}
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => $msg, 'wpac_tab' => 'account' ), $redirect ) );
			exit;
		}

		if ( 'notifications' === $action ) {
			// Mail preferences (the unsubscribe links of the mails change the same setting).
			$keep = isset( $_POST['wpac_mail'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wpac_mail'] ) ) : array();
			self::save_mail_preferences( (int) $aff->id, $keep );
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => 'notifications_saved', 'wpac_tab' => 'account' ), $redirect ) );
			exit;
		}

		// Requests that send mails to the operator: limited per partner, so the operator's
		// inbox cannot be flooded (the partner can still write an e-mail at any time).
		if ( in_array( $action, array( 'privacy', 'objection' ), true ) && ! RateLimiter::allow_for( 'portal_' . $action, (int) $aff->id, self::REQUESTS_PER_DAY, DAY_IN_SECONDS ) ) {
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => 'request_limit', 'wpac_tab' => 'privacy' === $action ? 'privacy' : 'payouts' ), $redirect ) );
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
						'note' => Db::cut( $note, 2000 ),
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
						'reason' => Db::cut( $reason, 2000 ),
					),
					15 * MINUTE_IN_SECONDS
				);
			}
			wp_safe_redirect( add_query_arg( array( 'wpac_msg' => is_wp_error( $result ) ? 'objection_failed' : 'objection_sent', 'wpac_tab' => 'payouts' ), $redirect ) . '#wpac-objection' );
			exit;
		}

		if ( 'profile' === $action ) {
			$fields = array( 'tax_status', 'legal_name', 'address', 'country', 'vat_id', 'tax_number', 'account_holder', 'iban', 'bic', 'payout_method', 'payment_details' );
			$in     = array();
			foreach ( $fields as $f ) {
				$in[ $f ] = isset( $_POST[ 'wpac_' . $f ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'wpac_' . $f ] ) ) : '';
			}
			// Changing where the money goes needs the account password: a taken-over session
			// (open computer, stolen cookie) must not redirect the next payout.
			$password = isset( $_POST['wpac_current_password'] ) ? (string) wp_unslash( $_POST['wpac_current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password, compared with the hash only.
			$user     = wp_get_current_user();
			if ( ProfileRepository::changes_destination( ProfileRepository::get( (int) $aff->id ), $in ) && ( '' === $password || ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) ) {
				\WPAffiliateCaptain\Core\Audit::log( 'payout_profile.password_refused', 'affiliate', (int) $aff->id );
				$result = new \WP_Error( 'wpac_password', __( 'Please confirm the change of your bank or payment details with your current password.', 'affiliate-captain' ) );
			} else {
				// Tax data that change the VAT on credit notes wait for the approval of an admin.
				$result = ProfileRepository::save( (int) $aff->id, $in, 'partner' );
			}
			$msg = is_wp_error( $result ) ? $result->get_error_code() : ( \WPAffiliateCaptain\Payouts\TaxHistory::pending( (int) $aff->id ) ? 'tax_pending' : 'saved' );
			if ( is_wp_error( $result ) ) {
				// Keep what the partner typed (never the IBAN or payment details) and mark the field with the error.
				unset( $in['iban'], $in['payment_details'] );
				$fields = array(
					'wpac_iban'            => 'iban',
					'wpac_iban_required'   => 'iban',
					'wpac_payment_details' => 'payment_details',
					'wpac_vat'        => 'vat_id',
					'wpac_vat_format' => 'vat_id',
					'wpac_tax_number' => 'tax_number',
					'wpac_password'   => 'current_password',
					'wpac_too_long_legal_name'     => 'legal_name',
					'wpac_too_long_account_holder' => 'account_holder',
					'wpac_too_long_vat_id'         => 'vat_id',
					'wpac_method_disabled'         => 'iban',
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
	 * Send the account link again for a logged-in customer whose e-mail belongs to a partner. The
	 * answer is the same whether such a partner exists or not (no probing of partner accounts),
	 * and limited per user (3 per hour) besides the 10 minutes per partner.
	 *
	 * @param object $user Current user.
	 * @return string Message key: link_sent|link_wait.
	 */
	public static function resend_link( $user ) {
		if ( ! RateLimiter::allow_for( 'portal_resend_link', (int) $user->ID, 3, HOUR_IN_SECONDS ) ) {
			return 'link_wait';
		}
		$partner = AffiliateRepository::find_by_email( (string) $user->user_email );
		$result  = $partner ? AffiliateRepository::resend_user_link( (int) $partner->id ) : true;
		return is_wp_error( $result ) && 'wpac_rate_limited' === $result->get_error_code() ? 'link_wait' : 'link_sent';
	}

	/**
	 * Shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! wp_style_is( 'wpac-portal', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'wpac-portal' );
		wp_enqueue_script( 'wpac-portal' );
		wp_localize_script(
			'wpac-portal',
			'wpacPortal',
			array(
				'copied' => __( 'Copied to the clipboard.', 'affiliate-captain' ),
				'failed' => __( 'Please copy the text manually.', 'affiliate-captain' ),
			)
		);

		if ( ! is_user_logged_in() ) {
			$reg = (int) Settings::get( 'registration_page_id' );
			$out = '<div class="wpac-portal"><p>' . esc_html__( 'Please log in to access your partner area.', 'affiliate-captain' ) . '</p>';
			$out .= wp_login_form( array( 'echo' => false ) );
			$out .= '<p><a href="' . esc_url( wp_lostpassword_url( get_permalink() ) ) . '">' . esc_html__( 'Forgot your password or no password set yet?', 'affiliate-captain' ) . '</a></p>';
			if ( $reg ) {
				$out .= '<p><a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Not a partner yet? Apply now.', 'affiliate-captain' ) . '</a></p>';
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
				echo '<div class="wpac-notice"><p>' . esc_html__( 'Your partner account is ready. Check your e-mail to link your partner account to this customer account: we have sent you a link (valid for 7 days). Open it while you are logged in.', 'affiliate-captain' ) . '</p>';
				echo '<form method="post">';
				wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
				echo '<input type="hidden" name="wpac_portal_action" value="resend_link"><button type="submit" class="wpac-button">' . esc_html__( 'Send the link again', 'affiliate-captain' ) . '</button></form></div></div>';
				return (string) ob_get_clean();
			}
			$reg = (int) Settings::get( 'registration_page_id' );
			return '<div class="wpac-notice">' . esc_html__( 'You do not have a partner account yet.', 'affiliate-captain' ) . ( $reg ? ' <a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Apply now', 'affiliate-captain' ) . '</a>' : '' ) . '</div>';
		}
		if ( 'pending' === $aff->status ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your application is being reviewed. We will notify you by e-mail.', 'affiliate-captain' ) . '</div>';
		}
		$closed = in_array( $aff->status, array( 'rejected', 'terminated' ), true );
		$reason = 'privacy_erasure' !== (string) $aff->status_reason ? trim( (string) $aff->status_reason ) : '';
		$admin  = (string) Settings::get( 'admin_email' );
		$admin  = is_email( $admin ) ? $admin : (string) get_option( 'admin_email' );
		if ( 'rejected' === $aff->status ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your application was not accepted.', 'affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . ' ' . sprintf( /* translators: %s: e-mail link */ esc_html__( 'Questions? Contact us at %s.', 'affiliate-captain' ), '<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>' ) . '</div>';
		}

		$tabs = array(
			'overview'    => __( 'Overview', 'affiliate-captain' ),
			'statistics'  => __( 'Statistics', 'affiliate-captain' ),
			'links'       => __( 'Links & codes', 'affiliate-captain' ),
			'promotion'   => __( 'Creatives', 'affiliate-captain' ),
			'commissions' => __( 'Commissions', 'affiliate-captain' ),
			'payouts'     => __( 'Payouts', 'affiliate-captain' ),
			'profile'     => __( 'Payout details', 'affiliate-captain' ),
			'account'     => __( 'My account', 'affiliate-captain' ),
			'privacy'     => __( 'Privacy', 'affiliate-captain' ),
		);
		if ( $closed ) {
			// Terminated partners keep read access to commissions, payouts and their credit
			// notes (open commissions are still paid out; the documents are needed for taxes).
			$tabs = array_intersect_key( $tabs, array_flip( array( 'commissions', 'payouts', 'privacy' ) ) );
		}
		$core = $tabs;
		/**
		 * Tabs of the partner area. Add-ons add tabs (key => label) and render them on the action
		 * "wpac_portal_tab_{key}" (argument: the partner). Core tabs cannot be replaced; the tabs
		 * overview, statistics, links, promotion, commissions and payouts can be hidden by removing them
		 * (payout details, account and privacy always stay).
		 *
		 * @param array  $tabs Tabs.
		 * @param object $aff  Partner.
		 */
		$extra = apply_filters( 'wpac_portal_tabs', $tabs, $aff );
		if ( is_array( $extra ) ) {
			foreach ( array( 'overview', 'statistics', 'links', 'promotion', 'commissions', 'payouts' ) as $key ) {
				if ( isset( $tabs[ $key ] ) && ! array_key_exists( $key, $extra ) ) {
					unset( $tabs[ $key ] );
				}
			}
		}
		foreach ( is_array( $extra ) ? $extra : array() as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && ! isset( $core[ $key ] ) && has_action( 'wpac_portal_tab_' . $key ) ) {
				$tabs[ $key ] = (string) $label;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['wpac_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpac_tab'] ) ) : key( $tabs );
		$tab = isset( $tabs[ $tab ] ) ? $tab : key( $tabs );

		ob_start();
		echo '<div class="wpac-portal">';
		self::messages();
		if ( Settings::get( 'maintenance_mode' ) ) {
			echo '<div class="wpac-notice">' . esc_html__( 'The partner area is being maintained. You can look at everything, but changes are possible again shortly.', 'affiliate-captain' ) . '</div>';
		}
		if ( 'paused' === $aff->status ) {
			echo '<div class="wpac-notice">' . esc_html__( 'Your partner account is paused. New sales are currently not attributed.', 'affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . '</div>';
		}
		if ( $closed ) {
			echo '<div class="wpac-notice">' . esc_html__( 'Your partnership has ended. You can still see your commissions and payouts and download your credit notes.', 'affiliate-captain' ) . ( '' !== $reason ? ' ' . esc_html__( 'Reason:', 'affiliate-captain' ) . ' ' . esc_html( $reason ) : '' ) . ' ' . sprintf( /* translators: %s: e-mail link */ esc_html__( 'Questions? Contact us at %s.', 'affiliate-captain' ), '<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>' ) . '</div>';
		}
		$terms = Agreements::current( (int) $aff->group_id );
		if ( ! $closed && $terms['page_id'] && ! Agreements::has_current( (int) $aff->id ) ) {
			self::terms_notice( $terms, (bool) Agreements::latest( (int) $aff->id ) );
		}
		echo '<nav class="wpac-tabs" aria-label="' . esc_attr__( 'Partner area', 'affiliate-captain' ) . '">';
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
		if ( isset( $core[ $tab ] ) ) {
			call_user_func( array( __CLASS__, 'tab_' . $tab ), $aff );
		} else {
			/**
			 * Renders an add-on tab of the partner area (escape all output).
			 *
			 * @param object $aff Partner.
			 */
			do_action( 'wpac_portal_tab_' . $tab, $aff );
		}
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/** Query arguments of one view (messages, generator, filters, page) – dropped when switching. */
	const VIEW_ARGS = array( 'wpac_msg', 'target', 'campaign', 'source', 'sub', 'wpac_sub', 'wpac_page', 'wpac_period', 'wpac_status', 'wpac_from', 'wpac_to' );

	/**
	 * URL of a portal tab.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		return add_query_arg( 'wpac_tab', $tab, remove_query_arg( self::VIEW_ARGS ) );
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
		return '<label class="wpac-sr" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="wpac-copy-row">' . $field . '<button type="button" class="wpac-copy-btn" data-wpac-copy="' . esc_attr( $id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: what is copied */ __( 'Copy: %s', 'affiliate-captain' ), $label ) ) . '">' . esc_html__( 'Copy', 'affiliate-captain' ) . '</button></div>';
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
	/** @var array<string,mixed> Flash data (error details, kept form entries) read in this request. */
	private static $flash = array();

	/**
	 * Read flash data once per request: the transient is deleted when it is read, but the
	 * value is kept for this request, because the partner area can be rendered more than once
	 * (SEO plugins and page builders run the_content before the visible output).
	 *
	 * @param string $key Transient name.
	 * @return mixed Value or false.
	 */
	public static function flash( $key ) {
		$key = (string) $key;
		if ( ! array_key_exists( $key, self::$flash ) ) {
			$value = get_transient( $key );
			if ( false !== $value ) {
				delete_transient( $key );
			}
			self::$flash[ $key ] = $value;
		}
		return self::$flash[ $key ];
	}

	private static function messages() {
		$messages = array(
			'saved'     => array( 'success', __( 'Your details have been saved.', 'affiliate-captain' ) ),
			'requested' => array( 'success', __( 'Your payout request was sent.', 'affiliate-captain' ) ),
			'request_failed' => array( 'error', __( 'The payout request could not be created.', 'affiliate-captain' ) ),
			'terms'     => array( 'success', __( 'Thank you for accepting the terms.', 'affiliate-captain' ) ),
			'terms_changed' => array( 'error', __( 'The terms were changed in the meantime. Please read the current version and accept it again.', 'affiliate-captain' ) ),
			'wpac_iban' => array( 'error', __( 'The IBAN is not valid.', 'affiliate-captain' ) ),
			'wpac_iban_required'   => array( 'error', __( 'Please enter your IBAN for payouts by bank transfer.', 'affiliate-captain' ) ),
			'wpac_payment_details' => array( 'error', __( 'Please enter your payment details (e.g. your PayPal address).', 'affiliate-captain' ) ),
			'wpac_vat'  => array( 'error', __( 'Please enter your VAT ID or tax number (required on credit notes; reverse charge across borders needs the VAT ID).', 'affiliate-captain' ) ),
			'wpac_vat_format' => array( 'error', __( 'The VAT ID does not have a valid format or does not start with the code of your country. Please check it.', 'affiliate-captain' ) ),
			'tax_pending'     => array( 'success', __( 'Your details have been saved. The change of your tax data is checked by us and applies once we have approved it; until then your previous tax data are used.', 'affiliate-captain' ) ),
			'wpac_tax_number' => array( 'error', __( 'Please enter your tax number or VAT ID (required on credit notes).', 'affiliate-captain' ) ),
			'wpac_password'   => array( 'error', __( 'Please confirm the change of your bank or payment details with your current password. Nothing was changed.', 'affiliate-captain' ) ),
			'wpac_too_long_legal_name'     => array( 'error', __( 'The name is too long (at most 190 characters). Nothing was changed.', 'affiliate-captain' ) ),
			'wpac_too_long_account_holder' => array( 'error', __( 'The account holder is too long (at most 190 characters). Nothing was changed.', 'affiliate-captain' ) ),
			'wpac_too_long_vat_id'         => array( 'error', __( 'The VAT ID is too long (at most 40 characters). Nothing was changed.', 'affiliate-captain' ) ),
			'wpac_method_disabled'         => array( 'error', __( 'Payouts by another payment method are not offered. Please enter your IBAN for a bank transfer.', 'affiliate-captain' ) ),
			'wpac_profile_db'              => array( 'error', __( 'Your payout details could not be saved. Please try again; your previous details are unchanged.', 'affiliate-captain' ) ),
			'wpac_email'         => array( 'error', __( 'Please enter a valid e-mail address.', 'affiliate-captain' ) ),
			'wpac_email_taken'   => array( 'error', __( 'This e-mail address is already used by another partner account.', 'affiliate-captain' ) ),
			'email_confirm_sent' => array( 'success', __( 'Saved. We have sent a confirmation link to your new e-mail address; it is used once you confirm it.', 'affiliate-captain' ) ),
			'email_changed'      => array( 'success', __( 'Your new e-mail address is confirmed.', 'affiliate-captain' ) ),
			'email_link_invalid' => array( 'error', __( 'The confirmation link is invalid or has expired.', 'affiliate-captain' ) ),
			'email_change_limit' => array( 'error', __( 'Too many e-mail changes today. Please try again tomorrow.', 'affiliate-captain' ) ),
			'request_limit'      => array( 'error', __( 'You have sent several requests today. Please try again tomorrow or contact us by e-mail.', 'affiliate-captain' ) ),
			'account_linked'     => array( 'success', __( 'Your customer account is now connected to the partner program.', 'affiliate-captain' ) ),
			'privacy_confirm'    => array( 'success', __( 'Please confirm your request with the link we have sent you by e-mail.', 'affiliate-captain' ) ),
			'privacy_sent'       => array( 'success', __( 'Your request was sent. We will get back to you within one month.', 'affiliate-captain' ) ),
			'privacy_failed'     => array( 'error', __( 'The request could not be sent. Please try again later or write to us by e-mail.', 'affiliate-captain' ) ),
			'privacy_note_missing' => array( 'error', __( 'For a restriction or an objection, please describe your reason in the details field.', 'affiliate-captain' ) ),
			'objection_sent'     => array( 'success', __( 'Thank you. Your objection was sent; we will review the credit note.', 'affiliate-captain' ) ),
			'objection_failed'   => array( 'error', __( 'The objection could not be filed.', 'affiliate-captain' ) ),
			'link_sent'          => array( 'success', __( 'If your e-mail address belongs to a partner account waiting to be connected, we have sent you a new link. Please check your e-mail (also the spam folder).', 'affiliate-captain' ) ),
			'notifications_saved' => array( 'success', __( 'Your e-mail preferences have been saved.', 'affiliate-captain' ) ),
			'link_wait'          => array( 'error', __( 'A link was sent a few minutes ago. Please check your e-mail or try again later.', 'affiliate-captain' ) ),
			'link_failed'        => array( 'error', __( 'The link could not be sent. Please contact us.', 'affiliate-captain' ) ),
			'maintenance'        => array( 'error', __( 'The partner area is being maintained; changes are possible again shortly.', 'affiliate-captain' ) ),
			'change_review'      => array( 'success', __( 'Saved. Your new name or website is shown after our review.', 'affiliate-captain' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['wpac_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpac_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			$text = $messages[ $key ][1];
			if ( in_array( $key, array( 'request_failed', 'objection_failed' ), true ) ) {
				// The actual reason (e.g. below the minimum, profile incomplete).
				$detail = self::flash( 'wpac_portal_error_' . get_current_user_id() );
				if ( $detail ) {
					$text .= ' ' . $detail;
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
		echo '<p>' . esc_html( $updated ? __( 'Our partner program terms have been updated.', 'affiliate-captain' ) : __( 'Welcome! Please read and accept our partner program terms. We need your acceptance to issue credit notes and pay out your commissions.', 'affiliate-captain' ) ) . '</p>';
		echo '<label><input type="checkbox" name="wpac_terms" value="1" required> ';
		printf(
			/* translators: 1: link, 2: version */
			esc_html__( 'I accept the %1$s (version %2$s).', 'affiliate-captain' ),
			'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'affiliate-captain' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $terms['version'] )
		);
		echo '</label> <button type="submit" class="wpac-button">' . esc_html__( 'Accept', 'affiliate-captain' ) . '</button></form>';
	}

	/**
	 * Amounts per currency as text, the program currency first ("0,00 €" when empty).
	 *
	 * @param array $by_currency currency => minor.
	 * @return string
	 */
	public static function money_list( array $by_currency ) {
		$program = strtoupper( (string) Settings::get( 'currency' ) );
		uksort(
			$by_currency,
			static function ( $a, $b ) use ( $program ) {
				if ( $program === $a || $program === $b ) {
					return $program === $a ? -1 : ( $program === $b ? 1 : 0 );
				}
				return strcmp( (string) $a, (string) $b );
			}
		);
		$out = array();
		foreach ( $by_currency as $cur => $minor ) {
			if ( 0 !== (int) $minor || $program === $cur ) {
				$out[] = Money::format( (int) $minor, (string) $cur );
			}
		}
		return $out ? implode( ' · ', $out ) : Money::format( 0, $program );
	}

	/**
	 * Sums of commission statuses per currency (all booking currencies, not only the program currency).
	 *
	 * @param array    $totals   Ledger::totals() result.
	 * @param string[] $statuses Statuses.
	 * @return array currency => minor
	 */
	public static function sum_statuses( array $totals, array $statuses ) {
		$out = array();
		foreach ( $statuses as $status ) {
			foreach ( isset( $totals[ $status ] ) ? (array) $totals[ $status ] : array() as $cur => $minor ) {
				$out[ $cur ] = ( isset( $out[ $cur ] ) ? $out[ $cur ] : 0 ) + (int) $minor;
			}
		}
		return $out;
	}

	/**
	 * Period of the statistics: last 7/30/90/365 days, this month or last month (UTC days).
	 *
	 * @param string   $key Period key.
	 * @param int|null $now Time (tests).
	 * @return array key, from, to (Y-m-d)
	 */
	public static function period( $key, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$today = gmdate( 'Y-m-d', $now );
		$key   = (string) $key;
		if ( 'month' === $key ) {
			return array( 'key' => $key, 'from' => gmdate( 'Y-m-01', $now ), 'to' => $today );
		}
		if ( 'last_month' === $key ) {
			$first = strtotime( gmdate( 'Y-m-01', $now ) . ' 00:00:00 UTC' );
			return array( 'key' => $key, 'from' => gmdate( 'Y-m-01', $first - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', $first - DAY_IN_SECONDS ) );
		}
		$days = in_array( $key, array( '7', '30', '90', '365' ), true ) ? (int) $key : 30;
		return array( 'key' => (string) $days, 'from' => gmdate( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS ), 'to' => $today );
	}

	/**
	 * Conditions of the partner: base rate, special rates, cookie duration, hold period, group,
	 * bonus steps and the accepted terms.
	 *
	 * @param object $aff Partner.
	 * @return array label => text
	 */
	public static function conditions( $aff ) {
		$rank    = array( 'global' => 1, 'group' => 2, 'affiliate' => 3 );
		$base    = \WPAffiliateCaptain\Commissions\RuleRepository::default_rate();
		$best    = null;
		$special = 0;
		foreach ( \WPAffiliateCaptain\Commissions\RuleRepository::active_for( (int) $aff->id, (int) $aff->group_id ) as $r ) {
			if ( 'all' !== $r['target'] ) {
				++$special;
				continue;
			}
			$score = array( isset( $rank[ $r['scope'] ] ) ? $rank[ $r['scope'] ] : 0, (int) $r['priority'], (int) $r['id'] );
			if ( null === $best || $score > $best[0] ) {
				$best = array( $score, $r );
			}
		}
		if ( $best ) {
			$base = $best[1];
		}
		$out = array(
			__( 'Your commission', 'affiliate-captain' ) => 'exclude' === $base['rate_type'] ? __( 'Only for selected products', 'affiliate-captain' ) : \WPAffiliateCaptain\Commissions\RuleRepository::format_rate( $base['rate_type'], $base['rate'] ) . ( 'percent' === $base['rate_type'] ? ' ' . __( 'of the net order value', 'affiliate-captain' ) : ' ' . __( 'per product sold', 'affiliate-captain' ) ),
		);
		if ( $special ) {
			$out[ __( 'Special rates', 'affiliate-captain' ) ] = __( 'Some products or categories have their own rate; each commission in the list shows the rate used.', 'affiliate-captain' );
		}
		$days = (int) Settings::get( 'cookie_days' );
		/* translators: %d: days */
		$out[ __( 'Referral valid for', 'affiliate-captain' ) ] = sprintf( _n( '%d day after the click', '%d days after the click', $days, 'affiliate-captain' ), $days );
		$hold = (int) Settings::get( 'hold_days' );
		/* translators: %d: days */
		$out[ __( 'Hold period', 'affiliate-captain' ) ] = sprintf( _n( '%d day (returns and cancellations), then the commission is approved', '%d days (returns and cancellations), then the commission is approved', $hold, 'affiliate-captain' ), $hold );
		$group = (int) $aff->group_id ? \WPAffiliateCaptain\Affiliates\Groups::find( (int) $aff->group_id ) : null;
		if ( $group ) {
			$out[ __( 'Partner group', 'affiliate-captain' ) ] = (string) $group->name;
		}
		$plan = \WPAffiliateCaptain\Commissions\TierService::plan_for( $aff );
		if ( $plan ) {
			$steps = \WPAffiliateCaptain\Commissions\TierService::steps_text( $plan, (string) Settings::get( 'currency' ) );
			if ( '' !== $steps ) {
				$out[ __( 'Monthly bonus', 'affiliate-captain' ) ] = $steps;
			}
		}
		$out[ __( 'Minimum payout amount', 'affiliate-captain' ) ] = Money::format( (int) Settings::get( 'min_payout_minor' ), (string) Settings::get( 'currency' ) );
		return $out;
	}

	/**
	 * Accepted partner terms: link, version and date (HTML).
	 *
	 * @param object $aff Partner.
	 * @return string
	 */
	private static function terms_line( $aff ) {
		$terms = Agreements::current( (int) $aff->group_id );
		if ( ! $terms['page_id'] || '' === (string) $terms['url'] ) {
			return '';
		}
		$latest = Agreements::latest( (int) $aff->id );
		$link   = '<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Partner program terms', 'affiliate-captain' ) . '</a>';
		if ( $latest ) {
			/* translators: 1: link, 2: version, 3: date */
			return sprintf( esc_html__( '%1$s – accepted version %2$s on %3$s.', 'affiliate-captain' ), $link, esc_html( (string) $latest->version ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $latest->created_at . ' UTC' ) ) ) );
		}
		/* translators: 1: link, 2: version */
		return sprintf( esc_html__( '%1$s – current version %2$s (not accepted yet).', 'affiliate-captain' ), $link, esc_html( (string) $terms['version'] ) );
	}

	/**
	 * Overview tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_overview( $aff ) {
		$totals  = Ledger::totals( array( 'affiliate_id' => (int) $aff->id ) );
		$period  = self::period( '30' );
		$summary = \WPAffiliateCaptain\Reports\Reports::partner_summary( (int) $aff->id, $period['from'], $period['to'] );
		$review  = self::sum_statuses( $totals, array( 'review' ) );
		// Amounts in every booking currency (sales in USD show up even if the program uses EUR).
		$cards = array(
			__( 'Link visits (30 days)', 'affiliate-captain' )  => number_format_i18n( $summary['visits'] ),
			__( 'Sales (30 days)', 'affiliate-captain' )        => number_format_i18n( $summary['sales'] ),
			__( 'Conversion rate (30 days)', 'affiliate-captain' ) => number_format_i18n( $summary['conversion'], 1 ) . ' %',
			__( 'Pending (hold period)', 'affiliate-captain' )  => self::money_list( self::sum_statuses( $totals, array( 'pending' ) ) ),
		);
		if ( array_filter( $review ) ) {
			$cards[ __( 'Being reviewed', 'affiliate-captain' ) ] = self::money_list( $review );
		}
		$cards += array(
			__( 'Approved, not yet paid', 'affiliate-captain' ) => self::money_list( self::sum_statuses( $totals, array( 'approved' ) ) ),
			__( 'Paid in total', 'affiliate-captain' )          => self::money_list( self::sum_statuses( $totals, array( 'paid' ) ) ),
		);
		$code    = CodeRepository::main_code( (int) $aff->id );
		$profile = ProfileRepository::get( (int) $aff->id );
		$terms   = Agreements::current( (int) $aff->group_id );
		$steps   = array(
			array( '' !== $code, __( 'Partner code received', 'affiliate-captain' ), 'links' ),
			array( ! $terms['page_id'] || Agreements::has_current( (int) $aff->id ), __( 'Current terms accepted', 'affiliate-captain' ), 'overview' ),
			array( ProfileRepository::is_complete( $profile ), __( 'Payout details completed', 'affiliate-captain' ), 'profile' ),
			array( Tracker::has_visits( (int) $aff->id ), __( 'First visitor via your link', 'affiliate-captain' ), 'promotion' ),
			array( $summary['sales'] > 0 || array_filter( self::sum_statuses( $totals, array( 'pending', 'review', 'approved', 'paid' ) ) ), __( 'First sale', 'affiliate-captain' ), 'links' ),
		);
		echo '<section class="wpac-hero" aria-labelledby="wpac-hero-title"><h3 id="wpac-hero-title">' . esc_html__( 'Share your partner link', 'affiliate-captain' ) . '</h3>';
		if ( $code ) {
			echo self::copy_field( add_query_arg( (string) Settings::get( 'referral_param' ), $code, home_url( '/' ) ), __( 'Your partner link', 'affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: %s: code */
			echo '<p>' . esc_html( sprintf( __( 'Or tell customers your code %s – they enter it at checkout, no cookies needed.', 'affiliate-captain' ), $code ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Your partner code is being prepared. Please contact us if it does not appear soon.', 'affiliate-captain' ) . '</p>';
		}
		$open = array_filter(
			$steps,
			static function ( $st ) {
				return ! $st[0];
			}
		);
		if ( $open ) {
			echo '<h4>' . esc_html__( 'Your next steps', 'affiliate-captain' ) . '</h4><ul class="wpac-steps">';
			foreach ( $steps as $st ) {
				echo '<li class="' . ( $st[0] ? 'done' : 'todo' ) . '">' . ( $st[0] ? esc_html( $st[1] ) : '<a href="' . esc_url( self::tab_url( $st[2] ) ) . '">' . esc_html( $st[1] ) . '</a>' ) . '<span class="wpac-sr"> – ' . esc_html( $st[0] ? __( 'done', 'affiliate-captain' ) : __( 'open', 'affiliate-captain' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<div class="wpac-cards">';
		foreach ( $cards as $label => $value ) {
			printf( '<div class="wpac-card"><span class="wpac-card__label">%s</span><strong class="wpac-card__value">%s</strong></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
		if ( array_filter( $review ) ) {
			echo '<p class="wpac-muted">' . esc_html__( 'Commissions being reviewed are checked by us before the hold period starts to count; you see the result in the commission list.', 'affiliate-captain' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::tab_url( 'statistics' ) ) . '">' . esc_html__( 'Detailed statistics by period, month and campaign', 'affiliate-captain' ) . '</a></p>';

		echo '<h3>' . esc_html__( 'Your conditions', 'affiliate-captain' ) . '</h3><dl class="wpac-conditions">';
		foreach ( self::conditions( $aff ) as $label => $text ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		$line = self::terms_line( $aff );
		if ( '' !== $line ) {
			echo '<dt>' . esc_html__( 'Terms', 'affiliate-captain' ) . '</dt><dd>' . $line . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in terms_line().
		}
		echo '</dl>';
	}

	/**
	 * Hidden fields for the other query args of the page (e.g. page_id on plain permalinks).
	 *
	 * @param string[] $skip Args set by the form itself.
	 */
	private static function hidden_args( array $skip ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $_GET as $k => $v ) {
			if ( 'wpac_tab' !== $k && ! in_array( $k, $skip, true ) && ! in_array( $k, self::VIEW_ARGS, true ) && is_string( $v ) ) {
				echo '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '">';
			}
		}
	}

	/**
	 * Statistics tab: period filter, figures with conversion rate, monthly earnings (bar chart and
	 * table) and the report by campaign and sub-ID.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_statistics( $aff ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period  = self::period( isset( $_GET['wpac_period'] ) ? sanitize_key( wp_unslash( $_GET['wpac_period'] ) ) : '30' );
		$options = array(
			'7'          => __( 'Last 7 days', 'affiliate-captain' ),
			'30'         => __( 'Last 30 days', 'affiliate-captain' ),
			'90'         => __( 'Last 90 days', 'affiliate-captain' ),
			'365'        => __( 'Last 12 months', 'affiliate-captain' ),
			'month'      => __( 'This month', 'affiliate-captain' ),
			'last_month' => __( 'Last month', 'affiliate-captain' ),
		);
		echo '<form method="get" class="wpac-form wpac-form--inline">';
		self::hidden_args( array( 'wpac_period' ) );
		echo '<input type="hidden" name="wpac_tab" value="statistics"><label for="wpac_period">' . esc_html__( 'Period', 'affiliate-captain' ) . '</label> <select id="wpac_period" name="wpac_period">';
		foreach ( $options as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $period['key'], (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <button type="submit" class="wpac-button">' . esc_html__( 'Show', 'affiliate-captain' ) . '</button></form>';

		$s = \WPAffiliateCaptain\Reports\Reports::partner_summary( (int) $aff->id, $period['from'], $period['to'] );
		echo '<div class="wpac-cards">';
		foreach (
			array(
				__( 'Link visits', 'affiliate-captain' )     => number_format_i18n( $s['visits'] ),
				__( 'Sales', 'affiliate-captain' )           => number_format_i18n( $s['sales'] ),
				__( 'Conversion rate', 'affiliate-captain' ) => number_format_i18n( $s['conversion'], 1 ) . ' %',
				__( 'Commission earned', 'affiliate-captain' ) => self::money_list( $s['earned'] ),
			) as $label => $value
		) {
			printf( '<div class="wpac-card"><span class="wpac-card__label">%s</span><strong class="wpac-card__value">%s</strong></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div><p class="wpac-muted">' . esc_html__( 'Sales count orders that were not rejected or completely refunded. Commission earned includes corrections of refunds.', 'affiliate-captain' ) . '</p>';

		// Monthly earnings: a simple bar chart (decorative, the table below has the same figures).
		$months  = array_reverse( \WPAffiliateCaptain\Reports\Reports::partner_months( (int) $aff->id, 12 ), true );
		$program = strtoupper( (string) Settings::get( 'currency' ) );
		$chart   = $program;
		foreach ( $months as $m ) {
			if ( empty( $m['earned'][ $program ] ) && $m['earned'] ) {
				$chart = (string) key( $m['earned'] );
			}
			if ( ! empty( $m['earned'][ $program ] ) ) {
				$chart = $program;
				break;
			}
		}
		$max = 0;
		foreach ( $months as $m ) {
			$max = max( $max, isset( $m['earned'][ $chart ] ) ? (int) $m['earned'][ $chart ] : 0 );
		}
		echo '<h3>' . esc_html__( 'Earnings per month', 'affiliate-captain' ) . '</h3>';
		if ( $max > 0 ) {
			echo '<div class="wpac-chart" aria-hidden="true">';
			foreach ( $months as $ym => $m ) {
				$value = isset( $m['earned'][ $chart ] ) ? max( 0, (int) $m['earned'][ $chart ] ) : 0;
				printf( '<div class="wpac-chart__col"><span class="wpac-chart__bar" style="height:%d%%" title="%s"></span><span class="wpac-chart__label">%s</span></div>', (int) round( $value * 100 / $max ), esc_attr( Money::format( $value, $chart ) ), esc_html( date_i18n( 'M', strtotime( $ym . '-15 12:00:00 UTC' ) ) ) );
			}
			echo '</div>';
		}
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Earnings per month', 'affiliate-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Month', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Sales', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Commission earned', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $months, true ) as $ym => $m ) {
			printf(
				'<tr><th scope="row" data-label="%4$s">%1$s</th><td data-label="%5$s">%2$s</td><td data-label="%6$s">%3$s</td></tr>',
				esc_html( date_i18n( 'F Y', strtotime( $ym . '-15 12:00:00 UTC' ) ) ),
				esc_html( number_format_i18n( $m['sales'] ) ),
				esc_html( self::money_list( $m['earned'] ) ),
				esc_attr__( 'Month', 'affiliate-captain' ),
				esc_attr__( 'Sales', 'affiliate-captain' ),
				esc_attr__( 'Commission earned', 'affiliate-captain' )
			);
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'By campaign and sub-ID', 'affiliate-captain' ) . '</h3>';
		$rows = \WPAffiliateCaptain\Reports\Reports::partner_campaigns( (int) $aff->id, $period['from'], $period['to'] );
		if ( ! $rows ) {
			echo self::empty_state( __( 'No visits or sales in this period yet. Give your links a campaign or sub-ID in the link generator to see here which placement works best.', 'affiliate-captain' ), __( 'Open the link generator', 'affiliate-captain' ), 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$labels = array( __( 'Campaign', 'affiliate-captain' ), __( 'Sub-ID', 'affiliate-captain' ), __( 'Link visits', 'affiliate-captain' ), __( 'Sales', 'affiliate-captain' ), __( 'Conversion rate', 'affiliate-captain' ), __( 'Commission', 'affiliate-captain' ) );
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'By campaign and sub-ID', 'affiliate-captain' ) . '</caption><thead><tr>';
		foreach ( $labels as $l ) {
			echo '<th scope="col">' . esc_html( $l ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$cells = array( $r['label'], '' !== $r['sub_id'] ? $r['sub_id'] : '—', number_format_i18n( $r['visits'] ), number_format_i18n( $r['orders'] ), number_format_i18n( $r['conversion'], 1 ) . ' %', $r['commission'] ? self::money_list( $r['commission'] ) : '—' );
			echo '<tr>';
			foreach ( $cells as $i => $cell ) {
				echo '<td data-label="' . esc_attr( $labels[ $i ] ) . '">' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * A URL the link generator accepts: a page of this shop, also typed as "www.shop.de/…",
	 * with another letter case, with or without "www." or as a path ("/kurse/"). Returns the
	 * normalized URL on the shop's host, or '' for other websites.
	 *
	 * @param string $target Input.
	 * @return string
	 */
	public static function own_page_url( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}
		if ( '/' === $target[0] && ( ! isset( $target[1] ) || '/' !== $target[1] ) ) {
			$target = rtrim( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ), '/' ) . $target;
		} elseif ( ! preg_match( '#^https?://#i', $target ) ) {
			$target = 'https://' . ltrim( $target, '/' );
		}
		$parts = wp_parse_url( $target );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host  = strtolower( (string) $parts['host'] );
		$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$strip = static function ( $h ) {
			return (string) preg_replace( '/^www\./', '', $h );
		};
		if ( $strip( $host ) === $strip( $home ) ) {
			$host = $home;
			$port = wp_parse_url( home_url(), PHP_URL_PORT );
		} elseif ( Tracker::is_own_host( $host ) ) {
			$port = isset( $parts['port'] ) ? $parts['port'] : null;
		} else {
			return '';
		}
		return (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . $host . ( $port ? ':' . (int) $port : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Partner link built by the generator: code, optional campaign (with source and medium) and
	 * sub-ID.
	 *
	 * @param string $target   Target URL (already checked).
	 * @param string $code     Partner code.
	 * @param string $campaign Campaign.
	 * @param string $source   Source (default "partner" when a campaign is set).
	 * @param string $sub      Sub-ID.
	 * @return string
	 */
	public static function generated_link( $target, $code, $campaign = '', $source = '', $sub = '' ) {
		$args = array( (string) Settings::get( 'referral_param' ) => $code );
		if ( '' !== $campaign ) {
			$args['utm_campaign'] = $campaign;
			$args['utm_source']   = '' !== $source ? $source : 'partner';
			$args['utm_medium']   = 'affiliate';
		} elseif ( '' !== $source ) {
			$args['utm_source'] = $source;
		}
		$sub = Tracker::clean_sub( $sub );
		if ( '' !== $sub ) {
			$args[ Tracker::sub_param() ] = $sub;
		}
		return add_query_arg( $args, $target );
	}

	/**
	 * QR code with text alternative and SVG download.
	 *
	 * @param string $url  Encoded URL.
	 * @param string $name File name part.
	 */
	private static function qr_block( $url, $name ) {
		try {
			$svg = Qr::svg( $url, 6, 4, __( 'QR code of your partner link', 'affiliate-captain' ) );
			echo '<div class="wpac-qr">' . $svg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG, label escaped inside.
			echo '<p><a class="wpac-button" download="qr-' . esc_attr( sanitize_file_name( $name ) ) . '.svg" href="data:image/svg+xml;base64,' . esc_attr( base64_encode( $svg ) ) . '">' . esc_html__( 'Download QR code (SVG)', 'affiliate-captain' ) . '</a></p>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \InvalidArgumentException $e ) {
			echo '<p>' . esc_html__( 'The link is too long for a QR code.', 'affiliate-captain' ) . '</p>';
		}
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
			echo '<h3>' . esc_html__( 'Your partner link', 'affiliate-captain' ) . '</h3>';
			echo self::copy_field( $link, __( 'Your partner link', 'affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html__( 'Your partner code', 'affiliate-captain' ) . ': <strong class="wpac-code">' . esc_html( $main ) . '</strong> ';
			echo '<span class="wpac-muted">' . esc_html__( 'Customers can enter it at checkout – it works without cookies.', 'affiliate-captain' ) . '</span></p>';

			// The short landing URL and its QR code: same effect as the partner link, easier to type and print.
			$landing = Landing::url( $aff );
			echo '<h3>' . esc_html__( 'Short link and QR code', 'affiliate-captain' ) . '</h3>';
			echo '<p class="wpac-muted">' . esc_html__( 'The short link leads to our landing page and works exactly like your partner link – ideal for print, talks and social media profiles.', 'affiliate-captain' ) . '</p>';
			echo self::copy_field( $landing, __( 'Your short link', 'affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::qr_block( $landing, (string) $aff->slug );

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$raw      = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
			$campaign = isset( $_GET['campaign'] ) ? sanitize_title( wp_unslash( $_GET['campaign'] ) ) : '';
			$source   = isset( $_GET['source'] ) ? sanitize_title( wp_unslash( $_GET['source'] ) ) : '';
			$sub      = isset( $_GET['sub'] ) ? Tracker::clean_sub( wp_unslash( $_GET['sub'] ) ) : '';
			// phpcs:enable
			echo '<h3>' . esc_html__( 'Link generator', 'affiliate-captain' ) . '</h3>';
			echo '<form method="get" class="wpac-form wpac-form--inline">';
			self::hidden_args( array( 'target', 'campaign', 'source', 'sub' ) );
			echo '<input type="hidden" name="wpac_tab" value="links">';
			echo '<label>' . esc_html__( 'Target page on our website', 'affiliate-captain' ) . ' <input type="text" inputmode="url" name="target" value="' . esc_attr( $raw ) . '" placeholder="' . esc_attr( home_url( '/' ) ) . '"></label> ';
			echo '<label>' . esc_html__( 'Campaign (optional)', 'affiliate-captain' ) . ' <input type="text" name="campaign" value="' . esc_attr( $campaign ) . '"></label> ';
			echo '<label>' . esc_html__( 'Source (optional, e.g. newsletter)', 'affiliate-captain' ) . ' <input type="text" name="source" value="' . esc_attr( $source ) . '"></label> ';
			echo '<label>' . esc_html__( 'Sub-ID (optional, your own tracking value)', 'affiliate-captain' ) . ' <input type="text" name="sub" maxlength="100" value="' . esc_attr( $sub ) . '"></label> ';
			echo '<button type="submit" class="wpac-button">' . esc_html__( 'Generate', 'affiliate-captain' ) . '</button></form>';
			if ( '' !== $raw ) {
				$target = self::own_page_url( $raw );
				if ( '' === $target ) {
					echo '<div class="wpac-notice wpac-notice--error" role="alert">' . esc_html__( 'Only pages of this website can be linked.', 'affiliate-captain' ) . '</div>';
				} else {
					$generated = self::generated_link( esc_url_raw( $target ), $main, $campaign, $source, $sub );
					echo self::copy_field( $generated, __( 'Generated link', 'affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					self::qr_block( $generated, (string) $aff->slug . '-link' );
				}
			}
			echo '<p class="wpac-muted">' . esc_html__( 'Campaign and sub-ID appear in your statistics, so you can see which placement brings sales.', 'affiliate-captain' ) . '</p>';
		}

		echo \WPAffiliateCaptain\Content\Editor::render_targets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		echo '<h3>' . esc_html__( 'Your codes', 'affiliate-captain' ) . '</h3>';
		if ( ! $codes ) {
			echo '<p>' . esc_html__( 'No codes assigned yet.', 'affiliate-captain' ) . '</p>';
			return;
		}
		$labels = array( __( 'Code', 'affiliate-captain' ), __( 'Coupon', 'affiliate-captain' ), __( 'Used', 'affiliate-captain' ), __( 'Valid until', 'affiliate-captain' ), __( 'Status', 'affiliate-captain' ) );
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Your codes', 'affiliate-captain' ) . '</caption><thead><tr>';
		foreach ( $labels as $l ) {
			echo '<th scope="col">' . esc_html( $l ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $codes as $c ) {
			$valid = (bool) CodeRepository::validate( $c );
			printf(
				'<tr><td data-label="%6$s"><code>%1$s</code></td><td data-label="%7$s">%2$s</td><td data-label="%8$s">%3$s</td><td data-label="%9$s">%4$s</td><td data-label="%10$s">%5$s</td></tr>',
				esc_html( $c->code ),
				esc_html( $c->coupon_code ? strtoupper( $c->coupon_code ) : '—' ),
				esc_html( (int) $c->usage_count . ( (int) $c->usage_limit ? ' / ' . (int) $c->usage_limit : '' ) ),
				esc_html( $c->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $c->expires_at . ' UTC' ) ) : '—' ),
				esc_html( $valid ? __( 'Active', 'affiliate-captain' ) : __( 'Inactive', 'affiliate-captain' ) ),
				esc_attr( $labels[0] ),
				esc_attr( $labels[1] ),
				esc_attr( $labels[2] ),
				esc_attr( $labels[3] ),
				esc_attr( $labels[4] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * HTML snippet to embed a banner: linked image with size, alt text and rel="sponsored".
	 *
	 * @param string $link  Partner link.
	 * @param string $src   Image URL.
	 * @param int    $w     Width.
	 * @param int    $h     Height.
	 * @param string $title Alt text.
	 * @return string
	 */
	public static function embed_code( $link, $src, $w, $h, $title ) {
		return '<a href="' . esc_url( $link ) . '" rel="sponsored noopener" target="_blank"><img src="' . esc_url( $src ) . '"' . ( $w && $h ? ' width="' . (int) $w . '" height="' . (int) $h . '"' : '' ) . ' alt="' . esc_attr( $title ) . '"></a>';
	}

	/**
	 * Creatives tab: guidelines and approved creatives (with sub-ID, embed code, size and original).
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_promotion( $aff ) {
		$code = CodeRepository::main_code( (int) $aff->id );
		if ( '' === $code ) {
			echo '<p>' . esc_html__( 'No active partner code yet.', 'affiliate-captain' ) . '</p>';
			return;
		}
		/* translators: %s: link to the tab */
		echo '<p class="wpac-muted">' . sprintf( esc_html__( 'Your short link and QR code are in the tab %s.', 'affiliate-captain' ), '<a href="' . esc_url( self::tab_url( 'links' ) ) . '">' . esc_html__( 'Links & codes', 'affiliate-captain' ) . '</a>' ) . '</p>';

		$guidelines = (string) Settings::get( 'promotion_guidelines' );
		if ( '' !== trim( $guidelines ) ) {
			echo '<h3>' . esc_html__( 'Promotion guidelines', 'affiliate-captain' ) . '</h3><div class="wpac-guidelines">' . wp_kses_post( wpautop( $guidelines ) ) . '</div>';
		}

		$creatives = Creatives::approved_for_group( (int) $aff->group_id );
		echo '<h3>' . esc_html__( 'Approved creatives', 'affiliate-captain' ) . '</h3>';
		if ( ! $creatives ) {
			echo self::empty_state( __( 'No creatives available yet. You can already use your partner link and QR code.', 'affiliate-captain' ), __( 'Get your link', 'affiliate-captain' ), 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sub = isset( $_GET['wpac_sub'] ) ? Tracker::clean_sub( wp_unslash( $_GET['wpac_sub'] ) ) : '';
		echo '<form method="get" class="wpac-form wpac-form--inline">';
		self::hidden_args( array( 'wpac_sub' ) );
		echo '<input type="hidden" name="wpac_tab" value="promotion"><label>' . esc_html__( 'Sub-ID for the links below (optional, e.g. the placement)', 'affiliate-captain' ) . ' <input type="text" name="wpac_sub" maxlength="100" value="' . esc_attr( $sub ) . '"></label> <button type="submit" class="wpac-button">' . esc_html__( 'Apply', 'affiliate-captain' ) . '</button></form>';
		foreach ( $creatives as $c ) {
			echo '<div class="wpac-creative"><h4>' . esc_html( $c->title ) . ' <small class="wpac-muted">v' . (int) $c->version . '</small></h4>';
			/* translators: %s: disclosure note */
			echo '<p class="wpac-disclosure">' . esc_html( sprintf( __( 'Mandatory label: "%s" – clearly visible at the beginning of your post.', 'affiliate-captain' ), $c->disclosure ) ) . '</p>';
			$link = Creatives::link( $c, $code, $sub );
			if ( 'image' === $c->type ) {
				$full = wp_get_attachment_image_src( (int) $c->attachment_id, 'full' );
				echo wp_get_attachment_image( (int) $c->attachment_id, 'medium' );
				if ( $full ) {
					list( $src, $w, $h ) = $full;
					echo '<p>';
					if ( $w && $h ) {
						/* translators: 1: width, 2: height */
						echo esc_html( sprintf( __( 'Size: %1$d × %2$d pixels', 'affiliate-captain' ), (int) $w, (int) $h ) ) . ' · ';
					}
					echo '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener">' . esc_html__( 'View original', 'affiliate-captain' ) . '</a> · <a href="' . esc_url( $src ) . '" download>' . esc_html__( 'Download image', 'affiliate-captain' ) . '</a></p>';
					echo self::copy_field( self::embed_code( $link, $src, (int) $w, (int) $h, $c->title ), __( 'Embed code (HTML)', 'affiliate-captain' ) . ': ' . $c->title, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo self::copy_field( $link, __( 'Link', 'affiliate-captain' ) . ': ' . $c->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $c->content ) {
				echo self::copy_field( Creatives::personalize( $c, $code, $aff->name, $sub ), __( 'Text', 'affiliate-captain' ) . ': ' . $c->title, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
	}

	/**
	 * Filter form of the commission/payout lists with the CSV export link.
	 *
	 * @param string $kind     commissions|payouts.
	 * @param array  $statuses status => label.
	 * @param array  $filters  Current filters.
	 */
	private static function list_filter_form( $kind, array $statuses, array $filters ) {
		echo '<form method="get" class="wpac-form wpac-form--inline wpac-filters">';
		self::hidden_args( array() );
		echo '<input type="hidden" name="wpac_tab" value="' . esc_attr( $kind ) . '">';
		echo '<label>' . esc_html__( 'Status', 'affiliate-captain' ) . ' <select name="wpac_status"><option value="">' . esc_html__( 'All', 'affiliate-captain' ) . '</option>';
		foreach ( $statuses as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filters['status'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'From', 'affiliate-captain' ) . ' <input type="date" name="wpac_from" value="' . esc_attr( $filters['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'affiliate-captain' ) . ' <input type="date" name="wpac_to" value="' . esc_attr( $filters['to'] ) . '"></label> ';
		echo '<button type="submit" class="wpac-button">' . esc_html__( 'Filter', 'affiliate-captain' ) . '</button> ';
		$csv = wp_nonce_url(
			add_query_arg(
				array(
					'wpac_csv'    => $kind,
					'wpac_status' => $filters['status'],
					'wpac_from'   => $filters['from'],
					'wpac_to'     => $filters['to'],
				),
				home_url( '/' )
			),
			'wpac_csv_' . $kind
		);
		echo '<a href="' . esc_url( $csv ) . '">' . esc_html__( 'Download as CSV', 'affiliate-captain' ) . '</a></form>';
	}

	/**
	 * Commissions tab (minimized data).
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_commissions( $aff ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['wpac_page'] ) ? max( 1, (int) $_GET['wpac_page'] ) : 1;
		$filters = self::list_filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in list_filters().
		$labels  = Ledger::status_labels();
		list( $rows, $total ) = Ledger::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'status'       => isset( $labels[ $filters['status'] ] ) ? $filters['status'] : '',
				'from'         => $filters['from'],
				'to'           => $filters['to'],
				'limit'        => 25,
				'offset'       => ( $page - 1 ) * 25,
			)
		);
		$filtered = '' !== $filters['status'] . $filters['from'] . $filters['to'];
		if ( ! $rows && ! $filtered ) {
			echo self::empty_state( __( 'No commissions yet. As soon as a customer buys via your link or code, the commission appears here.', 'affiliate-captain' ), __( 'Get your link', 'affiliate-captain' ), 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		self::list_filter_form( 'commissions', $labels, $filters );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No commissions match the filter.', 'affiliate-captain' ) . '</p>';
			return;
		}
		$reasons = self::rejection_reasons( $rows );
		$cols    = array( __( 'Date', 'affiliate-captain' ), __( 'Order', 'affiliate-captain' ), __( 'Product', 'affiliate-captain' ), __( 'Base', 'affiliate-captain' ), __( 'Rate', 'affiliate-captain' ), __( 'Amount', 'affiliate-captain' ), __( 'Status', 'affiliate-captain' ), __( 'Available from', 'affiliate-captain' ) );
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Your commissions', 'affiliate-captain' ) . '</caption><thead><tr>';
		foreach ( $cols as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$product = in_array( $r->kind, array( 'sale', 'bonus' ), true ) ? $r->description : sprintf( '%s (%s)', __( 'Correction', 'affiliate-captain' ), self::kind_label( $r->kind ) );
			$status  = self::status( $r->status, isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status );
			if ( 'rejected' === $r->status && ! empty( $reasons[ (int) $r->id ] ) ) {
				// Partners see why a commission was rejected.
				$status .= '<br><small class="wpac-muted">' . esc_html( $reasons[ (int) $r->id ] ) . '</small>';
			}
			// "Available from" only while the hold period runs; a commission under review has no date yet.
			if ( 'pending' === $r->status && $r->hold_until ) {
				$available = date_i18n( get_option( 'date_format' ), strtotime( $r->hold_until . ' UTC' ) );
			} elseif ( 'review' === $r->status ) {
				$available = __( 'after our review', 'affiliate-captain' );
			} else {
				$available = '—';
			}
			$cells = array(
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r->created_at . ' UTC' ) ) ),
				esc_html( 'wpac' === $r->source ? '—' : '#' . $r->source_order_id ),
				esc_html( $product ),
				esc_html( 'sale' === $r->kind && (int) $r->base_minor ? Money::format( (int) $r->base_minor, $r->currency ) : '—' ),
				esc_html( 'sale' === $r->kind && '' !== (string) $r->rate_type && in_array( $r->rate_type, array( 'percent', 'fixed' ), true ) ? \WPAffiliateCaptain\Commissions\RuleRepository::format_rate( $r->rate_type, $r->rate, $r->currency ) : '—' ),
				'<span class="' . ( (int) $r->amount_minor < 0 ? 'wpac-negative' : '' ) . '">' . esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ) . '</span>',
				$status,
				esc_html( $available ),
			);
			echo '<tr>';
			foreach ( $cells as $i => $cell ) {
				echo '<td data-label="' . esc_attr( $cols[ $i ] ) . '">' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			echo '</tr>';
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
		return \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $kind );
	}

	/**
	 * Payouts tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_payouts( $aff ) {
		if ( Settings::get( 'payout_requests' ) ) {
			$open      = PayoutRequests::open_for( (int) $aff->id );
			$amounts   = PayoutRequests::available_all( (int) $aff->id );
			$shown     = array();
			foreach ( $amounts as $cur => $a ) {
				$shown[] = Money::format( $a['gross'], $cur );
			}
			// What a payout would pay now (incl. VAT, without commissions held back by an open correction).
			/* translators: %s: amount(s) */
			echo '<p>' . esc_html( sprintf( __( 'Available for payout: %s', 'affiliate-captain' ), $shown ? implode( ' · ', $shown ) : Money::format( 0, Settings::get( 'currency' ) ) ) ) . '</p>';
			$req_cur   = PayoutRequests::request_currency( (int) $aff->id );
			$available = '' !== $req_cur ? (int) $amounts[ $req_cur ]['gross'] : 0;
			if ( $open ) {
				/* translators: %s: date */
				echo '<div class="wpac-notice">' . esc_html( sprintf( __( 'Payout requested on %s – in progress.', 'affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $open->created_at . ' UTC' ) ) ) ) . '</div>';
			} else {
				$last = PayoutRequests::last_rejected( (int) $aff->id );
				if ( $last ) {
					/* translators: 1: date, 2: reason */
					echo '<div class="wpac-notice wpac-notice--error">' . esc_html( sprintf( __( 'Your payout request of %1$s was not processed: %2$s', 'affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $last->created_at . ' UTC' ) ), $last->note ) ) . '</div>';
				}
				$can = PayoutRequests::can_request( $aff );
				if ( true === $can ) {
					echo '<form method="post">';
					wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
					/* translators: %s: amount */
					echo '<input type="hidden" name="wpac_portal_action" value="payout_request"><button class="wpac-button">' . esc_html( sprintf( __( 'Request payout of %s', 'affiliate-captain' ), Money::format( $available, '' !== $req_cur ? $req_cur : (string) Settings::get( 'currency' ) ) ) ) . '</button></form>';
				} else {
					echo '<p class="wpac-muted">' . esc_html( $can ) . '</p>';
				}
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['wpac_page'] ) ? max( 1, (int) $_GET['wpac_page'] ) : 1;
		$filters = self::list_filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in list_filters().
		$labels  = PayoutService::status_labels();
		list( $rows, $total ) = PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'status'       => isset( $labels[ $filters['status'] ] ) ? $filters['status'] : '',
				'limit'        => 25,
				'offset'       => ( $page - 1 ) * 25,
			)
		);
		if ( ! $rows && '' === $filters['status'] ) {
			echo self::empty_state( __( 'No payouts yet. Approved commissions are paid out once the minimum amount is reached.', 'affiliate-captain' ), ProfileRepository::is_complete( ProfileRepository::get( (int) $aff->id ) ) ? '' : __( 'Complete payout details', 'affiliate-captain' ), 'profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		self::list_filter_form( 'payouts', $labels, $filters );
		echo '<table class="wpac-table"><caption class="wpac-sr">' . esc_html__( 'Your payouts', 'affiliate-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html_x( 'Number', 'document number', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Period until', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Amount', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Credit note', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			if ( 'cancelled' === $p->status && ! $p->storno_file ) {
				continue;
			}
			$url = static function ( $storno ) use ( $p ) {
				return wp_nonce_url( add_query_arg( array( 'wpac_statement' => (int) $p->id, 'storno' => $storno ? 1 : 0 ), home_url( '/' ) ), 'wpac_statement_' . (int) $p->id );
			};
			$pdf = $p->statement_file ? '<a href="' . esc_url( $url( false ) ) . '">' . esc_html__( 'Credit note', 'affiliate-captain' ) . '</a>' : '—';
			if ( $p->storno_file ) {
				$pdf .= ' · <a href="' . esc_url( $url( true ) ) . '">' . esc_html__( 'Cancellation', 'affiliate-captain' ) . '</a>';
			}
			printf(
				'<tr><td data-label="%6$s">%1$s</td><td data-label="%7$s">%2$s</td><td data-label="%8$s">%3$s</td><td data-label="%9$s">%4$s</td><td data-label="%10$s">%5$s</td></tr>',
				esc_html( $p->number ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->period_end ) ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				self::payout_status( $p, $labels ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$pdf, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_attr_x( 'Number', 'document number', 'affiliate-captain' ),
				esc_attr__( 'Period until', 'affiliate-captain' ),
				esc_attr__( 'Amount', 'affiliate-captain' ),
				esc_attr__( 'Status', 'affiliate-captain' ),
				esc_attr__( 'Credit note', 'affiliate-captain' )
			);
		}
		echo '</tbody></table>';
		self::pager( $page, $total, 25 );
		// Annual overviews for the partner's tax return.
		// Years in the site's time zone, including years with only cancellations.
		$years = Statement::annual_years( (int) $aff->id );
		if ( $years ) {
			echo '<p>' . esc_html__( 'Annual overview:', 'affiliate-captain' ) . ' ';
			$links = array();
			foreach ( $years as $yr ) {
				$links[] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'wpac_annual', $yr, home_url( '/' ) ), 'wpac_annual_' . $yr ) ) . '">' . esc_html( (string) $yr ) . '</a>';
			}
			echo implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		// Objection to a credit note (§14 Abs. 2 S. 3 UStG): the recent credit notes, independent of the page.
		$objectable = array();
		list( $recent ) = PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 50,
			)
		);
		foreach ( $recent as $p ) {
			if ( 'cancelled' !== $p->status && $p->statement_file ) {
				$objectable[] = $p;
			}
		}
		foreach ( $rows as $p ) {
			$o = \WPAffiliateCaptain\Payouts\Objections::for_payout( (int) $p->id );
			if ( $o ) {
				/* translators: 1: number, 2: status */
				echo '<p class="wpac-muted">' . esc_html( sprintf( __( 'Objection to %1$s: %2$s', 'affiliate-captain' ), $p->number, 'open' === $o['status'] ? __( 'being reviewed', 'affiliate-captain' ) : $o['resolution'] ) ) . '</p>';
			}
		}
		if ( Settings::get( 'self_billing' ) && $objectable ) {
			$kept = self::flash( 'wpac_objection_form_' . get_current_user_id() );
			echo '<details class="wpac-objection" id="wpac-objection"' . ( is_array( $kept ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Something is wrong with a credit note?', 'affiliate-captain' ) . '</summary><form method="post" class="wpac-form">';
			wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
			echo '<input type="hidden" name="wpac_portal_action" value="objection"><p><label for="wpac_obj_payout">' . esc_html__( 'Credit note', 'affiliate-captain' ) . '</label><select id="wpac_obj_payout" name="wpac_obj_payout">';
			foreach ( $objectable as $p ) {
				echo '<option value="' . (int) $p->id . '"' . selected( is_array( $kept ) ? (int) $kept['payout'] : 0, (int) $p->id, false ) . '>' . esc_html( $p->number ) . '</option>';
			}
			echo '</select></p><p><label for="wpac_obj_reason">' . esc_html__( 'What is incorrect?', 'affiliate-captain' ) . '</label><textarea id="wpac_obj_reason" name="wpac_obj_reason" rows="3" required' . ( is_array( $kept ) ? ' aria-describedby="wpac-portal-error"' : '' ) . '>' . esc_textarea( is_array( $kept ) ? (string) $kept['reason'] : '' ) . '</textarea></p><p><button type="submit" class="wpac-button">' . esc_html__( 'Object to the credit note', 'affiliate-captain' ) . '</button></p></form></details>';
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
		echo '<h3>' . esc_html__( 'What we store about you', 'affiliate-captain' ) . '</h3><ul>';
		foreach (
			array(
				__( 'Your account: name or company, e-mail, website, channel and your application.', 'affiliate-captain' ),
				__( 'Your partner codes and the accepted terms (version, time and the accepted text).', 'affiliate-captain' ),
				__( 'Visits through your links (without IP address) and the orders attributed to you (order number and amount, never the customer\'s name).', 'affiliate-captain' ),
				__( 'For payouts: legal name, address, tax status, VAT ID or tax number and bank details (the IBAN is stored encrypted), your commissions, payouts and credit notes.', 'affiliate-captain' ),
			) as $line
		) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html(
			$years > 0
				/* translators: %d: years */
				? sprintf( __( 'Commission records and credit notes are kept for %d years after the end of the calendar year (statutory retention), everything else only as long as you are a partner.', 'affiliate-captain' ), $years )
				: __( 'Commission records and credit notes are kept for the statutory retention period, everything else only as long as you are a partner.', 'affiliate-captain' )
		) . '</p>';
		if ( $policy ) {
			echo '<p><a href="' . esc_url( $policy ) . '">' . esc_html__( 'Full privacy policy', 'affiliate-captain' ) . '</a></p>';
		}
		echo '<h3>' . esc_html__( 'Your rights', 'affiliate-captain' ) . '</h3><p>' . esc_html__( 'You can request a copy of your data, its erasure, the restriction of its processing, or object to processing based on our legitimate interest. Copy and erasure are confirmed by e-mail first. Records we must keep by law are restricted instead of deleted until the retention period ends.', 'affiliate-captain' ) . '</p>';
		$kept = self::flash( 'wpac_privacy_form_' . get_current_user_id() );
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="privacy"><p><label for="wpac_privacy_type">' . esc_html__( 'Request', 'affiliate-captain' ) . '</label><select id="wpac_privacy_type" name="wpac_privacy_type">';
		foreach (
			array(
				'export'   => __( 'Copy of my data (export)', 'affiliate-captain' ),
				'erase'    => __( 'Erase my data', 'affiliate-captain' ),
				'restrict' => __( 'Restrict processing', 'affiliate-captain' ),
				'object'   => __( 'Object to processing', 'affiliate-captain' ),
			) as $k => $l
		) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( is_array( $kept ) ? (string) $kept['type'] : '', $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p><label for="wpac_privacy_note">' . esc_html__( 'Details (optional; required for restriction and objection)', 'affiliate-captain' ) . '</label><textarea id="wpac_privacy_note" name="wpac_privacy_note" rows="3"' . ( is_array( $kept ) ? ' aria-describedby="wpac-portal-error"' : '' ) . '>' . esc_textarea( is_array( $kept ) ? (string) $kept['note'] : '' ) . '</textarea></p><p><button type="submit" class="wpac-button">' . esc_html__( 'Send request', 'affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * Payout details tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_profile( $aff ) {
		$p    = ProfileRepository::get( (int) $aff->id );
		$kept = self::flash( 'wpac_profile_form_' . get_current_user_id() );
		if ( is_array( $kept ) ) {
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
		echo '<p><label for="wpac_tax_status">' . esc_html__( 'Tax status', 'affiliate-captain' ) . ' *</label><select id="wpac_tax_status" name="wpac_tax_status" required><option value="">—</option>';
		foreach ( $labels as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $v( 'tax_status' ), $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		self::input( 'legal_name', __( 'Name / company for the statement', 'affiliate-captain' ), $v( 'legal_name' ), true );
		echo '<p><label for="wpac_address">' . esc_html__( 'Address', 'affiliate-captain' ) . ' *</label><textarea id="wpac_address" name="wpac_address" rows="3" required>' . esc_textarea( $v( 'address' ) ) . '</textarea></p>';
		self::input( 'country', __( 'Country (ISO code, e.g. DE)', 'affiliate-captain' ), $v( 'country' ), true, 'maxlength="2"' );
		self::input( 'vat_id', __( 'VAT ID (businesses)', 'affiliate-captain' ), $v( 'vat_id' ) );
		$tax_pending = \WPAffiliateCaptain\Payouts\TaxHistory::pending( (int) $aff->id );
		if ( $tax_pending ) {
			$pending_status = isset( $tax_pending['new']['tax_status'], $labels[ $tax_pending['new']['tax_status'] ] ) ? $labels[ $tax_pending['new']['tax_status'] ] : '—';
			/* translators: 1: tax status, 2: VAT ID */
			echo '<div class="wpac-notice" role="status">' . esc_html( sprintf( __( 'Your change of the tax data (%1$s, VAT ID %2$s) waits for our approval. Until then your previous tax data are used.', 'affiliate-captain' ), $pending_status, '' !== (string) $tax_pending['new']['vat_id'] ? (string) $tax_pending['new']['vat_id'] : '—' ) ) . '</div>';
		}
		$vies = $p && '' !== (string) $p->vat_id ? \WPAffiliateCaptain\Payouts\Vies::result_for( (int) $aff->id, array( (string) $p->vat_id ) ) : null;
		if ( $vies ) {
			if ( 'invalid' === $vies['status'] ) {
				echo '<div class="wpac-notice wpac-notice--error" role="alert">' . esc_html__( 'According to the EU VIES database, this VAT ID is not valid. Please check it (country prefix, digits) and save it again. Until it is confirmed, we cannot issue credit notes with VAT or reverse charge.', 'affiliate-captain' ) . '</div>';
			} elseif ( 'valid' === $vies['status'] ) {
				echo '<p class="wpac-muted">✔ ' . esc_html__( 'VAT ID confirmed by the EU VIES database.', 'affiliate-captain' ) . '</p>';
			} elseif ( 'unavailable' === $vies['status'] ) {
				echo '<p class="wpac-muted">' . esc_html__( 'The EU VIES database could not be reached to check your VAT ID; it is checked again automatically.', 'affiliate-captain' ) . '</p>';
			}
		}
		self::input( 'tax_number', __( 'Tax number (if you have no VAT ID)', 'affiliate-captain' ), $v( 'tax_number' ) );
		// Payout method: SEPA bank transfer (IBAN) or another method with free-text payment details.
		$stored_method = ProfileRepository::method( $p );
		$method        = is_array( $kept ) && isset( $kept['values']['payout_method'] ) && '' !== $kept['values']['payout_method'] ? (string) $kept['values']['payout_method'] : $stored_method;
		$manual        = ProfileRepository::METHOD_MANUAL === $method;
		// Switching the fields is in assets/js/portal.js (data-wpac-iban-required: an IBAN is needed
		// unless one is stored).
		$masked = $p && ProfileRepository::METHOD_SEPA === $stored_method ? Iban::mask( $p->iban_last4, $p->iban_country ) : '';
		echo '<p><label for="wpac_payout_method">' . esc_html__( 'Payout method', 'affiliate-captain' ) . ' *</label><select id="wpac_payout_method" name="wpac_payout_method" data-wpac-iban-required="' . ( $masked ? '0' : '1' ) . '" data-wpac-details-required="' . ( ProfileRepository::METHOD_MANUAL === $stored_method ? '0' : '1' ) . '">';
		foreach ( ProfileRepository::method_options( $p ) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $method, $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		echo '<div class="wpac-method-sepa"' . ( $manual ? ' hidden' : '' ) . '>';
		self::input( 'account_holder', __( 'Account holder', 'affiliate-captain' ), $v( 'account_holder' ), ! $manual );
		self::input( 'iban', __( 'IBAN', 'affiliate-captain' ) . ( $masked ? ' (' . __( 'stored', 'affiliate-captain' ) . ': ' . $masked . ')' : '' ), '', ! $masked && ! $manual, 'autocomplete="off" placeholder="' . esc_attr( $masked ? __( 'Leave empty to keep', 'affiliate-captain' ) : 'DE00 0000 0000 0000 0000 00' ) . '"' );
		self::input( 'bic', __( 'BIC (optional)', 'affiliate-captain' ), $v( 'bic' ) );
		echo '</div>';
		$has_details = ProfileRepository::METHOD_MANUAL === $stored_method;
		// The partner sees what is stored (masked like the IBAN), not only "stored".
		$stored_details = $has_details ? ProfileRepository::payment_details( $p ) : null;
		$details_label  = __( 'Payment details', 'affiliate-captain' ) . ( null !== $stored_details && '' !== $stored_details ? ' (' . __( 'stored', 'affiliate-captain' ) . ': ' . ProfileRepository::mask_details( str_replace( array( "\r\n", "\r", "\n" ), ' / ', $stored_details ) ) . ')' : '' );
		echo '<div class="wpac-method-manual"' . ( $manual ? '' : ' hidden' ) . '><p><label for="wpac_payment_details">' . esc_html( $details_label ) . ( $has_details ? '' : ' *' ) . '</label><textarea id="wpac_payment_details" name="wpac_payment_details" rows="3" autocomplete="off"' . ( $manual && ! $has_details ? ' required' : '' ) . ' placeholder="' . esc_attr( $has_details ? __( 'Leave empty to keep', 'affiliate-captain' ) : __( 'e.g. PayPal: name@example.com', 'affiliate-captain' ) ) . '"' . ( 'payment_details' === self::$invalid ? ' aria-invalid="true" aria-describedby="wpac-portal-error" autofocus' : '' ) . '></textarea></p>';
		echo '<p class="wpac-muted">' . esc_html__( 'Where and how we should send your commission (e.g. PayPal address, Wise or foreign bank account). We pay these payouts manually.', 'affiliate-captain' ) . '</p></div>';
		if ( $p && '' !== (string) $p->iban_encrypted ) {
			// Needed only when the IBAN, the payment details or the method change (checked on saving).
			echo '<p><label for="wpac_current_password">' . esc_html__( 'Current password (only needed to change your bank or payment details)', 'affiliate-captain' ) . '</label><input type="password" id="wpac_current_password" name="wpac_current_password" autocomplete="current-password"' . ( 'current_password' === self::$invalid ? ' aria-invalid="true" aria-describedby="wpac-portal-error" autofocus' : '' ) . '></p>';
		}
		echo '<p class="wpac-muted">' . esc_html__( 'Your bank and payment details are stored encrypted and are only used for commission payouts.', 'affiliate-captain' ) . '</p>';
		echo '<p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'affiliate-captain' ) . '</button></p></form>';
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
		$pending = \WPAffiliateCaptain\Core\Pending::get( 'email_change_' . (int) $aff->id );
		$pending = null !== $pending ? $pending : get_transient( 'wpac_email_change_' . (int) $aff->id ); // Older version.
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="account">';
		self::input( 'acc_name', __( 'Name / company', 'affiliate-captain' ), (string) $aff->name, true );
		printf( '<p><label for="wpac_acc_email">%1$s *</label><input type="email" id="wpac_acc_email" name="wpac_acc_email" value="%2$s" required autocomplete="email"></p>', esc_html__( 'E-mail for notifications', 'affiliate-captain' ), esc_attr( (string) $aff->email ) );
		if ( is_array( $pending ) ) {
			/* translators: %s: e-mail */
			echo '<p class="wpac-muted">' . esc_html( sprintf( __( 'Waiting for confirmation of %s.', 'affiliate-captain' ), $pending['email'] ) ) . '</p>';
		}
		printf( '<p><label for="wpac_acc_website">%1$s</label><input type="url" id="wpac_acc_website" name="wpac_acc_website" value="%2$s"></p>', esc_html__( 'Website', 'affiliate-captain' ), esc_attr( (string) $aff->website ) );
		self::input( 'acc_channel', __( 'Channel (e.g. blog, Instagram, sailing school)', 'affiliate-captain' ), (string) $aff->channel );
		echo '<p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'affiliate-captain' ) . '</button></p></form>';

		// E-mail preferences: optional mails (the same setting as the unsubscribe link in the mails).
		echo '<h3 id="wpac-mail-prefs">' . esc_html__( 'E-mail notifications', 'affiliate-captain' ) . '</h3><form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="notifications"><fieldset><legend class="wpac-sr">' . esc_html__( 'E-mail notifications', 'affiliate-captain' ) . '</legend>';
		foreach ( \WPAffiliateCaptain\Affiliates\Notifications::optout_labels() as $type => $label ) {
			echo '<p class="wpac-check"><label><input type="checkbox" name="wpac_mail[]" value="' . esc_attr( $type ) . '"' . checked( ! \WPAffiliateCaptain\Affiliates\Notifications::opted_out( (int) $aff->id, $type ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset><p class="wpac-muted">' . esc_html__( 'Mails about payouts, credit notes, your account and changed terms are always sent.', 'affiliate-captain' ) . '</p><p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'affiliate-captain' ) . '</button></p></form>';

		$line = self::terms_line( $aff );
		if ( '' !== $line ) {
			echo '<h3>' . esc_html__( 'Terms', 'affiliate-captain' ) . '</h3><p>' . $line . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in terms_line().
		}
		$admin = (string) Settings::get( 'admin_email' );
		$admin = is_email( $admin ) ? $admin : (string) get_option( 'admin_email' );
		echo '<p class="wpac-muted">' . sprintf(
			/* translators: 1: link to the privacy tab, 2: e-mail link */
			esc_html__( 'To delete your data or get a copy, use the %1$s tab. To end the partnership, write to %2$s.', 'affiliate-captain' ),
			'<a href="' . esc_url( self::tab_url( 'privacy' ) ) . '">' . esc_html__( 'Privacy', 'affiliate-captain' ) . '</a>',
			'<a href="mailto:' . esc_attr( $admin ) . '">' . esc_html( $admin ) . '</a>'
		) . '</p>';
	}

	/** @var string Field with the last validation error (profile form). */
	private static $invalid = '';

	/**
	 * Pager: first, last and two pages around the current one; links without old messages.
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
		$base = remove_query_arg( array( 'wpac_msg', 'wpac_page' ) );
		echo '<nav class="wpac-pager" aria-label="' . esc_attr__( 'Pages', 'affiliate-captain' ) . '">';
		$last = 0;
		foreach ( self::pager_pages( $page, $pages ) as $i ) {
			if ( $last && $i > $last + 1 ) {
				echo '<span aria-hidden="true">…</span> ';
			}
			printf( '<a href="%s" class="%s"%s>%d</a> ', esc_url( add_query_arg( 'wpac_page', $i, $base ) ), $i === $page ? 'is-active' : '', $i === $page ? ' aria-current="page"' : '', (int) $i );
			$last = $i;
		}
		echo '</nav>';
	}

	/**
	 * Page numbers the pager links to.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Number of pages.
	 * @return int[]
	 */
	public static function pager_pages( $page, $pages ) {
		$out = array();
		for ( $i = 1; $i <= $pages; $i++ ) {
			if ( 1 === $i || $pages === $i || abs( $i - $page ) <= 2 ) {
				$out[] = $i;
			}
		}
		return $out;
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
			$date = date_i18n( get_option( 'date_format' ), strtotime( $p->paid_at . ' UTC' ) );
			if ( ProfileRepository::METHOD_MANUAL === PayoutService::snapshot_method( $p ) ) {
				// PayPal, Wise …: no bank transfer.
				/* translators: %s: date */
				return self::status( 'paid', sprintf( __( 'Paid on %s', 'affiliate-captain' ), $date ) );
			}
			/* translators: %s: date */
			return self::status( 'paid', sprintf( __( 'Transferred on %s', 'affiliate-captain' ), $date ) );
		}
		if ( in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
			return self::status( 'pending', __( 'In progress', 'affiliate-captain' ) );
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
