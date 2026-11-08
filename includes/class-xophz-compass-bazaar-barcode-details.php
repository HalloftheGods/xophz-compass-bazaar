<?php
/**
 * What a barcode lookup tells a product page: the long and short description
 * and a category hint, built from a lookup's product the way the Add Product
 * editor takes them (mergeBarcodeLookup.ts): the lookup's description fills an
 * empty description and nothing else.
 *
 * Pure PHP apart from the Barcode_Memory string helpers it calls, which are
 * pure too, so tests/unit/barcode-details-test.php loads it without WordPress.
 *
 * Guarantees:
 * - Plain text out: tags are dropped, money amounts are taken out
 *   (Barcode_Memory::strip_money), whitespace is collapsed, and the text is
 *   cut at a word to DESCRIPTION_MAX characters. The caller still passes it
 *   through wp_kses_post before it reaches a product.
 * - fill() only fills what is empty. It never returns a field that already has text.
 * - Brand is not a field here: the editor does nothing with a brand except
 *   what the providers already did, joining it to the title.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Details {

  /** Longest description kept, in characters (also the Barcode_Memory column). */
  const DESCRIPTION_MAX = 1000;

  /** Longest short description, in characters. */
  const SHORT_MAX = 160;

  /** Category hints read from one lookup. */
  const HINTS_MAX = 8;

  /** The empty answer: every key present. */
  public static function none(): array {
    return [ 'description' => '', 'short_description' => '', 'category_hints' => [] ];
  }

  /**
   * The text a lookup gave, as plain text a product page may show; '' when
   * nothing is left.
   *
   * @param mixed $value
   */
  public static function clean_description( $value ): string {
    if ( ! is_scalar( $value ) ) {
      return '';
    }
    $text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', ' ', (string) $value );
    $text = strip_tags( $text );
    $text = (string) preg_replace( '/\s+/u', ' ', $text );
    $text = Xophz_Compass_Bazaar_Barcode_Memory::strip_money( trim( $text ) );
    return self::cut_at_word( $text, self::DESCRIPTION_MAX );
  }

  /**
   * A short version: the text itself when it fits, else its first sentence
   * when that fits and is not tiny, else a word-boundary cut ending in "...".
   */
  public static function short_description( string $description ): string {
    $text = trim( $description );
    if ( self::length( $text ) <= self::SHORT_MAX ) {
      return $text;
    }
    $head = self::slice( $text, self::SHORT_MAX );
    if ( preg_match( '/^(.{40,}?[.!?])(?:\s|$)/us', $head, $m ) ) {
      return trim( $m[1] );
    }
    return self::cut_at_word( $text, self::SHORT_MAX - 3 ) . '...';
  }

  /**
   * Category names a lookup suggests, most general first, as the provider
   * wrote them: Open*Facts gives a comma list, UPCitemdb a path joined by ">".
   * Plain strings only; the caller decides whether a store category with that
   * name exists.
   *
   * @param mixed $value
   * @return string[]
   */
  public static function category_hints( $value ): array {
    if ( ! is_scalar( $value ) ) {
      return [];
    }
    $found = [];
    foreach ( preg_split( '/[,>]/', strip_tags( (string) $value ) ) ?: [] as $part ) {
      // Open*Facts writes some names with a language prefix: "en:Snacks".
      $name = trim( (string) preg_replace( '/^[a-z]{2}:/', '', trim( $part ) ) );
      if ( '' !== $name && self::length( $name ) <= 100 && ! in_array( $name, $found, true ) ) {
        $found[] = $name;
      }
    }
    return array_slice( $found, 0, self::HINTS_MAX );
  }

  /**
   * The details of a lookup's product (title, brand, description, category,
   * ...): description, short_description and category_hints. A missing or
   * empty product gives none().
   *
   * @param array|null $product
   */
  public static function from_product( $product ): array {
    if ( ! is_array( $product ) ) {
      return self::none();
    }
    $description = self::clean_description( $product['description'] ?? '' );
    return [
      'description'       => $description,
      'short_description' => self::short_description( $description ),
      'category_hints'    => self::category_hints( $product['category'] ?? '' ),
    ];
  }

  /**
   * What to write on a product that has these texts now: a field of $details
   * comes back only when the product's own field is empty and the lookup has
   * text for it. A short description is only offered together with, or on
   * top of, a description that exists after the write.
   *
   * @param array $current {description, short_description} as the product has them.
   * @param array $details from_product() output.
   * @return array{description?:string,short_description?:string}
   */
  public static function fill( array $current, array $details ): array {
    $out   = [];
    $long  = trim( (string) ( $current['description'] ?? '' ) );
    $short = trim( (string) ( $current['short_description'] ?? '' ) );
    $new_l = trim( (string) ( $details['description'] ?? '' ) );
    $new_s = trim( (string) ( $details['short_description'] ?? '' ) );
    if ( '' === $long && '' !== $new_l ) {
      $out['description'] = $new_l;
    }
    if ( '' === $short && '' !== $new_s && ( '' !== $long || '' !== $new_l ) ) {
      $out['short_description'] = $new_s;
    }
    return $out;
  }

  private static function length( string $text ): int {
    return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
  }

  private static function slice( string $text, int $max ): string {
    return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
  }

  /** The text cut to $max characters at the last space, never mid-word. */
  private static function cut_at_word( string $text, int $max ): string {
    if ( self::length( $text ) <= $max ) {
      return trim( $text );
    }
    $head  = self::slice( $text, $max );
    $space = strrpos( $head, ' ' );
    // Keep the whole cut when the first word alone is longer than the limit.
    return trim( false !== $space && $space > 0 ? substr( $head, 0, $space ) : $head );
  }
}
