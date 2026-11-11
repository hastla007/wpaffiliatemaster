<?php
/**
 * Referral capture: ?partner=CODE (parameter configurable).
 *
 * - Records a minimized visit (no IP, only referrer host).
 * - Keeps the code in the WooCommerce session (shop/cart context, no marketing cookie).
 * - Sets the affiliate cookie only with marketing consent (or when configured to "always").
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	const COOKIE      = 'wpac_ref';
	const SESSION_KEY = 'wpac_ref';

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 5 );
		add_filter( 'wp_consent_api_registered_' . plugin_basename( WPAC_FILE ), '__return_true' );
	}

	/**
	 * Capture the referral parameter.
	 */
	public static function capture() {
		$param = (string) Settings::get( 'referral_param' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_admin() || empty( $_GET[ $param ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
		if ( ! RateLimiter::allow( 'visit', (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS ) ) {
			return;
		}
		$resolved = wpac_resolve_partner_code( $raw, array( 'context' => 'link' ) );
		if ( ! $resolved ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$touch = array(
			'code'       => $resolved['code'],
			'campaign'   => isset( $_GET['utm_campaign'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ), 0, 100 ) : '',
			'content_id' => is_singular() ? (int) get_queried_object_id() : 0,
			'ts'         => time(),
		);
		// phpcs:enable

		if ( ! self::is_bot() ) {
			$touch['visit_id'] = self::record_visit( $resolved, $touch );
		}

		self::store_touch( $touch );

		/**
		 * Fires after a referral link visit was captured.
		 *
		 * @param array $resolved affiliate_id, code_id, code.
		 * @param array $touch    Touch data.
		 */
		do_action( 'wpac_referral_captured', $resolved, $touch );
	}

	/**
	 * Store the touch in WC session and (with consent) cookie, honoring first/last touch.
	 *
	 * @param array $touch Touch.
	 */
	public static function store_touch( array $touch ) {
		$first = 'first' === Settings::get( 'touch_model' );

		if ( Settings::get( 'use_wc_session' ) && function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			$existing = WC()->session->get( self::SESSION_KEY );
			if ( ! $first || empty( $existing ) ) {
				WC()->session->set( self::SESSION_KEY, $touch );
			}
		}

		if ( Consent::cookie_allowed() && ! headers_sent() ) {
			if ( ! $first || empty( $_COOKIE[ self::COOKIE ] ) ) {
				$value = $touch['code'] . '|' . rawurlencode( $touch['campaign'] ) . '|' . (int) $touch['content_id'];
				setcookie(
					self::COOKIE,
					$value,
					array(
						'expires'  => time() + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'cookie_days' ) ),
						'path'     => COOKIEPATH ? COOKIEPATH : '/',
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}
	}

	/**
	 * Current touch from session (method "link") or consented cookie (method "cookie").
	 *
	 * @return array|null code, campaign, content_id, method
	 */
	public static function current_touch() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$touch = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $touch ) && ! empty( $touch['code'] ) ) {
				$touch['method'] = 'link';
				return $touch;
			}
		}
		if ( Consent::cookie_allowed() && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
			return array(
				'code'       => $parts[0],
				'campaign'   => isset( $parts[1] ) ? substr( rawurldecode( $parts[1] ), 0, 100 ) : '',
				'content_id' => isset( $parts[2] ) ? (int) $parts[2] : 0,
				'method'     => 'cookie',
			);
		}
		return null;
	}

	/**
	 * Clear stored touch after the order was attributed.
	 */
	public static function clear_touch() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * Insert a minimized visit row.
	 *
	 * @param array $resolved Resolved code.
	 * @param array $touch    Touch.
	 * @return int Visit ID.
	 */
	private static function record_visit( array $resolved, array $touch ) {
		global $wpdb;
		$ref_host = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$host     = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST );
			$ref_host = $host ? substr( strtolower( $host ), 0, 190 ) : '';
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$wpdb->insert(
			Db::table( 'visits' ),
			array(
				'affiliate_id'  => (int) $resolved['affiliate_id'],
				'code_id'       => (int) $resolved['code_id'],
				'campaign'      => $touch['campaign'],
				'utm_source'    => isset( $_GET['utm_source'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ), 0, 100 ) : '',
				'utm_medium'    => isset( $_GET['utm_medium'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ), 0, 100 ) : '',
				'referrer_host' => $ref_host,
				'landing_path'  => substr( (string) $path, 0, 255 ),
				'content_id'    => (int) $touch['content_id'],
				'created_at'    => Db::now(),
			)
		);
		// phpcs:enable
		return (int) $wpdb->insert_id;
	}

	/**
	 * Basic bot detection by user agent.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		foreach ( apply_filters( 'wpac_bot_user_agents', array( 'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview', 'curl', 'wget', 'python-requests', 'headless', 'lighthouse' ) ) as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Visits of an affiliate in a period.
	 *
	 * @param int $affiliate_id Affiliate (0 = all).
	 * @param int $days         Days.
	 * @return int
	 */
	public static function count_visits( $affiliate_id, $days = 30 ) {
		global $wpdb;
		$t     = Db::table( 'visits' );
		$since = Db::now( time() - $days * DAY_IN_SECONDS );
		if ( $affiliate_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE affiliate_id = %d AND created_at >= %s", $affiliate_id, $since ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", $since ) );
	}
}
