<?php
/**
 * POS customer search and receipt delivery handlers.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 *
 * Guaranteed: the search returns customers only (role customer, or the
 * paying_customer meta) and never a user who holds bazaar_sell,
 * edit_shop_orders or manage_woocommerce. Email and phone are masked unless
 * the caller holds bazaar_view_customers or typed the full email or phone.
 * A receipt never overwrites a billing email that is already set.
 *
 * Not guaranteed: the search returns at most 20 customers, and a customer
 * whose email or phone cannot be matched by the search text stays masked.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Customers {

  const MAX_RESULTS = 20;

  /** Rows fetched per lookup before staff are removed. */
  const FETCH = 60;

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_get_pos_customers' => 'getPosCustomers',
    'wp_ajax_send_pos_receipt'  => 'sendPosReceipt',
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

  /** a***@e***.com : first letter of the name and of the domain, then the TLD. */
  public static function mask_email( string $email ): string {
    if ( false === strpos( $email, '@' ) ) {
      return '';
    }
    list( $name, $domain ) = explode( '@', $email, 2 );
    $dot = strrpos( $domain, '.' );
    $tld = false === $dot ? '' : substr( $domain, $dot );
    return substr( $name, 0, 1 ) . '***@' . substr( $domain, 0, 1 ) . '***' . $tld;
  }

  /** ***-1234 : only the last four digits. */
  public static function mask_phone( string $phone ): string {
    $digits = preg_replace( '/\D/', '', $phone );
    return '' === $digits ? '' : '***-' . substr( $digits, -4 );
  }

  /** Digits only, or '' when fewer than 7 (too short to be a phone number). */
  private static function phone_digits( string $value ): string {
    $digits = preg_replace( '/\D/', '', $value );
    return strlen( $digits ) >= 7 ? $digits : '';
  }

  /** Users matching one lookup, any role; staff are removed by the caller. */
  private function lookup( array $extra ): array {
    $query = new WP_User_Query( $extra + [
      'number'  => self::FETCH,
      'orderby' => 'display_name',
      'order'   => 'ASC',
    ] );
    return $query->get_results();
  }

  private static function is_staff( $user ): bool {
    foreach ( [ 'bazaar_sell', 'edit_shop_orders', 'manage_woocommerce' ] as $cap ) {
      if ( user_can( $user, $cap ) ) {
        return true;
      }
    }
    return false;
  }

  public function getPosCustomers() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }

      $args   = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $search = isset( $args->search ) ? trim( sanitize_text_field( $args->search ) ) : '';

      $text = [];
      if ( $search ) {
        $text = [
          'search'         => '*' . esc_attr( $search ) . '*',
          'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
        ];
      }

      $found = [];
      $sets  = [
        $this->lookup( $text + [ 'role' => 'customer' ] ),
        $this->lookup( $text + [ 'meta_key' => 'paying_customer', 'meta_value' => '1' ] ), // phpcs:ignore
      ];
      $phone = self::phone_digits( $search );
      if ( '' !== $phone ) {
        $sets[] = $this->lookup( [ 'meta_key' => 'billing_phone', 'meta_value' => $search ] ); // phpcs:ignore
      }
      foreach ( $sets as $users ) {
        foreach ( $users as $user ) {
          if ( ! isset( $found[ $user->ID ] ) && ! self::is_staff( $user ) ) {
            $found[ $user->ID ] = $user;
          }
        }
      }
      usort( $found, function ( $a, $b ) {
        return strcasecmp( $a->display_name, $b->display_name );
      } );

      $unmasked = Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_view_customers' );
      $data     = [];

      foreach ( array_slice( $found, 0, self::MAX_RESULTS ) as $user ) {
        $number = '';
        if ( class_exists( 'WC_Customer' ) ) {
          try {
            $customer = new WC_Customer( $user->ID );
            $number   = (string) $customer->get_billing_phone();
          } catch ( \Throwable $e ) {
            // Fall back to user meta.
          }
        }
        if ( ! $number ) {
          $number = (string) get_user_meta( $user->ID, 'billing_phone', true );
        }

        $email         = (string) $user->user_email;
        $email_typed   = '' !== $search && 0 === strcasecmp( $search, $email );
        $phone_typed   = '' !== $phone && '' !== $number && self::phone_digits( $number ) === $phone;
        $data[] = [
          'id'    => $user->ID,
          'name'  => $user->display_name,
          'email' => ( $unmasked || $email_typed ) ? $email : self::mask_email( $email ),
          'phone' => ( $unmasked || $phone_typed ) ? $number : self::mask_phone( $number ),
        ];
      }

      $this->send_json_response( [ 'success' => true, 'customers' => $data ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'customers' => [] ] );
    }
  }

  /** Add the email to the Bomb Bag default list. Does nothing when Bomb Bag is not installed. */
  private function subscribe_to_newsletter( string $email, $order ): void {
    global $wpdb;
    $table    = $wpdb->prefix . 'bomb_bag_subscribers';
    $junction = $wpdb->prefix . 'bomb_bag_list_subscribers';

    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email = %s", $email ) );

    if ( ! $existing_id ) {
      $wpdb->insert( $table, [
        'email'      => $email,
        'first_name' => $order->get_billing_first_name() ?: '',
        'last_name'  => $order->get_billing_last_name() ?: '',
        'source'     => 'pos_checkout',
        'status'     => 'active',
      ] );
      $existing_id = $wpdb->insert_id;
    }

    if ( $existing_id ) {
      $lists_table     = $wpdb->prefix . 'bomb_bag_lists';
      $default_list_id = $wpdb->get_var( "SELECT id FROM $lists_table ORDER BY id ASC LIMIT 1" );

      if ( $default_list_id ) {
        $wpdb->query( $wpdb->prepare(
          "INSERT IGNORE INTO $junction (list_id, subscriber_id) VALUES (%d, %d)",
          $default_list_id,
          $existing_id
        ) );
      }
    }
  }

  public function sendPosReceipt() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args      = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $recipient = isset( $args->recipient ) ? sanitize_text_field( $args->recipient ) : '';
      $order_id  = isset( $args->order_id ) ? intval( $args->order_id ) : 0;

      if ( ! $recipient || ! $order_id ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Recipient and Order ID are required.' ] );
        return;
      }

      $order = wc_get_order( $order_id );
      if ( ! $order || is_wp_error( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Order not found.' ] );
        return;
      }

      if ( strpos( $recipient, '@' ) === false ) {
        $sanitized_phone = preg_replace( '/[^0-9+]/', '', $recipient );

        do_action( 'xophz_compass_send_sms_receipt', $sanitized_phone, $order_id );

        $this->send_json_response( [
          'success' => true,
          'type'    => 'sms',
          'message' => 'Receipt SMS request dispatched.',
        ] );
        return;
      }

      $recipient = sanitize_email( $recipient );
      if ( ! is_email( $recipient ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Enter a valid email address.' ] );
        return;
      }

      // Only fill an empty billing email. A different one stays as it is.
      if ( '' === (string) $order->get_billing_email() ) {
        $order->set_billing_email( $recipient );
        $save_res = $order->save();
        if ( is_wp_error( $save_res ) ) {
          throw new \Exception( $save_res->get_error_message() );
        }
      }

      if ( ! empty( $args->subscribe_newsletter ) && 'false' !== $args->subscribe_newsletter ) {
        $this->subscribe_to_newsletter( $recipient, $order );
      }

      if ( function_exists( 'WC' ) ) {
        $mailer        = WC()->mailer();
        $invoice_email = isset( $mailer->emails['WC_Email_Customer_Invoice'] ) ? $mailer->emails['WC_Email_Customer_Invoice'] : null;
        if ( $invoice_email ) {
          // Address this one send only; the order keeps its own billing email.
          $to = function () use ( $recipient ) {
            return $recipient;
          };
          add_filter( 'woocommerce_email_recipient_customer_invoice', $to, 99 );
          try {
            $invoice_email->trigger( $order_id );
          } finally {
            remove_filter( 'woocommerce_email_recipient_customer_invoice', $to, 99 );
          }
        }
      }

      $this->send_json_response( [
        'success' => true,
        'type'    => 'email',
        'message' => 'Receipt invoice email sent successfully.',
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
