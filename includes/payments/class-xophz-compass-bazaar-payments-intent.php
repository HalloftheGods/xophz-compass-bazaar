<?php
/**
 * What the sale needs from one tender: which checkout and order it belongs to,
 * the amount of this leg, and who is selling. Plain data.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Payments_Intent {

  /** @var int */
  public $checkout_id;
  /** @var int 0 before the order exists. */
  public $order_id;
  /** @var int Position of this tender in the sale, from 1. */
  public $seq;
  /** @var int */
  public $amount_minor;
  /** @var string */
  public $currency;
  /** @var int The logged-in WordPress user. Digital tokens are issued to this user. */
  public $operator_user_id;
  /** @var int The person who sold: the operator, or a PIN-verified staff member. */
  public $cashier_user_id;
  /** @var int 0 when there is no staff session. */
  public $staff_session_id;
  /** @var string Display title of the payment method, used in refusal texts. */
  public $method_title;

  /** Missing keys become 0 or ''. No validation: the checkout builds this from server state. */
  public function __construct( array $in = [] ) {
    $this->checkout_id      = (int) ( $in['checkout_id'] ?? 0 );
    $this->order_id         = (int) ( $in['order_id'] ?? 0 );
    $this->seq              = (int) ( $in['seq'] ?? 1 );
    $this->amount_minor     = (int) ( $in['amount_minor'] ?? 0 );
    $this->currency         = (string) ( $in['currency'] ?? '' );
    $this->operator_user_id = (int) ( $in['operator_user_id'] ?? 0 );
    $this->cashier_user_id  = (int) ( $in['cashier_user_id'] ?? 0 );
    $this->staff_session_id = (int) ( $in['staff_session_id'] ?? 0 );
    $this->method_title     = (string) ( $in['method_title'] ?? '' );
  }
}
