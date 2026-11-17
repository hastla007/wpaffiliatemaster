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
		'visits_daily',
		'attributions',
		'rules',
		'commissions',
		'commission_events',
		'payouts',
		'payout_items',
		'payout_corrections',
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
	 * UTC range of local calendar days (site time zone): stored times are UTC, but months and
	 * days in reports, bonuses and mails are the site's.
	 *
	 * @param string $from First local day (Y-m-d).
	 * @param string $to   Last local day (Y-m-d).
	 * @return string[] [ start, end ] as UTC "Y-m-d H:i:s" (inclusive).
	 */
	public static function local_range( $from, $to ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try {
			$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
			$end   = ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' );
		} catch ( \Exception $e ) {
			return array( $from . ' 00:00:00', $to . ' 23:59:59' );
		}
		return array( gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ), gmdate( 'Y-m-d H:i:s', $end->getTimestamp() - 1 ) );
	}

	/**
	 * UTC range of a local calendar month (site time zone).
	 *
	 * @param string $month Month (Y-m).
	 * @return string[] [ start, end ] as UTC "Y-m-d H:i:s" (inclusive).
	 */
	public static function month_range( $month ) {
		$first = $month . '-01';
		return self::local_range( $first, gmdate( 'Y-m-t', (int) strtotime( $first . ' 12:00:00 UTC' ) ) );
	}

	/**
	 * A local month relative to the current one (site time zone).
	 *
	 * @param int $offset Months from now (-1 = previous month).
	 * @param int $ts     Reference time (default now).
	 * @return string Y-m.
	 */
	public static function local_month( $offset = 0, $ts = null ) {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now = ( new \DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) ) )->setTimezone( $tz );
		return $now->modify( 'first day of this month' )->modify( ( (int) $offset >= 0 ? '+' : '' ) . (int) $offset . ' month' )->format( 'Y-m' );
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
			throw new IntegrationException( 'locked', esc_html( 'Could not acquire the lock ' . $name . '.' ) );
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
	 * Whether a text is longer than a column holds (in characters, as Db::cut() counts). wpdb
	 * refuses the whole INSERT/UPDATE for such a value, so check user input before writing.
	 *
	 * @param string $value Text.
	 * @param int    $max   Max characters.
	 * @return bool
	 */
	public static function too_long( $value, $max ) {
		return self::cut( (string) $value, $max ) !== (string) $value;
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

	/** @var mixed Connection on which innodb_snapshot_isolation was already switched off. */
	private static $isolation_dbh = null;

	/**
	 * MariaDB 11.6.2+ (and so the supported 12.3) has innodb_snapshot_isolation ON by default: a
	 * locking read (SELECT … FOR UPDATE) of a row that changed after the transaction's snapshot
	 * fails with error 1020 "Record has changed since last read" and the server rolls the whole
	 * transaction back. The bookings read and then lock rows (the classic REPEATABLE READ
	 * pattern MySQL uses), so the variable is switched off for the session once per connection.
	 * MySQL does not know the variable; the error is suppressed. Error 1020 also counts as an
	 * abort (FATAL_ERRNO) should the variable be on anyway.
	 *
	 * @return void
	 */
	private static function classic_isolation() {
		global $wpdb;
		$dbh = isset( $wpdb->dbh ) ? $wpdb->dbh : null;
		if ( null !== $dbh && $dbh === self::$isolation_dbh ) {
			return;
		}
		$info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		if ( false !== stripos( $info, 'mariadb' ) ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( 'SET SESSION innodb_snapshot_isolation = OFF' );
			$wpdb->suppress_errors( $suppress );
			$wpdb->last_error = '';
		}
		self::$isolation_dbh = $dbh;
	}

	/** @var callable[] Callbacks waiting for the COMMIT of the running transaction. */
	private static $after_commit = array();

	/**
	 * Run a callback in a transaction (InnoDB). Rolls back and rethrows on error.
	 *
	 * The real wpdb never throws: after a deadlock (1213) InnoDB has already rolled back the
	 * whole transaction, after a lock wait timeout (1205) the statement, after a lost connection
	 * wpdb reconnects without a transaction – and the queries return false/null. A callback that
	 * goes on (a skipped line, an unchecked UPDATE) would then report success for a booking that
	 * was not (or only partly) made, and the queries after the abort would run in autocommit
	 * (R13-K1). So during a transaction every query first checks the error of the previous one
	 * (guard_query()): after such an abort all further queries are refused, and the transaction
	 * ends with an IntegrationException 'db_deadlock' (rolled back; the job queue retries).
	 * Before the COMMIT a savepoint set at the start must still exist – it does not after a
	 * rollback by the server or a reconnect.
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
		if ( ! self::$guarded && function_exists( 'add_filter' ) ) {
			add_filter( 'query', array( __CLASS__, 'guard_query' ), PHP_INT_MAX );
			self::$guarded = true;
		}
		self::classic_isolation();
		// A transaction that could not be started or committed must never look like success
		// (ledger and payout data would be partly written or not at all).
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new IntegrationException( 'db_transaction', esc_html( 'Could not start a database transaction: ' . $wpdb->last_error ) );
		}
		self::$depth   = 1;
		self::$aborted = '';
		try {
			if ( false === $wpdb->query( 'SAVEPOINT wpac_tx' ) ) {
				throw new IntegrationException( 'db_transaction', esc_html( 'Could not start a database transaction: ' . $wpdb->last_error ) );
			}
			$result = $fn();
			// The savepoint is gone when the server rolled the transaction back (deadlock) or wpdb
			// reconnected (lost connection); the guard also catches an error of the last query.
			if ( false === $wpdb->query( 'RELEASE SAVEPOINT wpac_tx' ) && '' === self::$aborted ) {
				self::$aborted = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'transaction lost';
			}
			if ( '' !== self::$aborted ) {
				throw self::aborted_exception();
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new IntegrationException( 'db_commit', 'Could not commit the database transaction: ' . $wpdb->last_error );
			}
		} catch ( \Throwable $e ) {
			// Also when the callback threw right after the failed query (no further query ran).
			$aborted       = '' !== self::$aborted ? self::$aborted : self::fatal_error();
			self::$depth   = 0; // Lets the ROLLBACK through the guard.
			self::$aborted = '';
			$wpdb->query( 'ROLLBACK' );
			self::$after_commit = array();
			if ( '' !== $aborted && ! ( $e instanceof IntegrationException && 'db_deadlock' === $e->error_code ) ) {
				// The callback failed because its queries were refused or returned nothing after
				// the abort: report the abort (retry), not the follow-up error.
				self::$aborted = $aborted;
				$wrapped       = self::aborted_exception();
				self::$aborted = '';
				throw $wrapped;
			}
			throw $e;
		} finally {
			self::$depth   = 0;
			self::$aborted = '';
		}
		$queued             = self::$after_commit;
		self::$after_commit = array();
		foreach ( $queued as $cb ) {
			// The booking is committed: a failing listener (mail, hook of an add-on) must neither
			// make it look failed nor stop the callbacks after it.
			try {
				$cb();
			} catch ( \Throwable $e ) {
				Log::exception( 'Callback after a committed booking failed', $e );
			}
		}
		return $result;
	}

	/**
	 * Filter "query" while a transaction runs: when the previous query failed with a deadlock,
	 * a lock wait timeout or a lost connection, the transaction is (partly) gone. Every further
	 * query is refused – replaced by a statement that fails without touching anything (an empty
	 * query would make wpdb return the previous query's rows) – so nothing is written outside
	 * the transaction and every read returns nothing; transaction() then rolls back and throws.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public static function guard_query( $query ) {
		if ( self::$depth < 1 ) {
			return $query;
		}
		if ( '' === self::$aborted ) {
			self::$aborted = self::fatal_error();
		}
		return '' === self::$aborted ? $query : self::REFUSED_SQL;
	}

	/** Statement that replaces queries after an abort: fails at once (unknown function), changes nothing. */
	const REFUSED_SQL = 'SELECT wpac_transaction_aborted_by_database()';

	/**
	 * Error of the last query when it ended the transaction (or the statement inside it):
	 * deadlock, lock wait timeout, lost connection. '' otherwise.
	 *
	 * @return string
	 */
	public static function fatal_error() {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$errno = 0;
		$dbh   = isset( $wpdb->dbh ) ? $wpdb->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			$errno = (int) mysqli_errno( $dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- error number of the last wpdb query (language independent).
		}
		if ( in_array( $errno, self::FATAL_ERRNO, true ) ) {
			return '' !== $error ? $error : 'MySQL error ' . $errno;
		}
		if ( '' !== $error && preg_match( '/deadlock|lock wait timeout|gone away|lost connection|try restarting transaction|record has changed since last read/i', $error ) ) {
			return $error;
		}
		return '';
	}

	/**
	 * Throw when the last query failed (for locking reads and critical writes inside a
	 * transaction whose result decides what is booked: "no row" must not be mistaken for
	 * "nothing to do" when the query failed).
	 *
	 * @param string $what What was read or written (for the log).
	 * @throws IntegrationException When the last query failed.
	 */
	public static function check( $what ) {
		global $wpdb;
		if ( '' !== self::$aborted || '' !== self::fatal_error() ) {
			if ( '' === self::$aborted ) {
				self::$aborted = self::fatal_error();
			}
			throw self::aborted_exception();
		}
		if ( '' !== (string) $wpdb->last_error ) {
			throw new IntegrationException( 'db_error', esc_html( $what . ': ' . $wpdb->last_error ) );
		}
	}

	/**
	 * Exception for a transaction the database aborted.
	 *
	 * @return IntegrationException
	 */
	private static function aborted_exception() {
		Log::warning( 'Database transaction aborted, rolled back', array( 'error' => self::cut( self::$aborted, 200 ) ) );
		return new IntegrationException( 'db_deadlock', esc_html__( 'The database aborted the booking (deadlock, lock wait timeout or lost connection). Nothing was saved; please try again.', 'affiliate-captain' ) );
	}

	/** MySQL error numbers that abort a transaction or a statement in it: record changed since last read (MariaDB snapshot isolation), lock wait timeout, deadlock, server gone away, lost connection, disconnected (MySQL 8). */
	const FATAL_ERRNO = array( 1020, 1205, 1213, 2006, 2013, 4031 );

	/** @var string Error that aborted the running transaction ('' = none). */
	private static $aborted = '';

	/** @var bool Whether the query guard is registered. */
	private static $guarded = false;

	/** @var int Transaction nesting depth. */
	private static $depth = 0;
}
