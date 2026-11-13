<?php
/**
 * Slovak "PAY by square" payment QR payload (used for EUR orders).
 *
 * Payload = base32hex( header + length + LZMA1( crc32 + tab-separated fields ) ).
 * The LZMA stream is written by a small literal-only encoder (valid LZMA, no compression),
 * so no external library or xz binary is needed.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Pay_By_Square {

	const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

	/**
	 * @param string $iban    IBAN without spaces.
	 * @param float  $amount  Amount.
	 * @param string $currency ISO code.
	 * @param string $vs      Variable symbol (digits only, max 10), otherwise skipped.
	 * @param string $message Note for the recipient (max 140).
	 * @param string $bic     Optional BIC/SWIFT.
	 */
	public static function payload( $iban, $amount, $currency, $vs, $message, $bic = '' ) {
		$vs   = trim( (string) $vs );
		$vs   = ( '' !== $vs && ctype_digit( $vs ) && strlen( $vs ) <= 10 ) ? $vs : '';
		$note = function_exists( 'remove_accents' ) ? remove_accents( (string) $message ) : (string) $message;
		$note = trim( substr( preg_replace( '/[^\x20-\x7E]/', '', str_replace( "\t", ' ', $note ) ), 0, 140 ) );

		$fields = array(
			'',                                         // Invoice ID.
			'1',                                        // Number of payments.
			'1',                                        // Payment order.
			number_format( (float) $amount, 2, '.', '' ),
			strtoupper( (string) $currency ),
			'',                                         // Due date.
			$vs,
			'',                                         // Constant symbol.
			'',                                         // Specific symbol.
			'',                                         // Originator's reference.
			$note,
			'1',                                        // Number of bank accounts.
			strtoupper( preg_replace( '/\s+/', '', (string) $iban ) ),
			strtoupper( (string) $bic ),
			'0',                                        // Standing order extension.
			'0',                                        // Direct debit extension.
		);
		$data = implode( "\t", $fields );
		$data = strrev( hash( 'crc32b', $data, true ) ) . $data;

		$bin  = "\x00\x00" . pack( 'v', strlen( $data ) ) . self::lzma( $data );
		$bits = '';
		foreach ( str_split( $bin ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$rem = strlen( $bits ) % 5;
		if ( $rem ) {
			$bits .= str_repeat( '0', 5 - $rem );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( $chunk ) ];
		}
		return $out;
	}

	/**
	 * Raw LZMA1 stream (lc=3, lp=0, pb=2) that encodes every byte as a literal, followed by the end marker.
	 */
	public static function lzma( $data ) {
		$rc = array(
			'low'   => 0,
			'range' => 0xFFFFFFFF,
			'cache' => 0,
			'size'  => 1,
			'out'   => '',
		);
		$is_match  = array_fill( 0, 4, 1024 );   // state 0 only, posState 0..3.
		$literal   = array_fill( 0, 8 * 0x300, 1024 );
		$is_rep    = 1024;
		$len_ch    = 1024;
		$len_low   = array_fill( 0, 8, 1024 );    // posState low tree (3 bits) per posState 0..3 → only one used at a time.
		$pos_slot  = array_fill( 0, 64, 1024 );
		$align     = array_fill( 0, 16, 1024 );
		$prev      = 0;
		$len       = strlen( $data );

		for ( $i = 0; $i < $len; $i++ ) {
			$byte = ord( $data[ $i ] );
			self::bit( $rc, $is_match[ $i & 3 ], 0 );
			$base = 0x300 * ( $prev >> 5 );
			$sym  = 1;
			for ( $b = 7; $b >= 0; $b-- ) {
				$bit = ( $byte >> $b ) & 1;
				self::bit( $rc, $literal[ $base + $sym ], $bit );
				$sym = ( $sym << 1 ) | $bit;
			}
			$prev = $byte;
		}

		// End marker: match, not rep, length 2, distance 0xFFFFFFFF.
		self::bit( $rc, $is_match[ $len & 3 ], 1 );
		self::bit( $rc, $is_rep, 0 );
		self::bit( $rc, $len_ch, 0 );
		self::tree( $rc, $len_low, 3, 0 );
		self::tree( $rc, $pos_slot, 6, 63 );
		self::direct( $rc, 0x3FFFFFF, 26 );
		$m = 1;
		for ( $i = 0; $i < 4; $i++ ) {
			self::bit( $rc, $align[ $m ], 1 );
			$m = ( $m << 1 ) | 1;
		}

		for ( $i = 0; $i < 5; $i++ ) {
			self::shift_low( $rc );
		}
		return $rc['out'];
	}

	private static function tree( array &$rc, array &$probs, $bits, $sym ) {
		$m = 1;
		for ( $i = $bits - 1; $i >= 0; $i-- ) {
			$bit = ( $sym >> $i ) & 1;
			self::bit( $rc, $probs[ $m ], $bit );
			$m = ( $m << 1 ) | $bit;
		}
	}

	private static function bit( array &$rc, &$prob, $bit ) {
		$bound = ( $rc['range'] >> 11 ) * $prob;
		if ( 0 === $bit ) {
			$rc['range'] = $bound;
			$prob       += ( 2048 - $prob ) >> 5;
		} else {
			$rc['low']   += $bound;
			$rc['range'] -= $bound;
			$prob        -= $prob >> 5;
		}
		while ( $rc['range'] < 0x1000000 ) {
			$rc['range'] = ( $rc['range'] << 8 ) & 0xFFFFFFFF;
			self::shift_low( $rc );
		}
	}

	private static function direct( array &$rc, $value, $bits ) {
		for ( $i = $bits - 1; $i >= 0; $i-- ) {
			$rc['range'] >>= 1;
			if ( ( $value >> $i ) & 1 ) {
				$rc['low'] += $rc['range'];
			}
			while ( $rc['range'] < 0x1000000 ) {
				$rc['range'] = ( $rc['range'] << 8 ) & 0xFFFFFFFF;
				self::shift_low( $rc );
			}
		}
	}

	private static function shift_low( array &$rc ) {
		if ( $rc['low'] < 0xFF000000 || $rc['low'] > 0xFFFFFFFF ) {
			$carry = $rc['low'] > 0xFFFFFFFF ? 1 : 0;
			$temp  = $rc['cache'];
			do {
				$rc['out'] .= chr( ( $temp + $carry ) & 0xFF );
				$temp       = 0xFF;
			} while ( --$rc['size'] > 0 );
			$rc['cache'] = ( $rc['low'] >> 24 ) & 0xFF;
		}
		$rc['size']++;
		$rc['low'] = ( $rc['low'] & 0x00FFFFFF ) << 8;
	}
}
