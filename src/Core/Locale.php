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
		// The language chosen in the partner profile wins (SET-AC-17: e-mails and credit notes).
		$chosen = $aff && ! empty( $aff->id ) ? self::partner( (int) $aff->id ) : '';
		if ( '' !== $chosen ) {
			return $chosen;
		}
		$user = $aff && ! empty( $aff->user_id ) && function_exists( 'get_userdata' ) ? get_userdata( (int) $aff->user_id ) : false;
		// The language chosen in the linked account. An account without one follows the site
		// language: get_user_locale() would return get_locale(), which WPML/Polylang set to the
		// language of the current request (the admin who triggers the mail, a translated page).
		if ( $user && ! empty( $user->locale ) ) {
			return (string) $user->locale;
		}
		return self::site();
	}

	/**
	 * The site language: with WPML/Polylang the default language (get_locale() follows the
	 * language of the current request there), otherwise get_locale().
	 *
	 * @return string
	 */
	public static function site() {
		$locale = '';
		if ( function_exists( 'pll_default_language' ) ) {
			$locale = (string) pll_default_language( 'locale' );
		} elseif ( function_exists( 'has_filter' ) && has_filter( 'wpml_default_language' ) ) {
			$code  = (string) apply_filters( 'wpml_default_language', null );
			$langs = (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( '' !== $code && isset( $langs[ $code ]['default_locale'] ) ) {
				$locale = (string) $langs[ $code ]['default_locale'];
			}
		}
		if ( '' === $locale && function_exists( 'get_locale' ) ) {
			$locale = (string) get_locale();
		}
		return '' !== $locale ? $locale : 'en_US';
	}

	/** Option (not autoloaded): language chosen in the partner profile, partner ID => locale (SET-AC-17). */
	const PARTNER_OPTION = 'wpac_partner_locales';

	/**
	 * Language chosen in a partner's profile for e-mails and credit notes ('' = not chosen: credit
	 * notes in the site language, e-mails in the language of the linked account or the site).
	 *
	 * @param int $affiliate_id Partner.
	 * @return string Locale or ''.
	 */
	public static function partner( $affiliate_id ) {
		$map = function_exists( 'get_option' ) ? get_option( self::PARTNER_OPTION, array() ) : array();
		$id  = (int) $affiliate_id;
		return is_array( $map ) && $id > 0 && isset( $map[ $id ] ) && is_string( $map[ $id ] ) ? $map[ $id ] : '';
	}

	/**
	 * Store the language of a partner ('' = site language). Only installed languages.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $locale       Locale or ''.
	 * @return bool Whether the value is valid (and stored).
	 */
	public static function set_partner( $affiliate_id, $locale ) {
		$id     = (int) $affiliate_id;
		$locale = (string) $locale;
		if ( $id <= 0 || ( '' !== $locale && ! isset( self::installed()[ $locale ] ) ) ) {
			return false;
		}
		$map = get_option( self::PARTNER_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		$old = isset( $map[ $id ] ) ? (string) $map[ $id ] : '';
		if ( $old === $locale ) {
			return true;
		}
		// The site language is the default: not stored, so a later change of the site language applies.
		if ( '' === $locale || $locale === self::site() ) {
			unset( $map[ $id ] );
		} else {
			$map[ $id ] = $locale;
		}
		update_option( self::PARTNER_OPTION, $map, false );
		Audit::log(
			'affiliate.locale',
			'affiliate',
			$id,
			array(
				'from' => $old,
				'to'   => isset( $map[ $id ] ) ? $map[ $id ] : '',
			)
		);
		return true;
	}

	/**
	 * Forget the language of a deleted or anonymised partner.
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function forget_partner( $affiliate_id ) {
		$map = function_exists( 'get_option' ) ? get_option( self::PARTNER_OPTION, array() ) : array();
		if ( is_array( $map ) && isset( $map[ (int) $affiliate_id ] ) ) {
			unset( $map[ (int) $affiliate_id ] );
			update_option( self::PARTNER_OPTION, $map, false );
		}
	}

	/**
	 * Installed languages that texts can be stored for: the site language, English and every
	 * language with WordPress translations installed (get_available_languages()).
	 *
	 * @return array locale => name (site language first)
	 */
	public static function installed() {
		$site    = self::site();
		$locales = array( $site, 'en_US' );
		if ( function_exists( 'get_available_languages' ) ) {
			$locales = array_merge( $locales, (array) get_available_languages() );
		}
		$out = array();
		foreach ( $locales as $locale ) {
			$locale = (string) $locale;
			if ( preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) && ! isset( $out[ $locale ] ) ) {
				$out[ $locale ] = self::name( $locale );
			}
		}
		/**
		 * Languages offered for per-language texts and the partner language (SET-AC-17).
		 *
		 * @param array $out locale => name.
		 */
		$out = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpac_text_locales', $out ) : $out;
		return array( $site => isset( $out[ $site ] ) ? $out[ $site ] : self::name( $site ) ) + $out;
	}

	/**
	 * Display name of a locale (its own name, e.g. "Deutsch"; the code without intl).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function name( $locale ) {
		$name = class_exists( '\Locale' ) ? (string) \Locale::getDisplayName( (string) $locale, (string) $locale ) : '';
		return '' !== $name && $name !== $locale ? $name . ' (' . $locale . ')' : (string) $locale;
	}

	/**
	 * The language texts are written in right now (after a switch_to() the partner's language).
	 *
	 * @return string
	 */
	public static function current() {
		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}
		return function_exists( 'get_locale' ) ? (string) get_locale() : self::site();
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
	 * Languages without an own translation of the plugin that use another one (P3-08): Austrian and
	 * Swiss German use the formal German translation ("Sie", as WordPress does for de_AT/de_CH),
	 * informal Swiss German the informal one ("du"). A translation of the language itself (from
	 * translate.wordpress.org or in the plugin) always wins.
	 */
	const FALLBACKS = array(
		'de_AT'          => 'de_DE_formal',
		'de_CH'          => 'de_DE_formal',
		'de_CH_informal' => 'de_DE',
	);

	/**
	 * Locale whose translation the plugin uses for a language without its own ('' = none).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function fallback( $locale ) {
		$map = self::FALLBACKS;
		/**
		 * Languages that use the plugin translation of another language (locale => locale).
		 *
		 * @param array $map locale => fallback locale.
		 */
		$map = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpac_translation_fallbacks', $map ) : $map;
		return isset( $map[ (string) $locale ] ) && is_string( $map[ (string) $locale ] ) && $map[ (string) $locale ] !== $locale ? $map[ (string) $locale ] : '';
	}

	/**
	 * Filter load_textdomain_mofile: a missing .mo file of the plugin in a language with a fallback
	 * becomes the file of the fallback language (same folder first, then the plugin's folder).
	 *
	 * @param string $mofile Path.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function mofile( $mofile, $domain = '' ) {
		if ( self::DOMAIN !== $domain || ! is_string( $mofile ) || is_readable( $mofile ) ) {
			return $mofile;
		}
		$name = basename( $mofile );
		if ( ! preg_match( '/^' . self::DOMAIN . '-(.+)\.mo$/', $name, $m ) ) {
			return $mofile;
		}
		return self::fallback_file( $mofile, $m[1], '.mo' );
	}

	/**
	 * Filter load_script_translation_file: the same for the JSON translations of the block editor
	 * scripts (affiliate-captain-<locale>-<md5>.json).
	 *
	 * @param string|false $file   Path.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function script_file( $file, $handle = '', $domain = '' ) {
		if ( self::DOMAIN !== $domain || ! is_string( $file ) || is_readable( $file ) ) {
			return $file;
		}
		if ( ! preg_match( '/^' . self::DOMAIN . '-([a-z]{2,3}(?:_[A-Za-z0-9]+)*)(-[0-9a-f]{32}\.json)$/', basename( $file ), $m ) ) {
			return $file;
		}
		return self::fallback_file( $file, $m[1], $m[2] );
	}

	/**
	 * Path of the fallback translation of a missing file, or the path unchanged.
	 *
	 * @param string $file   Missing file.
	 * @param string $locale Its locale.
	 * @param string $suffix What follows the locale in the file name.
	 * @return string
	 */
	private static function fallback_file( $file, $locale, $suffix ) {
		$fallback = self::fallback( $locale );
		if ( '' === $fallback ) {
			return $file;
		}
		$name = self::DOMAIN . '-' . $fallback . $suffix;
		foreach ( array( dirname( $file ) . '/' . $name, WPAC_DIR . 'languages/' . $name ) as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}
		return $file;
	}

	/**
	 * Translation files of the plugin for a locale, in loading order (the language's own files,
	 * else those of its fallback language).
	 *
	 * @param string $locale Locale.
	 * @return string[] Readable files.
	 */
	public static function mo_files( $locale ) {
		foreach ( array_filter( array( (string) $locale, self::fallback( $locale ) ) ) as $try ) {
			$files = array_values(
				array_filter(
					array(
						( defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WPAC_DIR . 'languages' ) . '/plugins/' . self::DOMAIN . '-' . $try . '.mo',
						WPAC_DIR . 'languages/' . self::DOMAIN . '-' . $try . '.mo',
					),
					'is_readable'
				)
			);
			if ( $files ) {
				return $files;
			}
		}
		return array();
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
		foreach ( self::mo_files( $locale ) as $file ) {
			load_textdomain( self::DOMAIN, $file, $locale );
		}
	}

	/**
	 * Right-to-left overrides of a plugin stylesheet (assets/css/<name>-rtl.css), loaded after it
	 * for right-to-left languages.
	 *
	 * @param string $handle Handle of the main stylesheet.
	 * @param string $name   File name without extension.
	 */
	public static function enqueue_rtl( $handle, $name ) {
		if ( function_exists( 'is_rtl' ) && is_rtl() && function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( $handle . '-rtl', WPAC_URL . 'assets/css/' . $name . '-rtl.css', array( $handle ), WPAC_VERSION );
		}
	}

	/**
	 * A stored decimal ("12.5", "19.00") for display in the current language ("12,5", "19"):
	 * as many decimals as it has (without trailing zeros), at most 4.
	 *
	 * @param string|float|int $value Decimal.
	 * @return string
	 */
	public static function number( $value ) {
		$text = (string) $value;
		if ( ! is_numeric( $text ) ) {
			return $text;
		}
		$text = false !== strpos( $text, '.' ) ? rtrim( rtrim( $text, '0' ), '.' ) : $text;
		$dec  = false !== strpos( $text, '.' ) ? min( 4, strlen( substr( strrchr( $text, '.' ), 1 ) ) ) : 0;
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( (float) $text, $dec ) : number_format( (float) $text, $dec, '.', ',' );
	}

	/**
	 * A percentage for display ("12,5 %" in German, with a non-breaking space).
	 *
	 * @param string|float|int $value Decimal percent.
	 * @return string
	 */
	public static function percent( $value ) {
		return self::number( $value ) . "\u{00A0}%";
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
	 * Permalink of a page in the reader's language. Only published pages count (R14-T2): a page in
	 * the trash, a draft or a private page leads visitors to a 404. An unpublished translation
	 * falls back to the published original, an unpublished page to the fallback.
	 *
	 * @param int    $page_id  Page ID.
	 * @param string $locale   Locale of the reader ('' = current language).
	 * @param string $fallback URL if there is no (published) page.
	 * @return string
	 */
	public static function page_url( $page_id, $locale = '', $fallback = '' ) {
		$url = '';
		foreach ( array_unique( array( self::page_id( $page_id, $locale ), (int) $page_id ) ) as $id ) {
			if ( self::published( $id ) ) {
				$url = (string) get_permalink( $id );
				break;
			}
		}
		return '' !== $url ? $url : ( '' !== $fallback ? $fallback : home_url( '/' ) );
	}

	/**
	 * Whether a page is published (publicly reachable).
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	public static function published( $page_id ) {
		return (int) $page_id > 0 && 'publish' === get_post_status( (int) $page_id );
	}
}
