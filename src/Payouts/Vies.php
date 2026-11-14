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
use WPAffiliateCaptain\Core\Log;
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
	 * Stored result, but only when it belongs to one of the given VAT IDs (the current one or
	 * one waiting for approval): a result for an earlier VAT ID must not be shown next to a new one.
	 *
	 * @param int      $affiliate_id Partner.
	 * @param string[] $vat_ids      VAT IDs.
	 * @return array|null
	 */
	public static function result_for( $affiliate_id, array $vat_ids ) {
		$r = self::result( (int) $affiliate_id );
		if ( ! $r ) {
			return null;
		}
		foreach ( $vat_ids as $vat_id ) {
			if ( '' !== (string) $vat_id && strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $vat_id ) ) === (string) $r['vat_id'] ) {
				return $r;
			}
		}
		return null;
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
		// A change waiting for the admin's approval: check the requested VAT ID (the result
		// helps to decide; the stored one was checked before).
		$pending = TaxHistory::pending( (int) $affiliate_id );
		if ( $pending && ! empty( $pending['new']['vat_id'] ) ) {
			$vat_id = strtoupper( (string) $pending['new']['vat_id'] );
		}
		if ( '' === $vat_id ) {
			delete_option( 'wpac_vies_' . (int) $affiliate_id );
			return null;
		}
		$result = array(
			'vat_id'       => $vat_id,
			'status'       => 'not_applicable',
			'name'         => '',
			'consultation' => '',
			'error'        => '',
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
			$answer = self::request( $body );
			if ( 'INVALID_REQUESTER_INFO' === $answer['error'] && $requester ) {
				// Our own VAT ID was refused: the admin must correct it. Check without it meanwhile
				// (no consultation number then).
				Log::error( 'VIES refused the operator VAT ID (INVALID_REQUESTER_INFO) - check Settings, Payouts', array( 'affiliate' => (int) $affiliate_id ) );
				Audit::log( 'settings.vies_requester_invalid', 'settings', 0 );
				unset( $body['requesterMemberStateCode'], $body['requesterNumber'] );
				$answer                    = self::request( $body );
				$result['requester_error'] = true;
			}
			$result['status']       = $answer['status'];
			$result['error']        = $answer['error'];
			$result['name']         = $answer['name'];
			$result['consultation'] = $answer['consultation'];
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
				'error'        => $result['error'],
			)
		);
		return $result;
	}

	/**
	 * Errors of VIES that mean "the number is wrong" (not "try again later").
	 */
	const INVALID_ERRORS = array( 'INVALID_INPUT', 'INVALID_VAT_NUMBER' );

	/**
	 * One request to VIES.
	 *
	 * @param array $body Request body.
	 * @return array [ status valid|invalid|unavailable, error code, name, consultation ]
	 */
	private static function request( array $body ) {
		/**
		 * Timeout of a VIES request in seconds.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout  = max( 1, (int) apply_filters( 'wpac_vies_timeout', 10 ) );
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		$out = array(
			'status'       => 'unavailable',
			'error'        => '',
			'name'         => '',
			'consultation' => '',
		);
		if ( is_wp_error( $response ) ) {
			$out['error'] = 'HTTP';
			Log::warning( 'VIES request failed', array( 'error' => $response->get_error_message() ) );
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['errorWrappers'] ) && is_array( $data['errorWrappers'] ) ) {
			$first        = reset( $data['errorWrappers'] );
			$out['error'] = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', is_array( $first ) && isset( $first['error'] ) ? (string) $first['error'] : 'unknown' ) );
			if ( in_array( $out['error'], self::INVALID_ERRORS, true ) ) {
				$out['status'] = 'invalid'; // Malformed number: retrying never helps.
			} else {
				Log::warning( 'VIES answered with an error', array( 'error' => $out['error'], 'http' => $code ) );
			}
			return $out;
		}
		if ( ! is_array( $data ) || ! isset( $data['valid'] ) ) {
			$out['error'] = 'HTTP_' . $code;
			Log::warning( 'VIES answer not readable', array( 'http' => $code ) );
			return $out;
		}
		$out['status']       = $data['valid'] ? 'valid' : 'invalid';
		$out['name']         = isset( $data['name'] ) && '---' !== $data['name'] ? sanitize_text_field( (string) $data['name'] ) : '';
		$out['consultation'] = isset( $data['requestIdentifier'] ) ? sanitize_text_field( (string) $data['requestIdentifier'] ) : '';
		return $out;
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
		$a   = Db::table( 'affiliates' );
		// Ended partnerships are not checked any more.
		$ids = $wpdb->get_col( "SELECT p.affiliate_id FROM {$t} p JOIN {$a} a ON a.id = p.affiliate_id WHERE p.vat_id <> '' AND a.status NOT IN ('terminated','rejected') ORDER BY p.affiliate_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * Maximum VIES re-checks scheduled per day.
		 *
		 * @param int $cap Checks.
		 */
		$cap = max( 1, (int) apply_filters( 'wpac_vies_daily_cap', self::DAILY_CAP ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$r = self::result( (int) $id );
			if ( $r && self::next_check( $r ) > time() ) {
				continue;
			}
			\WPAffiliateCaptain\Core\Scheduler::once( self::HOOK, 60 + 30 * $n, array( (int) $id ) );
			if ( ++$n >= $cap ) {
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
