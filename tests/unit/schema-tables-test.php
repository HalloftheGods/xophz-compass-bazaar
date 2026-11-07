<?php
/**
 * Schema_Tables: shape of the eleven CREATE TABLE statements (not run against MySQL here).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/schema/class-xophz-compass-bazaar-schema-tables.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/schema/class-xophz-compass-bazaar-schema-installer.php';

$T    = 'Xophz_Compass_Bazaar_Schema_Tables';
$defs = $T::definitions( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );

$expected = [ 'stock_batches', 'stock_movements', 'stock_balances', 'checkouts', 'payments', 'refunds', 'staff', 'staff_sessions', 'approvals', 'events', 'quick_pile' ];
check( 'tables: the eleven names in order', array_keys( $defs ), $expected );
check( 'tables: NAMES matches', $T::NAMES, $expected );

foreach ( $defs as $name => $sql ) {
  $table = "wp_bazaar_$name";
  check( "tables: $name starts with CREATE TABLE <prefix>bazaar_$name", 0 === strpos( $sql, "CREATE TABLE $table (" ), true );
  check( "tables: $name is InnoDB", false !== strpos( $sql, 'ENGINE=InnoDB' ), true );
  check( "tables: $name carries the collation", false !== strpos( $sql, 'COLLATE utf8mb4_unicode_520_ci' ), true );
  check( "tables: $name has no placeholder left", false === strpos( $sql, '{' ), true );
  check( "tables: $name has no foreign key", false === stripos( $sql, 'FOREIGN KEY' ) && false === stripos( $sql, 'REFERENCES' ), true );
  check( "tables: $name has a PRIMARY KEY with two spaces (dbDelta)", 1 === preg_match( '/^  PRIMARY KEY  \(/m', $sql ), true );
  check( "tables: $name uses KEY, not INDEX", false === stripos( $sql, 'INDEX' ), true );
  check( "tables: $name ends with the engine clause", 1 === preg_match( '/\n\) ENGINE=InnoDB [^;\n]+;$/', $sql ), true );
  // dbDelta wants one definition per line inside the parentheses.
  $inner = array_slice( explode( "\n", $sql ), 1, -1 );
  $bad   = array_filter( $inner, function ( $line ) { return ! preg_match( '/^  \S.*[,]?$/', $line ); } );
  check( "tables: $name has one definition per indented line", array_values( $bad ), [] );
  $datetimes = array_filter( $inner, function ( $line ) { return false !== stripos( $line, ' timestamp' ); } );
  check( "tables: $name has no TIMESTAMP columns (datetimes are UTC)", array_values( $datetimes ), [] );
}

check( 'tables: a different prefix is applied', 0 === strpos( $T::definitions( 'x7_', '' )['events'], 'CREATE TABLE x7_bazaar_events (' ), true );
check( 'tables: a multisite site gets its own pile table', 0 === strpos( $T::definitions( 'wp_2_', '' )['quick_pile'], 'CREATE TABLE wp_2_bazaar_quick_pile (' ), true );

// Spot checks on keys other code relies on.
check( 'tables: stock idem key is unique', false !== strpos( $defs['stock_batches'], 'UNIQUE KEY idem_key (idem_key)' ), true );
check( 'tables: checkout key is per creator', false !== strpos( $defs['checkouts'], 'UNIQUE KEY client_key (created_by,client_key)' ), true );
check( 'tables: one checkout per order', false !== strpos( $defs['checkouts'], 'UNIQUE KEY order_id (order_id)' ), true );
check( 'tables: balances are keyed by product and location', false !== strpos( $defs['stock_balances'], 'PRIMARY KEY  (stock_product_id,location_id)' ), true );
check( 'tables: approvals token hash is unique', false !== strpos( $defs['approvals'], 'UNIQUE KEY token_hash (token_hash)' ), true );
check( 'tables: events uuid is unique', false !== strpos( $defs['events'], 'UNIQUE KEY event_uuid (event_uuid)' ), true );
check( 'tables: payments uuid is unique', false !== strpos( $defs['payments'], 'UNIQUE KEY payment_uuid (payment_uuid)' ), true );
check( 'tables: one open pile row per code', false !== strpos( $defs['quick_pile'], 'UNIQUE KEY open_key (open_key)' ), true );

check( 'installer: DB_VERSION', Xophz_Compass_Bazaar_Schema_Installer::DB_VERSION, '26.11.06.1' );

bazaar_test_standalone( __FILE__ );
