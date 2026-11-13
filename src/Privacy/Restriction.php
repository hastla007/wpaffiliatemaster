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
	 * @return bool Whether the restriction is stored (it is checked by reading it back).
	 */
	public static function add( $affiliate_id, $kind, $note = '' ) {
		$entry = array(
			'kind'  => $kind,
			'since' => Db::now(),
			'note'  => mb_substr( sanitize_text_field( (string) $note ), 0, 190 ),
		);
		// Read and write under a lock: two parallel changes must not overwrite each other.
		// update_option() also returns false for an unchanged value, so the stored list decides.
		$saved = (bool) Db::with_lock(
			self::LOCK,
			static function () use ( $affiliate_id, $entry ) {
				$all                        = self::fresh();
				$all[ (int) $affiliate_id ] = $entry;
				update_option( self::OPTION, $all, false );
				$stored = self::fresh();
				return isset( $stored[ (int) $affiliate_id ]['kind'] ) && $stored[ (int) $affiliate_id ]['kind'] === $entry['kind'];
			}
		);
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache( (int) $affiliate_id );
		if ( ! $saved ) {
			\WPAffiliateCaptain\Core\Log::error( 'Restriction of processing could not be saved', array( 'affiliate' => (int) $affiliate_id, 'kind' => $kind ) );
			Audit::log( 'privacy.restriction_failed', 'affiliate', (int) $affiliate_id, array( 'kind' => $kind ) );
			return false;
		}
		Audit::log( 'privacy.restricted', 'affiliate', (int) $affiliate_id, array( 'kind' => $kind ) );
		return true;
	}

	/** Lock of the list (read-modify-write). */
	const LOCK = 'wpac_restricted_lock';

	/**
	 * The list as stored right now (not from the per-request cache).
	 *
	 * @return array
	 */
	private static function fresh() {
		wp_cache_delete( self::OPTION, 'options' );
		return self::all();
	}

	/**
	 * Daily: drop entries of partners that no longer exist (e.g. deleted applications), so the
	 * list does not outlive the records it protects.
	 *
	 * @return int Entries removed.
	 */
	public static function purge() {
		global $wpdb;
		if ( ! self::all() ) {
			return 0;
		}
		$t = Db::table( 'affiliates' );
		return (int) Db::with_lock(
			self::LOCK,
			static function () use ( $wpdb, $t ) {
				$all = self::fresh();
				if ( ! $all ) {
					return 0;
				}
				$ids   = array_map( 'intval', array_keys( $all ) );
				$exist = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
				$gone  = array_diff( $ids, $exist );
				if ( ! $gone ) {
					return 0;
				}
				foreach ( $gone as $id ) {
					unset( $all[ $id ] );
				}
				update_option( self::OPTION, $all, false );
				return count( $gone );
			}
		);
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
		$id      = (int) $affiliate_id;
		$removed = Db::with_lock(
			self::LOCK,
			static function () use ( $id, $kind ) {
				$all = self::fresh();
				if ( ! isset( $all[ $id ] ) || ( '' !== $kind && $all[ $id ]['kind'] !== $kind ) ) {
					return false;
				}
				unset( $all[ $id ] );
				update_option( self::OPTION, $all, false );
				return true;
			}
		);
		if ( ! $removed ) {
			return false;
		}
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache( $id );
		if ( $log ) {
			Audit::log( 'privacy.restriction_lifted', 'affiliate', $id );
		}
		return true;
	}
}
