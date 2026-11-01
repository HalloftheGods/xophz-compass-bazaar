<?php
/**
 * A stock batch would take one or more balances below zero.
 *
 * Maps to 409 insufficient_stock. lines() lists every short line, not only the
 * first one found. Throwing it inside Stock_Service rolls back the whole
 * transaction, so nothing from the refused call is kept.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Stock_Insufficient_Exception extends Xophz_Compass_Bazaar_Core_Http_Error {

  /** @var array[] */
  private $short_lines;

  /**
   * @param array[] $lines Each: line_no, product_id, stock_product_id, variation_id, location_id, requested, available.
   */
  public function __construct( array $lines ) {
    $this->short_lines = array_values( $lines );
    $count             = count( $this->short_lines );
    parent::__construct(
      'insufficient_stock',
      1 === $count ? 'Not enough stock for 1 line.' : sprintf( 'Not enough stock for %d lines.', $count ),
      409,
      [ 'lines' => $this->short_lines ]
    );
  }

  /** @return array[] Every short line, in batch order. */
  public function lines(): array {
    return $this->short_lines;
  }
}
