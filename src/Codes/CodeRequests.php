<?php
/**
 * Vanity partner codes requested by partners (e.g. "MARIA10"), approved by the operator.
 *
 * An open request is a row in partner_codes with status "requested" (no schema change): the
 * UNIQUE key on the code reserves the code, so two partners asking for the same code at the same
 * time cannot both get it, and an admin cannot create it meanwhile. Such a row is never resolved:
 * CodeRepository::validate() accepts only status "active", the coupon lookup and the main code
 * read only active rows, and the code lists (portal, admin, REST) leave requested rows out.
 * Decided requests (approved, rejected, withdrawn) are kept per partner in a small non-autoloaded
 * option (history for the monthly limit, the partner's view and the privacy export).
 *
 * @package WPAffiliateCaptain
 * @since 0.15.0
 */

namespace WPAffiliateCaptain\Codes;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class CodeRequests {

	/** Status of a requested (not yet approved) code row. */
	const STATUS = 'requested';

	/** Batch marker of requested rows (the batch column is indexed: the open list is cheap). */
	const BATCH = 'code-request';

	/** Option prefix (+ partner ID, not autoloaded): decided requests of a partner. */
	const HISTORY_PREFIX = 'wpac_code_requests_';

	/** Entries kept in the history per partner. */
	const HISTORY_MAX = 20;

	/**
	 * Whether partners can request codes (setting, default on).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'code_requests' );
	}

	/**
	 * Requests a partner may make within 30 days (including rejected and withdrawn ones).
	 *
	 * @param int $affiliate_id Partner.
	 * @return int
	 */
	public static function per_month( $affiliate_id ) {
		/**
		 * Number of code requests a partner may make within 30 days.
		 *
		 * @since 0.15.0
		 * @param int $limit        Requests (default 3).
		 * @param int $affiliate_id Partner.
		 */
		return max( 1, (int) apply_filters( 'wpac_code_requests_per_month', 3, (int) $affiliate_id ) );
	}

	/**
	 * Maximum length of a requested code.
	 *
	 * @return int
	 */
	public static function max_length() {
		/**
		 * Maximum length of a code a partner can request (3 to 64).
		 *
		 * @since 0.15.0
		 * @param int $length Characters (default 20).
		 */
		return min( 64, max( 3, (int) apply_filters( 'wpac_code_request_max_length', 20 ) ) );
	}

	/**
	 * Open request of a partner, or null.
	 *
	 * @param int $affiliate_id Partner.
	 * @return object|null
	 */
	public static function open_for( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND status = %s ORDER BY id ASC LIMIT 1", (int) $affiliate_id, self::STATUS ) );
	}

