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
			'pending'    => __( 'Application pending', 'wp-affiliate-captain' ),
			'active'     => __( 'Active', 'wp-affiliate-captain' ),
			'paused'     => __( 'Paused', 'wp-affiliate-captain' ),
			'terminated' => __( 'Terminated', 'wp-affiliate-captain' ),
			'rejected'   => __( 'Rejected', 'wp-affiliate-captain' ),
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
			return new \WP_Error( 'wpac_invalid_email', __( 'Please enter a valid e-mail address.', 'wp-affiliate-captain' ) );
		}
		if ( self::find_by_email( $email ) ) {
			return new \WP_Error( 'wpac_exists', __( 'An affiliate account with this e-mail address already exists.', 'wp-affiliate-captain' ) );
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
			return new \WP_Error( 'wpac_db', __( 'The affiliate could not be saved.', 'wp-affiliate-captain' ) );
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
			return new \WP_Error( 'wpac_email', __( 'Please enter a valid e-mail address.', 'wp-affiliate-captain' ) );
		}
		$other = self::find_by_email( $email );
		if ( $other && (int) $other->id !== (int) $id ) {
			return new \WP_Error( 'wpac_email_taken', __( 'This e-mail address is already used by another partner account.', 'wp-affiliate-captain' ) );
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
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			wp_mail(
				$before->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] The e-mail address of your partner account was changed', 'wp-affiliate-captain' ), $site ),
				/* translators: %s: new address, partly hidden */
				sprintf( __( "Hello,\n\nthe e-mail address of your partner account was changed to %s. If you did not make this change, please contact us immediately.", 'wp-affiliate-captain' ), self::mask_email( $email ) )
			);
		}
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
			return new \WP_Error( 'wpac_not_found', __( 'Affiliate not found.', 'wp-affiliate-captain' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'wpac_invalid_status', __( 'Invalid status.', 'wp-affiliate-captain' ) );
		}
		if ( $aff->status === $status ) {
			return true;
		}
		if ( in_array( $status, array( 'paused', 'terminated', 'rejected' ), true ) && '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}

		$fields = array(
			'status'        => $status,
			'status_reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			'updated_at'    => Db::now(),
		);
		if ( 'active' === $status && ! $aff->approved_at ) {
			$fields['approved_at'] = Db::now();
			$fields['approved_by'] = get_current_user_id();
		}
		$wpdb->update( Db::table( 'affiliates' ), $fields, array( 'id' => (int) $id ) );
		self::clear_cache( $id );
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
			if ( 'pending' === $aff->status ) {
				Notifications::application_approved( (int) $id );
			}
		} elseif ( 'rejected' === $status && 'pending' === $aff->status ) {
			Notifications::application_rejected( (int) $id, $reason );
		}
		if ( 'terminated' === $status && 'reject' === Settings::get( 'terminated_open' ) ) {
			Ledger::reject_open_for_affiliate( (int) $id, __( 'Partnership terminated', 'wp-affiliate-captain' ) . ': ' . $reason );
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
					wp_new_user_notification( $user_id, null, 'user' );
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

	/**
	 * Send a one-time link that connects the partner to the existing WordPress account with the same e-mail.
	 *
	 * @param int    $id  Affiliate ID.
	 * @param object $aff Affiliate row.
	 */
	private static function request_user_link( $id, $aff ) {
		$token = strtolower( wp_generate_password( 32, false ) );
		update_option( 'wpac_user_link_' . $id, array( 'hash' => wp_hash( $token ), 'expires' => time() + 7 * DAY_IN_SECONDS ), false );
		$portal = (int) Settings::get( 'portal_page_id' );
		$base   = $portal ? get_permalink( $portal ) : home_url( '/' );
		$link   = add_query_arg( array( 'wpac_link_account' => $token, 'wpac_aff' => $id ), $base );
		wp_mail(
			$aff->email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Connect your partner account', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: %s: link */
			sprintf( __( "Hello,\n\nthere is already a customer account with this e-mail address. Log in to that account and open this link to use it for the partner program (valid for 7 days):\n%s\n\nIf you do not know this account, ignore this e-mail and contact us.", 'wp-affiliate-captain' ), $link )
		);
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
	 * Unique vanity slug.
	 *
	 * @param string $base Base.
	 * @return string
	 */
	public static function unique_slug( $base ) {
		$slug = sanitize_title( $base );
		$slug = substr( '' !== $slug ? $slug : 'partner', 0, 50 );
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
