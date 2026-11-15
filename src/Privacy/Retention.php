<?php
/**
 * Statutory retention periods and the clean-up of booking records after them.
 *
 * Credit notes are accounting vouchers (Buchungsbelege): Germany 8 years (§147 AO, since 2025),
 * Austria 7 years (§132 BAO), Switzerland 10 years (Art. 958f OR). The period starts at the end of
 * the calendar year of the last booking. A tax audit or legal hold suspends every deletion.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Privacy;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Retention {

	/** Legal minimum in years by operator country (fallback: 10). */
	const MINIMUM = array(
		'DE' => 8,
		'AT' => 7,
		'CH' => 10,
	);

	/**
	 * Legal minimum for the operator's country.
	 *
	 * @param string $country ISO country (default: operator country).
	 * @return int
	 */
	public static function minimum_years( $country = '' ) {
		$country = strtoupper( '' !== (string) $country ? (string) $country : (string) Settings::get( 'operator_country' ) );
		return isset( self::MINIMUM[ $country ] ) ? self::MINIMUM[ $country ] : 10;
	}

	/**
	 * Retention period in years (setting, never below the legal minimum); 0 = keep forever.
	 *
	 * @return int
	 */
	public static function years() {
		$set = (int) Settings::get( 'statement_retention_years' );
		return $set <= 0 ? 0 : max( self::minimum_years(), $set );
	}

	/**
	 * Whether a tax audit or legal hold suspends all deletions.
	 *
	 * @return bool
	 */
	public static function on_hold() {
		return (bool) Settings::get( 'retention_hold' );
	}

	/**
	 * Booking records dated before this (UTC, MySQL) are past their retention; null = keep everything.
	 *
	 * The single source of truth for every retention deletion: credit note and cancellation PDFs
	 * with the partner snapshot (Statement::purge_expired()), booking texts and acceptance records
	 * (purge_erased(), purge_agreements()) and the financial change log (Cron::purge_audit()). So
	 * nothing that belongs to a credit note is deleted before the credit note itself.
	 *
	 * @return string|null
	 */
	public static function cutoff() {
		$years = self::years();
		if ( $years <= 0 || self::on_hold() ) {
			return null;
		}
		// Period starts at the end of the calendar year: a booking from 2018 may go after 31.12.2018 + n years.
		return gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( $years + 1 ) . ' years' ) );
	}

	/**
	 * Whether all booking records of a partner are past their retention (last commission, payout
	 * or cancellation before the cutoff).
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	public static function partner_expired( $affiliate_id ) {
		global $wpdb;
		$cutoff = self::cutoff();
		if ( null === $cutoff ) {
			return false;
		}
		$c = Db::table( 'commissions' );
		$p = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST( COALESCE( ( SELECT MAX(updated_at) FROM {$c} WHERE affiliate_id = %d ), '1970-01-01' ), COALESCE( ( SELECT MAX(GREATEST(created_at, COALESCE(paid_at, created_at), COALESCE(storno_at, created_at))) FROM {$p} WHERE affiliate_id = %d ), '1970-01-01' ) )", $affiliate_id, $affiliate_id ) );
		return null !== $last && (string) $last < $cutoff;
	}

	/**
	 * Daily: free texts of erased (restricted) partners whose booking records are past their
	 * retention are deleted; the partner's agreements and the change log follow.
	 *
	 * @return int Partners cleaned up.
	 */
	public static function purge_erased() {
		global $wpdb;
		$n = 0;
		foreach ( Restriction::all( Restriction::ERASURE ) as $id => $info ) {
			if ( ! self::partner_expired( (int) $id ) ) {
				continue;
			}
			self::scrub_ledger_texts( (int) $id );
			self::delete_agreements( (int) $id );
			Restriction::remove( (int) $id, Restriction::ERASURE, false );
			Audit::log( 'privacy.retention_expired', 'affiliate', (int) $id );
			++$n;
		}
		return $n;
	}

	/** Partners anonymised per daily run. */
	const ENDED_BATCH = 50;

	/**
	 * Daily: partners whose partnership ended (terminated, or rejected with bookings) are
	 * anonymised without an erasure request once nothing requires their data any more
	 * (storage limitation, Art. 5(1)(e) GDPR): with bookings after the statutory retention of
	 * their last booking, without any booking after the retention of rejected applications.
	 * Nothing is deleted during a tax audit / legal hold or while something is still open.
	 *
	 * @return int Partners anonymised.
	 */
	public static function purge_ended() {
		global $wpdb;
		if ( self::on_hold() ) {
			return 0;
		}
		$cutoff = self::cutoff();
		$days   = (int) Settings::get( 'application_retention_days' );
		$quiet  = $days > 0 ? Db::now( time() - $days * DAY_IN_SECONDS ) : null;
		if ( null === $cutoff && null === $quiet ) {
			return 0;
		}
		$a = Db::table( 'affiliates' );
		$c = Db::table( 'commissions' );
		$p = Db::table( 'payouts' );
		// Booking-free partners first (cheap), then those whose bookings are past the retention.
		$cond   = array();
		$params = array();
		if ( null !== $quiet ) {
			$cond[]   = "( a.status = 'terminated' AND a.updated_at < %s AND NOT EXISTS ( SELECT 1 FROM {$c} c WHERE c.affiliate_id = a.id ) AND NOT EXISTS ( SELECT 1 FROM {$p} p WHERE p.affiliate_id = a.id ) )";
			$params[] = $quiet;
		}
		if ( null !== $cutoff ) {
			$cond[]   = "( a.status IN ('terminated','rejected') AND a.updated_at < %s )";
			$params[] = $cutoff;
		}
		$params[] = self::ENDED_BATCH * 4;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names; values are placeholders.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT a.id FROM {$a} a WHERE a.status_reason <> 'privacy_erasure' AND ( " . implode( ' OR ', $cond ) . ' ) ORDER BY a.id LIMIT %d', $params ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$aff = \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( $id );
			if ( ! $aff || Privacy::has_open_items( $id ) || Restriction::is_restricted( $id ) ) {
				continue; // Open money, or restricted on request (Art. 18): keep.
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bookings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ( SELECT COUNT(*) FROM {$c} WHERE affiliate_id = %d ) + ( SELECT COUNT(*) FROM {$p} WHERE affiliate_id = %d )", $id, $id ) );
			if ( $bookings && ! self::partner_expired( $id ) ) {
				continue; // Credit notes and their partner data are still within the retention.
			}
			Privacy::erase_partner( $aff );
			self::scrub_ledger_texts( $id );
			self::delete_agreements( $id );
			Restriction::remove( $id, Restriction::ERASURE, false );
			Audit::log( 'privacy.retention_anonymised', 'affiliate', $id, array( 'bookings' => $bookings ? 1 : 0 ) );
			if ( ++$n >= self::ENDED_BATCH ) {
				break;
			}
		}
		return $n;
	}

	/**
	 * Delete free texts in booking records (reasons and notes may contain personal data).
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function scrub_ledger_texts( $affiliate_id ) {
		global $wpdb;
		$c = Db::table( 'commissions' );
		$p = Db::table( 'payouts' );
		// Table names only; values are placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'commission_events' ) . " SET reason = '' WHERE commission_id IN (SELECT id FROM {$c} WHERE affiliate_id = %d)", $affiliate_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'attributions' ) . " SET note = '' WHERE affiliate_id = %d", $affiliate_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET note = '' WHERE affiliate_id = %d", $affiliate_id ) );
		// Write-off reasons are stored as the description; sales keep the product name.
		$wpdb->query( $wpdb->prepare( "UPDATE {$c} SET description = '' WHERE affiliate_id = %d AND kind = 'writeoff'", $affiliate_id ) );
		// Reasons of correction credit notes (the documents are past their retention by now).
		$wpdb->query( $wpdb->prepare( "UPDATE {$c} SET description = '' WHERE affiliate_id = %d AND kind = 'correction'", $affiliate_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payout_corrections' ) . " SET reason = '', profile_snapshot = NULL WHERE affiliate_id = %d", $affiliate_id ) );
		// Objections to the partner's credit notes.
		$objections = \WPAffiliateCaptain\Payouts\Objections::all();
		foreach ( $objections as $pid => $o ) {
			if ( (int) $o['affiliate_id'] === (int) $affiliate_id ) {
				unset( $objections[ $pid ] );
			}
		}
		update_option( \WPAffiliateCaptain\Payouts\Objections::OPTION, $objections, false );
		// Change log of the partner's payouts (e.g. the reason of a cancellation).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'payout' AND object_id IN (SELECT id FROM {$p} WHERE affiliate_id = %d)", $affiliate_id ) );
		// Change log of the partner's payout details and requests, kept unchanged by the erasure
		// during the retention period (acceptance records go with delete_agreements()).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d AND action NOT LIKE 'agreement.%%'", $affiliate_id ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		// Leftover tax data history (normally deleted with the payout details already).
		\WPAffiliateCaptain\Payouts\TaxHistory::delete( (int) $affiliate_id );
		// phpcs:enable
	}

	/**
	 * Daily: attributions (order number, partner, method, campaign, sub ID, visit ID) are booking
	 * records only as long as the commissions of their order: once the attribution and every
	 * commission of the order are past the retention period and nothing of it is open any more, the
	 * attribution row is deleted (storage limitation, R12-M13). Nothing is deleted during a tax
	 * audit / legal hold or with "keep forever" (cutoff() is null). The commissions themselves keep
	 * their amounts (statistics before the cutoff lose the campaign breakdown); their order number,
	 * product and description are pseudonymised afterwards (pseudonymise_commissions(), R13-M3).
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_attributions() {
		global $wpdb;
		$cutoff = self::cutoff();
		if ( null === $cutoff ) {
			return 0;
		}
		$at = Db::table( 'attributions' );
		$c  = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names; values are placeholders.
		$sql = $wpdb->prepare( "DELETE FROM {$at} WHERE created_at < %s AND NOT EXISTS ( SELECT 1 FROM {$c} c WHERE c.source = {$at}.source AND c.source_order_id = {$at}.source_order_id AND ( c.updated_at >= %s OR c.status IN ('pending','review') OR ( c.status = 'approved' AND c.payout_id = 0 ) ) )", $cutoff, $cutoff );
		/**
		 * Attribution rows deleted per statement (one short transaction each).
		 *
		 * @param int $batch Rows (default 1000).
		 */
		$batch   = max( 1, (int) apply_filters( 'wpac_attribution_purge_batch', 1000 ) );
		$deleted = \WPAffiliateCaptain\Core\Cron::delete_in_batches( $sql, $batch, 50 );
		self::pseudonymise_commissions();
		return $deleted;
	}

	/** Prefix of a pseudonymised idempotency key. */
	const PSEUDONYM_KEY = 'p:';

	/**
	 * Pseudonym of an order (source and order number) or an idempotency key: keyed hash (the
	 * WordPress auth salt from wp-config.php), so sequential order numbers cannot simply be
	 * hashed and compared with a database dump alone. 'x' + 40 hex characters fit source_order_id.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function pseudonym( $value ) {
		return 'x' . substr( hash_hmac( 'sha256', 'wpac-retention:' . (string) $value, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Pseudonymised idempotency key of a booking (see Ledger::find_by_key(): a re-delivered event
	 * still finds it and is not booked twice).
	 *
	 * @param string $key Idempotency key.
	 * @return string
	 */
	public static function pseudonym_key( $key ) {
		return self::PSEUDONYM_KEY . hash_hmac( 'sha256', 'wpac-key:' . (string) $key, wp_salt( 'auth' ) );
	}

	/**
	 * Daily, after purge_attributions(): the commissions of an order whose attribution is gone, that
	 * are all past the retention period and of which nothing is open any more keep partner, kind,
	 * amounts, currency, status and dates (balances, reports and the booking chain stay intact),
	 * but lose what links them to the order and the customer: the order number becomes a
	 * pseudonym (one per order, so order counts stay right), product and description are emptied,
	 * the idempotency key is hashed (a re-delivered event is still recognised) and the reasons of
	 * their status changes (e.g. the shop's refund reason) are deleted. Nothing happens during a
	 * tax audit / legal hold or with "keep forever". Works in batches within the daily time budget;
	 * the rest follows in the next run.
	 *
	 * @return int Orders pseudonymised.
	 */
	public static function pseudonymise_commissions() {
		global $wpdb;
		$cutoff = self::cutoff();
		if ( null === $cutoff ) {
			return 0;
		}
		$at = Db::table( 'attributions' );
		$c  = Db::table( 'commissions' );
		$e  = Db::table( 'commission_events' );
		$n  = 0;
		for ( $round = 0; $round < 50; $round++ ) {
			if ( $round > 0 && \WPAffiliateCaptain\Core\Cron::over_budget() ) {
				\WPAffiliateCaptain\Core\Cron::mark_unfinished();
				break;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names; values are placeholders.
			$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT c.source, c.source_order_id FROM {$c} c WHERE c.created_at < %s AND c.source_order_id <> '' AND c.idempotency_key NOT LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$at} a WHERE a.source = c.source AND a.source_order_id = c.source_order_id ) AND NOT EXISTS ( SELECT 1 FROM {$c} o WHERE o.source = c.source AND o.source_order_id = c.source_order_id AND ( o.created_at >= %s OR o.updated_at >= %s OR o.status IN ('pending','review') OR ( o.status = 'approved' AND o.payout_id = 0 ) ) ) LIMIT 200", $cutoff, $wpdb->esc_like( self::PSEUDONYM_KEY ) . '%', $cutoff, $cutoff ) );
			foreach ( $orders as $o ) {
				$pseudo = self::pseudonym( $o->source . ':' . $o->source_order_id );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, idempotency_key FROM {$c} WHERE source = %s AND source_order_id = %s", $o->source, $o->source_order_id ) );
				foreach ( $rows as $r ) {
					$key = 0 === strpos( (string) $r->idempotency_key, self::PSEUDONYM_KEY ) ? (string) $r->idempotency_key : self::pseudonym_key( (string) $r->idempotency_key );
					$wpdb->update(
						$c,
						array(
							'source_order_id' => $pseudo,
							'description'     => '',
							'product_ref'     => '',
							'idempotency_key' => $key,
						),
						array( 'id' => (int) $r->id )
					);
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( "UPDATE {$e} SET reason = '' WHERE commission_id = %d", (int) $r->id ) );
				}
				++$n;
			}
			if ( count( $orders ) < 200 ) {
				break;
			}
		}
		return $n;
	}

	/**
	 * Delete the partner's acceptance records (kept as proof of the self-billing agreement as long
	 * as the credit notes, then removed).
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function delete_agreements( $affiliate_id ) {
		global $wpdb;
		$wpdb->delete( Db::table( 'agreements' ), array( 'affiliate_id' => (int) $affiliate_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::table( 'audit' ) . " WHERE object_type = 'affiliate' AND object_id = %d AND action LIKE 'agreement.%%'", $affiliate_id ) );
	}

	/**
	 * Daily: acceptance records of ended partner relationships (terminated, rejected) are deleted
	 * once their booking records are past the retention period.
	 *
	 * @return int Partners whose agreements were deleted.
	 */
	public static function purge_agreements() {
		global $wpdb;
		if ( null === self::cutoff() ) {
			return 0;
		}
		$a = Db::table( 'affiliates' );
		$g = Db::table( 'agreements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT g.affiliate_id FROM {$g} g JOIN {$a} a ON a.id = g.affiliate_id WHERE a.status IN ('terminated','rejected') AND a.updated_at < %s LIMIT 100", self::cutoff() ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( self::partner_expired( (int) $id ) ) {
				self::delete_agreements( (int) $id );
				++$n;
			}
		}
		return $n;
	}
}
