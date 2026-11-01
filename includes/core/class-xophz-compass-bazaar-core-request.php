<?php
/**
 * Decodes the request shapes the legacy admin-ajax handlers receive.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Request {

  /**
   * Handler arguments as an object. When $_POST is non-empty the arguments
   * are the unslashed GET and POST merged (POST wins on a shared key), with a
   * single unslash. Otherwise the JSON body via Xophz_Compass::get_input_json(),
   * or an empty object when that class is not loaded.
   */
  public static function legacy_args(): object {
    if ( ! empty( $_POST ) ) {
      return (object) ( wp_unslash( $_POST ) + wp_unslash( $_GET ) );
    }
    if ( class_exists( 'Xophz_Compass' ) && method_exists( 'Xophz_Compass', 'get_input_json' ) ) {
      $json = Xophz_Compass::get_input_json();
      return is_object( $json ) ? $json : (object) ( is_array( $json ) ? $json : [] );
    }
    return (object) [];
  }

  /**
   * One argument as a list/map array. Accepts an array, an object or a JSON
   * string that decodes to one of those. Anything else gives [].
   *
   * @param object|array $args
   */
  public static function array_param( $args, string $name ): array {
    $value = self::raw( $args, $name );
    if ( is_string( $value ) ) {
      $value = json_decode( $value, true );
    } elseif ( is_object( $value ) || is_array( $value ) ) {
      $value = json_decode( json_encode( $value ), true );
    }
    return is_array( $value ) ? $value : [];
  }

  /**
   * True only for true, 'true', 1 and '1'.
   *
   * @param object|array $args
   */
  public static function bool_param( $args, string $name ): bool {
    $value = self::raw( $args, $name );
    return true === $value || 'true' === $value || 1 === $value || '1' === $value;
  }

  /** @param object|array $args @return mixed */
  private static function raw( $args, string $name ) {
    if ( is_object( $args ) ) {
      return $args->{$name} ?? null;
    }
    return is_array( $args ) ? ( $args[ $name ] ?? null ) : null;
  }
}
