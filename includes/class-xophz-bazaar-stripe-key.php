<?php
/**
 * Stripe secret key resolution for Bazaar checkout, through the WordPress Connectors API.
 *
 * The key is never read from an option, constant or environment variable by a name this
 * plugin chooses. It comes from the `stripe_secret_key` connector (live) or its
 * `stripe_test_secret_key` sibling (sandbox), both managed at Settings > Connectors.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Bazaar_Stripe_Key {

	/**
	 * Credential VALUE of a connector registered with the WordPress core Connectors API.
	 *
	 * Core exposes no public getter, so this follows core's precedence (environment variable,
	 * PHP constant, then the option) using the names from the connector's own `authentication`
	 * definition. Server-side use only; '' when the connector is unregistered or unset.
	 *
	 * @param string $connector_id Connector id, e.g. 'stripe_secret_key'.
	 * @return string
	 */
	private static function get_connector_key( string $connector_id ): string {
		if ( ! function_exists( 'wp_get_connector' ) || ! wp_is_connector_registered( $connector_id ) ) {
			return '';
		}

		$auth       = (array) ( wp_get_connector( $connector_id )['authentication'] ?? array() );
		$candidates = array(
			! empty( $auth['env_var_name'] ) ? getenv( (string) $auth['env_var_name'] ) : '',
			! empty( $auth['constant_name'] ) && defined( (string) $auth['constant_name'] ) ? constant( (string) $auth['constant_name'] ) : '',
			! empty( $auth['setting_name'] ) ? get_option( (string) $auth['setting_name'], '' ) : '',
		);

		foreach ( $candidates as $candidate ) {
			$value = is_scalar( $candidate ) ? trim( (string) $candidate ) : '';
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Whether this request is running on a local development host.
	 *
	 * @return bool
	 */
	private static function is_local(): bool {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, array( 'local', 'development' ), true ) ) {
			return true;
		}

		$host = ! empty( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';

		return '' !== $host && ( strpos( $host, 'localhost' ) !== false || strpos( $host, '127.0.0.1' ) !== false || strpos( $host, '.local' ) !== false );
	}

	/**
	 * Retrieve the active Stripe secret key.
	 *
	 * Live checkouts use the `stripe_secret_key` connector. Sandbox checkouts (forced, or on a
	 * local host) use `stripe_test_secret_key` so a test request can never charge the live
	 * account. With no live key configured the test key is used, as before.
	 *
	 * @param bool $force_test Whether to force selection of the Stripe Test Key.
	 * @return string
	 */
	public static function resolve( bool $force_test = false ): string {
		$test_key = self::get_connector_key( 'stripe_test_secret_key' );

		if ( ( $force_test || self::is_local() ) && '' !== $test_key ) {
			return $test_key;
		}

		$live_key = self::get_connector_key( 'stripe_secret_key' );

		return '' !== $live_key ? $live_key : $test_key;
	}
}
