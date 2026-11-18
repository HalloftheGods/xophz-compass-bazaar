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

  /** The Open*Facts and retail picture hosts, all served over https. */
  const IMAGE_HOSTS = [
    'images.openfoodfacts.org',
    'static.openfoodfacts.org',
    'images.openbeautyfacts.org',
    'static.openbeautyfacts.org',
    'images.openpetfoodfacts.org',
    'static.openpetfoodfacts.org',
    'images.openproductsfacts.org',
    'static.openproductsfacts.org',
    'm.media-amazon.com',
    'images-na.ssl-images-amazon.com',
    'images-amazon.com',
    'api.upcitemdb.com',
  ];

  /** Return the allowed image hosts, extensible via filter. */
  public static function image_hosts(): array {
    $hosts = self::IMAGE_HOSTS;
    if ( function_exists( 'apply_filters' ) ) {
      $hosts = (array) apply_filters( 'bazaar_barcode_image_hosts', $hosts );
    }
    return $hosts;
  }

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
   * @return array status, source, product, price_hint, sources_tried
   */
  public static function merge( array $outcomes ): array {
    $empty   = [ 'status' => 'error', 'source' => '', 'product' => null, 'price_hint' => null, 'sources_tried' => [] ];
    $missing = 0;
    $limited = false;
    $tried   = [];
    foreach ( $outcomes as $outcome ) {
      $status = $outcome['status'] ?? 'error';
      $src    = $outcome['source'] ?? '';
      if ( '' !== $src && ! in_array( $src, $tried, true ) ) {
        $tried[] = $src;
      }
      if ( 'found' === $status ) {
        $found = array_merge( $empty, $outcome );
        $found['sources_tried'] = $tried;
        return $found;
      }
      $missing += ( 'not_found' === $status || 'bot_blocked' === $status ) ? 1 : 0;
      $limited  = $limited || 'rate_limited' === $status;
    }
    $empty['sources_tried'] = $tried;
    if ( [] !== $outcomes && count( $outcomes ) === $missing ) {
      $empty['status'] = 'not_found';
    } elseif ( $limited ) {
      $empty['status'] = 'rate_limited';
    }
    return $empty;
  }

  /** True when the code represents an ISBN (13-digit 978/979 or 10-digit book code). */
  public static function is_isbn( string $code ): bool {
    $digits = (string) preg_replace( '/\D/', '', $code );
    $len = strlen( $digits );
    if ( 13 === $len && ( str_starts_with( $digits, '978' ) || str_starts_with( $digits, '979' ) ) ) {
      return true;
    }
    return 10 === $len;
  }

  /** True when a US UPC begins with 3 (GS1 NDC drug code prefix). */
  public static function is_ndc_candidate( string $code ): bool {
    $digits = (string) preg_replace( '/\D/', '', $code );
    $core = ltrim( $digits, '0' );
    return ( 10 === strlen( $digits ) || 11 === strlen( $digits ) || 12 === strlen( $digits ) ) && str_starts_with( $core, '3' );
  }

  /** Open Library Books API URL. */
  public static function open_library_url( string $isbn ): string {
    $clean = (string) preg_replace( '/\D/', '', $isbn );
    return 'https://openlibrary.org/api/books?bibkeys=ISBN:' . rawurlencode( $clean ) . '&format=json&jscmd=data';
  }

  /** Read one Open Library Books answer. */
  public static function parse_open_library( int $status, string $body, string $isbn ): array {
    $out = [ 'status' => 'error', 'source' => 'openlibrary', 'product' => null, 'price_hint' => null ];
    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $clean = (string) preg_replace( '/\D/', '', $isbn );
    $book = $data['ISBN:' . $clean] ?? ( reset( $data ) ?: null );
    if ( ! is_array( $book ) ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $title = self::field( $book['title'] ?? '' );
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $authors = [];
    foreach ( is_array( $book['authors'] ?? null ) ? $book['authors'] : [] as $a ) {
      $name = self::field( $a['name'] ?? '' );
      if ( '' !== $name ) {
        $authors[] = $name;
      }
    }
    $brand = implode( ', ', $authors );
    $image = self::field( $book['cover']['large'] ?? ( $book['cover']['medium'] ?? ( $book['cover']['small'] ?? '' ) ) );
    $images = '' !== $image ? [ $image ] : [];
    $category = self::field( $book['subjects'][0]['name'] ?? 'Books' );
    $desc = self::field( $book['notes'] ?? ( $book['subtitle'] ?? '' ) );

    $out['status'] = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $brand,
      'description' => $desc,
      'category'    => $category,
      'image_url'   => $image,
      'image_urls'  => $images,
    ];
    return $out;
  }

  /** Google Books API URL. */
  public static function google_books_url( string $isbn ): string {
    $clean = (string) preg_replace( '/\D/', '', $isbn );
    return 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . rawurlencode( $clean );
  }

  /** Read one Google Books answer. */
  public static function parse_google_books( int $status, string $body ): array {
    $out = [ 'status' => 'error', 'source' => 'googlebooks', 'product' => null, 'price_hint' => null ];
    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
    $item = isset( $items[0]['volumeInfo'] ) && is_array( $items[0]['volumeInfo'] ) ? $items[0]['volumeInfo'] : null;
    if ( ! $item ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $title = self::field( $item['title'] ?? '' );
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $authors = isset( $item['authors'] ) && is_array( $item['authors'] ) ? implode( ', ', array_map( [ __CLASS__, 'field' ], $item['authors'] ) ) : '';
    $image = self::field( $item['imageLinks']['thumbnail'] ?? ( $item['imageLinks']['smallThumbnail'] ?? '' ) );
    if ( str_starts_with( $image, 'http://' ) ) {
      $image = 'https://' . substr( $image, 7 );
    }
    $images = '' !== $image ? [ $image ] : [];
    $category = self::field( $item['categories'][0] ?? 'Books' );
    $desc = self::field( $item['description'] ?? '' );

    $out['status'] = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $authors,
      'description' => $desc,
      'category'    => $category,
      'image_url'   => $image,
      'image_urls'  => $images,
    ];
    return $out;
  }

  /** openFDA NDC Drug API URL. */
  public static function open_fda_url( string $ndc ): string {
    $clean = (string) preg_replace( '/\D/', '', $ndc );
    return 'https://api.fda.gov/drug/ndc.json?search=packaging.package_ndc:"' . rawurlencode( $clean ) . '"&limit=1';
  }

  /** Read one openFDA NDC answer. */
  public static function parse_open_fda( int $status, string $body ): array {
    $out = [ 'status' => 'error', 'source' => 'openfda', 'product' => null, 'price_hint' => null ];
    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : [];
    $r = isset( $results[0] ) && is_array( $results[0] ) ? $results[0] : null;
    if ( ! $r ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $brand = self::field( $r['brand_name'] ?? '' );
    $generic = self::field( $r['generic_name'] ?? '' );
    $title = '' !== $brand ? $brand : $generic;
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( '' !== $brand && '' !== $generic && false === stripos( $brand, $generic ) ) {
      $title = $brand . ' (' . $generic . ')';
    }
    $labeler = self::field( $r['labeler_name'] ?? '' );
    $dosage = self::field( $r['dosage_form'] ?? '' );
    $route = isset( $r['route'] ) && is_array( $r['route'] ) ? self::field( $r['route'][0] ?? '' ) : '';
    $desc = trim( $dosage . ( '' !== $route ? ' - ' . $route : '' ) );

    $out['status'] = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $labeler,
      'description' => $desc,
      'category'    => 'Health & Medicine',
      'image_url'   => '',
      'image_urls'  => [],
    ];
    return $out;
  }

  /** Brocade.io Open Retail API URL. */
  public static function brocade_url( string $code ): string {
    $clean = (string) preg_replace( '/\D/', '', $code );
    return 'https://www.brocade.io/api/items/' . rawurlencode( $clean );
  }

  /** Read one Brocade.io answer. */
  public static function parse_brocade( int $status, string $body ): array {
    $out = [ 'status' => 'error', 'source' => 'brocade', 'product' => null, 'price_hint' => null ];
    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $title = self::field( $data['name'] ?? '' );
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $brand = self::field( $data['brand_name'] ?? ( $data['brand'] ?? '' ) );
    $category = self::field( $data['category'] ?? '' );
    $desc = self::field( $data['description'] ?? '' );
    $images = self::images( $data['images'] ?? [] );

    $out['status'] = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $brand,
      'description' => $desc,
      'category'    => $category,
      'image_url'   => $images[0] ?? '',
      'image_urls'  => $images,
    ];
    return $out;
  }

  /** Barcode Monster API URL. */
  public static function barcode_monster_url( string $code ): string {
    $clean = (string) preg_replace( '/\D/', '', $code );
    return 'https://barcode.monster/api/' . rawurlencode( $clean );
  }

  /** Read one Barcode Monster answer. */
  public static function parse_barcode_monster( int $status, string $body ): array {
    $out = [ 'status' => 'error', 'source' => 'barcodemonster', 'product' => null, 'price_hint' => null ];
    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }
    if ( 429 === $status ) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    $data = json_decode( $body, true );
    if ( 200 !== $status || ! is_array( $data ) ) {
      return $out;
    }
    $statusNum = $data['status'] ?? null;
    if ( 'not_found' === $statusNum || ( is_numeric( $statusNum ) && 0 === (int) $statusNum ) ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $title = self::field( $data['description'] ?? ( $data['title'] ?? '' ) );
    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }
    $brand = self::field( $data['company'] ?? ( $data['brand'] ?? '' ) );
    $category = self::field( $data['class'] ?? '' );
    $image = self::field( $data['image_url'] ?? '' );
    $images = '' !== $image ? [ $image ] : [];

    $out['status'] = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $brand,
      'description' => '',
      'category'    => $category,
      'image_url'   => $image,
      'image_urls'  => $images,
    ];
    return $out;
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

