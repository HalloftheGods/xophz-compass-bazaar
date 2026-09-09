<?php
/**
 * Unified Bazaar Buy Router.
 *
 * Catches `/buy/...` routes, parses path segments, and delegates to the
 * dynamic Bazaar Checkout Service.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Bazaar_Buy_Router {

	/**
	 * Boot the router hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_template_redirect' ), 1 );
	}

	/**
	 * Register `xophz_buy_path` query var.
	 *
	 * @param array $vars
	 * @return array
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'xophz_buy_path';
		return $vars;
	}

	/**
	 * Register `/buy/(.+)` rewrite rule.
	 */
	public static function register_rewrite_rules() {
		add_rewrite_rule( '^buy/(.+)/?$', 'index.php?xophz_buy_path=$matches[1]', 'top' );
	}

	/**
	 * Intercept `/buy/...` requests and invoke the Checkout Service.
	 */
	public static function handle_template_redirect() {
		$buy_path = get_query_var( 'xophz_buy_path' );

		// Immediate fallback if rewrites have not yet flushed
		if ( empty( $buy_path ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$req_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
			if ( preg_match( '#^/buy/(.+?)/?$#', (string) $req_path, $matches ) ) {
				$buy_path = $matches[1];
			}
		}

		if ( empty( $buy_path ) ) {
			return;
		}

		$cleaned_path = trim( (string) $buy_path, '/' );
		$segments     = array_values( array_filter( explode( '/', $cleaned_path ) ) );

		if ( empty( $segments ) ) {
			wp_die( 'Please specify a product or tier to purchase.', 'Missing Target', array( 'response' => 400 ) );
		}

		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$result = Xophz_Bazaar_Checkout_Service::process_buy_request( $segments, $_GET, $method );

		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 400;
			wp_die( esc_html( $result->get_error_message() ), 'Checkout Error', array( 'response' => $status ) );
		}

		// For programmatic requests, output JSON if redirect was not issued
		if ( $method !== 'GET' ) {
			wp_send_json( $result );
		}

		exit;
	}
}
