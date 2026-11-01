<?php
/**
 * An error that maps to one REST error response: {code, message, data:{status, …}}.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Http_Error extends RuntimeException {

  /** @var string */
  private $error_code;

  /** @var int */
  private $status;

  /** @var array */
  private $data;

  public function __construct( string $code, string $message, int $status = 400, array $data = [] ) {
    parent::__construct( $message );
    $this->error_code = $code;
    $this->status     = $status;
    $this->data       = $data;
  }

  public function error_code(): string {
    return $this->error_code;
  }

  public function status(): int {
    return $this->status;
  }

  /** Extra data merged under the response's data key (status is always set). */
  public function data(): array {
    return array_merge( $this->data, [ 'status' => $this->status ] );
  }

  public function to_wp_error(): WP_Error {
    return new WP_Error( $this->error_code, $this->getMessage(), $this->data() );
  }
}
