<?php
/**
 * Fixtures and in-process checks for stock-write-sites.sh, run with `wp
 * eval-file` inside the container. The admin-ajax handlers are called
 * directly (wp_die turned into an exception) as the first administrator,
 * unless a user is named, and each prints `name=value` lines that the shell
 * script asserts on.
 *
 * Env: FIX_ACTION and the action's inputs.
 * - setup RUN: saves the mode options; creates, for each of the tags S
 *   (shadow run) and L (ledger run), a simple product A (stock 10), A2 (3),
 *   C (5), a multi-location product B (W1 4, W2 2, both enabled), a purchase
 *   order PO1 (5 x A2 at 2.50) and a warehouse W3; and the shared warehouses
 *   W1 and W2, product A3 with PO2 (4 x A3) for the parallel receive, a PRO
 *   subscriber (no Bazaar caps) and a catalog-only user (bazaar_manage_catalog,
 *   edit_products, bazaar_manage_stock denied). Prints one JSON line.
 * - seq MODE (shadow or ledger) IDS: every write site once in that mode.
 * - receive_one IDS: receive_po on PO2 in ledger mode (run in parallel by the script).
 * - after_parallel IDS: the stock of A3 after the parallel receive.
 * - cleanup IDS: deletes the ledger and outbox rows of the products, trashes
 *   the posts, deletes the users and puts the mode options back.
 *
 * In shadow mode the checks cover `_stock` and the Stock_Context reason seen
 * when WooCommerce changed the stock, not ledger rows: the shadow observer
 * (WP13) writes them. Nonce checks are not exercised (the handlers are called
 * directly); guard.sh and the ajax call in the script cover the guard.
 */
global $wpdb;

if ( ! defined( 'DOING_AJAX' ) ) {
  define( 'DOING_AJAX', true );
}
add_filter(
  'wp_die_ajax_handler',
  function () {
    return function () {
      throw new RuntimeException( 'wp_die', 4242 );
    };
  }
);

