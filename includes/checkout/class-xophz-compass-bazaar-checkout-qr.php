<?php
/**
 * A QR code drawn on our own server, so a receipt never calls another site.
 *
 * Original code (GPL-compatible, written for this plugin), following the QR
 * Code Model 2 spec (ISO/IEC 18004): byte mode, error correction level L,
 * versions 1 to 9 (up to 230 bytes; a receipt link is about 90). Longer text
 * gives no code. Checked by decoding the output with ZXing up to 200 bytes.
 * Pure PHP: no WordPress calls.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Qr {

  /** Per version 1..10 at level L: [data codewords, EC codewords per block, blocks]. */
  const SPEC = [
    1  => [ 19, 7, 1 ],
    2  => [ 34, 10, 1 ],
    3  => [ 55, 15, 1 ],
    4  => [ 80, 20, 1 ],
    5  => [ 108, 26, 1 ],
    6  => [ 136, 18, 2 ],
    7  => [ 156, 20, 2 ],
    8  => [ 194, 24, 2 ],
    9  => [ 232, 30, 2 ],
  ];

  const ALIGN = [
    2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ], 6 => [ 6, 34 ],
    7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ],
  ];

  /** @var int[]|null */
  private static $exp;
  /** @var int[]|null */
  private static $log;

  /**
   * The module grid for a text: rows of booleans (true is dark). Null when
   * the text is empty or too long for version 9.
   *
   * @return array<int,array<int,bool>>|null
   */
  public static function matrix( string $text ) {
    $bytes = '' === $text ? [] : array_values( unpack( 'C*', $text ) );
    $n     = count( $bytes );
    $ver   = 0;
    foreach ( self::SPEC as $v => $spec ) {
      if ( 4 + 8 + 8 * $n <= 8 * $spec[0] ) {
        $ver = $v;
        break;
      }
    }
    if ( 0 === $ver || 0 === $n ) {
      return null;
    }
    return self::draw( self::codewords( $bytes, $ver ), $ver );
  }

  /** An SVG document for a text, or '' when it does not fit. Only numbers go into the markup. */
  public static function svg( string $text, int $quiet = 4 ): string {
    $m = self::matrix( $text );
    if ( null === $m ) {
      return '';
    }
    $size = count( $m );
    $full = $size + 2 * $quiet;
    $path = '';
    foreach ( $m as $y => $row ) {
      $x = 0;
      while ( $x < $size ) {
        if ( ! $row[ $x ] ) {
          ++$x;
          continue;
        }
        $start = $x;
        while ( $x < $size && $row[ $x ] ) {
          ++$x;
        }
        $path .= 'M' . ( $start + $quiet ) . ' ' . ( $y + $quiet ) . 'h' . ( $x - $start ) . 'v1h-' . ( $x - $start ) . 'z';
      }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
      . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
  }

  /** Data + error correction codewords, blocks interleaved. */
  private static function codewords( array $bytes, int $ver ): array {
    list( $data_cw, $ec_len, $blocks ) = self::SPEC[ $ver ];
    $bits = '0100' . str_pad( decbin( count( $bytes ) ), 8, '0', STR_PAD_LEFT );
    foreach ( $bytes as $b ) {
      $bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
    }
    $bits .= str_repeat( '0', min( 4, $data_cw * 8 - strlen( $bits ) ) );
    $bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
    $data  = array_map( 'bindec', str_split( $bits, 8 ) );
    for ( $pad = 0xEC; count( $data ) < $data_cw; $pad ^= 0xEC ^ 0x11 ) {
      $data[] = $pad;
    }

    // Short blocks first; the last blocks take one more codeword each.
    $short  = intdiv( $data_cw, $blocks );
    $extra  = $data_cw % $blocks;
    $chunks = [];
    $ecs    = [];
    $at     = 0;
    for ( $i = 0; $i < $blocks; $i++ ) {
      $len      = $short + ( $i >= $blocks - $extra ? 1 : 0 );
      $chunk    = array_slice( $data, $at, $len );
      $at      += $len;
      $chunks[] = $chunk;
      $ecs[]    = self::remainder( $chunk, $ec_len );
    }
    $out = [];
    for ( $i = 0; $i <= $short; $i++ ) {
      foreach ( $chunks as $chunk ) {
        if ( $i < count( $chunk ) ) {
          $out[] = $chunk[ $i ];
        }
      }
    }
    for ( $i = 0; $i < $ec_len; $i++ ) {
      foreach ( $ecs as $ec ) {
        $out[] = $ec[ $i ];
      }
    }
    return $out;
  }

  private static function tables(): void {
    if ( null !== self::$exp ) {
      return;
    }
    self::$exp = [];
    self::$log = array_fill( 0, 256, 0 );
    $x         = 1;
    for ( $i = 0; $i < 255; $i++ ) {
      self::$exp[ $i ] = $x;
      self::$log[ $x ] = $i;
      $x             <<= 1;
      if ( $x & 0x100 ) {
        $x ^= 0x11D;
      }
    }
    for ( $i = 255; $i < 512; $i++ ) {
      self::$exp[ $i ] = self::$exp[ $i - 255 ];
    }
  }

  private static function mul( int $a, int $b ): int {
    if ( 0 === $a || 0 === $b ) {
      return 0;
    }
    return self::$exp[ self::$log[ $a ] + self::$log[ $b ] ];
  }

  /** Reed-Solomon remainder of the data polynomial. */
  private static function remainder( array $data, int $degree ): array {
    self::tables();
    $gen = [ 1 ];
    for ( $i = 0; $i < $degree; $i++ ) {
      $next = array_fill( 0, count( $gen ) + 1, 0 );
      foreach ( $gen as $j => $coef ) {
        $next[ $j ]     ^= $coef;
        $next[ $j + 1 ] ^= self::mul( $coef, self::$exp[ $i ] );
      }
      $gen = $next;
    }
    $rem = array_fill( 0, $degree, 0 );
    foreach ( $data as $byte ) {
      $factor = $byte ^ array_shift( $rem );
      $rem[]  = 0;
      foreach ( $rem as $j => $_ ) {
        $rem[ $j ] ^= self::mul( $gen[ $j + 1 ], $factor );
      }
    }
    return $rem;
  }

  /** Place the codewords, pick the best mask, add format and version bits. */
  private static function draw( array $codewords, int $ver ): array {
    $size = 17 + 4 * $ver;
    $mod  = array_fill( 0, $size, array_fill( 0, $size, false ) );
    $fn   = array_fill( 0, $size, array_fill( 0, $size, false ) );
    $set  = function ( int $x, int $y, bool $dark ) use ( &$mod, &$fn, $size ): void {
      if ( $x >= 0 && $y >= 0 && $x < $size && $y < $size ) {
        $mod[ $y ][ $x ] = $dark;
        $fn[ $y ][ $x ]  = true;
      }
    };

    for ( $i = 0; $i < $size; $i++ ) {
      $set( 6, $i, 0 === $i % 2 );
      $set( $i, 6, 0 === $i % 2 );
    }
    foreach ( [ [ 3, 3 ], [ $size - 4, 3 ], [ 3, $size - 4 ] ] as $c ) {
      for ( $dy = -4; $dy <= 4; $dy++ ) {
        for ( $dx = -4; $dx <= 4; $dx++ ) {
          $dist = max( abs( $dx ), abs( $dy ) );
          $set( $c[0] + $dx, $c[1] + $dy, 2 !== $dist && 4 !== $dist );
        }
      }
    }
    $pos = self::ALIGN[ $ver ] ?? [];
    $end = count( $pos ) - 1;
    foreach ( $pos as $i => $cy ) {
      foreach ( $pos as $j => $cx ) {
        $on_finder = ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $end ) || ( $i === $end && 0 === $j );
        if ( $on_finder ) {
          continue;
        }
        for ( $dy = -2; $dy <= 2; $dy++ ) {
          for ( $dx = -2; $dx <= 2; $dx++ ) {
            $set( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
          }
        }
      }
    }
    // Reserve the format area so data skips it; the real bits come per mask.
    self::format( $set, $size, 0 );
    if ( $ver >= 7 ) {
      $rem = $ver;
      for ( $i = 0; $i < 12; $i++ ) {
        $rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
      }
      $bits = ( $ver << 12 ) | $rem;
      for ( $i = 0; $i < 18; $i++ ) {
        $dark = (bool) ( ( $bits >> $i ) & 1 );
        $a    = $size - 11 + $i % 3;
        $b    = intdiv( $i, 3 );
        $set( $a, $b, $dark );
        $set( $b, $a, $dark );
      }
    }

    // Data runs in two-column strips from the bottom right, zigzagging.
    $i     = 0;
    $total = count( $codewords ) * 8;
    for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
      if ( 6 === $right ) {
        $right = 5;
      }
      for ( $vert = 0; $vert < $size; $vert++ ) {
        for ( $j = 0; $j < 2; $j++ ) {
          $x = $right - $j;
          $y = 0 === ( ( $right + 1 ) & 2 ) ? $size - 1 - $vert : $vert;
          if ( ! $fn[ $y ][ $x ] && $i < $total ) {
            $mod[ $y ][ $x ] = (bool) ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
            ++$i;
          }
        }
      }
    }

    $best       = $mod;
    $best_score = PHP_INT_MAX;
    for ( $mask = 0; $mask < 8; $mask++ ) {
      $try = $mod;
      for ( $y = 0; $y < $size; $y++ ) {
        for ( $x = 0; $x < $size; $x++ ) {
          if ( ! $fn[ $y ][ $x ] && self::masked( $mask, $x, $y ) ) {
            $try[ $y ][ $x ] = ! $try[ $y ][ $x ];
          }
        }
      }
      $put = function ( int $x, int $y, bool $dark ) use ( &$try ): void {
        $try[ $y ][ $x ] = $dark;
      };
      self::format( $put, $size, $mask );
      $score = self::penalty( $try );
      if ( $score < $best_score ) {
        $best_score = $score;
        $best       = $try;
      }
    }
    return $best;
  }

  private static function masked( int $mask, int $x, int $y ): bool {
    switch ( $mask ) {
      case 0:
        return 0 === ( $x + $y ) % 2;
      case 1:
        return 0 === $y % 2;
      case 2:
        return 0 === $x % 3;
      case 3:
        return 0 === ( $x + $y ) % 3;
      case 4:
        return 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
      case 5:
        return 0 === $x * $y % 2 + $x * $y % 3;
      case 6:
        return 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
      default:
        return 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
    }
  }

  /** Format bits (level L, a mask) in both places, plus the always-dark module. */
  private static function format( callable $set, int $size, int $mask ): void {
    $data = ( 1 << 3 ) | $mask;
    $rem  = $data;
    for ( $i = 0; $i < 10; $i++ ) {
      $rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
    }
    $bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
    $bit  = function ( int $i ) use ( $bits ): bool {
      return (bool) ( ( $bits >> $i ) & 1 );
    };
    for ( $i = 0; $i <= 5; $i++ ) {
      $set( 8, $i, $bit( $i ) );
    }
    $set( 8, 7, $bit( 6 ) );
    $set( 8, 8, $bit( 7 ) );
    $set( 7, 8, $bit( 8 ) );
    for ( $i = 9; $i < 15; $i++ ) {
      $set( 14 - $i, 8, $bit( $i ) );
    }
    for ( $i = 0; $i < 8; $i++ ) {
      $set( $size - 1 - $i, 8, $bit( $i ) );
    }
    for ( $i = 8; $i < 15; $i++ ) {
      $set( 8, $size - 15 + $i, $bit( $i ) );
    }
    $set( 8, $size - 8, true );
  }

  /** Spec penalty rules 1 to 4: lower reads better. */
  private static function penalty( array $m ): int {
    $size  = count( $m );
    $score = 0;
    $dark  = 0;
    $lines = [];
    for ( $y = 0; $y < $size; $y++ ) {
      $row = '';
      $col = '';
      for ( $x = 0; $x < $size; $x++ ) {
        $row .= $m[ $y ][ $x ] ? '1' : '0';
        $col .= $m[ $x ][ $y ] ? '1' : '0';
      }
      $dark   += substr_count( $row, '1' );
      $lines[] = $row;
      $lines[] = $col;
    }
    foreach ( $lines as $line ) {
      if ( preg_match_all( '/0{5,}|1{5,}/', $line, $runs ) ) {
        foreach ( $runs[0] as $run ) {
          $score += 3 + strlen( $run ) - 5;
        }
      }
      $score += 40 * preg_match_all( '/(?=(00001011101|10111010000))/', $line );
    }
    for ( $y = 0; $y < $size - 1; $y++ ) {
      for ( $x = 0; $x < $size - 1; $x++ ) {
        $c = $m[ $y ][ $x ];
        if ( $c === $m[ $y ][ $x + 1 ] && $c === $m[ $y + 1 ][ $x ] && $c === $m[ $y + 1 ][ $x + 1 ] ) {
          $score += 3;
        }
      }
    }
    $total = $size * $size;
    $k     = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
    return $score + 10 * max( 0, $k );
  }
}
