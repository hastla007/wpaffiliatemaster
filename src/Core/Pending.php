<?php
/**
 * Short-lived records that must not get lost (pending applications, confirmed-application markers,
 * pending e-mail changes): kept as options with their own expiry instead of transients – with a
 * persistent object cache (Redis, Memcached) transients live only in the cache and can be
 * evicted or flushed at any time.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Pending {

	/** Option name prefix. */
	const PREFIX = 'wpac_pending_';

	/**
	 * Store a value.
	 *
	 * @param string $key   Key (letters, digits, "_").
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds until it expires.
	 * @return bool Whether it is stored.
	 */
	public static function set( $key, $value, $ttl ) {
		$name  = self::PREFIX . $key;
		$entry = array(
			'value'   => $value,
			'expires' => time() + (int) $ttl,
		);
		update_option( $name, $entry, false );
		$stored = get_option( $name );
		return is_array( $stored ) && $stored === $entry;
	}

	/**
	 * Value, or null when missing or expired.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$entry = get_option( self::PREFIX . $key );
		if ( ! is_array( $entry ) || ! array_key_exists( 'value', $entry ) ) {
			return null;
		}
		if ( (int) $entry['expires'] < time() ) {
			delete_option( self::PREFIX . $key );
			return null;
		}
		return $entry['value'];
	}

	/**
	 * Delete a value.
	 *
	 * @param string $key Key.
	 */
	public static function delete( $key ) {
		delete_option( self::PREFIX . $key );
	}

	/**
	 * Daily: remove expired records (in batches).
	 *
	 * @return int Removed records.
	 */
	public static function purge() {
		global $wpdb;
		$removed = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 2000", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		foreach ( $names as $name ) {
			$entry = get_option( (string) $name );
			if ( ! is_array( $entry ) || (int) ( isset( $entry['expires'] ) ? $entry['expires'] : 0 ) < time() ) {
				delete_option( (string) $name );
				++$removed;
			}
		}
		return $removed;
	}
}
