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

	public static function register() {
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
	 * Permalink for a note in the Memex app.
	 */
	public static function url( $post ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$slug = $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		if ( ! $slug ) {
			$slug = (string) $post->ID;
		}
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
