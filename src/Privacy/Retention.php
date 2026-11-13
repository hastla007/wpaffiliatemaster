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
		// phpcs:enable
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
