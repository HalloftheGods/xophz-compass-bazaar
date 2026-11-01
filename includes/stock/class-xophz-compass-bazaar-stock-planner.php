<?php
/**
 * Pure planning of one stock batch: running balances, oversell and shortfalls.
 *
 * No I/O. Lines are applied in order, so two lines on the same (product,
 * location) see each other. Rules (spec 8.4):
 * - A decrement that leaves the balance below zero is a shortfall, unless the
 *   line allows negative (it carries an oversell approval) or the product's
 *   backorders are allowed. An allowed one is marked oversold.
 * - Increments never fail and are never oversold.
 * - In observed mode (opening and reconcile batches, which record what already
 *   happened) nothing is a shortfall and nothing is oversold.
 * Location 0 always counts toward the projectable sum; another location counts
 * while it is enabled.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Planner {

  /** Quantities closer than this are equal (the ledger keeps 4 decimals). */
  const EPSILON = 0.00005;

  /**
   * @param Xophz_Compass_Bazaar_Stock_Line[] $lines
   * @param array $balances "pid:loc" => ['on_hand', 'is_enabled', 'last_movement_id']; a missing pair starts at 0, enabled.
   * @param array $wc_values pid => locked WooCommerce `_stock`.
   * @param array $product_info pid => Stock_Wc_Port::product_info() array.
   * @return array [
   *   'movements'  => list of ['line' => Stock_Line, 'balance_after' => float, 'oversold' => bool],
   *   'balances'   => the balances after the batch (same shape as the input),
   *   'deltas'     => pid => projectable delta (0.0 for a product whose projectable sum does not move),
   *   'projected'  => pid => wc value + projectable delta,
   *   'shortfalls' => list of [line_no, product_id, stock_product_id, variation_id, location_id, requested, available],
   * ]
   */
  public static function plan( array $lines, array $balances, array $wc_values, array $product_info, bool $observed = false ): array {
    $movements  = [];
    $deltas     = [];
    $shortfalls = [];
    foreach ( array_values( $lines ) as $index => $line ) {
      $pid = $line->stock_product_id();
      $loc = $line->location_id();
      $key = $pid . ':' . $loc;
      if ( ! isset( $balances[ $key ] ) ) {
        $balances[ $key ] = [ 'on_hand' => 0.0, 'is_enabled' => true, 'last_movement_id' => 0 ];
      }
      $qty      = $line->qty_delta();
      $before   = (float) $balances[ $key ]['on_hand'];
      $after    = Xophz_Compass_Bazaar_Stock_Line::qty( $before + $qty );
      $oversold = false;
      if ( ! $observed && $qty < 0 && $after < -self::EPSILON ) {
        if ( $line->allow_negative() || ! empty( $product_info[ $pid ]['backorders_allowed'] ) ) {
          $oversold = true;
        } else {
          $shortfalls[] = [
            'line_no'          => $index + 1,
            'product_id'       => $line->product_id() ?: $pid,
            'stock_product_id' => $pid,
            'variation_id'     => $line->variation_id(),
            'location_id'      => $loc,
            'requested'        => -$qty,
            'available'        => Xophz_Compass_Bazaar_Stock_Line::qty( max( 0.0, $before ) ),
          ];
        }
      }
      $balances[ $key ]['on_hand'] = $after;
      $deltas[ $pid ]              = $deltas[ $pid ] ?? 0.0;
      if ( self::projectable( $loc, $balances[ $key ] ) ) {
        $deltas[ $pid ] = Xophz_Compass_Bazaar_Stock_Line::qty( $deltas[ $pid ] + $qty );
      }
      $movements[] = [ 'line' => $line, 'balance_after' => $after, 'oversold' => $oversold ];
    }
    $projected = [];
    foreach ( $deltas as $pid => $delta ) {
      $projected[ $pid ] = Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $wc_values[ $pid ] ?? 0.0 ) + $delta );
    }
    return [
      'movements'  => $movements,
      'balances'   => $balances,
      'deltas'     => $deltas,
      'projected'  => $projected,
      'shortfalls' => $shortfalls,
    ];
  }

  /** Location 0 plus every enabled location, for one product. */
  public static function projectable_sum( int $pid, array $balances ): float {
    $sum = 0.0;
    foreach ( $balances as $key => $row ) {
      list( $row_pid, $row_loc ) = array_map( 'intval', explode( ':', (string) $key ) );
      if ( $row_pid === $pid && self::projectable( $row_loc, $row ) ) {
        $sum += (float) $row['on_hand'];
      }
    }
    return Xophz_Compass_Bazaar_Stock_Line::qty( $sum );
  }

  /**
   * Opening entries keyed by location, with the residual on location 0 so the
   * projectable sum equals the WooCommerce value. Entries with a negative
   * location are ignored; a repeated location is summed.
   *
   * @param array[] $raw List of [loc, qty, enabled] from Stock_Wc_Port::initial_balances().
   * @return array loc => ['qty' => float, 'enabled' => bool], ascending loc.
   */
  public static function opening_entries( array $raw, float $wc_value ): array {
    $entries = [ 0 => [ 'qty' => 0.0, 'enabled' => true ] ];
    foreach ( $raw as $entry ) {
      $loc = (int) ( $entry[0] ?? -1 );
      if ( $loc >= 0 ) {
        $entries[ $loc ] = [
          'qty'     => Xophz_Compass_Bazaar_Stock_Line::qty( ( $entries[ $loc ]['qty'] ?? 0.0 ) + (float) ( $entry[1] ?? 0 ) ),
          'enabled' => 0 === $loc || ! empty( $entry[2] ),
        ];
      }
    }
    $projectable = 0.0;
    foreach ( $entries as $entry ) {
      $projectable += $entry['enabled'] ? $entry['qty'] : 0.0;
    }
    $entries[0]['qty'] = Xophz_Compass_Bazaar_Stock_Line::qty( $entries[0]['qty'] + $wc_value - $projectable );
    ksort( $entries );
    return $entries;
  }

  /** loc => on_hand for one product, ascending loc. */
  public static function locations( int $pid, array $balances ): array {
    $out = [];
    foreach ( $balances as $key => $row ) {
      list( $row_pid, $row_loc ) = array_map( 'intval', explode( ':', (string) $key ) );
      if ( $row_pid === $pid ) {
        $out[ $row_loc ] = (float) $row['on_hand'];
      }
    }
    ksort( $out );
    return $out;
  }

  /** @return int[] Unique stock keys of the lines, ascending. */
  public static function pids( array $lines ): array {
    $pids = [];
    foreach ( $lines as $line ) {
      $pids[ $line->stock_product_id() ] = true;
    }
    $pids = array_keys( $pids );
    sort( $pids );
    return $pids;
  }

  /** @return array[] [pid, loc] per line. */
  public static function pairs( array $lines ): array {
    return array_map(
      function ( Xophz_Compass_Bazaar_Stock_Line $line ) {
        return [ $line->stock_product_id(), $line->location_id() ];
      },
      $lines
    );
  }

  /** @return array[] The [pid, loc] pairs without repeats, ordered by (pid, loc): the lock order. */
  public static function sorted_pairs( array $pairs ): array {
    $unique = [];
    foreach ( $pairs as $pair ) {
      $unique[ (int) $pair[0] . ':' . (int) $pair[1] ] = [ (int) $pair[0], (int) $pair[1] ];
    }
    $list = array_values( $unique );
    usort(
      $list,
      function ( $a, $b ) {
        return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
      }
    );
    return $list;
  }

  /** True when the two quantities differ at ledger precision. */
  public static function differs( float $a, float $b ): bool {
    return abs( $a - $b ) >= self::EPSILON;
  }

  private static function projectable( int $loc, array $row ): bool {
    return 0 === $loc || ! empty( $row['is_enabled'] );
  }
}
