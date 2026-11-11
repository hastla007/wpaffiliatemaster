<?php
/**
 * Table names and small DB helpers.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Db {

	const TABLES = array(
		'affiliates',
		'partner_groups',
		'partner_codes',
		'agreements',
		'payout_profiles',
		'visits',
		'attributions',
		'rules',
		'commissions',
		'commission_events',
		'payouts',
		'payout_items',
		'jobs',
		'audit',
		'tiers',
		'customer_links',
		'creatives',
		'payout_requests',
		'sequences',
		'balances',
		'balance_entries',
	);

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpac_' . $name;
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int|null $ts Timestamp.
	 * @return string
	 */
	public static function now( $ts = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $ts ? time() : (int) $ts );
	}

	/**
	 * Run a callback in a transaction (InnoDB). Rolls back and rethrows on error.
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 * @throws \Throwable On failure.
	 */
	public static function transaction( callable $fn ) {
		global $wpdb;
		// Nested calls run inside the outer transaction (START TRANSACTION would commit it implicitly).
		if ( self::$depth > 0 ) {
			return $fn();
		}
		self::$depth = 1;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$result = $fn();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			self::$depth = 0;
		}
	}

	/** @var int Transaction nesting depth. */
	private static $depth = 0;
}
