<?php
/**
 * One validated POST /bazaar/v1/orders/{id}/refunds body. Built from untrusted input.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 12.
 *
 * Fields:
 * - client_key: a UUID v4, optionally prefixed "legacy:". Lowercased. The
 *   Idempotency-Key header is an alias: used when the body has no client_key,
 *   and a body key that differs from it is refused.
 * - order_id: taken from the route, never from the body.
 * - lines: 1 to 100 of {order_item_id, qty}, both whole numbers above 0. One
 *   order item may appear once. Stored sorted by order_item_id.
 * - reason: text, at most 191 characters (longer is cut). Default ''.
 * - restock: boolean, default true.
 * - tenders: optional, 0 to 10 of {payment_uuid | method_id, amount_minor,
 *   reference?}. Each names the capture it is paid back to, by payment_uuid
 *   or by method_id (newest capture of that method first), never both.
 * - approval_token: optional text, at most 200 characters.
 * - dry_run: boolean, default false.
 *
 * Guaranteed: every schema problem found is reported at once as 400
 * invalid_request with data.errors [{path, message}]. Unknown keys are
 * ignored. Not checked here: that the order or its items exist, quantities,
 * limits, approvals and payments (the service does that).
 *
 * request_hash() is Core_Canonical::hash of the normalized body without
 * approval_token and dry_run, so a retry that only adds an approval keeps its
 * hash and a dry run never conflicts with the real call.
 *
 * subject_hash() is the approval subject of a refund: sha256 of the order id,
 * the lines sorted by order item and the refund amount in minor units.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Refunds_Request {

  const MAX_LINES     = 100;
  const MAX_TENDERS   = 10;
  const MAX_TOKEN     = 200;
  const MAX_REASON    = 191;
  const MAX_REFERENCE = 64;
  const KEY_PATTERN   = '/^(?:legacy:)?[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
  const UUID_PATTERN  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
  const HASH_EXCLUDED = [ 'approval_token', 'dry_run' ];

  /** @var string */
  public $client_key = '';
  /** @var int */
  public $order_id = 0;
  /** @var array[] {order_item_id:int, qty:int}, sorted by order_item_id. */
  public $lines = [];
  /** @var string */
  public $reason = '';
  /** @var bool */
  public $restock = true;
  /** @var array[] {payment_uuid:string, method_id:string, amount_minor:int, reference:string} */
  public $tenders = [];
  /** @var string */
  public $approval_token = '';
  /** @var bool */
  public $dry_run = false;

  /** @var array[] {path, message} */
  private $errors = [];

  private function __construct() {}

  /**
   * @param array  $data       The decoded body.
   * @param int    $order_id   The order id from the route.
   * @param string $header_key The Idempotency-Key header value, '' when absent.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 400 invalid_request.
   */
  public static function from_array( array $data, int $order_id, string $header_key = '' ): self {
    $r = new self();
    if ( $order_id <= 0 ) {
      $r->error( 'order_id', 'Must be a whole number above 0.' );
    }
    $r->order_id = max( 0, $order_id );
    $r->parse_key( $data, strtolower( trim( $header_key ) ) );
    $r->parse_lines( $data['lines'] ?? null );
    $r->parse_reason( $data['reason'] ?? '' );
    $r->restock = $r->parse_bool( $data, 'restock', true );
    $r->dry_run = $r->parse_bool( $data, 'dry_run', false );
    $r->parse_tenders( $data['tenders'] ?? null );
    $r->parse_token( $data['approval_token'] ?? '' );
    if ( $r->errors ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'The refund request is not valid.', 400, [ 'errors' => $r->errors ] );
    }
    return $r;
  }

  /** The normalized body, including the fields the hash leaves out. */
  public function to_array(): array {
    return [
      'client_key'     => $this->client_key,
      'order_id'       => $this->order_id,
      'lines'          => $this->lines,
      'reason'         => $this->reason,
      'restock'        => $this->restock,
      'tenders'        => $this->tenders,
      'approval_token' => $this->approval_token,
      'dry_run'        => $this->dry_run,
    ];
  }

  /** sha256 hex over to_array() minus HASH_EXCLUDED. */
  public function request_hash(): string {
    return Xophz_Compass_Bazaar_Core_Canonical::hash( $this->to_array(), self::HASH_EXCLUDED );
  }

  /** The approval subject of this request's lines for a refund of $amount_minor. */
  public function subject_hash( int $amount_minor ): string {
    return self::subject_for( $this->order_id, $this->lines, $amount_minor );
  }

  /**
   * sha256 hex over {order_id, lines sorted by order_item_id, amount_minor}.
   * Lines may carry extra keys; only order_item_id and qty count.
   */
  public static function subject_for( int $order_id, array $lines, int $amount_minor ): string {
    $clean = [];
    foreach ( $lines as $line ) {
      $clean[] = [ 'order_item_id' => (int) ( $line['order_item_id'] ?? 0 ), 'qty' => (int) ( $line['qty'] ?? 0 ) ];
    }
    usort( $clean, function ( $a, $b ) {
      return $a['order_item_id'] <=> $b['order_item_id'];
    } );
    return Xophz_Compass_Bazaar_Core_Canonical::hash( [ 'order_id' => $order_id, 'lines' => $clean, 'amount_minor' => $amount_minor ] );
  }

  private function parse_key( array $data, string $header ): void {
    $raw = $data['client_key'] ?? '';
    if ( ! is_string( $raw ) ) {
      $this->error( 'client_key', 'Must be text.' );
      return;
    }
    $key = strtolower( trim( $raw ) );
    if ( '' !== $header && '' !== $key && $header !== $key ) {
      $this->error( 'client_key', 'Differs from the Idempotency-Key header.' );
      return;
    }
    $key = '' !== $key ? $key : $header;
    if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
      $this->error( 'client_key', 'Must be a UUID v4, optionally prefixed "legacy:".' );
      return;
    }
    $this->client_key = $key;
  }

  private function parse_lines( $lines ): void {
    if ( ! is_array( $lines ) || ! $lines || count( $lines ) > self::MAX_LINES || array_keys( $lines ) !== range( 0, count( $lines ) - 1 ) ) {
      $this->error( 'lines', 'Must be a list of 1 to ' . self::MAX_LINES . ' lines.' );
      return;
    }
    $seen = [];
    foreach ( $lines as $i => $line ) {
      $id  = is_array( $line ) ? self::whole( $line['order_item_id'] ?? null ) : null;
      $qty = is_array( $line ) ? self::whole( $line['qty'] ?? null ) : null;
      if ( null === $id || $id <= 0 ) {
        $this->error( "lines.$i.order_item_id", 'Must be a whole number above 0.' );
        continue;
      }
      if ( null === $qty || $qty <= 0 ) {
        $this->error( "lines.$i.qty", 'Must be a whole number above 0.' );
        continue;
      }
      if ( isset( $seen[ $id ] ) ) {
        $this->error( "lines.$i.order_item_id", 'Each order item may appear once.' );
        continue;
      }
      $seen[ $id ]   = true;
      $this->lines[] = [ 'order_item_id' => $id, 'qty' => $qty ];
    }
    usort( $this->lines, function ( $a, $b ) {
      return $a['order_item_id'] <=> $b['order_item_id'];
    } );
  }

  private function parse_reason( $value ): void {
    if ( null === $value ) {
      return;
    }
    if ( ! is_string( $value ) ) {
      $this->error( 'reason', 'Must be text.' );
      return;
    }
    $text         = trim( $value );
    $this->reason = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_REASON ) : substr( $text, 0, self::MAX_REASON );
  }

  private function parse_bool( array $data, string $name, bool $default ): bool {
    if ( ! array_key_exists( $name, $data ) || null === $data[ $name ] ) {
      return $default;
    }
    if ( ! is_bool( $data[ $name ] ) ) {
      $this->error( $name, 'Must be true or false.' );
      return $default;
    }
    return $data[ $name ];
  }

  private function parse_tenders( $tenders ): void {
    if ( null === $tenders ) {
      return;
    }
    if ( ! is_array( $tenders ) || count( $tenders ) > self::MAX_TENDERS || ( $tenders && array_keys( $tenders ) !== range( 0, count( $tenders ) - 1 ) ) ) {
      $this->error( 'tenders', 'Must be a list of 0 to ' . self::MAX_TENDERS . ' tenders.' );
      return;
    }
    foreach ( $tenders as $i => $tender ) {
      if ( ! is_array( $tender ) ) {
        $this->error( "tenders.$i", 'Must be an object.' );
        continue;
      }
      $uuid   = $tender['payment_uuid'] ?? '';
      $method = $tender['method_id'] ?? '';
      $ref    = $tender['reference'] ?? '';
      $amount = self::whole( $tender['amount_minor'] ?? null );
      if ( ! is_string( $uuid ) || ! is_string( $method ) || ! is_string( $ref ) ) {
        $this->error( "tenders.$i", 'payment_uuid, method_id and reference must be text.' );
        continue;
      }
      $uuid   = strtolower( trim( $uuid ) );
      $method = strtolower( trim( $method ) );
      $ref    = trim( $ref );
      $before = count( $this->errors );
      if ( ( '' === $uuid ) === ( '' === $method ) ) {
        $this->error( "tenders.$i", 'Give payment_uuid or method_id, not both and not neither.' );
      } elseif ( '' !== $uuid && ! preg_match( self::UUID_PATTERN, $uuid ) ) {
        $this->error( "tenders.$i.payment_uuid", 'Must be a UUID.' );
      } elseif ( '' !== $method && ( strlen( $method ) > 100 || ! preg_match( '/^[a-z0-9_\-]+$/', $method ) ) ) {
        $this->error( "tenders.$i.method_id", 'May hold only lowercase letters, digits, underscores and hyphens.' );
      }
      if ( null === $amount || $amount <= 0 ) {
        $this->error( "tenders.$i.amount_minor", 'Must be a whole number above 0.' );
      }
      if ( strlen( $ref ) > self::MAX_REFERENCE ) {
        $this->error( "tenders.$i.reference", 'May be at most ' . self::MAX_REFERENCE . ' characters.' );
      }
      if ( count( $this->errors ) === $before ) {
        $this->tenders[] = [ 'payment_uuid' => $uuid, 'method_id' => $method, 'amount_minor' => $amount, 'reference' => $ref ];
      }
    }
  }

  private function parse_token( $value ): void {
    if ( null === $value || '' === $value ) {
      return;
    }
    if ( ! is_string( $value ) || strlen( trim( $value ) ) > self::MAX_TOKEN ) {
      $this->error( 'approval_token', 'Must be text of at most ' . self::MAX_TOKEN . ' characters.' );
      return;
    }
    $this->approval_token = trim( $value );
  }

  /**
   * A whole number from an int or a string of digits, else null.
   *
   * @param mixed $value
   */
  private static function whole( $value ): ?int {
    if ( is_int( $value ) ) {
      return $value;
    }
    if ( is_string( $value ) && preg_match( '/^[0-9]{1,15}$/', $value ) ) {
      return (int) $value;
    }
    return null;
  }

  private function error( string $path, string $message ): void {
    $this->errors[] = [ 'path' => $path, 'message' => $message ];
  }
}
