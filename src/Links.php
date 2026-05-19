<?php
/**
 * [[Wiki-link]] parsing, stub creation, backlink tracking, and rendering.
 *
 * The Memex app is the primary authoring surface. Users can type:
 *   [[Target]]
 *   [[Target|Display text]]
 *   [[Target#Heading]]
 *
 * Gutenberg-authored/imported HTML anchors are still understood so older notes
 * continue to participate in backlinks and graph views.
 *
 * Storage:
 *   `_memex_links_to` post_meta — one row per outgoing target post ID. Lets
 *   us answer "what links to X?" with a single meta_query.
 */

namespace Memex;

class Links {
	const LINK_REGEX = '/\[\[([^\[\]\|]+?)(?:\|([^\[\]]+?))?\]\]/';

	public static function register() {
		add_action( 'save_post_' . CPT::POST_TYPE, array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_filter( 'the_content', array( __CLASS__, 'render_links' ), 9 );
		add_filter( 'the_content', array( __CLASS__, 'style_internal_links' ), 10 );
	}

	/**
	 * Sync forward-link meta rows from saved note content.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}
		self::sync_links_from_content( (int) $post_id, (string) $post->post_content );
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
	 * Rewrite `_memex_links_to` rows for a note from wiki links and HTML hrefs.
	 *
	 * @return int[] target post IDs that were stored.
	 */
	public static function sync_links_from_content( int $post_id, string $content ): array {
		delete_post_meta( $post_id, CPT::META_LINKS_TO );
		$stored = array();

		foreach ( self::extract_links( $content ) as $link ) {
			$tid = self::resolve_or_create( self::strip_fragment( $link['target'] ) );
			if ( $tid && $tid !== $post_id && ! in_array( $tid, $stored, true ) ) {
				$stored[] = $tid;
			}
		}

		foreach ( self::extract_hrefs( $content ) as $href ) {
			$tid = self::resolve_href( $href );
			if ( $tid && $tid !== $post_id && ! in_array( $tid, $stored, true ) ) {
				$stored[] = $tid;
			}
		}
		foreach ( $stored as $tid ) {
			add_post_meta( $post_id, CPT::META_LINKS_TO, $tid );
		}
		return $stored;
	}

	public static function sync_backlinks( int $post_id, string $content ): array {
		return self::sync_links_from_content( $post_id, $content );
	}

	/**
	 * Pull every `href` value out of `<a>` tags.
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
	 * Resolve a memex-note-looking URL to a note ID, or 0 if it doesn't point
	 * at one of our notes. Accepts absolute, relative, and `?p=N` URLs.
	 */
	public static function resolve_href( string $href ): int {
		$href = trim( $href );
		if ( '' === $href ) {
			return 0;
		}
		$frag_pos = strpos( $href, '#' );
		if ( false !== $frag_pos ) {
			$href = substr( $href, 0, $frag_pos );
		}

		$parts = wp_parse_url( $href );
		if ( ! empty( $parts['host'] ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $parts['host'] !== $home_host ) {
				return 0;
			}
		}
		$path  = $parts['path'] ?? $href;
		$query = $parts['query'] ?? '';

		if ( preg_match( '#/memex/note/([^/]+)/?$#', $path, $m ) ) {
			$slug    = rawurldecode( $m[1] );
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
		if ( '' === $title ) {
			return 0;
		}

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
	 * Find an existing note by title, otherwise create a stub. Stubs let an
	 * importer reference a note that hasn't been created yet in the same pass.
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
		return (string) preg_replace_callback(
			self::LINK_REGEX,
			static function ( $m ) {
				$raw_target = trim( $m[1] );
				if ( '' === $raw_target ) {
					return $m[0];
				}
				$display  = isset( $m[2] ) && '' !== trim( $m[2] ) ? trim( $m[2] ) : $raw_target;
				$target   = self::strip_fragment( $raw_target );
				$fragment = '';
				$hash     = strpos( $raw_target, '#' );
				if ( false !== $hash ) {
					$fragment = '#' . sanitize_title( substr( $raw_target, $hash + 1 ) );
				}
				$id = self::resolve( $target );
				if ( $id ) {
					$url     = CPT::url( $id ) . $fragment;
					$classes = (bool) get_post_meta( $id, CPT::META_STUB, true ) ? 'memex-link memex-link-stub' : 'memex-link';
					return sprintf(
						'<a class="%s" href="%s" data-memex-target="%s">%s</a>',
						esc_attr( $classes ),
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
	 * Convert `[[Target]]` / `[[Target|Display]]` shorthand to `<a href>` HTML.
	 *
	 * Kept for compatibility with older migration paths. Normal app editing
	 * now keeps wiki-link text in stored note content.
	 */
	public static function shorthand_to_html( string $content ): string {
		if ( false === strpos( $content, '[[' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			self::LINK_REGEX,
			static function ( $m ) {
				$raw_target = trim( $m[1] );
				if ( '' === $raw_target ) {
					return $m[0];
				}
				$display = isset( $m[2] ) && '' !== trim( $m[2] ) ? trim( $m[2] ) : $raw_target;

				$fragment = '';
				$target   = $raw_target;
				$hash_pos = strpos( $raw_target, '#' );
				if ( false !== $hash_pos ) {
					$fragment = '#' . sanitize_title( substr( $raw_target, $hash_pos + 1 ) );
					$target   = trim( substr( $raw_target, 0, $hash_pos ) );
				}

				$id = self::resolve_or_create( $target );
				if ( ! $id ) {
					return $m[0];
				}
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( CPT::url( $id ) . $fragment ),
					esc_html( $display )
				);
			},
			$content
		);
	}

	/**
	 * Convert stored internal anchors back to wiki-link syntax for the in-app editor.
	 */
	public static function internal_anchors_to_shorthand( string $content ): string {
		if ( false === stripos( $content, '<a ' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			static function ( $m ) {
				$attrs = $m[1];
				if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $h ) ) {
					return $m[0];
				}
				$id = self::resolve_href( $h[2] );
				if ( ! $id ) {
					return $m[0];
				}
				$title   = get_the_title( $id );
				$display = html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
				$display = trim( preg_replace( '/\s+/', ' ', $display ) );
				if ( '' === $display || 0 === strcasecmp( $display, $title ) ) {
					return '[[' . $title . ']]';
				}
				return '[[' . $title . '|' . $display . ']]';
			},
			$content
		);
	}

	/**
	 * Display-time filter: tag anchors that resolve to memex notes with a
	 * `.memex-link` class (and `.memex-link-stub` when the target is a stub),
	 * so notes-internal links pick up the in-app styling.
	 */
	public static function style_internal_links( $content ) {
		if ( ! is_string( $content ) || false === stripos( $content, '<a ' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( $m ) {
				$attrs = $m[1];
				if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $h ) ) {
					return $m[0];
				}
				$id = self::resolve_href( $h[2] );
				if ( ! $id ) {
					return $m[0];
				}
				$is_stub = (bool) get_post_meta( $id, CPT::META_STUB, true );
				$classes = $is_stub ? 'memex-link memex-link-stub' : 'memex-link';
				if ( preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $c ) ) {
					if ( false !== strpos( $c[2], 'memex-link' ) ) {
						return $m[0];
					}
					$new   = $c[2] . ' ' . $classes;
					$attrs = str_replace( $c[0], 'class=' . $c[1] . $new . $c[1], $attrs );
				} else {
					$attrs .= ' class="' . $classes . '"';
				}
				return '<a' . $attrs . '>';
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
}
