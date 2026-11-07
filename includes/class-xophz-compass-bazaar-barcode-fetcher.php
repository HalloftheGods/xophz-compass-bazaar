<?php
/**
 * HTTP for the barcode lookup: one GET, or several at once.
 *
 * Every answer is {status, body}; status 0 means no answer arrived.
 *
 * get_many() sends its URLs in parallel through the Requests library that
 * WordPress bundles, which skips WP_Http, so it does per URL what WP_Http
 * would: the http_request_args filter, then pre_http_request (a non-false
 * result answers that URL), then WP_Http::block_request.
 *
 * It goes through wp_remote_get one URL at a time when a proxy applies, when
 * fewer than 2 URLs remain, when the library is missing, when the filter
 * bazaar_barcode_lookup_parallel returns false, or when the parallel call
 * throws.
 *
 * Not guaranteed: the http_response filter and http_api_debug action do not
 * run for a parallel request.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Fetcher {

  const NO_ANSWER = [ 'status' => 0, 'body' => '' ];

  /** One GET through wp_remote_get. @return array{status:int,body:string} */
  public static function get( string $url ): array {
    return self::answer( wp_remote_get( $url, [
      'timeout'    => Xophz_Compass_Bazaar_Barcode_Sources::TIMEOUT,
      'user-agent' => Xophz_Compass_Bazaar_Barcode_Sources::USER_AGENT,
      'headers'    => self::headers(),
    ] ) );
  }

  /**
   * GET every URL.
   *
   * @param array<string,string> $urls key => URL
   * @return array<string,array{status:int,body:string}> The same keys and order.
   */
  public static function get_many( array $urls ): array {
    $answers = array_fill_keys( array_keys( $urls ), self::NO_ANSWER );
    $pending = [];
    $http    = _wp_http_get_object();
    foreach ( $urls as $key => $url ) {
      $args = apply_filters( 'http_request_args', self::request_args(), $url );
      $pre  = apply_filters( 'pre_http_request', false, $args, $url );
      if ( false !== $pre ) {
        $answers[ $key ] = self::answer( $pre );
      } elseif ( ! $http->block_request( $url ) ) {
        $pending[ $key ] = [ 'url' => $url, 'args' => $args ];
      }
    }
    if ( self::can_run_parallel( $pending ) ) {
      try {
        return array_replace( $answers, self::parallel( $pending ) );
      } catch ( \Throwable $e ) {
        error_log( 'bazaar barcode lookup: parallel request failed: ' . $e->getMessage() );
      }
    }
    foreach ( $pending as $key => $request ) {
      $answers[ $key ] = self::get( $request['url'] );
    }
    return $answers;
  }

  private static function headers(): array {
    return [ 'User-Agent' => Xophz_Compass_Bazaar_Barcode_Sources::USER_AGENT, 'Accept' => 'application/json' ];
  }

  /** The args a filter on http_request_args expects to see. */
  private static function request_args(): array {
    return [
      'method'          => 'GET',
      'timeout'         => Xophz_Compass_Bazaar_Barcode_Sources::TIMEOUT,
      'redirection'     => 5,
      'httpversion'     => '1.1',
      'user-agent'      => Xophz_Compass_Bazaar_Barcode_Sources::USER_AGENT,
      'headers'         => self::headers(),
      'blocking'        => true,
      'sslverify'       => true,
      'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
    ];
  }

  /** @param array<string,array{url:string,args:array}> $pending */
  private static function can_run_parallel( array $pending ): bool {
    if ( count( $pending ) < 2 || '' === self::requests_class() ) {
      return false;
    }
    if ( ! apply_filters( 'bazaar_barcode_lookup_parallel', true ) ) {
      return false;
    }
    $proxy = new WP_HTTP_Proxy();
    foreach ( $pending as $request ) {
      if ( $proxy->is_enabled() && $proxy->send_through_proxy( $request['url'] ) ) {
        return false;
      }
    }
    return true;
  }

  /** The Requests class this WordPress has, '' when there is none. */
  private static function requests_class(): string {
    foreach ( [ '\WpOrg\Requests\Requests', '\Requests' ] as $class ) {
      if ( class_exists( $class ) && method_exists( $class, 'request_multiple' ) ) {
        return $class;
      }
    }
    return '';
  }

  /**
   * @param array<string,array{url:string,args:array}> $pending
   * @return array<string,array{status:int,body:string}>
   */
  private static function parallel( array $pending ): array {
    $requests = [];
    foreach ( $pending as $key => $request ) {
      $requests[ $key ] = [
        'url'     => $request['url'],
        'type'    => 'GET',
        'headers' => (array) ( $request['args']['headers'] ?? [] ),
        'options' => self::requests_options( $request['url'], $request['args'] ),
      ];
    }
    $class     = self::requests_class();
    $responses = $class::request_multiple( $requests );
    $answers   = [];
    foreach ( array_keys( $pending ) as $key ) {
      // A request that failed comes back as its exception, not a response.
      $response        = $responses[ $key ] ?? null;
      $answers[ $key ] = is_object( $response ) && isset( $response->status_code )
        ? [ 'status' => (int) $response->status_code, 'body' => (string) $response->body ]
        : self::NO_ANSWER;
    }
    return $answers;
  }

  /** Requests options for one URL, with WordPress's CA bundle as WP_Http would pass it. */
  private static function requests_options( string $url, array $args ): array {
    $verify = apply_filters( 'https_ssl_verify', $args['sslverify'] ?? true, $url );
    $bundle = (string) ( $args['sslcertificates'] ?? ABSPATH . WPINC . '/certificates/ca-bundle.crt' );
    return [
      'timeout'         => (float) $args['timeout'],
      'connect_timeout' => (float) $args['timeout'],
      'useragent'       => (string) $args['user-agent'],
      'redirects'       => (int) $args['redirection'],
      'verify'          => $verify ? $bundle : false,
      'verifyname'      => (bool) $verify,
    ];
  }

  /** @param array|WP_Error|mixed $response @return array{status:int,body:string} */
  private static function answer( $response ): array {
    if ( ! is_array( $response ) ) {
      return self::NO_ANSWER;
    }
    return [
      'status' => (int) wp_remote_retrieve_response_code( $response ),
      'body'   => (string) wp_remote_retrieve_body( $response ),
    ];
  }
}
