<?php
/**
 * Restriction of processing (Art. 18 GDPR): after an erasure request (booking records that must
 * be kept) or on the partner's request (disputed accuracy, objection under review).
 *
 * Restricted partners keep their stored data, but nothing new is processed: their codes no longer
 * attribute sales, they get no report mails, the status cannot be changed back and the admin sees
 * a notice. Payouts of commissions already earned stay possible (legal claims, Art. 18(2)).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Privacy;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;

defined( 'ABSPATH' ) || exit;

final class Restriction {

	const OPTION  = 'wpac_restricted';
	const ERASURE = 'erasure';
	const REQUEST = 'request';

	/**
	 * All restrictions, optionally of one kind: affiliate ID => [ kind, since, note ].
	 *
	 * @param string $kind Kind filter.
	 * @return array
	 */
	public static function all( $kind = '' ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( '' === $kind ) {
			return $all;
		}
		return array_filter(
			$all,
			static function ( $r ) use ( $kind ) {
				return isset( $r['kind'] ) && $r['kind'] === $kind;
			}
		);
	}

	/**
	 * Restriction of a partner, or null.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array|null
	 */
	public static function get( $affiliate_id ) {
		$all = self::all();
		return isset( $all[ (int) $affiliate_id ] ) ? $all[ (int) $affiliate_id ] : null;
	}

	/**
	 * Whether processing of the partner is restricted.
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	public static function is_restricted( $affiliate_id ) {
		return null !== self::get( $affiliate_id );
	}

	/**
	 * Restrict processing.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $kind         erasure|request.
	 * @param string $note         Internal note (why, e.g. "accuracy disputed").
	 */
	public static function add( $affiliate_id, $kind, $note = '' ) {
		$all                         = self::all();
		$all[ (int) $affiliate_id ] = array(
			'kind'  => $kind,
			'since' => Db::now(),
			'note'  => substr( sanitize_text_field( (string) $note ), 0, 190 ),
		);
		update_option( self::OPTION, $all, false );
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache( (int) $affiliate_id );
		Audit::log( 'privacy.restricted', 'affiliate', (int) $affiliate_id, array( 'kind' => $kind ) );
	}

	/**
	 * Lift a restriction.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $kind         Only this kind ('' = any).
	 * @param bool   $log          Write an audit entry.
	 * @return bool Whether a restriction was lifted.
	 */
	public static function remove( $affiliate_id, $kind = '', $log = true ) {
		$all = self::all();
		$id  = (int) $affiliate_id;
		if ( ! isset( $all[ $id ] ) || ( '' !== $kind && $all[ $id ]['kind'] !== $kind ) ) {
			return false;
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache( $id );
		if ( $log ) {
			Audit::log( 'privacy.restriction_lifted', 'affiliate', $id );
		}
		return true;
	}
}
