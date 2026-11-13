<?php
/**
 * Gapless, unique number sequences per name and year (credit notes, cancellations).
 *
 * Must be called inside the transaction that also stores the document: the
 * counter row stays locked until COMMIT, and a ROLLBACK also rolls back the
 * counter – so numbers are never skipped or used twice.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Sequence {

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
	 * Formatted document number, e.g. GS-2026-000001.
	 *
	 * @param string $prefix Prefix.
	 * @param string $name   Sequence name.
	 * @param int    $year   Year.
	 * @return string
	 */
	public static function number( $prefix, $name, $year ) {
		return sprintf( '%s-%d-%06d', $prefix, $year, self::next( $name, $year ) );
	}
}
