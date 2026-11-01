<?php
/**
 * REST helpers: error mapping and bounded JSON bodies.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Rest {

  /**
   * Run $fn. A returned WP_REST_Response or WP_Error passes through; any
   * other value is wrapped in a 200 response. Core_Http_Error becomes a
   * WP_Error with its status; any other Throwable is logged and becomes a
   * 500 'internal' error whose message does not repeat the exception text.
   *
   * @return WP_REST_Response|WP_Error
   */
  public static function handle( callable $fn ) {
    try {
      $result = $fn();
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      return $e->to_wp_error();
    } catch ( Throwable $e ) {
      error_log( 'Bazaar REST error: ' . get_class( $e ) . ': ' . $e->getMessage() );
      return ( new Xophz_Compass_Bazaar_Core_Http_Error( 'internal', 'Something went wrong on the server.', 500 ) )->to_wp_error();
    }
    if ( $result instanceof WP_REST_Response || $result instanceof WP_Error ) {
      return $result;
    }
    return new WP_REST_Response( $result, 200 );
  }

  /**
   * The request's JSON body as an array. An empty body is []. A body over
   * $max bytes, malformed JSON, or JSON that is not an object or list gives a
   * 400 'invalid_request'.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function json_body( WP_REST_Request $request, int $max = 262144 ): array {
    $body = (string) $request->get_body();
    if ( strlen( $body ) > $max ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'Request body is too large.', 400 );
    }
    if ( '' === trim( $body ) ) {
      return [];
    }
    $decoded = json_decode( $body, true );
    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'Request body must be a JSON object.', 400 );
    }
    return $decoded;
  }
}
