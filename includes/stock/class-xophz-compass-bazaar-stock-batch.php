<?php
/**
 * One idempotent stock request: a key, a reason and its lines.
 *
 * Immutable. request_hash() is Core_Canonical::hash over the reason, the source
 * and the lines (spec 8.10). A request whose lines depend on the balance at the
 * time it runs (set_count, restock_refund) passes a hash_basis instead of
 * relying on its lines, so a retry of the same request hashes equally.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Batch {

  /** Spec 8.1 reasons, plus product_form for the product-form count of spec 8.9. */
  const REASONS = [
    'opening', 'sale', 'sale_reversal', 'resale', 'refund_restock', 'receive', 'adjust', 'count',
    'transfer_out', 'transfer_in', 'import', 'reconcile', 'online_sale', 'online_restore',
    'online_refund_restock', 'external', 'product_form',
  ];

  const OPTIONS = [
    'source_type'       => '',
    'source_id'         => 0,
    'note'              => '',
    'reverses_batch_id' => null,
    'hash_basis'        => null,
  ];

  /** @var string */
  private $idem_key;

  /** @var string */
  private $reason;

  /** @var Xophz_Compass_Bazaar_Stock_Line[] */
  private $lines;

  /** @var Xophz_Compass_Bazaar_Stock_Meta */
  private $meta;

  /** @var array */
  private $options;

  /**
   * @param Xophz_Compass_Bazaar_Stock_Line[] $lines
   * @param array $options Any of the OPTIONS keys.
   * @throws InvalidArgumentException On a bad key, reason, line, source or note.
   */
  public function __construct( string $idem_key, string $reason, array $lines, ?Xophz_Compass_Bazaar_Stock_Meta $meta = null, array $options = [] ) {
    if ( '' === $idem_key || strlen( $idem_key ) > Xophz_Compass_Bazaar_Stock_Keys::MAX_LENGTH ) {
      throw new InvalidArgumentException( 'A stock batch key must be 1 to 191 characters.' );
    }
    if ( ! in_array( $reason, self::REASONS, true ) ) {
      throw new InvalidArgumentException( "Unknown stock reason: $reason" );
    }
    foreach ( $lines as $line ) {
      if ( ! $line instanceof Xophz_Compass_Bazaar_Stock_Line ) {
        throw new InvalidArgumentException( 'Stock batch lines must be Stock_Line objects.' );
      }
    }
    if ( count( $lines ) > 65535 ) {
      throw new InvalidArgumentException( 'A stock batch holds at most 65535 lines.' );
    }
    $unknown = array_diff( array_keys( $options ), array_keys( self::OPTIONS ) );
    if ( $unknown ) {
      throw new InvalidArgumentException( 'Unknown stock batch option: ' . implode( ', ', $unknown ) );
    }
    $options                = array_merge( self::OPTIONS, $options );
    $options['source_type'] = (string) $options['source_type'];
    $options['source_id']   = (int) $options['source_id'];
    $options['note']        = (string) $options['note'];
    if ( strlen( $options['source_type'] ) > 32 || $options['source_id'] < 0 ) {
      throw new InvalidArgumentException( 'Stock batch source_type must be 32 characters or fewer and source_id 0 or more.' );
    }
    if ( strlen( $options['note'] ) > 255 ) {
      throw new InvalidArgumentException( 'Stock batch note must be 255 characters or fewer.' );
    }
    if ( null !== $options['reverses_batch_id'] ) {
      $options['reverses_batch_id'] = (int) $options['reverses_batch_id'];
    }
    if ( null !== $options['hash_basis'] && ! is_array( $options['hash_basis'] ) ) {
      throw new InvalidArgumentException( 'Stock batch hash_basis must be an array or null.' );
    }
    $this->idem_key = $idem_key;
    $this->reason   = $reason;
    $this->lines    = array_values( $lines );
    $this->meta     = $meta ?: new Xophz_Compass_Bazaar_Stock_Meta();
    $this->options  = $options;
  }

  public function idem_key(): string {
    return $this->idem_key;
  }

  public function reason(): string {
    return $this->reason;
  }

  /** @return Xophz_Compass_Bazaar_Stock_Line[] */
  public function lines(): array {
    return $this->lines;
  }

  public function meta(): Xophz_Compass_Bazaar_Stock_Meta {
    return $this->meta;
  }

  public function source_type(): string {
    return $this->options['source_type'];
  }

  public function source_id(): int {
    return $this->options['source_id'];
  }

  public function note(): string {
    return $this->options['note'];
  }

  public function reverses_batch_id(): ?int {
    return $this->options['reverses_batch_id'];
  }

  /** sha256 over reason, source and lines (or the hash basis when one was given). Meta and note are not hashed. */
  public function request_hash(): string {
    $data = [
      'reason' => $this->reason,
      'source' => [ 'type' => $this->options['source_type'], 'id' => $this->options['source_id'] ],
    ];
    if ( null !== $this->options['hash_basis'] ) {
      $data['request'] = $this->options['hash_basis'];
    } else {
      $data['lines'] = array_map(
        function ( Xophz_Compass_Bazaar_Stock_Line $line ) {
          return $line->hash_array();
        },
        $this->lines
      );
    }
    return Xophz_Compass_Bazaar_Core_Canonical::hash( $data );
  }

  /** A bazaar_stock_batches row (without id). $now is created_at, and occurred_at when the meta has none. */
  public function to_row( string $request_hash, string $now ): array {
    $meta = $this->meta;
    return [
      'idem_key'          => $this->idem_key,
      'request_hash'      => $request_hash,
      'reason'            => $this->reason,
      'source_type'       => $this->options['source_type'],
      'source_id'         => $this->options['source_id'],
      'reverses_batch_id' => $this->options['reverses_batch_id'],
      'actor_user_id'     => $meta->actor_user_id(),
      'cashier_user_id'   => $meta->cashier_user_id(),
      'staff_session_id'  => $meta->staff_session_id(),
      'approval_id'       => $meta->approval_id(),
      'register_id'       => $meta->register_id(),
      'device_id'         => $meta->device_id(),
      'note'              => $this->options['note'],
      'occurred_at'       => '' !== $meta->occurred_at() ? $meta->occurred_at() : $now,
      'created_at'        => $now,
    ];
  }
}
