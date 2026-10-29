<?php
/** App-scoped service worker endpoint; no private pages are cached. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
header( 'Content-Type: application/javascript; charset=UTF-8' );
header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/memex/' ), PHP_URL_PATH ) );
nocache_headers();
$base = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
$assets = array( $base . 'assets/memex.css', $base . 'assets/memex-offline.js', $base . 'assets/icon.svg' );
$version = md5( file_get_contents( __DIR__ . '/../assets/memex-offline.js' ) . file_get_contents( __DIR__ . '/../assets/memex-worker.js' ) . file_get_contents( __DIR__ . '/../assets/memex.css' ) . file_get_contents( __DIR__ . '/capture.php' ) );
echo 'self.memexWorker = ' . wp_json_encode( array( 'cacheName' => 'memex-capture-' . $version, 'captureUrl' => home_url( '/memex/capture/' ), 'assets' => $assets ) ) . ";\n";
readfile( __DIR__ . '/../assets/memex-worker.js' );
