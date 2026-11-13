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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_beacon' ) );
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		self::capture_code(
			sanitize_text_field( wp_unslash( $_GET[ $param ] ) ),
			isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
			is_singular() ? (int) get_queried_object_id() : 0,
			array(
				'utm_source' => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '',
				'utm_medium' => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '',
			)
		);
		// phpcs:enable
	}

	/**
	 * Record a referral (from the page request or, behind full-page caches, from the
	 * JavaScript beacon). A visit of the same client and code is counted once per minute,
	 * so page request and beacon never count twice.
	 *
	 * @param string $raw        Code or slug.
	 * @param string $campaign   utm_campaign.
	 * @param int    $content_id Landing content.
	 * @param array  $extra      utm_source, utm_medium, path, referrer (beacon).
	 * @return bool Whether the code resolved.
	 */
	public static function capture_code( $raw, $campaign, $content_id, array $extra = array() ) {
		$resolved = wpac_resolve_partner_code( (string) $raw, array( 'context' => 'link' ) );
		if ( ! $resolved ) {
			return false;
		}
		// Dedup only needed with the beacon (page request + beacon for the same visit). Kept in
		// the object cache or the rate-limit table, never as wp_options rows.
		// The limit only throttles visit statistics – the referral itself is always kept.
		// Checked after the code resolved, so unknown codes cause no counter writes.
		$count_visit = ! self::is_bot()
			&& ( ! Settings::get( 'js_tracking' ) || self::first_visit( RateLimiter::bucket_ip( RateLimiter::current_ip() ) . '|' . $resolved['code'] ) )
			&& RateLimiter::allow( 'visit', (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS );

		$touch = array(
			'code'       => $resolved['code'],
			'campaign'   => mb_substr( (string) $campaign, 0, 100 ),
			'content_id' => (int) $content_id,
			'ts'         => time(),
		);
		if ( $count_visit ) {
			$touch['visit_id'] = self::record_visit( $resolved, $touch, $extra );
		}

		self::store_touch( $touch );
		self::$captured_now = true;

		/**
		 * Fires after a referral link visit was captured.
		 *
		 * @param array $resolved affiliate_id, code_id, code.
		 * @param array $touch    Touch data.
		 */
		do_action( 'wpac_referral_captured', $resolved, $touch );
		return true;
	}

	/** @var bool Whether this request already captured a referral (the beacon can be skipped). */
	private static $captured_now = false;

	/**
	 * Whether this client/code pair was not seen in the last ~30–60 seconds. Two fixed
	 * one-minute windows shifted by 30 seconds: any two hits less than 30 seconds apart
	 * share at least one window, so page request and beacon are never counted twice.
	 *
	 * @param string $subject Client bucket and code.
	 * @return bool
	 */
	private static function first_visit( $subject ) {
		$a = RateLimiter::first( 'visit_dedup', $subject, MINUTE_IN_SECONDS );
		$b = RateLimiter::first( 'visit_dedup_shifted', $subject, MINUTE_IN_SECONDS, 30 );
		return $a && $b;
	}

	/**
	 * Optional beacon for sites behind a full-page cache/CDN that ignores DONOTCACHEPAGE:
	 * the cached page reports the referral parameter via REST. Tiny inline script (handle
	 * "wpac-beacon"), it only sends a request when the parameter is in the URL.
	 *
	 * The script itself is the same on every page and request (only settings change it),
	 * so JS combiners do not create a new bundle per request. Per-page values (content,
	 * and on freshly generated pages that captured the referral the time) are printed as
	 * a non-executable JSON block that combiners and "delay JS" features leave alone.
	 */
	public static function enqueue_beacon() {
		if ( ! Settings::get( 'js_tracking' ) ) {
			return;
		}
		$config = array(
			'url'   => esc_url_raw( rest_url( 'wpac/v1/touch' ) ),
			'param' => (string) Settings::get( 'referral_param' ),
			// The previous page is read from the browser only with marketing consent (or "always").
			'ref'   => 'always' === Settings::get( 'cookie_mode' ) ? 1 : 0,
		);
		wp_register_script( 'wpac-beacon', '', array(), WPAC_VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( 'wpac-beacon' );
		wp_add_inline_script( 'wpac-beacon', self::beacon_script( $config ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_beacon_data' ), 5 );
	}

	/**
	 * Static beacon script for a configuration.
	 *
	 * @param array $config url, param, ref.
	 * @return string
	 */
	public static function beacon_script( array $config ) {
		return 'window.wpacBeacon=' . wp_json_encode( $config ) . ';(function(c){try{var q=new URLSearchParams(location.search),v=q.get(c.param);if(!v){return;}var d={},e=document.getElementById("wpac-beacon-data");if(e){try{d=JSON.parse(e.textContent)||{};}catch(x){}}if(d.cap&&Math.abs(Date.now()/1000-d.gen)<30){return;}fetch(c.url,{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/json"},body:JSON.stringify({code:v,campaign:q.get("utm_campaign")||"",utm_source:q.get("utm_source")||"",utm_medium:q.get("utm_medium")||"",content_id:d.content||0,path:location.pathname,referrer:r(c)})});}catch(e){}function r(c){try{if(!document.referrer){return"";}if(!c.ref&&!(typeof window.wp_has_consent==="function"&&window.wp_has_consent("marketing"))){return"";}return new URL(document.referrer).host;}catch(e){return"";}}})(window.wpacBeacon);';
	}

	/**
	 * Per-page beacon data (before the footer scripts). "gen"/"cap" only when this very
	 * request captured the referral: the beacon is then not needed on this fresh page,
	 * while cached copies carry an old time and still report.
	 */
	public static function print_beacon_data() {
		$data = self::beacon_data( is_singular() ? (int) get_queried_object_id() : 0, self::$captured_now, time() );
		echo '<script type="application/json" id="wpac-beacon-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and & hex-encoded.
	}

	/**
	 * Per-page beacon data.
	 *
	 * @param int  $content  Content ID.
	 * @param bool $captured Whether this request captured the referral.
	 * @param int  $now      Time.
	 * @return array
	 */
	public static function beacon_data( $content, $captured, $now ) {
		$data = array( 'content' => (int) $content );
		if ( $captured ) {
			$data['cap'] = 1;
			$data['gen'] = (int) $now;
		}
		return $data;
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
					'campaign'   => mb_substr( $campaign, 0, 100 ),
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
	 * Parse and verify the referral cookie. Only signed cookies are accepted: unsigned
	 * ones were only set by release 0.7.0 (a few hours before signing was released) and
	 * cannot be told apart from forged ones.
	 *
	 * @param string $raw Raw value.
	 * @return array|null
	 */
	public static function parse_cookie( $raw ) {
		$parts = explode( '|', (string) $raw );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		$sig = array_pop( $parts );
		if ( ! hash_equals( substr( hash_hmac( 'sha256', implode( '|', $parts ), wp_salt( 'auth' ) . 'wpac_ref' ), 0, 16 ), (string) $sig ) ) {
			return null;
		}
		$touch = array(
			'code'       => $parts[0],
			'campaign'   => isset( $parts[1] ) ? mb_substr( rawurldecode( $parts[1] ), 0, 100 ) : '',
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
	private static function record_visit( array $resolved, array $touch, array $extra = array() ) {
		global $wpdb;
		$ref_host = '';
		$referrer = isset( $extra['referrer'] ) ? (string) $extra['referrer'] : ( ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' );
		if ( '' !== $referrer ) {
			$host     = wp_parse_url( $referrer, PHP_URL_HOST );
			$ref_host = $host ? mb_substr( strtolower( $host ), 0, 190 ) : '';
		}
		$path = isset( $extra['path'] ) ? (string) $extra['path'] : ( isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$wpdb->insert(
			Db::table( 'visits' ),
			array(
				'affiliate_id'  => (int) $resolved['affiliate_id'],
				'code_id'       => (int) $resolved['code_id'],
				'campaign'      => $touch['campaign'],
				'utm_source'    => mb_substr( isset( $extra['utm_source'] ) ? (string) $extra['utm_source'] : '', 0, 100 ),
				'utm_medium'    => mb_substr( isset( $extra['utm_medium'] ) ? (string) $extra['utm_medium'] : '', 0, 100 ),
				'referrer_host' => $ref_host,
				'landing_path'  => mb_substr( (string) $path, 0, 255 ),
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
	 * Whether a partner ever had a visit (existence check, no count over all rows).
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return bool
	 */
	public static function has_visits( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'visits' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE affiliate_id = %d LIMIT 1", $affiliate_id ) );
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
