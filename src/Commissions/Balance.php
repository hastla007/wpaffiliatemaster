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
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return string[] Problems (empty = ok).
	 */
	public static function verify( $affiliate_id, $currency ) {
		global $wpdb;
		$problems = array();
		$e        = Db::table( 'balance_entries' );
		$b        = Db::table( 'balances' );
		$c        = Db::table( 'commissions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE affiliate_id = %d AND currency = %s ORDER BY id ASC", $affiliate_id, $currency ), ARRAY_A );
		$acc  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$b} WHERE affiliate_id = %d AND currency = %s", $affiliate_id, $currency ) );
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$c} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0", $affiliate_id, $currency ) );
		// phpcs:enable
		$prev    = '';
		$running = 0;
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
		}
		if ( $acc && ( (int) $acc->balance_minor !== $running || $acc->last_hash !== $prev ) ) {
			$problems[] = __( 'Stored account balance does not match the bookings.', 'affiliate-captain' );
		}
		if ( $running !== $open ) {
			/* translators: 1: balance, 2: open commissions */
			$problems[] = sprintf( __( 'Balance (%1$d) differs from approved, unpaid commissions (%2$d).', 'affiliate-captain' ), $running, $open );
		}
		return $problems;
	}

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

	/**
	 * Daily reconciliation: every partner account against its bookings (hash chain and sums).
	 * Differences are kept for the admin notice and Site Health and mailed at most once a day.
	 *
	 * @return array Problems (empty = consistent).
	 */
	public static function scheduled_check() {
		$problems = self::verify_all( 5000 );
		if ( ! $problems ) {
			delete_option( 'wpac_balance_problems' );
			return array();
		}
		$known = get_option( 'wpac_balance_problems' );
		update_option(
			'wpac_balance_problems',
			array(
				'checked_at' => Db::now(),
				'accounts'   => array_keys( $problems ),
			),
			false
		);
		\WPAffiliateCaptain\Core\Audit::log( 'balance.mismatch', 'balance', 0, array( 'accounts' => array_slice( array_keys( $problems ), 0, 20 ) ) );
		if ( ! is_array( $known ) || $known['accounts'] !== array_keys( $problems ) || ! get_transient( 'wpac_balance_alerted' ) ) {
			set_transient( 'wpac_balance_alerted', 1, DAY_IN_SECONDS );
			\WPAffiliateCaptain\Affiliates\Notifications::send(
				'balance_mismatch',
				\WPAffiliateCaptain\Affiliates\Notifications::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] Partner program: account balances differ from the bookings', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: number, 2: accounts */
				sprintf( __( "The daily reconciliation found %1\$d partner accounts whose balance does not match the bookings:\n%2\$s\n\nPlease do not run payouts for them before checking (Affiliate Captain → Partners → partner → Account statement).", 'affiliate-captain' ), count( $problems ), implode( ', ', array_slice( array_keys( $problems ), 0, 50 ) ) ),
				array( 'admin' => true )
			);
		}
		return $problems;
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