$env = function ( string $name ): string {
  return (string) getenv( $name );
};
$tbl = function ( string $name ): string {
  return Xophz_Compass_Bazaar_Core_Db::table( $name );
};
$num = function ( $value ): string {
  $text = rtrim( rtrim( sprintf( '%.4F', (float) $value ), '0' ), '.' );
  return '-0' === $text ? '0' : $text;
};
$say = function ( string $key, $value ) use ( $num ) {
  if ( is_bool( $value ) ) {
    $value = $value ? 'true' : 'false';
  } elseif ( is_int( $value ) || is_float( $value ) ) {
    $value = $num( $value );
  } elseif ( null === $value ) {
    $value = 'null';
  } elseif ( ! is_string( $value ) ) {
    $value = wp_json_encode( $value );
  }
  echo $key . '=' . $value . "\n";
};
$admin = function (): int {
  $ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
  return (int) ( $ids[0] ?? 0 );
};
/** Run one handler as a user with $req as the request; returns the decoded JSON it sent. */
$call = function ( callable $fn, array $req = [], ?int $user = null ) use ( $admin ): array {
  wp_set_current_user( null === $user ? $admin() : $user );
  Xophz_Compass_Bazaar_Security_Guard::reset();
  $_REQUEST = $_POST = $_GET = [];
  foreach ( $req as $key => $value ) {
    $_REQUEST[ $key ] = $_POST[ $key ] = $_GET[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
  }
  $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
  ob_start();
  try {
    $fn();
  } catch ( RuntimeException $e ) {
    if ( 4242 !== $e->getCode() ) {
      ob_end_clean();
      throw $e;
    }
  }
  $decoded = json_decode( trim( ob_get_clean() ), true );
  return is_array( $decoded ) ? $decoded : [ 'unparsed' => true ];
};
$code = function ( array $r ): string {
  return (string) ( $r['code'] ?? $r['data']['code'] ?? '' );
};
$ok = function ( array $r ): bool {
  return ! empty( $r['success'] );
};
$new = function ( string $class ) {
  return new $class( 'xophz-compass-bazaar', 'integration' );
};

/** Fresh `_stock`, ledger balances and the last ledger reason of one product. */
$state = function ( int $pid ) use ( $wpdb, $tbl ): array {
  wp_cache_delete( $pid, 'post_meta' );
  clean_post_cache( $pid );
  $bal = [];
  $sum = 0.0;
  foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT location_id, on_hand, is_enabled FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d ORDER BY location_id', $pid ) ) as $row ) {
    $bal[ (int) $row->location_id ] = (float) $row->on_hand;
    $sum += (int) $row->is_enabled ? (float) $row->on_hand : 0.0;
  }
  $meta = [];
  foreach ( (array) get_post_meta( $pid, '_compass_multi_inventory', true ) as $loc => $entry ) {
    if ( is_array( $entry ) ) {
      $meta[ (int) $loc ] = $entry['stock_quantity'] ?? null;
    }
  }
  $wc = (float) get_post_meta( $pid, '_stock', true );
  return [
    'wc'     => $wc,
    'bal'    => $bal,
    'agree'  => $bal ? abs( $wc - $sum ) < 0.00005 : null,
    'reason' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT b.reason FROM ' . $tbl( 'stock_movements' ) . ' m JOIN ' . $tbl( 'stock_batches' ) . ' b ON b.id = m.batch_id WHERE m.stock_product_id = %d ORDER BY m.id DESC LIMIT 1', $pid ) ),
    'meta'   => $meta,
    'unit'   => $wpdb->get_var( $wpdb->prepare( 'SELECT m.unit_cost_minor FROM ' . $tbl( 'stock_movements' ) . ' m WHERE m.stock_product_id = %d ORDER BY m.id DESC LIMIT 1', $pid ) ),
  ];
};
$product = function ( int $stock, string $name, array $multi = [] ): int {
  $p = new WC_Product_Simple();
  $p->set_name( $name );
  $p->set_status( 'private' );
  $p->set_regular_price( '3.00' );
  $p->set_manage_stock( true );
  $p->set_stock_quantity( $stock );
  $p->save();
  if ( $multi ) {
    $meta = [];
    foreach ( $multi as $loc => $entry ) {
      $meta[ $loc ] = [ 'location_id' => $loc, 'stock_quantity' => $entry[0], 'low_stock_threshold' => 5, 'sku' => '', 'price' => null, 'is_enabled' => $entry[1] ];
    }
    update_post_meta( $p->get_id(), '_compass_multi_inventory', $meta );
  }
  return $p->get_id();
};
$po = function ( int $pid, int $qty, string $title ): int {
  $id = (int) wp_insert_post( [ 'post_type' => 'bazaar_po', 'post_status' => 'publish', 'post_title' => $title ] );
  update_post_meta( $id, '_po_items', [ [ 'product_id' => $pid, 'name' => 'x', 'qty' => $qty, 'cost' => 2.5, 'total' => 2.5 * $qty ] ] );
  update_post_meta( $id, '_po_status', 'sent' );
  return $id;
};
$warehouse = function ( string $title ): int {
  return (int) wp_insert_post( [ 'post_type' => 'compass_warehouse', 'post_status' => 'private', 'post_title' => $title ] );
};
$ids = json_decode( $env( 'IDS' ), true );
$ids = is_array( $ids ) ? $ids : [];
$run = $env( 'RUN' );

