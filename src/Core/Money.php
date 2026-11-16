<?php
/**
 * Integer money helpers. Amounts are always stored as minor units (int) with an
 * ISO 4217 currency code; floats are never persisted.
 *
 * This class has no WordPress dependency so it can be unit tested.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Money {

	/**
	 * Currencies whose minor unit differs from 2 decimals (ISO 4217), plus the precision of the
	 * codes WooCommerce offers that are not (or no longer) in ISO 4217. ContractApi::currencies()
	 * accepts a code only when it is in its ISO list or in this map, so every accepted currency
	 * has a known precision (an unknown code would be booked with 2 decimals: 0.0045 BTC = 0).
	 */
	const DECIMALS = array(
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
		// Not in ISO 4217 (WooCommerce codes): Bitcoin, Iranian toman (no subunit in use), the old
		// Belarusian ruble, crown dependency pounds, Transnistrian ruble, renminbi alias and
		// withdrawn codes.
		'BTC' => 8, 'IRT' => 0, 'BYR' => 0, 'GGP' => 2, 'IMP' => 2, 'JEP' => 2, 'PRB' => 2, 'RMB' => 2,
		'CUC' => 2, 'HRK' => 2, 'SLL' => 2, 'VEF' => 2, 'MRO' => 2, 'STD' => 2,
	);

	/**
	 * Whether DECIMALS names the precision of a currency (needed for codes outside the ISO list).
	 *
	 * @param string $currency Code.
	 * @return bool
	 */
	public static function has_decimals( $currency ) {
		return isset( self::DECIMALS[ strtoupper( (string) $currency ) ] );
	}

	/**
	 * Number of decimals of a currency.
	 *
	 * @param string $currency ISO code.
	 * @return int
	 */
	public static function decimals( $currency ) {
		$currency = strtoupper( (string) $currency );
		return isset( self::DECIMALS[ $currency ] ) ? self::DECIMALS[ $currency ] : 2;
	}

	/**
	 * Normalize user input like "10,5", "1.234,56" or "1,234.56" to "1234.56".
	 *
	 * @param string $value Raw value.
	 * @return string|null Canonical decimal string or null when invalid.
	 */
	public static function normalize_decimal( $value ) {
		$value = trim( str_replace( array( ' ', "\u{00A0}", "'" ), '', (string) $value ) );
		if ( '' === $value ) {
			return null;
		}
		$neg = false;
		if ( '-' === $value[0] ) {
			$neg   = true;
			$value = substr( $value, 1 );
		}
		$last_comma = strrpos( $value, ',' );
		$last_dot   = strrpos( $value, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			$dec   = $last_comma > $last_dot ? ',' : '.';
			$thou  = ',' === $dec ? '.' : ',';
			$value = str_replace( $thou, '', $value );
			$value = str_replace( $dec, '.', $value );
		} elseif ( false !== $last_comma ) {
			$value = str_replace( ',', '.', $value );
		}
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $value ) && ! preg_match( '/^\.\d+$/', $value ) ) {
			return null;
		}
		if ( '.' === $value[0] ) {
			$value = '0' . $value;
		}
		return ( $neg ? '-' : '' ) . $value;
	}

	/**
	 * Parse an amount typed into a form ("50", "50,00", "1.000,50", "1,000.50", "1 000,50",
	 * "EUR 25") for a currency. Thousands separators are accepted only where they are
	 * unambiguous: groups of exactly three digits, followed by a decimal part with the other
	 * separator ("1.000,50", "1,000.50") or repeated ("1.000.000"). A lone group of three digits
	 * ("1.000", "12,345") is ambiguous (problem 'ambiguous') – except for currencies without
	 * decimals (JPY: "1.500" can only be 1500) and with three or more decimals (KWD, BTC: "5.000"
	 * is the amount as shown by to_decimal(), 5 dinars). A misplaced separator ("12.50,00",
	 * "1.2.3") is invalid.
	 *
	 * @param string      $raw      Input.
	 * @param string      $currency ISO code ('' = unknown: a lone group is ambiguous).
	 * @param string|null $problem  Set to '' (fine), 'invalid' or 'ambiguous'.
	 * @return string|null Canonical decimal string or null when invalid.
	 */
	public static function parse_amount( $raw, $currency = '', &$problem = null ) {
		$problem = '';
		$value   = str_replace( array( '€', '$', '£', '¥', "\u{00A0}", "\u{202F}" ), array( '', '', '', '', ' ', ' ' ), (string) $raw );
		$value   = trim( (string) preg_replace( '/^[A-Za-z]{3}\s*|\s*[A-Za-z]{3}$/', '', trim( $value ) ) );
		$value   = str_replace( array( ' ', "'" ), '', $value );
		$neg     = '' !== $value && '-' === $value[0];
		$body    = $neg ? substr( $value, 1 ) : $value;
		$comma   = strrpos( $body, ',' );
		$dot     = strrpos( $body, '.' );
		if ( false !== $comma && false !== $dot ) {
			$dec  = $comma > $dot ? ',' : '.';
			$thou = ',' === $dec ? '.' : ',';
			if ( ! preg_match( '/^\d{1,3}(?:' . preg_quote( $thou, '/' ) . '\d{3})+' . preg_quote( $dec, '/' ) . '\d+$/', $body ) ) {
				$problem = 'invalid';
				return null;
			}
			$body = str_replace( $thou, '', $body );
		} elseif ( false !== $comma || false !== $dot ) {
			$sep = false !== $comma ? ',' : '.';
			$q   = preg_quote( $sep, '/' );
			if ( substr_count( $body, $sep ) > 1 ) {
				if ( ! preg_match( '/^\d{1,3}(?:' . $q . '\d{3}){2,}$/', $body ) ) {
					$problem = 'invalid';
					return null;
				}
				$body = str_replace( $sep, '', $body );
			} elseif ( preg_match( '/^[1-9]\d{0,2}' . $q . '\d{3}$/', $body ) ) {
				$decimals = '' === (string) $currency ? 2 : self::decimals( $currency );
				if ( 0 === $decimals ) {
					$body = str_replace( $sep, '', $body );
				} elseif ( $decimals < 3 ) {
					$problem = 'ambiguous';
					return null;
				}
			}
		}
		$out = self::normalize_decimal( ( $neg ? '-' : '' ) . $body );
		if ( null === $out ) {
			$problem = 'invalid';
		}
		return $out;
	}

	/**
	 * Parse an amount typed into a form (parse_amount()) to minor units of a currency, without
	 * rounding: input with more significant decimals than the currency has ("12,3456" or "0,125"
	 * in EUR) is reported instead of rounded, a lone group of three digits ("1.000", "12,345")
	 * as ambiguous (see parse_amount()).
	 *
	 * @param string      $raw      Input.
	 * @param string      $currency ISO code.
	 * @param string|null $problem  Set to '' (fine), 'invalid', 'ambiguous' or 'precision'.
	 * @return int|null Minor units, null when not valid.
	 */
	public static function parse_minor( $raw, $currency, &$problem = null ) {
		$value = self::parse_amount( (string) $raw, (string) $currency, $problem );
		if ( null === $value ) {
			return null;
		}
		$parts = explode( '.', ltrim( $value, '-' ), 2 );
		if ( isset( $parts[1] ) && strlen( rtrim( $parts[1], '0' ) ) > self::decimals( $currency ) ) {
			$problem = 'precision';
			return null;
		}
		return self::to_minor( $value, $currency );
	}

	/**
	 * Convert a decimal amount (string preferred, float accepted) to minor units,
	 * rounding half away from zero.
	 *
	 * @param string|float|int $amount   Amount.
	 * @param string           $currency ISO code.
	 * @return int
	 */
	public static function to_minor( $amount, $currency ) {
		return self::scale( $amount, self::decimals( $currency ) );
	}

	/**
	 * Scale a decimal to an integer with the given number of decimals (half away from zero).
	 *
	 * @param string|float|int $amount   Amount.
	 * @param int              $decimals Decimals.
	 * @return int
	 */
	public static function scale( $amount, $decimals ) {
		if ( is_float( $amount ) ) {
			// Use a precise string representation of the float first.
			$amount = sprintf( '%.' . ( $decimals + 6 ) . 'F', $amount );
		}
		$str = self::normalize_decimal( (string) $amount );
		if ( null === $str ) {
			return 0;
		}
		$neg = false;
		if ( '-' === $str[0] ) {
			$neg = true;
			$str = substr( $str, 1 );
		}
		$parts = explode( '.', $str, 2 );
		$int   = ltrim( $parts[0], '0' );
		$frac  = isset( $parts[1] ) ? $parts[1] : '';
		$frac  = str_pad( $frac, $decimals + 1, '0' );
		$keep  = substr( $frac, 0, $decimals );
		$next  = (int) $frac[ $decimals ];

		$value = (int) ( ( '' === $int ? '0' : $int ) . $keep );
		if ( $next >= 5 ) {
			++$value;
		}
		return $neg ? -$value : $value;
	}

	/**
	 * Minor units to a canonical decimal string ("1234.50").
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function to_decimal( $minor, $currency ) {
		$dec   = self::decimals( $currency );
		$minor = (int) $minor;
		$neg   = $minor < 0;
		$abs   = (string) abs( $minor );
		if ( 0 === $dec ) {
			return ( $neg ? '-' : '' ) . $abs;
		}
		$abs = str_pad( $abs, $dec + 1, '0', STR_PAD_LEFT );
		return ( $neg ? '-' : '' ) . substr( $abs, 0, -$dec ) . '.' . substr( $abs, -$dec );
	}

	/**
	 * Human readable amount, e.g. "1.234,50 EUR" (uses WP locale when available).
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function format( $minor, $currency ) {
		$dec   = self::decimals( $currency );
		$float = (float) self::to_decimal( $minor, $currency );
		$num   = function_exists( 'number_format_i18n' ) ? number_format_i18n( $float, $dec ) : number_format( $float, $dec, ',', '.' );
		return $num . "\u{00A0}" . strtoupper( (string) $currency );
	}

	/**
	 * Percentage of a minor amount. Rate is a decimal percent string ("12.5").
	 * Up to 4 decimals of the rate are honored; result rounds half away from zero.
	 *
	 * @param int    $base_minor Base.
	 * @param string $rate       Percent.
	 * @return int
	 */
	public static function percent_of( $base_minor, $rate ) {
		$rate_scaled = self::scale( (string) $rate, 4 ); // 12.5% => 125000.
		return self::round_div( (int) $base_minor * $rate_scaled, 1000000 );
	}

	/**
	 * Pro-rata share: $amount * $part / $whole, rounded half away from zero.
	 *
	 * @param int $amount Amount.
	 * @param int $part   Part.
	 * @param int $whole  Whole.
	 * @return int
	 */
	public static function prorate( $amount, $part, $whole ) {
		if ( 0 === (int) $whole ) {
			return 0;
		}
		return self::round_div( (int) $amount * (int) $part, (int) $whole );
	}

	/**
	 * Integer division rounding half away from zero.
	 *
	 * @param int $num Numerator.
	 * @param int $den Denominator (non-zero).
	 * @return int
	 */
	public static function round_div( $num, $den ) {
		$neg = ( $num < 0 ) xor ( $den < 0 );
		$num = abs( $num );
		$den = abs( $den );
		$q   = intdiv( $num, $den );
		if ( ( $num % $den ) * 2 >= $den ) {
			++$q;
		}
		return $neg ? -$q : $q;
	}
}
