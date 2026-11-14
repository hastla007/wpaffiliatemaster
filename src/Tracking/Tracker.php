<?php
/**
 * Referral capture: ?partner=CODE (parameter configurable).
 *
 * - Records a minimized visit (no IP, the referrer host only with consent).
 * - Keeps the code in an existing WooCommerce session (shop/cart context, no marketing cookie).
 * - Sets the affiliate cookie only with marketing consent (or when configured to "always"),
 *   also when the consent is given after the landing (consent listener / beacon) or when the
 *   first product is put into the cart.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	/** Base name of the referral cookie (see cookie_name()). */
	const COOKIE      = 'wpac_ref';
	const SESSION_KEY = 'wpac_ref';

	/** @var bool Whether this request already captured a referral. */
	private static $captured_now = false;

	/** @var array Cookies written in this request (name, value, options) – for diagnostics and tests. */
	private static $written = array();

	public static function register_hooks() {
		// After redirect_canonical() (priority 10): a canonical redirect (trailing slash, www.)
		// keeps the parameter, so the visit is captured once, on the final host.
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 11 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_on_add_to_cart' ), 5 );
		add_action( 'init', array( __CLASS__, 'drop_cookie_without_consent' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_beacon' ) );
		add_filter( 'wp_consent_api_registered_' . plugin_basename( WPAC_FILE ), '__return_true' );
		// Expired rate-limit counters are removed every hour (not once a day).
		add_action( 'wpac_hourly', array( RateLimiter::class, 'purge' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_status_tests' ) );
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
		if ( self::beacon_only() ) {
			// The beacon records the referral from the browser (a prerendered page only once it is
			// shown): the page stays cacheable and no visitor's cookie goes into a page cache.
			return;
		}
		if ( self::is_speculative( $_SERVER ) ) {
			// Prefetch/prerender is not a click: decline it, so the browser loads the page again
			// when the visitor really follows the link (then it is captured).
			self::decline_speculative();
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
			isset( $_GET['utm_campaign'] ) ? self::clean_utm( wp_unslash( $_GET['utm_campaign'] ) ) : '',
			is_singular() ? (int) get_queried_object_id() : 0,
			array(
				'utm_source' => isset( $_GET['utm_source'] ) ? self::clean_utm( wp_unslash( $_GET['utm_source'] ) ) : '',
				'utm_medium' => isset( $_GET['utm_medium'] ) ? self::clean_utm( wp_unslash( $_GET['utm_medium'] ) ) : '',
			)
		);
		// phpcs:enable
	}

	/**
	 * Whether page requests leave the capture to the beacon (JavaScript tracking on): only for
	 * guests – pages of logged-in users are not served from a page cache, they are captured
	 * on the server.
	 *
	 * @return bool
	 */
	private static function beacon_only() {
		$only = Settings::get( 'js_tracking' ) && ! ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() );
		/**
		 * Whether a page request with the referral parameter is left to the beacon (and stays cacheable).
		 *
		 * @param bool $only Default: JavaScript tracking is on and the visitor is not logged in.
		 */
		return (bool) apply_filters( 'wpac_beacon_only', $only );
	}

	/**
	 * Whether the request is a prefetch or prerender (Speculation Rules, <link rel="prefetch">,
	 * Firefox X-Moz): not a click of the visitor.
	 *
	 * @param array $server $_SERVER.
	 * @return bool
	 */
	public static function is_speculative( array $server ) {
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $k ) {
			if ( isset( $server[ $k ] ) && preg_match( '/prefetch|prerender|preview/i', (string) $server[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Answer a prefetch with 503 + no-store: browsers drop such a prefetch and request the page
	 * again on the real navigation.
	 */
	private static function decline_speculative() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		status_header( 503 );
		header( 'Retry-After: 0' );
		exit;
	}

	/**
	 * Clean a UTM value: some hosts' edge caches replace campaign parameters with a placeholder
	 * (e.g. Pantheon "PANTHEON_STRIPPED"); such values are not a campaign.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_utm( $value ) {
		$value = mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
		if ( preg_match( '/^[A-Z0-9_]*_STRIPPED$/i', $value ) ) {
			$value = '';
		}
		/**
		 * Filter a UTM value from a partner link.
		 *
		 * @param string $value Cleaned value.
		 */
		return (string) apply_filters( 'wpac_utm_value', $value );
	}

	/**
	 * Record a referral (from the page request, the add-to-cart request or the JavaScript
	 * beacon). A visit of the same client (address + browser) and code is counted once per
	 * minute, so page request, canonical redirect, reload and beacon never count twice.
	 *
	 * @param string $raw        Code or slug.
	 * @param string $campaign   utm_campaign.
	 * @param int    $content_id Landing content.
	 * @param array  $extra      utm_source, utm_medium, path, referrer (beacon); count (false: store
	 *                           only, e.g. after a consent given later); cart (true: a cart is being
	 *                           created, the shop session may be used).
	 * @return bool Whether the code resolved.
	 */
	public static function capture_code( $raw, $campaign, $content_id, array $extra = array() ) {
		$resolved = wpac_resolve_partner_code( (string) $raw, array( 'context' => ! empty( $extra['cart'] ) ? 'cart' : 'link' ) );
		if ( ! $resolved ) {
			return false;
		}
		$touch = array(
			'code'       => $resolved['code'],
			'campaign'   => mb_substr( (string) $campaign, 0, 100 ),
			'content_id' => (int) $content_id,
			'ts'         => time(),
		);
		// Checked after the code resolved, so unknown codes cause no counter writes. The limit only
		// throttles visit statistics – the referral itself is always kept.
		if ( ( ! isset( $extra['count'] ) || $extra['count'] ) && self::count_visit( $resolved ) ) {
			$touch['visit_id'] = self::record_visit( $resolved, $touch, $extra );
		}

		self::store_touch( $touch, ! empty( $extra['cart'] ) );
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

	/**
	 * Whether this referral is counted as a visit: no bot, not the partner's or an admin's own
	 * click, not a repeat of the same client within a minute, within the per-client limit.
	 *
	 * @param array $resolved Resolved code.
	 * @return bool
	 */
	private static function count_visit( array $resolved ) {
		$count = ! self::is_bot() && ! self::own_click( $resolved );
		if ( $count ) {
			$client = RateLimiter::client_key();
			$count  = RateLimiter::first( 'visit_seen', $client . '|' . $resolved['code'], MINUTE_IN_SECONDS )
				&& RateLimiter::allow_for( 'visit', $client, (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS );
		}
		/**
		 * Whether a referral is counted as a visit (statistics only; the referral is kept anyway).
		 *
		 * @param bool  $count    Decision.
		 * @param array $resolved affiliate_id, code_id, code.
		 */
		return (bool) apply_filters( 'wpac_count_visit', $count, $resolved );
	}

	/**
	 * Whether a logged-in program manager or the partner of the code follows the link.
	 *
	 * @param array $resolved Resolved code.
	 * @return bool
	 */
	private static function own_click( array $resolved ) {
		$user = self::visitor_user_id();
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, Capabilities::MANAGE_PROGRAM ) ) {
			return true;
		}
		$aff = AffiliateRepository::find_by_user( $user );
		return $aff && (int) $aff->id === (int) $resolved['affiliate_id'];
	}

	/**
	 * Logged-in user of the request; in REST requests without nonce (beacon) from the login
	 * cookie, which WordPress itself does not evaluate there.
	 *
	 * @return int
	 */
	public static function visitor_user_id() {
		$user = (int) get_current_user_id();
		if ( ! $user && function_exists( 'wp_validate_auth_cookie' ) && defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$user = (int) wp_validate_auth_cookie( '', 'logged_in' );
		}
		return $user;
	}

	/**
	 * Whether the browser sends a WordPress login cookie.
	 *
	 * @return bool
	 */
	public static function has_login_cookie() {
		return defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/**
	 * Beacon script: reports the referral parameter of a cached page via REST (JavaScript
	 * tracking on) and, with cookie mode "consent", sends the referral again once the visitor
	 * gives marketing consent after the landing (WP Consent API event
	 * `wp_listen_for_consent_change`, or `window.wpacConsentGranted()` for other banners).
	 *
	 * The script itself is the same on every page and request (only settings change it),
	 * so JS combiners do not create a new bundle per request. The content ID is printed as a
	 * non-executable JSON block that combiners and "delay JS" features leave alone.
	 */
	public static function enqueue_beacon() {
		$beacon  = (bool) Settings::get( 'js_tracking' );
		$consent = 'consent' === Settings::get( 'cookie_mode' );
		$param   = (string) Settings::get( 'referral_param' );
		if ( ! $beacon ) {
			// Without the beacon only the consent listener is needed, and only on a landing that was
			// just captured without consent.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $consent || empty( $_GET[ $param ] ) || ! self::$captured_now || Consent::cookie_allowed() ) {
				return;
			}
		}
		$config = array(
			'url'     => esc_url_raw( rest_url( 'wpac/v1/touch' ) ),
			'param'   => $param,
			// The previous page is read from the browser only with marketing consent (or "always").
			'ref'     => 'always' === Settings::get( 'cookie_mode' ) ? 1 : 0,
			'beacon'  => $beacon ? 1 : 0,
			'consent' => $consent ? 1 : 0,
		);
		wp_register_script( 'wpac-beacon', '', array(), WPAC_VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( 'wpac-beacon' );
		wp_add_inline_script( 'wpac-beacon', self::beacon_script( $config ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_beacon_data' ), 5 );
	}

	/**
	 * Static beacon script for a configuration. Every page view reports (the server counts
	 * a visit once per client and minute); a prerendered page reports when it is shown.
	 *
	 * @param array $config url, param, ref, beacon, consent.
	 * @return string
	 */
	public static function beacon_script( array $config ) {
		return 'window.wpacBeacon=' . wp_json_encode( $config ) . ';(function(c){try{var q=new URLSearchParams(location.search),v=q.get(c.param);if(!v){return;}var d={},e=document.getElementById("wpac-beacon-data"),done=0;if(e){try{d=JSON.parse(e.textContent)||{};}catch(x){}}'
			. 'function has(){return typeof window.wp_has_consent==="function"&&window.wp_has_consent("marketing");}'
			. 'function r(){try{if(!document.referrer){return"";}if(!c.ref&&!has()){return"";}return new URL(document.referrer).host;}catch(x){return"";}}'
			. 'function send(k){var b={code:v,campaign:q.get("utm_campaign")||"",utm_source:q.get("utm_source")||"",utm_medium:q.get("utm_medium")||"",content_id:d.content||0,path:location.pathname,referrer:r()};if(k){b.consent=1;}try{fetch(c.url,{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/json"},body:JSON.stringify(b)});}catch(x){}}'
			. 'function granted(){if(done){return;}done=1;send(1);}window.wpacConsentGranted=granted;'
			. 'function go(){if(c.beacon!==0){if(has()){done=1;}send(0);}else if(has()){granted();}}'
			. 'if(c.consent){document.addEventListener("wp_listen_for_consent_change",function(ev){var x=ev&&ev.detail||{};if(x.marketing==="allow"){granted();}});}'
			. 'if(document.prerendering){document.addEventListener("prerenderingchange",go,{once:true});}else{go();}'
			. '}catch(e){}})(window.wpacBeacon);';
	}

	/**
	 * Per-page beacon data (before the footer scripts): the content ID.
	 */
	public static function print_beacon_data() {
		$data = self::beacon_data( is_singular() ? (int) get_queried_object_id() : 0, false, time() );
		echo '<script type="application/json" id="wpac-beacon-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and & hex-encoded.
	}

	/**
	 * Per-page beacon data. "cap"/"gen" are no longer used by the script (a cached copy carries
	 * them as well); kept for compatibility of the data format.
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
	 * Store the touch in an existing WC session and (with consent) the cookie, honoring
	 * first/last touch. A click alone never creates a shop session (no session row per click,
	 * no cache bypass): without a cart the consented cookie carries the referral.
	 *
	 * @param array $touch Touch.
	 * @param bool  $cart  A cart is being created right now (the shop session exists anyway).
	 */
	public static function store_touch( array $touch, $cart = false ) {
		$first = 'first' === Settings::get( 'touch_model' );

		if ( Settings::get( 'use_wc_session' ) && function_exists( 'WC' ) && WC()->session && ( $cart || WC()->session->has_session() ) ) {
			$existing = WC()->session->get( self::SESSION_KEY );
			// A session that older versions created only because of the consent stays bound to it:
			// every later touch in it is ignored once the consent is withdrawn.
			if ( is_array( $existing ) && ! empty( $existing['via_consent'] ) ) {
				$touch['via_consent'] = 1;
			}
			// First touch keeps the earlier referral only while it is still valid (signature, window,
			// code and partner active) – an invalid one is replaced.
			if ( ! $first || ! self::touch_valid( $existing ) ) {
				WC()->session->set( self::SESSION_KEY, $touch );
			}
		}

		if ( Consent::cookie_allowed() ) {
			if ( ! $first || ! self::touch_valid( self::read_cookie() ) ) {
				self::write_cookie(
					self::sign( $touch['code'] . '|' . rawurlencode( $touch['campaign'] ) . '|' . (int) $touch['content_id'] . '|' . (int) $touch['ts'] ),
					time() + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'cookie_days' ) )
				);
			}
		}
	}

	/**
	 * Whether a stored touch can still be attributed: inside the attribution window and its
	 * code resolves to an active partner.
	 *
	 * @param mixed $touch Touch.
	 * @return bool
	 */
	public static function touch_valid( $touch ) {
		if ( ! is_array( $touch ) || empty( $touch['code'] ) ) {
			return false;
		}
		$ts = isset( $touch['ts'] ) ? (int) $touch['ts'] : 0;
		if ( $ts && $ts < time() - DAY_IN_SECONDS * max( 1, (int) Settings::get( 'cookie_days' ) ) ) {
			return false;
		}
		return (bool) wpac_resolve_partner_code( (string) $touch['code'], array( 'context' => 'touch' ) );
	}

	/**
	 * Cookie-free fallback when a product is added to the cart (the shop session exists now
	 * anyway): a partner link in the add-to-cart URL itself (e.g. ?add-to-cart=12&partner=X,
	 * which WooCommerce redirects before the page would capture it) is captured completely; else
	 * the code is taken from the referring page of this shop. With consent the cookie is set too.
	 */
	public static function capture_on_add_to_cart() {
		$param = (string) Settings::get( 'referral_param' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ $param ] ) ) {
			if ( ! self::is_speculative( $_SERVER ) ) {
				self::capture_code(
					sanitize_text_field( wp_unslash( $_GET[ $param ] ) ),
					isset( $_GET['utm_campaign'] ) ? self::clean_utm( wp_unslash( $_GET['utm_campaign'] ) ) : '',
					0,
					array(
						'utm_source' => isset( $_GET['utm_source'] ) ? self::clean_utm( wp_unslash( $_GET['utm_source'] ) ) : '',
						'utm_medium' => isset( $_GET['utm_medium'] ) ? self::clean_utm( wp_unslash( $_GET['utm_medium'] ) ) : '',
						'cart'       => true,
					)
				);
			}
			return;
		}
		if ( empty( $_SERVER['HTTP_REFERER'] ) || self::current_touch() ) {
			return;
		}
		$ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		if ( ! self::is_own_host( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) ) {
			return;
		}
		parse_str( (string) wp_parse_url( $ref, PHP_URL_QUERY ), $query );
		$code = isset( $query[ $param ] ) ? sanitize_text_field( (string) $query[ $param ] ) : '';
		// phpcs:enable
		$resolved = '' !== $code ? wpac_resolve_partner_code( $code, array( 'context' => 'cart' ) ) : null;
		if ( ! $resolved ) {
			return;
		}
		// The visit was counted on the referring page; here only the referral is kept.
		self::store_touch(
			array(
				'code'       => $resolved['code'],
				'campaign'   => isset( $query['utm_campaign'] ) ? self::clean_utm( (string) $query['utm_campaign'] ) : '',
				// The referring page is the content the visitor came through (same as a direct link visit).
				'content_id' => (int) url_to_postid( $ref ),
				'ts'         => time(),
			),
			true
		);
	}

	/**
	 * Whether a host belongs to this shop: the home host, or a host inside the configured
	 * cookie domain (landing on www.brand.com, shop on shop.brand.com).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_own_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return false;
		}
		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return true;
		}
		$domain = ltrim( strtolower( (string) self::cookie_domain() ), '.' );
		return '' !== $domain && ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain );
	}

	/**
	 * Remove the affiliate cookie when consent is missing or was withdrawn.
	 */
	public static function drop_cookie_without_consent() {
		if ( Consent::cookie_allowed() ) {
			return;
		}
		if ( ! empty( $_COOKIE[ self::cookie_name() ] ) ) {
			self::write_cookie( '', time() - 365 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Name of the referral cookie: "wpac_ref", in a multisite network "wpac_ref_{blog ID}" (a
	 * cookie of one site must not count on another). Filter `wpac_cookie_name`, e.g. for hosts
	 * that only pass allow-listed cookie names to WordPress (Pantheon: "STYXKEY_wpac_ref").
	 *
	 * @return string
	 */
	public static function cookie_name() {
		$name = is_multisite() ? self::COOKIE . '_' . get_current_blog_id() : self::COOKIE;
		/**
		 * Filter the name of the referral cookie.
		 *
		 * @param string $name Name.
		 */
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) apply_filters( 'wpac_cookie_name', $name ) );
		return '' !== $name ? $name : self::COOKIE;
	}

	/**
	 * Cookie domain: the setting "cookie_domain" (e.g. ".brand.com" for landing and shop on
	 * different subdomains) or WordPress' COOKIE_DOMAIN. Filter `wpac_cookie_domain`.
	 *
	 * @return string
	 */
	public static function cookie_domain() {
		$domain = (string) Settings::get( 'cookie_domain' );
		if ( '' === $domain ) {
			$domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
		}
		/**
		 * Filter the domain of the referral cookie ('' = current host only).
		 *
		 * @param string $domain Domain.
		 */
		return (string) apply_filters( 'wpac_cookie_domain', $domain );
	}

	/**
	 * Cookie options. Path of this site's home URL (a multisite subdirectory site does not
	 * share it with the network); Secure whenever the site runs on HTTPS, also behind a TLS
	 * proxy that WordPress does not detect (is_ssl() false).
	 *
	 * @param int $expires Expiry.
	 * @return array
	 */
	public static function cookie_options( $expires ) {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return array(
			'expires'  => (int) $expires,
			'path'     => '' !== $path ? trailingslashit( $path ) : '/',
			'domain'   => self::cookie_domain(),
			'secure'   => self::secure_for( home_url(), function_exists( 'is_ssl' ) && is_ssl() ),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	/**
	 * Whether the cookie gets the Secure flag: the request is HTTPS or the site's home URL is.
	 *
	 * @param string $home   Home URL.
	 * @param bool   $is_ssl is_ssl() of the request.
	 * @return bool
	 */
	public static function secure_for( $home, $is_ssl ) {
		return (bool) $is_ssl || 'https' === strtolower( (string) wp_parse_url( (string) $home, PHP_URL_SCHEME ) );
	}

	/**
	 * Set (or with an expiry in the past delete) the referral cookie, with the same attributes.
	 *
	 * @param string $value   Value ('' to delete).
	 * @param int    $expires Expiry.
	 */
	private static function write_cookie( $value, $expires ) {
		$name    = self::cookie_name();
		$options = self::cookie_options( $expires );
		self::$written[] = array( $name, (string) $value, $options );
		if ( '' === (string) $value ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = (string) $value; // Later code of this request sees the new referral.
		}
		if ( ! headers_sent() ) {
			setcookie( $name, (string) $value, $options );
		}
	}

	/**
	 * Cookies written in this request (diagnostics and tests).
	 *
	 * @return array List of [ name, value, options ].
	 */
	public static function written_cookies() {
		return self::$written;
	}

	/**
	 * Key for the cookie signature. In a multisite network it includes the blog ID: the auth
	 * salt is shared by all sites.
	 *
	 * @return string
	 */
	private static function sign_key() {
		return wp_salt( 'auth' ) . 'wpac_ref' . ( is_multisite() ? '|' . get_current_blog_id() : '' );
	}

	/**
	 * Sign a cookie value (HMAC), so code, campaign, content and the click time
	 * cannot be forged (fraud checks and the attribution window rely on them).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sign( $value ) {
		return $value . '|' . substr( hash_hmac( 'sha256', $value, self::sign_key() ), 0, 16 );
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
		if ( ! hash_equals( substr( hash_hmac( 'sha256', implode( '|', $parts ), self::sign_key() ), 0, 16 ), (string) $sig ) ) {
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
	 * The verified referral cookie of the request (without the consent check).
	 *
	 * @return array|null
	 */
	private static function read_cookie() {
		$name = self::cookie_name();
		return empty( $_COOKIE[ $name ] ) ? null : self::parse_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
	}

	/**
	 * Current touch from session (method "link") or consented cookie (method "cookie").
	 * Only touches that can still be attributed take part (window, code and partner active),
	 * so an expired or deactivated earlier referral never hides a valid one.
	 *
	 * @return array|null code, campaign, content_id, method
	 */
	public static function current_touch() {
		$session = null;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$touch = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $touch ) && ! empty( $touch['code'] ) && ( empty( $touch['via_consent'] ) || Consent::cookie_allowed() ) && self::touch_valid( $touch ) ) {
				$touch['method'] = 'link';
				$session         = $touch;
			}
		}
		$cookie = Consent::cookie_allowed() ? self::read_cookie() : null;
		if ( $cookie && ! self::touch_valid( $cookie ) ) {
			$cookie = null;
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
	 * Clear the session touch only once the order is paid (payment complete, or the "order
	 * received" page of an order that did not fail): an aborted payment keeps the referral
	 * for the next attempt, which is a new order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function clear_touch_for_order( $order_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->has_status( array( 'failed', 'pending', 'cancelled' ) ) ) {
			return;
		}
		self::clear_touch();
	}

	/**
	 * Insert a minimized visit row (or not: setting "visit_log_percent" / filter
	 * `wpac_record_visit`; the referral itself is kept either way).
	 *
	 * @param array $resolved Resolved code.
	 * @param array $touch    Touch.
	 * @param array $extra    utm_source, utm_medium, path, referrer.
	 * @return int Visit ID (0 = not recorded).
	 */
	private static function record_visit( array $resolved, array $touch, array $extra = array() ) {
		global $wpdb;
		$percent = (int) Settings::get( 'visit_log_percent' );
		$log     = $percent >= 100 || ( $percent > 0 && wp_rand( 1, 100 ) <= $percent );
		/**
		 * Whether this visit is written to the visit log (statistics).
		 *
		 * @param bool  $log      Decision (sampling setting).
		 * @param array $resolved affiliate_id, code_id, code.
		 * @param array $touch    Touch.
		 */
		if ( ! apply_filters( 'wpac_record_visit', $log, $resolved, $touch ) ) {
			return 0;
		}
		// The previous page's host only with marketing consent (or cookie mode "always").
		$ref_host = '';
		if ( Consent::cookie_allowed() ) {
			$referrer = isset( $extra['referrer'] ) ? (string) $extra['referrer'] : ( ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' );
			if ( '' !== $referrer ) {
				$host     = wp_parse_url( $referrer, PHP_URL_HOST );
				$ref_host = $host ? mb_substr( strtolower( $host ), 0, 190 ) : '';
			}
		}
		$path = isset( $extra['path'] ) ? (string) $extra['path'] : ( isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '' );
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
		return (int) $wpdb->insert_id;
	}

	/**
	 * Bot detection by user agent (crawlers, link previews of messengers and social networks,
	 * uptime monitors, HTTP libraries) and HEAD requests.
	 *
	 * @param array|null $server $_SERVER (default: current request).
	 * @return bool
	 */
	public static function is_bot( $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;
		if ( isset( $server['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $server['REQUEST_METHOD'] ) ) {
			return true;
		}
		$ua = isset( $server['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $server['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		// Phones of the brand CUBOT are browsers ("bot" is part of the brand name).
		$ua      = str_replace( 'cubot', 'cu-phone', $ua );
		$needles = array(
			'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview', 'curl', 'wget', 'python-requests', 'headless', 'lighthouse',
			'whatsapp', 'viber', 'mastodon', 'iframely', 'pinterest/0.', 'microsoft outlook', 'ms-office', 'googleother', 'feedfetcher-google',
			'statuscake', 'uptime-kuma', 'site24x7', 'pingdom', 'guzzlehttp', 'go-http-client', 'okhttp', 'axios', 'node-fetch', 'java/',
			'apache-httpclient', 'scrapy', 'wordpress/', 'httpclient', 'libwww', 'python-urllib', 'aiohttp', 'monitor',
		);
		foreach ( apply_filters( 'wpac_bot_user_agents', $needles ) as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( $ua, (string) $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Site Health: tracking setup (proxy, cookie forwarding, object cache).
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_status_tests( $tests ) {
		$tests['direct']['wpac_tracking'] = array(
			'label' => __( 'Partner links (Affiliate Captain)', 'affiliate-captain' ),
			'test'  => array( __CLASS__, 'site_health_tracking' ),
		);
		return $tests;
	}

	/**
	 * Site Health result for the tracking setup.
	 *
	 * @return array
	 */
	public static function site_health_tracking() {
		$problems = self::tracking_problems();
		$list     = '';
		foreach ( $problems as $p ) {
			$list .= '<li><strong>' . esc_html( $p[0] ) . '</strong> – ' . esc_html( $p[1] ) . '</li>';
		}
		return array(
			'label'       => $problems ? __( 'Partner link tracking needs attention', 'affiliate-captain' ) : __( 'Partner link tracking is set up', 'affiliate-captain' ),
			'status'      => $problems ? 'recommended' : 'good',
			'badge'       => array(
				'label' => __( 'Partner program', 'affiliate-captain' ),
				'color' => $problems ? 'orange' : 'blue',
			),
			'description' => $list ? '<ul>' . $list . '</ul>' : '<p>' . esc_html__( 'Client addresses, the referral cookie and the rate-limit counters work as expected.', 'affiliate-captain' ) . '</p>',
			'test'        => 'wpac_tracking',
		);
	}

	/**
	 * Tracking setup problems of this request/host.
	 *
	 * @return array List of [ title, description ].
	 */
	public static function tracking_problems() {
		$out = array();
		if ( RateLimiter::behind_untrusted_proxy() ) {
			$out[] = array(
				__( 'Proxy or CDN not configured', 'affiliate-captain' ),
				__( 'This request came through a proxy/CDN (X-Forwarded-For) that is not listed under Settings → Security → Trusted proxies. All visitors then share the proxy address: code checks and registrations hit the limits early. Add the proxy addresses, or raise single limits with the filter wpac_rate_limit.', 'affiliate-captain' ),
			);
		}
		$name = self::cookie_name();
		if ( ( getenv( 'PANTHEON_ENVIRONMENT' ) || isset( $_ENV['PANTHEON_ENVIRONMENT'] ) ) && ! preg_match( '/^(STYXKEY_|SESS|wp-|wordpress_|woocommerce_)/', $name ) ) {
			$out[] = array(
				__( 'Referral cookie filtered by the host', 'affiliate-captain' ),
				/* translators: %s: cookie name */
				sprintf( __( 'This host only passes allow-listed cookies to WordPress; "%s" never arrives. Rename it with the filter wpac_cookie_name, e.g. to STYXKEY_wpac_ref.', 'affiliate-captain' ), $name ),
			);
		}
		if ( wp_using_ext_object_cache() && ! RateLimiter::use_object_cache() ) {
			$out[] = array(
				__( 'Object cache is local to each server', 'affiliate-captain' ),
				__( 'Rate-limit counters use the database table instead (a per-server cache would multiply every limit).', 'affiliate-captain' ),
			);
		}
		return $out;
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
