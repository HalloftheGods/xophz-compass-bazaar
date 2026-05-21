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
    'wp_ajax_create_pos_order' => 'createPosOrder',
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
      $data = $order->get_data();
      $data['order'] = $order->get_order_number();
      if (isset($data['date_created']) && is_a($data['date_created'], 'WC_DateTime')) {
          $data['date_created'] = $data['date_created']->date('Y-m-d H:i:s');
      }
      
      $cashier_id = $order->get_meta('_pos_cashier_id');
      if ($cashier_id) {
          $cashier = get_userdata($cashier_id);
          if ($cashier) {
              $data['cashier_name'] = $cashier->display_name;
          }
      }
      
      return $data;
    };

    $orders_data = array_map( $mapOrderData, $orderIds->orders );

    Xophz_Compass::output_json([
      'total_count' => (int) $orderIds->total, 
      'data'        => $orders_data
    ]);
  }

  public function createPosOrder() {
    $args = Xophz_Compass::get_input_json();
    $items = isset($args->items) ? $args->items : [];

    // Parse stringified JSON items array from FormData
    if (is_string($items)) {
        $decoded = json_decode($items);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $raw_input = file_get_contents('php://input');
            parse_str($raw_input, $raw_parsed);
            $decoded = isset($raw_parsed['items']) ? json_decode(stripslashes($raw_parsed['items'])) : [];
        }
        $items = $decoded;
    }

    $discount = isset($args->discount) ? floatval($args->discount) : 0;
    $paymentMethod = isset($args->paymentMethod) ? $args->paymentMethod : 'cash';

    if (empty($items)) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Cart is empty.']);
        return;
    }

    try {
        $order = wc_create_order();
        
        $current_user_id = get_current_user_id();
        if ($current_user_id) {
            $order->update_meta_data('_pos_cashier_id', $current_user_id);
        }

        foreach ($items as $item) {
            $product_id = intval($item->product_id);
            $quantity = intval($item->quantity);
            $product = wc_get_product($product_id);

            if ($product) {
                $order->add_product($product, $quantity);
            }
        }

        if ($discount > 0) {
            $item = new WC_Order_Item_Fee();
            $item->set_name('POS Discount');
            $item->set_amount(-$discount);
            $item->set_total(-$discount);
            $order->add_item($item);
        }

        // Set payment method
        $order->set_payment_method($paymentMethod);
        $order->set_payment_method_title(ucfirst($paymentMethod));

        // Calculate totals
        $order->calculate_totals();

        // Complete the order since POS is an immediate transaction
        $order->update_status('completed', 'Order created via Bazaar POS.');

        Xophz_Compass::output_json([
            'success' => true, 
            'order_id' => $order->get_id()
        ]);
    } catch (Exception $e) {
        Xophz_Compass::output_json([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
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

  public static function getOrderIds($args){
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
	public function display_element( $element, &$children_elements, $max_depth, $depth, $args, &$output ) {
    if ( ! $element || ( 0 === $element->count && ! empty( $args[0]['hide_empty'] ) ) ) {
      return;
    }
    parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
	}
}
