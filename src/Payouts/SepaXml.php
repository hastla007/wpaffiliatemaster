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
	 * Restrict text to the SEPA (Latin) character set.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function clean( $text, $max ) {
		$map  = array(
			'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ò' => 'o', 'í' => 'i', 'ç' => 'c', 'ñ' => 'n',
		);
		$text = strtr( (string) $text, $map );
		$text = preg_replace( '/[^A-Za-z0-9\/\-\?:\(\)\.,\'\+ ]/', '', $text );
		return substr( trim( preg_replace( '/\s+/', ' ', $text ) ), 0, $max );
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
			$msg = 'The SEPA XML export needs the PHP extension "dom" (DOMDocument), which is not installed on this server. Ask your hosting provider to enable it, or use the transfer list (CSV) instead.';
			throw new \InvalidArgumentException( function_exists( '__' ) ? __( 'The SEPA XML export needs the PHP extension "dom" (DOMDocument), which is not installed on this server. Ask your hosting provider to enable it, or use the transfer list (CSV) instead.', 'affiliate-captain' ) : $msg );
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
				throw new \InvalidArgumentException( 'Invalid creditor IBAN for ' . $t['end_to_end_id'] );
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
