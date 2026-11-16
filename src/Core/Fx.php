<?php
/**
 * Multi-currency rules.
 *
 * The ledger never converts: every commission, payout and SEPA amount stays in
 * the order currency. Manually maintained reference rates (setting
 * "exchange_rates", e.g. "USD=0.92" = 1 USD is 0.92 in program currency) are
 * only used for reports and for the minimum payout of foreign-currency balances.
 *
 * Pure helpers are WordPress independent (rates passed in) → unit tested.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Fx {

	/**
	 * Parse "USD=0.92" lines.
	 *
	 * @param string $text Text.
	 * @return array currency => decimal string
	 */
	public static function parse( $text ) {
		$out = array();
		foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
			if ( ! preg_match( '/^\s*([A-Za-z]{3})\s*[=:]\s*([0-9.,]+)\s*$/', $line, $m ) ) {
				continue;
			}
			$rate = Money::normalize_decimal( $m[2] );
			if ( null !== $rate && (float) $rate > 0 ) {
				$out[ strtoupper( $m[1] ) ] = $rate;
			}
		}
		return $out;
	}

	/**
	 * Convert minor units of $from into program-currency minor units.
	 *
	 * @param int    $minor   Amount.
	 * @param string $from    Currency.
	 * @param string $program Program currency.
	 * @param array  $rates   Rates (currency => program per 1 unit).
	 * @return int|null Null when no rate is known.
	 */
	public static function to_program( $minor, $from, $program, array $rates ) {
		$from    = strtoupper( (string) $from );
		$program = strtoupper( (string) $program );
		if ( $from === $program ) {
			return (int) $minor;
		}
		if ( ! isset( $rates[ $from ] ) ) {
			return null;
		}
		$rate = self::ratio( $rates[ $from ] );
		if ( null === $rate ) {
			return null;
		}
		return self::mul_div( (int) $minor, array( $rate[0], 10 ** Money::decimals( $program ) ), array( $rate[1], 10 ** Money::decimals( $from ) ) );
	}

	/**
	 * Convert program-currency minor units into $to (e.g. minimum payout).
	 *
	 * @param int    $minor   Amount in program currency.
	 * @param string $to      Target currency.
	 * @param string $program Program currency.
	 * @param array  $rates   Rates.
	 * @return int|null
	 */
	public static function from_program( $minor, $to, $program, array $rates ) {
		$to      = strtoupper( (string) $to );
		$program = strtoupper( (string) $program );
		if ( $to === $program ) {
			return (int) $minor;
		}
		if ( ! isset( $rates[ $to ] ) ) {
			return null;
		}
		$rate = self::ratio( $rates[ $to ] );
		if ( null === $rate ) {
			return null; // No usable rate (0 or too small): never a division by zero.
		}
		return self::mul_div( (int) $minor, array( $rate[1], 10 ** Money::decimals( $to ) ), array( $rate[0], 10 ** Money::decimals( $program ) ) );
	}

	/** Significant digits of a rate that are used (and kept when rates are rebased). */
	const PRECISION = 15;

	/**
	 * A rate as an exact fraction [ numerator, denominator ] of its decimal digits (at most
	 * PRECISION significant digits, at most 18 decimals) – no rounding to a fixed number of
	 * decimals, so small rates (e.g. 1 IRR = 0.0000213 EUR) keep their precision.
	 *
	 * @param string $rate Decimal string.
	 * @return array|null Null for a rate that is not positive (or too small to use).
	 */
	public static function ratio( $rate ) {
		$rate = Money::normalize_decimal( (string) $rate );
		if ( null === $rate || '-' === $rate[0] ) {
			return null;
		}
		$parts = explode( '.', $rate, 2 );
		$int   = ltrim( $parts[0], '0' );
		$frac  = substr( isset( $parts[1] ) ? $parts[1] : '', 0, 18 );
		$sig   = ltrim( $int . $frac, '0' );
		if ( strlen( $sig ) > self::PRECISION ) {
			// Too many digits: drop the least significant decimals.
			$frac = substr( $frac, 0, max( 0, strlen( $frac ) - ( strlen( $sig ) - self::PRECISION ) ) );
		}
		$num = ltrim( $int . $frac, '0' );
		if ( '' === $num || strlen( $num ) > 18 ) {
			return null;
		}
		return array( (int) $num, 10 ** strlen( $frac ) );
	}

	/**
	 * Round( $minor × Π $num / Π $den ) half away from zero: exact integer arithmetic while the
	 * products fit, floating point (about 15 significant digits) beyond.
	 *
	 * @param int   $minor Amount.
	 * @param int[] $num   Factors of the numerator.
	 * @param int[] $den   Factors of the denominator.
	 * @return int|null Null on a zero denominator or a result out of range.
	 */
	private static function mul_div( $minor, array $num, array $den ) {
		$n = $minor;
		foreach ( $num as $f ) {
			$n *= $f; // Becomes a float on integer overflow.
		}
		$d = 1;
		foreach ( $den as $f ) {
			$d *= $f;
		}
		if ( 0 == $d ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int or float.
			return null;
		}
		if ( is_int( $n ) && is_int( $d ) ) {
			return Money::round_div( $n, $d );
		}
		$q = (float) $n / (float) $d;
		if ( ! is_finite( $q ) || abs( $q ) >= (float) PHP_INT_MAX ) {
			return null;
		}
		return (int) ( $q < 0 ? -floor( -$q + 0.5 ) : floor( $q + 0.5 ) );
	}

	/**
	 * Configured rates (WordPress context).
	 *
	 * @return array
	 */
	public static function rates() {
		return self::parse( (string) Settings::get( 'exchange_rates' ) );
	}

	/**
	 * Minimum payout in a currency (null = no rate → no payout possible).
	 *
	 * @param string $currency Currency.
	 * @return int|null
	 */
	public static function min_payout( $currency ) {
		return self::from_program( (int) Settings::get( 'min_payout_minor' ), $currency, (string) Settings::get( 'currency' ), self::rates() );
	}

	/**
	 * Convert to program currency using configured rates.
	 *
	 * @param int    $minor Amount.
	 * @param string $from  Currency.
	 * @return int|null
	 */
	public static function convert( $minor, $from ) {
		return self::to_program( $minor, $from, (string) Settings::get( 'currency' ), self::rates() );
	}
}
