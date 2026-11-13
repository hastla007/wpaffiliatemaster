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
