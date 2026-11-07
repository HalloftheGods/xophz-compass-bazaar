<?php
/**
 * The free barcode databases behind global_barcode_lookup: where they live,
 * how to read an answer, and how several answers become one outcome.
 *
 * Pure PHP: no WordPress or WooCommerce calls, so tests/unit can load it alone.
 * Every outcome has the keys status, source, product and price_hint.
 *
 * Guarantees:
 * - parse_open_facts() never throws. A 429 is always 'rate_limited' and a
 *   record without a name is 'not_found' (no help to a person adding stock).
 * - merge() answers not_found only when every source said not_found, so a
 *   source that failed can never get a code cached as unknown.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Sources {

  /** User-Agent the Open*Facts projects ask every API client to send. */
  const USER_AGENT = 'WestwoodCompassBazaar/1.0 (+https://www.westwooddiscount.com)';

  /** Seconds each provider request may take. */
  const TIMEOUT = 4;

  /** Pictures kept from one UPCitemdb record. */
  const MAX_IMAGES = 5;

  /** A recorded high beyond this many times the low is a data error, not a price. */
  const PRICE_SPREAD_MAX = 4;

  /** Asked first, on its own: most of the shelf is food. */
  const OPEN_FOOD_FACTS = 'openfoodfacts';

  /** Asked together when Open Food Facts has no record. */
  const OPEN_FACTS_OTHERS = [ 'openbeautyfacts', 'openpetfoodfacts', 'openproductsfacts' ];

  /** The Open*Facts picture hosts, all served over https. */
  const IMAGE_HOSTS = [
    'images.openfoodfacts.org',
    'static.openfoodfacts.org',
    'images.openbeautyfacts.org',
    'static.openbeautyfacts.org',
    'images.openpetfoodfacts.org',
    'static.openpetfoodfacts.org',
    'images.openproductsfacts.org',
    'static.openproductsfacts.org',
  ];

  /** The v2 product URL of an Open*Facts source. */
  public static function open_facts_url( string $source, string $code ): string {
    return 'https://world.' . $source . '.org/api/v2/product/' . rawurlencode( $code ) . '.json';
  }

  /**
   * Read one Open*Facts answer.
   *
   * @param int    $status HTTP status; 0 when no answer arrived.
   * @param string $body   Response body.
   * @param string $source The source's name, reported in the outcome.
   * @return array status, source, product, price_hint
   */
  public static function parse_open_facts( int $status, string $body, string $source ): array {
    $out  = [ 'status' => 'error', 'source' => $source, 'product' => null, 'price_hint' => null ];
    $data = json_decode( $body, true );
    if ( 404 === $status || ( 200 === $status && is_array( $data ) && isset( $data['status'] ) && 1 !== (int) $data['status'] ) ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    if ( 200 !== $status || ! is_array( $data ) || ! isset( $data['product'] ) || ! is_array( $data['product'] ) ) {
      return $out;
    }

    $p     = $data['product'];
    $title = self::field( $p['product_name'] ?? '' );
    if ( '' === $title ) {
      $title = self::field( $p['product_name_en'] ?? '' );
    }
    $brand = self::field( $p['brands'] ?? '' );
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( '' !== $brand && false === stripos( $title, $brand ) ) {
      $title = $brand . ' ' . $title;
    }
    $image          = self::field( $p['image_url'] ?? ( $p['image_front_url'] ?? '' ) );
    $out['status']  = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $brand,
      'description' => self::field( $p['generic_name'] ?? '' ),
      'category'    => self::field( $p['categories'] ?? ( $p['categories_old'] ?? '' ) ),
      'image_url'   => $image,
      'image_urls'  => '' !== $image ? [ $image ] : [],
    ];
    return $out;
  }

  /**
   * Read one UPCitemdb trial answer. A 429 is rate_limited; a 400 is
   * INVALID_UPC, a code UPCitemdb cannot know.
   *
   * @return array status, source, product, price_hint
   */
  public static function parse_upcitemdb( int $status, string $body ): array {
    $out = [ 'status' => 'error', 'source' => 'upcitemdb', 'product' => null, 'price_hint' => null ];
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    if ( 400 === $status || 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
    $item  = isset( $items[0] ) && is_array( $items[0] ) ? $items[0] : null;
    $title = $item ? self::field( $item['title'] ?? '' ) : '';
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $images         = self::images( $item['images'] ?? [] );
    $out['status']  = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => self::field( $item['brand'] ?? '' ),
      'description' => self::field( $item['description'] ?? '' ),
      'category'    => self::field( $item['category'] ?? '' ),
      'image_url'   => $images[0] ?? '',
      'image_urls'  => $images,
    ];
    $out['price_hint'] = self::price_hint( $item['lowest_recorded_price'] ?? null, $item['highest_recorded_price'] ?? null );
    return $out;
  }

  /** The http(s) picture URLs of a list, without repeats, at most MAX_IMAGES. @return string[] */
  public static function images( $urls ): array {
    $found = [];
    foreach ( is_array( $urls ) ? $urls : [] as $url ) {
      $url = self::field( $url );
      if ( preg_match( '#^https?://#i', $url ) && ! in_array( $url, $found, true ) ) {
        $found[] = $url;
      }
    }
    return array_slice( $found, 0, self::MAX_IMAGES );
  }

  /**
   * low and high as strings with 2 decimals, else null. high is null when it
   * is more than PRICE_SPREAD_MAX times low: UPCitemdb's highest price is
   * often an outlier, and a hint must never carry one.
   *
   * @return array{low:string,high:?string,source:string}|null
   */
  public static function price_hint( $low, $high ): ?array {
    if ( ! is_numeric( $low ) || ! is_numeric( $high ) || (float) $low <= 0 || (float) $high < (float) $low ) {
      return null;
    }
    return [
      'low'    => number_format( (float) $low, 2, '.', '' ),
      'high'   => (float) $high > self::PRICE_SPREAD_MAX * (float) $low ? null : number_format( (float) $high, 2, '.', '' ),
      'source' => 'upcitemdb',
    ];
  }

  /**
   * One outcome from several, given in the order they were asked: the first
   * found wins; not_found only when every source said not_found; otherwise
   * rate_limited if any source got a 429; otherwise error.
   *
   * @param array[] $outcomes Each with at least a status.
   * @return array status, source, product, price_hint
   */
  public static function merge( array $outcomes ): array {
    $empty   = [ 'status' => 'error', 'source' => '', 'product' => null, 'price_hint' => null ];
    $missing = 0;
    $limited = false;
    foreach ( $outcomes as $outcome ) {
      $status = $outcome['status'] ?? 'error';
      if ( 'found' === $status ) {
        return array_merge( $empty, $outcome );
      }
      $missing += 'not_found' === $status ? 1 : 0;
      $limited  = $limited || 'rate_limited' === $status;
    }
    if ( [] !== $outcomes && count( $outcomes ) === $missing ) {
      $empty['status'] = 'not_found';
    } elseif ( $limited ) {
      $empty['status'] = 'rate_limited';
    }
    return $empty;
  }

  /** A provider field as a trimmed plain string ('' for arrays and null). */
  public static function field( $value ): string {
    if ( ! is_scalar( $value ) ) {
      return '';
    }
    $text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $value );
    return trim( strip_tags( $text ) );
  }
}
