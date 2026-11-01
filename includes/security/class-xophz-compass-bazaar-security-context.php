<?php
/**
 * Who is acting on a Bazaar request.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 6.5 and 13.5.
 * - operator_user_id: the logged-in WordPress user (the device's login).
 * - acting_user_id:   the cashier. The staff user of a PIN session, else the operator.
 * - staff_session_id: the bazaar_staff_sessions row id, 0 without a session.
 * - device_id:        the X-Bazaar-Device value (not a secret), '' when absent.
 * - via:              'login' or 'pin'.
 *
 * A value object: it checks nothing by itself. Security_Guard builds it only
 * after a request passed its checks.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Security_Context {

  const VIA_LOGIN = 'login';
  const VIA_PIN   = 'pin';

  /** @var int */
  public $operator_user_id;
  /** @var int */
  public $acting_user_id;
  /** @var int */
  public $staff_session_id;
  /** @var string */
  public $device_id;
  /** @var string 'login' or 'pin' */
  public $via;

  public function __construct( int $operator_user_id, int $acting_user_id, int $staff_session_id = 0, string $device_id = '', string $via = self::VIA_LOGIN ) {
    $this->operator_user_id = max( 0, $operator_user_id );
    $this->acting_user_id   = max( 0, $acting_user_id );
    $this->staff_session_id = max( 0, $staff_session_id );
    $this->device_id        = $device_id;
    $this->via              = self::VIA_PIN === $via ? self::VIA_PIN : self::VIA_LOGIN;
  }

  /** The operator acts for themselves (no staff session). */
  public static function for_operator( int $operator_user_id, string $device_id = '' ): self {
    return new self( $operator_user_id, $operator_user_id, 0, $device_id, self::VIA_LOGIN );
  }

  /** A PIN-verified staff user acts on the operator's device. */
  public static function for_staff( int $operator_user_id, int $staff_user_id, int $staff_session_id, string $device_id = '' ): self {
    return new self( $operator_user_id, $staff_user_id, $staff_session_id, $device_id, self::VIA_PIN );
  }

  public function is_staff_session(): bool {
    return self::VIA_PIN === $this->via;
  }

  /** @return array{operator_user_id:int,acting_user_id:int,staff_session_id:int,device_id:string,via:string} */
  public function to_array(): array {
    return [
      'operator_user_id' => $this->operator_user_id,
      'acting_user_id'   => $this->acting_user_id,
      'staff_session_id' => $this->staff_session_id,
      'device_id'        => $this->device_id,
      'via'              => $this->via,
    ];
  }
}
