<?php
/**
 * The SEO fields of the product editor (SmartCrawl `_wds_*` post meta).
 *
 * Guarantees:
 * - Only the keys in KEYS are read or written, whatever the request holds.
 * - clean() drops a key the request did not send, so a save never blanks a
 *   field it was not given.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Seo {

  /** Meta key => kind: text, url, social (title and description) or flag. */
  const KEYS = [
    '_wds_title'                => 'text',
    '_wds_metadesc'             => 'text',
    '_wds_canonical'            => 'url',
    '_wds_redirect'             => 'url',
    '_wds_opengraph'            => 'social',
    '_wds_meta-robots-noindex'  => 'flag',
    '_wds_meta-robots-nofollow' => 'flag',
  ];

  /**
   * The allowed keys of a request's meta object, sanitized.
   *
   * @param mixed $meta The request's `meta` (object or array).
   * @return array<string,mixed> key => string, array (social) or bool (flag).
   */
  public static function clean( $meta ): array {
    $meta = is_string( $meta ) ? json_decode( $meta, true ) : $meta;
    $meta = is_object( $meta ) ? (array) $meta : $meta;
    if ( ! is_array( $meta ) ) {
      return [];
    }
    $out = [];
    foreach ( self::KEYS as $key => $kind ) {
      if ( ! array_key_exists( $key, $meta ) ) {
        continue;
      }
      $value = $meta[ $key ];
      if ( 'flag' === $kind ) {
        $out[ $key ] = ! empty( $value ) && 'false' !== $value;
      } elseif ( 'social' === $kind ) {
        $value = is_object( $value ) ? (array) $value : $value;
        $value = is_array( $value ) ? $value : [];
        $out[ $key ] = [
          'title'       => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
          'description' => sanitize_text_field( (string) ( $value['description'] ?? '' ) ),
        ];
      } elseif ( 'url' === $kind ) {
        $out[ $key ] = is_scalar( $value ) ? esc_url_raw( trim( (string) $value ) ) : '';
      } else {
        $out[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
      }
    }
    return $out;
  }

  /** Write the cleaned fields. An empty value removes the meta row; Open Graph keeps the keys the SEO plugin added. */
  public static function save( int $product_id, $meta ): void {
    foreach ( self::clean( $meta ) as $key => $value ) {
      if ( 'social' === self::KEYS[ $key ] ) {
        $existing = get_post_meta( $product_id, $key, true );
        $value    = array_merge( is_array( $existing ) ? $existing : [], $value );
        $empty    = '' === $value['title'] && '' === $value['description'];
      } else {
        $empty = false === $value || '' === $value;
      }
      if ( $empty ) {
        delete_post_meta( $product_id, $key );
      } else {
        update_post_meta( $product_id, $key, $value );
      }
    }
  }

  /** The allowed fields of a product as the editor reads them back (flags as bool, Open Graph as title and description). */
  public static function read( int $product_id ): array {
    $out = [];
    foreach ( self::KEYS as $key => $kind ) {
      $value = get_post_meta( $product_id, $key, true );
      if ( 'flag' === $kind ) {
        $out[ $key ] = ! empty( $value );
      } elseif ( 'social' === $kind ) {
        $value = is_array( $value ) ? $value : [];
        $out[ $key ] = [
          'title'       => (string) ( $value['title'] ?? '' ),
          'description' => (string) ( $value['description'] ?? '' ),
        ];
      } else {
        $out[ $key ] = is_scalar( $value ) ? (string) $value : '';
      }
    }
    return $out;
  }
}