	/**
	 * Open requests of all partners, oldest first.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function open_all( $limit = 50 ) {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE batch = %s AND status = %s ORDER BY id ASC LIMIT %d", self::BATCH, self::STATUS, max( 1, (int) $limit ) ) );
	}

	/**
	 * Number of open requests.
	 *
	 * @return int
	 */
	public static function open_count() {
		global $wpdb;
		$t = Db::table( 'partner_codes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE batch = %s AND status = %s", self::BATCH, self::STATUS ) );
	}

	/**
	 * Decided and open requests of a partner (newest last).
	 *
	 * @param int $affiliate_id Partner.
	 * @return array List of { code, status, requested_at, decided_at, reason }.
	 */
	public static function history( $affiliate_id ) {
		$h = get_option( self::HISTORY_PREFIX . (int) $affiliate_id, array() );
		return is_array( $h ) ? array_values( array_filter( $h, 'is_array' ) ) : array();
	}

	/**
	 * Requests made within the last 30 days.
	 *
	 * @param int $affiliate_id Partner.
	 * @return int
	 */
	public static function recent_count( $affiliate_id ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$n     = 0;
		foreach ( self::history( $affiliate_id ) as $e ) {
			if ( isset( $e['requested_at'] ) && (string) $e['requested_at'] >= $since ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Why a partner cannot request a code right now ('' = can request).
	 *
	 * @param object $aff Partner.
	 * @return string Error code ('' when allowed).
	 */
	public static function blocked_reason( $aff ) {
		if ( ! self::enabled() ) {
			return 'wpac_code_requests_off';
		}
		if ( ! $aff || 'active' !== $aff->status || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) ) {
			return 'wpac_code_request_inactive';
		}
		if ( self::open_for( (int) $aff->id ) ) {
			return 'wpac_code_request_open';
		}
		if ( self::recent_count( (int) $aff->id ) >= self::per_month( (int) $aff->id ) ) {
			return 'wpac_code_request_limit';
		}
		return '';
	}

	/**
	 * Message for a blocked_reason() code.
	 *
	 * @param string $reason   Error code.
	 * @param int    $affiliate_id Partner.
	 * @return string
	 */
	public static function reason_message( $reason, $affiliate_id = 0 ) {
		switch ( $reason ) {
			case 'wpac_code_requests_off':
				return __( 'Requests for own codes are switched off.', 'affiliate-captain' );
			case 'wpac_code_request_inactive':
				return __( 'Only active partners can request a code.', 'affiliate-captain' );
			case 'wpac_code_request_open':
				return __( 'You already have a code request waiting for review. Please wait for our decision or withdraw it first.', 'affiliate-captain' );
			case 'wpac_code_request_limit':
				$n = self::per_month( (int) $affiliate_id );
				/* translators: %d: number of requests */
				return sprintf( _n( 'You can request %d code within 30 days. Please try again later.', 'You can request %d codes within 30 days. Please try again later.', $n, 'affiliate-captain' ), $n );
		}
		return __( 'The code could not be requested.', 'affiliate-captain' );
	}

	/**
	 * A partner requests a code. Checked with the rules of CodeRepository::check_new_code(); the
	 * code is reserved by the UNIQUE key at once (a parallel request for it fails).
	 *
	 * @param object $aff  Partner.
	 * @param string $code Requested code as typed.
	 * @return int|\WP_Error Row ID of the request.
	 */
	public static function request( $aff, $code ) {
		global $wpdb;
		$reason = self::blocked_reason( $aff );
		if ( '' !== $reason ) {
			return new \WP_Error( $reason, self::reason_message( $reason, $aff ? (int) $aff->id : 0 ) );
		}
		$id   = (int) $aff->id;
		$lock = 'wpac_code_request_lock_' . $id;
		$own  = Db::lock( $lock, 30 );
		if ( '' === $own ) {
			return new \WP_Error( 'wpac_code_request_open', self::reason_message( 'wpac_code_request_open', $id ) );
		}
		try {
			// Again under the lock: two parallel submits of the same partner create one request.
			$reason = self::blocked_reason( $aff );
			if ( '' !== $reason ) {
				return new \WP_Error( $reason, self::reason_message( $reason, $id ) );
			}
			$norm = CodeRepository::check_new_code( $code, $id, 0, self::max_length() );
			if ( is_wp_error( $norm ) ) {
				return $norm;
			}
			$now      = Db::now();
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert(
				Db::table( 'partner_codes' ),
				array(
					'affiliate_id' => $id,
					'code'         => $norm,
					'coupon_code'  => '',
					'batch'        => self::BATCH,
					'usage_limit'  => 0,
					'usage_count'  => 0,
					'expires_at'   => null,
					'status'       => self::STATUS,
					'created_at'   => $now,
				)
			);
			$wpdb->suppress_errors( $suppress );
			if ( ! $ok ) {
				// The UNIQUE key refused it: someone took the code a moment ago.
				return new \WP_Error( 'wpac_code_taken', __( 'This code is already in use.', 'affiliate-captain' ) );
			}
			$row_id = (int) $wpdb->insert_id;
			self::add_history(
				$id,
				array(
					'code'         => $norm,
					'status'       => 'open',
					'requested_at' => $now,
					'decided_at'   => '',
					'reason'       => '',
				)
			);
		} finally {
			Db::unlock( $lock, $own );
		}
		Audit::log( 'code.requested', 'affiliate', $id, array( 'code' => $norm ) );
		Notifications::code_requested( $id, $norm );
		/**
		 * A partner requested a code (waiting for approval).
		 *
		 * @since 0.15.0
		 * @param int    $affiliate_id Partner.
		 * @param string $code         Code.
		 * @param int    $row_id       Row in partner_codes (status "requested").
		 */
		do_action( 'wpac_code_requested', $id, $norm, $row_id );
		return $row_id;
	}

	/**
	 * The partner withdraws the open request (the code is free again).
	 *
	 * @param object $aff Partner.
	 * @return bool
	 */
	public static function withdraw( $aff ) {
		global $wpdb;
		$row = $aff ? self::open_for( (int) $aff->id ) : null;
		if ( ! $row ) {
			return false;
		}
		$deleted = $wpdb->delete(
			Db::table( 'partner_codes' ),
			array(
				'id'     => (int) $row->id,
				'status' => self::STATUS,
			)
		);
		if ( ! $deleted ) {
			return false;
		}
		self::close_history( (int) $aff->id, (string) $row->code, 'withdrawn' );
		Audit::log( 'code.request_withdrawn', 'affiliate', (int) $aff->id, array( 'code' => (string) $row->code ) );
		return true;
	}

	/**
	 * Find an open request by its row ID.
	 *
	 * @param int $row_id Row.
	 * @return object|null
	 */
	public static function find_open( $row_id ) {
		$row = CodeRepository::find( (int) $row_id );
		return $row && self::STATUS === $row->status ? $row : null;
	}

	/**
	 * Approve a request: the code becomes an additional active code of the partner.
	 *
	 * @param int   $row_id Row of the request.
	 * @param array $opts {
	 *     @type bool   $make_main     Make it the main code (shown in the partner area and links);
	 *                                 earlier codes stay valid.
	 *     @type string $coupon        '' (default) | 'create' (new WooCommerce coupon with this code,
	 *                                 needs discount_type and amount) | 'rename' (rename the coupon
	 *                                 linked to the previous main code and move the link).
	 *     @type string $discount_type For 'create': percent|fixed_cart.
	 *     @type string $amount        For 'create': discount.
	 * }
	 * @return array|\WP_Error { code, warnings[] }
	 */
	public static function approve( $row_id, array $opts = array() ) {
		global $wpdb;
		$row = self::find_open( $row_id );
		if ( ! $row ) {
			return new \WP_Error( 'wpac_code_request_gone', __( 'This request was already decided or withdrawn.', 'affiliate-captain' ) );
		}
		$aff_id = (int) $row->affiliate_id;
		$aff    = AffiliateRepository::find( $aff_id );
		if ( ! $aff || ! in_array( $aff->status, array( 'active', 'paused' ), true ) ) {
			return new \WP_Error( 'wpac_code_request_inactive', __( 'The partner is no longer active. Please reject the request.', 'affiliate-captain' ) );
		}
		// The rules again: a WooCommerce coupon or a partner slug with this name may have been
		// created since the request (the row itself does not count).
		$check = CodeRepository::check_new_code( (string) $row->code, $aff_id, (int) $row->id, 64 );
		if ( is_wp_error( $check ) ) {
			return new \WP_Error( $check->get_error_code(), $check->get_error_message() . ' ' . __( 'Please reject the request with a short explanation.', 'affiliate-captain' ) );
		}
		$coupon = isset( $opts['coupon'] ) && in_array( $opts['coupon'], array( 'create', 'rename' ), true ) ? (string) $opts['coupon'] : '';
		$main   = CodeRepository::main_row( $aff_id );
		if ( 'rename' === $coupon && ( ! $main || '' === (string) $main->coupon_code ) ) {
			return new \WP_Error( 'wpac_code_request_no_coupon', __( 'The current main code has no linked coupon that could be renamed.', 'affiliate-captain' ) );
		}
		if ( '' !== $coupon && ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) || ! current_user_can( 'edit_shop_coupons' ) ) ) {
			return new \WP_Error( 'wpac_forbidden', __( 'Coupons can only be changed while WooCommerce is active and with the right to edit coupons.', 'affiliate-captain' ) );
		}
		if ( 'create' === $coupon ) {
			// The discount first: a typo must not leave the code approved without its coupon.
			$amount = isset( $opts['amount'] ) ? str_replace( ',', '.', trim( (string) $opts['amount'] ) ) : '';
			$pct    = ! isset( $opts['discount_type'] ) || 'fixed_cart' !== $opts['discount_type'];
			if ( ! is_numeric( $amount ) || (float) $amount <= 0 || ( $pct && (float) $amount > 100 ) ) {
				return new \WP_Error( 'wpac_coupon_amount', __( 'Please enter a discount greater than 0 (at most 100 for a percentage discount).', 'affiliate-captain' ) );
			}
		}
		$t = Db::table( 'partner_codes' );
		// Conditional: of two admins deciding at the same time, only one approves.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'active', batch = '' WHERE id = %d AND status = %s", (int) $row->id, self::STATUS ) );
		if ( 1 !== $done ) {
			return new \WP_Error( 'wpac_code_request_gone', __( 'This request was already decided or withdrawn.', 'affiliate-captain' ) );
		}
		$warnings = array();
		if ( 'create' === $coupon ) {
			$made = \WPAffiliateCaptain\Integrations\WooCommerce\CouponLink::create_for_partner(
				$aff_id,
				array(
					'code_id'       => (int) $row->id,
					'coupon_code'   => (string) $row->code,
					'discount_type' => isset( $opts['discount_type'] ) ? (string) $opts['discount_type'] : 'percent',
					'amount'        => isset( $opts['amount'] ) ? (string) $opts['amount'] : '',
				)
			);
			if ( is_wp_error( $made ) ) {
				/* translators: %s: reason */
				$warnings[] = sprintf( __( 'The coupon was not created: %s', 'affiliate-captain' ), $made->get_error_message() );
			}
		} elseif ( 'rename' === $coupon && $main ) {
			$renamed = self::rename_coupon( $main, $row );
			if ( is_wp_error( $renamed ) ) {
				$warnings[] = $renamed->get_error_message();
			}
		}
		$blocked = array(); // Earlier codes stay valid (owner decision 2026-11-15).
		if ( ! empty( $opts['make_main'] ) ) {
			CodeRepository::set_main( $aff_id, (int) $row->id );
		}
		self::close_history( $aff_id, (string) $row->code, 'approved' );
		Audit::log(
			'code.request_approved',
			'affiliate',
			$aff_id,
			array(
				'code'    => (string) $row->code,
				'main'    => ! empty( $opts['make_main'] ) ? 1 : 0,
				'blocked' => implode( ', ', $blocked ),
				'coupon'  => $coupon,
			)
		);
		Notifications::code_request_resolved( $aff_id, (string) $row->code, true );
		/**
		 * A code request was approved (the code is active now).
		 *
		 * @since 0.15.0
		 * @param int    $affiliate_id Partner.
		 * @param string $code         Code.
		 * @param int    $row_id       Row in partner_codes.
		 * @param bool   $make_main    Whether it was made the main code.
		 */
		do_action( 'wpac_code_request_approved', $aff_id, (string) $row->code, (int) $row->id, ! empty( $opts['make_main'] ) );
		return array(
			'code'     => (string) $row->code,
			'blocked'  => $blocked,
			'warnings' => $warnings,
		);
	}

	/**
	 * Rename the WooCommerce coupon linked to the previous main code to the new code and move the
	 * link to the new row (WooCommerce CRUD only).
	 *
	 * @param object $main Previous main code row (with coupon).
	 * @param object $row  New code row.
	 * @return true|\WP_Error
	 */
	private static function rename_coupon( $main, $row ) {
		$coupon_id = (int) wc_get_coupon_id_by_code( (string) $main->coupon_code );
		if ( ! $coupon_id ) {
			/* translators: %s: coupon code */
			return new \WP_Error( 'wpac_coupon_missing', sprintf( __( 'The coupon "%s" was not renamed: it no longer exists in WooCommerce.', 'affiliate-captain' ), (string) $main->coupon_code ) );
		}
		$new = CodeRepository::normalize_coupon( (string) $row->code );
		try {
			$wc = new \WC_Coupon( $coupon_id );
			$wc->set_code( $new );
			$wc->update_meta_data( \WPAffiliateCaptain\Integrations\WooCommerce\CouponLink::META_CODE_ID, (int) $row->id );
			$wc->save();
		} catch ( \Throwable $e ) {
			/* translators: %s: technical reason */
			return new \WP_Error( 'wpac_coupon_save', sprintf( __( 'The coupon could not be renamed: %s', 'affiliate-captain' ), $e->getMessage() ) );
		}
		$old = (string) $main->coupon_code;
		CodeRepository::set_coupon( (int) $main->id, '' );
		CodeRepository::set_coupon( (int) $row->id, $new );
		\WPAffiliateCaptain\Integrations\WooCommerce\CouponLink::remember( (int) $row->id, $new );
		Audit::log(
			'coupon.renamed',
			'affiliate',
			(int) $row->affiliate_id,
			array(
				'from'    => $old,
				'to'      => $new,
				'code_id' => (int) $row->id,
			)
		);
		return true;
	}

	/**
	 * Reject a request with a reason (sent to the partner); the code is free again.
	 *
	 * @param int    $row_id Row of the request.
	 * @param string $reason Reason (required).
	 * @return true|\WP_Error
	 */
	public static function reject( $row_id, $reason ) {
		global $wpdb;
		$reason = Db::cut( trim( sanitize_text_field( (string) $reason ) ), 500 );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_missing', __( 'Please enter a reason (it is sent to the partner).', 'affiliate-captain' ) );
		}
		$row = self::find_open( $row_id );
		if ( ! $row ) {
			return new \WP_Error( 'wpac_code_request_gone', __( 'This request was already decided or withdrawn.', 'affiliate-captain' ) );
		}
		$deleted = $wpdb->delete(
			Db::table( 'partner_codes' ),
			array(
				'id'     => (int) $row->id,
				'status' => self::STATUS,
			)
		);
		if ( ! $deleted ) {
			return new \WP_Error( 'wpac_code_request_gone', __( 'This request was already decided or withdrawn.', 'affiliate-captain' ) );
		}
		$aff_id = (int) $row->affiliate_id;
		self::close_history( $aff_id, (string) $row->code, 'rejected', $reason );
		Audit::log(
			'code.request_rejected',
			'affiliate',
			$aff_id,
			array(
				'code'   => (string) $row->code,
				'reason' => $reason,
			)
		);
		Notifications::code_request_resolved( $aff_id, (string) $row->code, false, $reason );
		/**
		 * A code request was rejected (the code is free again).
		 *
		 * @since 0.15.0
		 * @param int    $affiliate_id Partner.
		 * @param string $code         Code.
		 * @param string $reason       Reason sent to the partner.
		 */
		do_action( 'wpac_code_request_rejected', $aff_id, (string) $row->code, $reason );
		return true;
	}

