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
		$consent = function_exists( 'wp_has_consent' ) ? (bool) wp_has_consent( 'marketing' ) : false;

		/**
		 * Filter the marketing consent decision (e.g. for a custom consent banner).
		 *
		 * @param bool $consent Whether marketing consent was given.
		 */
		return (bool) apply_filters( 'wpac_has_marketing_consent', $consent );
	}
}
