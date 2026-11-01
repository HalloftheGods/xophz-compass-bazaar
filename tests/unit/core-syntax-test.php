<?php
/**
 * php -l on every plugin PHP file (vendor, node_modules and dist excluded).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';

$root  = dirname( dirname( __DIR__ ) );
$skip  = [ '/vendor/', '/node_modules/', '/dist/', '/.chemx/' ];
$files = [];
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
  $path = str_replace( '\\', '/', $file->getPathname() );
  if ( 'php' !== $file->getExtension() ) {
    continue;
  }
  foreach ( $skip as $part ) {
    if ( false !== strpos( $path, $part ) ) {
      continue 2;
    }
  }
  $files[] = $path;
}
sort( $files );

check( 'syntax: plugin has PHP files to lint', count( $files ) > 20, true );
foreach ( $files as $path ) {
  $out = [];
  $code = 0;
  exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
  check( 'syntax: ' . substr( $path, strlen( $root ) + 1 ), 0 === $code ? 'ok' : implode( ' ', $out ), 'ok' );
}

bazaar_test_standalone( __FILE__ );
