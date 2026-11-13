<?php
/**
 * Objections against credit notes (§14 Abs. 2 S. 3 UStG): the partner can object to a
 * self-billed credit note at any time; it then loses its effect as an invoice. The operator
 * cancels the payout (cancellation document) and issues a corrected credit note.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Objections {

	const OPTION = 'wpac_objections';

	/**
	 * All objections: payout ID => [ affiliate_id, at, reason, status, resolution, resolved_at ].
	 *
	 * @return array
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Open objections.
	 *
	 * @return array
	 */
	public static function open() {
		return array_filter(
			self::all(),
			static function ( $o ) {
				return 'open' === $o['status'];
			}
		);
	}

	/**
	 * Objection of a payout, or null.
	 *
	 * @param int $payout_id Payout.
	 * @return array|null
	 */
	public static function for_payout( $payout_id ) {
		$all = self::all();
		return isset( $all[ (int) $payout_id ] ) ? $all[ (int) $payout_id ] : null;
	}

	/**
	 * The partner objects to a credit note.
	 *
	 * @param int    $payout_id    Payout.
	 * @param int    $affiliate_id Partner (must own the payout).
	 * @param string $reason       What is wrong.
	 * @return true|\WP_Error
	 */
	public static function file( $payout_id, $affiliate_id, $reason ) {
		$p      = PayoutService::find( (int) $payout_id );
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		if ( ! $p || (int) $p->affiliate_id !== (int) $affiliate_id || 'cancelled' === $p->status || '' === (string) $p->statement_file ) {
			return new \WP_Error( 'wpac_objection', __( 'This credit note cannot be objected to.', 'wp-affiliate-captain' ) );
		}
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please describe what is incorrect.', 'wp-affiliate-captain' ) );
		}
		$existing = self::for_payout( (int) $payout_id );
		if ( $existing && 'open' === $existing['status'] ) {
			return new \WP_Error( 'wpac_objection_open', __( 'Your objection to this credit note is already being reviewed.', 'wp-affiliate-captain' ) );
		}
		$all                      = self::all();
		$all[ (int) $payout_id ] = array(
			'affiliate_id' => (int) $affiliate_id,
			'at'           => Db::now(),
			'reason'       => substr( $reason, 0, 1000 ),
			'status'       => 'open',
			'resolution'   => '',
			'resolved_at'  => '',
		);
		update_option( self::OPTION, $all, false );
		Audit::log( 'statement.objection', 'payout', (int) $payout_id );
		$aff   = AffiliateRepository::find( (int) $affiliate_id );
		$admin = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $admin ) ? $admin : (string) get_option( 'admin_email' ),
			/* translators: %s: credit note number */
			sprintf( __( 'Objection to credit note %s', 'wp-affiliate-captain' ), $p->number ),
			/* translators: 1: partner, 2: number, 3: reason, 4: URL */
			sprintf( __( "%1\$s objected to credit note %2\$s:\n\n%3\$s\n\nThe credit note no longer counts as an invoice. Please check it, cancel the payout if needed and issue a corrected credit note:\n%4\$s", 'wp-affiliate-captain' ), $aff ? $aff->name : '#' . (int) $affiliate_id, $p->number, $reason, admin_url( 'admin.php?page=wpac-payouts' ) )
		);
		return true;
	}

	/**
	 * Mark an objection as handled (e.g. payout cancelled and reissued, or explained).
	 *
	 * @param int    $payout_id  Payout.
	 * @param string $resolution What was done (shown to the partner).
	 * @return bool
	 */
	public static function resolve( $payout_id, $resolution ) {
		$all = self::all();
		if ( ! isset( $all[ (int) $payout_id ] ) || 'open' !== $all[ (int) $payout_id ]['status'] ) {
			return false;
		}
		$all[ (int) $payout_id ]['status']      = 'resolved';
		$all[ (int) $payout_id ]['resolution']  = substr( sanitize_textarea_field( (string) $resolution ), 0, 1000 );
		$all[ (int) $payout_id ]['resolved_at'] = Db::now();
		update_option( self::OPTION, $all, false );
		Audit::log( 'statement.objection_resolved', 'payout', (int) $payout_id );
		$aff = AffiliateRepository::find( (int) $all[ (int) $payout_id ]['affiliate_id'] );
		$p   = PayoutService::find( (int) $payout_id );
		if ( $aff && $p ) {
			wp_mail(
				$aff->email,
				/* translators: %s: credit note number */
				sprintf( __( 'Your objection to credit note %s', 'wp-affiliate-captain' ), $p->number ),
				/* translators: %s: resolution */
				sprintf( __( "Hello,\n\nwe have reviewed your objection:\n\n%s", 'wp-affiliate-captain' ), $all[ (int) $payout_id ]['resolution'] )
			);
		}
		return true;
	}
}
