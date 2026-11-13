<?php
// Router for "php -S": static files directly, everything else through WordPress.
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && file_exists( $root . $path ) && ! is_dir( $root . $path ) ) { return false; }
if ( is_dir( $root . $path ) && file_exists( rtrim( $root . $path, '/' ) . '/index.php' ) ) { chdir( rtrim( $root . $path, '/' ) ); require rtrim( $root . $path, '/' ) . '/index.php'; return; }
chdir( $root );
require $root . '/index.php';
