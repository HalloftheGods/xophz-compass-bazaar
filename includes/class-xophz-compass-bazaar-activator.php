<?php

/**
 * Fired during plugin activation
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Bazaar_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Activation runs before the plugin class is constructed, so register the autoloader here.
		require_once plugin_dir_path( __FILE__ ) . 'core/class-xophz-compass-bazaar-core-autoloader.php';
		Xophz_Compass_Bazaar_Core_Autoloader::register( dirname( __DIR__ ) );
		Xophz_Compass_Bazaar_Schema_Installer::install();
	}

}
