<?php
/**
 * One stock movement request: a signed quantity at one (stock product, location).
 *
 * Immutable. Quantities are rounded to 4 decimals, the precision of the
 * decimal(19,4) columns. allow_negative marks a line that carries an oversell
 * approval: Stock_Planner lets it take the balance below zero.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Line {

  /** Optional fields and their defaults. */
  const DEFAULTS = [
    'product_id'      => 0,
    'variation_id'    => 0,
    'allow_negative'  => false,
    'order_id'        => 0,
    'order_item_id'   => 0,
    'unit_cost_minor' => null,
    'cost_currency'   => null,
    'cost_source'     => null,
  ];

  /** @var int */
  private $stock_product_id;

  /** @var int */
  private $location_id;

  /** @var float */
  private $qty_delta;

  /** @var array */
  private $extra;

  /**
   * @param array $extra Any of the DEFAULTS keys. An unknown key throws, so a typo cannot drop data.
   * @throws InvalidArgumentException On a bad id, cost currency or unknown key.
   */
  public function __construct( int $stock_product_id, int $location_id, float $qty_delta, array $extra = [] ) {
    $unknown = array_diff( array_keys( $extra ), array_keys( self::DEFAULTS ) );
    if ( $unknown ) {
      throw new InvalidArgumentException( 'Unknown stock line field: ' . implode( ', ', $unknown ) );
    }
    if ( $stock_product_id <= 0 || $location_id < 0 ) {
      throw new InvalidArgumentException( 'A stock line needs a positive stock product id and a location id of 0 or more.' );
    }
    $extra = array_merge( self::DEFAULTS, $extra );
    foreach ( [ 'product_id', 'variation_id', 'order_id', 'order_item_id' ] as $key ) {
      $extra[ $key ] = (int) $extra[ $key ];
      if ( $extra[ $key ] < 0 ) {
        throw new InvalidArgumentException( "Stock line $key must be 0 or more." );
      }
    }
    $extra['allow_negative']  = (bool) $extra['allow_negative'];
    $extra['unit_cost_minor'] = null === $extra['unit_cost_minor'] ? null : (int) $extra['unit_cost_minor'];
    if ( null !== $extra['cost_currency'] && ! preg_match( '/^[A-Z]{3}$/', (string) $extra['cost_currency'] ) ) {
      throw new InvalidArgumentException( 'Stock line cost_currency must be a 3-letter upper-case code or null.' );
    }
    if ( null !== $extra['cost_source'] && strlen( (string) $extra['cost_source'] ) > 16 ) {
      throw new InvalidArgumentException( 'Stock line cost_source must be 16 characters or fewer.' );
    }
    $this->stock_product_id = $stock_product_id;
    $this->location_id      = $location_id;
    $this->qty_delta        = self::qty( $qty_delta );
    $this->extra            = $extra;
  }

  /** Round to the ledger's 4 decimals; never returns -0.0. */
  public static function qty( float $value ): float {
    $rounded = round( $value, 4 );
    return 0.0 === $rounded ? 0.0 : $rounded;
  }

  public function stock_product_id(): int {
    return $this->stock_product_id;
  }

  public function location_id(): int {
    return $this->location_id;
  }

  public function qty_delta(): float {
    return $this->qty_delta;
  }

  public function product_id(): int {
    return $this->extra['product_id'];
  }

  public function variation_id(): int {
    return $this->extra['variation_id'];
  }

  public function allow_negative(): bool {
    return $this->extra['allow_negative'];
  }

  public function order_id(): int {
    return $this->extra['order_id'];
  }

  public function order_item_id(): int {
    return $this->extra['order_item_id'];
  }

  public function unit_cost_minor(): ?int {
    return $this->extra['unit_cost_minor'];
  }

  public function cost_currency(): ?string {
    return $this->extra['cost_currency'];
  }

  public function cost_source(): ?string {
    return $this->extra['cost_source'];
  }

  /** A copy at another stock key, with the product and variation ids filled in when they were 0. */
  public function for_stock_key( int $stock_product_id, int $product_id, int $variation_id ): self {
    $extra = $this->extra;
    if ( 0 === $extra['product_id'] ) {
      $extra['product_id']   = $product_id;
      $extra['variation_id'] = $variation_id;
    }
    return new self( $stock_product_id, $this->location_id, $this->qty_delta, $extra );
  }

  /** Every field, for results and request hashing. qty_delta is a float. */
  public function to_array(): array {
    return array_merge(
      [
        'stock_product_id' => $this->stock_product_id,
        'location_id'      => $this->location_id,
        'qty_delta'        => $this->qty_delta,
      ],
      $this->extra
    );
  }

  /** The hashed form: qty_delta as a fixed 4-decimal string, so equal quantities hash equally. */
  public function hash_array(): array {
    $data              = $this->to_array();
    $data['qty_delta'] = number_format( $this->qty_delta, 4, '.', '' );
    return $data;
  }

  /** A bazaar_stock_movements row (without id). */
  public function to_movement_row( int $batch_id, int $line_no, float $balance_after, bool $oversold, string $created_at ): array {
    return [
      'batch_id'         => $batch_id,
      'line_no'          => $line_no,
      'stock_product_id' => $this->stock_product_id,
      'product_id'       => $this->extra['product_id'],
      'variation_id'     => $this->extra['variation_id'],
      'location_id'      => $this->location_id,
      'qty_delta'        => $this->qty_delta,
      'balance_after'    => self::qty( $balance_after ),
      'oversold'         => $oversold ? 1 : 0,
      'order_id'         => $this->extra['order_id'],
      'order_item_id'    => $this->extra['order_item_id'],
      'unit_cost_minor'  => $this->extra['unit_cost_minor'],
      'cost_currency'    => $this->extra['cost_currency'],
      'cost_source'      => $this->extra['cost_source'],
      'created_at'       => $created_at,
    ];
  }
}
