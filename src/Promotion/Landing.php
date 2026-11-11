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
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
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
		$key = 'v' . WPAC_DB_VERSION . ':' . Settings::get( 'landing_prefix' );
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
		$aff = AffiliateRepository::find_by_slug( (string) $vars[ self::QUERY_VAR ] );
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
		$aff = AffiliateRepository::find_by_slug( $slug );
		if ( ! $aff || 'active' !== $aff->status ) {
			return; // Handled as a normal page / 404 (see fallback_to_page()).
		}
		$code = CodeRepository::main_code( (int) $aff->id );
		$page = $aff && (int) $aff->landing_page_id ? (int) $aff->landing_page_id : (int) Settings::get( 'landing_page_id' );
		$url  = $page ? get_permalink( $page ) : home_url( '/' );
		if ( $code ) {
			$args = array( (string) Settings::get( 'referral_param' ) => $code );
			// Keep campaign parameters from the partner's link.
			foreach ( array( 'utm_campaign', 'utm_source', 'utm_medium' ) as $k ) {
				if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}
			$url = add_query_arg( $args, $url );
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * [wpac_partner_welcome text="Empfohlen von %s"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function welcome( $atts = array() ) {
		$atts  = shortcode_atts( array( 'text' => __( 'Recommended by %s', 'wp-affiliate-captain' ) ), (array) $atts, 'wpac_partner_welcome' );
		$param = (string) Settings::get( 'referral_param' );
		$code  = '';
		if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_text_field( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		// Visitor-specific output must not end up in a page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		// No sprintf(): editor text may contain "%" (e.g. "10% Rabatt") – only %s is replaced.
		$text = (string) $atts['text'];
		$out  = '<div class="wpac-welcome"><strong>' . ( false !== strpos( $text, '%s' ) ? str_replace( '%s', esc_html( $aff->name ), esc_html( $text ) ) : esc_html( $text . ' ' . $aff->name ) ) . '</strong>';
		if ( $aff->landing_message ) {
			$out .= '<p>' . nl2br( esc_html( $aff->landing_message ) ) . '</p>';
		}
		return $out . '</div>';
	}
}
