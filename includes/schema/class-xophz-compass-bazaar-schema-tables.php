<?php
/**
 * SQL for the ten Bazaar tables (spec section 7.1), written for dbDelta:
 * one column per line, two spaces after PRIMARY KEY, KEY (not INDEX), no
 * foreign keys. Every datetime column is UTC.
 *
 * Guarantees: the shape of the SQL text, checked by tests/unit/schema-tables-test.php.
 * Not guaranteed by this class: that MySQL accepts it. tests/integration/schema.sh does that.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Schema_Tables {

  /** Short names (without the prefix and "bazaar_"), in creation order. */
  const NAMES = ['stock_batches', 'stock_movements', 'stock_balances', 'checkouts', 'payments', 'refunds', 'staff', 'staff_sessions', 'approvals', 'events'];

  /**
   * CREATE TABLE statements keyed by short name.
   *
   * @param string $prefix  $wpdb->prefix
   * @param string $collate $wpdb->get_charset_collate()
   * @return array<string,string>
   */
  public static function definitions( string $prefix, string $collate ): array {
    $out = [];
    foreach ( self::templates() as $name => $sql ) {
      $out[ $name ] = str_replace( [ '{p}', '{collate}' ], [ $prefix, $collate ], $sql );
    }
    return $out;
  }

  /** @return array<string,string> Statements with {p} and {collate} placeholders. */
  private static function templates(): array {
    return [
    'stock_batches' => <<<'SQL'
CREATE TABLE {p}bazaar_stock_batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  idem_key varchar(191) NOT NULL,
  request_hash char(64) NOT NULL,
  reason varchar(32) NOT NULL,
  source_type varchar(32) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reverses_batch_id bigint(20) unsigned DEFAULT NULL,
  actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cashier_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  staff_session_id bigint(20) unsigned DEFAULT NULL,
  approval_id bigint(20) unsigned DEFAULT NULL,
  register_id bigint(20) unsigned DEFAULT NULL,
  device_id varchar(64) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  occurred_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idem_key (idem_key),
  KEY source (source_type,source_id),
  KEY reason_created (reason,created_at)
) ENGINE=InnoDB {collate};
SQL,

    'stock_movements' => <<<'SQL'
CREATE TABLE {p}bazaar_stock_movements (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id bigint(20) unsigned NOT NULL,
  line_no smallint(5) unsigned NOT NULL,
  stock_product_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty_delta decimal(19,4) NOT NULL,
  balance_after decimal(19,4) NOT NULL,
  oversold tinyint(1) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  unit_cost_minor bigint(20) DEFAULT NULL,
  cost_currency char(3) DEFAULT NULL,
  cost_source varchar(16) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY batch_line (batch_id,line_no),
  KEY product_location (stock_product_id,location_id,id),
  KEY order_item (order_id,order_item_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {collate};
SQL,

    'stock_balances' => <<<'SQL'
CREATE TABLE {p}bazaar_stock_balances (
  stock_product_id bigint(20) unsigned NOT NULL,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  on_hand decimal(19,4) NOT NULL DEFAULT 0,
  is_enabled tinyint(1) NOT NULL DEFAULT 1,
  last_movement_id bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (stock_product_id,location_id),
  KEY location_id (location_id)
) ENGINE=InnoDB {collate};
SQL,

    'checkouts' => <<<'SQL'
CREATE TABLE {p}bazaar_checkouts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_key varchar(64) NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  request_hash char(64) NOT NULL,
  channel varchar(16) NOT NULL DEFAULT 'pos',
  state varchar(20) NOT NULL,
  retryable tinyint(1) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned DEFAULT NULL,
  operator_user_id bigint(20) unsigned NOT NULL,
  cashier_user_id bigint(20) unsigned NOT NULL,
  staff_session_id bigint(20) unsigned DEFAULT NULL,
  register_id bigint(20) unsigned DEFAULT NULL,
  device_id varchar(64) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  stock_owner varchar(8) NOT NULL DEFAULT 'wc',
  currency char(3) NOT NULL,
  subtotal_minor bigint(20) NOT NULL DEFAULT 0,
  discount_minor bigint(20) NOT NULL DEFAULT 0,
  tax_minor bigint(20) NOT NULL DEFAULT 0,
  tip_minor bigint(20) NOT NULL DEFAULT 0,
  total_minor bigint(20) NOT NULL DEFAULT 0,
  quote_hash char(64) NOT NULL DEFAULT '',
  lease_token char(32) DEFAULT NULL,
  lease_until datetime DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  error_code varchar(48) NOT NULL DEFAULT '',
  error_message varchar(255) NOT NULL DEFAULT '',
  response_status smallint(5) unsigned NOT NULL DEFAULT 0,
  response_json longtext NULL,
  client_created_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  committed_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY client_key (created_by,client_key),
  UNIQUE KEY order_id (order_id),
  KEY state_lease (state,lease_until),
  KEY cashier_created (cashier_user_id,created_at)
) ENGINE=InnoDB {collate};
SQL,

    'payments' => <<<'SQL'
CREATE TABLE {p}bazaar_payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  payment_uuid char(36) NOT NULL,
  checkout_id bigint(20) unsigned DEFAULT NULL,
  refund_id bigint(20) unsigned DEFAULT NULL,
  parent_payment_id bigint(20) unsigned DEFAULT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  seq smallint(5) unsigned NOT NULL DEFAULT 1,
  direction varchar(8) NOT NULL DEFAULT 'capture',
  provider varchar(32) NOT NULL,
  method_id varchar(64) NOT NULL,
  kind varchar(24) NOT NULL,
  status varchar(16) NOT NULL,
  verified tinyint(1) NOT NULL DEFAULT 0,
  amount_minor bigint(20) NOT NULL,
  refunded_minor bigint(20) NOT NULL DEFAULT 0,
  tendered_minor bigint(20) DEFAULT NULL,
  change_minor bigint(20) DEFAULT NULL,
  currency char(3) NOT NULL,
  reference varchar(128) NOT NULL DEFAULT '',
  provider_ref varchar(191) NOT NULL DEFAULT '',
  card_brand varchar(32) NOT NULL DEFAULT '',
  card_last4 char(4) NOT NULL DEFAULT '',
  operator_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cashier_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  staff_session_id bigint(20) unsigned DEFAULT NULL,
  approval_id bigint(20) unsigned DEFAULT NULL,
  register_id bigint(20) unsigned DEFAULT NULL,
  register_session_id bigint(20) unsigned DEFAULT NULL,
  provider_payload longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY payment_uuid (payment_uuid),
  UNIQUE KEY checkout_seq (checkout_id,seq),
  UNIQUE KEY refund_parent (refund_id,parent_payment_id),
  KEY order_id (order_id),
  KEY provider_ref (provider,provider_ref),
  KEY session_created (staff_session_id,created_at),
  KEY cashier_created (cashier_user_id,created_at)
) ENGINE=InnoDB {collate};
SQL,

    'refunds' => <<<'SQL'
CREATE TABLE {p}bazaar_refunds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_key varchar(64) NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  request_hash char(64) NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  wc_refund_id bigint(20) unsigned DEFAULT NULL,
  state varchar(16) NOT NULL,
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  tax_minor bigint(20) NOT NULL DEFAULT 0,
  restock tinyint(1) NOT NULL DEFAULT 1,
  reason varchar(191) NOT NULL DEFAULT '',
  cashier_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  staff_session_id bigint(20) unsigned DEFAULT NULL,
  approval_id bigint(20) unsigned DEFAULT NULL,
  error_code varchar(48) NOT NULL DEFAULT '',
  response_json longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY client_key (created_by,client_key),
  UNIQUE KEY wc_refund_id (wc_refund_id),
  KEY order_id (order_id)
) ENGINE=InnoDB {collate};
SQL,

    'staff' => <<<'SQL'
