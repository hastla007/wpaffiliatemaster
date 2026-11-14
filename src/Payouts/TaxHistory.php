<?php
/**
 * History of the tax-relevant data: the VAT treatment follows the time of the service
 * (commission date), not the day of the payout (§13/§27 UStG).
 *
 * - Partner tax data (tax status, country, VAT ID): every change keeps the previous values
 *   with the time until which they applied (option wpac_tax_history_{partner}).
 * - Domestic VAT rate (setting vat_rate): every change keeps the previous rate
 *   (option wpac_vat_rate_history).
 * - Changes of the tax data by the partner that would change the VAT on credit notes wait for
 *   the approval of an admin (option wpac_tax_pending_{partner}); until then the previous data
 *   apply.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class TaxHistory {

	/** Tax-relevant profile fields. */
	const FIELDS = array( 'tax_status', 'country', 'vat_id', 'tax_number' );

	/** Option with the rate history. */
	const RATE_OPTION = 'wpac_vat_rate_history';

	/**
	 * Earlier tax data of a partner.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array List of [ until, tax_status, country, vat_id, tax_number ].
	 */
	public static function profile( $affiliate_id ) {
		$h = get_option( 'wpac_tax_history_' . (int) $affiliate_id, array() );
		return is_array( $h ) ? array_values( $h ) : array();
	}

	/**
	 * Remember the tax data valid until now (called before they are replaced).
	 *
	 * @param int          $affiliate_id Partner.
	 * @param object|array $old          Previous profile (row or array).
	 */
	public static function record_profile( $affiliate_id, $old ) {
		$old = (array) $old;
		if ( empty( $old['tax_status'] ) ) {
			return; // Nothing was declared before.
		}
		$h   = self::profile( $affiliate_id );
		$h[] = array(
			'until'      => Db::now(),
			'tax_status' => (string) $old['tax_status'],
			'country'    => isset( $old['country'] ) ? (string) $old['country'] : '',
			'vat_id'     => isset( $old['vat_id'] ) ? (string) $old['vat_id'] : '',
			'tax_number' => isset( $old['tax_number'] ) ? (string) $old['tax_number'] : '',
		);
		update_option( 'wpac_tax_history_' . (int) $affiliate_id, $h, false );
	}

	/**
	 * Earlier domestic VAT rates.
	 *
	 * @return array List of [ until, rate ].
	 */
	public static function rates() {
		$h = get_option( self::RATE_OPTION, array() );
		return is_array( $h ) ? array_values( $h ) : array();
	}

	/**
	 * Remember a rate change (the old rate applied until now).
	 *
	 * @param string $old Old rate.
	 * @param string $new New rate.
	 */
	public static function record_rate( $old, $new ) {
		if ( (string) $old === (string) $new || '' === (string) $old ) {
			return;
		}
		$h   = self::rates();
		$h[] = array(
			'until' => Db::now(),
			'rate'  => (string) $old,
		);
		update_option( self::RATE_OPTION, $h, false );
		Audit::log(
			'settings.vat_rate_changed',
			'settings',
			0,
			array(
				'old' => (string) $old,
				'new' => (string) $new,
			)
		);
	}

	/**
	 * Tax data and domestic rate valid at the time of a service.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $when         Time of the service (UTC).
	 * @param array  $current      Current tax data (snapshot).
	 * @return array [ tax data, rate ]
	 */
	public static function at( $affiliate_id, $when, array $current ) {
		$data = Vat::state_at( self::profile( $affiliate_id ), (string) $when, $current );
		$rate = Vat::state_at( self::rates(), (string) $when, array( 'rate' => (string) Settings::get( 'vat_rate' ) ) );
		return array( $data, (string) $rate['rate'] );
	}

	/**
	 * Pending change of the tax data, waiting for an admin.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array|null [ new => fields, old => fields, requested_at ]
	 */
	public static function pending( $affiliate_id ) {
		$p = get_option( 'wpac_tax_pending_' . (int) $affiliate_id );
		return is_array( $p ) && isset( $p['new'] ) ? $p : null;
	}

	/**
	 * Store a pending change.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $new          New tax fields.
	 * @param array $old          Current tax fields.
	 */
	public static function set_pending( $affiliate_id, array $new, array $old ) {
		update_option(
			'wpac_tax_pending_' . (int) $affiliate_id,
			array(
				'new'          => $new,
				'old'          => $old,
				'requested_at' => Db::now(),
			),
			false
		);
	}

	/**
	 * Drop a pending change.
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function clear_pending( $affiliate_id ) {
		delete_option( 'wpac_tax_pending_' . (int) $affiliate_id );
	}

	/**
	 * Tax fields of a profile as array.
	 *
	 * @param object|array|null $profile Profile.
	 * @return array
	 */
	public static function fields( $profile ) {
		$profile = (array) $profile;
		$out     = array();
		foreach ( self::FIELDS as $f ) {
			$out[ $f ] = isset( $profile[ $f ] ) ? (string) $profile[ $f ] : '';
		}
		return $out;
	}

	/**
	 * Whether a change of the tax data by the partner needs an admin's approval: a first
	 * declaration as VAT-registered business, or any change of the VAT treatment or of the
	 * VAT ID of a (small) business.
	 *
	 * @param array $old Current tax fields.
	 * @param array $new New tax fields.
	 * @return bool
	 */
	public static function needs_approval( array $old, array $new ) {
		if ( '' === $old['tax_status'] ) {
			return 'business' === $new['tax_status'];
		}
		$operator = (string) Settings::get( 'operator_country' );
		$rate     = (string) Settings::get( 'vat_rate' );
		$t_old    = Vat::treatment( $old['tax_status'], $old['country'], $operator, $rate );
		$t_new    = Vat::treatment( $new['tax_status'], $new['country'], $operator, $rate );
		if ( $t_old !== $t_new ) {
			return true;
		}
		return in_array( $new['tax_status'], array( 'business', 'small_business' ), true ) && strtoupper( $old['vat_id'] ) !== strtoupper( $new['vat_id'] );
	}

	/**
	 * Delete the tax history of a partner (privacy eraser, after the retention period).
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function delete( $affiliate_id ) {
		delete_option( 'wpac_tax_history_' . (int) $affiliate_id );
		self::clear_pending( $affiliate_id );
	}
}
