<?php
/**
 * Note custom post type and tag taxonomy.
 *
 * Notes are stored as a hierarchical CPT so nesting (Notion-style) works
 * out of the box. They're marked `public = false` because the Memex app
 * itself (via WpApp) is the primary reader; we don't want `/?p=123` style
 * URLs competing with `/memex/note/{slug}`.
 */

namespace Memex;

class CPT {
	const POST_TYPE = 'memex_note';
	const TAXONOMY  = 'memex_tag';

	// Post meta keys.
	const META_LINKS_TO      = '_memex_links_to';        // Forward links (one row per target post ID).
	const META_STUB          = '_memex_stub';            // 1 if note was auto-created by a wiki-link.
	const META_DAILY         = '_memex_daily';           // YYYY-MM-DD if this is a daily note.
	const META_IMPORT_SOURCE = '_memex_import_source';   // e.g. obsidian, notion, evernote, roam.
	const META_IMPORT_PATH   = '_memex_import_path';     // Original path inside the import source.

	/**
	 * Fallback gate used only when an older wp-app (< 1.5.0) without
	 * WpApp\Rest\Access is the loaded copy: deny anonymous REST reads of the
	 * note and tag routes so notes are never served publicly. Logged-in reads
	 * (and the block editor) are unaffected.
	 *
	 * @param mixed            $result  Response to replace the requested one, or null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public static function require_login_for_rest( $result, $server, $request ) {
		if ( is_user_logged_in() ) {
			return $result;
		}

		$route = $request->get_route();
		foreach ( array( self::POST_TYPE, self::TAXONOMY ) as $base ) {
			if ( 0 === strpos( $route, '/wp/v2/' . $base ) ) {
				return new \WP_Error(
					'rest_login_required',
					__( 'Authentication is required to read this data.', 'memex' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return $result;
	}

	public static function register() {
		// REST reads are gated by wp-app via the 'post_types' app option. If an
		// older wp-app without that gate is the loaded copy, fall back to a
		// request filter.
		if ( ! class_exists( '\\WpApp\\Rest\\Access' ) ) {
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'require_login_for_rest' ), 10, 3 );
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'               => __( 'Notes', 'memex' ),
					'singular_name'      => __( 'Note', 'memex' ),
					'add_new'            => __( 'Add New', 'memex' ),
					'add_new_item'       => __( 'Add New Note', 'memex' ),
					'edit_item'          => __( 'Edit Note', 'memex' ),
					'new_item'           => __( 'New Note', 'memex' ),
					'view_item'          => __( 'View Note', 'memex' ),
					'search_items'       => __( 'Search Notes', 'memex' ),
					'not_found'          => __( 'No notes found', 'memex' ),
					'not_found_in_trash' => __( 'No notes in trash', 'memex' ),
					'menu_name'          => __( 'Memex', 'memex' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_admin_bar' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'menu_icon'         => 'dashicons-book-alt',
				'menu_position'     => 20,
				'supports'          => array(
					'title',
					'editor',
					'revisions',
					'author',
					'excerpt',
					'custom-fields',
					'page-attributes',
				),
				'rewrite'           => false,
				'capability_type'   => 'page',
				'map_meta_cap'      => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Tags', 'memex' ),
					'singular_name' => __( 'Tag', 'memex' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => false,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STUB,
			array(
				'type'         => 'boolean',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_DAILY,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_IMPORT_SOURCE,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_IMPORT_PATH,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Does this post type count as a note?
	 */
	public static function is_note( $post ): bool {
		$post = get_post( $post );
		return $post && self::POST_TYPE === $post->post_type;
	}

	/**
	 * Post statuses that should be visible inside the authenticated Memex app.
	 *
	 * @return string[]
	 */
	public static function readable_statuses(): array {
		return array( 'publish', 'draft', 'private', 'pending' );
	}

	/**
	 * Notes that have children ("folders" — e.g. the parents created from the
	 * directory layout of an imported vault), as a nested tree.
	 *
	 * Only folder notes are included: leaves are listed on their parent's page.
	 *
	 * @return array<int, array{post: \WP_Post, children: array}> Root nodes, sorted by title.
	 */
	public static function folder_tree(): array {
		global $wpdb;

		$statuses     = self::readable_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$parent_ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0 AND post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::POST_TYPE ), $statuses )
			)
		);
		if ( ! $parent_ids ) {
			return array();
		}

		$folders = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $statuses,
				'post__in'       => array_map( 'intval', $parent_ids ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$nodes = array();
		foreach ( $folders as $f ) {
			$nodes[ $f->ID ] = array(
				'post'     => $f,
				'children' => array(),
			);
		}
		$roots = array();
		foreach ( array_keys( $nodes ) as $id ) {
			$parent = (int) $nodes[ $id ]['post']->post_parent;
			if ( $parent && isset( $nodes[ $parent ] ) ) {
				$nodes[ $parent ]['children'][] = &$nodes[ $id ];
			} else {
				$roots[] = &$nodes[ $id ];
			}
		}
		return $roots;
	}

	/**
	 * IDs of a note and all its ancestors, for expanding the folder tree.
	 *
	 * @return int[]
	 */
	public static function ancestor_ids( $post ): array {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}
		$ids  = array( (int) $post->ID );
		$seen = array();
		while ( $post && $post->post_parent && ! isset( $seen[ $post->post_parent ] ) ) {
			$seen[ $post->post_parent ] = true;
			$ids[]                      = (int) $post->post_parent;
			$post                       = get_post( $post->post_parent );
		}
		return $ids;
	}

	/**
	 * Permalink for a note in the Memex app.
	 */
	public static function url( $post ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$slug = $post->post_name ? $post->post_name : (string) $post->ID;
		return home_url( '/memex/note/' . rawurlencode( $slug ) );
	}

	/**
	 * Filter callback for `post_type_link`: makes get_permalink() return the
	 * Memex app URL for memex_note posts, so Gutenberg's link UI inserts
	 * `/memex/note/{slug}` instead of `?p=N&post_type=memex_note`.
	 */
	public static function filter_permalink( $link, $post ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $link;
		}
		return self::url( $post );
	}
}