	/**
	 * Remove all requests of a partner (erasure): open rows and the history.
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function forget( $affiliate_id ) {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'partner_codes' ),
			array(
				'affiliate_id' => (int) $affiliate_id,
				'status'       => self::STATUS,
			)
		);
		delete_option( self::HISTORY_PREFIX . (int) $affiliate_id );
		CodeRepository::set_main( (int) $affiliate_id, 0 );
	}

	/**
	 * Append a history entry (keeps the newest HISTORY_MAX).
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $entry        Entry.
	 */
	private static function add_history( $affiliate_id, array $entry ) {
		$h   = self::history( $affiliate_id );
		$h[] = $entry;
		update_option( self::HISTORY_PREFIX . (int) $affiliate_id, array_slice( $h, -self::HISTORY_MAX ), false );
	}

	/**
	 * Mark the open history entry of a code as decided.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $code         Code.
	 * @param string $status       approved|rejected|withdrawn.
	 * @param string $reason       Reason of a rejection.
	 */
	private static function close_history( $affiliate_id, $code, $status, $reason = '' ) {
		$h     = self::history( $affiliate_id );
		$found = false;
		for ( $i = count( $h ) - 1; $i >= 0; $i-- ) {
			if ( isset( $h[ $i ]['code'], $h[ $i ]['status'] ) && (string) $code === (string) $h[ $i ]['code'] && 'open' === $h[ $i ]['status'] ) {
				$h[ $i ]['status']     = $status;
				$h[ $i ]['decided_at'] = Db::now();
				$h[ $i ]['reason']     = (string) $reason;
				$found                 = true;
				break;
			}
		}
		if ( ! $found ) {
			$h[] = array(
				'code'         => (string) $code,
				'status'       => $status,
				'requested_at' => '',
				'decided_at'   => Db::now(),
				'reason'       => (string) $reason,
			);
		}
		update_option( self::HISTORY_PREFIX . (int) $affiliate_id, array_slice( $h, -self::HISTORY_MAX ), false );
	}

	/**
	 * Last decided request of a partner (for the partner area), or null.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array|null
	 */
	public static function last_decided( $affiliate_id ) {
		$h = self::history( $affiliate_id );
		for ( $i = count( $h ) - 1; $i >= 0; $i-- ) {
			if ( isset( $h[ $i ]['status'] ) && in_array( $h[ $i ]['status'], array( 'approved', 'rejected' ), true ) ) {
				return $h[ $i ];
			}
		}
		return null;
	}

	/**
	 * Status labels for the partner area and the privacy export.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'open'      => __( 'Waiting for review', 'affiliate-captain' ),
			'approved'  => __( 'Approved', 'affiliate-captain' ),
			'rejected'  => __( 'Not approved', 'affiliate-captain' ),
			'withdrawn' => __( 'Withdrawn', 'affiliate-captain' ),
		);
	}
}
