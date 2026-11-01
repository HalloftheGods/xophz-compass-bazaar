<?php
/**
 * A stock request conflicts with what is already recorded.
 *
 * Two codes:
 * - idempotency_key_reused (422): the key was already used for a request with
 *   another request hash. The earlier batch is unchanged.
 * - stock_changed (409): set_count was given an expected balance that no longer
 *   matches. data.current holds the balance read under lock.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Stock_Conflict_Exception extends Xophz_Compass_Bazaar_Core_Http_Error {

  public static function key_reused(): self {
    return new self( 'idempotency_key_reused', 'This idempotency key was already used for a different stock request.', 422 );
  }

  public static function stock_changed( float $current ): self {
    return new self( 'stock_changed', 'The stock changed since it was read. Review the current count and try again.', 409, [ 'current' => $current ] );
  }

  /** The balance read under lock, for stock_changed; null for idempotency_key_reused. */
  public function current(): ?float {
    $data = $this->data();
    return array_key_exists( 'current', $data ) ? (float) $data['current'] : null;
  }
}
