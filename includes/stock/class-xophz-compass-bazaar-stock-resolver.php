<?php
/**
 * Stock keys for Stock_Service: which product holds a product's stock, and
 * the lines and order lines moved onto those keys (spec 8.1 "Stock key").
 *
 * Guarantees: every method only reads product info through the port. A
 * product that is missing or does not manage stock has no key, and its
 * lines are dropped. Each key's product info is recorded in $infos.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Resolver {

  /** @var Xophz_Compass_Bazaar_Stock_Wc_Port */
  private $wc;

  public function __construct( Xophz_Compass_Bazaar_Stock_Wc_Port $wc ) {
    $this->wc = $wc;
  }

  /**
   * The stock key for a product or variation, recording the key's product
   * info in $infos. Null when the product is missing or does not manage stock.
   *
   * @return array|null ['key' => int, 'product_id' => int, 'variation_id' => int]
   */
  public function stock_key( int $id, array &$infos ): ?array {
    $info = $id > 0 ? $this->wc->product_info( $id ) : null;
    if ( ! $info || empty( $info['manages_stock'] ) ) {
      return null;
    }
    $key   = (int) ( $info['stock_product_id'] ?? 0 ) ?: $id;
    $owner = $key === $id ? $info : $this->wc->product_info( $key );
    if ( ! $owner || empty( $owner['manages_stock'] ) ) {
      return null;
    }
    $infos[ $key ] = $owner;
    return [ 'key' => $key, 'product_id' => (int) ( $info['product_id'] ?? $id ), 'variation_id' => (int) ( $info['variation_id'] ?? 0 ) ];
  }

  /** Stock_Lines moved to their stock keys; zero lines and products that do not manage stock are dropped. */
  public function lines( array $lines, array &$infos ): array {
    $out = [];
    foreach ( $lines as $line ) {
      $owner = Xophz_Compass_Bazaar_Stock_Planner::differs( $line->qty_delta(), 0.0 ) ? $this->stock_key( $line->stock_product_id(), $infos ) : null;
      if ( $owner ) {
        $out[] = $line->for_stock_key( $owner['key'], $owner['product_id'], $owner['variation_id'] );
      }
    }
    return $out;
  }

  /**
   * Raw order lines (stock_product_id, or variation_id / product_id) on their
   * stock keys, normalized by Stock_Order_Math.
   *
   * @return array [order lines, int[] stock keys]
   */
  public function order_lines( array $lines, array &$infos ): array {
    $out  = [];
    $keys = [];
    foreach ( $lines as $raw ) {
      $id    = (int) ( $raw['stock_product_id'] ?? 0 ) ?: ( (int) ( $raw['variation_id'] ?? 0 ) ?: (int) ( $raw['product_id'] ?? 0 ) );
      $owner = $this->stock_key( $id, $infos );
      if ( ! $owner ) {
        continue;
      }
      $raw['stock_product_id'] = $owner['key'];
      $raw['product_id']       = (int) ( $raw['product_id'] ?? 0 ) ?: $owner['product_id'];
      $raw['variation_id']     = (int) ( $raw['variation_id'] ?? 0 ) ?: $owner['variation_id'];
      $out[]                   = Xophz_Compass_Bazaar_Stock_Order_Math::normalize( $raw );
      $keys[ $owner['key'] ]   = $owner['key'];
    }
    return [ $out, array_values( $keys ) ];
  }

  /** @return int[] The stock keys that manage stock among an order's movement sums (Stock_Store::order_sums). */
  public function order_keys( array $sums, array &$infos ): array {
    $out = [];
    foreach ( $sums['items'] ?? [] as $locs ) {
      foreach ( $locs as $row ) {
        $pid = (int) ( $row['stock_product_id'] ?? 0 );
        if ( $pid > 0 && ! isset( $out[ $pid ] ) && $this->stock_key( $pid, $infos ) ) {
          $out[ $pid ] = $pid;
        }
      }
    }
    return array_values( $out );
  }

  /** product_id and variation_id of a stock key from $infos. */
  public static function product_ids( array $infos, int $pid ): array {
    return [ 'product_id' => (int) ( $infos[ $pid ]['product_id'] ?? 0 ), 'variation_id' => (int) ( $infos[ $pid ]['variation_id'] ?? 0 ) ];
  }
}
