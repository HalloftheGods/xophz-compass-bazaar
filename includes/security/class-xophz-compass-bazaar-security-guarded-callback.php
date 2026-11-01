<?php
/**
 * A wp_ajax_* callback wrapped by Security_Guard.
 *
 * __invoke() runs Security_Guard::enforce_ajax( $action ) and calls the
 * original callback only when the guard returned a context. On a refusal the
 * guard has already answered (and normally ended the request); the original
 * callback is never called.
 *
 * Note: remove_action() with the original callback no longer finds the hook,
 * because WordPress now holds this object.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Security_Guarded_Callback {

  /** @var string Action without the wp_ajax_ prefix. */
  private $action;

  /** @var callable|mixed The original callback, as it was queued. */
  private $callback;

  /**
   * @param string $action   Action name without the wp_ajax_ prefix.
   * @param mixed  $callback The original callback.
   */
  public function __construct( string $action, $callback ) {
    $this->action   = $action;
    $this->callback = $callback;
  }

  public function action(): string {
    return $this->action;
  }

  /** @return mixed The original callback. */
  public function original() {
    return $this->callback;
  }

  /** @return mixed What the original callback returned, or null when refused. */
  public function __invoke( ...$args ) {
    $context = Xophz_Compass_Bazaar_Security_Guard::enforce_ajax( $this->action );
    if ( null === $context ) {
      return null;
    }
    return call_user_func_array( $this->callback, $args );
  }
}
