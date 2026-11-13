<?php
/**
 * Payout requests by partners (in addition to payout runs).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PayoutRequests {

	/**
	 * Approved, unassigned balance of a partner in the program currency.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return int
	 */
	public static function available( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$t} WHERE affiliate_id = %d AND status = 'approved' AND payout_id = 0 AND currency = %s", $affiliate_id, Settings::get( 'currency' ) ) );
	}

	/**
	 * Open request of a partner.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return object|null
	 */
	public static function open_for( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'payout_requests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND status = 'requested' ORDER BY id DESC LIMIT 1", $affiliate_id ) );
	}

	/**
	 * Whether the partner may request now; returns an error message otherwise.
	 *
	 * @param object $aff Affiliate.
	 * @return true|string
	 */
	public static function can_request( $aff ) {
		if ( ! Settings::get( 'payout_requests' ) ) {
			return __( 'Payout requests are not enabled.', 'wp-affiliate-captain' );
		}
		if ( 'active' !== $aff->status && 'paused' !== $aff->status ) {
			return __( 'Your partner account is not active.', 'wp-affiliate-captain' );
		}
		if ( self::open_for( (int) $aff->id ) ) {
			return __( 'You already have an open payout request.', 'wp-affiliate-captain' );
		}
		if ( ! ProfileRepository::is_complete( ProfileRepository::get( (int) $aff->id ) ) ) {
			return __( 'Please complete your payout details first.', 'wp-affiliate-captain' );
		}
		$min = (int) Settings::get( 'min_payout_minor' );
		if ( self::available( (int) $aff->id ) < max( 1, $min ) ) {
			/* translators: %s: minimum */
			return sprintf( __( 'Your approved balance is below the minimum payout of %s.', 'wp-affiliate-captain' ), Money::format( $min, Settings::get( 'currency' ) ) );
		}
		return true;
	}

	/**
	 * Create a request.
	 *
	 * @param object $aff Affiliate.
	 * @return int|\WP_Error
	 */
	public static function request( $aff ) {
		global $wpdb;
		$ok = self::can_request( $aff );
		if ( true !== $ok ) {
			return new \WP_Error( 'wpac_request', $ok );
		}
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $aff ) {
					// Serialize requests of the same partner (double click, two tabs): lock the partner row,
					// then check again for an open request before inserting.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
					$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::table( 'affiliates' ) . ' WHERE id = %d FOR UPDATE', (int) $aff->id ) );
					if ( self::open_for( (int) $aff->id ) ) {
						return 0;
					}
					$ok = $wpdb->insert(
						Db::table( 'payout_requests' ),
						array(
							'affiliate_id' => (int) $aff->id,
							'currency'     => (string) Settings::get( 'currency' ),
							'amount_minor' => self::available( (int) $aff->id ),
							'status'       => 'requested',
							'created_at'   => Db::now(),
						)
					);
					if ( ! $ok || ! (int) $wpdb->insert_id ) {
						throw new \WPAffiliateCaptain\Core\IntegrationException( 'db_payout_request', $wpdb->last_error );
					}
					return (int) $wpdb->insert_id;
				}
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpac_request', __( 'The payout request could not be saved. Please try again.', 'wp-affiliate-captain' ) );
		}
		if ( ! $id ) {
			return new \WP_Error( 'wpac_request', __( 'You already have an open payout request.', 'wp-affiliate-captain' ) );
		}
		$amount = (int) self::find( $id )->amount_minor;
		Audit::log( 'payout_request.created', 'affiliate', (int) $aff->id, array( 'amount' => $amount ) );
		$to = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $to ) ? $to : (string) get_option( 'admin_email' ),
			/* translators: %s: partner */
			sprintf( __( 'Payout request from %s', 'wp-affiliate-captain' ), $aff->name ),
			/* translators: 1: partner, 2: amount, 3: URL */
			sprintf( __( "%1\$s requested a payout of %2\$s.\n\n%3\$s", 'wp-affiliate-captain' ), $aff->name, Money::format( $amount, Settings::get( 'currency' ) ), admin_url( 'admin.php?page=wpac-payouts' ) )
		);
		return $id;
	}

	/**
	 * Process: create the payout now (period end = today).
	 *
	 * @param int $id Request.
	 * @return int|\WP_Error Payout ID.
	 */
	public static function process( $id ) {
		global $wpdb;
		$r = self::find( $id );
		if ( ! $r || 'requested' !== $r->status ) {
			return new \WP_Error( 'wpac_state', __( 'This request was already handled.', 'wp-affiliate-captain' ) );
		}
		$blocked = PayoutService::blocked_reason( (int) $r->affiliate_id );
		if ( '' !== $blocked ) {
			return new \WP_Error( 'wpac_profile', $blocked );
		}
		try {
			$payout_id = PayoutService::create_for_affiliate( (int) $r->affiliate_id, gmdate( 'Y-m-d' ), $r->currency );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpac_payout', __( 'The payout could not be created. Please try again.', 'wp-affiliate-captain' ) . ' (' . ( $e instanceof \WPAffiliateCaptain\Core\IntegrationException ? $e->error_code : 'error' ) . ')' );
		}
		if ( ! $payout_id ) {
			return new \WP_Error( 'wpac_balance', __( 'The approved balance is no longer above the minimum.', 'wp-affiliate-captain' ) );
		}
		// create_for_affiliate() settles the open request (fulfil()).
		return $payout_id;
	}

	/**
	 * The partner's latest request if it was rejected (and no newer one exists), within 60 days.
	 *
	 * @param int $affiliate_id Partner.
	 * @return object|null
	 */
	public static function last_rejected( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'payout_requests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d ORDER BY id DESC LIMIT 1", $affiliate_id ) );
		return $r && 'rejected' === $r->status && '' !== (string) $r->note && strtotime( $r->created_at . ' UTC' ) > time() - 60 * DAY_IN_SECONDS ? $r : null;
	}

	/**
	 * Mark the open request of a partner as processed by a payout (also from regular runs).
	 *
	 * @param int $affiliate_id Partner.
	 * @param int    $payout_id    Payout.
	 * @param string $currency     Payout currency (a request is only settled in its own currency).
	 */
	public static function fulfil( $affiliate_id, $payout_id, $currency = '' ) {
		global $wpdb;
		$open = self::open_for( (int) $affiliate_id );
		if ( ! $open ) {
			return;
		}
		if ( '' !== $currency && isset( $open->currency ) && strtoupper( (string) $open->currency ) !== strtoupper( (string) $currency ) ) {
			return; // A payout in another currency does not pay this request.
		}
		$wpdb->update(
			Db::table( 'payout_requests' ),
			array(
				'status'       => 'processed',
				'payout_id'    => (int) $payout_id,
				'processed_at' => Db::now(),
				'processed_by' => get_current_user_id(),
			),
			array(
				'id'     => (int) $open->id,
				'status' => 'requested',
			)
		);
		Audit::log( 'payout_request.processed', 'payout', (int) $payout_id, array( 'request' => (int) $open->id ) );
	}

	/**
	 * Reject with reason (partner is notified).
	 *
	 * @param int    $id     Request.
	 * @param string $reason Reason.
	 * @return true|\WP_Error
	 */
	public static function reject( $id, $reason ) {
		global $wpdb;
		$r = self::find( $id );
		if ( ! $r || 'requested' !== $r->status ) {
			return new \WP_Error( 'wpac_state', __( 'This request was already handled.', 'wp-affiliate-captain' ) );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		$updated = $wpdb->update(
			Db::table( 'payout_requests' ),
			array(
				'status'       => 'rejected',
				'note'         => substr( sanitize_text_field( $reason ), 0, 255 ),
				'processed_at' => Db::now(),
				'processed_by' => get_current_user_id(),
			),
			array(
				'id'     => (int) $id,
				'status' => 'requested', // Only an open request (it may have been processed meanwhile).
			)
		);
		if ( 1 !== (int) $updated ) {
			return new \WP_Error( 'wpac_state', __( 'This request was already handled or could not be updated. Please reload.', 'wp-affiliate-captain' ) );
		}
		Audit::log( 'payout_request.rejected', 'affiliate', (int) $r->affiliate_id, array( 'reason' => $reason ) );
		$aff = AffiliateRepository::find( (int) $r->affiliate_id );
		if ( $aff ) {
			/* translators: 1: name, 2: reason */
			/* translators: %s: site name */
			wp_mail( $aff->email, sprintf( __( '[%s] Your payout request', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), sprintf( __( "Hello %1\$s,\n\nyour payout request could not be processed.\n\n%2\$s", 'wp-affiliate-captain' ), $aff->name, $reason ) );
		}
		return true;
	}

	/**
	 * Find.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'payout_requests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Requests by status.
	 *
	 * @param string $status Status ('' = all).
	 * @param int    $affiliate_id Optional partner.
	 * @return array
	 */
	public static function query( $status = 'requested', $affiliate_id = 0 ) {
		global $wpdb;
		$t      = Db::table( 'payout_requests' );
		$where  = '1=1';
		$params = array();
		if ( $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( $affiliate_id ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $affiliate_id;
		}
		$sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT 200";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
	}
}
