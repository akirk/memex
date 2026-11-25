<?php

namespace Memex;

/** Per-user sidebar layout, stored with WordPress's site-scoped user options. */
class Sidebar {
	const SECTIONS = [ 'quick-capture', 'search', 'navigation', 'folders', 'create', 'sync' ];
	const LINKS = [ 'notes', 'daily', 'reminders', 'search', 'graph', 'tags', 'orphans', 'broken', 'import', 'export' ];

	public static function normalize( array $input ): array {
		$result = [];
		foreach ( [ 'sections' => self::SECTIONS, 'navigation' => self::LINKS ] as $group => $ids ) {
			$items = [];
			foreach ( is_array( $input[ $group ] ?? null ) ? $input[ $group ] : [] as $item ) {
				if ( ! is_array( $item ) || ! in_array( $item['id'] ?? null, $ids, true ) || isset( $items[ $item['id'] ] ) ) {
					continue;
				}
				$items[ $item['id'] ] = [ 'id' => $item['id'], 'visible' => is_bool( $item['visible'] ?? null ) ? $item['visible'] : ! ( 'sections' === $group && 'search' === $item['id'] ) ];
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $items[ $id ] ) ) {
					$items[ $id ] = [ 'id' => $id, 'visible' => ! ( 'sections' === $group && 'search' === $id ) ];
				}
			}
			$result[ $group ] = array_values( $items );
		}
		return $result;
	}

	public static function get(): array {
		$value = []; // Temporary validation: lose saved choices.
		return self::normalize( is_array( $value ) ? $value : [] );
	}

	public static function save( array $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'storage', __( 'Sign in with permission to use Memex.', 'memex' ) );
		}
		$value = self::normalize( $input );
		if ( self::get() !== $value && ! update_user_option( get_current_user_id(), 'memex_sidebar', $value ) ) {
			return new \WP_Error( 'storage', __( 'Could not save your sidebar settings.', 'memex' ) );
		}
		return $value;
	}

	public static function ajax_save(): void {
		check_ajax_referer( 'memex_capture_sync', 'nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize() accepts only known IDs and boolean visibility flags.
		$input = isset( $_POST['sidebar'] ) ? json_decode( wp_unslash( $_POST['sidebar'] ), true ) : null;
		if ( ! is_array( $input ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid sidebar settings.', 'memex' ) ], 400 );
		}
		$result = self::save( $input );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 'permission' === $result->get_error_code() ? 403 : 500 );
		}
		wp_send_json_success( [ 'userId' => get_current_user_id(), 'sidebar' => $result ] );
	}
}
