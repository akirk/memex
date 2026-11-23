<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';
function home_url( $path ) { return 'http://placeholder' . $path; }
function sanitize_key( $key ) { return $key; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function add_action() {}
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
$config = WpApp\Pwa::register('memex', ['cache_policy'=>'shell', 'offline_url'=>'http://placeholder/memex/capture', 'precache'=>['http://placeholder/assets/memex-offline.js','http://placeholder/assets/wp-app-outbox.js'], 'cache_name'=>'memex-capture-test', 'cache_prefix'=>'memex-capture-', 'cache_message_type'=>'memex-capture-cache', 'cache_status_message_type'=>'memex-capture-status', 'sync_message_type'=>'memex-capture-sync', 'sync_tag'=>'memex-capture-sync']);
$method = new ReflectionMethod(WpApp\Pwa::class, 'get_service_worker_script');
$method->setAccessible(true);
echo $method->invoke(null,$config);
