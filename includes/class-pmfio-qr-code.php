<?php
/**
 * Minimal QR code encoder: byte mode, error correction level M, versions 1–10.
 * Enough for Czech "QR Platba" (SPD) strings. No dependencies.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_QR_Code {

	/**
	 * Level M block structure per version: [ ec codewords per block, [ [block count, data codewords], ... ] ].
	 */
	const BLOCKS = array(
		1  => array( 10, array( array( 1, 16 ) ) ),
		2  => array( 16, array( array( 1, 28 ) ) ),
		3  => array( 26, array( array( 1, 44 ) ) ),
		4  => array( 18, array( array( 2, 32 ) ) ),
		5  => array( 24, array( array( 2, 43 ) ) ),
		6  => array( 16, array( array( 4, 27 ) ) ),
		7  => array( 18, array( array( 4, 31 ) ) ),
		8  => array( 22, array( array( 2, 38 ), array( 2, 39 ) ) ),
		9  => array( 22, array( array( 3, 36 ), array( 2, 37 ) ) ),
		10 => array( 26, array( array( 4, 43 ), array( 1, 44 ) ) ),
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

	private $size;
	private $modules;
	private $is_function;

	/**
	 * @return bool[][]|null Matrix [row][col] (true = dark) or null when the text is too long.
	 */
	public static function matrix( $text ) {
		$bytes   = array_values( unpack( 'C*', (string) $text ) ?: array() );
		$version = 0;
		foreach ( self::BLOCKS as $v => $spec ) {
			$capacity = 0;
			foreach ( $spec[1] as $g ) {
				$capacity += $g[0] * $g[1];
			}
			$bits = 4 + ( $v < 10 ? 8 : 16 ) + 8 * count( $bytes );
			if ( $bits <= $capacity * 8 ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			return null;
		}
		$qr = new self();
		return $qr->build( $version, $bytes );
	}

	private function build( $version, array $bytes ) {
		$this->size        = 17 + 4 * $version;
		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->is_function = $this->modules;

		$this->draw_function_patterns( $version );
		$this->draw_codewords( $this->codewords( $version, $bytes ) );

		$best      = 0;
		$min_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$score = $this->penalty();
			if ( $score < $min_score ) {
				$best      = $mask;
				$min_score = $score;
			}
			$this->apply_mask( $mask );
		}
		$this->apply_mask( $best );
		$this->draw_format_bits( $best );
		return $this->modules;
	}

	private function codewords( $version, array $bytes ) {
		list( $ec_len, $groups ) = self::BLOCKS[ $version ];
		$capacity = 0;
		foreach ( $groups as $g ) {
			$capacity += $g[0] * $g[1];
		}

		$bits = array();
		$push = function ( $value, $len ) use ( &$bits ) {
			for ( $i = $len - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};
		$push( 4, 4 );
		$push( count( $bytes ), $version < 10 ? 8 : 16 );
		foreach ( $bytes as $b ) {
			$push( $b, 8 );
		}
		$push( 0, min( 4, $capacity * 8 - count( $bits ) ) );
		$push( 0, ( 8 - count( $bits ) % 8 ) % 8 );

		$data = array();
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$data[] = bindec( implode( '', $chunk ) );
		}
		for ( $pad = 0xEC; count( $data ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$data[] = $pad;
		}

		$divisor = self::rs_divisor( $ec_len );
		$blocks  = array();
		$offset  = 0;
		foreach ( $groups as $g ) {
			for ( $n = 0; $n < $g[0]; $n++ ) {
				$chunk    = array_slice( $data, $offset, $g[1] );
				$offset  += $g[1];
				$blocks[] = array( $chunk, self::rs_remainder( $chunk, $divisor ) );
			}
		}

		$out     = array();
		$max_len = max( array_map( function ( $b ) {
			return count( $b[0] );
		}, $blocks ) );
		for ( $i = 0; $i < $max_len; $i++ ) {
			foreach ( $blocks as $b ) {
				if ( $i < count( $b[0] ) ) {
					$out[] = $b[0][ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $blocks as $b ) {
				$out[] = $b[1][ $i ];
			}
		}
		return $out;
	}

	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D ) ) & 0xFF;
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	private static function rs_divisor( $degree ) {
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

	private static function rs_remainder( array $data, array $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	private function set_function( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]     = $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns( $version ) {
		$s = $this->size;
		for ( $i = 0; $i < $s; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $s - 4, 3 ), array( 3, $s - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $c[0] + $dx;
					$y = $c[1] + $dy;
					if ( $x >= 0 && $x < $s && $y >= 0 && $y < $s ) {
						$dist = max( abs( $dx ), abs( $dy ) );
						$this->set_function( $x, $y, 2 !== $dist && 4 !== $dist );
					}
				}
			}
		}
		$pos = self::ALIGN[ $version ];
		$n   = count( $pos );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $n - 1 === $j ) || ( $n - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format_bits( 0 );
		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = 1 === ( ( $bits >> $i ) & 1 );
				$a   = $s - 11 + $i % 3;
				$b   = intdiv( $i, 3 );
				$this->set_function( $a, $b, $bit );
				$this->set_function( $b, $a, $bit );
			}
		}
	}

	private function draw_format_bits( $mask ) {
		$data = ( 0 << 3 ) | $mask; // Level M = 00.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = function ( $i ) use ( $bits ) {
			return 1 === ( ( $bits >> $i ) & 1 );
		};
		$s = $this->size;
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $s - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $s - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $s - 8, true );
	}

	private function draw_codewords( array $data ) {
		$s     = $this->size;
		$total = count( $data ) * 8;
		$i     = 0;
		for ( $right = $s - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $s; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $s - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( $mask ) {
		$s = $this->size;
		for ( $y = 0; $y < $s; $y++ ) {
			for ( $x = 0; $x < $s; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === $x * $y % 2 + $x * $y % 3;
						break;
					case 6:
						$invert = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	private function penalty() {
		$s     = $this->size;
		$m     = $this->modules;
		$score = 0;
		$dark  = 0;
		for ( $a = 0; $a < $s; $a++ ) {
			$row = '';
			$col = '';
			for ( $b = 0; $b < $s; $b++ ) {
				$row .= $m[ $a ][ $b ] ? '1' : '0';
				$col .= $m[ $b ][ $a ] ? '1' : '0';
				$dark += $m[ $a ][ $b ] ? 1 : 0;
			}
			foreach ( array( $row, $col ) as $line ) {
				if ( preg_match_all( '/0{5,}|1{5,}/', $line, $runs ) ) {
					foreach ( $runs[0] as $run ) {
						$score += 3 + strlen( $run ) - 5;
					}
				}
				$score += 40 * ( substr_count( $line, '10111010000' ) + substr_count( $line, '00001011101' ) );
			}
		}
		for ( $y = 0; $y < $s - 1; $y++ ) {
			for ( $x = 0; $x < $s - 1; $x++ ) {
				$c = $m[ $y ][ $x ];
				if ( $c === $m[ $y ][ $x + 1 ] && $c === $m[ $y + 1 ][ $x ] && $c === $m[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}
		$score += 10 * intdiv( abs( $dark * 20 - $s * $s * 10 ), $s * $s );
		return $score;
	}

	/**
	 * Tags and attributes of the generated SVG, for wp_kses().
	 */
	public static function allowed_svg() {
		return array(
			'svg'  => array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'shape-rendering' => true, 'role' => true, 'aria-label' => true ),
			'rect' => array( 'width' => true, 'height' => true, 'fill' => true ),
			'path' => array( 'd' => true, 'fill' => true ),
		);
	}

	/** Inline SVG markup. */
	public static function svg( $text, $px = 200, $quiet = 4 ) {
		$m = self::matrix( $text );
		if ( null === $m ) {
			return '';
		}
		$n    = count( $m );
		$full = $n + 2 * $quiet;
		$path = '';
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $on ) {
				if ( $on ) {
					$path .= 'M' . ( $x + $quiet ) . ' ' . ( $y + $quiet ) . 'h1v1h-1z';
				}
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" width="' . (int) $px . '" height="' . (int) $px . '" shape-rendering="crispEdges" role="img"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
	}

	/** PNG binary (needs GD), or '' when GD is missing. */
	public static function png( $text, $scale = 6, $quiet = 4 ) {
		$m = self::matrix( $text );
		if ( null === $m || ! function_exists( 'imagecreate' ) ) {
			return '';
		}
		$n    = count( $m );
		$full = ( $n + 2 * $quiet ) * $scale;
		$im   = imagecreate( $full, $full );
		imagecolorallocate( $im, 255, 255, 255 );
		$black = imagecolorallocate( $im, 0, 0, 0 );
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $on ) {
				if ( $on ) {
					$x0 = ( $x + $quiet ) * $scale;
					$y0 = ( $y + $quiet ) * $scale;
					imagefilledrectangle( $im, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $black );
				}
			}
		}
		ob_start();
		imagepng( $im );
		return (string) ob_get_clean();
	}
}
