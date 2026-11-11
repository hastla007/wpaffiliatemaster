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
		$amount = self::available( (int) $aff->id );
		$wpdb->insert(
			Db::table( 'payout_requests' ),
			array(
				'affiliate_id' => (int) $aff->id,
				'currency'     => (string) Settings::get( 'currency' ),
				'amount_minor' => $amount,
				'status'       => 'requested',
				'created_at'   => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
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
		if ( ! ProfileRepository::is_complete( ProfileRepository::get( (int) $r->affiliate_id ) ) ) {
			return new \WP_Error( 'wpac_profile', __( 'Payout profile incomplete.', 'wp-affiliate-captain' ) );
		}
		$payout_id = PayoutService::create_for_affiliate( (int) $r->affiliate_id, gmdate( 'Y-m-d' ), $r->currency );
		if ( ! $payout_id ) {
			return new \WP_Error( 'wpac_balance', __( 'The approved balance is no longer above the minimum.', 'wp-affiliate-captain' ) );
		}
		$wpdb->update(
			Db::table( 'payout_requests' ),
			array(
				'status'       => 'processed',
				'payout_id'    => $payout_id,
				'processed_at' => Db::now(),
				'processed_by' => get_current_user_id(),
			),
			array( 'id' => (int) $id )
		);
		Audit::log( 'payout_request.processed', 'payout', $payout_id, array( 'request' => (int) $id ) );
		return $payout_id;
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
		$wpdb->update(
			Db::table( 'payout_requests' ),
			array(
				'status'       => 'rejected',
				'note'         => substr( sanitize_text_field( $reason ), 0, 255 ),
				'processed_at' => Db::now(),
				'processed_by' => get_current_user_id(),
			),
			array( 'id' => (int) $id )
		);
		Audit::log( 'payout_request.rejected', 'affiliate', (int) $r->affiliate_id, array( 'reason' => $reason ) );
		$aff = AffiliateRepository::find( (int) $r->affiliate_id );
		if ( $aff ) {
			/* translators: 1: name, 2: reason */
			wp_mail( $aff->email, __( 'Your payout request', 'wp-affiliate-captain' ), sprintf( __( "Hello %1\$s,\n\nyour payout request could not be processed.\n\n%2\$s", 'wp-affiliate-captain' ), $aff->name, $reason ) );
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
