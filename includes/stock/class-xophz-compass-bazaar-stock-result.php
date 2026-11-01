<?php
/**
 * What a Stock_Service call recorded.
 *
 * - batch_id: the batch written (or replayed); 0 when nothing was written.
 * - replayed: the key was already recorded with the same request hash, so the
 *   stored batch is returned and nothing new was written.
 * - noop: the request needed no movement, so no batch was written. Drift found
 *   on the way may still have been recorded; see reconciled().
 * - lines: line_no, stock_product_id, product_id, variation_id, location_id,
 *   qty_delta, balance_after, oversold, order_id, order_item_id.
 * - reconciled: lines of reconcile batches written first, in the same
 *   transaction, each with batch_id and reason added. A replay reports none.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Result {

  const LINE_KEYS = [ 'line_no', 'stock_product_id', 'product_id', 'variation_id', 'location_id', 'qty_delta', 'balance_after', 'oversold', 'order_id', 'order_item_id' ];

  /** @var int */
  private $batch_id;

  /** @var bool */
  private $replayed;

  /** @var bool */
  private $noop;

  /** @var array[] */
  private $lines;

  /** @var array[] */
  private $reconciled;

  public function __construct( int $batch_id, bool $replayed, bool $noop, array $lines, array $reconciled = [] ) {
    $this->batch_id   = $batch_id;
    $this->replayed   = $replayed;
    $this->noop       = $noop;
    $this->lines      = array_values( $lines );
    $this->reconciled = array_values( $reconciled );
  }

  /** A result with noop true: no batch was written (reconciled lines may still be present). */
  public static function none( array $reconciled = [] ): self {
    return new self( 0, false, true, [], $reconciled );
  }

  /** The stored batch, from Stock_Store::find_batch(), as a replay. */
  public static function replay( array $found ): self {
    $lines = array_map( [ self::class, 'line_from_row' ], $found['lines'] ?? [] );
    usort(
      $lines,
      function ( $a, $b ) {
        return $a['line_no'] <=> $b['line_no'];
      }
    );
    return new self( (int) ( $found['batch']['id'] ?? 0 ), true, false, $lines );
  }

  /** A result line from a movement row (stored or about to be stored). */
  public static function line_from_row( array $row ): array {
    $line = [];
    foreach ( self::LINE_KEYS as $key ) {
      $value = $row[ $key ] ?? 0;
      if ( 'qty_delta' === $key || 'balance_after' === $key ) {
        $line[ $key ] = Xophz_Compass_Bazaar_Stock_Line::qty( (float) $value );
      } elseif ( 'oversold' === $key ) {
        $line[ $key ] = (bool) $value;
      } else {
        $line[ $key ] = (int) $value;
      }
    }
    return $line;
  }

  public function batch_id(): int {
    return $this->batch_id;
  }

  public function replayed(): bool {
    return $this->replayed;
  }

  public function noop(): bool {
    return $this->noop;
  }

  /** @return array[] */
  public function lines(): array {
    return $this->lines;
  }

  /** @return array[] */
  public function reconciled(): array {
    return $this->reconciled;
  }

  public function oversold(): bool {
    foreach ( $this->lines as $line ) {
      if ( $line['oversold'] ) {
        return true;
      }
    }
    return false;
  }

  public function to_array(): array {
    return [
      'batch_id'   => $this->batch_id,
      'replayed'   => $this->replayed,
      'noop'       => $this->noop,
      'lines'      => $this->lines,
      'reconciled' => $this->reconciled,
    ];
  }
}
