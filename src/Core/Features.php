<?php
/**
 * Switches per feature (SET-AC-26): creatives, landing pages, monthly tiers, QR codes and the
 * broadcast mail can each be switched off in the settings. A switched-off feature loses its admin
 * menu item / section and its part of the partner area, and its endpoints and shortcodes refuse
 * quietly. Stored data (creatives, tier plans, bonuses, opt-outs, a running broadcast) are never
 * changed: switching the feature on again shows everything as before.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Features {

	/** Feature => setting key (default 1 = on, so existing sites are unchanged). */
	const SETTINGS = array(
		'creatives' => 'feature_creatives',
		'landing'   => 'feature_landing',
		'tiers'     => 'feature_tiers',
		'qr'        => 'feature_qr',
		'broadcast' => 'feature_broadcast',
	);

	/** Admin actions (admin-post "do") that belong to a feature. */
	const ACTIONS = array(
		'creative_save'    => 'creatives',
		'creative_approve' => 'creatives',
		'creative_archive' => 'creatives',
		'tier_create'      => 'tiers',
		'tier_deactivate'  => 'tiers',
		'tier_close_month' => 'tiers',
		'broadcast_send'   => 'broadcast',
	);

	/**
	 * Whether a feature is switched on. Unknown features are always on.
	 *
	 * @param string $feature creatives|landing|tiers|qr|broadcast.
	 * @return bool
	 */
	public static function enabled( $feature ) {
		$feature = (string) $feature;
		if ( ! isset( self::SETTINGS[ $feature ] ) ) {
			return true;
		}
		$value = Settings::get( self::SETTINGS[ $feature ] );
		$on    = null === $value ? true : (bool) (int) $value;
		/**
		 * Whether a feature of Affiliate Captain is switched on (since plugin 0.21.0). The setting
		 * on the settings page is the default; the filter wins.
		 *
		 * @param bool   $on      Value of the setting.
		 * @param string $feature creatives, landing, tiers, qr or broadcast.
		 */
		return (bool) apply_filters( 'wpac_feature_enabled', $on, $feature );
	}

	/**
	 * Whether an admin action may run (false when its feature is switched off).
	 *
	 * @param string $action Admin action ("do").
	 * @return bool
	 */
	public static function action_allowed( $action ) {
		return ! isset( self::ACTIONS[ $action ] ) || self::enabled( self::ACTIONS[ $action ] );
	}
}
