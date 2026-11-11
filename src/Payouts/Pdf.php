<?php
/**
 * Minimal PDF 1.4 writer for text documents (A4, Helvetica, WinAnsi encoding
 * so German umlauts/€ work). No WordPress dependency → unit tested.
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
	 * Convert UTF-8 to a PDF literal string in WinAnsi (CP1252).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function literal( $text ) {
		$text = (string) $text;
		$conv = function_exists( 'mb_convert_encoding' ) ? @mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $conv || '' === $conv && '' !== $text ) {
			$conv = (string) @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return '(' . strtr( $conv, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) ) . ')';
	}

	/**
	 * Approximate Helvetica text width.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @return float
	 */
	public static function width( $text, $size ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
		return $len * $size * 0.5;
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
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $paragraph ) ) as $word ) {
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
		$objects[5] = '<< /Title ' . self::literal( isset( $this->meta['title'] ) ? $this->meta['title'] : '' ) . ' /Author ' . self::literal( isset( $this->meta['author'] ) ? $this->meta['author'] : '' ) . ' /Producer (WP Affiliate Captain) /CreationDate (D:' . gmdate( 'YmdHis', $created ) . "Z) >>";
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
