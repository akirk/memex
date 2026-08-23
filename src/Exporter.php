<?php
/**
 * Markdown export.
 *
 * Produces the inverse of the Markdown importer: every note becomes a `.md`
 * file with YAML frontmatter (title, tags, aliases, dates) and a body in the
 * same editor Markdown the in-app editor uses, so `[[Wiki Links]]` survive a
 * round trip. The parent/child hierarchy becomes a folder tree, which the
 * importer turns back into parent notes.
 */

namespace Memex;

class Exporter {
	/**
	 * All exportable notes, stubs excluded (they carry no content of their own).
	 *
	 * @return \WP_Post[]
	 */
	public static function notes(): array {
		return get_posts(
			array(
				'post_type'        => CPT::POST_TYPE,
				'post_status'      => CPT::readable_statuses(),
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => CPT::META_STUB,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => CPT::META_STUB,
						'value' => '1',
						'compare' => '!=',
					),
				),
			)
		);
	}

	/**
	 * Render a single note as a Markdown document with frontmatter.
	 */
	public static function note_to_markdown( \WP_Post $post ): string {
		$front = array(
			'title'   => $post->post_title,
			'created' => get_post_time( 'c', true, $post ),
			'updated' => get_post_modified_time( 'c', true, $post ),
		);

		$tags = get_the_terms( $post->ID, CPT::TAXONOMY );
		if ( is_array( $tags ) && $tags ) {
			$front['tags'] = array_values( array_map( static fn( $t ) => $t->name, $tags ) );
		}
		$aliases = get_post_meta( $post->ID, '_memex_alias' );
		if ( $aliases ) {
			$front['aliases'] = array_values( array_unique( array_map( 'strval', $aliases ) ) );
		}
		$daily = (string) get_post_meta( $post->ID, CPT::META_DAILY, true );
		if ( '' !== $daily ) {
			$front['daily'] = $daily;
		}
		if ( 'publish' !== $post->post_status ) {
			$front['status'] = $post->post_status;
		}

		$body = Content::editor_text_from_html( (string) $post->post_content );

		return self::frontmatter( $front ) . $body . ( '' === $body ? '' : "\n" );
	}

	/**
	 * Relative path inside the export for a note: ancestors become folders.
	 */
	public static function note_path( \WP_Post $post ): string {
		$segments = array( self::filename( $post->post_title, (int) $post->ID ) . '.md' );
		$seen     = array( (int) $post->ID => true );
		$parent   = (int) $post->post_parent;
		while ( $parent && ! isset( $seen[ $parent ] ) ) {
			$seen[ $parent ] = true;
			$p               = get_post( $parent );
			if ( ! $p || CPT::POST_TYPE !== $p->post_type ) {
				break;
			}
			array_unshift( $segments, self::filename( $p->post_title, (int) $p->ID ) );
			$parent = (int) $p->post_parent;
		}
		return implode( '/', $segments );
	}

	/**
	 * Build a ZIP of every note at $zip_path. Returns the number of files written.
	 *
	 * @return int|\WP_Error
	 */
	public static function build_zip( string $zip_path ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return new \WP_Error( 'memex_no_zip', __( 'ZIP support (ext-zip) is not available on this server.', 'memex' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'memex_zip_open', __( 'Could not create the export archive.', 'memex' ) );
		}

		$count = 0;
		$used  = array();
		foreach ( self::notes() as $post ) {
			$path = self::note_path( $post );
			// Two notes with the same sanitized name would collide; disambiguate by ID.
			if ( isset( $used[ strtolower( $path ) ] ) ) {
				$path = preg_replace( '/\.md$/', ' ' . (int) $post->ID . '.md', $path );
			}
			$used[ strtolower( $path ) ] = true;
			$zip->addFromString( $path, self::note_to_markdown( $post ) );
			++$count;
		}
		$zip->close();
		return $count;
	}

	/**
	 * Filesystem-safe name for a note title; falls back to the ID.
	 */
	public static function filename( string $title, int $id ): string {
		$name = trim( (string) preg_replace( '/\s*[\/\\\\:*?"<>|\x00-\x1F]+\s*/', ' - ', $title ) );
		$name = trim( $name, '. ' );
		if ( '' === $name ) {
			$name = 'note-' . $id;
		}
		if ( strlen( $name ) > 120 ) {
			$name = rtrim( substr( $name, 0, 120 ) );
		}
		return $name;
	}

	/**
	 * Emit the simple YAML subset the Markdown importer reads back:
	 * scalars and flat lists.
	 */
	public static function frontmatter( array $data ): string {
		$lines = array( '---' );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( ! $value ) {
					continue;
				}
				$lines[] = $key . ':';
				foreach ( $value as $item ) {
					$lines[] = '  - ' . self::yaml_scalar( (string) $item );
				}
			} else {
				$lines[] = $key . ': ' . self::yaml_scalar( (string) $value );
			}
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n\n";
	}

	private static function yaml_scalar( string $value ): string {
		if ( '' === $value || preg_match( '/[:#\[\]{}&*!|>\'"%@`,\n]|^\s|\s$|^(true|false|null|yes|no|~)$|^[\d.+-]/i', $value ) ) {
			return '"' . addcslashes( $value, "\"\\\n" ) . '"';
		}
		return $value;
	}
}
