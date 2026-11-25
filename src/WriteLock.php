<?php

namespace Memex;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Connection-scoped locks and transactions must use the current database connection.

/** Serialize note writes on MySQL and on SQLite (including Playground). */
class WriteLock {
	public static function acquire( string $name ): bool {
		global $wpdb;
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			// SQLite has no advisory locks. Reserve its single writer before
			// reading a receipt or baseline; edits use a savepoint inside this.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return false;
			}
			if ( false === $wpdb->query( "UPDATE $wpdb->options SET option_value = option_value WHERE option_name = 'siteurl'" ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			return true;
		}
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) );
	}

	public static function release( string $name ): void {
		global $wpdb;
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the SQLite note write. Retry syncing.' );
			}
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
