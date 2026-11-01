<?php
/**
 * Product category handlers for the Bazaar register and catalog.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Categories {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_get_categories'         => 'getCategories',
    'wp_ajax_bazaar_create_category' => 'createCategory',
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

  /** @throws \Exception 'Permission denied.' when the user holds none of the caps. */
  private function require_any( string ...$caps ): void {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( ...$caps ) ) {
      throw new \Exception( 'Permission denied.' );
    }
  }

  public function getCategories() {
    try {
      $this->require_any( 'bazaar_sell', 'bazaar_manage_catalog', 'bazaar_manage_stock' );

      $args = [
        'meta_key'     => 'order', // phpcs:ignore
        'orderby'      => 'meta_value_num',
        'pad_counts'   => 1,
        'show_count'   => 1,
        'hierarchical' => 1,
        'hide_empty'   => 0,
        'taxonomy'     => 'product_cat',
      ];

      $categories = get_terms( $args['taxonomy'], $args );
      if ( is_wp_error( $categories ) ) {
        throw new \Exception( $categories->get_error_message() );
      }
      $walker = new Walker_Simple_String( $args );

      $walker->walk( $categories, 0 );

      $this->send_json_response( [
        'success'    => true,
        'categories' => $walker->categories,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'categories' => [] ] );
    }
  }

  public function createCategory() {
    try {
      $this->require_any( 'bazaar_manage_catalog' );

      $args   = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $name   = isset( $args->name ) ? trim( sanitize_text_field( $args->name ) ) : '';
      $parent = isset( $args->parent_id ) ? intval( $args->parent_id ) : 0;

      if ( empty( $name ) ) {
        throw new \Exception( 'Category name is required.' );
      }

      $term_args = [];
      if ( $parent > 0 ) {
        $term_args['parent'] = $parent;
      }

      $res = wp_insert_term( $name, 'product_cat', $term_args );
      if ( is_wp_error( $res ) ) {
        throw new \Exception( $res->get_error_message() );
      }

      $term = get_term( $res['term_id'], 'product_cat' );

      $this->send_json_response( [
        'success'  => true,
        'category' => [
          'id'    => $term->term_id,
          'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
          'slug'  => $term->slug,
          'count' => $term->count,
          'text'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) . ' (0)',
          'value' => $term->slug,
        ],
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}

/**
 * Flattens a category tree into [id, name, text, value] rows with a dash
 * indent per depth. Used by Admin_Categories::getCategories().
 */
class Walker_Simple_String extends Walker {
  /**
   * What the class handles.
   *
   * @var string
   */
  public $tree_type = 'category';

  /** @var array The rows collected by start_el(). */
  public $categories = [];

  /**
   * DB fields to use.
   *
   * @var array
   */
  public $db_fields = [
    'parent' => 'parent',
    'id'     => 'term_id',
    'slug'   => 'slug',
  ];

  /**
   * Add one category row.
   *
   * @param string $output            Unused.
   * @param object $cat               Category.
   * @param int    $depth             Depth of category in reference to parents.
   * @param array  $args              Arguments.
   * @param int    $current_object_id Current object ID.
   */
  public function start_el( &$output, $cat, $depth = 0, $args = [], $current_object_id = 0 ) {
    $indent = $depth > 0 ? str_repeat( '- ', $depth ) : '';

    $cat_name = apply_filters( 'list_product_cats', $cat->name, $cat );
    $cat_name = html_entity_decode( $cat_name, ENT_QUOTES, 'UTF-8' );

    $this->categories[] = [
      'id'    => $cat->term_id,
      'name'  => $cat_name,
      'text'  => $indent . $cat_name . ' (' . absint( $cat->count ) . ')',
      'value' => $cat->slug,
    ];
  }

  /**
   * Traverse elements to create the list; see Walker::display_element().
   *
   * @param object $element           Data object.
   * @param array  $children_elements List of elements to continue traversing.
   * @param int    $max_depth         Max depth to traverse.
   * @param int    $depth             Depth of current element.
   * @param array  $args              Arguments.
   * @param string $output            Passed by reference.
   * @return null Null on failure with no changes to parameters.
   */
  public function display_element( $element, &$children_elements, $max_depth, $depth, $args, &$output ) {
    if ( ! $element ) {
      return;
    }
    parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
  }
}
