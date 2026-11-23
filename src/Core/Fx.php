<?php
/**
 * Multi-currency rules.
 *
 * The ledger never converts: every commission, payout and SEPA amount stays in
 * the order currency. Manually maintained reference rates (setting
 * "exchange_rates", e.g. "USD=0.92" = 1 USD is 0.92 in program currency) are
 * only used for reports and for the minimum payout of foreign-currency balances.
 *
 * Since plugin 0.21.0 (SET-AC-29) the settings page shows the rates as a table; the stored format
 * stays the same text. Next to it: "exchange_rates_date" (date of the rates, set automatically
 * when they change) and "min_payout_currency" ("USD=50.00" lines: an own minimum payout per
 * currency, amounts in that currency; without one the program minimum is converted). Rates are
 * never fetched automatically (that is a feature of Affiliate Captain Pro).
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
	 * Parse "USD=50.00" lines of minimum payouts per currency (amounts in that currency, stored
	 * with a decimal point).
	 *
	 * @param string|array $value Stored text, or currency => decimal amount (from wpac_setting).
	 * @return array currency => minor units of that currency
	 */
	public static function parse_minimums( $value ) {
		$lines = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $cur => $amount ) {
				if ( is_string( $cur ) && is_scalar( $amount ) ) {
					$lines[] = $cur . '=' . $amount;
				}
			}
		} else {
			$lines = preg_split( '/[\r\n;]+/', (string) $value );
		}
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\s*([A-Za-z]{3})\s*[=:]\s*([0-9.,]+)\s*$/', (string) $line, $m ) ) {
				continue;
			}
			$amount = Money::normalize_decimal( $m[2] );
			if ( null !== $amount ) {
				$out[ strtoupper( $m[1] ) ] = Money::to_minor( $amount, strtoupper( $m[1] ) );
			}
		}
		return $out;
	}

	/**
	 * Date of the reference rates (Y-m-d, '' = unknown, e.g. rates of a version before 0.21.0).
	 *
	 * @return string
	 */
	public static function rates_date() {
		$date = (string) Settings::get( 'exchange_rates_date' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * "Rates as of …" for places that show converted amounts ('' without rates or date).
	 *
	 * @return string
	 */
	public static function rates_note() {
		$date = self::rates_date();
		if ( '' === $date || ! self::rates() ) {
			return '';
		}
		/* translators: %s: date */
		return sprintf( __( 'Reference exchange rates as of %s', 'affiliate-captain' ), self::date_text( $date ) );
	}

	/**
	 * A date of the rates in the site's date format.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function date_text( $date ) {
		$ts = strtotime( (string) $date . ' 12:00:00 UTC' );
		return false === $ts ? (string) $date : (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Table rows of the settings page → the stored texts (rates, minimums). Empty rows are
	 * skipped; an incomplete row becomes a line the checks of Settings::sanitize() reject.
	 *
	 * @param array $rows List of { currency, rate, min }.
	 * @return string[] [ exchange_rates text, min_payout_currency text ]
	 */
	public static function rows_to_text( array $rows ) {
		$rates = array();
		$mins  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cell = static function ( $name ) use ( $row ) {
				return isset( $row[ $name ] ) && is_scalar( $row[ $name ] ) ? trim( str_replace( array( "\r", "\n", ';' ), ' ', (string) $row[ $name ] ) ) : '';
			};
			$cur  = strtoupper( $cell( 'currency' ) );
			$rate = $cell( 'rate' );
			$min  = $cell( 'min' );
			if ( '' === $cur && '' === $rate && '' === $min ) {
				continue;
			}
			if ( '' !== $rate || '' === $min ) {
				$rates[] = $cur . '=' . $rate;
			}
			if ( '' !== $min ) {
				$mins[] = $cur . '=' . $min;
			}
		}
		return array( implode( "\n", $rates ), implode( "\n", $mins ) );
	}

	/**
	 * Stored texts (or rejected input) → table rows, currency order of the rates first.
	 *
	 * @param string $rates Rates text.
	 * @param string $mins  Minimums text.
	 * @return array List of { currency, rate, min }.
	 */
	public static function text_to_rows( $rates, $mins ) {
		$rows  = array();
		$split = static function ( $text ) {
			$out = array();
			foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$parts = preg_split( '/\s*[=:]\s*/', trim( $line ), 2 );
				$out[] = array( strtoupper( trim( $parts[0] ) ), isset( $parts[1] ) ? trim( $parts[1] ) : '' );
			}
			return $out;
		};
		foreach ( $split( $rates ) as $pair ) {
			$rows[ '' !== $pair[0] ? $pair[0] : '#' . count( $rows ) ] = array(
				'currency' => $pair[0],
				'rate'     => $pair[1],
				'min'      => '',
			);
		}
		foreach ( $split( $mins ) as $pair ) {
			$key = '' !== $pair[0] ? $pair[0] : '#' . count( $rows );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'currency' => $pair[0],
					'rate'     => '',
					'min'      => '',
				);
			}
			$rows[ $key ]['min'] = $pair[1];
		}
		return array_values( $rows );
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
	 * Minimum payout in a currency (null = no rate and no own minimum → no payout possible).
	 *
	 * @param string $currency     Currency.
	 * @param int    $affiliate_id Partner (0 = none; with a partner the minimum passes the filter
	 *                             wpac_setting with the partner's program).
	 * @return int|null
	 */
	public static function min_payout( $currency, $affiliate_id = 0 ) {
		$info = self::min_payout_info( $currency, (int) $affiliate_id > 0 ? array( 'affiliate_id' => (int) $affiliate_id ) : array() );
		return $info['minor'];
	}

	/**
	 * Maximum payout of a partner in a currency (SET-AC-19): 0 = no limit, null = no rate to
	 * convert the limit (then nothing can be checked, like the minimum). Per program through the
	 * filter wpac_setting (key max_payout_minor).
	 *
	 * @param string $currency     Currency.
	 * @param int    $affiliate_id Partner (0 = general).
	 * @return int|null
	 */
	public static function max_payout( $currency, $affiliate_id = 0 ) {
		$max = max( 0, (int) Settings::for_affiliate( 'max_payout_minor', (int) $affiliate_id ) );
		if ( 0 === $max ) {
			return 0;
		}
		$converted = self::from_program( $max, $currency, (string) Settings::get( 'currency' ), self::rates() );
		return null === $converted ? null : max( 1, (int) $converted ); // A tiny limit never turns into "no limit".
	}

	/**
	 * Minimum payout of a partner program in a currency (filter wpac_setting with the program as
	 * context, no partner; null = no rate and no own minimum).
	 *
	 * @param string $currency Currency.
	 * @param string $program  Program key ('' = default program).
	 * @return int|null
	 */
	public static function min_payout_program( $currency, $program ) {
		$info = self::min_payout_info( $currency, self::program_context( $program ) );
		return $info['minor'];
	}

	/**
	 * Context of wpac_setting for a program key ("group:7" also names the group).
	 *
	 * @param string $program Program key.
	 * @return array
	 */
	private static function program_context( $program ) {
		$context = array( 'program' => (string) $program );
		if ( preg_match( '/^group:([1-9]\d{0,9})$/', (string) $program, $m ) ) {
			$context['group_id'] = (int) $m[1];
		}
		return $context;
	}

	/**
	 * Minimum payout in a currency and where it comes from (SET-AC-29).
	 *
	 * Order: the program currency uses the program minimum (min_payout_minor). Another currency
	 * uses its own minimum (min_payout_currency) if one is set, else the program minimum converted
	 * with the reference rate. With a context both values pass the filter wpac_setting: when an
	 * add-on gives a program its own program minimum but no own per-currency minimums, the
	 * per-currency minimums of the settings (they belong to the default program) are not used for
	 * it – its minimum is converted instead.
	 *
	 * @param string $currency Currency.
	 * @param array  $context  Context of wpac_setting (empty = the stored settings).
	 * @return array { minor: int|null, source: 'program'|'currency'|'converted'|'none' }
	 */
	public static function min_payout_info( $currency, array $context = array() ) {
		$currency = strtoupper( (string) $currency );
		$program  = strtoupper( (string) Settings::get( 'currency' ) );
		$minimum  = max( 0, (int) ( $context ? Settings::get( 'min_payout_minor', $context ) : Settings::get( 'min_payout_minor' ) ) );
		if ( $currency === $program ) {
			return array(
				'minor'  => $minimum,
				'source' => 'program',
			);
		}
		$stored = Settings::get( 'min_payout_currency' );
		$own    = $stored;
		if ( $context ) {
			$own = Settings::get( 'min_payout_currency', $context );
			if ( $own === $stored && $minimum !== max( 0, (int) Settings::get( 'min_payout_minor' ) ) ) {
				$own = ''; // The program has its own minimum: the general per-currency values do not apply.
			}
		}
		$own = self::parse_minimums( $own );
		if ( isset( $own[ $currency ] ) ) {
			return array(
				'minor'  => max( 0, (int) $own[ $currency ] ),
				'source' => 'currency',
			);
		}
		$converted = self::from_program( $minimum, $currency, $program, self::rates() );
		return array(
			'minor'  => $converted,
			'source' => null === $converted ? 'none' : 'converted',
		);
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
