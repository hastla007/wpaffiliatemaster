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
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_on_add_to_cart' ), 5 );
		add_action( 'init', array( __CLASS__, 'drop_cookie_without_consent' ) );
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
		// A page with a referral parameter must never come from (or go into) a full-page
		// cache: otherwise later visitors get the cached page and no visit/touch is recorded.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
		$resolved = wpac_resolve_partner_code( $raw, array( 'context' => 'link' ) );
		if ( ! $resolved ) {
			return;
		}
		// The limit only throttles visit statistics – the referral itself is always kept.
		// Checked after the code resolved, so unknown codes cause no counter writes.
		$count_visit = ! self::is_bot() && RateLimiter::allow( 'visit', (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$touch = array(
			'code'       => $resolved['code'],
			'campaign'   => isset( $_GET['utm_campaign'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ), 0, 100 ) : '',
			'content_id' => is_singular() ? (int) get_queried_object_id() : 0,
			'ts'         => time(),
		);
		// phpcs:enable

		if ( $count_visit ) {
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

		// The shop session is only used when it already exists (e.g. a cart) or with marketing
		// consent – a click alone never creates a cookie without consent (TTDSG §25).
		if ( Settings::get( 'use_wc_session' ) && function_exists( 'WC' ) && WC()->session && ( WC()->session->has_session() || Consent::cookie_allowed() ) ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
				// The session exists only because of the consent: withdrawing it removes the touch.
				$touch['via_consent'] = 1;
			}
			$existing = WC()->session->get( self::SESSION_KEY );
			if ( ! $first || empty( $existing ) ) {
				WC()->session->set( self::SESSION_KEY, $touch );
			}
		}

		if ( Consent::cookie_allowed() && ! headers_sent() ) {
			if ( ! $first || empty( $_COOKIE[ self::COOKIE ] ) ) {
				$value = self::sign( $touch['code'] . '|' . rawurlencode( $touch['campaign'] ) . '|' . (int) $touch['content_id'] . '|' . (int) $touch['ts'] );
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
	 * Cookie-free fallback: when a product is added to the cart (the shop session
	 * exists now anyway), take the partner code from the current URL or the
	 * referring page of this shop.
	 */
	public static function capture_on_add_to_cart() {
		if ( ! Settings::get( 'use_wc_session' ) || ! function_exists( 'WC' ) || ! WC()->session || WC()->session->get( self::SESSION_KEY ) ) {
			return;
		}
		$param      = (string) Settings::get( 'referral_param' );
		$code       = '';
		$campaign   = '';
		$content_id = 0;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ $param ] ) ) {
			$code     = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			$campaign = isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '';
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			if ( wp_parse_url( $ref, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				parse_str( (string) wp_parse_url( $ref, PHP_URL_QUERY ), $query );
				$code     = isset( $query[ $param ] ) ? sanitize_text_field( (string) $query[ $param ] ) : '';
				$campaign = isset( $query['utm_campaign'] ) ? sanitize_text_field( (string) $query['utm_campaign'] ) : '';
				// The referring page is the content the visitor came through (same as a direct link visit).
				$content_id = '' !== $code ? (int) url_to_postid( $ref ) : 0;
			}
		}
		// phpcs:enable
		$resolved = '' !== $code ? wpac_resolve_partner_code( $code, array( 'context' => 'cart' ) ) : null;
		if ( $resolved ) {
			WC()->session->set(
				self::SESSION_KEY,
				array(
					'code'       => $resolved['code'],
					'campaign'   => substr( $campaign, 0, 100 ),
					'content_id' => $content_id,
					'ts'         => time(),
				)
			);
		}
	}

	/**
	 * Remove the affiliate cookie when consent is missing or was withdrawn.
	 */
	public static function drop_cookie_without_consent() {
		if ( Consent::cookie_allowed() ) {
			return;
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) && ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}

	/**
	 * Sign a cookie value (HMAC), so code, campaign, content and the click time
	 * cannot be forged (fraud checks and the attribution window rely on them).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sign( $value ) {
		return $value . '|' . substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) . 'wpac_ref' ), 0, 16 );
	}

	/**
	 * Parse and verify the referral cookie. Unsigned cookies from before signing
	 * are accepted for one attribution window after the update.
	 *
	 * @param string $raw Raw value.
	 * @return array|null
	 */
	public static function parse_cookie( $raw ) {
		$parts = explode( '|', (string) $raw );
		if ( 5 === count( $parts ) ) {
			$sig = array_pop( $parts );
			if ( ! hash_equals( substr( hash_hmac( 'sha256', implode( '|', $parts ), wp_salt( 'auth' ) . 'wpac_ref' ), 0, 16 ), (string) $sig ) ) {
				return null;
			}
		} else {
			$since = (int) get_option( 'wpac_cookie_signed_since' );
			if ( ! $since ) {
				$since = time();
				update_option( 'wpac_cookie_signed_since', $since, false );
			}
			if ( time() > $since + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'cookie_days' ) ) ) {
				return null;
			}
		}
		$touch = array(
			'code'       => $parts[0],
			'campaign'   => isset( $parts[1] ) ? substr( rawurldecode( $parts[1] ), 0, 100 ) : '',
			'content_id' => isset( $parts[2] ) ? (int) $parts[2] : 0,
			'ts'         => isset( $parts[3] ) ? (int) $parts[3] : 0,
			'method'     => 'cookie',
		);
		// Server-side attribution window (the cookie lifetime alone is client-controlled).
		if ( $touch['ts'] && $touch['ts'] < time() - DAY_IN_SECONDS * max( 1, (int) Settings::get( 'cookie_days' ) ) ) {
			return null;
		}
		return '' !== $touch['code'] ? $touch : null;
	}

	/**
	 * Current touch from session (method "link") or consented cookie (method "cookie").
	 *
	 * @return array|null code, campaign, content_id, method
	 */
	public static function current_touch() {
		$session = null;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$touch = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $touch ) && ! empty( $touch['code'] ) && ( empty( $touch['via_consent'] ) || Consent::cookie_allowed() ) ) {
				$touch['method'] = 'link';
				$session         = $touch;
			}
		}
		$cookie = null;
		if ( Consent::cookie_allowed() && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$cookie = self::parse_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		}
		if ( $session && $cookie && 'first' === Settings::get( 'touch_model' ) ) {
			// First touch: the older referral wins (a new shop session must not override it).
			$s_ts = isset( $session['ts'] ) ? (int) $session['ts'] : 0;
			return $cookie['ts'] && ( ! $s_ts || $cookie['ts'] < $s_ts ) ? $cookie : $session;
		}
		return $session ? $session : $cookie;
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
