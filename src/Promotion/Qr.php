<?php
/**
 * Minimal QR code encoder (ISO/IEC 18004): byte mode, error correction level M,
 * versions 1–10 (up to 213 bytes – plenty for partner links). Outputs SVG.
 * No WordPress dependency → unit tested (decoded with zbar in development).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Promotion;

final class Qr {

	/** Version => [ ec codewords per block, blocks group 1, data cw g1, blocks group 2, data cw g2 ] (level M). */
	const BLOCKS = array(
		1  => array( 10, 1, 16, 0, 0 ),
		2  => array( 16, 1, 28, 0, 0 ),
		3  => array( 26, 1, 44, 0, 0 ),
		4  => array( 18, 2, 32, 0, 0 ),
		5  => array( 24, 2, 43, 0, 0 ),
		6  => array( 16, 4, 27, 0, 0 ),
		7  => array( 18, 4, 31, 0, 0 ),
		8  => array( 22, 2, 38, 2, 39 ),
		9  => array( 22, 3, 36, 2, 37 ),
		10 => array( 26, 4, 43, 1, 44 ),
	);

	const ALIGN = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/** @var int */
	private $size;
	/** @var int */
	private $version;
	/** @var array<int,array<int,bool>> modules[y][x] */
	private $modules = array();
	/** @var array<int,array<int,bool>> */
	private $function = array();

	/**
	 * Encode text.
	 *
	 * @param string $text Text (UTF-8).
	 * @return array<int,array<int,bool>> Matrix [row][col], true = dark.
	 * @throws \InvalidArgumentException When the text is too long.
	 */
	public static function matrix( $text ) {
		$qr = new self();
		return $qr->encode( (string) $text );
	}

	/**
	 * SVG markup.
	 *
	 * @param string $text  Text.
	 * @param int    $scale Pixel per module.
	 * @param int    $quiet Quiet zone in modules.
	 * @return string
	 */
	public static function svg( $text, $scale = 6, $quiet = 4 ) {
		$m    = self::matrix( $text );
		$n    = count( $m );
		$dim  = ( $n + 2 * $quiet ) * $scale;
		$path = '';
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $m[ $y ][ $x ] ) {
					$path .= 'M' . ( $x + $quiet ) . ',' . ( $y + $quiet ) . 'h1v1h-1z';
				}
			}
		}
		$v = $n + 2 * $quiet;
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" viewBox="0 0 ' . $v . ' ' . $v . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
	}

	/**
	 * Build the matrix.
	 *
	 * @param string $text Text.
	 * @return array
	 * @throws \InvalidArgumentException When the text is too long.
	 */
	private function encode( $text ) {
		$bytes = array_values( unpack( 'C*', $text ) ? unpack( 'C*', $text ) : array() );
		$len   = count( $bytes );

		$this->version = 0;
		foreach ( self::BLOCKS as $v => $b ) {
			$capacity_bits = ( $b[1] * $b[2] + $b[3] * $b[4] ) * 8;
			$needed        = 4 + ( $v < 10 ? 8 : 16 ) + 8 * $len;
			if ( $needed <= $capacity_bits ) {
				$this->version = $v;
				break;
			}
		}
		if ( ! $this->version ) {
			throw new \InvalidArgumentException( 'Text too long for QR versions 1-10.' );
		}
		$this->size = $this->version * 4 + 17;
		$b          = self::BLOCKS[ $this->version ];
		$data_cw    = $b[1] * $b[2] + $b[3] * $b[4];

		// Bit stream.
		$bits = array();
		$push = static function ( $value, $count ) use ( &$bits ) {
			for ( $i = $count - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};
		$push( 4, 4 );
		$push( $len, $this->version < 10 ? 8 : 16 );
		foreach ( $bytes as $byte ) {
			$push( $byte, 8 );
		}
		$capacity = $data_cw * 8;
		$push( 0, min( 4, $capacity - count( $bits ) ) );
		while ( count( $bits ) % 8 ) {
			$bits[] = 0;
		}
		$data = array();
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$data[] = bindec( implode( '', $chunk ) );
		}
		for ( $pad = 0xEC; count( $data ) < $data_cw; $pad ^= 0xEC ^ 0x11 ) {
			$data[] = $pad;
		}

		// Blocks + error correction, interleaved.
		$blocks = array();
		$offset = 0;
		for ( $g = 0; $g < 2; $g++ ) {
			$count = $b[ 1 + 2 * $g ];
			$size  = $b[ 2 + 2 * $g ];
			for ( $i = 0; $i < $count; $i++ ) {
				$blocks[] = array_slice( $data, $offset, $size );
				$offset  += $size;
			}
		}
		$gen = self::generator( $b[0] );
		$ecc = array();
		foreach ( $blocks as $block ) {
			$ecc[] = self::remainder( $block, $gen );
		}
		$final  = array();
		$maxlen = max( array_map( 'count', $blocks ) );
		for ( $i = 0; $i < $maxlen; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $i ] ) ) {
					$final[] = $block[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $b[0]; $i++ ) {
			foreach ( $ecc as $e ) {
				$final[] = $e[ $i ];
			}
		}

		// Matrix.
		$this->modules  = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->function = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->draw_function_patterns();
		$this->draw_codewords( $final );

		$best       = null;
		$best_score = PHP_INT_MAX;
		$base       = $this->modules;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->modules = $base;
			$this->apply_mask( $mask );
			$this->draw_format( $mask );
			$score = $this->penalty();
			if ( $score < $best_score ) {
				$best_score = $score;
				$best       = $this->modules;
			}
		}
		return $best;
	}

	/**
	 * Set a function module.
	 *
	 * @param int  $x    Column.
	 * @param int  $y    Row.
	 * @param bool $dark Dark.
	 */
	private function set( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]  = (bool) $dark;
		$this->function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns() {
		$n = $this->size;
		for ( $i = 0; $i < $n; $i++ ) {
			$this->set( 6, $i, 0 === $i % 2 );
			$this->set( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $n - 4, 3 ), array( 3, $n - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $c[0] + $dx;
					$y = $c[1] + $dy;
					if ( $x >= 0 && $x < $n && $y >= 0 && $y < $n ) {
						$dist = max( abs( $dx ), abs( $dy ) );
						$this->set( $x, $y, 2 !== $dist && 4 !== $dist );
					}
				}
			}
		}
		$pos = self::ALIGN[ $this->version ];
		$num = count( $pos );
		for ( $i = 0; $i < $num; $i++ ) {
			for ( $j = 0; $j < $num; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $num - 1 === $j ) || ( $num - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format( 0 ); // Reserve format areas.
		if ( $this->version >= 7 ) {
			$rem = $this->version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $this->version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$a   = $n - 11 + $i % 3;
				$bb  = intdiv( $i, 3 );
				$this->set( $a, $bb, $bit );
				$this->set( $bb, $a, $bit );
			}
		}
	}

	/**
	 * Format information (level M = 0b00).
	 *
	 * @param int $mask Mask.
	 */
	private function draw_format( $mask ) {
		$n    = $this->size;
		$data = ( 0 << 3 ) | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = static function ( $i ) use ( $bits ) {
			return ( $bits >> $i ) & 1;
		};
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set( 8, $i, $bit( $i ) );
		}
		$this->set( 8, 7, $bit( 6 ) );
		$this->set( 8, 8, $bit( 7 ) );
		$this->set( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set( $n - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set( 8, $n - 15 + $i, $bit( $i ) );
		}
		$this->set( 8, $n - 8, true );
	}

	/**
	 * Place data in the zigzag pattern.
	 *
	 * @param int[] $codewords Codewords.
	 */
	private function draw_codewords( array $codewords ) {
		$n     = $this->size;
		$i     = 0;
		$total = count( $codewords ) * 8;
		for ( $right = $n - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $n; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $n - 1 - $vert : $vert;
					if ( ! $this->function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = (bool) ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	/**
	 * XOR mask on non-function modules.
	 *
	 * @param int $mask Mask 0-7.
	 */
	private function apply_mask( $mask ) {
		$n = $this->size;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $this->function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$inv = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$inv = 0 === $y % 2;
						break;
					case 2:
						$inv = 0 === $x % 3;
						break;
					case 3:
						$inv = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$inv = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$inv = 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
						break;
					case 6:
						$inv = 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
					default:
						$inv = 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
				}
				if ( $inv ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * Mask penalty score (rules N1–N4).
	 *
	 * @return int
	 */
	private function penalty() {
		$n     = $this->size;
		$score = 0;
		$lines = array();
		for ( $y = 0; $y < $n; $y++ ) {
			$row = '';
			$col = '';
			for ( $x = 0; $x < $n; $x++ ) {
				$row .= $this->modules[ $y ][ $x ] ? '1' : '0';
				$col .= $this->modules[ $x ][ $y ] ? '1' : '0';
			}
			$lines[] = $row;
			$lines[] = $col;
		}
		foreach ( $lines as $line ) {
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $m ) ) {
				foreach ( $m[0] as $run ) {
					$score += 3 + strlen( $run ) - 5;
				}
			}
			$padded = '0000' . $line . '0000';
			$score += 40 * ( substr_count( $padded, '10111010000' ) + substr_count( $padded, '00001011101' ) );
		}
		$dark = 0;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				$c     = $this->modules[ $y ][ $x ];
				$dark += $c ? 1 : 0;
				if ( $x < $n - 1 && $y < $n - 1 && $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}
		$total  = $n * $n;
		$k      = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
		$score += max( 0, $k ) * 10;
		return $score;
	}

	/* GF(256) Reed–Solomon ------------------------------------------------ */

	/**
	 * Multiply in GF(256) with polynomial 0x11D.
	 *
	 * @param int $x X.
	 * @param int $y Y.
	 * @return int
	 */
	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	/**
	 * Generator polynomial coefficients (highest degree omitted).
	 *
	 * @param int $degree Degree.
	 * @return int[]
	 */
	private static function generator( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * Reed–Solomon remainder.
	 *
	 * @param int[] $data Data.
	 * @param int[] $gen  Generator.
	 * @return int[]
	 */
	private static function remainder( array $data, array $gen ) {
		$result = array_fill( 0, count( $gen ), 0 );
		foreach ( $data as $b ) {
			$factor = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $gen as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}
}
