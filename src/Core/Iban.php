<?php
/**
 * IBAN helpers (no WordPress dependency).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

final class Iban {

	/**
	 * Strip spaces and upper-case.
	 *
	 * @param string $iban IBAN.
	 * @return string
	 */
	public static function normalize( $iban ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
	}

	/**
	 * ISO 13616 mod-97 check.
	 *
	 * @param string $iban IBAN.
	 * @return bool
	 */
	public static function is_valid( $iban ) {
		$iban = self::normalize( $iban );
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}
		$moved   = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric = '';
		$len     = strlen( $moved );
		for ( $i = 0; $i < $len; $i++ ) {
			$c        = $moved[ $i ];
			$numeric .= ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
		}
		$rest = 0;
		foreach ( str_split( $numeric, 7 ) as $chunk ) {
			$rest = (int) ( $rest . $chunk ) % 97;
		}
		return 1 === $rest;
	}

	/**
	 * Masked display, e.g. "DE89 •••• •••• 3000".
	 *
	 * @param string $last4   Last 4 chars.
	 * @param string $country Country code.
	 * @return string
	 */
	public static function mask( $last4, $country = '' ) {
		if ( '' === (string) $last4 ) {
			return '';
		}
		return trim( $country . ' •••• •••• ' . $last4 );
	}
}
