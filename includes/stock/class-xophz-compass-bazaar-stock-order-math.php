<?php
/**
 * Pure order-to-ledger arithmetic (spec 8.7).
 *
 * For each order item and location, net outflow = -SUM(qty_delta) of the
 * order's movements. sync deltas() bring each item's total net outflow to its
 * target, where target = committed ? quantity - restocked : 0 (never below 0):
 * - more outflow needed: one movement at the line's current location, reason
 *   sale (the item has no movement yet) or resale;
 * - less outflow needed: stock returns to the locations it left, in ascending
 *   location order, reason sale_reversal.
 * An item that has movements but is no longer on the order has target 0.
 *
 * Order line shape: order_item_id, stock_product_id, product_id, variation_id,
 * location_id (the current sale location), quantity, allow_negative (optional).
 * $sums is Stock_Store::order_sums().
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Order_Math {

  const EPSILON = 0.00005;

  /**
   * @return array[] Each: order_item_id, stock_product_id, product_id, variation_id, location_id, qty_delta, reason, allow_negative.
   */
  public static function deltas( array $order_lines, array $sums, bool $committed ): array {
    $items = $sums['items'] ?? [];
    $out   = [];
    $seen  = [];
    foreach ( $order_lines as $raw ) {
      $line                         = self::normalize( $raw );
      $id                           = $line['order_item_id'];
      $seen[ $id ]                  = true;
      $locs                         = $items[ $id ] ?? [];
      list( $outflow, $restocked )  = self::totals( $locs );
      $target                       = $committed ? min( $line['quantity'], max( 0.0, $line['quantity'] - $restocked ) ) : 0.0;
      $diff                         = Xophz_Compass_Bazaar_Stock_Line::qty( $target - $outflow );
      if ( $diff > self::EPSILON ) {
        $out[] = self::delta( $line, $line['location_id'], -$diff, $locs ? 'resale' : 'sale' );
      } elseif ( $diff < -self::EPSILON ) {
        $out = array_merge( $out, self::returns( $line, $locs, -$diff, 'sale_reversal' ) );
      }
    }
    foreach ( $items as $id => $locs ) {
      if ( isset( $seen[ (int) $id ] ) ) {
        continue;
      }
      list( $outflow ) = self::totals( $locs );
      if ( $outflow > self::EPSILON ) {
        $out = array_merge( $out, self::returns( self::line_from_sums( (int) $id, $locs ), $locs, $outflow, 'sale_reversal' ) );
      }
    }
    return $out;
  }

  /**
   * Refund restock lines: each item's quantity, capped at its net outflow,
   * returned to the locations it left (ascending location order).
   *
   * @param array $item_qtys order_item_id => quantity to restock.
   * @return array[] Same shape as deltas(), reason refund_restock.
   */
  public static function restock( array $order_lines, array $sums, array $item_qtys ): array {
    $items  = $sums['items'] ?? [];
    $byitem = [];
    foreach ( $order_lines as $raw ) {
      $line                            = self::normalize( $raw );
      $byitem[ $line['order_item_id'] ] = $line;
    }
    $out = [];
    ksort( $item_qtys );
    foreach ( $item_qtys as $id => $qty ) {
      $id   = (int) $id;
      $locs = $items[ $id ] ?? [];
      if ( ! $locs ) {
        continue;
      }
      list( $outflow ) = self::totals( $locs );
      $amount          = Xophz_Compass_Bazaar_Stock_Line::qty( min( max( 0.0, (float) $qty ), $outflow ) );
      if ( $amount > self::EPSILON ) {
        $line = $byitem[ $id ] ?? self::line_from_sums( $id, $locs );
        $out  = array_merge( $out, self::returns( $line, $locs, $amount, 'refund_restock' ) );
      }
    }
    return $out;
  }

  /** One batch reason for a set of deltas: the shared reason, else resale (committed) or sale_reversal. */
  public static function batch_reason( array $deltas, bool $committed ): string {
    $reasons = array_values( array_unique( array_column( $deltas, 'reason' ) ) );
    if ( 1 === count( $reasons ) ) {
      return $reasons[0];
    }
    return $committed ? 'resale' : 'sale_reversal';
  }

  /**
   * Stock lines for an order's deltas, carrying order_id and order_item_id.
   * Deltas whose stock key is not in $keep (pid => anything) are dropped.
   *
   * @return Xophz_Compass_Bazaar_Stock_Line[]
   */
  public static function stock_lines( int $order_id, array $deltas, array $keep ): array {
    $lines = [];
    foreach ( $deltas as $d ) {
      if ( isset( $keep[ $d['stock_product_id'] ] ) ) {
        $extra   = [ 'product_id' => $d['product_id'], 'variation_id' => $d['variation_id'], 'order_id' => $order_id, 'order_item_id' => $d['order_item_id'], 'allow_negative' => $d['allow_negative'] ];
        $lines[] = new Xophz_Compass_Bazaar_Stock_Line( $d['stock_product_id'], $d['location_id'], $d['qty_delta'], $extra );
      }
    }
    return $lines;
  }

  /** order_item_id => quantity as canonical strings, ascending item id, for a request hash. */
  public static function hash_items( array $item_qtys ): array {
    $items = [];
    foreach ( $item_qtys as $id => $qty ) {
      $items[ (string) (int) $id ] = Xophz_Compass_Bazaar_Stock_Keys::number( (float) $qty );
    }
    ksort( $items, SORT_NUMERIC );
    return $items;
  }

  /** @throws InvalidArgumentException On a missing id or a negative quantity. */
  public static function normalize( array $raw ): array {
    $line = [
      'order_item_id'    => (int) ( $raw['order_item_id'] ?? 0 ),
      'stock_product_id' => (int) ( $raw['stock_product_id'] ?? 0 ),
      'product_id'       => (int) ( $raw['product_id'] ?? 0 ),
      'variation_id'     => (int) ( $raw['variation_id'] ?? 0 ),
      'location_id'      => (int) ( $raw['location_id'] ?? 0 ),
      'quantity'         => Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $raw['quantity'] ?? 0 ) ),
      'allow_negative'   => ! empty( $raw['allow_negative'] ),
    ];
    if ( $line['order_item_id'] <= 0 || $line['stock_product_id'] <= 0 || $line['location_id'] < 0 || $line['quantity'] < 0 ) {
      throw new InvalidArgumentException( 'An order stock line needs order_item_id and stock_product_id, a location of 0 or more and a quantity of 0 or more.' );
    }
    return $line;
  }

  /** @return float[] [net outflow, restocked] over every location of one item. */
  private static function totals( array $locs ): array {
    $net       = 0.0;
    $restocked = 0.0;
    foreach ( $locs as $row ) {
      $net       += (float) ( $row['net'] ?? 0 );
      $restocked += (float) ( $row['restocked'] ?? 0 );
    }
    return [ Xophz_Compass_Bazaar_Stock_Line::qty( -$net ), Xophz_Compass_Bazaar_Stock_Line::qty( $restocked ) ];
  }

  /** Return $amount to the locations the item left, ascending location id. */
  private static function returns( array $line, array $locs, float $amount, string $reason ): array {
    ksort( $locs );
    $out  = [];
    $left = $amount;
    foreach ( $locs as $loc => $row ) {
      $loc_out = Xophz_Compass_Bazaar_Stock_Line::qty( -(float) ( $row['net'] ?? 0 ) );
      if ( $left <= self::EPSILON || $loc_out <= self::EPSILON ) {
        continue;
      }
      $take  = min( $left, $loc_out );
      $out[] = self::delta( $line, (int) $loc, $take, $reason );
      $left  = Xophz_Compass_Bazaar_Stock_Line::qty( $left - $take );
    }
    return $out;
  }

  private static function line_from_sums( int $id, array $locs ): array {
    $first = reset( $locs ) ?: [];
    return [
      'order_item_id'    => $id,
      'stock_product_id' => (int) ( $first['stock_product_id'] ?? 0 ),
      'product_id'       => (int) ( $first['product_id'] ?? 0 ),
      'variation_id'     => (int) ( $first['variation_id'] ?? 0 ),
      'location_id'      => 0,
      'quantity'         => 0.0,
      'allow_negative'   => false,
    ];
  }

  private static function delta( array $line, int $loc, float $qty, string $reason ): array {
    return [
      'order_item_id'    => $line['order_item_id'],
      'stock_product_id' => $line['stock_product_id'],
      'product_id'       => $line['product_id'],
      'variation_id'     => $line['variation_id'],
      'location_id'      => $loc,
      'qty_delta'        => Xophz_Compass_Bazaar_Stock_Line::qty( $qty ),
      'reason'           => $reason,
      'allow_negative'   => $line['allow_negative'],
    ];
  }
}
