<?php
/**
 * POS barcode lookup: one scanned code to one product.
 *
 * Guaranteed: bazaar_pos_lookup needs bazaar_sell, bazaar_manage_catalog or
 * bazaar_manage_stock (Security_Policy), so the product list can scan too. It
 * answers {success:true, found:false} or {success:true, found:true, product:
 * {id, title, price, sku, thumb, stock_quantity}}, or {success:false, message}
 * when the request fails. The code goes through Barcode::normalize and Quick
 * Add's find_existing, so a short UPC-E, an EAN-8 and a zero-padded form all
 * find the product that was saved under any of them.
 *
 * Not guaranteed: only an exact SKU or barcode match counts. A post ID or a
 * part of a name never matches. A draft or trashed product is "not found",
 * except that a catalog or stock manager who sends include_hidden finds a
 * draft, pending or private one too (the list's "Hidden" products).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Lookup {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_pos_lookup' => 'lookupPosProduct',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /** Clean active output buffers and emit the JSON payload. */
  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  public function lookupPosProduct() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell', 'bazaar_manage_catalog', 'bazaar_manage_stock' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      // Hidden products are for managers only: the POS must never sell a draft.
      $hidden_ok = Xophz_Compass_Bazaar_Core_Request::bool_param( $args, 'include_hidden' ) && Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_manage_catalog', 'bazaar_manage_stock' );
      $statuses  = $hidden_ok ? [ 'publish', 'private', 'draft', 'pending' ] : [ 'publish', 'private' ];
      $code = Xophz_Compass_Bazaar_Barcode::normalize( isset( $args->code ) && is_scalar( $args->code ) ? sanitize_text_field( (string) $args->code ) : '' );
      $id   = '' === $code['raw'] ? 0 : Xophz_Compass_Bazaar_Admin_Quick_Add::find_existing( $code );
      $p    = $id > 0 ? wc_get_product( $id ) : false;

      if ( ! $p || ! in_array( $p->get_status(), $statuses, true ) ) {
        $this->send_json_response( [ 'success' => true, 'found' => false ] );
        return;
      }
      $this->send_json_response( [
        'success' => true,
        'found'   => true,
        'product' => [
          'id'             => $p->get_id(),
          'title'          => $p->get_name(),
          'price'          => $p->get_price(),
          'sku'            => $p->get_sku(),
          'thumb'          => wp_get_attachment_image_url( $p->get_image_id() ) ?: '',
          'status'         => $p->get_status(),
          'stock_quantity' => $p->get_stock_quantity(),
        ],
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
