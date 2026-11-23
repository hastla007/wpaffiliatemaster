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

defined( 'ABSPATH' ) || exit;

final class Pdf {

	const WIDTH  = 595.28;
	const HEIGHT = 841.89;

	/** @var string[] Content streams per page. */
	private $pages = array();

	/** @var array */
	private $meta = array();

	/** @var string[] Original (UTF-8) texts that had to be transliterated. */
	private $originals = array();

	/** @var array[] Images (image_info() results) in the order they were placed. */
	private $images = array();

	/** @var array[] Per page: image index => true (the page's XObject resources). */
	private $page_images = array();

	/** @var string[] Footer lines printed at the bottom of every page (SET-AC-16). */
	private $footer = array();

	/** Footer: font size and line height in points. */
	const FOOTER_SIZE = 7;
	const FOOTER_LINE = 9;

	/** Largest image accepted (pixels), so a huge picture cannot exhaust memory or time. */
	const MAX_PIXELS = 4000000;

	/** Largest PNG with transparency (pixels): its alpha channel is separated in PHP. */
	const MAX_ALPHA_PIXELS = 1000000;

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
		$this->pages[]       = '';
		$this->page_images[] = array();
	}

	/**
	 * Footer printed at the bottom of every page (SET-AC-16): plain text, wrapped to the text
	 * width. Content must stay above limit().
	 *
	 * @param string $text      Text (lines separated by line breaks).
	 * @param int    $max_lines Most lines printed (after wrapping).
	 */
	public function set_footer( $text, $max_lines = 6 ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines = array_merge( $lines, self::wrap( $line, 495, self::FOOTER_SIZE ) );
			}
		}
		$this->footer = array_slice( $lines, 0, max( 0, (int) $max_lines ) );
		foreach ( $this->footer as $line ) {
			self::to_cp1252( $line, $lossy );
			if ( $lossy ) {
				$this->originals[] = $line;
			}
		}
	}

	/**
	 * Lowest position for content: $y minus the space of the footer (unchanged without a footer).
	 *
	 * @param float $y Limit of the layout without a footer (from top).
	 * @return float
	 */
	public function limit( $y ) {
		return $this->footer ? $y - ( self::FOOTER_LINE * count( $this->footer ) + 14 ) : $y;
	}

	/**
	 * Read a JPEG or PNG for embedding, without any image library (no GD/Imagick needed).
	 *
	 * Supported: JPEG (grey, RGB, CMYK; baseline or progressive) and PNG without interlacing
	 * (grey, RGB, palette; 8 bit grey/RGB with alpha and palettes with a transparency table become
	 * an image with a soft mask).
	 *
	 * @param string      $bytes  File content.
	 * @param string|null $reason Out: why the image cannot be used ('' when it can).
	 * @return array|null w, h, cs, bpc, filter, parms, data, palette, smask, decode.
	 */
	public static function image_info( $bytes, &$reason = null ) {
		$bytes  = (string) $bytes;
		$reason = '';
		if ( "\xFF\xD8" === substr( $bytes, 0, 2 ) ) {
			$info = self::jpeg_info( $bytes );
		} elseif ( "\x89PNG\r\n\x1A\n" === substr( $bytes, 0, 8 ) ) {
			$info = self::png_info( $bytes, $reason );
		} else {
			$reason = 'format';
			return null;
		}
		if ( ! is_array( $info ) ) {
			$reason = '' !== $reason ? $reason : 'format';
			return null;
		}
		if ( $info['w'] < 1 || $info['h'] < 1 || $info['w'] * $info['h'] > self::MAX_PIXELS ) {
			$reason = 'pixels';
			return null;
		}
		return $info;
	}

	/**
	 * JPEG: dimensions and colour space from the frame header (the data is embedded as is).
	 *
	 * @param string $bytes File content.
	 * @return array|null
	 */
	private static function jpeg_info( $bytes ) {
		$len   = strlen( $bytes );
		$pos   = 2;
		$adobe = false;
		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $bytes[ $pos ] ) {
				return null;
			}
			$marker = ord( $bytes[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos; // Fill byte.
				continue;
			}
			if ( 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
				$pos += 2;
				continue;
			}
			$size = unpack( 'n', substr( $bytes, $pos + 2, 2 ) )[1];
			if ( $size < 2 || $pos + 2 + $size > $len ) {
				return null;
			}
			if ( 0xEE === $marker && 'Adobe' === substr( $bytes, $pos + 4, 5 ) ) {
				$adobe = true;
			}
			if ( $marker >= 0xC0 && $marker <= 0xCF && ! in_array( $marker, array( 0xC4, 0xC8, 0xCC ), true ) ) {
				if ( $size < 8 ) {
					return null;
				}
				$bpc   = ord( $bytes[ $pos + 4 ] );
				$dims  = unpack( 'nh/nw', substr( $bytes, $pos + 5, 4 ) );
				$comps = ord( $bytes[ $pos + 9 ] );
				$cs    = array(
					1 => 'DeviceGray',
					3 => 'DeviceRGB',
					4 => 'DeviceCMYK',
				);
				if ( 8 !== $bpc || ! isset( $cs[ $comps ] ) ) {
					return null;
				}
				return array(
					'w'       => (int) $dims['w'],
					'h'       => (int) $dims['h'],
					'cs'      => $cs[ $comps ],
					'bpc'     => 8,
					'filter'  => 'DCTDecode',
					'parms'   => '',
					'data'    => $bytes,
					'palette' => '',
					'smask'   => '',
					// Adobe CMYK JPEGs store inverted values.
					'decode'  => 4 === $comps && $adobe ? '[1 0 1 0 1 0 1 0]' : '',
				);
			}
			if ( 0xDA === $marker ) {
				return null; // Image data before a frame header.
			}
			$pos += 2 + $size;
		}
		return null;
	}

	/**
	 * PNG: the compressed data is embedded with the PNG predictors; an alpha channel becomes a
	 * soft mask (needs zlib).
	 *
	 * @param string      $bytes  File content.
	 * @param string|null $reason Out: reason when unsupported.
	 * @return array|null
	 */
	private static function png_info( $bytes, &$reason = null ) {
		$len     = strlen( $bytes );
		$pos     = 8;
		$header  = null;
		$palette = '';
		$trns    = '';
		$idat    = '';
		while ( $pos + 8 <= $len ) {
			$size = unpack( 'N', substr( $bytes, $pos, 4 ) )[1];
			$type = substr( $bytes, $pos + 4, 4 );
			if ( $size < 0 || $pos + 12 + $size > $len ) {
				return null;
			}
			$chunk = substr( $bytes, $pos + 8, $size );
			if ( 'IHDR' === $type && 13 === $size ) {
				$header = unpack( 'Nw/Nh/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk );
			} elseif ( 'PLTE' === $type ) {
				$palette = $chunk;
			} elseif ( 'tRNS' === $type ) {
				$trns = $chunk;
			} elseif ( 'IDAT' === $type ) {
				$idat .= $chunk;
			} elseif ( 'IEND' === $type ) {
				break;
			}
			$pos += 12 + $size;
		}
		if ( ! $header || '' === $idat || 0 !== $header['compression'] || 0 !== $header['filter'] ) {
			return null;
		}
		if ( 0 !== $header['interlace'] ) {
			$reason = 'interlaced';
			return null;
		}
		$w     = (int) $header['w'];
		$h     = (int) $header['h'];
		$depth = (int) $header['depth'];
		$color = (int) $header['color'];
		if ( $w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS ) {
			$reason = 'pixels';
			return null;
		}
		$info = array(
			'w'       => $w,
			'h'       => $h,
			'bpc'     => $depth,
			'filter'  => 'FlateDecode',
			'data'    => $idat,
			'palette' => '',
			'smask'   => '',
			'decode'  => '',
		);
		if ( 3 === $color && '' !== $trns && '' !== $palette && $depth <= 8 ) {
			// Palette with transparency (e.g. from PNG optimisers): expanded to RGB with a soft mask.
			return self::png_palette_alpha( $info, $palette, $trns, $reason );
		}
		if ( in_array( $color, array( 0, 2, 3 ), true ) ) {
			if ( $depth > 8 || ( 2 === $color && 8 !== $depth ) || ( 3 === $color && '' === $palette ) ) {
				$reason = 'depth';
				return null;
			}
			$colors          = 2 === $color ? 3 : 1;
			$info['cs']      = 0 === $color ? 'DeviceGray' : ( 2 === $color ? 'DeviceRGB' : 'Indexed' );
			$info['palette'] = 3 === $color ? $palette : '';
			$info['parms']   = '<< /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $depth . ' /Columns ' . $w . ' >>';
			return $info;
		}
		if ( ! in_array( $color, array( 4, 6 ), true ) || 8 !== $depth ) {
			$reason = 'depth';
			return null;
		}
		if ( $w * $h > self::MAX_ALPHA_PIXELS ) {
			$reason = 'pixels';
			return null;
		}
		if ( ! function_exists( 'gzuncompress' ) ) {
			$reason = 'zlib';
			return null;
		}
		$raw = @gzuncompress( $idat ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bpp = 6 === $color ? 4 : 2;
		$row = $w * $bpp;
		if ( ! is_string( $raw ) || strlen( $raw ) < ( $row + 1 ) * $h ) {
			return null;
		}
		$pixels = self::png_unfilter( $raw, $h, $row, $bpp );
		if ( null === $pixels ) {
			return null;
		}
		$colour = '';
		$alpha  = '';
		$n      = $bpp - 1;
		$total  = strlen( $pixels );
		for ( $i = 0; $i < $total; $i += $bpp ) {
			$colour .= substr( $pixels, $i, $n );
			$alpha  .= $pixels[ $i + $n ];
		}
		$info['cs']    = 6 === $color ? 'DeviceRGB' : 'DeviceGray';
		$info['data']  = (string) gzcompress( $colour );
		$info['parms'] = '';
		$info['smask'] = (string) gzcompress( $alpha );
		return $info;
	}

	/**
	 * PNG palette image with a transparency table: RGB data plus soft mask.
	 *
	 * @param array       $info    Image so far (w, h, bpc = bit depth, data = compressed rows).
	 * @param string      $palette PLTE chunk.
	 * @param string      $trns    tRNS chunk (alpha per palette entry).
	 * @param string|null $reason  Out: reason when unsupported.
	 * @return array|null
	 */
	private static function png_palette_alpha( array $info, $palette, $trns, &$reason = null ) {
		$w     = (int) $info['w'];
		$h     = (int) $info['h'];
		$depth = (int) $info['bpc'];
		if ( $w * $h > self::MAX_ALPHA_PIXELS ) {
			$reason = 'pixels';
			return null;
		}
		if ( ! function_exists( 'gzuncompress' ) ) {
			$reason = 'zlib';
			return null;
		}
		$raw = @gzuncompress( $info['data'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$row = (int) ceil( $w * $depth / 8 );
		if ( ! is_string( $raw ) || strlen( $raw ) < ( $row + 1 ) * $h ) {
			return null;
		}
		$bytes = self::png_unfilter( $raw, $h, $row, 1 );
		if ( null === $bytes ) {
			return null;
		}
		$colour = '';
		$alpha  = '';
		$mask   = ( 1 << $depth ) - 1;
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$bit   = $x * $depth;
				$byte  = ord( $bytes[ $y * $row + ( $bit >> 3 ) ] );
				$index = ( $byte >> ( 8 - $depth - ( $bit & 7 ) ) ) & $mask;
				$rgb   = substr( $palette, 3 * $index, 3 );
				$colour .= 3 === strlen( $rgb ) ? $rgb : "\0\0\0";
				$alpha  .= $index < strlen( $trns ) ? $trns[ $index ] : "\xFF";
			}
		}
		$info['cs']    = 'DeviceRGB';
		$info['bpc']   = 8;
		$info['data']  = (string) gzcompress( $colour );
		$info['parms'] = '';
		$info['smask'] = (string) gzcompress( $alpha );
		return $info;
	}

	/**
	 * Undo the PNG row filters.
	 *
	 * @param string $raw  Decompressed data (filter byte + row per line).
	 * @param int    $h    Rows.
	 * @param int    $row  Bytes per row.
	 * @param int    $bpp  Bytes per pixel.
	 * @return string|null Pixel bytes.
	 */
	private static function png_unfilter( $raw, $h, $row, $bpp ) {
		$out   = '';
		$prior = array_fill( 0, $row, 0 );
		for ( $y = 0; $y < $h; $y++ ) {
			$offset = $y * ( $row + 1 );
			$type   = ord( $raw[ $offset ] );
			$cur    = array_values( unpack( 'C*', substr( $raw, $offset + 1, $row ) ) );
			switch ( $type ) {
				case 0:
					break;
				case 1:
					for ( $i = $bpp; $i < $row; $i++ ) {
						$cur[ $i ] = ( $cur[ $i ] + $cur[ $i - $bpp ] ) & 0xFF;
					}
					break;
				case 2:
					for ( $i = 0; $i < $row; $i++ ) {
						$cur[ $i ] = ( $cur[ $i ] + $prior[ $i ] ) & 0xFF;
					}
					break;
				case 3:
					for ( $i = 0; $i < $row; $i++ ) {
						$left      = $i >= $bpp ? $cur[ $i - $bpp ] : 0;
						$cur[ $i ] = ( $cur[ $i ] + ( ( $left + $prior[ $i ] ) >> 1 ) ) & 0xFF;
					}
					break;
				case 4:
					for ( $i = 0; $i < $row; $i++ ) {
						$a  = $i >= $bpp ? $cur[ $i - $bpp ] : 0;
						$b  = $prior[ $i ];
						$c  = $i >= $bpp ? $prior[ $i - $bpp ] : 0;
						$p  = $a + $b - $c;
						$pa = abs( $p - $a );
						$pb = abs( $p - $b );
						$pc = abs( $p - $c );
						$cur[ $i ] = ( $cur[ $i ] + ( ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c ) ) ) & 0xFF;
					}
					break;
				default:
					return null;
			}
			$out  .= pack( 'C*', ...$cur );
			$prior = $cur;
		}
		return $out;
	}

	/**
	 * Place an image (result of image_info()) on the current page.
	 *
	 * @param array $info Image.
	 * @param float $x    Left edge.
	 * @param float $y    Top edge (from top).
	 * @param float $w    Width in points.
	 * @param float $h    Height in points.
	 */
	public function image( array $info, $x, $y, $w, $h ) {
		$index                  = count( $this->images );
		$this->images[]         = $info;
		$page                   = count( $this->pages ) - 1;
		$this->page_images[ $page ][ $index ] = true;
		$this->pages[ $page ]  .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, self::HEIGHT - $y - $h, $index + 1 );
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
	 * Content of the footer for one page ('' without a footer).
	 *
	 * @return string
	 */
	private function footer_content() {
		if ( ! $this->footer ) {
			return '';
		}
		$count = count( $this->footer );
		$first = 818 - self::FOOTER_LINE * ( $count - 1 );
		$out   = sprintf( "0.5 w %.2F %.2F m %.2F %.2F l S\n", 50, self::HEIGHT - ( $first - 10 ), 545, self::HEIGHT - ( $first - 10 ) );
		foreach ( $this->footer as $i => $line ) {
			$out .= sprintf( "BT /F1 %.2F Tf %.2F %.2F Td %s Tj ET\n", self::FOOTER_SIZE, 50, self::HEIGHT - ( $first + self::FOOTER_LINE * $i ), self::literal( $line ) );
		}
		return $out;
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
		// Images after the pages: XObject (and its soft mask) per placed image.
		$image_ids = array();
		$next      = 6 + 2 * $n;
		foreach ( $this->images as $index => $img ) {
			$image_ids[ $index ] = $next;
			$smask_id            = '' !== $img['smask'] ? $next + 1 : 0;
			$cs                  = '/' . $img['cs'];
			if ( 'Indexed' === $img['cs'] ) {
				$cs = '[/Indexed /DeviceRGB ' . ( intdiv( strlen( $img['palette'] ), 3 ) - 1 ) . ' <' . bin2hex( $img['palette'] ) . '>]';
			}
			$dict = '<< /Type /XObject /Subtype /Image /Width ' . (int) $img['w'] . ' /Height ' . (int) $img['h'] . ' /ColorSpace ' . $cs . ' /BitsPerComponent ' . (int) $img['bpc'] . ' /Filter /' . $img['filter']
				. ( '' !== $img['parms'] ? ' /DecodeParms ' . $img['parms'] : '' )
				. ( '' !== $img['decode'] ? ' /Decode ' . $img['decode'] : '' )
				. ( $smask_id ? ' /SMask ' . $smask_id . ' 0 R' : '' )
				. ' /Length ' . strlen( $img['data'] ) . ' >>';
			$objects[ $next ] = $dict . "\nstream\n" . $img['data'] . "\nendstream";
			if ( $smask_id ) {
				$objects[ $smask_id ] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $img['w'] . ' /Height ' . (int) $img['h'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen( $img['smask'] ) . " >>\nstream\n" . $img['smask'] . "\nendstream";
			}
			$next += $smask_id ? 2 : 1;
		}
		foreach ( $this->pages as $i => $content ) {
			$page_id = 6 + 2 * $i;
			$content .= $this->footer_content();
			$xobjects = '';
			foreach ( array_keys( isset( $this->page_images[ $i ] ) ? $this->page_images[ $i ] : array() ) as $index ) {
				$xobjects .= ' /Im' . ( $index + 1 ) . ' ' . $image_ids[ $index ] . ' 0 R';
			}
			$xobjects = '' !== $xobjects ? ' /XObject <<' . $xobjects . ' >>' : '';
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xobjects . ' >> /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
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
