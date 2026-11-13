<?php
/**
 * Fixed-window rate limiter.
 *
 * - The client IP is only used as a salted hash in a short-lived cache key and
 *   never stored in plugin tables.
 * - Behind a reverse proxy/CDN, configure the proxy IPs/CIDRs (setting
 *   "trusted_proxies"); only then X-Forwarded-For / CF-Connecting-IP is used.
 * - IPv6 clients are grouped by /64 (one subscriber), so rotating addresses
 *   within a prefix does not bypass limits.
 * - Uses the object cache (atomic increments) when available.
 *
 * Pure helpers (client_ip, in_cidr, bucket_ip) have no WordPress dependency.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Whether the current client may perform the action.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Max hits per window (0 = unlimited).
	 * @param int    $window Seconds.
	 * @return bool
	 */
	public static function allow( $bucket, $limit, $window = 60 ) {
		if ( $limit <= 0 ) {
			return true;
		}
		$ip     = self::bucket_ip( self::current_ip() );
		$key    = 'wpac_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $ip . '|' . (int) floor( time() / $window ), wp_salt( 'nonce' ) ), 0, 32 );
		$group  = 'wpac_rl';
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, $group, $window );
			$hits = (int) wp_cache_incr( $key, 1, $group );
			return $hits <= $limit;
		}
		return self::count_in_db( $key, $window ) <= $limit;
	}

	/**
	 * Atomic counter without an external object cache: one INSERT … ON DUPLICATE KEY UPDATE,
	 * so parallel requests cannot all read the same old value (get/set_transient could).
	 * The rows are ordinary transients, so WordPress' expired-transient clean-up removes them.
	 *
	 * @param string $key    Transient name.
	 * @param int    $window Seconds.
	 * @return int Hits in this window, including this one.
	 */
	private static function count_in_db( $key, $window ) {
		global $wpdb;
		$name    = '_transient_' . $key;
		$timeout = '_transient_timeout_' . $key;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $timeout, (string) ( time() + (int) $window ) ) );
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $name ) );
		if ( false === $ok ) {
			return 0; // Database unavailable: do not block visitors (best effort, like before).
		}
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		// phpcs:enable
		return max( 1, $hits );
	}

	/**
	 * Client IP of the current request (proxy aware).
	 *
	 * @return string
	 */
	public static function current_ip() {
		$server  = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $k ) {
			if ( isset( $_SERVER[ $k ] ) ) {
				$server[ $k ] = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		$trusted = preg_split( '/[\s,]+/', (string) Settings::get( 'trusted_proxies' ), -1, PREG_SPLIT_NO_EMPTY );
		return (string) apply_filters( 'wpac_client_ip', self::client_ip( $server, $trusted ) );
	}

	/**
	 * Determine the client IP. Forwarded headers are only honoured when the
	 * direct peer is a trusted proxy; the right-most untrusted address wins.
	 *
	 * @param array    $server  Server vars (REMOTE_ADDR, HTTP_X_FORWARDED_FOR, HTTP_CF_CONNECTING_IP, HTTP_X_REAL_IP).
	 * @param string[] $trusted Trusted proxy IPs/CIDRs.
	 * @return string
	 */
	public static function client_ip( array $server, array $trusted ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( $server['REMOTE_ADDR'] ) : '';
		if ( '' === $remote || ! self::is_trusted( $remote, $trusted ) ) {
			return '' !== $remote ? $remote : 'unknown';
		}
		if ( ! empty( $server['HTTP_CF_CONNECTING_IP'] ) && filter_var( trim( $server['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ) ) {
			return trim( $server['HTTP_CF_CONNECTING_IP'] );
		}
		if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = array_reverse( array_map( 'trim', explode( ',', $server['HTTP_X_FORWARDED_FOR'] ) ) );
			foreach ( $chain as $hop ) {
				if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
					break;
				}
				if ( ! self::is_trusted( $hop, $trusted ) ) {
					return $hop;
				}
			}
		}
		if ( ! empty( $server['HTTP_X_REAL_IP'] ) && filter_var( trim( $server['HTTP_X_REAL_IP'] ), FILTER_VALIDATE_IP ) ) {
			return trim( $server['HTTP_X_REAL_IP'] );
		}
		return $remote;
	}

	/**
	 * Whether an IP matches one of the trusted entries.
	 *
	 * @param string   $ip      IP.
	 * @param string[] $trusted IPs/CIDRs.
	 * @return bool
	 */
	public static function is_trusted( $ip, array $trusted ) {
		foreach ( $trusted as $entry ) {
			if ( self::in_cidr( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IP in CIDR (IPv4 and IPv6). A plain IP is treated as /32 or /128.
	 *
	 * @param string $ip   IP.
	 * @param string $cidr CIDR.
	 * @return bool
	 */
	public static function in_cidr( $ip, $cidr ) {
		$parts = explode( '/', trim( $cidr ), 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$addr  = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		$bits = max( 0, min( strlen( $net ) * 8, $bits ) );
		$full = intdiv( $bits, 8 );
		if ( substr( $net, 0, $full ) !== substr( $addr, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
		return ( $net[ $full ] & $mask ) === ( $addr[ $full ] & $mask );
	}

	/**
	 * Address used for limits: IPv4 as is, IPv6 reduced to its /64 prefix.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public static function bucket_ip( $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return (string) $ip;
		}
		return inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}
}
