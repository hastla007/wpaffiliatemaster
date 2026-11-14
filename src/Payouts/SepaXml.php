<?php
/**
 * SEPA credit transfer file (pain.001.001.03, widely accepted by EU banks).
 * No WordPress dependency → unit tested. Check with your bank whether it
 * requires a different pain version (e.g. pain.001.001.09) before production use.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Iban;
use WPAffiliateCaptain\Core\Money;

final class SepaXml {

	/**
	 * Restrict text to the SEPA (Latin) character set. Letters outside it are transliterated
	 * ("Łukasz" → "Lukasz", "Øyvind Ærø" → "Oyvind Aero", Cyrillic → Latin) instead of being
	 * dropped: the bank compares the name with the account holder (Verification of Payee).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function clean( $text, $max ) {
		$text = strtr( (string) $text, self::transliteration_map() );
		if ( preg_match( '/[^\x00-\x7F]/', $text ) ) {
			if ( function_exists( 'transliterator_transliterate' ) ) {
				$latin = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $text );
				$text  = is_string( $latin ) ? $latin : $text;
			} elseif ( function_exists( 'remove_accents' ) ) {
				$text = remove_accents( $text );
			} elseif ( function_exists( 'iconv' ) ) {
				$ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid bytes are dropped.
				$text  = is_string( $ascii ) ? $ascii : $text;
			}
		}
		$text = preg_replace( '/[^A-Za-z0-9\/\-\?:\(\)\.,\'\+ ]/', '', $text );
		return substr( trim( preg_replace( '/\s+/', ' ', $text ) ), 0, $max );
	}

	/**
	 * Transliteration of letters outside the SEPA character set that generic transliteration
	 * gets wrong or does not cover everywhere (German umlauts, Nordic, Polish and Czech letters,
	 * Cyrillic).
	 *
	 * @return array
	 */
	private static function transliteration_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array(
			'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'SS',
			'Æ' => 'Ae', 'æ' => 'ae', 'Ø' => 'O', 'ø' => 'o', 'Å' => 'A', 'å' => 'a', 'Œ' => 'Oe', 'œ' => 'oe',
			'Ł' => 'L', 'ł' => 'l', 'Đ' => 'D', 'đ' => 'd', 'Ð' => 'D', 'ð' => 'd', 'Þ' => 'Th', 'þ' => 'th', 'ı' => 'i',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
			'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Á' => 'A', 'À' => 'A', 'Ó' => 'O', 'Í' => 'I', 'Ú' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
			'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ń' => 'n', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ń' => 'N', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			'č' => 'c', 'š' => 's', 'ž' => 'z', 'ř' => 'r', 'ě' => 'e', 'ů' => 'u', 'Č' => 'C', 'Š' => 'S', 'Ž' => 'Z', 'Ř' => 'R',
		);
		$cyr = array(
			'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y',
			'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
			'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
			'Є' => 'Ye', 'І' => 'I', 'Ї' => 'Yi', 'Ґ' => 'G',
		);
		foreach ( $cyr as $upper => $latin ) {
			$map[ $upper ] = $latin;
			$lower         = function_exists( 'mb_strtolower' ) ? mb_strtolower( $upper, 'UTF-8' ) : $upper;
			$map[ $lower ] = strtolower( $latin );
		}
		return $map;
	}

	/**
	 * Easter Sunday (Gregorian, anonymous Gauss/Meeus algorithm) as UTC timestamp.
	 *
	 * @param int $year Year.
	 * @return int
	 */
	public static function easter( $year ) {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return gmmktime( 0, 0, 0, $month, $day, $year );
	}

	/**
	 * Requested execution date: next TARGET2 business day after $from
	 * (skips weekends, 1 Jan, Good Friday, Easter Monday, 1 May, 25/26 Dec).
	 *
	 * @param int $from Timestamp.
	 * @return string Y-m-d
	 */
	public static function next_business_day( $from ) {
		$ts = (int) $from + 86400;
		for ( $i = 0; $i < 10; $i++ ) {
			$day    = gmdate( 'Y-m-d', $ts );
			$easter = self::easter( (int) gmdate( 'Y', $ts ) );
			$closed = in_array( gmdate( 'm-d', $ts ), array( '01-01', '05-01', '12-25', '12-26' ), true )
				|| gmdate( 'Y-m-d', $easter - 2 * 86400 ) === $day
				|| gmdate( 'Y-m-d', $easter + 86400 ) === $day;
			if ( (int) gmdate( 'N', $ts ) < 6 && ! $closed ) {
				break;
			}
			$ts += 86400;
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Whether the PHP extension "dom" is available (needed to build the XML file).
	 *
	 * @return bool
	 */
	public static function dom_available() {
		return class_exists( 'DOMDocument', false );
	}

	/**
	 * Build the XML document.
	 *
	 * @param array  $debtor    name, iban, bic.
	 * @param array  $transfers List of [ end_to_end_id, name, iban, bic, amount_minor, remittance ].
	 * @param string $msg_id    Unique message ID.
	 * @param string $exec_date Requested execution date (Y-m-d).
	 * @param string $created   Creation timestamp (ISO 8601).
	 * @return string
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public static function build( array $debtor, array $transfers, $msg_id, $exec_date, $created = '' ) {
		if ( ! self::dom_available() ) {
			// Exception texts are escaped (they may end up in an admin notice); without WordPress
			// (unit tests) the untranslated text is used.
			throw new \InvalidArgumentException( function_exists( 'esc_html__' ) ? esc_html__( 'The SEPA XML export needs the PHP extension "dom" (DOMDocument), which is not installed on this server. Ask your hosting provider to enable it, or use the transfer list (CSV) instead.', 'affiliate-captain' ) : 'The SEPA XML export needs the PHP extension dom (DOMDocument), which is not installed on this server. Ask your hosting provider to enable it, or use the transfer list (CSV) instead.' );
		}
		if ( ! $transfers ) {
			throw new \InvalidArgumentException( 'No transfers.' );
		}
		if ( ! Iban::is_valid( $debtor['iban'] ) ) {
			throw new \InvalidArgumentException( 'Invalid debtor IBAN.' );
		}
		$total = 0;
		foreach ( $transfers as $t ) {
			if ( (int) $t['amount_minor'] <= 0 ) {
				throw new \InvalidArgumentException( 'Transfer amounts must be positive.' );
			}
			if ( ! Iban::is_valid( $t['iban'] ) ) {
				throw new \InvalidArgumentException( function_exists( 'esc_html' ) ? esc_html( 'Invalid creditor IBAN for ' . $t['end_to_end_id'] ) : 'Invalid creditor IBAN.' );
			}
			$total += (int) $t['amount_minor'];
		}
		$created = $created ? $created : gmdate( 'Y-m-d\TH:i:s' );

		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$root = $doc->createElementNS( 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', 'Document' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
		$doc->appendChild( $root );
		$init = $root->appendChild( $doc->createElement( 'CstmrCdtTrfInitn' ) );

		$el = static function ( \DOMNode $parent, $name, $value = null ) use ( $doc ) {
			$node = null === $value ? $doc->createElement( $name ) : $doc->createElement( $name );
			if ( null !== $value ) {
				$node->appendChild( $doc->createTextNode( (string) $value ) );
			}
			$parent->appendChild( $node );
			return $node;
		};

		$hdr = $el( $init, 'GrpHdr' );
		$el( $hdr, 'MsgId', self::clean( $msg_id, 35 ) );
		$el( $hdr, 'CreDtTm', $created );
		$el( $hdr, 'NbOfTxs', count( $transfers ) );
		$el( $hdr, 'CtrlSum', Money::to_decimal( $total, 'EUR' ) );
		$el( $el( $hdr, 'InitgPty' ), 'Nm', self::clean( $debtor['name'], 70 ) );

		$pmt = $el( $init, 'PmtInf' );
		$el( $pmt, 'PmtInfId', self::clean( $msg_id . '-1', 35 ) );
		$el( $pmt, 'PmtMtd', 'TRF' );
		$el( $pmt, 'BtchBookg', 'true' );
		$el( $pmt, 'NbOfTxs', count( $transfers ) );
		$el( $pmt, 'CtrlSum', Money::to_decimal( $total, 'EUR' ) );
		$el( $el( $el( $pmt, 'PmtTpInf' ), 'SvcLvl' ), 'Cd', 'SEPA' );
		$el( $pmt, 'ReqdExctnDt', $exec_date );
		$el( $el( $pmt, 'Dbtr' ), 'Nm', self::clean( $debtor['name'], 70 ) );
		$el( $el( $el( $pmt, 'DbtrAcct' ), 'Id' ), 'IBAN', Iban::normalize( $debtor['iban'] ) );
		$agt = $el( $el( $pmt, 'DbtrAgt' ), 'FinInstnId' );
		if ( ! empty( $debtor['bic'] ) ) {
			$el( $agt, 'BIC', $debtor['bic'] );
		} else {
			$el( $el( $agt, 'Othr' ), 'Id', 'NOTPROVIDED' );
		}
		$el( $pmt, 'ChrgBr', 'SLEV' );

		foreach ( $transfers as $t ) {
			$tx = $el( $pmt, 'CdtTrfTxInf' );
			$el( $el( $tx, 'PmtId' ), 'EndToEndId', self::clean( $t['end_to_end_id'], 35 ) );
			$amt = $doc->createElement( 'InstdAmt', Money::to_decimal( (int) $t['amount_minor'], 'EUR' ) );
			$amt->setAttribute( 'Ccy', 'EUR' );
			$el( $tx, 'Amt' )->appendChild( $amt );
			if ( ! empty( $t['bic'] ) ) {
				$el( $el( $el( $tx, 'CdtrAgt' ), 'FinInstnId' ), 'BIC', $t['bic'] );
			}
			$name = self::clean( $t['name'], 70 );
			$el( $el( $tx, 'Cdtr' ), 'Nm', '' !== $name ? $name : 'NOTPROVIDED' );
			$el( $el( $el( $tx, 'CdtrAcct' ), 'Id' ), 'IBAN', Iban::normalize( $t['iban'] ) );
			$el( $el( $tx, 'RmtInf' ), 'Ustrd', self::clean( $t['remittance'], 140 ) );
		}

		return $doc->saveXML();
	}
}
