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
}
