<?php
/**
 * Affiliates: persistence and lifecycle.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class AffiliateRepository {

	const STATUSES = array( 'pending', 'active', 'paused', 'terminated', 'rejected' );

	/**
	 * Translated status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'    => __( 'Application pending', 'affiliate-captain' ),
			'active'     => __( 'Active', 'affiliate-captain' ),
			'paused'     => __( 'Paused', 'affiliate-captain' ),
			'terminated' => __( 'Terminated', 'affiliate-captain' ),
			'rejected'   => __( 'Rejected', 'affiliate-captain' ),
		);
	}

	/**
	 * Find by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! array_key_exists( $id, self::$cache ) ) {
			$t = Db::table( 'affiliates' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$cache[ $id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
		}
		return self::$cache[ $id ];
	}

	/** @var array Per-request cache id => row|null. */
	private static $cache = array();

	/**
	 * Load many affiliates into the cache with one query.
	 *
	 * @param int[] $ids IDs.
	 * @return array id => row
	 */
	public static function find_many( array $ids ) {
		global $wpdb;
		$ids     = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$missing = array_values( array_diff( $ids, array_keys( self::$cache ) ) );
		if ( $missing ) {
			$t            = Db::table( 'affiliates' );
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %d placeholders built above.
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$placeholders})", $missing ) ) as $row ) {
				self::$cache[ (int) $row->id ] = $row;
			}
			foreach ( $missing as $id ) {
				if ( ! array_key_exists( $id, self::$cache ) ) {
					self::$cache[ $id ] = null;
				}
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::$cache[ $id ];
		}
		return $out;
	}

	/**
	 * Invalidate the per-request cache.
	 *
	 * @param int $id Affiliate (0 = all).
	 */
	public static function clear_cache( $id = 0 ) {
		if ( $id ) {
			unset( self::$cache[ (int) $id ] );
		} else {
			self::$cache = array();
		}
	}

	/**
	 * Find by WordPress user.
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function find_by_user( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$t = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ) );
	}

	/**
	 * Find by e-mail.
	 *
	 * @param string $email E-mail.
	 * @return object|null
	 */
	public static function find_by_email( $email ) {
		global $wpdb;
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return null;
		}
		$t = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) );
	}

	/**
	 * Find by vanity slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function find_by_slug( $slug ) {
		global $wpdb;
		$t = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", sanitize_title( $slug ) ) );
	}

	/**
	 * Normalize an e-mail for storage and lookups (trimmed, lower-case).
	 *
	 * @param string $email E-mail.
	 * @return string
	 */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Comparable form for self-referral checks: "+tags" removed, Gmail dots ignored.
	 *
	 * @param string $email E-mail.
	 * @return string
	 */
	public static function comparable_email( $email ) {
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return '';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		$local                  = explode( '+', $local, 2 )[0];
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/**
	 * Create an affiliate.
	 *
	 * @param array $data name, email, website, channel, application_note, user_id, group_id, status, slug.
	 * @return int|\WP_Error New ID.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$email = sanitize_email( isset( $data['email'] ) ? $data['email'] : '' );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wpac_invalid_email', __( 'Please enter a valid e-mail address.', 'affiliate-captain' ) );
		}
		if ( self::find_by_email( $email ) ) {
			return new \WP_Error( 'wpac_exists', __( 'An affiliate account with this e-mail address already exists.', 'affiliate-captain' ) );
		}
		$name   = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'pending';
		$slug   = self::unique_slug( ! empty( $data['slug'] ) ? $data['slug'] : $name );
		$now    = Db::now();

		$ok = $wpdb->insert(
			Db::table( 'affiliates' ),
			array(
				'user_id'          => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'group_id'         => isset( $data['group_id'] ) ? (int) $data['group_id'] : 0,
				'status'           => $status,
				'slug'             => $slug,
				'name'             => $name,
				'email'            => self::normalize_email( $email ),
				'website'          => esc_url_raw( isset( $data['website'] ) ? $data['website'] : '' ),
				'channel'          => sanitize_text_field( isset( $data['channel'] ) ? $data['channel'] : '' ),
				'application_note' => sanitize_textarea_field( isset( $data['application_note'] ) ? $data['application_note'] : '' ),
				'application_data' => ! empty( $data['application_data'] ) ? wp_json_encode( $data['application_data'] ) : null,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'wpac_db', __( 'The affiliate could not be saved.', 'affiliate-captain' ) );
		}
		$id = (int) $wpdb->insert_id;
		self::clear_cache( $id );
		Audit::log( 'affiliate.created', 'affiliate', $id, array( 'status' => $status ) );

		if ( 'active' === $status ) {
			self::on_activated( $id );
		}
		/**
		 * A partner account was created (admin, application or API).
		 *
		 * @param int    $id     Affiliate ID.
		 * @param string $status Initial status.
		 */
		do_action( 'wpac_affiliate_created', $id, $status );
		return $id;
	}

	/**
	 * Update editable profile fields.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$fields = array();
		if ( isset( $data['name'] ) ) {
			$fields['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['website'] ) ) {
			$fields['website'] = esc_url_raw( $data['website'] );
		}
		if ( isset( $data['channel'] ) ) {
			$fields['channel'] = sanitize_text_field( $data['channel'] );
		}
		if ( isset( $data['group_id'] ) ) {
			$fields['group_id'] = (int) $data['group_id'];
		}
		if ( isset( $data['landing_page_id'] ) ) {
			$fields['landing_page_id'] = absint( $data['landing_page_id'] );
		}
		if ( isset( $data['landing_message'] ) ) {
			$fields['landing_message'] = sanitize_textarea_field( $data['landing_message'] );
		}
		if ( isset( $data['slug'] ) && '' !== $data['slug'] ) {
			$current = self::find( $id );
			$slug    = sanitize_title( $data['slug'] );
			if ( $current && $current->slug !== $slug ) {
				$fields['slug'] = self::unique_slug( $slug );
			}
		}
		if ( ! $fields ) {
			return;
		}
		$fields['updated_at'] = Db::now();
		$wpdb->update( Db::table( 'affiliates' ), $fields, array( 'id' => (int) $id ) );
		self::clear_cache( $id );
		Audit::log( 'affiliate.updated', 'affiliate', (int) $id, array( 'fields' => array_keys( $fields ) ) );
	}

	/**
	 * Change the contact e-mail (after the partner confirmed the new address).
	 *
	 * @param int    $id    Affiliate.
	 * @param string $email New e-mail.
	 * @return true|\WP_Error
	 */
	public static function set_email( $id, $email ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wpac_email', __( 'Please enter a valid e-mail address.', 'affiliate-captain' ) );
		}
		$other = self::find_by_email( $email );
		if ( $other && (int) $other->id !== (int) $id ) {
			return new \WP_Error( 'wpac_email_taken', __( 'This e-mail address is already used by another partner account.', 'affiliate-captain' ) );
		}
		$before = self::find( (int) $id );
		$wpdb->update(
			Db::table( 'affiliates' ),
			array(
				'email'      => $email,
				'updated_at' => Db::now(),
			),
			array( 'id' => (int) $id )
		);
		self::clear_cache( $id );
		Audit::log( 'affiliate.email_changed', 'affiliate', (int) $id );
		if ( $before && is_email( $before->email ) && strtolower( (string) $before->email ) !== strtolower( $email ) ) {
			// Tell the previous address (a hijacked session must not silently take over the account)
			// and keep sending security alerts there for 30 days.
			update_option( 'wpac_prev_email_' . (int) $id, array( 'email' => $before->email, 'until' => time() + 30 * DAY_IN_SECONDS ), false );
			$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $before );
			try {
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				Notifications::send(
					'email_changed',
					$before->email,
					/* translators: %s: site name */
					sprintf( __( '[%s] The e-mail address of your partner account was changed', 'affiliate-captain' ), $site ),
					/* translators: %s: new address, partly hidden */
					sprintf( __( "Hello,\n\nthe e-mail address of your partner account was changed to %s. If you did not make this change, please contact us immediately.", 'affiliate-captain' ), self::mask_email( $email ) ),
					array( 'affiliate_id' => (int) $id )
				);
			} finally {
				\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
			}
		}
		return true;
	}

	/**
	 * A partner's change of the public name or website (shown in the "recommended by" box,
	 * creatives and mails) waits for the operator's review once the partner is approved.
	 *
	 * @param int    $id      Affiliate ID.
	 * @param string $name    New name.
	 * @param string $website New website.
	 * @return string applied|pending|unchanged
	 */
	public static function request_public_change( $id, $name, $website ) {
		$aff = self::find( (int) $id );
		if ( ! $aff ) {
			return 'unchanged';
		}
		$name    = '' !== trim( (string) $name ) ? sanitize_text_field( $name ) : (string) $aff->name;
		$website = esc_url_raw( (string) $website );
		if ( $name === (string) $aff->name && $website === (string) $aff->website ) {
			delete_option( 'wpac_public_change_' . (int) $id );
			return 'unchanged';
		}
		if ( ! in_array( $aff->status, array( 'active', 'paused' ), true ) ) {
			self::update( (int) $id, array( 'name' => $name, 'website' => $website ) );
			return 'applied';
		}
		update_option( 'wpac_public_change_' . (int) $id, array( 'name' => $name, 'website' => $website, 'requested_at' => Db::now() ), false );
		Audit::log( 'affiliate.public_change_requested', 'affiliate', (int) $id );
		Notifications::public_change_requested( (int) $id );
		return 'pending';
	}

	/**
	 * Pending change of name/website, or null.
	 *
	 * @param int $id Affiliate ID.
	 * @return array|null
	 */
	public static function pending_public_change( $id ) {
		$p = get_option( 'wpac_public_change_' . (int) $id );
		return is_array( $p ) ? $p : null;
	}

	/**
	 * Approve or reject the pending change.
	 *
	 * @param int  $id      Affiliate ID.
	 * @param bool $approve Approve.
	 * @return bool
	 */
	public static function resolve_public_change( $id, $approve ) {
		$p = self::pending_public_change( $id );
		if ( ! $p ) {
			return false;
		}
		delete_option( 'wpac_public_change_' . (int) $id );
		if ( $approve ) {
			self::update( (int) $id, array( 'name' => $p['name'], 'website' => $p['website'] ) );
		}
		Audit::log( $approve ? 'affiliate.public_change_approved' : 'affiliate.public_change_rejected', 'affiliate', (int) $id );
		Notifications::public_change_resolved( (int) $id, (bool) $approve );
		return true;
	}

	/**
	 * Previous e-mail address that still receives security alerts (30 days after a change), or ''.
	 *
	 * @param int $id Affiliate ID.
	 * @return string
	 */
	public static function previous_email( $id ) {
		$prev = get_option( 'wpac_prev_email_' . (int) $id );
		if ( ! is_array( $prev ) ) {
			return '';
		}
		if ( (int) $prev['until'] < time() ) {
			delete_option( 'wpac_prev_email_' . (int) $id );
			return '';
		}
		return (string) $prev['email'];
	}

	/**
	 * a***@example.com
	 *
	 * @param string $email E-mail.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$at = strpos( $email, '@' );
		return false === $at ? '***' : substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Change status with a reason.
	 *
	 * @param int    $id     ID.
	 * @param string $status New status.
	 * @param string $reason Reason (required for pause/terminate/reject).
	 * @return true|\WP_Error
	 */
	public static function set_status( $id, $status, $reason = '' ) {
		global $wpdb;
		$aff = self::find( $id );
		if ( ! $aff ) {
			return new \WP_Error( 'wpac_not_found', __( 'Affiliate not found.', 'affiliate-captain' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'wpac_invalid_status', __( 'Invalid status.', 'affiliate-captain' ) );
		}
		if ( $aff->status === $status ) {
			return true;
		}
		if ( \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $id ) && in_array( $status, array( 'active', 'pending' ), true ) ) {
			// Restricted processing (erasure or Art. 18 request): the account cannot be reactivated.
			return new \WP_Error( 'wpac_restricted', __( 'Processing of this partner is restricted (data protection). Lift the restriction first.', 'affiliate-captain' ) );
		}
		if ( in_array( $status, array( 'paused', 'terminated', 'rejected' ), true ) && '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}

		$fields = array(
			'status'        => $status,
			'status_reason' => Db::cut( sanitize_text_field( $reason ), 255 ),
			'updated_at'    => Db::now(),
		);
		if ( 'active' === $status && ! $aff->approved_at ) {
			$fields['approved_at'] = Db::now();
			$fields['approved_by'] = get_current_user_id();
		}
		// Compare-and-set on the old status; mails, audit and ledger follow only a saved change
		// (a failed or lost update must never terminate the partner "on paper" only).
		$saved = $wpdb->update( Db::table( 'affiliates' ), $fields, array( 'id' => (int) $id, 'status' => $aff->status ) );
		self::clear_cache( $id );
		if ( ! $saved ) {
			Log::error( 'Partner status could not be saved', array( 'affiliate' => (int) $id, 'to' => $status, 'error' => (string) $wpdb->last_error ) );
			return new \WP_Error( 'wpac_status_not_saved', __( 'The status could not be saved (database error or changed meanwhile). Nothing was changed; please try again.', 'affiliate-captain' ) );
		}
		Audit::log(
			'affiliate.status',
			'affiliate',
			(int) $id,
			array(
				'from'   => $aff->status,
				'to'     => $status,
				'reason' => $fields['status_reason'],
			)
		);

		if ( 'active' === $status ) {
			self::on_activated( (int) $id );
			if ( in_array( $aff->status, array( 'pending', 'rejected' ), true ) ) {
				Notifications::application_approved( (int) $id );
			}
		} elseif ( 'rejected' === $status && 'pending' === $aff->status ) {
			Notifications::application_rejected( (int) $id, $reason );
		}
		if ( 'terminated' === $status && 'reject' === Settings::get( 'terminated_open' ) ) {
			// Payouts that were created but not exported are cancelled first: their commissions
			// would otherwise be paid although they are to be rejected.
			\WPAffiliateCaptain\Payouts\PayoutService::cancel_open_for_affiliate( (int) $id, __( 'Partnership terminated', 'affiliate-captain' ) . ': ' . $reason );
			Ledger::reject_open_for_affiliate( (int) $id, __( 'Partnership terminated', 'affiliate-captain' ) . ': ' . $reason );
		}
		if ( in_array( $status, array( 'paused', 'terminated' ), true ) || ( 'active' === $status && in_array( $aff->status, array( 'paused', 'terminated' ), true ) ) ) {
			Notifications::status_changed( (int) $id, $status, $aff->status, $reason );
		}

		do_action( 'wpac_affiliate_status_changed', (int) $id, $status, $aff->status );
		return true;
	}

	/**
	 * Called when an affiliate becomes active: link/create WP user and default code.
	 *
	 * @param int $id Affiliate ID.
	 */
	private static function on_activated( $id ) {
		global $wpdb;
		$aff = self::find( $id );
		if ( ! $aff ) {
			return;
		}
		if ( ! $aff->user_id ) {
			$user = get_user_by( 'email', $aff->email );
			if ( $user && user_can( $user, 'edit_posts' ) ) {
				// Never link privileged accounts automatically (e.g. an administrator's address).
				Audit::log( 'affiliate.user_link_skipped', 'affiliate', $id, array( 'reason' => 'privileged_account' ) );
				$user_id = 0;
			} elseif ( $user ) {
				// WordPress/WooCommerce never verify an account e-mail, so someone could have put the
				// partner's address on their own account. Link only after the partner confirms with
				// a one-time link sent to the partner e-mail while logged in to that account.
				self::request_user_link( (int) $id, $aff );
				$user_id = 0;
			} else {
				$login   = sanitize_user( strstr( $aff->email, '@', true ), true );
				$login   = username_exists( $login ) ? $login . '-' . wp_rand( 100, 999 ) : $login;
				$user_id = wp_insert_user(
					array(
						'user_login'   => $login,
						'user_email'   => $aff->email,
						'user_pass'    => wp_generate_password( 24 ),
						'display_name' => $aff->name ? $aff->name : $login,
						'role'         => get_option( 'default_role', 'subscriber' ),
					)
				);
				if ( is_wp_error( $user_id ) ) {
					Audit::log( 'affiliate.user_failed', 'affiliate', $id, array( 'error' => $user_id->get_error_code() ) );
					$user_id = 0;
				} else {
					// No separate WordPress "login details" mail: the welcome/approval mail carries the
					// set-password link (one mail). If no such mail is sent in this request, WordPress'
					// own notification is sent at the end of the request instead.
					self::$new_users[ (int) $id ] = (int) $user_id;
					if ( ! self::$notify_pending ) {
						self::$notify_pending = true;
						add_action( 'shutdown', array( __CLASS__, 'notify_new_users' ) );
					}
				}
			}
			if ( $user_id ) {
				$wpdb->update( Db::table( 'affiliates' ), array( 'user_id' => $user_id ), array( 'id' => $id ) );
				self::clear_cache( $id );
			}
		}
		if ( ! CodeRepository::for_affiliate( $id ) ) {
			// Random by default: name-based codes are easy to guess (commission fraud).
			CodeRepository::create( $id, CodeRepository::random_code() );
		}
	}

	/** @var array Partner ID => WordPress user created in this request (welcome mail not sent yet). */
	private static $new_users = array();

	/** @var bool Whether the end-of-request fallback is registered. */
	private static $notify_pending = false;

	/**
	 * Set-password link for a WordPress account that was just created for the partner (used by
	 * the welcome mail; afterwards WordPress' own "login details" mail is no longer sent).
	 *
	 * @param int $id Affiliate ID.
	 * @return string URL or '' when no account was created in this request.
	 */
	public static function set_password_url( $id ) {
		if ( empty( self::$new_users[ (int) $id ] ) ) {
			return '';
		}
		$user = get_userdata( self::$new_users[ (int) $id ] );
		if ( ! $user || ! function_exists( 'get_password_reset_key' ) ) {
			return '';
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}
		unset( self::$new_users[ (int) $id ] );
		return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}

	/**
	 * End of request: accounts created without a welcome mail get WordPress' login details mail.
	 */
	public static function notify_new_users() {
		foreach ( self::$new_users as $user_id ) {
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}
		self::$new_users = array();
	}

	/**
	 * Whether a link request to an existing customer account is open (not expired).
	 *
	 * @param int $id Affiliate ID.
	 * @return bool
	 */
	public static function user_link_pending( $id ) {
		$pending = get_option( 'wpac_user_link_' . (int) $id );
		return is_array( $pending ) && (int) $pending['expires'] >= time();
	}

	/**
	 * Why the partner cannot be linked to this WordPress user ('' = can be linked).
	 *
	 * @param object   $aff  Affiliate.
	 * @param \WP_User $user User.
	 * @return string
	 */
	private static function link_problem( $aff, $user ) {
		if ( (int) $aff->user_id ) {
			return __( 'This partner is already linked to a WordPress user.', 'affiliate-captain' );
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return __( 'Accounts that can edit posts (administrators, editors, authors …) are never linked to a partner – the partner area would give partner access to an account with backend rights. Use a customer or subscriber account.', 'affiliate-captain' );
		}
		$other = self::find_by_user( (int) $user->ID );
		if ( $other && (int) $other->id !== (int) $aff->id ) {
			/* translators: %s: partner name */
			return sprintf( __( 'This WordPress user is already linked to the partner "%s".', 'affiliate-captain' ), $other->name );
		}
		return '';
	}

	/**
	 * Send the link to connect an existing customer account again (admin or partner, at most
	 * every 10 minutes).
	 *
	 * @param int $id Affiliate ID.
	 * @return true|\WP_Error
	 */
	public static function resend_user_link( $id ) {
		$aff = self::find( (int) $id );
		if ( ! $aff || ! in_array( $aff->status, array( 'active', 'paused' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'Only active or paused partners can be linked to an account.', 'affiliate-captain' ) );
		}
		$user = get_user_by( 'email', $aff->email );
		if ( ! $user ) {
			return new \WP_Error( 'wpac_no_user', __( 'There is no WordPress account with the partner\'s e-mail address. Link a user manually or change the e-mail address.', 'affiliate-captain' ) );
		}
		$problem = self::link_problem( $aff, $user );
		if ( '' !== $problem ) {
			return new \WP_Error( 'wpac_link', $problem );
		}
		$pending = get_option( 'wpac_user_link_' . (int) $id );
		if ( is_array( $pending ) && ! empty( $pending['sent'] ) && (int) $pending['sent'] > time() - 10 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'wpac_rate_limited', __( 'The link was sent a few minutes ago. Please wait 10 minutes before sending it again.', 'affiliate-captain' ) );
		}
		self::request_user_link( (int) $id, $aff );
		return true;
	}

	/**
	 * Link a WordPress user manually (admin): by user ID or e-mail address.
	 *
	 * @param int        $id   Affiliate ID.
	 * @param string|int $user User ID or e-mail.
	 * @return true|\WP_Error
	 */
	public static function link_user( $id, $user ) {
		global $wpdb;
		$aff = self::find( (int) $id );
		if ( ! $aff ) {
			return new \WP_Error( 'wpac_not_found', __( 'Affiliate not found.', 'affiliate-captain' ) );
		}
		$user = trim( (string) $user );
		$wp   = ctype_digit( $user ) ? get_userdata( (int) $user ) : get_user_by( 'email', $user );
		if ( ! $wp ) {
			return new \WP_Error( 'wpac_no_user', __( 'No WordPress user with this ID or e-mail address was found.', 'affiliate-captain' ) );
		}
		$problem = self::link_problem( $aff, $wp );
		if ( '' !== $problem ) {
			return new \WP_Error( 'wpac_link', $problem );
		}
		// Whoever controls the linked account sees the partner area and can enter bank details:
		// never one's own account, and an account with another e-mail address only with the
		// program management right. The partner and the program admin are always informed.
		if ( (int) $wp->ID === (int) get_current_user_id() ) {
			return new \WP_Error( 'wpac_link_self', __( 'You cannot link your own account to a partner. Ask another administrator, or let the partner confirm the link from the e-mail.', 'affiliate-captain' ) );
		}
		$same_email = strtolower( (string) $wp->user_email ) === strtolower( (string) $aff->email );
		if ( ! $same_email && ! current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM ) ) {
			return new \WP_Error( 'wpac_link_email', __( 'The account\'s e-mail address differs from the partner\'s. Only program managers can link such an account; otherwise change the partner\'s e-mail address first.', 'affiliate-captain' ) );
		}
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'affiliates' ) . ' SET user_id = %d WHERE id = %d AND user_id = 0', (int) $wp->ID, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_cache( (int) $id );
		if ( ! $done ) {
			return new \WP_Error( 'wpac_link', __( 'This partner is already linked to a WordPress user.', 'affiliate-captain' ) );
		}
		delete_option( 'wpac_user_link_' . (int) $id );
		Audit::log(
			'affiliate.user_linked',
			'affiliate',
			(int) $id,
			array(
				'user'       => (int) $wp->ID,
				'manual'     => true,
				'same_email' => $same_email,
			)
		);
		self::notify_manual_link( $aff, $wp, $same_email );
		return true;
	}

	/**
	 * Disconnect the WordPress user from a partner (admin action, or the user was deleted). The
	 * partner keeps everything; a new account can be linked or created on the next approval.
	 *
	 * @param int    $id     Affiliate ID.
	 * @param string $reason manual|user_deleted|removed_from_site.
	 * @return bool Whether a link was removed.
	 */
	public static function unlink_user( $id, $reason = 'manual' ) {
		global $wpdb;
		$aff = self::find( (int) $id );
		if ( ! $aff || ! (int) $aff->user_id ) {
			return false;
		}
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'affiliates' ) . ' SET user_id = 0, updated_at = %s WHERE id = %d AND user_id = %d', Db::now(), (int) $id, (int) $aff->user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_cache( (int) $id );
		if ( ! $done ) {
			return false;
		}
		Audit::log(
			'affiliate.user_unlinked',
			'affiliate',
			(int) $id,
			array(
				'user'   => (int) $aff->user_id,
				'reason' => sanitize_key( $reason ),
			)
		);
		return true;
	}

	/**
	 * Hooks for deleted WordPress users: a partner whose account was deleted must not stay
	 * linked to a user ID that no longer exists (portal: "no partner account", new application:
	 * "already registered").
	 */
	public static function register_user_hooks() {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'on_user_removed_from_site' ), 10, 2 );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'on_network_user_deleted' ), 10, 1 );
	}

	/**
	 * A user was deleted (current site).
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_user_deleted( $user_id ) {
		global $wpdb;
		if ( ! (int) $user_id ) {
			return;
		}
		$t = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d", (int) $user_id ) ) as $id ) {
			self::unlink_user( (int) $id, 'user_deleted' );
		}
	}

	/**
	 * A user was removed from a site of the network.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Site ID.
	 */
	public static function on_user_removed_from_site( $user_id, $blog_id ) {
		$switched = (int) $blog_id && (int) $blog_id !== (int) get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( (int) $blog_id );
		}
		try {
			self::on_user_deleted( (int) $user_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * A user is deleted from the whole network: every site the user belongs to.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_network_user_deleted( $user_id ) {
		global $wpdb;
		$blogs = function_exists( 'get_blogs_of_user' ) ? array_keys( (array) get_blogs_of_user( (int) $user_id, true ) ) : array();
		foreach ( $blogs as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Db::table( 'affiliates' ) ) ) ) ) {
					self::on_user_deleted( (int) $user_id );
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Inform the partner (at the partner e-mail) and the program admin about a manual account link.
	 *
	 * @param object   $aff        Partner.
	 * @param \WP_User $wp         Linked user.
	 * @param bool     $same_email Whether the account has the partner's e-mail address.
	 */
	private static function notify_manual_link( $aff, $wp, $same_email ) {
		$site        = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
		try {
			Notifications::send(
				'account_linked',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] An account was linked to your partner access', 'affiliate-captain' ), $site ),
				/* translators: 1: partner name, 2: login name */
				sprintf( __( "Hello %1\$s,\n\nthe shop team linked the user account \"%2\$s\" to your partner access. Whoever logs in with it sees your partner area, commissions and payout details.\n\nIf you did not ask for this, please contact us right away.", 'affiliate-captain' ), $aff->name, $wp->user_login ),
				array( 'affiliate_id' => (int) $aff->id )
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
		$actor       = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$wpac_locale = Notifications::switch_for_admin();
		try {
			Notifications::send(
				'account_linked_admin',
				Notifications::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] Partner account linked manually', 'affiliate-captain' ), $site ),
				/* translators: 1: partner ID, 2: login name, 3: user who linked it, 4: note */
				sprintf( __( "Partner #%1\$d was linked to the user account \"%2\$s\" by %3\$s.%4\$s", 'affiliate-captain' ), (int) $aff->id, $wp->user_login, $actor && $actor->exists() ? $actor->user_login : '-', $same_email ? '' : ' ' . __( 'The account has a different e-mail address than the partner.', 'affiliate-captain' ) ),
				array(
					'admin'        => true,
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
	}

	/**
	 * Send a one-time link that connects the partner to the existing WordPress account with the same e-mail.
	 *
	 * @param int    $id  Affiliate ID.
	 * @param object $aff Affiliate row.
	 */
	private static function request_user_link( $id, $aff ) {
		$token = strtolower( wp_generate_password( 32, false ) );
		update_option(
			'wpac_user_link_' . $id,
			array(
				'hash'    => wp_hash( $token ),
				'expires' => time() + 7 * DAY_IN_SECONDS,
				'sent'    => time(),
			),
			false
		);
		$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
		try {
			$base = \WPAffiliateCaptain\Core\Locale::page_url( (int) Settings::get( 'portal_page_id' ), \WPAffiliateCaptain\Core\Locale::for_affiliate( $aff ) );
			$link = add_query_arg( array( 'wpac_link_account' => $token, 'wpac_aff' => $id ), $base );
			Notifications::send(
				'link_account',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Connect your partner account', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: %s: link */
				sprintf( __( "Hello,\n\nthere is already a customer account with this e-mail address. Log in to that account and open this link to use it for the partner program (valid for 7 days):\n%s\n\nIf you do not know this account, ignore this e-mail and contact us.", 'affiliate-captain' ), $link ),
				array( 'affiliate_id' => (int) $id )
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
		Audit::log( 'affiliate.user_link_requested', 'affiliate', $id );
	}

	/**
	 * Confirm the account link (logged in to the account with the partner e-mail, token from the mail).
	 *
	 * @param int    $id    Affiliate ID.
	 * @param string $token Token from the mail.
	 * @return bool
	 */
	public static function confirm_user_link( $id, $token ) {
		global $wpdb;
		$pending = get_option( 'wpac_user_link_' . (int) $id );
		$aff     = self::find( (int) $id );
		$user    = wp_get_current_user();
		if ( ! is_array( $pending ) || ! $aff || $aff->user_id || ! $user || ! $user->exists()
			|| (int) $pending['expires'] < time() || ! hash_equals( (string) $pending['hash'], wp_hash( (string) $token ) )
			|| strtolower( (string) $user->user_email ) !== strtolower( (string) $aff->email )
			|| user_can( $user, 'edit_posts' ) || self::find_by_user( $user->ID ) ) {
			return false;
		}
		delete_option( 'wpac_user_link_' . (int) $id );
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'affiliates' ) . ' SET user_id = %d WHERE id = %d AND user_id = 0', $user->ID, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_cache( (int) $id );
		if ( $done ) {
			Audit::log( 'affiliate.user_linked', 'affiliate', (int) $id, array( 'user' => (int) $user->ID ) );
		}
		return (bool) $done;
	}

	/**
	 * Shorten a slug to $max bytes. Non-Latin names are stored URL-encoded ("%d0%b0…"): the cut
	 * never ends inside an escape or a multi-byte character (a slug ending in "%d" or in half a
	 * character is never found again, and the next partner collides with it).
	 *
	 * @param string $slug Sanitized slug.
	 * @param int    $max  Bytes.
	 * @return string
	 */
	public static function cut_slug( $slug, $max ) {
		$slug = (string) $slug;
		if ( strlen( $slug ) <= $max ) {
			return $slug;
		}
		$cut = (string) preg_replace( '/%[0-9a-f]?$/i', '', substr( $slug, 0, $max ) );
		if ( preg_match( '/(?:%[0-9a-f]{2})+$/i', $cut, $m ) ) {
			$bytes = rawurldecode( $m[0] );
			while ( '' !== $bytes && ! mb_check_encoding( $bytes, 'UTF-8' ) ) {
				$bytes = substr( $bytes, 0, -1 );
			}
			$encoded = '';
			foreach ( '' !== $bytes ? str_split( $bytes ) : array() as $byte ) {
				$encoded .= '%' . bin2hex( $byte );
			}
			$cut = substr( $cut, 0, strlen( $cut ) - strlen( $m[0] ) ) . $encoded;
		}
		return trim( $cut, '-' );
	}

	/**
	 * Unique vanity slug.
	 *
	 * @param string $base Base.
	 * @return string
	 */
	public static function unique_slug( $base ) {
		$slug = self::cut_slug( sanitize_title( $base ), 50 );
		$slug = '' !== $slug ? $slug : 'partner';
		$try  = $slug;
		$i    = 2;
		while ( self::find_by_slug( $try ) ) {
			$try = $slug . '-' . $i++;
		}
		return $try;
	}

	/**
	 * List affiliates.
	 *
	 * @param array $args status, search, limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t      = Db::table( 'affiliates' );
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (name LIKE %s OR email LIKE %s OR slug LIKE %s OR website LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'name', 'status', 'created_at' ) );
	}

	/**
	 * Count by status.
	 *
	 * @return array status => count
	 */
	public static function count_by_status() {
		global $wpdb;
		$t   = Db::table( 'affiliates' );
		$out = array_fill_keys( self::STATUSES, 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$t} GROUP BY status" ) as $r ) {
			$out[ $r->status ] = (int) $r->c;
		}
		return $out;
	}
}
