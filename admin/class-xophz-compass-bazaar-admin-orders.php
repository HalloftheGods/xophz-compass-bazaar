<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Bazaar_Admin_Orders {
  /**
  * The ID of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $plugin_name    The ID of this plugin.
  */
  private $plugin_name;

  /**
  * The version of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $version    The current version of this plugin.
  */
  private $version;

  public  $action_hooks = [
    'wp_ajax_get_orders' => 'getOrders',
    'wp_ajax_get_categories' => 'getCategories',
  ];

  /**
  * Initialize the class and set its properties.
  *
  * @since    1.0.0
  * @param    string    $plugin_name  The name of this plugin.
  * @param    string    $version      The version of this plugin.
  */
  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  public function getOrders(){
    $args   = Xophz_Compass::get_input_json();
    $args->return = 'objects';

    $orderIds = Xophz_Compass_Bazaar_Admin_Orders::getOrderIds($args);

    $mapOrderData = function($id){
      $order = wc_get_order($id);
      return $order->get_data();
    };

    $orders = array_map( $mapOrderData, $orderIds->orders );

    Xophz_Compass::output_json([
      'total_count' => (int) $orders->total, 
      'data'        => $orders
    ]);
  }
  /**
    * undocumented function
    *
    * @return void
    */
  public function getCategories()
  {
    global $wp_query;

    $args['orderby']  = 'meta_value_num';
    $args['meta_key'] = 'order'; // phpcs:ignore

    $args = 
      array(
        'meta_key' => 'order',
        'orderby' => 'meta_value_num',
        'pad_counts'         => 1,
        'show_count'         => 1,
        'hierarchical'       => 1,
        'hide_empty'         => 1,
        'taxonomy'           => 'product_cat',
    );

    if ( 'order' === $args['orderby'] ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'order'; // phpcs:ignore
    }

    $categories = get_terms($args['taxonomy'],$args);
    $walker = new Walker_Simple_String($args);

    $walker->walk($categories,0);

    Xophz_Compass::output_json([
      'categories' => $walker->categories 
    ]);
  }

  public function getOrderIds($args){
    $default = [
      'return'    => 'ids',
      'paginate'  => true
    ];

    return wc_get_orders( array_merge($default, (array) $args) );
  }
}

class Walker_Simple_String extends Walker {
	/**
	 * What the class handles.
	 *
	 * @var string
	 */
	public $tree_type = 'category';
	public $categories = [];

	/**
	 * DB fields to use.
	 *
	 * @var array
	 */
	public $db_fields = array(
    'parent' => 'parent',
    'id'     => 'term_id',
    'slug'   => 'slug',
	);

	/**
	 * Starts the list before the elements are added.
	 *
	 * @see Walker::start_el()
	 * @since 2.1.0
	 *
	 * @param string $output            Passed by reference. Used to append additional content.
	 * @param object $cat               Category.
	 * @param int    $depth             Depth of category in reference to parents.
	 * @param array  $args              Arguments.
	 * @param int    $current_object_id Current object ID.
	 */
	public function start_el( &$output, $cat, $depth = 0, $args = array(), $current_object_id = 0 ) {
    $pad = str_repeat( '&nbsp;', $depth * 3 );

    $cat_name = apply_filters( 'list_product_cats', $cat->name, $cat );
    $this->categories[] = [
      'text' => esc_html( $pad . $cat_name ) . '&nbsp;(' . absint( $cat->count ) . ')',
      'value' => $cat->slug
    ];
	}

	/**
	 * Traverse elements to create list from elements.
	 *
	 * Display one element if the element doesn't have any children otherwise,
	 * display the element and its children. Will only traverse up to the max.
	 * depth and no ignore elements under that depth. It is possible to set the.
	 * max depth to include all depths, see walk() method.
	 *
	 * This method shouldn't be called directly, use the walk() method instead.
	 *
	 * @since 2.5.0
	 *
	 * @param object $element           Data object.
	 * @param array  $children_elements List of elements to continue traversing.
	 * @param int    $max_depth         Max depth to traverse.
	 * @param int    $depth             Depth of current element.
	 * @param array  $args              Arguments.
	 * @param string $output            Passed by reference. Used to append additional content.
	 * @return null Null on failure with no changes to parameters.
	 */
	public function display_element( $element, &$children_elements, $max_depth, $depth = 0, $args, &$output ) {
    if ( ! $element || ( 0 === $element->count && ! empty( $args[0]['hide_empty'] ) ) ) {
      return;
    }
    parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
	}
}
