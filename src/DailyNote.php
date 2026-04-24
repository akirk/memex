<?php
/**
 * Daily-notes helper: get-or-create one note per day keyed by YYYY-MM-DD.
 */

namespace Memex;

class DailyNote {
	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	public static function is_valid_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
			&& checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) );
	}

	/**
	 * Find an existing daily note for $date, or null.
	 */
	public static function find( string $date ): ?\WP_Post {
		if ( ! self::is_valid_date( $date ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'meta_key'       => CPT::META_DAILY,
				'meta_value'     => $date,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Find or create the daily note for $date.
	 */
	public static function get_or_create( string $date ): ?\WP_Post {
		if ( ! self::is_valid_date( $date ) ) {
			return null;
		}
		$existing = self::find( $date );
		if ( $existing ) {
			return $existing;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => $date,
				'post_status'  => 'publish',
				'post_content' => '',
				'post_name'    => 'daily-' . $date,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return null;
		}
		update_post_meta( $id, CPT::META_DAILY, $date );
		return get_post( $id );
	}
}
