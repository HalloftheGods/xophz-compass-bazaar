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
}
