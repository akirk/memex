<?php
/**
 * PHPUnit bootstrap: load the WordPress core test library with the plugin
 * active, so tests run against real WordPress and a real database.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$memex_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $memex_tests_dir || ! file_exists( $memex_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "wp-phpunit/wp-phpunit is not installed; run composer install.\n" );
	exit( 1 );
}

require_once $memex_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/memex.php';
	}
);

require $memex_tests_dir . '/includes/bootstrap.php';
