<?php
/**
 * [[Wiki-link]] parsing, stub creation, backlink tracking, and rendering.
 *
 * Syntax supported:
 *   [[Target]]               - link to a note titled "Target"
 *   [[Target|Display text]]  - Obsidian-style display-text override
 *   [[Target#Heading]]       - heading anchor (kept as fragment)
 *
 * Storage model:
 *   Each forward link is stored as a separate post_meta row with key
 *   `_memex_links_to` and value = target post ID. One row per target makes
 *   backlink queries trivial: `meta_key = _memex_links_to, meta_value = ID`.
 *   Serialized-array meta would defeat meta_query.
 */

namespace Memex;

class WikiLinks {
	const LINK_REGEX = '/\[\[([^\[\]\|]+?)(?:\|([^\[\]]+?))?\]\]/';

	public static function register() {
		add_action( 'save_post_' . CPT::POST_TYPE, array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_filter( 'the_content', array( __CLASS__, 'render_links' ), 9 );
	}

	/**
	 * After a note is saved, sync forward-link meta rows.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}
		self::sync_links_from_content( $post_id, $post->post_content );
	}

	public static function on_trash( $post_id ) {
		if ( CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		delete_post_meta( $post_id, CPT::META_LINKS_TO );
	}

	/**
	 * Extract `[[Target]]` strings from content.
	 *
	 * @return array<int,array{target:string,display:?string}>
	 */
	public static function extract_links( string $content ): array {
		if ( ! preg_match_all( self::LINK_REGEX, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches as $m ) {
			$target = trim( $m[1] );
			if ( '' === $target ) {
				continue;
			}
			$out[] = array(
				'target'  => $target,
				'display' => isset( $m[2] ) && '' !== trim( $m[2] ) ? trim( $m[2] ) : null,
			);
		}
		return $out;
	}

	/**
	 * Sync forward-link meta rows from the given content.
	 *
	 * Recognises two link shapes:
	 *   1. `<a href="/memex/note/SLUG">…</a>` — what Gutenberg's link picker emits.
	 *   2. `[[Target]]` wiki-links — portable syntax used by imports and by anyone
	 *      who'd rather type brackets than open a link dialog.
	 *
	 * Only wiki-links auto-create stub notes (pre-declare an idea before
	 * writing it). HTML links resolve against existing notes — if the target
	 * isn't a memex_note URL we recognise, the link is ignored for backlink
	 * purposes (it's just an ordinary external/internal link).
	 */
	public static function sync_links_from_content( int $post_id, string $content ): array {
		delete_post_meta( $post_id, CPT::META_LINKS_TO );
		$stored = array();

		foreach ( self::extract_links( $content ) as $link ) {
			$target    = self::strip_fragment( $link['target'] );
			$target_id = self::resolve_or_create( $target );
			if ( $target_id && $target_id !== $post_id && ! in_array( $target_id, $stored, true ) ) {
				$stored[] = $target_id;
			}
		}

		foreach ( self::extract_hrefs( $content ) as $href ) {
			$target_id = self::resolve_href( $href );
			if ( $target_id && $target_id !== $post_id && ! in_array( $target_id, $stored, true ) ) {
				$stored[] = $target_id;
			}
		}

		foreach ( $stored as $tid ) {
			add_post_meta( $post_id, CPT::META_LINKS_TO, $tid );
		}
		return $stored;
	}

	/**
	 * Pull every `href` value out of `<a>` tags in post_content.
	 *
	 * @return string[]
	 */
	public static function extract_hrefs( string $content ): array {
		if ( false === stripos( $content, '<a ' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $content, $m ) ) {
			return array();
		}
		return array_values( array_unique( $m[2] ) );
	}

	/**
	 * Resolve a Memex-note-looking URL to a note ID, or 0 if it doesn't point
	 * at one of our notes. Accepts absolute and relative URLs.
	 */
	public static function resolve_href( string $href ): int {
		$href = trim( $href );
		if ( '' === $href ) {
			return 0;
		}
		// Drop fragment; keep query for ?p=N fallback.
		$frag_pos = strpos( $href, '#' );
		if ( false !== $frag_pos ) {
			$href = substr( $href, 0, $frag_pos );
		}

		// Ensure we're looking at a same-site URL: relative, or same host as home_url().
		$parts = wp_parse_url( $href );
		if ( ! empty( $parts['host'] ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $parts['host'] !== $home_host ) {
				return 0;
			}
		}
		$path  = $parts['path'] ?? $href;
		$query = $parts['query'] ?? '';

		// /memex/note/SLUG
		if ( preg_match( '#/memex/note/([^/]+)/?$#', $path, $m ) ) {
			$slug = rawurldecode( $m[1] );
			$by_slug = get_posts(
				array(
					'post_type'      => CPT::POST_TYPE,
					'name'           => $slug,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $by_slug ) {
				return (int) $by_slug[0];
			}
			$id = self::resolve( $slug );
			if ( $id ) {
				return $id;
			}
		}

		// ?p=N or ?post=N (Gutenberg sometimes emits `?p=` for non-public types).
		if ( '' !== $query ) {
			parse_str( $query, $q );
			foreach ( array( 'p', 'post', 'memex_note' ) as $key ) {
				if ( ! empty( $q[ $key ] ) ) {
					$id = is_numeric( $q[ $key ] ) ? (int) $q[ $key ] : 0;
					if ( $id && CPT::POST_TYPE === get_post_type( $id ) ) {
						return $id;
					}
				}
			}
		}

		return 0;
	}

	private static function strip_fragment( string $target ): string {
		$pos = strpos( $target, '#' );
		if ( false === $pos ) {
			return $target;
		}
		return trim( substr( $target, 0, $pos ) );
	}

	/**
	 * Find an existing note by title (case-insensitive) or slug.
	 */
	public static function resolve( string $title ): int {
		$title = trim( $title );
		if ( '' === $title ) {
			return 0;
		}
		$title = self::strip_fragment( $title );

		// Exact title match (any case).
		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts
				 WHERE post_type = %s
				   AND post_status IN ('publish','draft','private','pending')
				   AND LOWER(post_title) = LOWER(%s)
				 ORDER BY post_status = 'publish' DESC, ID ASC
				 LIMIT 1",
				CPT::POST_TYPE,
				$title
			)
		);
		if ( $id ) {
			return $id;
		}

		// Slug match.
		$slug    = sanitize_title( $title );
		$by_slug = get_posts(
			array(
				'post_type'        => CPT::POST_TYPE,
				'name'             => $slug,
				'post_status'      => array( 'publish', 'draft', 'private', 'pending' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		return $by_slug ? (int) $by_slug[0] : 0;
	}

	/**
	 * Find or create a stub note for the given title.
	 */
	public static function resolve_or_create( string $title ): int {
		$existing = self::resolve( $title );
		if ( $existing ) {
			return $existing;
		}
		$title = trim( self::strip_fragment( $title ) );
		if ( '' === $title ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => $title,
				'post_status'  => 'draft',
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, CPT::META_STUB, 1 );
		return (int) $id;
	}

	/**
	 * Replace `[[...]]` strings with anchors when content is rendered.
	 */
	public static function render_links( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[[' ) ) {
			return $content;
		}
		return preg_replace_callback(
			self::LINK_REGEX,
			static function ( $m ) {
				$raw_target = trim( $m[1] );
				$display    = isset( $m[2] ) && '' !== trim( $m[2] ) ? trim( $m[2] ) : $raw_target;
				$target     = self::strip_fragment( $raw_target );
				$fragment   = '';
				$hash       = strpos( $raw_target, '#' );
				if ( false !== $hash ) {
					$fragment = '#' . sanitize_title( substr( $raw_target, $hash + 1 ) );
				}
				$id = self::resolve( $target );
				if ( $id ) {
					$url = CPT::url( $id ) . $fragment;
					return sprintf(
						'<a class="memex-link" href="%s" data-memex-target="%s">%s</a>',
						esc_url( $url ),
						esc_attr( $target ),
						esc_html( $display )
					);
				}
				$create_url = add_query_arg( 'title', rawurlencode( $target ), home_url( '/memex/new' ) );
				return sprintf(
					'<a class="memex-link memex-link-stub" href="%s" title="%s" data-memex-target="%s">%s</a>',
					esc_url( $create_url ),
					esc_attr__( 'Create this note', 'memex' ),
					esc_attr( $target ),
					esc_html( $display )
				);
			},
			$content
		);
	}

	/**
	 * Notes that link TO the given note.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_backlinks( int $post_id, int $limit = 200 ): array {
		if ( ! $post_id ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => CPT::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'numberposts'      => $limit,
				'meta_key'         => CPT::META_LINKS_TO,
				'meta_value'       => (string) $post_id,
				'post__not_in'     => array( $post_id ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		return $posts ? $posts : array();
	}

	/**
	 * Notes this note links out to (forward links).
	 *
	 * @return \WP_Post[]
	 */
	public static function get_forward_links( int $post_id ): array {
		$ids = get_post_meta( $post_id, CPT::META_LINKS_TO );
		if ( ! $ids ) {
			return array();
		}
		$ids   = array_map( 'intval', array_unique( $ids ) );
		$posts = get_posts(
			array(
				'post_type'   => CPT::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'private' ),
				'post__in'    => $ids,
				'orderby'     => 'post__in',
				'numberposts' => count( $ids ),
			)
		);
		return $posts ? $posts : array();
	}

	/**
	 * All titles that are referenced but don't yet exist (orphan targets).
	 *
	 * @return string[]
	 */
	public static function get_broken_targets( int $limit = 500 ): array {
		$notes = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
			)
		);
		$broken = array();
		foreach ( $notes as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			foreach ( self::extract_links( $post->post_content ) as $link ) {
				$t = self::strip_fragment( $link['target'] );
				if ( ! self::resolve( $t ) ) {
					$broken[ strtolower( $t ) ] = $t;
				}
			}
		}
		return array_values( $broken );
	}
}
