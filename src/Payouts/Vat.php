<?php
/**
 * VAT treatment of commission credit notes (self-billing, §14 Abs. 2 UStG).
 * Commission rates are net amounts; VAT is added on top where applicable.
 * No WordPress dependency → unit tested.
 *
 * This is a technical default for a German operator; the tax treatment must be
 * confirmed by a tax advisor (the result can be filtered: wpac_vat_treatment).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Money;

final class Vat {

	/** Treatment modes. */
	const STANDARD       = 'standard';        // Domestic business partner: VAT shown and paid.
	const REVERSE_CHARGE = 'reverse_charge';  // Foreign business partner: recipient owes the VAT (§13b UStG).
	const SMALL_BUSINESS = 'small_business';  // Small business (§19 UStG): no VAT.
	const PRIVATE_PERSON = 'private';         // Private individual: no VAT.
	const NONE           = 'none';            // Not a service (repayment received, claim written off): outside the scope of VAT.

	/** Booking kinds that are no service of the partner (never taxed on a credit note). */
	const NON_TAXABLE_KINDS = array( 'repayment', 'writeoff' );

	/**
	 * VAT ID formats of the EU member states (VIES country code => pattern of the number part).
	 */
	const FORMATS = array(
		'AT' => 'U[0-9]{8}',
		'BE' => '[01][0-9]{9}',
		'BG' => '[0-9]{9,10}',
		'CY' => '[0-9]{8}[A-Z]',
		'CZ' => '[0-9]{8,10}',
		'DE' => '[0-9]{9}',
		'DK' => '[0-9]{8}',
		'EE' => '[0-9]{9}',
		'EL' => '[0-9]{9}',
		'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
		'FI' => '[0-9]{8}',
		'FR' => '[A-HJ-NP-Z0-9]{2}[0-9]{9}',
		'HR' => '[0-9]{11}',
		'HU' => '[0-9]{8}',
		'IE' => '[0-9][A-Z0-9+*][0-9]{5}[A-Z]{1,2}',
		'IT' => '[0-9]{11}',
		'LT' => '([0-9]{9}|[0-9]{12})',
		'LU' => '[0-9]{8}',
		'LV' => '[0-9]{11}',
		'MT' => '[0-9]{8}',
		'NL' => '[0-9]{9}B[0-9]{2}',
		'PL' => '[0-9]{10}',
		'PT' => '[0-9]{9}',
		'RO' => '[0-9]{2,10}',
		'SE' => '[0-9]{12}',
		'SI' => '[0-9]{8}',
		'SK' => '[0-9]{10}',
		'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
	);

	/**
	 * Determine the treatment.
	 *
	 * @param string $tax_status       private|small_business|business.
	 * @param string $partner_country  ISO country of the partner.
	 * @param string $operator_country ISO country of the operator.
	 * @param string $rate             Domestic VAT rate in percent.
	 * @return array mode, rate (decimal string, "0" without VAT)
	 */
	public static function treatment( $tax_status, $partner_country, $operator_country, $rate ) {
		$partner_country  = strtoupper( (string) $partner_country );
		$operator_country = strtoupper( (string) $operator_country );
		if ( 'small_business' === $tax_status && '' !== $partner_country && '' !== $operator_country && $partner_country !== $operator_country ) {
			// A foreign small business is still a business: the service is taxable where the
			// operator is and the operator owes the VAT (§13b UStG). The domestic small-business
			// rule of the partner's country does not apply here (EU SME scheme: confirm with a tax advisor).
			return array(
				'mode' => self::REVERSE_CHARGE,
				'rate' => '0',
			);
		}
		if ( 'business' === $tax_status ) {
			if ( '' === $partner_country || $partner_country === $operator_country ) {
				return array(
					'mode' => self::STANDARD,
					'rate' => (string) $rate,
				);
			}
			return array(
				'mode' => self::REVERSE_CHARGE,
				'rate' => '0',
			);
		}
		return array(
			'mode' => 'small_business' === $tax_status ? self::SMALL_BUSINESS : self::PRIVATE_PERSON,
			'rate' => '0',
		);
	}

	/**
	 * Treatment of one booking: repayments and write-offs are no service (not taxable);
	 * everything else gets the treatment of the partner at the time of the service.
	 *
	 * @param string $kind      Booking kind.
	 * @param array  $treatment Treatment of the partner (mode, rate).
	 * @return array mode, rate
	 */
	public static function for_kind( $kind, array $treatment ) {
		if ( in_array( (string) $kind, self::NON_TAXABLE_KINDS, true ) ) {
			return array(
				'mode' => self::NONE,
				'rate' => '0',
			);
		}
		return $treatment;
	}

	/**
	 * Value valid at a point in time from a change history: each entry holds the values that
	 * were valid until its "until" (UTC, Y-m-d H:i:s); later times use the current values.
	 * Entries with an empty tax status (nothing declared yet) are skipped.
	 *
	 * @param array  $history Entries [ until, ... ] (any order).
	 * @param string $when    Time of the service (UTC, Y-m-d H:i:s).
	 * @param array  $current Current values.
	 * @return array
	 */
	public static function state_at( array $history, $when, array $current ) {
		usort(
			$history,
			static function ( $a, $b ) {
				return strcmp( (string) $a['until'], (string) $b['until'] );
			}
		);
		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['until'] ) || (string) $when >= (string) $entry['until'] ) {
				continue;
			}
			if ( array_key_exists( 'tax_status', $entry ) && '' === (string) $entry['tax_status'] ) {
				continue;
			}
			return array_merge( $current, array_diff_key( $entry, array( 'until' => 1 ) ) );
		}
		return $current;
	}

	/**
	 * Whether a VAT ID has a valid format and – for partners in an EU member state – the
	 * prefix of that country. VAT IDs outside the EU are only checked roughly.
	 *
	 * @param string $vat_id  VAT ID (normalised: upper case, letters and digits).
	 * @param string $country ISO country of the partner.
	 * @return string '' = fine, otherwise the problem: format|prefix.
	 */
	public static function vat_id_problem( $vat_id, $country ) {
		$vat_id  = strtoupper( preg_replace( '/[^A-Za-z0-9+*]/', '', (string) $vat_id ) );
		$country = strtoupper( (string) $country );
		$country = 'GR' === $country ? 'EL' : $country;
		$prefix  = substr( $vat_id, 0, 2 );
		$prefix  = 'GR' === $prefix ? 'EL' : $prefix;
		if ( isset( self::FORMATS[ $country ] ) && $prefix !== $country ) {
			return 'prefix';
		}
		if ( isset( self::FORMATS[ $prefix ] ) ) {
			return 1 === preg_match( '/^' . self::FORMATS[ $prefix ] . '$/', substr( $vat_id, 2 ) ) ? '' : 'format';
		}
		// Outside the EU (CHE…, GB…, NO…): letters and digits, 4–20 characters.
		return 1 === preg_match( '/^[A-Z0-9]{4,20}$/', $vat_id ) ? '' : 'format';
	}

	/**
	 * Small business limit check (§19 UStG since 2025: previous year at most 25,000 €, current
	 * year at most 100,000 €). Amounts in minor units of the same currency.
	 *
	 * @param int   $previous_year Credit notes of the previous calendar year.
	 * @param int   $current_year  Credit notes of the current calendar year so far.
	 * @param array $limits        [ previous, current ] in minor units.
	 * @return string '' | previous | current
	 */
	public static function small_business_exceeded( $previous_year, $current_year, array $limits ) {
		if ( (int) $current_year > (int) $limits[1] ) {
			return 'current';
		}
		if ( (int) $previous_year > (int) $limits[0] ) {
			return 'previous';
		}
		return '';
	}

	/**
	 * Totals of a set of bookings with their treatments: VAT is computed per rate.
	 *
	 * @param array $rows  Rows with id and amount_minor.
	 * @param array $treat Commission ID => [ mode, rate ].
	 * @return array [ net, vat, gross ]
	 */
	public static function totals( array $rows, array $treat ) {
		$net    = 0;
		$groups = array();
		foreach ( $rows as $r ) {
			$amount = (int) $r->amount_minor;
			$rate   = isset( $treat[ (int) $r->id ] ) ? (string) $treat[ (int) $r->id ]['rate'] : '0';
			$net   += $amount;
			$groups[ $rate ] = ( isset( $groups[ $rate ] ) ? $groups[ $rate ] : 0 ) + $amount;
		}
		$vat = 0;
		foreach ( $groups as $rate => $sum ) {
			$vat += self::amount( $sum, (string) $rate );
		}
		return array( $net, $vat, $net + $vat );
	}

	/**
	 * VAT amount for a net amount.
	 *
	 * @param int    $net_minor Net.
	 * @param string $rate      Rate.
	 * @return int
	 */
	public static function amount( $net_minor, $rate ) {
		return Money::percent_of( (int) $net_minor, (string) $rate );
	}
}
