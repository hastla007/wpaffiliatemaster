<?php
/**
 * Gapless, unique number sequences per name and year (credit notes, cancellations).
 *
 * Must be called inside the transaction that also stores the document: the
 * counter row stays locked until COMMIT, and a ROLLBACK also rolls back the
 * counter – so numbers are never skipped or used twice.
 *
 * Settable per number range (SET-AC-15, owner decision E7 option a): the start number
 * ("{range}_start", only raised, never to or below the highest number already issued in the
 * current year) and the number of digits ("{range}_digits", 4–10). The year in the number and the
 * restart per year stay fixed until the legal review.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Sequence {

	/** Number ranges of the core (sequence name = settings key prefix: credit_prefix, credit_start, credit_digits). */
	const RANGES = array( 'credit', 'storno', 'correction' );

	/** Digits of a number when nothing else is set (former fixed format …-000001). */
	const DEFAULT_DIGITS = 6;

	/** Smallest and largest settable number of digits. */
	const MIN_DIGITS = 4;
	const MAX_DIGITS = 10;

	/**
	 * Next value.
	 *
	 * @param string $name Sequence name.
	 * @param int    $year Year.
	 * @return int
	 * @throws IntegrationException On DB errors.
	 */
	public static function next( $name, $year ) {
		global $wpdb;
		$t = Db::table( 'sequences' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (name, year, value) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE value = value + 1", $name, $year ) );
		if ( false === $ok ) {
			throw new IntegrationException( 'db_sequence', esc_html( $wpdb->last_error ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$t} WHERE name = %s AND year = %d", $name, $year ) );
	}

	/**
	 * Formatted document number, e.g. GS-2026-000001. A value longer than the digits is
	 * written in full (never cut).
	 *
	 * @param string $prefix Prefix.
	 * @param string $name   Sequence name.
	 * @param int    $year   Year.
	 * @return string
	 */
	public static function number( $prefix, $name, $year ) {
		return self::format( $prefix, $year, self::next( $name, $year ), self::digits( $name ) );
	}

	/**
	 * Format a number of a range.
	 *
	 * @param string $prefix Prefix.
	 * @param int    $year   Year.
	 * @param int    $value  Counter value.
	 * @param int    $digits Minimum number of digits (leading zeros).
	 * @return string
	 */
	public static function format( $prefix, $year, $value, $digits = self::DEFAULT_DIGITS ) {
		$digits = max( self::MIN_DIGITS, min( self::MAX_DIGITS, (int) $digits ) );
		return sprintf( '%s-%d-%0' . $digits . 'd', $prefix, (int) $year, (int) $value );
	}

	/**
	 * Number of digits of a range (setting "{range}_digits"; other sequences: 6).
	 *
	 * @param string $name Sequence name.
	 * @return int
	 */
	public static function digits( $name ) {
		if ( ! in_array( $name, self::RANGES, true ) ) {
			return self::DEFAULT_DIGITS;
		}
		$digits = (int) Settings::get( $name . '_digits' );
		return $digits >= self::MIN_DIGITS && $digits <= self::MAX_DIGITS ? $digits : self::DEFAULT_DIGITS;
	}

	/**
	 * Highest value issued so far in a year (0 = none). Read without a lock: for checks and display.
	 *
	 * @param string $name Sequence name.
	 * @param int    $year Year.
	 * @return int
	 */
	public static function current( $name, $year ) {
		global $wpdb;
		$t = Db::table( 'sequences' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$t} WHERE name = %s AND year = %d", $name, $year ) );
	}

	/**
	 * The number the next document of a range gets (display only, nothing is reserved).
	 *
	 * @param string $name Range.
	 * @param int    $year Year (0 = current year).
	 * @return string
	 */
	public static function preview( $name, $year = 0 ) {
		$year = $year ? (int) $year : (int) wp_date( 'Y' );
		return self::format( (string) Settings::get( $name . '_prefix' ), $year, self::current( $name, $year ) + 1, self::digits( $name ) );
	}

	/**
	 * Let the counter of a year continue at a start number (start number setting). Only ever
	 * raises: in one statement on the locked counter row, so a document numbered at the same time
	 * either comes before (and the start still applies after it) or waits for this; a start at
	 * or below the highest issued number changes nothing. Documents already issued keep their
	 * numbers. Numbers between the old counter and the start are skipped (the audit log records why).
	 *
	 * @param string $name  Sequence name.
	 * @param int    $year  Year.
	 * @param int    $start First number to issue next.
	 * @return int|false Highest number issued before (the counter before raising), false on a DB error.
	 */
	public static function raise( $name, $year, $start ) {
		global $wpdb;
		$t     = Db::table( 'sequences' );
		$floor = max( 0, (int) $start - 1 );
		try {
			$before = Db::transaction(
				static function () use ( $wpdb, $t, $name, $year, $floor ) {
					// The INSERT locks the row (or creates it locked); the value read after it is current.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (name, year, value) VALUES (%s, %d, 0) ON DUPLICATE KEY UPDATE value = value", $name, $year ) ) ) {
						throw new IntegrationException( 'db_sequence', esc_html( $wpdb->last_error ) );
					}
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$t} WHERE name = %s AND year = %d FOR UPDATE", $name, $year ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $floor > $before && false === $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET value = %d WHERE name = %s AND year = %d", $floor, $name, $year ) ) ) {
						throw new IntegrationException( 'db_sequence', esc_html( $wpdb->last_error ) );
					}
					return $before;
				}
			);
		} catch ( \Throwable $e ) {
			Log::error( 'Number range start could not be set', array( 'range' => (string) $name, 'error' => $e->getMessage() ) );
			return false;
		}
		return (int) $before;
	}
}