switch ( $env( 'FIX_ACTION' ) ) {
  case 'setup':
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $out = [ 'saved' => [ 'mode' => get_option( 'bazaar_ledger_mode', '__absent__' ), 'history' => get_option( 'bazaar_ledger_mode_history', '__absent__' ) ] ];
    update_option( 'bazaar_ledger_mode', 'off' ); // no observer while the fixtures are created
    $out['W1'] = $warehouse( "Write sites W1 $run" );
    $out['W2'] = $warehouse( "Write sites W2 $run" );
    foreach ( [ 'S', 'L' ] as $tag ) {
      $a2          = $product( 3, "Write sites A2$tag $run" );
      $out[ $tag ] = [
        'A'   => $product( 10, "Write sites A$tag $run" ),
        'A2'  => $a2,
        'C'   => $product( 5, "Write sites C$tag $run" ),
        'B'   => $product( 6, "Write sites B$tag $run", [ $out['W1'] => [ 4, true ], $out['W2'] => [ 2, true ] ] ),
        'PO1' => $po( $a2, 5, "Write sites PO1$tag $run" ),
        'W3'  => $warehouse( "Write sites W3$tag $run" ),
      ];
    }
    $out['A3']  = $product( 10, "Write sites A3 $run" );
    $out['PO2'] = $po( $out['A3'], 4, "Write sites PO2 $run" );
    $pro        = wp_insert_user( [ 'user_login' => "bz_pro_$run", 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ] );
    $cat        = wp_insert_user( [ 'user_login' => "bz_cat_$run", 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ] );
    update_user_meta( $pro, '_xp_is_pro', 'yes' );
    $cat_user = new WP_User( $cat );
    $cat_user->add_cap( 'edit_products', true );
    $cat_user->add_cap( 'bazaar_manage_catalog', true );
    $cat_user->add_cap( 'bazaar_manage_stock', false );
    $out['PRO']   = (int) $pro;
    $out['CAT']   = (int) $cat;
    $out['ADMIN'] = $admin();
    echo wp_json_encode( $out ) . "\n";
    break;

  case 'seq':
    $mode = $env( 'MODE' );
    $t    = $ids['shadow' === $mode ? 'S' : 'L'];
    $L    = 'ledger' === $mode;
    // Other test scripts flip the stored mode now and then; this process keeps its own.
    $forced = $mode;
    add_filter(
      'pre_option_bazaar_ledger_mode',
      function () use ( &$forced ) {
        return $forced;
      }
    );
    $spy = [];
    add_action(
      'woocommerce_updated_product_stock',
      function ( $pid ) use ( &$spy ) {
        $ctx            = Xophz_Compass_Bazaar_Stock_Context::current();
        $spy[ (int) $pid ] = $ctx ? $ctx['reason'] : 'none';
      }
    );
    // after() : the facts about one product after a step, as name.wc, name.agree, name.reason.
    $after = function ( string $name, int $pid ) use ( $state, $say, $L, &$spy, $mode ) {
      $s = $state( $pid );
      $say( "$mode.$name.wc", $s['wc'] );
      if ( $L ) {
        $say( "$mode.$name.agree", $s['agree'] );
        $say( "$mode.$name.reason", $s['reason'] );
      } else {
        $say( "$mode.$name.reason", $spy[ $pid ] ?? 'none' );
      }
    };
    $stock  = $new( 'Xophz_Compass_Bazaar_Admin' );
    $update = function ( array $req, ?int $user = null ) use ( $call, $stock, $t ) {
      return $call( [ $stock, 'updateProductStock' ], $req + [ 'product_id' => $t['A'] ], $user );
    };

    // update_product_stock
    $r = $update( [ 'operation' => 'add', 'quantity' => 3, 'client_uuid' => "$run-a1" ] );
    $say( "$mode.add.success", $ok( $r ) );
    $after( 'add', $t['A'] );
    $r = $update( [ 'operation' => 'subtract', 'quantity' => 4, 'client_uuid' => "$run-a2" ] );
    $after( 'subtract', $t['A'] );
    $r = $update( [ 'operation' => 'bogus', 'quantity' => 4 ] );
    $say( "$mode.bogus.success", $ok( $r ) );
    $after( 'bogus', $t['A'] );
    $r = $update( [ 'quantity' => 20, 'client_uuid' => "$run-a3" ] );
    $say( "$mode.set.success", $ok( $r ) );
    $after( 'set', $t['A'] );
    $r = $update( [ 'operation' => 'count', 'quantity' => 1, 'expected' => 99, 'client_uuid' => "$run-a4" ] );
    $say( "$mode.stale.code", $code( $r ) );
    $say( "$mode.stale.current", $r['current'] ?? null );
    $after( 'stale', $t['A'] );
    $r = $update( [ 'operation' => 'count', 'quantity' => 15, 'expected' => 20, 'client_uuid' => "$run-a5" ] );
    $say( "$mode.count.success", $ok( $r ) );
    $after( 'count', $t['A'] );
    if ( $L ) {
      $update( [ 'operation' => 'add', 'quantity' => 1, 'client_uuid' => "$run-a6" ] );
      $update( [ 'operation' => 'add', 'quantity' => 1, 'client_uuid' => "$run-a6" ] );
      $after( 'replay', $t['A'] );
      $r = $update( [ 'operation' => 'subtract', 'quantity' => 1000, 'client_uuid' => "$run-a7" ] );
      $say( "$mode.oversell.code", $code( $r ) );
      $after( 'oversell', $t['A'] );
    }
    $before = $state( $t['A'] )['wc'];
    $r      = $update( [ 'operation' => 'add', 'quantity' => 1, 'client_uuid' => "$run-a8" ], $ids['PRO'] );
    $say( "$mode.pro_update.code", $code( $r ) );
    $say( "$mode.pro_update.unchanged", $state( $t['A'] )['wc'] === $before );

    // save_product on C (stock 5)
    $save = function ( array $req, ?int $user = null ) use ( $call, $stock, $t ) {
      return $call( [ $stock, 'saveProduct' ], $req + [ 'id' => $t['C'], 'title' => 'Write sites C', 'regular_price' => '3' ], $user );
    };
    $save( [ 'manage_stock' => 'false' ] );
    wp_cache_delete( $t['C'], 'post_meta' );
    $say( "$mode.save.string_false.managing", (bool) wc_get_product( $t['C'] )->get_manage_stock() );
    $r = $save( [ 'manage_stock' => 'true', 'stock_quantity' => 8 ] );
    $say( "$mode.save.stock.success", $ok( $r ) );
    $say( "$mode.save.stock.managing", (bool) wc_get_product( $t['C'] )->get_manage_stock() );
    $after( 'save.stock', $t['C'] );
    $r = $save( [ 'manage_stock' => 'true', 'stock_quantity' => 8, 'title' => 'Write sites C renamed' ], $ids['CAT'] );
    $say( "$mode.save.cat_same.success", $ok( $r ) );
    $r = $save( [ 'manage_stock' => 'true', 'stock_quantity' => 50 ], $ids['CAT'] );
    $say( "$mode.save.cat_change.success", $ok( $r ) );
    $say( "$mode.save.cat_change.refused", false !== strpos( (string) ( $r['message'] ?? '' ), 'permission' ) );
    $after( 'save.cat_change', $t['C'] );
    $r = $save( [ 'manage_stock' => 'true', 'stock_quantity' => 60 ], $ids['PRO'] );
    $say( "$mode.save.pro.success", $ok( $r ) );
    $after( 'save.pro', $t['C'] );

    // product CSV import
    $import = $new( 'Xophz_Compass_Bazaar_Admin' );
    $rows   = [ [ 'title' => "Write sites import $mode $run", 'sku' => "WS-$mode-$run", 'regular_price' => '5', 'stock_quantity' => '12' ] ];
    $r      = $call( [ $import, 'importProductsCsv' ], [ 'products' => $rows ] );
    $say( "$mode.import.created", $r['created_count'] ?? null );
    $imported = (int) wc_get_product_id_by_sku( "WS-$mode-$run" );
    $after( 'import', $imported );
    $update( [ 'operation' => 'add', 'quantity' => 3, 'client_uuid' => "$run-i1", 'product_id' => $imported ] );
    $r = $call( [ $import, 'importProductsCsv' ], [ 'products' => $rows ] );
    $say( "$mode.import.again.updated", $r['updated_count'] ?? null );
    $after( 'import_again', $imported );
    $rows[0]['stock_quantity'] = '99';
    $r = $call( [ $import, 'importProductsCsv' ], [ 'products' => $rows ], $ids['CAT'] );
    $say( "$mode.import.cat.updated", $r['updated_count'] ?? null );
    $say( "$mode.import.cat.noted", false !== strpos( implode( ' ', (array) ( $r['errors'] ?? [] ) ), 'permission' ) );
    $after( 'import_cat', $imported );

    // receive_po
    $proc = $new( 'Xophz_Compass_Bazaar_Admin_Procurement' );
    $recv = function ( int $po, ?int $user = null ) use ( $call, $proc ) {
      return $call( [ $proc, 'receive_po' ], [ 'id' => $po ], $user );
    };
    $r = $recv( $t['PO1'], $ids['PRO'] );
    $say( "$mode.po.pro.code", $code( $r ) );
    $after( 'po_pro', $t['A2'] );
    $r = $recv( $t['A'] );
    $say( "$mode.po.not_a_po.code", $code( $r ) );
    $r = $recv( $t['PO1'] );
    $say( "$mode.po.first.success", $ok( $r ) );
    $after( 'po', $t['A2'] );
    if ( $L ) {
      $say( 'ledger.po.unit_cost_minor', $state( $t['A2'] )['unit'] );
    }
    $say( "$mode.po.status", (string) get_post_meta( $t['PO1'], '_po_status', true ) );
    $r = $recv( $t['PO1'] );
    $say( "$mode.po.second.code", $code( $r ) );
    $after( 'po_again', $t['A2'] );

    // Multi-inventory (B: W1 4, W2 2, both enabled; _stock 6)
    $multi = $new( 'Xophz_Compass_Bazaar_Admin_Multi_Inventory' );
    $inv   = function ( int $w1, int $w2, bool $w2_on ) use ( $ids ) {
      return [
        $ids['W1'] => [ 'stock_quantity' => $w1, 'low_stock_threshold' => 7, 'sku' => 'KEEP', 'price' => '4.5', 'is_enabled' => true ],
        $ids['W2'] => [ 'stock_quantity' => $w2, 'low_stock_threshold' => 5, 'sku' => '', 'price' => '', 'is_enabled' => $w2_on ],
      ];
    };
    $r = $call( [ $multi, 'saveProductInventories' ], [ 'product_id' => $t['B'], 'inventories' => $inv( 7, 2, false ) ], $ids['PRO'] );
    $say( "$mode.inv.pro.code", $code( $r ) );
    $s = $state( $t['B'] );
    $say( "$mode.inv.pro.unchanged", 6.0 === $s['wc'] );
    $r = $call( [ $multi, 'saveProductInventories' ], [ 'product_id' => $t['B'], 'inventories' => $inv( 7, 2, false ) ] );
    $say( "$mode.inv.save.success", $ok( $r ) );
    $say( "$mode.inv.save.total", $r['data']['total_stock'] ?? null );
    $after( 'inv_save', $t['B'] );
    $s = $state( $t['B'] );
    $say( "$mode.inv.save.meta", $s['meta'] === [ $ids['W1'] => 7, $ids['W2'] => 2 ] || $s['meta'] === [ $ids['W1'] => 7.0, $ids['W2'] => 2.0 ] );
    $entry = get_post_meta( $t['B'], '_compass_multi_inventory', true )[ $ids['W1'] ] ?? [];
    $say( "$mode.inv.save.kept", ( $entry['sku'] ?? '' ) . '/' . ( $entry['low_stock_threshold'] ?? '' ) . '/' . ( $entry['price'] ?? '' ) );
    if ( $L ) {
      $say( 'ledger.inv.save.bal', $s['bal'] );
      $say( 'ledger.inv.save.w2_enabled', (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT is_enabled FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d AND location_id = %d', $t['B'], $ids['W2'] ) ) );
    }
    $r = $call( [ $multi, 'bulkUpdateInventoryStock' ], [ 'updates' => [ [ 'product_id' => $t['B'], 'location_id' => $ids['W1'], 'stock_quantity' => 5 ] ] ] );
    $say( "$mode.inv.bulk.updated", $r['data']['updated_count'] ?? null );
    $after( 'inv_bulk', $t['B'] );
    $csv = tempnam( sys_get_temp_dir(), 'bzcsv' );
    file_put_contents( $csv, "Product ID,SKU,Product Name,Location ID,Location Stock,Low Stock Threshold,Location Price\n{$t['B']},CSVSKU,n,{$ids['W1']},9,4,3.00\n" );
    $_FILES['file'] = [ 'tmp_name' => $csv, 'name' => 'x.csv', 'error' => 0, 'size' => 1, 'type' => 'text/csv' ];
    $csvh = $new( 'Xophz_Compass_Bazaar_Admin_Multi_Inventory' );
    $r    = $call( [ $csvh, 'importInventoryCsv' ], [], $ids['PRO'] );
    $say( "$mode.inv.csv.pro.code", $code( $r ) );
    $_FILES['file'] = [ 'tmp_name' => $csv, 'name' => 'x.csv', 'error' => 0, 'size' => 1, 'type' => 'text/csv' ];
    $r              = $call( [ $csvh, 'importInventoryCsv' ], [] );
    $say( "$mode.inv.csv.rows", $r['data']['rows_processed'] ?? null );
    $after( 'inv_csv', $t['B'] );
    $entry = get_post_meta( $t['B'], '_compass_multi_inventory', true )[ $ids['W1'] ] ?? [];
    $say( "$mode.inv.csv.kept", ( $entry['sku'] ?? '' ) . '/' . ( $entry['low_stock_threshold'] ?? '' ) );
    unset( $_FILES['file'] );
    @unlink( $csv ); // phpcs:ignore

    // Delete location: refused while stock remains
    $r = $call( [ $multi, 'deleteInventoryLocation' ], [ 'location_id' => $ids['W1'] ], $ids['PRO'] );
    $say( "$mode.del.pro.code", $code( $r ) );
    $r = $call( [ $multi, 'deleteInventoryLocation' ], [ 'location_id' => $ids['W1'] ] );
    $say( "$mode.del.stock.code", $code( $r ) );
    $say( "$mode.del.stock.still_there", null !== get_post( $ids['W1'] ) );
    $r = $call( [ $multi, 'deleteInventoryLocation' ], [ 'location_id' => $t['W3'] ] );
    $say( "$mode.del.empty.success", $ok( $r ) );

    // reduceLocationOrderStock does nothing unless the mode is off
    $order = new class( $ids['W1'], $t['B'] ) {
      public $loc;
      public $pid;
      public function __construct( $loc, $pid ) {
        $this->loc = $loc;
        $this->pid = $pid;
      }
      public function get_meta( $key ) {
        return $this->loc;
      }
      public function get_items() {
        $pid = $this->pid;
        return [ new class( $pid ) {
          public $pid;
          public function __construct( $pid ) {
            $this->pid = $pid;
          }
          public function get_product_id() {
            return $this->pid;
          }
          public function get_quantity() {
            return 2;
          }
        } ];
      }
    };
    $before = $state( $t['B'] )['meta'][ $ids['W1'] ] ?? null;
    $multi->reduceLocationOrderStock( $order );
    $say( "$mode.reduce.unchanged", ( $state( $t['B'] )['meta'][ $ids['W1'] ] ?? null ) === $before );
    if ( ! $L ) {
      $forced = 'off';
      $multi->reduceLocationOrderStock( $order );
      $say( 'off.reduce.meta', $state( $t['B'] )['meta'][ $ids['W1'] ] ?? null );
    }
    break;

  case 'after_parallel':
    $s = $state( (int) $ids['A3'] );
    $say( 'par.wc', $s['wc'] );
    $say( 'par.bal0', $s['bal'][0] ?? null );
    $say( 'par.agree', $s['agree'] );
    $say( 'par.reason', $s['reason'] );
    $say( 'par.batches', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_batches' ) . " WHERE idem_key = %s", 'po:' . (int) $ids['PO2'] . ':receive' ) ) );
    $say( 'par.status', (string) get_post_meta( (int) $ids['PO2'], '_po_status', true ) );
    break;

  case 'receive_one':
    add_filter(
      'pre_option_bazaar_ledger_mode',
      function () {
        return 'ledger';
      }
    );
    $r = $call( [ $new( 'Xophz_Compass_Bazaar_Admin_Procurement' ), 'receive_po' ], [ 'id' => (int) $ids['PO2'] ] );
    $say( 'recv.success', $ok( $r ) );
    $say( 'recv.code', $code( $r ) );
    break;

  case 'cleanup':
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $pids = [];
    $wids = [ $ids['W1'] ?? 0, $ids['W2'] ?? 0, $ids['PO2'] ?? 0, $ids['A3'] ?? 0 ];
    foreach ( [ 'S', 'L' ] as $tag ) {
      foreach ( (array) ( $ids[ $tag ] ?? [] ) as $key => $id ) {
        if ( in_array( $key, [ 'A', 'A2', 'C', 'B' ], true ) ) {
          $pids[] = (int) $id;
        } else {
          $wids[] = (int) $id;
        }
      }
    }
    $pids[] = (int) ( $ids['A3'] ?? 0 );
    foreach ( [ 'S', 'L' ] as $mode ) {
      $found = (int) wc_get_product_id_by_sku( "WS-$mode-" . $run );
      if ( $found ) {
        $pids[] = $found;
      }
    }
    $pids      = array_values( array_filter( $pids ) );
    $batch_ids = [];
    if ( $pids ) {
      $in        = implode( ',', $pids );
      $batch_ids = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . $tbl( 'stock_movements' ) . " WHERE stock_product_id IN ($in)" ) );
      if ( $batch_ids ) {
        $bin = implode( ',', $batch_ids );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
      }
      $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_balances' ) . " WHERE stock_product_id IN ($in)" );
    }
    $trashed = 0;
    foreach ( array_merge( $pids, array_filter( $wids ) ) as $id ) {
      $trashed += get_post( $id ) && wp_trash_post( $id ) ? 1 : 0;
    }
    foreach ( [ 'PRO', 'CAT' ] as $who ) {
      if ( ! empty( $ids[ $who ] ) ) {
        wp_delete_user( (int) $ids[ $who ] );
      }
    }
    $saved = $ids['saved'] ?? null;
    if ( is_array( $saved ) && array_key_exists( 'mode', $saved ) ) {
      foreach ( [ 'bazaar_ledger_mode' => $saved['mode'], 'bazaar_ledger_mode_history' => $saved['history'] ?? '__absent__' ] as $name => $value ) {
        if ( '__absent__' === $value ) {
          delete_option( $name );
        } else {
          update_option( $name, $value );
        }
      }
    }
    $say( 'cleanup.batches', count( $batch_ids ) );
    $say( 'cleanup.trashed', $trashed );
    $say( 'cleanup.mode', get_option( 'bazaar_ledger_mode', '__absent__' ) );
    break;

  default:
    $say( 'error', 'unknown FIX_ACTION' );
}
