<?php
/**
 * Language helpers: e-mails to partners in the partner's language (their WordPress
 * user locale if the account is linked, otherwise the site language) and page links
 * translated with WPML/Polylang.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Locale {

	const DOMAIN = 'affiliate-captain';

	/**
	 * Locale for messages to a partner.
	 *
	 * @param object|null $aff Affiliate row.
	 * @return string
	 */
	public static function for_affiliate( $aff ) {
		if ( $aff && ! empty( $aff->user_id ) && function_exists( 'get_user_locale' ) && function_exists( 'get_userdata' ) && get_userdata( (int) $aff->user_id ) ) {
			$locale = (string) get_user_locale( (int) $aff->user_id );
			if ( '' !== $locale ) {
				return $locale;
			}
		}
		// The site language (get_locale(), not the language of the admin who triggers the mail).
		$site = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		return '' !== $site ? $site : 'en_US';
	}

	/**
	 * Switch to a partner's locale.
	 *
	 * @param object|null $aff Affiliate row.
	 * @return bool Whether switched (pass to restore()).
	 */
	public static function switch_for_affiliate( $aff ) {
		return self::switch_to( self::for_affiliate( $aff ) );
	}

	/**
	 * Switch to a locale (no-op if it is already active).
	 *
	 * @param string $locale Locale.
	 * @return bool Whether switched.
	 */
	public static function switch_to( $locale ) {
		if ( '' === (string) $locale || ! function_exists( 'switch_to_locale' ) || ! function_exists( 'determine_locale' ) || determine_locale() === $locale ) {
			return false;
		}
		if ( ! switch_to_locale( $locale ) ) {
			return false;
		}
		self::reload( $locale );
		return true;
	}

	/**
	 * Restore the locale after switch_to()/switch_for_affiliate().
	 *
	 * @param bool $switched Return value of the switch.
	 */
	public static function restore( $switched ) {
		if ( ! $switched || ! function_exists( 'restore_previous_locale' ) ) {
			return;
		}
		restore_previous_locale();
		self::reload( determine_locale() );
	}

	/**
	 * Reload the plugin's translations for a locale. WordPress versions differ in whether
	 * plugin text domains follow switch_to_locale() (and in the admin determine_locale()
	 * returns the admin's language), so the files are loaded explicitly.
	 *
	 * @param string $locale Locale.
	 */
	private static function reload( $locale ) {
		if ( ! function_exists( 'unload_textdomain' ) || ! function_exists( 'load_textdomain' ) ) {
			return;
		}
		// Reloadable: without it WordPress (6.1+) marks the domain as unloaded for good and the
		// just-in-time loader no longer finds translations for later strings of the request.
		unload_textdomain( self::DOMAIN, true );
		$files = array(
			WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo',
			WPAC_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo',
		);
		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) {
				load_textdomain( self::DOMAIN, $file, $locale );
			}
		}
	}

	/**
	 * Language code used by WPML/Polylang for a locale ('' = current language).
	 *
	 * @param string $locale Locale ('' = current).
	 * @return string
	 */
	public static function lang_code( $locale ) {
		if ( '' === (string) $locale ) {
			return '';
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$i       = array_search( $locale, $locales, true );
			return false !== $i && isset( $slugs[ $i ] ) ? (string) $slugs[ $i ] : '';
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_active_languages' ) ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $lang ) {
				if ( isset( $lang['default_locale'] ) && $lang['default_locale'] === $locale ) {
					return (string) $code;
				}
			}
		}
		return '';
	}

	/**
	 * ID of the translation of a page (WPML/Polylang), or the page itself.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $locale  Locale of the reader ('' = current language).
	 * @return int
	 */
	public static function page_id( $page_id, $locale = '' ) {
		$page_id = (int) $page_id;
		if ( ! $page_id ) {
			return 0;
		}
		$lang = self::lang_code( $locale );
		if ( function_exists( 'pll_get_post' ) ) {
			$id = '' !== $lang ? pll_get_post( $page_id, $lang ) : pll_get_post( $page_id );
			return $id ? (int) $id : $page_id;
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_object_id' ) ) {
			$type = get_post_type( $page_id );
			$id   = apply_filters( 'wpml_object_id', $page_id, $type ? $type : 'page', true, '' !== $lang ? $lang : null );
			return $id ? (int) $id : $page_id;
		}
		return $page_id;
	}

	/**
	 * Permalink of a page in the reader's language.
	 *
	 * @param int    $page_id  Page ID.
	 * @param string $locale   Locale of the reader ('' = current language).
	 * @param string $fallback URL if there is no page.
	 * @return string
	 */
	public static function page_url( $page_id, $locale = '', $fallback = '' ) {
		$id  = self::page_id( $page_id, $locale );
		$url = $id ? get_permalink( $id ) : '';
		return $url ? (string) $url : ( '' !== $fallback ? $fallback : home_url( '/' ) );
	}
}
