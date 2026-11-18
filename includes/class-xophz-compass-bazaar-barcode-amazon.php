<?php
/**
 * Amazon search scraper for barcode lookup: queries Amazon by UPC/EAN, parses
 * product search results (title, primary image, price hint, brand), and
 * gracefully handles rate-limits and bot challenges.
 *
 * Pure PHP parsing methods: no WordPress dependencies in parse_search(), so
 * tests/unit can run in isolation.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Amazon {

  /** Realistic desktop browser User-Agent to avoid immediate bot detection. */
  const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

  /** Base URL for Amazon product searches. */
  const SEARCH_URL = 'https://www.amazon.com/s?k=';

  /** Maximum wait time in seconds for the Amazon search request. */
  const TIMEOUT = 4;

  /**
   * Search Amazon for a barcode using WordPress HTTP API.
   *
   * @param string $code Raw or normalized barcode.
   * @return array{status:string,source:string,product:?array,price_hint:?array}
   */
  public static function search( string $code ): array {
    $clean = (string) preg_replace( '/\D/', '', $code );
    if ( '' === $clean ) {
      return [ 'status' => 'error', 'source' => 'amazon', 'product' => null, 'price_hint' => null ];
    }

    $url = self::SEARCH_URL . rawurlencode( $clean );
    $headers = [
      'User-Agent'      => self::USER_AGENT,
      'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
      'Accept-Language' => 'en-US,en;q=0.9',
      'sec-ch-ua'       => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
      'sec-ch-ua-mobile' => '?0',
      'sec-ch-ua-platform' => '"Windows"',
    ];

    if ( function_exists( 'apply_filters' ) ) {
      $headers = (array) apply_filters( 'bazaar_barcode_amazon_headers', $headers );
    }

    if ( ! function_exists( 'wp_remote_get' ) ) {
      return [ 'status' => 'error', 'source' => 'amazon', 'product' => null, 'price_hint' => null ];
    }

    $response = wp_remote_get( $url, [
      'timeout'    => self::TIMEOUT,
      'user-agent' => self::USER_AGENT,
      'headers'    => $headers,
    ] );

    if ( is_wp_error( $response ) ) {
      return [ 'status' => 'error', 'source' => 'amazon', 'product' => null, 'price_hint' => null ];
    }

    return self::parse_search(
      (int) wp_remote_retrieve_response_code( $response ),
      (string) wp_remote_retrieve_body( $response )
    );
  }

  /**
   * Parse Amazon search HTML into a standardized outcome.
   *
   * @param int    $status HTTP response status code.
   * @param string $html   Raw HTML from the search page.
   * @return array{status:string,source:string,product:?array,price_hint:?array}
   */
  public static function parse_search( int $status, string $html ): array {
    $out = [ 'status' => 'error', 'source' => 'amazon', 'product' => null, 'price_hint' => null ];

    if ( 404 === $status ) {
      $out['status'] = 'not_found';
      return $out;
    }

    // Check for Amazon robot detection / CAPTCHA pages.
    if (
      503 === $status ||
      false !== stripos( $html, 'api-services-support@amazon.com' ) ||
      false !== stripos( $html, 'To discuss automated access to Amazon data' ) ||
      false !== stripos( $html, 'Enter the characters you see below' )
    ) {
      $out['status'] = 'bot_blocked';
      return $out;
    }

    if ( 200 !== $status || '' === trim( $html ) ) {
      return $out;
    }

    // Isolate search result item blocks.
    if ( ! preg_match_all( '/data-component-type="s-search-result"[\s\S]*?(?=data-component-type="s-search-result"|$)/i', $html, $matches ) ) {
      // Check for zero-results indicator.
      if ( false !== stripos( $html, 'No results for' ) || false !== stripos( $html, 'Check each product page for other buying options' ) ) {
        $out['status'] = 'not_found';
        return $out;
      }
      $out['status'] = 'not_found';
      return $out;
    }

    $first_block = $matches[0][0] ?? '';
    if ( '' === $first_block ) {
      $out['status'] = 'not_found';
      return $out;
    }

    // 1. Title Extraction: Check standard h2 headline spans.
    $title = '';
    if ( preg_match( '/<h2[^>]*>[\s\S]*?<span[^>]*>(.*?)<\/span>/i', $first_block, $t_match ) ) {
      $title = self::clean_text( $t_match[1] );
    }
    if ( '' === $title && preg_match( '/data-cy="title-recipe-headline"[^>]*>[\s\S]*?<span[^>]*>(.*?)<\/span>/i', $first_block, $t_match ) ) {
      $title = self::clean_text( $t_match[1] );
    }
    if ( '' === $title && preg_match( '/class="[^"]*a-text-normal[^"]*"[^>]*>(.*?)<\/span>/i', $first_block, $t_match ) ) {
      $title = self::clean_text( $t_match[1] );
    }

    if ( '' === $title ) {
      $out['status'] = 'not_found';
      return $out;
    }

    // 2. Image Extraction: Product thumbnail.
    $image = '';
    if ( preg_match( '/<img[^>]+class="[^"]*s-image[^"]*"[^>]+src="([^"]+)"/i', $first_block, $img_match ) ) {
      $image = trim( $img_match[1] );
    }

    // 3. Price Extraction: Look for a-offscreen or a-price elements.
    $price_hint = null;
    if ( preg_match( '/<span[^>]+class="a-offscreen"[^>]*>[$£€]?([0-9]+(?:\.[0-9]{2})?)<\/span>/i', $first_block, $p_match ) ) {
      $price_val = (float) $p_match[1];
      if ( $price_val > 0 ) {
        $price_hint = [
          'low'    => number_format( $price_val, 2, '.', '' ),
          'high'   => null,
          'source' => 'amazon',
        ];
      }
    }

    // 4. Brand Extraction (if present in byline).
    $brand = '';
    if ( preg_match( '/<span[^>]+class="[^"]*a-size-base-plus[^"]*"[^>]*>(.*?)<\/span>/i', $first_block, $b_match ) ) {
      $brand_candidate = self::clean_text( $b_match[1] );
      if ( strlen( $brand_candidate ) > 0 && strlen( $brand_candidate ) < 40 && false !== stripos( $title, $brand_candidate ) ) {
        $brand = $brand_candidate;
      }
    }

    $out['status']  = 'found';
    $out['product'] = [
      'title'       => $title,
      'brand'       => $brand,
      'description' => '',
      'category'    => '',
      'image_url'   => $image,
      'image_urls'  => '' !== $image ? [ $image ] : [],
    ];
    $out['price_hint'] = $price_hint;

    return $out;
  }

  /** Clean and unescape raw HTML text. */
  private static function clean_text( string $raw ): string {
    $stripped = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $raw );
    $text     = trim( strip_tags( $stripped ) );
    return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
  }
}
