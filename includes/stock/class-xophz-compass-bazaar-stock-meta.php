<?php
/**
 * Who and what caused a stock batch: attribution copied onto the batch row.
 *
 * Immutable. occurred_at is a UTC 'Y-m-d H:i:s' string; '' means "when the
 * service records it" (the service clock fills it in). Meta is not part of the
 * request hash, so a replay with other attribution still replays.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Meta {

  const DEFAULTS = [
    'actor_user_id'    => 0,
    'cashier_user_id'  => 0,
    'staff_session_id' => null,
    'approval_id'      => null,
    'register_id'      => null,
    'device_id'        => '',
    'occurred_at'      => '',
  ];

  /** @var array */
  private $fields;

  /**
   * @param array $fields Any of the DEFAULTS keys.
   * @throws InvalidArgumentException On an unknown key, a negative id, a device id over 64 characters or a malformed occurred_at.
   */
  public function __construct( array $fields = [] ) {
    $unknown = array_diff( array_keys( $fields ), array_keys( self::DEFAULTS ) );
    if ( $unknown ) {
      throw new InvalidArgumentException( 'Unknown stock meta field: ' . implode( ', ', $unknown ) );
    }
    $fields = array_merge( self::DEFAULTS, $fields );
    foreach ( [ 'actor_user_id', 'cashier_user_id' ] as $key ) {
      $fields[ $key ] = (int) $fields[ $key ];
      if ( $fields[ $key ] < 0 ) {
        throw new InvalidArgumentException( "Stock meta $key must be 0 or more." );
      }
    }
    foreach ( [ 'staff_session_id', 'approval_id', 'register_id' ] as $key ) {
      if ( null !== $fields[ $key ] ) {
        $fields[ $key ] = (int) $fields[ $key ];
        if ( $fields[ $key ] <= 0 ) {
          throw new InvalidArgumentException( "Stock meta $key must be a positive id or null." );
        }
      }
    }
    $fields['device_id'] = (string) $fields['device_id'];
    if ( strlen( $fields['device_id'] ) > 64 ) {
      throw new InvalidArgumentException( 'Stock meta device_id must be 64 characters or fewer.' );
    }
    $fields['occurred_at'] = (string) $fields['occurred_at'];
    if ( '' !== $fields['occurred_at'] && ! self::is_utc_datetime( $fields['occurred_at'] ) ) {
      throw new InvalidArgumentException( "Stock meta occurred_at must be UTC 'Y-m-d H:i:s' or ''." );
    }
    $this->fields = $fields;
  }

  public function actor_user_id(): int {
    return $this->fields['actor_user_id'];
  }

  public function cashier_user_id(): int {
    return $this->fields['cashier_user_id'];
  }

  public function staff_session_id(): ?int {
    return $this->fields['staff_session_id'];
  }

  public function approval_id(): ?int {
    return $this->fields['approval_id'];
  }

  public function register_id(): ?int {
    return $this->fields['register_id'];
  }

  public function device_id(): string {
    return $this->fields['device_id'];
  }

  public function occurred_at(): string {
    return $this->fields['occurred_at'];
  }

  public function to_array(): array {
    return $this->fields;
  }

  private static function is_utc_datetime( string $value ): bool {
    $parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
    return $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $value;
  }
}
