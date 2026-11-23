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
		add_action( 'template_redirect', array( __CLASS__, 'enqueue_head_styles' ), 3 ); // Stylesheet in the head (R13-N10).
		// Block themes: the template is known only now (before any output, R12-M29).
		add_filter( 'template_include', array( __CLASS__, 'no_cache_template' ), 99 );
		// The cached template scan is renewed when templates, parts, synced patterns or the theme change.
		add_action( 'save_post', array( __CLASS__, 'maybe_flush_template_scan' ) );
		add_action( 'deleted_post', array( __CLASS__, 'maybe_flush_template_scan' ) );
		add_action( 'switch_theme', array( __CLASS__, 'flush_template_scan' ), 10, 0 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_template_scan' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		// Browsers must not prefetch/prerender /partner/slug/ (Speculation Rules, WP 6.8+): a
		// prefetch is no click. Links with ?partner=… have a query string and are excluded by core.
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'speculation_exclude' ) );
		// Pages with a partner link and the partner area are not indexed (R14-T3, R14-T8).
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/** Query parameters of the plugin's confirmation and one-time pages (registration, links in mails). */
	const NOINDEX_PARAMS = array( 'wpac_confirm', 'wpac_reg', 'wpac_err', 'wpac_email_confirm', 'wpac_unsubscribe', 'wpac_link_account' );

	/**
	 * Robots meta: "noindex, follow" for pages opened with the referral parameter (every partner
	 * code, campaign and sub-ID would be a duplicate in the index, and the welcome box would put
	 * partner names into search snippets), the partner area and the confirmation pages. Links are
	 * still followed; robots.txt stays open (a blocked page never shows its noindex).
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$robots = (array) $robots;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = (array) wp_unslash( $_GET );
		if ( self::noindex( $query, is_singular() ? (int) get_queried_object_id() : 0 ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			if ( empty( $robots['nofollow'] ) ) {
				$robots['follow'] = true;
			}
		}
		return $robots;
	}

	/**
	 * Whether a request must not be indexed (pure apart from the settings).
	 *
	 * @param array $query      Query parameters.
	 * @param int   $queried_id Queried post ID (0 = no single post or page).
	 * @return bool
	 */
	public static function noindex( array $query, $queried_id ) {
		$param = (string) Settings::get( 'referral_param' );
		$found = '' !== $param && ! empty( $query[ $param ] );
		foreach ( self::NOINDEX_PARAMS as $k ) {
			$found = $found || ! empty( $query[ $k ] );
		}
		$portal = (int) Settings::get( 'portal_page_id' );
		if ( ! $found && $portal && $queried_id ) {
			$found = in_array( (int) $queried_id, array( $portal, \WPAffiliateCaptain\Core\Locale::page_id( $portal ) ), true );
		}
		/**
		 * Whether the current page gets "noindex, follow" from the plugin.
		 *
		 * @since 0.14.0
		 * @param bool  $found      Decision: referral parameter, partner area or confirmation page.
		 * @param array $query      Query parameters.
		 * @param int   $queried_id Queried post ID.
		 */
		return (bool) apply_filters( 'wpac_noindex', $found, $query, (int) $queried_id );
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
		$found = self::current_post_has( 'wpac_partner_welcome', 'wpac/partner-welcome' ) || self::widgets_have( 'wpac_partner_welcome', 'wpac/partner-welcome' );
		/**
		 * Whether the current page shows personal content of the plugin (the welcome box) and
		 * must not be cached. The plugin checks the content of the queried post, active widgets
		 * and – in block themes – the template with its template parts; builders that keep
		 * their layout elsewhere (e.g. in post meta) can say so here.
		 *
		 * @since 0.13.0
		 * @param bool   $found     Found by the plugin's own checks.
		 * @param string $shortcode Shortcode tag.
		 * @param string $block     Block name.
		 */
		if ( apply_filters( 'wpac_needs_nocache', $found, 'wpac_partner_welcome', 'wpac/partner-welcome' ) ) {
			self::no_cache();
		}
	}

	/**
	 * Block themes: the welcome box in the template or a template part (header, sidebar) of
	 * the page. Runs at template_include, after the template was chosen and before output.
	 *
	 * @param string $template Template file.
	 * @return string Unchanged.
	 */
	public static function no_cache_template( $template ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) && self::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' ) ) {
			self::no_cache();
			self::enqueue_style(); // Before wp_head: in the head, not the footer (R13-N10).
		}
		return $template;
	}

	/** Option: generation of the cached template scans (raised when templates, parts or synced patterns change). */
	const TPL_GEN = 'wpac_tplscan_gen';

	/**
	 * Raise the generation of the cached template scans (a template, template part, synced
	 * pattern or the theme changed).
	 */
	public static function flush_template_scan() {
		update_option( self::TPL_GEN, (int) get_option( self::TPL_GEN, 0 ) + 1, true );
	}

	/**
	 * Saving or deleting a post: only templates, template parts and synced patterns matter.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_flush_template_scan( $post_id ) {
		if ( in_array( get_post_type( (int) $post_id ), array( 'wp_template', 'wp_template_part', 'wp_block' ), true ) ) {
			self::flush_template_scan();
		}
	}

	/**
	 * Whether the block template of the current request (or a template part, synced pattern or
	 * pattern in it, nested ones included) contains a shortcode or block. Only for block themes;
	 * false before template_include. The result is cached per template content (R13-N12): the
	 * template parts were loaded on every page view.
	 *
	 * @param string $shortcode Shortcode tag.
	 * @param string $block     Block name.
	 * @return bool
	 */
	public static function block_template_has( $shortcode, $block ) {
		global $_wp_current_template_content;
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! is_string( $_wp_current_template_content ) || '' === $_wp_current_template_content ) {
			return false;
		}
		$key    = 'wpac_tplscan_' . md5( implode( '|', array( get_stylesheet(), md5( $_wp_current_template_content ), $shortcode, $block, (int) get_option( self::TPL_GEN, 0 ), WPAC_VERSION ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['found'] ) ) {
			return (bool) $cached['found'];
		}
		$contents = array();
		self::collect_contents( $_wp_current_template_content, $contents, 0 );
		$found = false;
		foreach ( $contents as $content ) {
			if ( has_shortcode( $content, $shortcode ) || ( function_exists( 'has_block' ) && has_block( $block, $content ) ) ) {
				$found = true;
				break;
			}
		}
		set_transient( $key, array( 'found' => $found ), DAY_IN_SECONDS );
		return $found;
	}

	/**
	 * A block markup and the markup of the template parts, synced patterns and patterns it uses,
	 * recursively (at most 3 levels and 20 pieces).
	 *
	 * @param string   $content Block markup.
	 * @param string[] $out     Collected markup (by reference).
	 * @param int      $depth   Nesting level.
	 */
	private static function collect_contents( $content, array &$out, $depth ) {
		$out[] = (string) $content;
		// The attribute JSON is matched up to the comment end: core escapes "--" inside it, so
		// nested objects ("style":{…}) are captured whole (the old [^}]* stopped at the first }).
		if ( $depth >= 3 || ! preg_match_all( '/<!--\s+wp:(?:core\/)?(template-part|block|pattern)\s+(\{.*?\})\s*\/?-->/s', (string) $content, $m, PREG_SET_ORDER ) ) {
			return;
		}
		$seen = array();
		foreach ( $m as $match ) {
			if ( count( $out ) >= 20 ) {
				return;
			}
			$attrs = json_decode( $match[2], true );
			if ( ! is_array( $attrs ) ) {
				continue;
			}
			$inner = self::referenced_markup( $match[1], $attrs );
			if ( '' !== $inner && ! isset( $seen[ md5( $inner ) ] ) ) {
				$seen[ md5( $inner ) ] = true;
				self::collect_contents( $inner, $out, $depth + 1 );
			}
		}
	}

	/**
	 * Markup of a template part, synced pattern (core/block ref) or registered pattern.
	 *
	 * @param string $type  template-part|block|pattern.
	 * @param array  $attrs Block attributes.
	 * @return string
	 */
	private static function referenced_markup( $type, array $attrs ) {
		if ( 'template-part' === $type && ! empty( $attrs['slug'] ) && function_exists( 'get_block_template' ) ) {
			$theme = ! empty( $attrs['theme'] ) ? (string) $attrs['theme'] : get_stylesheet();
			$part  = get_block_template( $theme . '//' . sanitize_key( (string) $attrs['slug'] ), 'wp_template_part' );
			return $part && is_string( $part->content ) ? $part->content : '';
		}
		if ( 'block' === $type && ! empty( $attrs['ref'] ) ) {
			$post = get_post( (int) $attrs['ref'] );
			return $post && isset( $post->post_type, $post->post_content ) && 'wp_block' === $post->post_type ? (string) $post->post_content : '';
		}
		if ( 'pattern' === $type && ! empty( $attrs['slug'] ) && class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $attrs['slug'] );
			return is_array( $pattern ) && isset( $pattern['content'] ) ? (string) $pattern['content'] : '';
		}
		return '';
	}

	/**
	 * Whether an active widget (block, text or custom HTML widget in a used sidebar) contains
	 * a shortcode or block. Reads the widget options only (autoloaded).
	 *
	 * @param string $shortcode Shortcode tag.
	 * @param string $block     Block name.
	 * @return bool
	 */
	public static function widgets_have( $shortcode, $block ) {
		$fields = array(
			'block'       => 'content',
			'text'        => 'text',
			'custom_html' => 'content',
		);
		$stored = array();
		// The option itself (wp_get_sidebars_widgets() is a private core function, refused by Plugin Check).
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || 'array_version' === $sidebar || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( ! preg_match( '/^(block|text|custom_html)-(\d+)$/', (string) $id, $m ) ) {
					continue;
				}
				if ( ! isset( $stored[ $m[1] ] ) ) {
					$stored[ $m[1] ] = (array) get_option( 'widget_' . $m[1], array() );
				}
				$widget  = isset( $stored[ $m[1] ][ (int) $m[2] ] ) ? (array) $stored[ $m[1] ][ (int) $m[2] ] : array();
				$content = isset( $widget[ $fields[ $m[1] ] ] ) ? (string) $widget[ $fields[ $m[1] ] ] : '';
				if ( '' !== $content && ( has_shortcode( $content, $shortcode ) || ( function_exists( 'has_block' ) && has_block( $block, $content ) ) ) ) {
					return true;
				}
			}
		}
		return false;
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
	 * Classic themes: enqueue the portal stylesheet in the head when the queried post shows a
	 * block or shortcode of the plugin. Render callbacks enqueue it in the body only, where
	 * WordPress prints it in the footer: the content stood unstyled until then (R13-N10).
	 */
	public static function enqueue_head_styles() {
		foreach ( array(
			'wpac_affiliate_registration' => 'wpac/registration',
			'wpac_partner_welcome'        => 'wpac/partner-welcome',
			'wpac_partner_targets'        => 'wpac/partner-targets',
		) as $shortcode => $block ) {
			if ( self::current_post_has( $shortcode, $block ) ) {
				self::enqueue_style();
				return;
			}
		}
	}

	/**
	 * Enqueue the portal stylesheet (the blocks and shortcodes share it).
	 */
	public static function enqueue_style() {
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
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
		if ( ! \WPAffiliateCaptain\Core\Features::enabled( 'landing' ) ) {
			// Landing pages switched off (SET-AC-26): the address is an ordinary page address (or a 404).
			return array( 'pagename' => Settings::get( 'landing_prefix' ) . '/' . sanitize_title( (string) $vars[ self::QUERY_VAR ] ) );
		}
		$aff = self::affiliate_for_slug( (string) $vars[ self::QUERY_VAR ] );
		if ( $aff && 'active' === $aff->status ) {
			return $vars;
		}
		// Remembered for redirect(): the query var is gone once the URL is resolved as a page.
		self::$fallback_slug = sanitize_title( (string) $vars[ self::QUERY_VAR ] );
		return array( 'pagename' => Settings::get( 'landing_prefix' ) . '/' . self::$fallback_slug );
	}

	/** @var string Slug of a landing URL that fallback_to_page() resolved as a page in this request. */
	private static $fallback_slug = '';

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
		if ( ! $slug ) {
			$slug = self::$fallback_slug;
		}
		if ( ! $slug && isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $slug ) {
			return;
		}
		$url = self::redirect_url( (string) $slug, wp_unslash( $_GET ), function_exists( 'is_404' ) && is_404() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( null === $url ) {
			return;
		}
		// The redirect depends on the partner's current status, code and landing page: never cached
		// by Varnish/Cloudflare (a cached 302 would keep sending visitors to an old target).
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Target of a landing URL /{prefix}/{slug}/ (null = no redirect).
	 *
	 * An active partner leads to their landing page with the code. A real page under the path wins
	 * (see fallback_to_page()). Otherwise – unknown slug, or a paused/terminated partner – the
	 * visitor goes to the default landing page without a code (R14-T4): printed QR codes and old
	 * posts do not end on a 404, and unknown slugs get the same answer as known ones (R14-S2).
	 *
	 * @param string $slug   Slug.
	 * @param array  $query  Query parameters of the request (unslashed).
	 * @param bool   $is_404 Whether WordPress found nothing under the path.
	 * @return string|null
	 */
	public static function redirect_url( $slug, array $query, $is_404 ) {
		if ( ! \WPAffiliateCaptain\Core\Features::enabled( 'landing' ) ) {
			return null; // Landing pages switched off (SET-AC-26): no redirect.
		}
		$aff = self::affiliate_for_slug( $slug );
		if ( ! $aff || 'active' !== $aff->status ) {
			if ( ! $is_404 ) {
				return null;
			}
			if ( ! $aff ) {
				Tracker::note_code_miss();
			}
			return self::default_url( $query );
		}
		if ( Tracker::code_misses_exceeded() ) {
			// This address tried too many unknown codes/slugs: no code in the answer (R14-S2).
			return self::default_url( $query );
		}
		return self::target_url( $aff, $query );
	}

	/**
	 * Default landing page (published, in the visitor's language; else the home page) with the
	 * campaign parameters of the link but without a partner code.
	 *
	 * @param array $query Query parameters of the request (unslashed).
	 * @return string
	 */
	public static function default_url( array $query = array() ) {
		$url  = \WPAffiliateCaptain\Core\Locale::page_url( (int) Settings::get( 'landing_page_id' ) );
		$args = self::passthrough_args( $query );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Click IDs of ad networks passed on by the landing redirect (besides all utm_* parameters).
	 */
	const CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'dclid', 'gad_source', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', 'epik', 'sccid', 'irclickid', 'mc_cid', 'mc_eid' );

	/**
	 * Campaign parameters and click IDs of the partner's link that the landing redirect keeps
	 * (R14-T7): every utm_* parameter and the common click IDs, plus the sub-ID. Placeholders of
	 * edge caches are dropped.
	 *
	 * @param array $query Query parameters of the request (unslashed).
	 * @return array<string,string>
	 */
	public static function passthrough_args( array $query ) {
		$names = array();
		foreach ( array_keys( $query ) as $key ) {
			if ( is_string( $key ) && preg_match( '/^utm_[a-z0-9_]{1,40}$/', $key ) ) {
				$names[] = $key;
			}
		}
		/**
		 * Query parameters the landing redirect /partner/{slug}/ passes on to the landing page
		 * (values are cleaned; the referral and sub-ID parameters are handled separately).
		 *
		 * @since 0.14.0
		 * @param string[] $names Default: the utm_* parameters of the request and common click IDs
		 *                        (gclid, fbclid, msclkid, ttclid, …).
		 * @param array    $query Query parameters of the request.
		 */
		$names = (array) apply_filters( 'wpac_landing_passthrough_params', array_merge( $names, self::CLICK_IDS ), $query );
		$skip  = array( (string) Settings::get( 'referral_param' ), Tracker::sub_param(), self::QUERY_VAR );
		$args  = array();
		foreach ( $names as $k ) {
			$k = (string) $k;
			if ( '' === $k || in_array( $k, $skip, true ) || ! isset( $query[ $k ] ) || ! is_string( $query[ $k ] ) ) {
				continue;
			}
			$value = 0 === strpos( $k, 'utm_' ) ? Tracker::clean_utm( $query[ $k ] ) : self::clean_click_id( $query[ $k ] );
			if ( '' !== $value ) {
				$args[ $k ] = $value;
			}
		}
		$sub = isset( $query[ Tracker::sub_param() ] ) ? Tracker::clean_sub( $query[ Tracker::sub_param() ] ) : '';
		if ( '' !== $sub ) {
			$args[ Tracker::sub_param() ] = $sub;
		}
		return $args;
	}

	/**
	 * Clean a click ID: letters, digits and . _ - ~ only, at most 255 characters; placeholders of
	 * edge caches ("…_STRIPPED") are dropped.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_click_id( $value ) {
		$value = substr( (string) preg_replace( '/[^A-Za-z0-9._\-~]/', '', (string) $value ), 0, 255 );
		return preg_match( '/_STRIPPED$/i', $value ) ? '' : $value;
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
		// The landing page in the visitor's current language (WPML/Polylang); an unpublished partner
		// page falls back to the default landing page, an unpublished default page to the home page
		// (R14-T2).
		$url = \WPAffiliateCaptain\Core\Locale::page_url( (int) Settings::get( 'landing_page_id' ) );
		if ( (int) $aff->landing_page_id ) {
			$url = \WPAffiliateCaptain\Core\Locale::page_url( (int) $aff->landing_page_id, '', $url );
		}
		if ( $code ) {
			// The code is passed on: a slug could equal another partner's code (codes win when
			// resolving), and a slug resolves to any valid code of the partner, not the main one.
			// Campaign parameters, click IDs and the sub-ID of the partner's link are kept (R14-T7).
			$url = add_query_arg( array( (string) Settings::get( 'referral_param' ) => $code ) + self::passthrough_args( $query ), $url );
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
		if ( ! \WPAffiliateCaptain\Core\Features::enabled( 'landing' ) ) {
			return ''; // Landing pages switched off (SET-AC-26).
		}
		$atts  = shortcode_atts(
			array(
				/* translators: %s: partner name */
				'text'     => __( 'Recommended by %s', 'affiliate-captain' ),
				'discount' => '',
			),
			(array) $atts,
			'wpac_partner_welcome'
		);
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
		$name     = self::display_name( (string) $aff->name );
		$discount = in_array( strtolower( (string) $atts['discount'] ), array( '1', 'yes', 'true' ), true ) ? self::welcome_discount( $aff, $resolved ) : '';
		if ( '' === $name && ! $aff->landing_message && '' === $discount ) {
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
		if ( '' !== $discount ) {
			$out .= '<p class="wpac-welcome-discount">' . esc_html( $discount ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Discount line of the welcome box (attribute discount="1"): only with the automatic partner
	 * coupon switched on and a usable coupon of this partner.
	 *
	 * @param object $aff      Partner.
	 * @param array  $resolved Resolved code of the visit.
	 * @return string
	 */
	private static function welcome_discount( $aff, array $resolved ) {
		if ( ! \WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::enabled() ) {
			return '';
		}
		$coupon = \WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::coupon_for( (int) $aff->id, isset( $resolved['code_id'] ) ? (int) $resolved['code_id'] : 0 );
		$label  = '' !== $coupon ? \WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::discount_label( $coupon ) : '';
		if ( '' === $label ) {
			return '';
		}
		/* translators: 1: discount, e.g. "10%" or "5,00 €", 2: coupon code */
		return sprintf( __( 'Your discount: %1$s off with the coupon %2$s. If it is not in your cart automatically, enter it at checkout.', 'affiliate-captain' ), $label, strtoupper( $coupon ) );
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
