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
		$rate = Money::scale( $rates[ $from ], 6 );
		$num  = (int) $minor * $rate * ( 10 ** Money::decimals( $program ) );
		$den  = 1000000 * ( 10 ** Money::decimals( $from ) );
		return Money::round_div( $num, $den );
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
		$rate = Money::scale( $rates[ $to ], 6 );
		$num  = (int) $minor * 1000000 * ( 10 ** Money::decimals( $to ) );
		$den  = $rate * ( 10 ** Money::decimals( $program ) );
		return Money::round_div( $num, $den );
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
