<?php
/**
 * Canonical JSON and hashing, so equal requests hash equally.
 *
 * Maps are sorted by key (as strings), recursively. Lists keep their order.
 * Objects are treated as maps.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Canonical {

  public static function json( array $data ): string {
    return json_encode( self::normalize( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
  }

  /**
   * sha256 hex of json(). $exclude_keys are removed at every depth of a map
   * before hashing; list positions are never removed.
   */
  public static function hash( array $data, array $exclude_keys = [] ): string {
    if ( $exclude_keys ) {
      $data = self::without( $data, array_map( 'strval', $exclude_keys ) );
    }
    return hash( 'sha256', self::json( $data ) );
  }

  private static function is_list( array $value ): bool {
    $i = 0;
    foreach ( $value as $key => $unused ) {
      if ( $key !== $i++ ) {
        return false;
      }
    }
    return true;
  }

  /** @param mixed $value */
  private static function normalize( $value ) {
    if ( is_object( $value ) ) {
      $value = (array) $value;
      if ( ! $value ) {
        return new stdClass();
      }
    }
    if ( ! is_array( $value ) ) {
      return $value;
    }
    if ( self::is_list( $value ) ) {
      return array_map( [ self::class, 'normalize' ], $value );
    }
    ksort( $value, SORT_STRING );
    return array_map( [ self::class, 'normalize' ], $value );
  }

  /** @param mixed $value @return mixed */
  private static function without( $value, array $keys ) {
    if ( is_object( $value ) ) {
      $value = (array) $value;
    }
    if ( ! is_array( $value ) ) {
      return $value;
    }
    $list = self::is_list( $value );
    $out  = [];
    foreach ( $value as $key => $item ) {
      if ( ! $list && in_array( (string) $key, $keys, true ) ) {
        continue;
      }
      $out[ $key ] = self::without( $item, $keys );
    }
    return $out;
  }
}
