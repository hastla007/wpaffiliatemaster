<?php
/**
 * Simple fixed-window rate limiter. The client IP is only used as a salted hash
 * in a short-lived transient key and never stored in plugin tables.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

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
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'wpac_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}
}