CREATE TABLE {p}bazaar_staff (
  user_id bigint(20) unsigned NOT NULL,
  pin_hash varchar(255) NOT NULL DEFAULT '',
  pin_set_at datetime DEFAULT NULL,
  pin_set_by bigint(20) unsigned NOT NULL DEFAULT 0,
  failed_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  locked_until datetime DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id)
) ENGINE=InnoDB {collate};
SQL,

    'staff_sessions' => <<<'SQL'
CREATE TABLE {p}bazaar_staff_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  staff_user_id bigint(20) unsigned NOT NULL,
  operator_user_id bigint(20) unsigned NOT NULL,
  device_id varchar(64) NOT NULL DEFAULT '',
  register_id bigint(20) unsigned DEFAULT NULL,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  idle_expires_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  ended_at datetime DEFAULT NULL,
  end_reason varchar(24) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY staff_open (staff_user_id,ended_at),
  KEY operator_device (operator_user_id,device_id,ended_at)
) ENGINE=InnoDB {collate};
SQL,

    'approvals' => <<<'SQL'
CREATE TABLE {p}bazaar_approvals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  scope varchar(24) NOT NULL,
  subject_hash char(64) NOT NULL,
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  basis_points int(11) NOT NULL DEFAULT 0,
  requested_by bigint(20) unsigned NOT NULL,
  approved_by bigint(20) unsigned NOT NULL,
  staff_session_id bigint(20) unsigned DEFAULT NULL,
  device_id varchar(64) NOT NULL DEFAULT '',
  reason varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  consumed_at datetime DEFAULT NULL,
  consumed_ref varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY approver_created (approved_by,created_at),
  KEY scope_created (scope,created_at)
) ENGINE=InnoDB {collate};
SQL,

    'events' => <<<'SQL'
CREATE TABLE {p}bazaar_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_uuid char(36) NOT NULL,
  type varchar(48) NOT NULL,
  aggregate_type varchar(24) NOT NULL,
  aggregate_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  created_at datetime NOT NULL,
  dispatched_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_uuid (event_uuid),
  KEY type_id (type,id),
  KEY undispatched (dispatched_at,id)
) ENGINE=InnoDB {collate};
SQL,

    ];
  }
}
