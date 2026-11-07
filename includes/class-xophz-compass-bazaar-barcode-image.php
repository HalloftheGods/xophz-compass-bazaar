<?php
/**
 * Product pictures a barcode lookup found, which can live on any retailer's
 * host: http URLs a https page would block, or hosts that refuse hotlinks.
 * The phone sees them through this server, and a save attaches them from here.
 *
 * accept_url() is pure, so tests/unit can load this file without WordPress.
 *
 * Guarantees:
 * - A URL is fetched only when it is http(s), has no login in it, and
 *   wp_http_validate_url passes it, and only through wp_safe_remote_get, so
 *   private and loopback addresses are refused, redirects included.
 * - fetch() answers only for at most MAX_BYTES of real jpeg, png, gif or webp
 *   (checked from the bytes with wp_get_image_mime, never from a header).
 * - A save uses only URLs from this user's last lookup of that code, or an
 *   Open*Facts host (see candidates()).
 *
 * Not guaranteed: wp_http_validate_url resolves a name before the request
 * does, so a host that changes address in between is not caught.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Image {

  const MAX_BYTES       = 4194304;
  const TIMEOUT         = 6;
  const REDIRECTS       = 1;
  const MIMES           = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
  const MAX_URL         = 2000;
  const MAX_CANDIDATES  = 5;
  const CACHE_PREFIX    = 'bazaar_qa_pic_';
  const CACHE_TTL       = 86400;
  /** Bigger pictures are fetched each time: a transient is an options row. */
  const CACHE_MAX_BYTES = 1048576;
  const LOOKUP_PREFIX   = 'bazaar_qa_pics_';
  const LOOKUP_TTL      = 86400;

  /**
   * The trimmed URL when it is http(s) with a host and no login, else ''.
   * Also refuses addresses wp_http_validate_url lets through: link-local
   * IPv4 (cloud metadata lives there) and any IPv6 literal.
   */
  public static function accept_url( $url ): string {
    if ( ! is_string( $url ) ) {
      return '';
    }
    $url = trim( $url );
    if ( '' === $url || strlen( $url ) > self::MAX_URL || preg_match( '/[\s<>"\'\\\\]/', $url ) ) {
      return '';
    }
    $parts = parse_url( $url );
    if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
      return '';
    }
    $host = strtolower( (string) ( $parts['host'] ?? '' ) );
    if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) || '' === $host ) {
      return '';
    }
    if ( '[' === $host[0] || 0 === strpos( $host, '169.254.' ) ) {
      return '';
    }
    return $url;
  }

  /** The acceptable URLs of a list, without repeats, at most MAX_CANDIDATES. */
  public static function clean_list( $urls ): array {
    $clean = array_map( [ self::class, 'accept_url' ], is_array( $urls ) ? array_values( $urls ) : [] );
    return array_slice( array_values( array_unique( array_filter( $clean ) ) ), 0, self::MAX_CANDIDATES );
  }

  /**
   * The picture at a URL.
   *
   * @return array{mime:string,data_base64:string}|null Null when it is not a
   *   safe URL, did not arrive, is too big or is not a picture.
   */
  public static function fetch( string $url ): ?array {
    $url = self::accept_url( $url );
    if ( '' === $url || ! wp_http_validate_url( $url ) ) {
      return null;
    }
    $key    = self::CACHE_PREFIX . md5( $url );
    $cached = get_transient( $key );
    if ( is_array( $cached ) && isset( $cached['mime'], $cached['data_base64'] ) ) {
      return $cached;
    }
    $response = wp_safe_remote_get( $url, [
      'timeout'             => self::TIMEOUT,
      'redirection'         => self::REDIRECTS,
      'limit_response_size' => self::MAX_BYTES + 1,
      'user-agent'          => Xophz_Compass_Bazaar_Barcode_Sources::USER_AGENT,
    ] );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
      return null;
    }
    $bytes = (string) wp_remote_retrieve_body( $response );
    if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
      return null;
    }
    $mime = self::mime_of( $bytes );
    if ( '' === $mime ) {
      return null;
    }
    $picture = [ 'mime' => $mime, 'data_base64' => base64_encode( $bytes ) ];
    if ( strlen( $bytes ) <= self::CACHE_MAX_BYTES ) {
      set_transient( $key, $picture, self::CACHE_TTL );
    }
    return $picture;
  }

  /** The allowed image type of these bytes, '' for anything else. */
  private static function mime_of( string $bytes ): string {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp = wp_tempnam( 'bazaar-picture' );
    if ( ! $tmp ) {
      return '';
    }
    file_put_contents( $tmp, $bytes );
    $mime = wp_get_image_mime( $tmp );
    wp_delete_file( $tmp );
    return in_array( $mime, self::MIMES, true ) ? (string) $mime : '';
  }

  /** The file extension for an allowed image type. */
  public static function extension( string $mime ): string {
    return [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ][ $mime ] ?? '';
  }

  /** Keep what a lookup offered, so a save can be held to it. */
  public static function remember_candidates( int $user_id, string $code, array $urls ): void {
    set_transient( self::lookup_key( $user_id, $code ), self::clean_list( $urls ), self::LOOKUP_TTL );
  }

  /** What this user's last lookup of this code offered. @return string[] */
  public static function candidates( int $user_id, string $code ): array {
    $urls = get_transient( self::lookup_key( $user_id, $code ) );
    return is_array( $urls ) ? $urls : [];
  }

  private static function lookup_key( int $user_id, string $code ): string {
    return self::LOOKUP_PREFIX . md5( $user_id . '|' . $code );
  }
}
