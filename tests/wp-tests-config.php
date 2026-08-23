<?php
/**
 * Configuration for the WordPress core test library (wp-phpunit/wp-phpunit).
 *
 * Everything comes from the environment so CI needs no file; for local runs
 * put overrides in tests/wp-tests-config.local.php (git-ignored), e.g.
 *
 *   putenv( 'WP_CORE_DIR=/path/to/wordpress' );
 *   putenv( 'WP_TESTS_DB_USER=...' );
 *
 * The test library DROPS every table with the configured prefix in the
 * configured database on each run. Use a dedicated database, or at the very
 * least a prefix no real site uses.
 */

if ( file_exists( __DIR__ . '/wp-tests-config.local.php' ) ) {
	require __DIR__ . '/wp-tests-config.local.php';
}

$memex_env = static function ( string $name, string $default ): string {
	$value = getenv( $name );
	return false === $value ? $default : $value;
};

define( 'ABSPATH', rtrim( $memex_env( 'WP_CORE_DIR', '/tmp/wordpress' ), '/' ) . '/' );

define( 'DB_NAME', $memex_env( 'WP_TESTS_DB_NAME', 'wordpress_tests' ) );
define( 'DB_USER', $memex_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $memex_env( 'WP_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', $memex_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = $memex_env( 'WP_TESTS_TABLE_PREFIX', 'wptests_' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
