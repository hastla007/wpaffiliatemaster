<?php
/**
 * Partner balance ledger (append-only account per partner and currency).
 *
 * Every change of the payable balance is a booking with the resulting running
 * balance:
 *  - approved          commission (or correction/bonus) became payable (+/-)
 *  - rejected          an approved commission was rejected (reversal)
 *  - payout            commissions were assigned to a payout (−net)
 *  - payout_cancelled  a payout was cancelled, commissions are payable again (+net)
 *  - opening           opening balance when the ledger was introduced
 *
 * Invariant: balance == sum of approved commissions not assigned to a payout.
 * Bookings are hash-chained per account (hash of the previous booking + data),
 * so later modifications or deletions are detectable (verify()). Bookings are
 * serialized per account by locking its balance row.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;

defined( 'ABSPATH' ) || exit;

final class Balance {

	/**
	 * Post a booking (inside the caller's transaction, or its own).
	 *
	 * @param int    $affiliate_id  Partner.
	 * @param string $currency      Currency.
	 * @param string $type          Booking type.
	 * @param int    $amount_minor  Signed amount.
	 * @param int    $commission_id Commission reference.
	 * @param int    $payout_id     Payout reference.
	 * @param string $reference     Free reference (e.g. payout number).
	 * @return int Entry ID.
	 */
	public static function post( $affiliate_id, $currency, $type, $amount_minor, $commission_id = 0, $payout_id = 0, $reference = '' ) {
		global $wpdb;
		return Db::transaction(
			static function () use ( $wpdb, $affiliate_id, $currency, $type, $amount_minor, $commission_id, $payout_id, $reference ) {
				$b = Db::table( 'balances' );
				$e = Db::table( 'balance_entries' );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$b} (affiliate_id, currency, balance_minor, entries, last_hash, updated_at) VALUES (%d, %s, 0, 0, '', %s)", $affiliate_id, $currency, Db::now() ) );
				$acc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$b} WHERE affiliate_id = %d AND currency = %s FOR UPDATE", $affiliate_id, $currency ) );
				// phpcs:enable
				if ( ! $acc ) {
					throw new IntegrationException( 'balance_lock', esc_html( $wpdb->last_error ) );
				}
				$row          = array(
					'affiliate_id'  => (int) $affiliate_id,
					'currency'      => (string) $currency,
					'type'          => (string) $type,
					'amount_minor'  => (int) $amount_minor,
					'balance_minor' => (int) $acc->balance_minor + (int) $amount_minor,
					'commission_id' => (int) $commission_id,
					'payout_id'     => (int) $payout_id,
					'reference'     => Db::cut( (string) $reference, 191 ),
					'actor_id'      => get_current_user_id(),
					'created_at'    => Db::now(),
					'prev_hash'     => (string) $acc->last_hash,
				);
				$row['hash'] = self::hash( $row );
				if ( ! $wpdb->insert( $e, $row ) ) {
					throw new IntegrationException( 'balance_entry', esc_html( $wpdb->last_error ) );
				}
				$id = (int) $wpdb->insert_id;
				$updated = $wpdb->update(
					$b,
					array(
						'balance_minor' => $row['balance_minor'],
						'entries'       => (int) $acc->entries + 1,
						'last_hash'     => $row['hash'],
						'updated_at'    => $row['created_at'],
					),
					array(
						'affiliate_id' => (int) $affiliate_id,
						'currency'     => (string) $currency,
					)
				);
				// Booking and account summary must change together (otherwise the whole booking rolls back).
				if ( 1 !== (int) $updated ) {
					throw new IntegrationException( 'balance_update', esc_html( '' !== $wpdb->last_error ? $wpdb->last_error : 'Account summary was not updated.' ) );
				}
				return $id;
			}
		);
	}

	/**
	 * Hash of a booking (chained via prev_hash).
	 *
	 * @param array $row Booking.
	 * @return string
	 */
	public static function hash( array $row ) {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					$row['prev_hash'],
					(int) $row['affiliate_id'],
					$row['currency'],
					$row['type'],
					(int) $row['amount_minor'],
					(int) $row['balance_minor'],
					(int) $row['commission_id'],
					(int) $row['payout_id'],
					$row['reference'],
					$row['created_at'],
				)
			)
		);
	}

	/**
	 * Current balance.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int
	 */
	public static function get( $affiliate_id, $currency ) {
		global $wpdb;
		$b = Db::table( 'balances' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT balance_minor FROM {$b} WHERE affiliate_id = %d AND currency = %s", $affiliate_id, $currency ) );
	}

	/**
	 * Bookings of an account (newest first).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param int    $limit        Limit.
	 * @return array
	 */
	public static function entries( $affiliate_id, $currency, $limit = 200 ) {
		global $wpdb;
		$e = Db::table( 'balance_entries' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE affiliate_id = %d AND currency = %s ORDER BY id DESC LIMIT %d", $affiliate_id, $currency, $limit ) );
	}

	/**
	 * Currencies with an account for a partner.
	 *
	 * @param int $affiliate_id Partner.
	 * @return string[]
	 */
	public static function currencies( $affiliate_id ) {
		global $wpdb;
		$b = Db::table( 'balances' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare( "SELECT currency FROM {$b} WHERE affiliate_id = %d ORDER BY currency", $affiliate_id ) );
	}

	/**
	 * Verify an account: hash chain, running balances, stored balance and the
	 * invariant against the commission ledger.
	 *
	 * The bookings are read in pages (never the whole history at once). An incremental check
	 * starts after the last verified booking (checkpoint in the balances row) and only re-hashes
	 * the bookings since then; it still checks that the checkpoint booking is unchanged and that
	 * no booking was removed (number of bookings). A full check re-hashes everything.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param bool   $incremental  Start from the checkpoint (if there is one).
	 * @return string[] Problems (empty = ok).
	 */
	public static function verify( $affiliate_id, $currency, $incremental = false ) {
		global $wpdb;
		$problems = array();
		$e        = Db::table( 'balance_entries' );
		$b        = Db::table( 'balances' );
		$c        = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$acc  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$b} WHERE affiliate_id = %d AND currency = %s", $affiliate_id, $currency ) );
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$c} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0", $affiliate_id, $currency ) );
		$prev    = '';
		$running = 0;
		$after   = 0;
		$full    = true;
		if ( $incremental && $acc && isset( $acc->verified_id ) && (int) $acc->verified_id > 0 ) {
			$point = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$e} WHERE id = %d AND affiliate_id = %d AND currency = %s", (int) $acc->verified_id, $affiliate_id, $currency ), ARRAY_A );
			if ( $point && $point['hash'] === $acc->verified_hash && (int) $point['balance_minor'] === (int) $acc->verified_balance && self::hash( $point ) === $point['hash'] ) {
				$after   = (int) $acc->verified_id;
				$prev    = (string) $acc->verified_hash;
				$running = (int) $acc->verified_balance;
				$full    = false;
			}
			// Otherwise the checkpoint booking was changed or removed: check everything (and report it).
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$e} WHERE affiliate_id = %d AND currency = %s", $affiliate_id, $currency ) );
		$last  = $after;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE affiliate_id = %d AND currency = %s AND id > %d ORDER BY id ASC LIMIT %d", $affiliate_id, $currency, $last, self::VERIFY_PAGE ), ARRAY_A );
			foreach ( $rows as $row ) {
				$running += (int) $row['amount_minor'];
				if ( $row['prev_hash'] !== $prev ) {
					/* translators: %d: booking ID */
					$problems[] = sprintf( __( 'Booking #%d: chain broken (a booking was removed or reordered).', 'affiliate-captain' ), $row['id'] );
				}
				if ( self::hash( $row ) !== $row['hash'] ) {
					/* translators: %d: booking ID */
					$problems[] = sprintf( __( 'Booking #%d: content was modified.', 'affiliate-captain' ), $row['id'] );
				}
				if ( (int) $row['balance_minor'] !== $running ) {
					/* translators: %d: booking ID */
					$problems[] = sprintf( __( 'Booking #%d: running balance does not match.', 'affiliate-captain' ), $row['id'] );
				}
				$prev = $row['hash'];
				$last = (int) $row['id'];
			}
		} while ( count( $rows ) === self::VERIFY_PAGE );
		// phpcs:enable
		if ( $acc && ( (int) $acc->balance_minor !== $running || $acc->last_hash !== $prev || (int) $acc->entries !== $count ) ) {
			$problems[] = __( 'Stored account balance does not match the bookings.', 'affiliate-captain' );
		}
		if ( $running !== $open ) {
			/* translators: 1: balance, 2: open commissions */
			$problems[] = sprintf( __( 'Balance (%1$d) differs from approved, unpaid commissions (%2$d).', 'affiliate-captain' ), $running, $open );
		}
		if ( ! $problems && $acc && $last > 0 && property_exists( $acc, 'verified_id' ) ) {
			// Checkpoint for the next incremental check (only a consistent state is remembered).
			$fields = array(
				'verified_id'      => $last,
				'verified_hash'    => $prev,
				'verified_balance' => $running,
			);
			if ( $full ) {
				$fields['verified_at'] = Db::now();
			}
			$wpdb->update(
				$b,
				$fields,
				array(
					'affiliate_id' => (int) $affiliate_id,
					'currency'     => (string) $currency,
				)
			);
		}
		return $problems;
	}

	/** Bookings read per query during a check. */
	const VERIFY_PAGE = 2000;

	/**
	 * Verify all accounts (bounded), in pages of 500 accounts.
	 *
	 * @param int      $limit   Max accounts (0 = all).
	 * @param int|null $checked Out: number of accounts checked.
	 * @return array "affiliate_id:currency" => problems
	 */
	public static function verify_all( $limit = 500, &$checked = null ) {
		global $wpdb;
		$b        = Db::table( 'balances' );
		$out      = array();
		$checked  = 0;
		$last_id  = -1;
		$last_cur = '';
		do {
			$page = $limit > 0 ? min( 500, (int) $limit - $checked ) : 500;
			if ( $page <= 0 ) {
				break;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, currency FROM {$b} WHERE affiliate_id > %d OR ( affiliate_id = %d AND currency > %s ) ORDER BY affiliate_id, currency LIMIT %d", $last_id, $last_id, $last_cur, $page ) );
			foreach ( $rows as $acc ) {
				++$checked;
				$last_id  = (int) $acc->affiliate_id;
				$last_cur = (string) $acc->currency;
				$p        = self::verify( (int) $acc->affiliate_id, $acc->currency );
				if ( $p ) {
					$out[ $acc->affiliate_id . ':' . $acc->currency ] = $p;
				}
			}
		} while ( count( $rows ) === $page );
		return $out;
	}

	/** Option: state of the running daily reconciliation pass (cursor and accounts with problems). */
	const CHECK_PASS = 'wpac_balance_check_pass';

	/** Single event that continues a reconciliation pass that used up its time budget. */
	const CHECK_HOOK = 'wpac_balance_check';

	/**
	 * Daily reconciliation: every partner account against its bookings (hash chain and sums).
	 * Incremental (only bookings since the last verified one are re-hashed; an account is fully
	 * re-hashed every 30 days), with a time budget: a pass that does not fit continues a few
	 * minutes later from where it stopped, so every account is checked however many there are.
	 * Differences are kept for the admin notice and Site Health and mailed at most once a day.
	 *
	 * @return array|null Problems of the finished pass (empty = consistent), null while it continues.
	 */
	public static function scheduled_check() {
		global $wpdb;
		$b    = Db::table( 'balances' );
		$pass = get_option( self::CHECK_PASS );
		$pass = is_array( $pass ) && isset( $pass['id'], $pass['cur'], $pass['problems'] ) ? $pass : array(
			'id'       => -1,
			'cur'      => '',
			'problems' => array(),
		);
		/**
		 * Seconds one reconciliation run may take before it continues in a separate request.
		 *
		 * @param float $budget Seconds (0 = no limit).
		 */
		$budget = (float) apply_filters( 'wpac_balance_check_budget', 30 );
		/**
		 * Days after which an account is fully re-hashed instead of incrementally.
		 *
		 * @param int $days Days.
		 */
		$full_days = max( 1, (int) apply_filters( 'wpac_balance_full_check_days', 30 ) );
		$full_by   = Db::now( time() - $full_days * DAY_IN_SECONDS );
		$start     = microtime( true );
		$done      = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, currency, verified_at FROM {$b} WHERE affiliate_id > %d OR ( affiliate_id = %d AND currency > %s ) ORDER BY affiliate_id, currency LIMIT 200", (int) $pass['id'], (int) $pass['id'], (string) $pass['cur'] ) );
			foreach ( $rows as $acc ) {
				if ( $budget > 0 && $done > 0 && microtime( true ) - $start > $budget ) {
					// At least one account per run: the pass always moves on.
					update_option( self::CHECK_PASS, $pass, false );
					\WPAffiliateCaptain\Core\Scheduler::once( self::CHECK_HOOK, 120 );
					return null;
				}
				$incremental = ! empty( $acc->verified_at ) && (string) $acc->verified_at >= $full_by;
				$p           = self::verify( (int) $acc->affiliate_id, (string) $acc->currency, $incremental );
				if ( $p ) {
					$pass['problems'][] = $acc->affiliate_id . ':' . $acc->currency;
				}
				$pass['id']  = (int) $acc->affiliate_id;
				$pass['cur'] = (string) $acc->currency;
				++$done;
			}
		} while ( 200 === count( $rows ) );
		delete_option( self::CHECK_PASS );
		$keys = array_values( array_unique( $pass['problems'] ) );
		return self::report( $keys );
	}

	/**
	 * Remember and report the accounts of a finished reconciliation pass.
	 *
	 * @param string[] $accounts "affiliate_id:currency" with differences.
	 * @return array The accounts (empty = consistent).
	 */
	private static function report( array $accounts ) {
		if ( ! $accounts ) {
			delete_option( 'wpac_balance_problems' );
			return array();
		}
		$known = get_option( 'wpac_balance_problems' );
		update_option(
			'wpac_balance_problems',
			array(
				'checked_at' => Db::now(),
				'accounts'   => $accounts,
			),
			false
		);
		\WPAffiliateCaptain\Core\Audit::log( 'balance.mismatch', 'balance', 0, array( 'accounts' => array_slice( $accounts, 0, 20 ) ) );
		if ( ! is_array( $known ) || ! isset( $known['accounts'] ) || $known['accounts'] !== $accounts || ! get_transient( 'wpac_balance_alerted' ) ) {
			set_transient( 'wpac_balance_alerted', 1, DAY_IN_SECONDS );
			\WPAffiliateCaptain\Affiliates\Notifications::send(
				'balance_mismatch',
				\WPAffiliateCaptain\Affiliates\Notifications::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] Partner program: account balances differ from the bookings', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: number, 2: accounts */
				sprintf( __( "The daily reconciliation found %1\$d partner accounts whose balance does not match the bookings:\n%2\$s\n\nPlease do not run payouts for them before checking (Affiliate Captain → Partners → partner → Account statement).", 'affiliate-captain' ), count( $accounts ), implode( ', ', array_slice( $accounts, 0, 50 ) ) ),
				array( 'admin' => true )
			);
		}
		return $accounts;
	}

	/**
	 * Migration: opening bookings from the current commission ledger.
	 */
	public static function open_from_ledger() {
		global $wpdb;
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT affiliate_id, currency, SUM(amount_minor) total FROM {$c} WHERE status = 'approved' AND payout_id = 0 GROUP BY affiliate_id, currency" );
		foreach ( $rows as $r ) {
			if ( ! self::currencies( (int) $r->affiliate_id ) || ! in_array( $r->currency, self::currencies( (int) $r->affiliate_id ), true ) ) {
				self::post( (int) $r->affiliate_id, $r->currency, 'opening', (int) $r->total, 0, 0, 'opening balance' );
			}
		}
	}

	/**
	 * Labels of booking types.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'approved'         => __( 'Commission approved', 'affiliate-captain' ),
			'rejected'         => __( 'Commission rejected', 'affiliate-captain' ),
			'payout'           => __( 'Payout', 'affiliate-captain' ),
			'payout_cancelled' => __( 'Payout cancelled', 'affiliate-captain' ),
			'opening'          => __( 'Opening balance', 'affiliate-captain' ),
		);
	}
}
