<?php
/**
 * VAT ID check against the EU VIES service (qualified confirmation with the operator's VAT ID,
 * so the consultation number serves as proof for reverse-charge credit notes).
 *
 * Results are stored per partner; a VAT ID that VIES reports as invalid blocks reverse-charge
 * payouts. When VIES is unavailable nothing is blocked (the check is repeated).
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
	 * @return array|null Result (null without VAT ID).
	 */
	public static function check( $affiliate_id ) {
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
		if ( ! wp_next_scheduled( self::HOOK, array( (int) $affiliate_id ) ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK, array( (int) $affiliate_id ) );
		}
	}

	/**
	 * Daily: re-check VAT IDs whose last check is older than 30 days or was unavailable (at most 20).
	 */
	public static function recheck_stale() {
		global $wpdb;
		$t   = Db::table( 'payout_profiles' );
		$ids = $wpdb->get_col( "SELECT affiliate_id FROM {$t} WHERE vat_id <> '' ORDER BY affiliate_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n   = 0;
		foreach ( $ids as $id ) {
			$r = self::result( (int) $id );
			if ( $r && 'unavailable' !== $r['status'] && strtotime( $r['checked_at'] . ' UTC' ) > time() - 30 * DAY_IN_SECONDS ) {
				continue;
			}
			self::check( (int) $id );
			if ( ++$n >= 20 ) {
				break;
			}
		}
	}

	/**
	 * Whether VIES reported the partner's current VAT ID as invalid.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $vat_id       Current VAT ID.
	 * @return bool
	 */
	public static function is_invalid( $affiliate_id, $vat_id ) {
		$r = self::result( (int) $affiliate_id );
		return $r && 'invalid' === $r['status'] && strtoupper( (string) $vat_id ) === $r['vat_id'];
	}
}
