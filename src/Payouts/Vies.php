<?php
/**
 * VAT ID check against the EU VIES service (qualified confirmation with the operator's VAT ID,
 * so the consultation number serves as proof for reverse-charge credit notes).
 *
 * Results are stored per partner; a VAT ID that VIES reports as invalid blocks reverse-charge
 * payouts. When VIES is unavailable nothing is blocked (the check is repeated with growing pauses).
 *
 * The check sends the partner's VAT ID (and the operator's) to the European Commission, so it is
 * optional (setting "vies_check", off for new installs): while it is off, nothing is sent and
 * stored results no longer block payouts.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Vies {

	const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
	const HOOK     = 'wpac_vies_check';

	/** EU member states as VIES expects them (Greece = EL, Northern Ireland = XI). */
	const MEMBERS = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI' );

	/**
	 * Split a VAT ID into VIES country code and number, or null when VIES does not cover it.
	 *
	 * @param string $vat_id VAT ID.
	 * @return array|null [ country, number ]
	 */
	public static function split( $vat_id ) {
		$vat_id  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $vat_id ) );
		$country = substr( $vat_id, 0, 2 );
		$country = 'GR' === $country ? 'EL' : $country;
		if ( strlen( $vat_id ) < 4 || ! in_array( $country, self::MEMBERS, true ) ) {
			return null;
		}
		return array( $country, substr( $vat_id, 2 ) );
	}

	/**
	 * Whether VAT IDs are checked with VIES (setting; external request to the EU Commission).
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Check partner VAT IDs with the EU VIES service.
		 *
		 * @param bool $enabled Setting "vies_check".
		 */
		return (bool) apply_filters( 'wpac_vies_enabled', (bool) Settings::get( 'vies_check' ) );
	}

	/** Re-checks scheduled per daily run (each one is a request of up to 10 seconds). */
	const DAILY_CAP = 20;

	/**
	 * Last stored result of a partner, or null.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array|null [ vat_id, status valid|invalid|unavailable|not_applicable, name, consultation, checked_at ]
	 */
	public static function result( $affiliate_id ) {
		$r = get_option( 'wpac_vies_' . (int) $affiliate_id );
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Check a partner's VAT ID now and store the result.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array|null Result (null without VAT ID or when the check is switched off).
	 */
	public static function check( $affiliate_id ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$profile = ProfileRepository::get( (int) $affiliate_id );
		$vat_id  = $profile ? strtoupper( (string) $profile->vat_id ) : '';
		if ( '' === $vat_id ) {
			delete_option( 'wpac_vies_' . (int) $affiliate_id );
			return null;
		}
		$result = array(
			'vat_id'       => $vat_id,
			'status'       => 'not_applicable',
			'name'         => '',
			'consultation' => '',
			'checked_at'   => Db::now(),
		);
		$parts = self::split( $vat_id );
		if ( $parts ) {
			$body      = array(
				'countryCode' => $parts[0],
				'vatNumber'   => $parts[1],
			);
			$requester = self::split( (string) Settings::get( 'operator_vat_id' ) );
			if ( $requester ) {
				$body['requesterMemberStateCode'] = $requester[0];
				$body['requesterNumber']          = $requester[1];
			}
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			$data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['valid'] ) || ! empty( $data['errorWrappers'] ) ) {
				$result['status'] = 'unavailable';
			} else {
				$result['status']       = $data['valid'] ? 'valid' : 'invalid';
				$result['name']         = isset( $data['name'] ) && '---' !== $data['name'] ? sanitize_text_field( (string) $data['name'] ) : '';
				$result['consultation'] = isset( $data['requestIdentifier'] ) ? sanitize_text_field( (string) $data['requestIdentifier'] ) : '';
			}
		}
		// Consecutive "unavailable" answers for the same VAT ID: the next try waits longer each time.
		$before          = self::result( (int) $affiliate_id );
		$result['tries'] = 'unavailable' === $result['status'] ? ( $before && 'unavailable' === $before['status'] && $before['vat_id'] === $vat_id && isset( $before['tries'] ) ? (int) $before['tries'] : 0 ) + 1 : 0;
		update_option( 'wpac_vies_' . (int) $affiliate_id, $result, false );
		Audit::log(
			'payout_profile.vies_checked',
			'affiliate',
			(int) $affiliate_id,
			array(
				'status'       => $result['status'],
				'consultation' => $result['consultation'],
			)
		);
		return $result;
	}

	/**
	 * Check soon in the background (after the profile was saved).
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function schedule( $affiliate_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		\WPAffiliateCaptain\Core\Scheduler::once( self::HOOK, 10, array( (int) $affiliate_id ) );
	}

	/**
	 * When the stored result is due for a new check: valid/invalid results after 30 days,
	 * "unavailable" after 1, 2, 4, 8, 16, then every 30 days (not daily forever).
	 *
	 * @param array|null $r Stored result.
	 * @return int Unix time (0 = now).
	 */
	public static function next_check( $r ) {
		if ( ! is_array( $r ) || empty( $r['checked_at'] ) ) {
			return 0;
		}
		$checked = (int) strtotime( $r['checked_at'] . ' UTC' );
		if ( 'unavailable' !== $r['status'] ) {
			return $checked + 30 * DAY_IN_SECONDS;
		}
		$tries = max( 1, isset( $r['tries'] ) ? (int) $r['tries'] : 1 );
		return $checked + min( 30, 2 ** min( $tries - 1, 5 ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Daily: schedule re-checks of VAT IDs whose result is due (at most DAILY_CAP, spread out).
	 * The requests run as separate background tasks, never inside the daily maintenance.
	 *
	 * @return int Checks scheduled.
	 */
	public static function recheck_stale() {
		global $wpdb;
		if ( ! self::enabled() ) {
			return 0;
		}
		$t   = Db::table( 'payout_profiles' );
		$ids = $wpdb->get_col( "SELECT affiliate_id FROM {$t} WHERE vat_id <> '' ORDER BY affiliate_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n   = 0;
		foreach ( $ids as $id ) {
			$r = self::result( (int) $id );
			if ( $r && self::next_check( $r ) > time() ) {
				continue;
			}
			\WPAffiliateCaptain\Core\Scheduler::once( self::HOOK, 60 + 30 * $n, array( (int) $id ) );
			if ( ++$n >= self::DAILY_CAP ) {
				break;
			}
		}
		return $n;
	}

	/**
	 * Whether VIES reported the partner's current VAT ID as invalid.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $vat_id       Current VAT ID.
	 * @return bool
	 */
	public static function is_invalid( $affiliate_id, $vat_id ) {
		if ( ! self::enabled() ) {
			return false; // Switched off: an old result can no longer be re-checked, so it does not block.
		}
		$r = self::result( (int) $affiliate_id );
		return $r && 'invalid' === $r['status'] && strtoupper( (string) $vat_id ) === $r['vat_id'];
	}
}
