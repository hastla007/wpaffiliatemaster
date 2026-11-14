<?php
/**
 * Partner landing pages: /partner/{slug}/ redirects to the partner's landing
 * page (or the default one) with the partner code, so the visit is captured.
 * [wpac_partner_welcome] shows "recommended by …" and the operator-maintained
 * welcome message on that page.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Promotion;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Landing {

	const QUERY_VAR = 'wpac_landing';

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'request', array( __CLASS__, 'fallback_to_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
		add_shortcode( 'wpac_partner_welcome', array( __CLASS__, 'welcome' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_welcome' ), 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		// Browsers must not prefetch/prerender /partner/slug/ (Speculation Rules, WP 6.8+): a
		// prefetch is no click. Links with ?partner=… have a query string and are excluded by core.
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'speculation_exclude' ) );
	}

	/**
	 * Exclude landing URLs from speculative loading.
	 *
	 * @param array $paths Path patterns.
	 * @return array
	 */
	public static function speculation_exclude( $paths ) {
		$paths   = (array) $paths;
		$paths[] = '/' . trim( (string) Settings::get( 'landing_prefix' ), '/' ) . '/*';
		return $paths;
	}

	/** @var array<string,object|null> Partners by slug in this request. */
	private static $by_slug = array();

	/**
	 * Partner of a landing slug (looked up once per request: request filter and redirect
	 * both need it).
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	private static function affiliate_for_slug( $slug ) {
		$key = sanitize_title( (string) $slug );
		if ( ! array_key_exists( $key, self::$by_slug ) ) {
			self::$by_slug[ $key ] = AffiliateRepository::find_by_slug( $key );
		}
		return self::$by_slug[ $key ];
	}

	/**
	 * The personal welcome box differs per visitor: the page must not go into a page,
	 * edge or Varnish cache. Shortcodes run after the headers were sent, so this is
	 * decided before any output (DONOTCACHEPAGE plus no-cache headers).
	 */
	public static function no_cache_welcome() {
		if ( self::current_post_has( 'wpac_partner_welcome', 'wpac/partner-welcome' ) ) {
			self::no_cache();
		}
	}

	/**
	 * Whether the queried post contains a shortcode or block (in its content).
	 *
	 * @param string $shortcode Shortcode tag.
	 * @param string $block     Block name.
	 * @return bool
	 */
	public static function current_post_has( $shortcode, $block ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post( get_queried_object_id() );
		if ( ! $post ) {
			return false;
		}
		$content = (string) $post->post_content;
		return has_shortcode( $content, $shortcode ) || ( function_exists( 'has_block' ) && has_block( $block, $content ) );
	}

	/**
	 * Mark the current response as not cacheable (page caches honour DONOTCACHEPAGE,
	 * proxies/CDNs the Cache-Control headers).
	 */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Rewrite rule for /{prefix}/{slug}/.
	 */
	public static function rewrite() {
		$prefix = preg_quote( (string) Settings::get( 'landing_prefix' ), '#' );
		add_rewrite_rule( '^' . $prefix . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flush rewrite rules once after activation/prefix change.
	 */
	public static function maybe_flush() {
		$key = 'v' . \WPAffiliateCaptain\Core\Installer::db_version() . ':' . Settings::get( 'landing_prefix' );
		if ( get_option( 'wpac_rewrite_flushed' ) !== $key ) {
			flush_rewrite_rules( false );
			update_option( 'wpac_rewrite_flushed', $key, true );
		}
	}

	/**
	 * Real pages win: if /{prefix}/{slug}/ is not an active partner, resolve the
	 * URL as a normal page (e.g. a page "partner" with a child page "bewerbung").
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function fallback_to_page( $vars ) {
		if ( empty( $vars[ self::QUERY_VAR ] ) ) {
			return $vars;
		}
		$aff = self::affiliate_for_slug( (string) $vars[ self::QUERY_VAR ] );
		if ( $aff && 'active' === $aff->status ) {
			return $vars;
		}
		return array( 'pagename' => Settings::get( 'landing_prefix' ) . '/' . sanitize_title( (string) $vars[ self::QUERY_VAR ] ) );
	}

	/**
	 * Force rewrite rules to be rebuilt (activation/deactivation).
	 */
	public static function reset_rewrite() {
		delete_option( 'wpac_rewrite_flushed' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Public landing URL of a partner.
	 *
	 * @param object $aff Affiliate.
	 * @return string
	 */
	public static function url( $aff ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( Settings::get( 'landing_prefix' ) . '/' . $aff->slug ) );
		}
		return add_query_arg( self::QUERY_VAR, $aff->slug, home_url( '/' ) );
	}

	/**
	 * Redirect /partner/{slug}/ to the landing page with the partner code.
	 */
	public static function redirect() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug && isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $slug ) {
			return;
		}
		$aff = self::affiliate_for_slug( $slug );
		if ( ! $aff || 'active' !== $aff->status ) {
			// Handled as a normal page / 404 (see fallback_to_page()). A 404 must not be cached by a
			// proxy/CDN: the partner may be approved a minute later.
			if ( function_exists( 'is_404' ) && is_404() && ! headers_sent() ) {
				nocache_headers();
			}
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = self::target_url( $aff, wp_unslash( $_GET ) );
		// The redirect depends on the partner's current status, code and landing page: never cached
		// by Varnish/Cloudflare (a cached 302 would keep sending visitors to an old target).
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Target of a partner's landing URL: the landing page with the partner code and the
	 * campaign parameters of the link.
	 *
	 * @param object $aff   Affiliate.
	 * @param array  $query Query parameters of the request (unslashed).
	 * @return string
	 */
	public static function target_url( $aff, array $query ) {
		$code = CodeRepository::main_code( (int) $aff->id );
		$page = (int) $aff->landing_page_id ? (int) $aff->landing_page_id : (int) Settings::get( 'landing_page_id' );
		// The landing page in the visitor's current language (WPML/Polylang).
		$url = $page ? \WPAffiliateCaptain\Core\Locale::page_url( $page ) : home_url( '/' );
		if ( $code ) {
			// The code is passed on: a slug could equal another partner's code (codes win when
			// resolving), and a slug resolves to any valid code of the partner, not the main one.
			$args = array( (string) Settings::get( 'referral_param' ) => $code );
			// Keep campaign parameters from the partner's link (placeholders of edge caches dropped).
			foreach ( array( 'utm_campaign', 'utm_source', 'utm_medium' ) as $k ) {
				if ( isset( $query[ $k ] ) && is_string( $query[ $k ] ) ) {
					$value = Tracker::clean_utm( $query[ $k ] );
					if ( '' !== $value ) {
						$args[ $k ] = $value;
					}
				}
			}
			$sub = isset( $query[ Tracker::sub_param() ] ) ? Tracker::clean_sub( $query[ Tracker::sub_param() ] ) : '';
			if ( '' !== $sub ) {
				$args[ Tracker::sub_param() ] = $sub;
			}
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	/** @var array<string,bool> Rate-limit decision per URL code in this request. */
	private static $url_code_allowed = array();

	/**
	 * [wpac_partner_welcome text="Empfohlen von %s"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function welcome( $atts = array() ) {
		/* translators: %s: partner name */
		$atts  = shortcode_atts( array( 'text' => __( 'Recommended by %s', 'affiliate-captain' ) ), (array) $atts, 'wpac_partner_welcome' );
		// Visitor-specific output must not end up in a page cache (also when the box stays
		// empty: the cached copy would be served to visitors with a partner link).
		self::no_cache();
		$param = (string) Settings::get( 'referral_param' );
		$code  = '';
		if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_text_field( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// A code from the URL is checked against the code-check limit (once per request),
			// so the box cannot be used to test codes and reveal partner names.
			if ( '' !== $code && ! isset( self::$url_code_allowed[ $code ] ) ) {
				self::$url_code_allowed[ $code ] = \WPAffiliateCaptain\Tracking\RateLimiter::allow( 'code_check', 20, MINUTE_IN_SECONDS );
			}
			if ( '' !== $code && ! self::$url_code_allowed[ $code ] ) {
				return '';
			}
		} else {
			$touch = Tracker::current_touch();
			$code  = $touch ? (string) $touch['code'] : '';
		}
		$resolved = $code ? wpac_resolve_partner_code( $code, array( 'context' => 'welcome' ) ) : null;
		if ( ! $resolved ) {
			return '';
		}
		$aff = AffiliateRepository::find( (int) $resolved['affiliate_id'] );
		if ( ! $aff ) {
			return '';
		}
		$name = self::display_name( (string) $aff->name );
		if ( '' === $name && ! $aff->landing_message ) {
			return '';
		}
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		\WPAffiliateCaptain\Core\Locale::enqueue_rtl( 'wpac-portal', 'portal' ); // R6-MS11.
		$out = '<div class="wpac-welcome">';
		if ( '' !== $name ) {
			// No sprintf(): editor text may contain "%" (e.g. "10% Rabatt") – only %s is replaced.
			$text = (string) $atts['text'];
			$out .= '<strong>' . ( false !== strpos( $text, '%s' ) ? str_replace( '%s', esc_html( $name ), esc_html( $text ) ) : esc_html( $text . ' ' . $name ) ) . '</strong>';
		}
		if ( $aff->landing_message ) {
			$out .= '<p>' . nl2br( esc_html( $aff->landing_message ) ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Partner name as shown on shop pages. The name comes from the application form and is not
	 * reviewed when applications are approved automatically: only a plain name is shown (letters,
	 * spaces and . ' & -, at most 60 characters and 6 words, no numbers, addresses or links), so
	 * the box cannot be used for messages like "Support – pay only by bank transfer to …".
	 *
	 * @param string $name Partner name.
	 * @return string '' when the name is not shown.
	 */
	public static function display_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		$ok   = '' !== $name
			&& (bool) preg_match( "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’&\\-]{0,59}$/u", $name )
			&& ! preg_match( '/\.\p{L}/u', $name ) // No domains ("www.example.com").
			&& count( explode( ' ', $name ) ) <= 6;
		/**
		 * Filter the partner name shown in the welcome box ('' = no name line).
		 *
		 * @param string $shown Name or ''.
		 * @param string $name  Stored name.
		 */
		return (string) apply_filters( 'wpac_welcome_name', $ok ? $name : '', $name );
	}
}
