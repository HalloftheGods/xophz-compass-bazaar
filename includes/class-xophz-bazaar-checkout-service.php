<?php
/**
 * Unified Bazaar Checkout Service & Dynamic Ad-Hoc Ledger.
 *
 * Provides central Stripe checkout session generation, dynamic product/pricing
 * resolution hooks, backward-compatible legacy tier fallbacks, and a lightweight
 * transaction ledger without creating synthetic WooCommerce products.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Bazaar_Checkout_Service {

	/**
	 * Retrieve the active Stripe Secret Key across WordPress options, constants, and environment.
	 *
	 * @param bool $force_test Whether to force selection of the Stripe Test Key.
	 * @return string
	 */
	public static function get_stripe_secret_key( bool $force_test = false ): string {
		$is_local = false;
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, array( 'local', 'development' ), true ) ) {
			$is_local = true;
		} elseif ( ! empty( $_SERVER['HTTP_HOST'] ) && ( strpos( $_SERVER['HTTP_HOST'], 'localhost' ) !== false || strpos( $_SERVER['HTTP_HOST'], '127.0.0.1' ) !== false || strpos( $_SERVER['HTTP_HOST'], '.local' ) !== false ) ) {
			$is_local = true;
		}

		$test_keys = array(
			get_option( 'compass_stripe_test_secret_key' ),
			get_option( 'stripe_test_secret_key' ),
			defined( 'STRIPE_TEST_SECRET_KEY' ) ? STRIPE_TEST_SECRET_KEY : '',
			getenv( 'STRIPE_TEST_SECRET_KEY' ) ?: '',
			isset( $_ENV['STRIPE_TEST_SECRET_KEY'] ) ? $_ENV['STRIPE_TEST_SECRET_KEY'] : '',
		);

		$resolved_test_key = '';
		foreach ( $test_keys as $tk ) {
			$trimmed = trim( (string) $tk );
			if ( ! empty( $trimmed ) ) {
				$resolved_test_key = $trimmed;
				break;
			}
		}

		// When developing locally or when test mode is forced, prioritize the Stripe Test Key
		if ( ( $force_test || $is_local ) && ! empty( $resolved_test_key ) ) {
			return $resolved_test_key;
		}

		$live_keys = array(
			get_option( 'compass_stripe_secret_key' ),
			get_option( 'xophz_compass_stripe_secret_key' ),
			get_option( 'stripe_secret_key' ),
			defined( 'STRIPE_SECRET_KEY' ) ? STRIPE_SECRET_KEY : '',
			getenv( 'STRIPE_SECRET_KEY' ) ?: '',
			isset( $_ENV['STRIPE_SECRET_KEY'] ) ? $_ENV['STRIPE_SECRET_KEY'] : '',
		);

		foreach ( $live_keys as $k ) {
			$trimmed = trim( (string) $k );
			if ( ! empty( $trimmed ) ) {
				return $trimmed;
			}
		}

		// Fallback to test key if no live key is configured
		if ( ! empty( $resolved_test_key ) ) {
			return $resolved_test_key;
		}

		return '';
	}

	/**
	 * Default fallback product catalog preserving 100% legacy Tesseract tiers and ad-hoc products.
	 *
	 * @return array
	 */
	public static function get_default_catalog(): array {
		return array(
			// Tesseract Sovereign Node Tiers (Legacy compatibility for blackboxwhite.com)
			'quantum'            => array( 'name' => 'Quantum', 'diy_price' => 14.99, 'white_glove_price' => 149.00, 'hours' => 1, 'mode' => 'subscription' ),
			'bronze'             => array( 'name' => 'Bronze', 'diy_price' => 34.99, 'white_glove_price' => 299.00, 'hours' => 2, 'mode' => 'subscription' ),
			'silver'             => array( 'name' => 'Silver', 'diy_price' => 74.99, 'white_glove_price' => 399.00, 'hours' => 3, 'mode' => 'subscription' ),
			'silver-enhanced'    => array( 'name' => 'Silver Enhanced', 'diy_price' => 99.99, 'white_glove_price' => 599.00, 'hours' => 4, 'mode' => 'subscription' ),
			'gold'               => array( 'name' => 'Gold', 'diy_price' => 129.99, 'white_glove_price' => 799.00, 'hours' => 5, 'mode' => 'subscription' ),
			'gold-enhanced'      => array( 'name' => 'Gold Enhanced', 'diy_price' => 242.40, 'white_glove_price' => 999.00, 'hours' => 6, 'mode' => 'subscription' ),
			'platinum'           => array( 'name' => 'Platinum', 'diy_price' => 299.00, 'white_glove_price' => 1299.00, 'hours' => 8, 'mode' => 'subscription' ),
			'platinum-enhanced'  => array( 'name' => 'Platinum Enhanced', 'diy_price' => 420.42, 'white_glove_price' => 1799.00, 'hours' => 10, 'mode' => 'subscription' ),
			'uranium'            => array( 'name' => 'Uranium', 'diy_price' => 650.00, 'white_glove_price' => 2499.00, 'hours' => 15, 'mode' => 'subscription' ),
			'titanium'           => array( 'name' => 'Titanium', 'diy_price' => 1250.00, 'white_glove_price' => 3499.00, 'hours' => 20, 'mode' => 'subscription' ),
			'palladium'          => array( 'name' => 'Palladium', 'diy_price' => 2499.00, 'white_glove_price' => 4999.00, 'hours' => 30, 'mode' => 'subscription' ),
			'palladium-enhanced' => array( 'name' => 'Palladium Enhanced', 'diy_price' => 4999.00, 'white_glove_price' => 4999.00, 'hours' => 30, 'mode' => 'subscription' ),
			'rhodium'            => array( 'name' => 'Rhodium', 'diy_price' => 7500.00, 'white_glove_price' => 7500.00, 'hours' => 40, 'mode' => 'subscription' ),
			'iridium'            => array( 'name' => 'Iridium', 'diy_price' => 10000.00, 'white_glove_price' => 10000.00, 'hours' => 50, 'mode' => 'subscription' ),
			// WPMU DEV Dedicated Hosting Plan Aliases
			'bronze-plus'        => array( 'name' => 'Bronze Plus', 'diy_price' => 15.00, 'white_glove_price' => 299.00, 'hours' => 2, 'mode' => 'subscription' ),
			'bronze-max'         => array( 'name' => 'Bronze Max', 'diy_price' => 23.00, 'white_glove_price' => 299.00, 'hours' => 2, 'mode' => 'subscription' ),
			'silver-plus'        => array( 'name' => 'Silver Plus', 'diy_price' => 35.00, 'white_glove_price' => 399.00, 'hours' => 3, 'mode' => 'subscription' ),
			'silver-max'         => array( 'name' => 'Silver Max', 'diy_price' => 45.00, 'white_glove_price' => 599.00, 'hours' => 4, 'mode' => 'subscription' ),
			'gold-plus'          => array( 'name' => 'Gold Plus', 'diy_price' => 150.00, 'white_glove_price' => 999.00, 'hours' => 6, 'mode' => 'subscription' ),
			'platinum-plus'      => array( 'name' => 'Platinum Plus', 'diy_price' => 200.00, 'white_glove_price' => 1799.00, 'hours' => 10, 'mode' => 'subscription' ),
			'uranium-plus'       => array( 'name' => 'Uranium Plus', 'diy_price' => 300.00, 'white_glove_price' => 2499.00, 'hours' => 15, 'mode' => 'subscription' ),

			// Chemical X Interactive Architecture Vault
			'chemical-x/standard'   => array( 'name' => 'Chemical X: Single Developer License', 'price' => 27.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'standard' ),
			'chemical-x-standard'   => array( 'name' => 'Chemical X: Single Developer License', 'price' => 27.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'standard' ),
			'chemical-x/master'     => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),
			'chemical-x-master'     => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),
			'chemical-x/vip'        => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),
			'chemical-x/power-puff' => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),
			'chemical-x-power-puff' => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),
			'chemical-x/team'       => array( 'name' => 'Chemical X: Team Power Puff (Unlimited Lifetime)', 'price' => 97.00, 'mode' => 'payment', 'product' => 'chemical-x', 'tier' => 'vip_bundle' ),

			// Card Vault Pro & Shop Software Licenses
			'card-vault/single-annual'   => array( 'name' => 'Card Vault: Single Dealer License (Annual Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 3, 'product' => 'card-vault', 'tier' => 'single' ),
			'card-vault-single-annual'   => array( 'name' => 'Card Vault: Single Dealer License (Annual Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 3, 'product' => 'card-vault', 'tier' => 'single' ),
			'card-vault/single-lifetime' => array( 'name' => 'Card Vault: Single Dealer License (Lifetime Access)', 'price' => 249.00, 'mode' => 'payment', 'product' => 'card-vault', 'tier' => 'single_lifetime' ),
			'card-vault-single-lifetime' => array( 'name' => 'Card Vault: Single Dealer License (Lifetime Access)', 'price' => 249.00, 'mode' => 'payment', 'product' => 'card-vault', 'tier' => 'single_lifetime' ),
			'card-vault/single'          => array( 'name' => 'Card Vault: Single Dealer License (Annual Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 3, 'product' => 'card-vault', 'tier' => 'single' ),

			'card-vault/team-annual'     => array( 'name' => 'Card Vault: Team & Card Shop License (Annual Subscription)', 'price' => 249.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'product' => 'card-vault', 'tier' => 'team' ),
			'card-vault-team-annual'     => array( 'name' => 'Card Vault: Team & Card Shop License (Annual Subscription)', 'price' => 249.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'product' => 'card-vault', 'tier' => 'team' ),
			'card-vault/team-lifetime'   => array( 'name' => 'Card Vault: Team & Card Shop License (Lifetime Access)', 'price' => 599.00, 'mode' => 'payment', 'product' => 'card-vault', 'tier' => 'team_lifetime' ),
			'card-vault-team-lifetime'   => array( 'name' => 'Card Vault: Team & Card Shop License (Lifetime Access)', 'price' => 599.00, 'mode' => 'payment', 'product' => 'card-vault', 'tier' => 'team_lifetime' ),
			'card-vault/team'            => array( 'name' => 'Card Vault: Team & Card Shop License (Annual Subscription)', 'price' => 249.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'product' => 'card-vault', 'tier' => 'team' ),
			'card-vault'                 => array( 'name' => 'Card Vault: Single Dealer License (Annual Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 3, 'product' => 'card-vault', 'tier' => 'single' ),

			// Sovereign BlackBox Node: Turnkey Card Shop Website & Consignment Portal ($99/mo or $999/yr)
			'card-vault/node-monthly'     => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Monthly Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'month', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault-node-monthly'     => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Monthly Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'month', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault/node-annual'      => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault-node-annual'      => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault/node'             => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault-node'             => array( 'name' => 'Card Vault: Sovereign BlackBox Shop Node (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'node' ),
			'card-vault/turnkey-monthly'  => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Monthly Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'month', 'product' => 'card-vault', 'tier' => 'turnkey' ),
			'card-vault-turnkey-monthly'  => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Monthly Subscription)', 'price' => 99.00, 'mode' => 'subscription', 'interval' => 'month', 'product' => 'card-vault', 'tier' => 'turnkey' ),
			'card-vault/turnkey-annual'   => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'turnkey' ),
			'card-vault-turnkey-annual'   => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'turnkey' ),
			'card-vault/turnkey'          => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'turnkey' ),
			'card-vault-turnkey'          => array( 'name' => 'Card Vault: Sovereign Turnkey Website (Annual Subscription)', 'price' => 999.00, 'mode' => 'subscription', 'interval' => 'year', 'product' => 'card-vault', 'tier' => 'turnkey' ),

			// Developer UI Kit & Compass Engine Offerings
			'ui-kit'              => array( 'name' => 'Glassmorphic UI Kit (Single Site License)', 'price' => 49.98, 'mode' => 'payment' ),
			'ui-kit-agency'       => array( 'name' => 'Glassmorphic UI Kit (Agency 5-Site License)', 'price' => 149.98, 'mode' => 'payment' ),
			'ui-kit-unlimited'    => array( 'name' => 'Glassmorphic UI Kit (Unlimited Commercial License)', 'price' => 399.98, 'mode' => 'payment' ),
			'sovereign-engine'    => array( 'name' => 'Sovereign Ecosystem Engine (Monthly Subscription)', 'price' => 99.98, 'mode' => 'subscription' ),
		);
	}

	/**
	 * Process a `/buy/...` route request dynamically.
	 *
	 * @param array  $segments Path segments (e.g. ['singles', 'card-1042', 'vendor-9'] or ['bronze']).
	 * @param array  $query    Query string parameters ($_GET).
	 * @param string $method   HTTP method ('GET' or 'POST').
	 * @return array|WP_Error
	 */
	public static function process_buy_request( array $segments, array $query = array(), string $method = 'GET' ) {
		// Detect sandbox / test checkout request
		$test_requested  = ! empty( $query['test'] ) || ! empty( $query['test_mode'] ) || ! empty( $query['sandbox'] );
		$wizard_key      = get_option( 'compass_wizard_key', '' );
		$has_valid_dev   = ! empty( $wizard_key ) && ! empty( $query['dev_key'] ) && hash_equals( $wizard_key, (string) $query['dev_key'] );
		$from_local      = ( ! empty( $query['return_origin'] ) && ( strpos( $query['return_origin'], 'localhost' ) !== false || strpos( $query['return_origin'], '127.0.0.1' ) !== false ) )
			|| ( ! empty( $query['return_url'] ) && ( strpos( $query['return_url'], 'localhost' ) !== false || strpos( $query['return_url'], '127.0.0.1' ) !== false ) );
		$force_test_mode = $test_requested || $has_valid_dev || $from_local;

		// 1. Give external plugins first right of refusal via dynamic resolution hook
		$dynamic_resolved = apply_filters( 'xophz_resolve_buy_request', null, $segments, $query );

		if ( is_array( $dynamic_resolved ) && ! empty( $dynamic_resolved['handled'] ) ) {
			$dynamic_resolved['force_test'] = $dynamic_resolved['force_test'] ?? $force_test_mode;

			// Attach verified success and cancel URLs if not provided
			if ( empty( $dynamic_resolved['success_url'] ) || empty( $dynamic_resolved['cancel_url'] ) ) {
				$return_url = ! empty( $query['return_url'] ) ? esc_url_raw( $query['return_url'] ) : '';
				$is_allowed = class_exists( 'Xophz_Compass_Security' )
					? Xophz_Compass_Security::is_allowed_redirect_url( $return_url )
					: ! empty( $return_url );

				$target_slug = sanitize_key( $dynamic_resolved['metadata']['plugin_slug'] ?? ( $segments[0] ?? '' ) );

				if ( $is_allowed ) {
					$sep = ( strpos( $return_url, '?' ) !== false ) ? '&' : '?';
					$dynamic_resolved['success_url'] = $dynamic_resolved['success_url'] ?? ( $return_url . $sep . 'session_id={CHECKOUT_SESSION_ID}' . ( ! empty( $target_slug ) ? '&purchased=' . $target_slug : '' ) );
					$dynamic_resolved['cancel_url']  = $dynamic_resolved['cancel_url'] ?? ( $return_url . $sep . 'status=cancelled' );
				} else {
					$dynamic_resolved['success_url'] = $dynamic_resolved['success_url'] ?? home_url( '/callback/stripe?status=success&session_id={CHECKOUT_SESSION_ID}&purchased=' . $target_slug );
					$dynamic_resolved['cancel_url']  = $dynamic_resolved['cancel_url'] ?? home_url( '/callback/stripe?status=cancel' );
				}
			}
			return self::create_stripe_session( $dynamic_resolved, $method );
		}

		// 2. Fall back to static / filtered catalog
		$catalog = apply_filters( 'xophz_checkout_catalog', self::get_default_catalog() );

		// Check multiple path permutations: 'chemical-x/master' or 'bronze'
		$full_path = implode( '/', $segments );
		$first_key = $segments[0] ?? '';

		$target_item = null;
		$resolved_key = '';

		if ( isset( $catalog[ $full_path ] ) ) {
			$target_item = $catalog[ $full_path ];
			$resolved_key = $full_path;
		} elseif ( isset( $catalog[ $first_key ] ) ) {
			$target_item = $catalog[ $first_key ];
			$resolved_key = $first_key;
		}

		if ( ! $target_item ) {
			return new WP_Error( 'not_found', 'Requested checkout target could not be found.', array( 'status' => 404 ) );
		}

		// Handle billing modifier for Card Vault (e.g. ?billing=lifetime or ?billing=annual)
		$billing_cycle = sanitize_key( (string) ( $query['billing'] ?? $query['cycle'] ?? '' ) );
		if ( strpos( $resolved_key, 'card-vault' ) !== false && ! empty( $billing_cycle ) ) {
			$sub_tier = ( strpos( $resolved_key, 'team' ) !== false ) ? 'team' : 'single';
			$alt_key  = "card-vault/{$sub_tier}-{$billing_cycle}";
			if ( isset( $catalog[ $alt_key ] ) ) {
				$target_item  = $catalog[ $alt_key ];
				$resolved_key = $alt_key;
			}
		}

		// Resolve plan mode (DIY vs White Glove for Tesseract, or single price)
		$plan_mode = sanitize_key( (string) (
			$query['plan'] ??
			$query['mode'] ??
			$query['tier_type'] ??
			'whiteglove'
		) );
		$is_diy = in_array( $plan_mode, array( 'diy', 'selfhost', 'self-host', 'bare' ), true );

		$price = 0.0;
		$name  = $target_item['name'] ?? 'Compass Product';
		$mode  = $target_item['mode'] ?? 'payment';

		if ( isset( $target_item['price'] ) ) {
			$price = (float) $target_item['price'];
		} elseif ( $is_diy && isset( $target_item['diy_price'] ) ) {
			$price = (float) $target_item['diy_price'];
			$name  = 'Tesseract ' . $target_item['name'] . 'BOX - DIY Bare Hosting';
		} elseif ( isset( $target_item['white_glove_price'] ) ) {
			$price = (float) $target_item['white_glove_price'];
			$hours = ! empty( $target_item['hours'] ) ? ' (' . $target_item['hours'] . 'h Support)' : '';
			$name  = 'Tesseract ' . $target_item['name'] . 'BOX - White Glove Concierge' . $hours;
		}

		$device_id  = sanitize_text_field( (string) ( $query['device_id'] ?? $query['deviceId'] ?? '' ) );
		$tier_id    = sanitize_key( (string) ( $target_item['tier'] ?? $resolved_key ) );
		$interval   = $target_item['interval'] ?? ( $query['interval'] ?? ( $mode === 'subscription' ? 'year' : '' ) );
		$trial_days = isset( $target_item['trial_days'] ) ? intval( $target_item['trial_days'] ) : ( isset( $query['trial_days'] ) ? intval( $query['trial_days'] ) : 0 );

		// Determine Success & Cancel URLs
		$return_url = ! empty( $query['return_url'] ) ? esc_url_raw( $query['return_url'] ) : '';
		$is_allowed_return = ! empty( $return_url ) && ( class_exists( 'Xophz_Compass_Security' ) ? Xophz_Compass_Security::is_allowed_redirect_url( $return_url ) : true );

		$return_origin = ! empty( $query['return_origin'] )
			? rtrim( esc_url_raw( $query['return_origin'] ), '/' )
			: ( strpos( $resolved_key, 'chemical-x' ) !== false
				? 'https://awesome-secret-sauce.pages.dev'
				: ( strpos( $resolved_key, 'card-vault' ) !== false
					? home_url( '/card-vault' )
					: home_url() ) );

		if ( $is_allowed_return ) {
			$sep = ( strpos( $return_url, '?' ) !== false ) ? '&' : '?';
			$success_url = $return_url . $sep . "session_id={CHECKOUT_SESSION_ID}&status=success&tier={$tier_id}&purchased={$tier_id}";
			$cancel_url  = $return_url . $sep . 'status=cancelled';
		} else {
			$success_url = ! empty( $query['success_url'] )
				? esc_url_raw( $query['success_url'] )
				: ( strpos( $resolved_key, 'chemical-x' ) !== false
					? "{$return_origin}/?session_id={CHECKOUT_SESSION_ID}&status=success&tier={$tier_id}&device_id={$device_id}"
					: home_url( "/callback/stripe?status=success&tier={$tier_id}&session_id={CHECKOUT_SESSION_ID}" )
				);

			$cancel_url = ! empty( $query['cancel_url'] )
				? esc_url_raw( $query['cancel_url'] )
				: ( strpos( $resolved_key, 'chemical-x' ) !== false
					? "{$return_origin}/?status=cancelled"
					: home_url( "/callback/stripe?status=cancel&tier={$tier_id}" )
				);
		}

		$payload = array(
			'price'        => $price,
			'product_name' => $name,
			'license'      => $name,
			'mode'         => $mode,
			'interval'     => $interval,
			'trial_days'   => $trial_days,
			'is_diy'       => $is_diy,
			'success_url'  => $success_url,
			'cancel_url'   => $cancel_url,
			'force_test'   => $force_test_mode,
			'metadata'     => array(
				'route'      => $full_path,
				'tier'       => $tier_id,
				'trial_days' => $trial_days,
				'interval'   => $interval,
				'device_id'  => $device_id,
				'source'     => 'bazaar_buy_router',
				'env'        => $force_test_mode ? 'sandbox' : 'production',
			),
		);

		return self::create_stripe_session( $payload, $method );
	}

	/**
	 * Create the Stripe Checkout session via Stripe API.
	 *
	 * @param array  $data   Payload parameters.
	 * @param string $method Request HTTP method ('GET' or 'POST').
	 * @return array|WP_Error
	 */
	public static function create_stripe_session( array $data, string $method = 'GET' ) {
		$price        = (float) ( $data['price'] ?? 0 );
		$product_name = sanitize_text_field( (string) ( $data['product_name'] ?? $data['license'] ?? 'Compass Product' ) );
		$mode         = ( $data['mode'] ?? 'payment' ) === 'subscription' ? 'subscription' : 'payment';
		$success_url  = esc_url_raw( (string) ( $data['success_url'] ?? home_url( '/callback/stripe?status=success' ) ) );
		$cancel_url   = esc_url_raw( (string) ( $data['cancel_url'] ?? home_url( '/callback/stripe?status=cancel' ) ) );
		$is_diy       = ! empty( $data['is_diy'] );
		$force_test   = ! empty( $data['force_test'] );
		$metadata     = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();

		if ( $force_test ) {
			$metadata['env']       = 'sandbox';
			$metadata['test_mode'] = 'true';
		}

		if ( $price <= 0 ) {
			return new WP_Error( 'invalid_price', 'Checkout price must be greater than zero.', array( 'status' => 400 ) );
		}

		$stripe_key = self::get_stripe_secret_key( $force_test );

		// Fallback Simulator when Stripe key is unconfigured or mock
		if ( empty( $stripe_key ) || strpos( $stripe_key, 'sk_test_Mock' ) === 0 ) {
			$mock_url = add_query_arg( array(
				'mock_checkout' => '1',
				'price'         => $price,
				'product_name'  => urlencode( $product_name ),
				'mode'          => $mode,
				'interval'      => $data['interval'] ?? '',
				'trial_days'    => $data['trial_days'] ?? '',
				'success_url'   => urlencode( $success_url ),
				'cancel_url'    => urlencode( $cancel_url ),
			), home_url( '/callback/stripe' ) );

			self::log_adhoc_transaction( 'mock_' . uniqid(), $price, $product_name, $metadata, 'mock_initiated' );

			if ( $method === 'GET' ) {
				wp_redirect( $mock_url );
				exit;
			}

			return array(
				'url'          => $mock_url,
				'checkout_url' => $mock_url,
				'session_id'   => 'mock_sim_' . uniqid(),
			);
		}

		$unit_amount = (int) round( $price * 100 );

		$body = array(
			'line_items[0][price_data][currency]'               => 'usd',
			'line_items[0][price_data][product_data][name]'     => $product_name,
			'line_items[0][price_data][product_data][tax_code]' => 'txcd_10103000',
			'line_items[0][price_data][unit_amount]'            => $unit_amount,
			'line_items[0][quantity]'                           => 1,
			'mode'                                              => $mode,
			'success_url'                                       => $success_url,
			'cancel_url'                                        => $cancel_url,
			'allow_promotion_codes'                             => 'true',
		);

		if ( $mode === 'subscription' ) {
			$interval = ! empty( $data['interval'] ) ? sanitize_key( $data['interval'] ) : 'month';
			$body['line_items[0][price_data][recurring][interval]'] = $interval;

			$trial_days = isset( $data['trial_days'] ) ? intval( $data['trial_days'] ) : 0;
			if ( $trial_days > 0 ) {
				$body['subscription_data[trial_period_days]'] = $trial_days;
			}

			// Legacy Tesseract White Glove setup fee ($749)
			if ( ! $is_diy && ( strpos( strtolower( $product_name ), 'tesseract' ) !== false || strpos( strtolower( $product_name ), 'white glove' ) !== false ) ) {
				$body['line_items[1][price_data][currency]']               = 'usd';
				$body['line_items[1][price_data][product_data][name]']     = 'White Glove Setup & Onboarding Fee';
				$body['line_items[1][price_data][product_data][tax_code]'] = 'txcd_10103000';
				$body['line_items[1][price_data][unit_amount]']            = 74900;
				$body['line_items[1][quantity]']                            = 1;
			}
		}

		// Inject tracking metadata
		foreach ( $metadata as $m_key => $m_val ) {
			$body[ "metadata[{$m_key}]" ] = sanitize_text_field( (string) $m_val );
		}

		$response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $stripe_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query( $body ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_connection_error', 'Connection to payment gateway failed: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$data_res = json_decode( $raw_body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $data_res['url'] ) ) {
			$err_msg = $data_res['error']['message'] ?? 'Payment gateway returned an invalid session response.';
			return new WP_Error( 'stripe_api_error', $err_msg, array( 'status' => 500 ) );
		}

		// Log into lightweight Bazaar ad-hoc ledger
		self::log_adhoc_transaction( $data_res['id'] ?? '', $price, $product_name, $metadata, 'initiated' );

		if ( $method === 'GET' ) {
			wp_redirect( $data_res['url'] );
			exit;
		}

		return array(
			'url'          => $data_res['url'],
			'checkout_url' => $data_res['url'],
			'session_id'   => $data_res['id'] ?? '',
		);
	}

	/**
	 * Log ad-hoc transaction into Bazaar's internal ledger option array.
	 *
	 * @param string $session_id
	 * @param float  $amount
	 * @param string $product_name
	 * @param array  $metadata
	 * @param string $status
	 */
	public static function log_adhoc_transaction( string $session_id, float $amount, string $product_name, array $metadata, string $status = 'initiated' ) {
		$records = get_option( '_xophz_bazaar_adhoc_ledger', array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}

		// Keep recent 500 records
		if ( count( $records ) > 500 ) {
			$records = array_slice( $records, -450, null, true );
		}

		$records[ $session_id ] = array(
			'session_id'   => $session_id,
			'amount'       => $amount,
			'product_name' => $product_name,
			'metadata'     => $metadata,
			'status'       => $status,
			'created_at'   => current_time( 'mysql', true ),
		);

		update_option( '_xophz_bazaar_adhoc_ledger', $records, false );
	}
}
