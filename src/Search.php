<?php
/**
 * Simple in-app search across notes.
 *
 * Matches on title + content via WP_Query. Orders recently-modified first.
 */

namespace Memex;

class Search {
	/**
	 * @return \WP_Post[]
	 */
	public static function query( string $q, int $limit = 50 ): array {
		$q = trim( $q );
		if ( '' === $q ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				's'              => $q,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		return $posts ? $posts : array();
	}

	/**
	 * Title-prefix autocomplete for the `[[` popover.
	 *
	 * @return array<int,array{id:int,title:string,slug:string}>
	 */
	public static function title_suggest( string $prefix, int $limit = 10 ): array {
		$prefix = trim( $prefix );
		if ( '' === $prefix ) {
			return array();
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $prefix ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name FROM $wpdb->posts
				 WHERE post_type = %s
				   AND post_status IN ('publish','draft','private')
				   AND post_title LIKE %s
				 ORDER BY (post_title LIKE %s) DESC, post_modified DESC
				 LIMIT %d",
				CPT::POST_TYPE,
				$like,
				$wpdb->esc_like( $prefix ) . '%',
				$limit
			)
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r->ID,
				'title' => $r->post_title,
				'slug'  => $r->post_name,
			);
		}
		return $out;
	}
}
