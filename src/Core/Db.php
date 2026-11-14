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
		'rate_limits',
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
	 * Take a named lock (a row in the options table) atomically. add_option() is NOT atomic
	 * in WordPress (it is an upsert after a cached read), so INSERT IGNORE decides: only the
	 * request that inserted the row owns the lock. An expired lock is taken over with a
	 * compare-and-set on its old value. Returns the owner value, or '' when someone else holds it.
	 *
	 * @param string $name Lock name (option name).
	 * @param int    $ttl  Seconds after which a lock counts as abandoned.
	 * @return string Owner value for unlock()/refresh(), '' when not acquired.
	 */
	public static function lock( $name, $ttl ) {
		global $wpdb;
		$value = time() . ':' . wp_generate_password( 16, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
		if ( 1 !== (int) $won ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' !== $held && time() - (int) $held < (int) $ttl ) {
				return '';
			}
			$won = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $held ) );
		}
		// phpcs:enable
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $won ? $value : '';
	}

	/**
	 * Extend a lock that is still owned (long migrations).
	 *
	 * @param string $name  Lock name.
	 * @param string $owner Value returned by lock().
	 * @return string New owner value ('' when the lock was lost).
	 */
	public static function refresh( $name, $owner ) {
		global $wpdb;
		$value = time() . ':' . substr( (string) $owner, strpos( (string) $owner, ':' ) + 1 );
		if ( $value === (string) $owner ) {
			// Same second: an UPDATE to the same value reports 0 changed rows, so check ownership instead.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			return $held === $value ? $value : '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $owner ) );
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $ok ? $value : '';
	}

	/**
	 * Release a lock – only by its owner (never someone else's lock taken over meanwhile).
	 *
	 * @param string $name  Lock name.
	 * @param string $owner Value returned by lock()/refresh().
	 */
	public static function unlock( $name, $owner ) {
		global $wpdb;
		if ( '' === (string) $owner ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $owner ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Run a short read-modify-write (e.g. of a list kept in one option) under a named lock, so two
	 * parallel requests cannot overwrite each other's change. Waits for the lock (a lock of a
	 * request that died is taken over after $ttl seconds).
	 *
	 * @param string   $name Lock name (option name).
	 * @param callable $fn   Callback.
	 * @param int      $ttl  Seconds after which the lock counts as abandoned.
	 * @return mixed Result of the callback.
	 * @throws IntegrationException When the lock could not be taken.
	 */
	public static function with_lock( $name, callable $fn, $ttl = 15 ) {
		$deadline = microtime( true ) + (int) $ttl + 2;
		do {
			$owner = self::lock( $name, $ttl );
			if ( '' !== $owner ) {
				break;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );
		if ( '' === $owner ) {
			throw new IntegrationException( 'locked', 'Could not acquire the lock ' . $name . '.' );
		}
		try {
			return $fn();
		} finally {
			self::unlock( $name, $owner );
		}
	}

	/**
	 * Shorten a value to a column length in characters without cutting a multibyte (UTF-8)
	 * character in half. A byte cut (substr) inside "ä" or "€" leaves invalid UTF-8, which
	 * WordPress/MySQL reject: the whole INSERT/UPDATE fails.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum number of characters.
	 * @return string
	 */
	public static function cut( $value, $max ) {
		$value = (string) $value;
		$max   = max( 0, (int) $max );
		if ( strlen( $value ) <= $max ) {
			return $value; // Fits in bytes, so certainly in characters.
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max, 'UTF-8' );
		}
		// Without mbstring: byte cut, then drop a trailing incomplete UTF-8 sequence.
		$cut = substr( $value, 0, $max );
		if ( preg_match( '/[\xC0-\xFF][\x80-\xBF]*$/', $cut, $m ) ) {
			$lead = ord( $m[0][0] );
			$len  = $lead >= 0xF0 ? 4 : ( $lead >= 0xE0 ? 3 : 2 );
			if ( strlen( $m[0] ) < $len ) {
				$cut = substr( $cut, 0, -strlen( $m[0] ) );
			}
		}
		return $cut;
	}

	/**
	 * Run a callback after the current transaction was committed (right away outside a
	 * transaction). Dropped when the transaction is rolled back. For notifications and other
	 * side effects that must not happen for a booking that is rolled back.
	 *
	 * @param callable $fn Callback.
	 */
	public static function after_commit( callable $fn ) {
		if ( self::$depth > 0 ) {
			self::$after_commit[] = $fn;
			return;
		}
		$fn();
	}

	/** @var callable[] Callbacks waiting for the COMMIT of the running transaction. */
	private static $after_commit = array();

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
		// A transaction that could not be started or committed must never look like success
		// (ledger and payout data would be partly written or not at all).
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new IntegrationException( 'db_transaction', 'Could not start a database transaction: ' . $wpdb->last_error );
		}
		self::$depth = 1;
		try {
			$result = $fn();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new IntegrationException( 'db_commit', 'Could not commit the database transaction: ' . $wpdb->last_error );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::$after_commit = array();
			throw $e;
		} finally {
			self::$depth = 0;
		}
		$queued             = self::$after_commit;
		self::$after_commit = array();
		foreach ( $queued as $cb ) {
			$cb();
		}
		return $result;
	}

	/** @var int Transaction nesting depth. */
	private static $depth = 0;
}
