<?php
/**
 * Fixed-window rate limiter.
 *
 * - The client IP is only used as a salted hash in a short-lived cache key and
 *   never stored in plugin tables.
 * - Behind a reverse proxy/CDN, configure the proxy IPs/CIDRs (setting
 *   "trusted_proxies"); only then X-Forwarded-For is used (CF-Connecting-IP /
 *   X-Real-IP only when enabled with the filter `wpac_client_ip_headers`).
 * - IPv6 clients are grouped by /64 (one subscriber), so rotating addresses
 *   within a prefix does not bypass limits.
 * - Uses a shared object cache (atomic increments) when available, otherwise one
 *   atomic row per counter (BINARY(16) key) in the rate-limit table.
 * - Every limit can be changed with the filter `wpac_rate_limit`.
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
		$limit = self::limit( $bucket, $limit, $window );
		if ( $limit <= 0 ) {
			return true;
		}
		return self::hit( $bucket, 'ip:' . self::bucket_ip( self::current_ip() ), $window ) <= $limit;
	}

	/**
	 * Whether an action for a subject other than the client IP may be performed,
	 * e.g. per partner or per recipient address (an attacker can rotate IPs, not
	 * the account or the address being targeted). The subject is only stored as
	 * a salted hash.
	 *
	 * @param string     $bucket  Bucket name.
	 * @param string|int $subject Subject (partner ID, e-mail address …).
	 * @param int        $limit   Max hits per window (0 = unlimited).
	 * @param int        $window  Seconds.
	 * @return bool
	 */
	public static function allow_for( $bucket, $subject, $limit, $window = 60 ) {
		$limit = self::limit( $bucket, $limit, $window );
		if ( $limit <= 0 ) {
			return true;
		}
		return self::hit( $bucket, 'subject:' . (string) $subject, $window ) <= $limit;
	}

	/**
	 * Effective limit of a bucket. Every limit can be changed with the filter
	 * `wpac_rate_limit` (e.g. a higher `code_check` or `register` limit behind a proxy
	 * whose visitors share one address).
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Default limit.
	 * @param int    $window Seconds.
	 * @return int
	 */
	public static function limit( $bucket, $limit, $window = 60 ) {
		/**
		 * Filter a rate limit (hits per window, 0 = unlimited).
		 *
		 * @param int    $limit  Limit.
		 * @param string $bucket Bucket: code_check, rest_code, register, visit, …
		 * @param int    $window Window in seconds.
		 */
		return (int) apply_filters( 'wpac_rate_limit', (int) $limit, (string) $bucket, (int) $window );
	}

	/**
	 * Whether the subject was not seen during the last $window seconds (dedup). One counter
	 * per subject that lives $window seconds from its first hit: every later hit within that
	 * time is a repeat (no second, shifted window and no extra row). Uses a shared object
	 * cache or the plugin's rate-limit table, never wp_options rows (unless the table is not
	 * there yet).
	 *
	 * @param string $bucket  Bucket name.
	 * @param string $subject Subject.
	 * @param int    $window  Seconds.
	 * @param int    $offset  Unused (kept for compatibility).
	 * @return bool True also when no storage is available (best effort, nothing is lost).
	 */
	public static function first( $bucket, $subject, $window, $offset = 0 ) {
		unset( $offset );
		return self::count( self::key( $bucket . '|subject:' . (string) $subject ), $window ) <= 1;
	}

	/**
	 * Count one hit for bucket + subject in the current fixed window.
	 *
	 * @param string $bucket  Bucket.
	 * @param string $subject Subject.
	 * @param int    $window  Seconds.
	 * @return int Hits including this one (0 = storage unavailable).
	 */
	private static function hit( $bucket, $subject, $window ) {
		$window = max( 1, (int) $window );
		return self::count( self::key( $bucket . '|' . $subject . '|' . (int) floor( time() / $window ) ), $window );
	}

	/**
	 * Counter key: 32 hex characters of a keyed hash (the subject is never stored).
	 *
	 * @param string $name Bucket, subject and window.
	 * @return string
	 */
	private static function key( $name ) {
		return substr( hash_hmac( 'sha256', $name, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Increment a counter that expires $window seconds after its first hit.
	 *
	 * @param string $key    32 hex characters.
	 * @param int    $window Seconds.
	 * @return int Hits including this one (0 = storage unavailable).
	 */
	private static function count( $key, $window ) {
		$window = max( 1, (int) $window );
		if ( self::use_object_cache() ) {
			$group = 'wpac_rl';
			if ( wp_cache_add( 'wpac_rl_' . $key, 1, $group, $window ) ) {
				return 1;
			}
			$hits = wp_cache_incr( 'wpac_rl_' . $key, 1, $group );
			if ( false !== $hits ) {
				return (int) $hits;
			}
			// Evicted between add and incr: count in the database instead of letting the request
			// through unlimited (the limit fails closed, nothing is lost for the visitor).
		}
		return self::count_in_db( $key, $window );
	}

	/**
	 * Whether counters may live in the object cache. Only a cache shared by all web servers
	 * counts: a node-local cache (APCu drop-ins) would multiply every limit by the number of
	 * servers, so the database table is used then.
	 *
	 * @return bool
	 */
	public static function use_object_cache() {
		if ( ! wp_using_ext_object_cache() ) {
			return false;
		}
		$cache  = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ? get_class( $GLOBALS['wp_object_cache'] ) : '';
		$shared = self::shared_cache_class( $cache );
		/**
		 * Whether rate-limit counters may use the persistent object cache (it must be shared by
		 * all web servers, e.g. Redis or Memcached – not APCu).
		 *
		 * @param bool   $shared Default: true unless the object cache class looks like APCu.
		 * @param string $cache  Class of the object cache.
		 */
		return (bool) apply_filters( 'wpac_rate_limit_object_cache', $shared, $cache );
	}

	/**
	 * Whether an object cache class is shared between servers (not an APCu drop-in).
	 *
	 * @param string $cache Class name.
	 * @return bool
	 */
	public static function shared_cache_class( $cache ) {
		return false === stripos( (string) $cache, 'apc' );
	}

	/**
	 * Atomic counter without a shared object cache: one INSERT … ON DUPLICATE KEY UPDATE whose
	 * new value comes back through LAST_INSERT_ID() (per connection), so parallel requests can
	 * neither read the same old value nor another request's value – and no extra SELECT.
	 *
	 * @param string $key    32 hex characters (stored as BINARY(16)).
	 * @param int    $window Seconds.
	 * @return int Hits in this window, including this one.
	 */
	private static function count_in_db( $key, $window ) {
		global $wpdb;
		$table = \WPAffiliateCaptain\Core\Db::table( 'rate_limits' );
		$now   = time();
		// One row per key; an expired row starts again at 1 (atomic, no read-modify-write).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (rl_key, hits, expires_at) VALUES (UNHEX(%s), LAST_INSERT_ID(1), %d) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(expires_at < %d, 1, hits + 1)), expires_at = IF(expires_at < %d, %d, expires_at)", $key, $now + (int) $window, $now, $now, $now + (int) $window ) );
		$wpdb->suppress_errors( $suppress );
		if ( false !== $ok ) {
			$hits = (int) $wpdb->insert_id;
			if ( $hits < 1 ) {
				// Database layer without the insert ID of this statement: read the row.
				$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE rl_key = UNHEX(%s)", $key ) );
			}
			// phpcs:enable
			return max( 1, $hits );
		}
		// Table not there yet (migration pending): transient rows in wp_options as before.
		$name    = '_transient_wpac_rl_' . $key;
		$timeout = '_transient_timeout_wpac_rl_' . $key;
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
	 * Delete expired counters in small batches. Runs hourly, so the table never holds more
	 * than about an hour of expired rows, however many visits there are.
	 *
	 * @return int Deleted rows.
	 */
	public static function purge() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return \WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( 'DELETE FROM ' . \WPAffiliateCaptain\Core\Db::table( 'rate_limits' ) . ' WHERE expires_at < %d', time() ), 5000, 200 );
	}

	/**
	 * Key of the current client for visit statistics: address plus a short hash of the browser
	 * (user agent, language), so visitors behind one proxy or carrier NAT are not merged into
	 * one. Only used inside a hashed counter key.
	 *
	 * @return string
	 */
	public static function client_key() {
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		return self::bucket_ip( self::current_ip() ) . '|' . substr( md5( $ua . '|' . $lang ), 0, 12 );
	}

	/**
	 * Whether the request came through a proxy/CDN that is not configured as trusted: forwarding
	 * headers are present, but the direct peer is not in "trusted_proxies". Then all visitors
	 * share the proxy address for every limit (Site Health warns about it).
	 *
	 * @return bool
	 */
	public static function behind_untrusted_proxy() {
		$headers = false;
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED' ) as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$headers = true;
			}
		}
		if ( ! $headers || empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return false;
		}
		$trusted = preg_split( '/[\s,]+/', (string) Settings::get( 'trusted_proxies' ), -1, PREG_SPLIT_NO_EMPTY );
		return ! self::is_trusted( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), $trusted );
	}

	/**
	 * Client IP of the current request (proxy aware).
	 *
	 * @return string
	 */
	public static function current_ip() {
		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $k ) {
			if ( isset( $_SERVER[ $k ] ) ) {
				$server[ $k ] = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		$trusted = preg_split( '/[\s,]+/', (string) Settings::get( 'trusted_proxies' ), -1, PREG_SPLIT_NO_EMPTY );
		/**
		 * Headers that carry the client address behind a trusted proxy. Default: only
		 * X-Forwarded-For (proxies and Cloudflare append to it). CF-Connecting-IP or X-Real-IP
		 * are only safe when EVERY trusted proxy overwrites them – add 'HTTP_CF_CONNECTING_IP'
		 * only if the trusted entries are exactly Cloudflare's ranges.
		 *
		 * @param string[] $headers $_SERVER keys in order of preference.
		 */
		$headers = (array) apply_filters( 'wpac_client_ip_headers', array( 'HTTP_X_FORWARDED_FOR' ) );
		return (string) apply_filters( 'wpac_client_ip', self::client_ip( $server, $trusted, $headers ) );
	}

	/**
	 * Determine the client IP. Forwarded headers are only honoured when the
	 * direct peer is a trusted proxy; the right-most untrusted address wins.
	 * Single-value headers (CF-Connecting-IP, X-Real-IP) are only read when listed in
	 * $headers: any client can send them, and a trusted proxy that does not overwrite them
	 * passes a forged value on.
	 *
	 * @param array    $server  Server vars (REMOTE_ADDR, HTTP_X_FORWARDED_FOR, HTTP_CF_CONNECTING_IP, HTTP_X_REAL_IP).
	 * @param string[] $trusted Trusted proxy IPs/CIDRs.
	 * @param string[] $headers Headers to read, in order (default: X-Forwarded-For only).
	 * @return string
	 */
	public static function client_ip( array $server, array $trusted, array $headers = array( 'HTTP_X_FORWARDED_FOR' ) ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( $server['REMOTE_ADDR'] ) : '';
		if ( '' === $remote || ! self::is_trusted( $remote, $trusted ) ) {
			return '' !== $remote ? $remote : 'unknown';
		}
		foreach ( $headers as $header ) {
			if ( empty( $server[ $header ] ) ) {
				continue;
			}
			if ( 'HTTP_X_FORWARDED_FOR' !== $header ) {
				$ip = trim( (string) $server[ $header ] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
				continue;
			}
			$chain = array_reverse( array_map( 'trim', explode( ',', (string) $server[ $header ] ) ) );
			foreach ( $chain as $hop ) {
				if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
					break;
				}
				if ( ! self::is_trusted( $hop, $trusted ) ) {
					return $hop;
				}
			}
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
