<?php
/**
 * Minimal PDF 1.4 writer for text documents (A4, Helvetica, WinAnsi encoding
 * so German umlauts/€ work). No WordPress dependency → unit tested.
 *
 * Limitation: the standard fonts only cover Windows-1252 (Western European). Other characters
 * (Ł, ś, ř, ő, Cyrillic, Greek …) are transliterated (Ł → L, ś → s, Ж → Zh) instead of being
 * printed as "?"; the original spelling of every transliterated text is kept in the document
 * properties (Subject, UTF-16) and listed in a note on the document. Embedding a Unicode font
 * would make every document several hundred KB larger, so it is not done.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

final class Pdf {

	const WIDTH  = 595.28;
	const HEIGHT = 841.89;

	/** @var string[] Content streams per page. */
	private $pages = array();

	/** @var array */
	private $meta = array();

	/** @var string[] Original (UTF-8) texts that had to be transliterated. */
	private $originals = array();

	/**
	 * Fallback transliteration of letters outside Windows-1252 that do not decompose into a base
	 * letter plus accent (used when neither intl nor iconv can transliterate).
	 */
	const FALLBACK = array(
		'Ł' => 'L', 'ł' => 'l', 'Đ' => 'D', 'đ' => 'd', 'Ħ' => 'H', 'ħ' => 'h', 'ı' => 'i', 'İ' => 'I',
		'Ŀ' => 'L', 'ŀ' => 'l', 'Ŋ' => 'N', 'ŋ' => 'n', 'Ŧ' => 'T', 'ŧ' => 't', 'ſ' => 's', 'Ə' => 'E', 'ə' => 'e',
		'ș' => 's', 'Ș' => 'S', 'ț' => 't', 'Ț' => 'T', '–' => '-', '—' => '-', '„' => '"', '“' => '"', '”' => '"',
	);

	/**
	 * Constructor.
	 *
	 * @param array $meta title, author, creation timestamp.
	 */
	public function __construct( array $meta = array() ) {
		$this->meta = $meta;
		$this->add_page();
	}

	/** Start a new page. */
	public function add_page() {
		$this->pages[] = '';
	}

	/**
	 * Number of pages.
	 *
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Convert UTF-8 to Windows-1252; characters outside it are transliterated.
	 *
	 * @param string    $text  Text (UTF-8).
	 * @param bool|null $lossy Out: whether something had to be transliterated.
	 * @return string
	 */
	public static function to_cp1252( $text, &$lossy = null ) {
		$text  = (string) $text;
		$lossy = false;
		if ( ! preg_match( '/[\x80-\xFF]/', $text ) ) {
			return $text; // ASCII.
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			// Not valid UTF-8 (e.g. already Windows-1252): keep the bytes.
			return $text;
		}
		$out = '';
		foreach ( $chars as $ch ) {
			if ( ord( $ch[0] ) < 0x80 ) {
				$out .= $ch;
				continue;
			}
			$c = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'Windows-1252', $ch ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $c && function_exists( 'mb_convert_encoding' ) ) {
				$c = @mb_convert_encoding( $ch, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$c = '?' === $c ? false : $c;
			}
			if ( false !== $c && '' !== $c ) {
				$out .= $c;
				continue;
			}
			$lossy = true;
			$out  .= self::transliterate( $ch );
		}
		return $out;
	}

	/**
	 * Transliterate one character into ASCII.
	 *
	 * @param string $ch Character (UTF-8).
	 * @return string
	 */
	public static function transliterate( $ch ) {
		if ( isset( self::FALLBACK[ $ch ] ) ) {
			return self::FALLBACK[ $ch ];
		}
		if ( function_exists( 'transliterator_transliterate' ) ) {
			$t = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $ch );
			if ( is_string( $t ) && '' !== $t && ! preg_match( '/[^\x20-\x7E]/', $t ) ) {
				return $t;
			}
		}
		if ( class_exists( 'Normalizer' ) ) {
			$t = preg_replace( '/\p{Mn}+/u', '', (string) \Normalizer::normalize( $ch, \Normalizer::FORM_D ) );
			if ( is_string( $t ) && '' !== $t && ! preg_match( '/[^\x20-\x7E]/', $t ) ) {
				return $t;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $ch ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $t ) && '' !== $t && false === strpos( $t, '?' ) ) {
				return $t;
			}
		}
		return '?';
	}

	/**
	 * Convert UTF-8 to a PDF literal string in WinAnsi (CP1252).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function literal( $text ) {
		$conv = self::to_cp1252( (string) $text );
		return '(' . strtr( $conv, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) ) . ')';
	}

	/**
	 * PDF text string in UTF-16BE (document properties keep every character).
	 *
	 * @param string $text Text (UTF-8).
	 * @return string
	 */
	public static function utf16( $text ) {
		$text = (string) $text;
		if ( ! preg_match( '/[\x80-\xFF]/', $text ) ) {
			return self::literal( $text );
		}
		$conv = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) : @iconv( 'UTF-8', 'UTF-16BE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return '<FEFF' . strtoupper( bin2hex( (string) $conv ) ) . '>';
	}

	/**
	 * Texts that had to be transliterated on this document (original spelling).
	 *
	 * @return string[]
	 */
	public function transliterated() {
		return array_values( array_unique( $this->originals ) );
	}

	/**
	 * Approximate Helvetica text width.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @return float
	 */
	public static function width( $text, $size ) {
		return strlen( self::to_cp1252( (string) $text ) ) * $size * 0.5;
	}

	/**
	 * Draw text at x/y (y from top, in points).
	 *
	 * @param float  $x     X.
	 * @param float  $y     Y from top.
	 * @param string $text  Text.
	 * @param float  $size  Size.
	 * @param bool   $bold  Bold.
	 * @param string $align left|right.
	 */
	public function text( $x, $y, $text, $size = 10, $bold = false, $align = 'left' ) {
		if ( 'right' === $align ) {
			$x -= self::width( $text, $size );
		}
		self::to_cp1252( (string) $text, $lossy );
		if ( $lossy ) {
			$this->originals[] = (string) $text;
		}
		$this->pages[ count( $this->pages ) - 1 ] .= sprintf( "BT /%s %.2F Tf %.2F %.2F Td %s Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, self::HEIGHT - $y, self::literal( $text ) );
	}

	/**
	 * Horizontal/vertical line.
	 *
	 * @param float $x1 X1.
	 * @param float $y1 Y1 (from top).
	 * @param float $x2 X2.
	 * @param float $y2 Y2 (from top).
	 */
	public function line( $x1, $y1, $x2, $y2 ) {
		$this->pages[ count( $this->pages ) - 1 ] .= sprintf( "0.5 w %.2F %.2F m %.2F %.2F l S\n", $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2 );
	}

	/**
	 * Word-wrap a text to a maximum width.
	 *
	 * @param string $text  Text.
	 * @param float  $width Width in points.
	 * @param float  $size  Font size.
	 * @return string[]
	 */
	public static function wrap( $text, $width, $size ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $paragraph ) {
			$line  = '';
			$words = array();
			foreach ( preg_split( '/\s+/', trim( $paragraph ) ) as $word ) {
				// A word wider than the column (order number, UUID, long product name) is broken.
				while ( '' !== $word && self::width( $word, $size ) > $width ) {
					$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
					$chars = false === $chars ? str_split( $word ) : $chars;
					$part  = '';
					foreach ( $chars as $c ) {
						if ( '' !== $part && self::width( $part . $c, $size ) > $width ) {
							break;
						}
						$part .= $c;
					}
					$words[] = $part;
					$word    = (string) substr( $word, strlen( $part ) );
				}
				if ( '' !== $word ) {
					$words[] = $word;
				}
			}
			foreach ( $words as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && self::width( $try, $size ) > $width ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Render the PDF document.
	 *
	 * @return string
	 */
	public function output() {
		$objects = array();
		$n       = count( $this->pages );
		// 1: catalog, 2: pages, 3: font regular, 4: font bold, 5: info; then page/content pairs.
		$kids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 6 + 2 * $i ) . ' 0 R';
		}
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$created    = isset( $this->meta['created'] ) ? (int) $this->meta['created'] : time();
		$subject    = '';
		if ( $this->originals ) {
			// Original spelling of transliterated texts (the fonts cannot show them).
			$subject = ' /Subject ' . self::utf16( 'Original spelling: ' . implode( ' | ', $this->transliterated() ) );
		}
		$objects[5] = '<< /Title ' . self::utf16( isset( $this->meta['title'] ) ? $this->meta['title'] : '' ) . ' /Author ' . self::utf16( isset( $this->meta['author'] ) ? $this->meta['author'] : '' ) . $subject . ' /Producer (Affiliate Captain) /CreationDate (D:' . gmdate( 'YmdHis', $created ) . "Z) >>";
		foreach ( $this->pages as $i => $content ) {
			$page_id             = 6 + 2 * $i;
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
			$objects[ $page_id + 1 ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "endstream";
		}
		ksort( $objects );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$max  = max( array_keys( $objects ) );
		$out .= 'xref' . "\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$out .= "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $out;
	}
}
