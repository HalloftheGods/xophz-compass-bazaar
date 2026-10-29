<?php

class Xophz_Compass_Bazaar_Twilio {

    public $action_hooks = [
        'xophz_compass_send_sms_receipt' => 'send_sms_receipt',
    ];

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function send_sms_receipt( $phone_number, $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( 'Bazaar POS: Order not found for SMS receipt: ' . $order_id );
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $total = $order->get_formatted_order_total();
        // Since we are sending a plaintext SMS, strip HTML tags from the formatted total
        $total = html_entity_decode( strip_tags( $total ) );
        
        $message = "Thank you for your purchase at $site_name!\n";
        $message .= "Order #" . $order->get_order_number() . "\n";
        $message .= "Total: " . $total . "\n";
        
        // Add a link to view the receipt if needed
        $message .= "\nView details: " . $order->get_checkout_order_received_url();

        if ( class_exists( 'Xophz_Compass_Twilio_API' ) ) {
            $response = Xophz_Compass_Twilio_API::send_sms( $phone_number, $message );
            if ( is_wp_error( $response ) ) {
                error_log( 'Bazaar POS Twilio Error: ' . $response->get_error_message() );
            }
        } else {
            error_log( 'Bazaar POS Twilio Error: Xophz_Compass_Twilio_API not found.' );
        }
    }

    /**
     * True when the core Twilio API is loaded and all three Twilio
     * Connector keys are set.
     */
    public static function is_configured() {
        if ( ! class_exists( 'Xophz_Compass_Twilio_API' ) || ! class_exists( 'Xophz_Compass_Connector_Key' ) ) {
            return false;
        }
        foreach ( [ 'twilio_account_sid', 'twilio_auth_token', 'twilio_phone_number' ] as $key ) {
            if ( empty( Xophz_Compass_Connector_Key::get( $key ) ) ) {
                return false;
            }
        }
        return true;
    }

    /** Plain-text "ready for pickup" message, used for the text and the customer note. */
    public static function pickup_ready_message( $order ) {
        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $first     = $order->get_billing_first_name();
        $greeting  = $first ? "Hi $first, your" : 'Your';
        return sprintf( '%s %s order #%s is ready for pickup.', $greeting, $site_name, $order->get_order_number() );
    }

    /**
     * Text the customer that their order is ready for pickup.
     *
     * @return true|WP_Error
     */
    public static function send_pickup_ready_sms( $phone_number, $order ) {
        if ( ! self::is_configured() ) {
            return new WP_Error( 'twilio_not_configured', 'Twilio is not configured in Connectors.' );
        }
        $message  = self::pickup_ready_message( $order );
        $message .= "\nOrder details: " . $order->get_view_order_url();
        return Xophz_Compass_Twilio_API::send_sms( $phone_number, $message );
    }
}
