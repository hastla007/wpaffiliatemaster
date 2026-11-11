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
	 * Delete expired links (cron).
	 *
	 * @return int
	 */
	public static function purge_expired() {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at IS NOT NULL AND expires_at <= %s", Db::now() ) );
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_hash IN ({$placeholders}) AND (expires_at IS NULL OR expires_at > %s) ORDER BY id ASC LIMIT 1", $params ) );
	}

	/**
	 * Link a customer to a partner (first link wins; existing links are kept).
	 *
	 * @param int      $affiliate_id Affiliate.
	 * @param string[] $hashes       Hashes.
	 * @param string   $source       Source.
	 * @param string   $order_id     Order.
	 */
	public static function link( $affiliate_id, array $hashes, $source, $order_id ) {
		global $wpdb;
		$days    = (int) Settings::get( 'lifetime_days' );
		$expires = $days > 0 ? Db::now( time() + $days * DAY_IN_SECONDS ) : null;
		$t       = Db::table( 'customer_links' );
		foreach ( $hashes as $hash ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE customer_hash = %s AND (expires_at IS NULL OR expires_at > %s)", $hash, Db::now() ) );
			if ( $exists ) {
				continue;
			}
			// Replace an expired link for the same customer.
			$wpdb->delete( $t, array( 'customer_hash' => $hash ) );
			$wpdb->insert(
				$t,
				array(
					'affiliate_id'    => (int) $affiliate_id,
					'customer_hash'   => $hash,
					'source'          => substr( (string) $source, 0, 40 ),
					'source_order_id' => substr( (string) $order_id, 0, 64 ),
					'created_at'      => Db::now(),
					'expires_at'      => $expires,
				)
			);
		}
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
	 * Number of linked customers of a partner.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return int
	 */
	public static function count_for( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'customer_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source, source_order_id) FROM {$t} WHERE affiliate_id = %d AND (expires_at IS NULL OR expires_at > %s)", $affiliate_id, Db::now() ) );
	}
}
