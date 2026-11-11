<?php
/**
 * Marketing consent decision for the optional affiliate cookie.
 *
 * Supports the WP Consent API (wp_has_consent) and a filter for custom consent
 * banners. Without consent no affiliate cookie is set; partner codes keep working.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Tracking;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Consent {

	/**
	 * Declare compliance with the WP Consent API: the cookie is only used with marketing consent.
	 */
	public static function register_hooks() {
		if ( defined( 'WPAC_FILE' ) ) {
			add_filter( 'wp_consent_api_registered_' . plugin_basename( WPAC_FILE ), '__return_true' );
		}
	}

	/**
	 * Whether the affiliate cookie may be set/read.
	 *
	 * @return bool
	 */
	public static function cookie_allowed() {
		$mode = Settings::get( 'cookie_mode' );
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'always' === $mode ) {
			return true;
		}
		$consent = false;
		// The WP Consent API answers "true" when no consent banner has registered a consent type
		// ("no consent management, allow everything"). Under TTDSG/GDPR that is not a consent, so the
		// API is only asked once a banner has set a consent type (optin/optout).
		if ( function_exists( 'wp_has_consent' ) && function_exists( 'wp_get_consent_type' ) && '' !== (string) wp_get_consent_type() ) {
			$consent = (bool) wp_has_consent( 'marketing', defined( 'WPAC_FILE' ) ? plugin_basename( WPAC_FILE ) : 'wp-affiliate-captain' );
		}

		/**
		 * Filter the marketing consent decision (e.g. for a custom consent banner).
		 *
		 * @param bool $consent Whether marketing consent was given.
		 */
		return (bool) apply_filters( 'wpac_has_marketing_consent', $consent );
	}
}
