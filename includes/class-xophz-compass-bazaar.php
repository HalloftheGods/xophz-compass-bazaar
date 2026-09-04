<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://youmeos.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 * @author     Your Name
 */
if ( ! class_exists( 'Xophz_Compass_Plugin_Base' ) ) {
	$core_plugin_base = dirname( dirname( __DIR__ ) ) . '/xophz-compass/includes/core/class-compass-plugin-base.php';
	if ( file_exists( $core_plugin_base ) ) {
		require_once $core_plugin_base;
	}
}

class Xophz_Compass_Bazaar extends Xophz_Compass_Plugin_Base {

  /**
  * The loader that's responsible for maintaining and registering all hooks that power
  * the plugin.
  *
  * @since    1.0.0
  * @access   protected
  * @var      Xophz_Compass_Bazaar_Loader    $loader    Maintains and registers all hooks for the plugin.
  */
  protected $loader;

  /**
  * The unique identifier of this plugin.
  *
  * @since    1.0.0
  * @access   protected
  * @var      string    $plugin_name    The string used to uniquely identify this plugin.
  */
  protected $plugin_name;

  /**
  * The current version of the plugin.
  *
  * @since    1.0.0
  * @access   protected
  * @var      string    $version    The current version of the plugin.
  */
  protected string $version;

  /**
  * Define the core functionality of the plugin.
  *
  * Set the plugin name and the plugin version that can be used throughout the plugin.
  * Load the dependencies, define the locale, and set the hooks for the admin area and
  * the public-facing side of the site.
  *
  * @since    1.0.0
  */
  public function __construct( ?string $param1 = null, ?string $version = null, string $param3 = '' ) {
		if ( null === $param1 ) {
			$file = dirname( __DIR__ ) . '/xophz-compass-bazaar.php';
			$ver  = defined( 'XOPHZ_COMPASS_BAZAAR_VERSION' ) ? XOPHZ_COMPASS_BAZAAR_VERSION : '1.0.0';
			parent::__construct( $file, $ver, 'xophz-compass-bazaar' );
		} else {
			parent::__construct( $param1, $version ?? '1.0.0', $param3 );
		}
		$this->plugin_name = $this->text_domain;
		$this->loader = $this;

    $this->load_dependencies();
    $this->set_locale();
    $this->define_admin_hooks();
    $this->define_public_hooks();
    $this->define_compass_hooks();
  }

  /**
  * Load the required dependencies for this plugin.
  *
  * Include the following files that make up the plugin:
  *
  * - Xophz_Compass_Bazaar_Loader. Orchestrates the hooks of the plugin.
  * - Xophz_Compass_Bazaar_i18n. Defines internationalization functionality.
  * - Xophz_Compass_Bazaar_Admin. Defines all hooks for the admin area.
  * - Xophz_Compass_Bazaar_Public. Defines all hooks for the public side of the site.
  *
  * Create an instance of the loader which will be used to register the hooks
  * with WordPress.
  *
  * @since    1.0.0
  * @access   private
  */
  private function load_dependencies() {
    /**
    * The class responsible for orchestrating the actions and filters of the
    * core plugin.
    */

    /**
    * The class responsible for defining internationalization functionality
    * of the plugin.
    */

    /**
    * The class responsible for defining all actions that occur in the admin area.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin.php';
    /**
      * The class responsible for orders
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin-orders.php';
    /**
      * The class responsible for sales 
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin-sales.php';
    /**
      * The class responsible for reports 
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin-reports.php';
    
    /**
      * The class responsible for procurement / POs
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin-procurement.php';

    /**
      * The class responsible for twilio
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-twilio.php';

    /**
      * The class responsible for multi-inventory and warehouse management
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin-multi-inventory.php';

    /**
      * The class responsible for products 
    */
    // require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-bazaar-admin.php';

    /**
    * The class responsible for defining all actions that occur in the public-facing
    * side of the site.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-xophz-compass-bazaar-public.php';

  }

  /**
  * Define the locale for this plugin for internationalization.
  *
  * Uses the Xophz_Compass_Bazaar_i18n class in order to set the domain and to register the hook
  * with WordPress.
  *
  * @since    1.0.0
  * @access   private
  */
  private function set_locale() {
		// Localization handled by Xophz_Compass_Plugin_Base on init priority 5
	}

  /**
  * Register all of the hooks related to the admin area functionality
  * of the plugin.
  *
  * @since    1.0.0
  * @access   private
  */
  private function define_admin_hooks() {
    $plugin_admin = new Xophz_Compass_Bazaar_Admin( $this->get_plugin_name(), $this->get_version() );

    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );

    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

    $this->loader->add_action( 'admin_menu', $plugin_admin, 'addToMenu' );

    $this->loader->add_action( 'wp_ajax_get_products', $plugin_admin, 'getProducts'); 

    $this->loader->add_action( 'wp_ajax_get_products_stats', $plugin_admin, 'getProductsStats'); 

    $this->loader->add_action( 'wp_ajax_update_product_stock', $plugin_admin, 'updateProductStock');

    $this->loader->add_action( 'wp_ajax_save_product', $plugin_admin, 'saveProduct');

    $this->loader->add_action( 'wp_ajax_bazaar_import_products_csv', $plugin_admin, 'importProductsCsv');

    $this->loader->add_action( 'wp_ajax_bazaar_lookup_barcode', $plugin_admin, 'lookupBarcode');
  }

  private function define_compass_hooks(){
    $this->define_class_hooks('Xophz_Compass_Bazaar_Admin_Orders');
    $this->define_class_hooks('Xophz_Compass_Bazaar_Admin_Sales');
    $this->define_class_hooks('Xophz_Compass_Bazaar_Admin_Reports');
    $this->define_class_hooks('Xophz_Compass_Bazaar_Admin_Procurement');
    $this->define_class_hooks('Xophz_Compass_Bazaar_Twilio');
    $this->define_class_hooks('Xophz_Compass_Bazaar_Admin_Multi_Inventory');
  } 

  public function define_class_hooks($class){
    $class = new $class($this->get_plugin_name(), $this->get_version());

    if($class->action_hooks){
      foreach($class->action_hooks as $action => $hook){
        $this->loader->add_action( $action, $class, $hook); 
      }
    }
  }

  /**
  * Register all of the hooks related to the public-facing functionality
  * of the plugin.
  *
  * @since    1.0.0
  * @access   private
  */
  private function define_public_hooks() {

    $plugin_public = new Xophz_Compass_Bazaar_Public( $this->get_plugin_name(), $this->get_version() );

    // $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
    // $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

  }

  /**
  * Run the loader to execute all of the hooks with WordPress.
  *
  * @since    1.0.0
  */
  public function run(): void {
		$this->run_hooks();
	}

  /**
  * The name of the plugin used to uniquely identify it within the context of
  * WordPress and to define internationalization functionality.
  *
  * @since     1.0.0
  * @return    string    The name of the plugin.
  */
  public function get_plugin_name() {
    return $this->plugin_name;
  }

  /**
  * The reference to the class that orchestrates the hooks with the plugin.
  *
  * @since     1.0.0
  * @return    Xophz_Compass_Bazaar_Loader    Orchestrates the hooks of the plugin.
  */
  public function get_loader(): self {
		return $this;
	}

  /**
  * Retrieve the version number of the plugin.
  *
  * @since     1.0.0
  * @return    string    The version number of the plugin.
  */
  public function get_version(): string {
		return $this->version;
	}

}
