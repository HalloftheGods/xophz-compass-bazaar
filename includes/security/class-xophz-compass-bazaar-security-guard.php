<?php
/**
 * The Bazaar request guard: admin-ajax wrapper and REST permission factory.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 6.4 and 6.5.
 *
 * admin-ajax (enforce_ajax), in order:
 *   1. An action missing from Security_Policy (and from the module policies
 *      in MODULE_POLICIES, see caps_for) is logged
 *      (bazaar_guard_unmapped:<action>) and refused 403 {code:'forbidden'}.
 *   2. Nonce shim: no X-WP-Nonce and no _wpnonce, but a `security` field,
 *      copies that field to $_REQUEST['_wpnonce'] (the phone app).
 *   3. Xophz_Compass_Security::enforce_spa_ajax(...$caps) answers the 403
 *      bodies. With XOPHZ_BAZAAR_GUARD_REPORT_ONLY true, caps are still
 *      enforced (same body) and nonce failures are only logged.
 *   4. Dry run (local or development only, filter
 *      xophz_compass_bazaar_guard_dry_run): answers
 *      {success:true, guard:'allowed', action} and skips the handler.
 *   5. Stores and returns the operator-only Security_Context.
 *
 * REST (rest_permission): 401 rest_not_logged_in only when nobody is logged
 * in; every other refusal is 403 (staff_session_invalid or forbidden).
 *
 * Not guaranteed: the guard checks who may call an action. Input validation,
 * limits and approvals stay with the handlers and services.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Security_Guard {

  const AJAX_PREFIX      = 'wp_ajax_';
  const STAFF_HEADER     = 'x-bazaar-staff-session';
  const DEVICE_HEADER    = 'x-bazaar-device';
  const DRY_RUN_FILTER   = 'xophz_compass_bazaar_guard_dry_run';
  const REPORT_ONLY_FLAG = 'XOPHZ_BAZAAR_GUARD_REPORT_ONLY';
  const DRY_RUN_ENVS     = [ 'local', 'development' ];
  const SESSION_CLASS    = 'Xophz_Compass_Bazaar_Staff_Sessions';

  /**
   * Admin modules that declare the caps of their own admin-ajax actions with
   * a public static policy(): array (action => caps). Consulted only for an
   * action Security_Policy does not map, so Security_Policy always wins. A
   * fixed list of classes, never a filter: no other code can open an action.
   */
  const MODULE_POLICIES = [ 'Xophz_Compass_Bazaar_Admin_Quick_Add', 'Xophz_Compass_Bazaar_Admin_Quick_Pile' ];

  /** @var Xophz_Compass_Bazaar_Security_Context|null */
  private static $context = null;

  /** @var bool True when $context came from rest_permission(). */
  private static $rest = false;

  /**
   * Replace the callback of every queued hook whose name starts with
   * wp_ajax_ by a Security_Guarded_Callback. Priority, accepted_args and every
   * other hook are returned unchanged. An already guarded callback is kept.
   *
   * @param array $registered Entries {hook, callback, priority, accepted_args}.
   * @return array
   */
  public static function wrap_ajax_actions( array $registered ): array {
    foreach ( $registered as $i => $entry ) {
      if ( ! is_array( $entry ) || ! isset( $entry['hook'] ) || ! is_string( $entry['hook'] ) ) {
        continue;
      }
      if ( 0 !== strpos( $entry['hook'], self::AJAX_PREFIX ) ) {
        continue;
      }
      $callback = $entry['callback'] ?? null;
      if ( $callback instanceof Xophz_Compass_Bazaar_Security_Guarded_Callback ) {
        continue;
      }
      $registered[ $i ]['callback'] = new Xophz_Compass_Bazaar_Security_Guarded_Callback(
        substr( $entry['hook'], strlen( self::AJAX_PREFIX ) ),
        $callback
      );
    }
    return $registered;
  }

  /**
   * Pure decision for one admin-ajax call.
   *
   * @param string[]|null $caps Policy caps, null when the action is unmapped.
   * @param array         $env  held: caps the user holds (string[]),
   *                            nonce: 'valid'|'missing'|'bad',
   *                            report_only: bool. Missing keys fail closed.
   * @return string 'allow', 'forbidden', 'bad_nonce' or 'unmapped'.
   */
  public static function decide( ?array $caps, array $env ): string {
    if ( null === $caps ) {
      return 'unmapped';
    }
    $held = isset( $env['held'] ) && is_array( $env['held'] ) ? $env['held'] : [];
    if ( ! array_intersect( $caps, $held ) ) {
      return 'forbidden';
    }
    $nonce = $env['nonce'] ?? 'missing';
    if ( 'valid' !== $nonce && true !== ( $env['report_only'] ?? false ) ) {
      return 'bad_nonce';
    }
    return 'allow';
  }

  /**
   * The caps for one admin-ajax action: Security_Policy's entry, else the
   * entry of a module in MODULE_POLICIES. Null when nobody maps it.
   *
   * @return string[]|null
   */
  public static function caps_for( string $action ): ?array {
    $caps = Xophz_Compass_Bazaar_Security_Policy::caps_for( $action );
    if ( null !== $caps ) {
      return $caps;
    }
    foreach ( self::MODULE_POLICIES as $class ) {
      if ( ! class_exists( $class ) || ! method_exists( $class, 'policy' ) ) {
        continue;
      }
      $map = $class::policy();
      if ( isset( $map[ $action ] ) && is_array( $map[ $action ] ) && [] !== $map[ $action ] ) {
        return array_values( $map[ $action ] );
      }
    }
    return null;
  }

  /**
   * Guard one admin-ajax action. Returns the stored context when the handler
   * may run, or null after the guard answered (refusal or dry run).
   */
  public static function enforce_ajax( string $action ): ?Xophz_Compass_Bazaar_Security_Context {
    self::reset();
    $caps = self::caps_for( $action );
    if ( null === $caps ) {
      error_log( 'bazaar_guard_unmapped:' . $action );
      self::send_forbidden();
      return null;
    }

    self::apply_nonce_shim();
    $report_only = self::report_only();
    $nonce       = self::nonce_state();
    $decision    = self::decide( $caps, [
      'held'        => array_values( array_filter( $caps, 'current_user_can' ) ),
      'nonce'       => $nonce,
      'report_only' => $report_only,
    ] );

    if ( ! $report_only && class_exists( 'Xophz_Compass_Security' ) ) {
      // Answers 403 forbidden / rest_cookie_invalid_nonce itself and ends the request.
      Xophz_Compass_Security::enforce_spa_ajax( ...$caps );
    }
    if ( 'forbidden' === $decision ) {
      self::send_forbidden();
      return null;
    }
    if ( 'bad_nonce' === $decision ) {
      self::send_bad_nonce();
      return null;
    }
    if ( $report_only && 'valid' !== $nonce ) {
      error_log( 'bazaar_guard_nonce_report_only:' . $action . ':' . $nonce );
    }

    if ( self::dry_run( $action ) ) {
      wp_send_json( [ 'success' => true, 'guard' => 'allowed', 'action' => $action ] );
      return null;
    }

    self::$context = Xophz_Compass_Bazaar_Security_Context::for_operator(
      (int) get_current_user_id(),
      self::clean_device( $_SERVER['HTTP_X_BAZAAR_DEVICE'] ?? '' )
    );
    return self::$context;
  }

  /**
   * True when the user holds at least one cap. Checks the acting user when
   * rest_permission() stored a context in this request, else the current user.
   */
  public static function can_any( string ...$caps ): bool {
    if ( self::$rest && self::$context ) {
      return self::user_holds_any( self::$context->acting_user_id, $caps );
    }
    foreach ( $caps as $cap ) {
      if ( current_user_can( $cap ) ) {
        return true;
      }
    }
    return false;
  }

  /**
   * permission_callback for a bazaar/v1 route. Any one of $caps suffices;
   * with no caps every call is refused.
   */
  public static function rest_permission( string ...$caps ): Closure {
    return static function ( $request = null ) use ( $caps ) {
      self::reset();
      $operator = (int) get_current_user_id();
      if ( $operator <= 0 ) {
        return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'xophz-compass-bazaar' ), [ 'status' => 401 ] );
      }
      $device  = self::clean_device( self::header( $request, self::DEVICE_HEADER ) ?? '' );
      $token   = self::header( $request, self::STAFF_HEADER );
      $context = Xophz_Compass_Bazaar_Security_Context::for_operator( $operator, $device );
      if ( null !== $token ) {
        $context = self::resolve_staff_session( $token, $operator, $device );
        if ( null === $context ) {
          return new WP_Error( 'staff_session_invalid', __( 'The staff session is not valid. Enter the PIN again.', 'xophz-compass-bazaar' ), [ 'status' => 403 ] );
        }
      }
      $allowed = self::user_holds_any( $context->acting_user_id, $caps );
      if ( $allowed && $context->is_staff_session() ) {
        $allowed = user_can( $operator, Xophz_Compass_Bazaar_Security_Capabilities::SELL );
      }
      if ( ! $allowed ) {
        return new WP_Error( 'forbidden', __( 'You do not have permission to do this.', 'xophz-compass-bazaar' ), [ 'status' => 403 ] );
      }
      self::$context = $context;
      self::$rest    = true;
      return true;
    };
  }

  /** The stored context, or the operator-only context of the current user. */
  public static function context(): Xophz_Compass_Bazaar_Security_Context {
    if ( self::$context ) {
      return self::$context;
    }
    return Xophz_Compass_Bazaar_Security_Context::for_operator(
      (int) get_current_user_id(),
      self::clean_device( $_SERVER['HTTP_X_BAZAAR_DEVICE'] ?? '' )
    );
  }

  /** Forget the stored context (start of every guarded request). */
  public static function reset(): void {
    self::$context = null;
    self::$rest    = false;
  }

  /**
   * Turn a Staff_Sessions::resolve() result into a context. Accepts an array
   * or object with a positive staff_user_id and a positive id (or
   * staff_session_id). When it also names operator_user_id or device_id, they
   * must match. Anything else (null, false, WP_Error, …) gives null.
   *
   * @param mixed $resolved
   */
  public static function context_from_session( $resolved, int $operator, string $device ): ?Xophz_Compass_Bazaar_Security_Context {
    if ( is_object( $resolved ) && ! ( $resolved instanceof WP_Error ) ) {
      $resolved = get_object_vars( $resolved );
    }
    if ( ! is_array( $resolved ) ) {
      return null;
    }
    $staff   = (int) ( $resolved['staff_user_id'] ?? 0 );
    $session = (int) ( $resolved['id'] ?? ( $resolved['staff_session_id'] ?? 0 ) );
    if ( $staff <= 0 || $session <= 0 || $operator <= 0 ) {
      return null;
    }
    if ( isset( $resolved['operator_user_id'] ) && (int) $resolved['operator_user_id'] !== $operator ) {
      return null;
    }
    if ( isset( $resolved['device_id'] ) && (string) $resolved['device_id'] !== $device ) {
      return null;
    }
    return Xophz_Compass_Bazaar_Security_Context::for_staff( $operator, $staff, $session, $device );
  }

  /** Keep [A-Za-z0-9_-], at most 64 characters (the device_id column). */
  public static function clean_device( $value ): string {
    if ( ! is_scalar( $value ) ) {
      return '';
    }
    return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ), 0, 64 );
  }

  private static function resolve_staff_session( string $token, int $operator, string $device ): ?Xophz_Compass_Bazaar_Security_Context {
    $class = self::SESSION_CLASS;
    if ( '' === trim( $token ) || ! class_exists( $class ) || ! method_exists( $class, 'resolve' ) ) {
      return null;
    }
    try {
      $resolved = $class::resolve( $token, $operator, $device );
    } catch ( Throwable $e ) {
      error_log( 'bazaar_guard_staff_session_error:' . get_class( $e ) );
      return null;
    }
    return self::context_from_session( $resolved, $operator, $device );
  }

  /** @param mixed $request WP_REST_Request or null. */
  private static function header( $request, string $name ): ?string {
    if ( ! is_object( $request ) || ! method_exists( $request, 'get_header' ) ) {
      return null;
    }
    $value = $request->get_header( $name );
    return null === $value ? null : (string) $value;
  }

  private static function user_holds_any( int $user_id, array $caps ): bool {
    if ( $user_id <= 0 ) {
      return false;
    }
    foreach ( $caps as $cap ) {
      if ( user_can( $user_id, $cap ) ) {
        return true;
      }
    }
    return false;
  }

  private static function apply_nonce_shim(): void {
    if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) || isset( $_REQUEST['_wpnonce'] ) ) {
      return;
    }
    if ( isset( $_REQUEST['security'] ) && is_scalar( $_REQUEST['security'] ) ) {
      $_REQUEST['_wpnonce'] = (string) $_REQUEST['security'];
    }
  }

  /** Reads the nonce the way enforce_spa_ajax() does: X-WP-Nonce, then _wpnonce. */
  private static function nonce_state(): string {
    $raw = $_SERVER['HTTP_X_WP_NONCE'] ?? ( $_REQUEST['_wpnonce'] ?? '' );
    if ( ! is_scalar( $raw ) ) {
      return 'bad';
    }
    $nonce = sanitize_text_field( wp_unslash( (string) $raw ) );
    if ( '' === $nonce ) {
      return 'missing';
    }
    return wp_verify_nonce( $nonce, 'wp_rest' ) ? 'valid' : 'bad';
  }

  private static function report_only(): bool {
    return defined( self::REPORT_ONLY_FLAG ) && true === constant( self::REPORT_ONLY_FLAG );
  }

  private static function dry_run( string $action ): bool {
    if ( ! in_array( wp_get_environment_type(), self::DRY_RUN_ENVS, true ) ) {
      return false;
    }
    return true === apply_filters( self::DRY_RUN_FILTER, false, $action );
  }

  /** Same body as Xophz_Compass_Security::enforce_spa_ajax() for a missing cap. */
  private static function send_forbidden(): void {
    wp_send_json(
      [
        'success' => false,
        'code'    => 'forbidden',
        'message' => esc_html__( 'You do not have permission to do this.', 'xophz-compass' ),
      ],
      403
    );
  }

  /** Same body as Xophz_Compass_Security::enforce_spa_ajax() for a bad nonce. */
  private static function send_bad_nonce(): void {
    wp_send_json(
      [
        'success' => false,
        'code'    => 'rest_cookie_invalid_nonce',
        'message' => esc_html__( 'Security check failed. Please refresh the page.', 'xophz-compass' ),
      ],
      403
    );
  }
}
