<?php
/**
 * Lifetime customer ↔ partner links.
 *
 * After a customer's first attributed purchase, later purchases by the same
 * customer can be attributed to the same partner (optionally limited in days and
 * with a separate rate). Customers are identified only by keyed hashes (HMAC) of
 * their user ID and normalized e-mail – no plain customer data is stored.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class CustomerLinks {

	/**
	 * Keyed hash of an identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private static function hash( $identifier ) {
		return hash_hmac( 'sha256', $identifier, self::key() );
	}

	/**
	 * Dedicated, persistent key (independent of WordPress salt rotation, so
	 * erasure requests still find the hashes).
	 *
	 * @return string
	 */
	private static function key() {
		$key = get_option( 'wpac_customer_key' );
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
			$key = bin2hex( random_bytes( 32 ) );
			add_option( 'wpac_customer_key', $key, '', false );
		}
		return $key;
	}

	/**
	 * Delete expired links (cron) in small batches (index on expires_at), so a large backlog never
	 * becomes one long-locking DELETE. With lifetime commissions switched off, the links have no
	 * purpose any more and are all deleted (the privacy notice no longer mentions them).
	 *
	 * @return int
	 */
	public static function purge_expired() {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		if ( ! Settings::get( 'lifetime_enabled' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return \WPAffiliateCaptain\Core\Cron::delete_in_batches( "DELETE FROM {$t}", 2000, 100 );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return \WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at IS NOT NULL AND expires_at <= %s", Db::now() ), 2000, 100 );
	}

	/**
	 * Register hooks: the links of a partner end with the partnership.
	 */
	public static function register_hooks() {
		add_action( 'wpac_affiliate_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 2 );
	}

	/**
	 * A terminated or rejected partner keeps no customers ("until the end of the contract").
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $status       New status.
	 */
	public static function on_status_changed( $affiliate_id, $status ) {
		if ( in_array( $status, array( 'terminated', 'rejected' ), true ) ) {
			self::forget_affiliate( (int) $affiliate_id );
		}
	}

	/**
	 * Remove all customer links of a partner.
	 *
	 * @param int $affiliate_id Partner.
	 * @return int Deleted rows.
	 */
	public static function forget_affiliate( $affiliate_id ) {
		global $wpdb;
		$n = (int) $wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $affiliate_id ) );
		if ( $n ) {
			\WPAffiliateCaptain\Core\Audit::log( 'customer_links.removed', 'affiliate', (int) $affiliate_id, array( 'count' => $n ) );
		}
		return $n;
	}

	/**
	 * Hashes for a sale's customer.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @return string[]
	 */
	public static function hashes( $user_id, $email ) {
		$out = array();
		if ( (int) $user_id ) {
			$out[] = self::hash( 'u:' . (int) $user_id );
		}
		$email = AffiliateRepository::comparable_email( $email );
		if ( '' !== $email ) {
			$out[] = self::hash( 'e:' . $email );
		}
		return $out;
	}

	/**
	 * Hashes a NEW link may be created for. Only identities the shop has verified count: the
	 * customer account (user ID) and the e-mail address of that account. A guest order's billing
	 * e-mail is not verified – anyone can type a stranger's address into a 0 € guest order – so
	 * guest orders never create a link (they can still be matched by an existing one).
	 *
	 * @param int    $user_id User ID of the order (0 = guest).
	 * @param string $email   Billing e-mail of the order.
	 * @return string[]
	 */
	public static function link_hashes( $user_id, $email ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		$account = '';
		$user    = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( $user && isset( $user->user_email ) && AffiliateRepository::comparable_email( $user->user_email ) === AffiliateRepository::comparable_email( $email ) ) {
			$account = (string) $user->user_email;
		}
		/**
		 * Whether a new lifetime link may also use the account e-mail (so later guest orders with
		 * that address are found). Return false to bind customer accounts only.
		 *
		 * @param bool   $use     Default: true when the billing e-mail is the account e-mail.
		 * @param int    $user_id Customer user ID.
		 * @param string $email   Billing e-mail.
		 */
		if ( '' !== $account && ! apply_filters( 'wpac_lifetime_link_email', true, $user_id, $email ) ) {
			$account = '';
		}
		return self::hashes( $user_id, $account );
	}

	/**
	 * All active (not pending, not expired) links of a customer, oldest first.
	 *
	 * @param string[] $hashes Hashes.
	 * @return object[]
	 */
	public static function find_all( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$t            = Db::table( 'customer_links' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( $hashes, array( Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) AND pending = 0 AND (expires_at IS NULL OR expires_at > %s) ORDER BY id ASC", $params ) );
	}

	/**
	 * Active partner link for a customer.
	 *
	 * @param string[] $hashes Hashes.
	 * @return object|null
	 */
	public static function find( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return null;
		}
		$t            = Db::table( 'customer_links' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( $hashes, array( Db::now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) AND pending = 0 AND (expires_at IS NULL OR expires_at > %s) ORDER BY id ASC LIMIT 1", $params ) );
	}

	/**
	 * Link a customer to a partner (first link wins; existing links are kept). A link to a
	 * partner who no longer works with the shop (terminated, rejected, deleted) is replaced, and a
	 * pending link (order still under review) never blocks an active one.
	 *
	 * @param int      $affiliate_id Affiliate.
	 * @param string[] $hashes       Hashes (see link_hashes()).
	 * @param string   $source       Source.
	 * @param string   $order_id     Order.
	 * @param bool     $pending      Order under review: the link only counts once approved (activate_order()).
	 */
	public static function link( $affiliate_id, array $hashes, $source, $order_id, $pending = false ) {
		global $wpdb;
		$days    = (int) Settings::get( 'lifetime_days' );
		$expires = $days > 0 ? Db::now( time() + $days * DAY_IN_SECONDS ) : null;
		$t       = Db::table( 'customer_links' );
		foreach ( $hashes as $hash ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, affiliate_id, pending FROM {$t} WHERE customer_hash = %s AND (expires_at IS NULL OR expires_at > %s)", $hash, Db::now() ) );
			if ( $row && ( ! (int) $row->pending || $pending ) && self::binding( (int) $row->affiliate_id ) ) {
				continue;
			}
			// Replace an expired, pending or orphaned link for the same customer.
			$wpdb->delete( $t, array( 'customer_hash' => $hash ) );
			$wpdb->insert(
				$t,
				array(
					'affiliate_id'    => (int) $affiliate_id,
					'customer_hash'   => $hash,
					'source'          => substr( (string) $source, 0, 40 ),
					'source_order_id' => substr( (string) $order_id, 0, 64 ),
					'pending'         => $pending ? 1 : 0,
					'created_at'      => Db::now(),
					'expires_at'      => $expires,
				)
			);
		}
	}

	/**
	 * Whether a partner still holds customer links (exists and was not terminated/rejected).
	 * Paused or restricted partners keep their customers; they just earn nothing meanwhile.
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	private static function binding( $affiliate_id ) {
		$aff = AffiliateRepository::find( $affiliate_id );
		return $aff && ! in_array( $aff->status, array( 'terminated', 'rejected' ), true );
	}

	/**
	 * Activate the pending links of an order (its commission was approved after a review).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return int Activated rows.
	 */
	public static function activate_order( $source, $order_id ) {
		global $wpdb;
		return (int) $wpdb->update(
			Db::table( 'customer_links' ),
			array( 'pending' => 0 ),
			array(
				'source'          => substr( (string) $source, 0, 40 ),
				'source_order_id' => substr( (string) $order_id, 0, 64 ),
				'pending'         => 1,
			)
		);
	}

	/**
	 * Links of one customer for the admin (account and/or e-mail), with partner names.
	 *
	 * @param int    $user_id User ID (0 = none).
	 * @param string $email   E-mail ('' = none).
	 * @return object[]
	 */
	public static function for_customer( $user_id, $email ) {
		global $wpdb;
		$hashes = self::hashes( $user_id, $email );
		if ( ! $hashes ) {
			return array();
		}
		$t            = Db::table( 'customer_links' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) ORDER BY id ASC", $hashes ) );
	}

	/**
	 * Admin removes the lifetime binding of a customer (audited).
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @param string $reason  Reason.
	 * @return int Deleted rows.
	 */
	public static function unlink_customer( $user_id, $email, $reason = '' ) {
		$rows = self::for_customer( $user_id, $email );
		$n    = self::forget( $user_id, $email );
		if ( $n ) {
			\WPAffiliateCaptain\Core\Audit::log(
				'customer_links.unlinked',
				'affiliate',
				$rows ? (int) $rows[0]->affiliate_id : 0,
				array(
					'count'  => $n,
					'orders' => array_values( array_unique( wp_list_pluck( $rows, 'source_order_id' ) ) ),
					'reason' => substr( (string) $reason, 0, 255 ),
				)
			);
		}
		return $n;
	}

	/**
	 * Remove links of a customer (privacy eraser).
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   E-mail.
	 * @return int Deleted rows.
	 */
	public static function forget( $user_id, $email ) {
		global $wpdb;
		$n = 0;
		foreach ( self::hashes( $user_id, $email ) as $hash ) {
			$n += (int) $wpdb->delete( Db::table( 'customer_links' ), array( 'customer_hash' => $hash ) );
		}
		return $n;
	}

	/**
	 * Remove the links created by an order (the order was fully refunded, cancelled
	 * or rejected – a reversed first order must not bind the customer to the partner).
	 *
	 * @param string $source   Source.
	 * @param string $order_id Order.
	 * @return int Deleted rows.
	 */
	public static function forget_order( $source, $order_id ) {
		global $wpdb;
		return (int) $wpdb->delete(
			Db::table( 'customer_links' ),
			array(
				'source'          => substr( (string) $source, 0, 40 ),
				'source_order_id' => substr( (string) $order_id, 0, 64 ),
			)
		);
	}

	/**
	 * Number of linked customers of a partner.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return int
	 */
	public static function count_for( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source, source_order_id) FROM {$t} WHERE affiliate_id = %d AND pending = 0 AND (expires_at IS NULL OR expires_at > %s)", $affiliate_id, Db::now() ) );
	}
}
