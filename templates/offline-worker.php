<?php
/** App-scoped service worker endpoint; no private pages are cached. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
header( 'Content-Type: application/javascript; charset=UTF-8' );
header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/memex/' ), PHP_URL_PATH ) );
nocache_headers();
$memex_worker_base = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
$memex_worker_version = \Memex\OfflineCapture::asset_version();
$memex_worker_assets = array(
	add_query_arg( 'ver', $memex_worker_version, $memex_worker_base . 'assets/memex.css' ),
	add_query_arg( 'ver', $memex_worker_version, $memex_worker_base . 'assets/memex-offline.js' ),
	$memex_worker_base . 'assets/icon.svg',
);
echo 'self.memexWorker = ' . wp_json_encode( array( 'cacheName' => 'memex-capture-' . $memex_worker_version, 'captureUrl' => home_url( '/memex/capture/' ), 'assets' => $memex_worker_assets ) ) . ";\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This endpoint serves trusted, bundled JavaScript, which must remain executable source.
echo file_get_contents( __DIR__ . '/../assets/memex-worker.js' );
