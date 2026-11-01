<?php
/**
 * Coupons through WooCommerce's own WC_Discounts, without saving anything.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 9.2 step 2 and section 3
 * (calculate_totals() and apply_coupon() both save the order;
 * set_items_from_order() keys unsaved items by id 0).
 *
 * - The WC_Discounts object is built on an unsaved WC_Order that carries
 *   the customer id and email (so email and per-user restrictions apply)
 *   and unsaved line items (so minimum and maximum spend see the cart).
 * - set_items() gets unique keys line-<n> and an unsaved
 *   WC_Order_Item_Product as each item's object; a custom line has no
 *   product (false), as WooCommerce has for product-less items.
 * - Each coupon goes through apply_coupon($coupon, true), in request order;
 *   the per-line amounts come from get_discounts(true) (minor units, in the
 *   store's price basis: tax-inclusive when prices include tax).
 * - WC_Order::apply_coupon() and calculate_totals() are never called.
 *   Reading coupons and usage counts queries the database; nothing is
 *   written.
 * - An invalid coupon is returned with valid false and WooCommerce's
 *   message (HTML tags removed). A coupon whose check throws (for example a
 *   restriction WooCommerce cannot evaluate for a custom line) is invalid
 *   with a generic message, and the error is logged.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Coupon_Engine {

  /**
   * @param string[] $codes    Coupon codes in request order.
   * @param array[]  $items    Line key => product (WC_Product|null), name, quantity,
   *                           price (float, minor units, the price WC_Discounts sees),
   *                           subtotal (string, tax-exclusive, major units),
   *                           subtotal_tax (string, major units).
   * @param array    $customer Pricing_Cart_Request::$customer.
   * @return array{coupons:array[],by_line:int[]} coupons: code, valid, message,
   *   amount_minor (int) and by_line (line key => int); by_line sums every valid coupon.
   */
  public function apply( array $codes, array $items, array $customer, bool $prices_include_tax ): array {
    $by_line = array_fill_keys( array_keys( $items ), 0 );
    if ( ! $codes || ! $items ) {
      return [ 'coupons' => [], 'by_line' => $by_line ];
    }
    [ $order, $order_items ] = $this->unsaved_order( $items, $customer, $prices_include_tax );
    $keys      = $this->wc_keys( $items );
    $discounts = new WC_Discounts( $order );
    $discounts->set_items( $this->wc_items( $items, $order_items, $keys ) );

    $results = [];
    foreach ( $codes as $code ) {
      $results[] = $this->apply_one( $discounts, $code );
    }
    $amounts = $discounts->get_discounts( true );
    foreach ( $results as &$result ) {
      $result['by_line'] = array_fill_keys( array_keys( $items ), 0 );
      if ( ! $result['valid'] ) {
        continue;
      }
      foreach ( (array) ( $amounts[ $result['wc_code'] ] ?? [] ) as $wc_key => $amount ) {
        $key = $keys[ $wc_key ] ?? null;
        if ( null !== $key ) {
          $result['by_line'][ $key ] += (int) round( (float) $amount );
          $by_line[ $key ]           += (int) round( (float) $amount );
        }
      }
      $result['amount_minor'] = array_sum( $result['by_line'] );
    }
    unset( $result );
    return [
      'coupons' => array_map( function ( $r ) {
        unset( $r['wc_code'] );
        return $r;
      }, $results ),
      'by_line' => $by_line,
    ];
  }

  private function apply_one( WC_Discounts $discounts, string $code ): array {
    $result = [ 'code' => $code, 'wc_code' => $code, 'valid' => false, 'message' => '', 'amount_minor' => 0 ];
    try {
      $coupon            = new WC_Coupon( $code );
      $result['wc_code'] = (string) $coupon->get_code();
      $applied           = $discounts->apply_coupon( $coupon, true );
    } catch ( Throwable $e ) {
      error_log( 'Bazaar coupon check failed for ' . $code . ': ' . get_class( $e ) . ': ' . $e->getMessage() );
      $result['message'] = __( 'This coupon could not be checked for this cart.', 'xophz-compass-bazaar' );
      return $result;
    }
    if ( is_wp_error( $applied ) ) {
      $result['message'] = self::plain( $applied->get_error_message() );
      return $result;
    }
    $result['valid'] = true;
    return $result;
  }

  /** WooCommerce key line-<n> => our line key. */
  private function wc_keys( array $items ): array {
    $keys = [];
    $n    = 0;
    foreach ( array_keys( $items ) as $key ) {
      $keys[ 'line-' . $n++ ] = $key;
    }
    return $keys;
  }

  private function wc_items( array $items, array $order_items, array $keys ): array {
    $out = [];
    foreach ( $keys as $wc_key => $key ) {
      $item           = new stdClass();
      $item->key      = $wc_key;
      $item->object   = $order_items[ $key ];
      $item->product  = $items[ $key ]['product'] ?: false;
      $item->quantity = (int) $items[ $key ]['quantity'];
      $item->price    = (float) $items[ $key ]['price'];
      $out[ $wc_key ] = $item;
    }
    return $out;
  }

  /**
   * An order that is never saved: customer context plus one item per line.
   *
   * @return array{0:WC_Order,1:WC_Order_Item_Product[]} The order and its items keyed by our line keys.
   */
  private function unsaved_order( array $items, array $customer, bool $prices_include_tax ): array {
    $order = new WC_Order();
    $order->set_prices_include_tax( $prices_include_tax );
    $customer_id = (int) ( $customer['id'] ?? 0 );
    $email       = (string) ( $customer['email'] ?? '' );
    if ( $customer_id > 0 ) {
      $order->set_customer_id( $customer_id );
      $user  = get_userdata( $customer_id );
      $email = '' === $email && $user ? (string) $user->user_email : $email;
    }
    if ( '' !== $email && is_email( $email ) ) {
      $order->set_billing_email( $email );
    }
    $tax         = 0.0;
    $order_items = [];
    foreach ( $items as $key => $line ) {
      $item = new WC_Order_Item_Product();
      if ( $line['product'] instanceof WC_Product ) {
        $item->set_product( $line['product'] );
      }
      $item->set_name( (string) $line['name'] );
      $item->set_quantity( (int) $line['quantity'] );
      $item->set_subtotal( (string) $line['subtotal'] );
      $item->set_total( (string) $line['subtotal'] );
      $item->set_subtotal_tax( (string) $line['subtotal_tax'] );
      $item->set_total_tax( (string) $line['subtotal_tax'] );
      $order->add_item( $item );
      $order_items[ $key ] = $item;
      $tax                += (float) $line['subtotal_tax'];
    }
    $order->set_cart_tax( $tax );
    return [ $order, $order_items ];
  }

  /** WooCommerce messages carry HTML entities and tags; the API returns plain text. */
  private static function plain( string $message ): string {
    return trim( html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
  }
}
