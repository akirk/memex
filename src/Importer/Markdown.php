<?php
/**
 * Markdown / Obsidian vault importer.
 *
 * Handles a single `.md` file, or a ZIP of markdown (typical Obsidian vault
 * layout: nested folders, `.md` files, optional `---` YAML frontmatter,
 * `[[wiki-links]]`, `![[attachments]]`).
 *
 * Folder structure is preserved as post hierarchy (each subfolder becomes a
 * parent note, auto-created if absent).
 */

namespace Memex\Importer;

use Memex\CPT;
use Parsedown;

class Markdown extends Importer {
	public function source(): string {
		return 'markdown';
	}

	public function import( string $path ): array {
		$errors = array();
		$ids    = array();

		if ( is_dir( $path ) ) {
			$root = $path;
			$cleanup = false;
		} elseif ( preg_match( '/\.zip$/i', $path ) ) {
			$root = $this->extract_zip( $path );
			if ( ! $root ) {
				return array(
					'ids'     => array(),
					'errors'  => array( 'Could not extract ZIP archive.' ),
					'skipped' => 0,
				);
			}
			$cleanup = true;
		} else {
			$id = $this->import_file( $path, basename( $path ) );
			return array(
				'ids'     => $id ? array( $id ) : array(),
				'errors'  => $id ? array() : array( 'Could not import file.' ),
				'skipped' => 0,
			);
		}

		$folder_parents = array();  // rel-dir => parent post ID
		foreach ( $this->walk( $root, array( 'md', 'markdown', 'txt' ) ) as $file => $rel ) {
			$parent_id = $this->ensure_folder_parents( dirname( $rel ), $folder_parents );
			$id        = $this->import_file( $file, $rel, $parent_id );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$errors[] = 'Failed: ' . $rel;
			}
		}

		if ( $cleanup && $root && is_dir( $root ) ) {
			$this->rrmdir( $root );
		}

		return array(
			'ids'     => $ids,
			'errors'  => $errors,
			'skipped' => 0,
		);
	}

	/**
	 * For relative folder `a/b/c`, ensure a chain of folder-parent notes
	 * exists (creating empty notes for each segment) and return the leaf ID.
	 */
	private function ensure_folder_parents( string $rel_dir, array &$map ): int {
		$rel_dir = trim( str_replace( '\\', '/', $rel_dir ), '/.' );
		if ( '' === $rel_dir ) {
			return 0;
		}
		if ( isset( $map[ $rel_dir ] ) ) {
			return $map[ $rel_dir ];
		}
		$parts  = explode( '/', $rel_dir );
		$parent = 0;
		$trail  = '';
		foreach ( $parts as $part ) {
			$trail = '' === $trail ? $part : $trail . '/' . $part;
			if ( isset( $map[ $trail ] ) ) {
				$parent = $map[ $trail ];
				continue;
			}
			$id = $this->upsert(
				$part,
				'',
				array(
					'post_parent'  => $parent,
					'_import_path' => $trail,
				)
			);
			$map[ $trail ] = $id;
			$parent        = $id;
		}
		return $parent;
	}

	private function import_file( string $file, string $rel, int $parent_id = 0 ): int {
		$raw = @file_get_contents( $file );
		if ( false === $raw ) {
			return 0;
		}
		list( $frontmatter, $body ) = $this->split_frontmatter( $raw );

		$title = '';
		$tags  = array();
		$aliases = array();
		if ( $frontmatter ) {
			if ( isset( $frontmatter['title'] ) ) {
				$title = (string) $frontmatter['title'];
			}
			if ( isset( $frontmatter['tags'] ) ) {
				$tags = $this->normalize_list( $frontmatter['tags'] );
			}
			if ( isset( $frontmatter['aliases'] ) ) {
				$aliases = $this->normalize_list( $frontmatter['aliases'] );
			}
		}
		if ( '' === $title ) {
			// Fall back to first ATX heading, or filename.
			if ( preg_match( '/^#\s+(.+)$/m', $body, $m ) ) {
				$title = trim( $m[1] );
			} else {
				$base  = pathinfo( $rel, PATHINFO_FILENAME );
				$title = $base;
			}
		}

		// Also look for `#tag` markers inline (Obsidian style).
		if ( preg_match_all( '/(?:^|\s)#([A-Za-z][A-Za-z0-9_\-\/]{1,40})\b/', $body, $mm ) ) {
			foreach ( $mm[1] as $t ) {
				$tags[] = str_replace( '/', '-', $t );
			}
		}

		// Convert Obsidian embeds `![[foo.png]]` → ordinary `[[foo.png]]` link
		// (we don't resolve attachments to media library in v1).
		$body = preg_replace( '/!\[\[/', '[[', $body );

		$html = $this->markdown_to_html( $body );

		$post_args = array(
			'_import_path' => $rel,
		);
		if ( $parent_id ) {
			$post_args['post_parent'] = $parent_id;
		}

		$id = $this->upsert( $title, $html, $post_args );
		if ( $id ) {
			$this->set_tags( $id, $tags );
			foreach ( $aliases as $alias ) {
				if ( $alias !== $title ) {
					add_post_meta( $id, '_memex_alias', $alias );
				}
			}
		}
		return $id;
	}

	private function markdown_to_html( string $md ): string {
		// Protect wiki-links from Parsedown's `[...]` link parsing.
		$md = preg_replace_callback(
			'/\[\[([^\[\]]+?)\]\]/',
			static fn( $m ) => '§§MEMEXLINK§§' . base64_encode( $m[1] ) . '§§',
			$md
		);
		$parser = new Parsedown();
		if ( method_exists( $parser, 'setSafeMode' ) ) {
			$parser->setSafeMode( false );
		}
		$html = $parser->text( $md );
		$html = preg_replace_callback(
			'/§§MEMEXLINK§§([A-Za-z0-9+\/=]+)§§/',
			static fn( $m ) => '[[' . base64_decode( $m[1] ) . ']]',
			$html
		);
		return $html;
	}

	/**
	 * Tiny YAML-frontmatter extractor. Returns [frontmatter_array, body].
	 * Handles the subset Obsidian/Notion actually emit: scalars + simple lists.
	 */
	private function split_frontmatter( string $raw ): array {
		if ( 0 !== strncmp( $raw, "---\n", 4 ) && 0 !== strncmp( $raw, "---\r\n", 5 ) ) {
			return array( array(), $raw );
		}
		$after = substr( $raw, strpos( $raw, "\n" ) + 1 );
		if ( ! preg_match( '/^---\s*$/m', $after, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( array(), $raw );
		}
		$end_pos = $m[0][1];
		$yaml    = substr( $after, 0, $end_pos );
		$body    = substr( $after, $end_pos + strlen( $m[0][0] ) );
		$body    = ltrim( $body, "\r\n" );

		$fm        = array();
		$last_key  = '';
		$list_mode = false;
		foreach ( preg_split( '/\r?\n/', $yaml ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( preg_match( '/^([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $mm ) ) {
				$last_key  = $mm[1];
				$value     = trim( $mm[2] );
				$list_mode = '' === $value;
				if ( ! $list_mode ) {
					if ( '[' === substr( $value, 0, 1 ) ) {
						$inner = trim( $value, "[]" );
						$parts = array_map( static fn( $p ) => trim( $p, " \t\"'" ), explode( ',', $inner ) );
						$fm[ $last_key ] = array_values( array_filter( $parts, static fn( $p ) => '' !== $p ) );
					} else {
						$fm[ $last_key ] = trim( $value, "\"'" );
					}
				} else {
					$fm[ $last_key ] = array();
				}
			} elseif ( $list_mode && preg_match( '/^\s*-\s*(.+)$/', $line, $mm ) ) {
				$fm[ $last_key ][] = trim( $mm[1], "\"'" );
			}
		}
		return array( $fm, $body );
	}

	private function normalize_list( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'strval', $value ), static fn( $v ) => '' !== trim( $v ) ) );
		}
		if ( is_string( $value ) && '' !== $value ) {
			return array_map( 'trim', explode( ',', $value ) );
		}
		return array();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $dir . '/' . $item;
			if ( is_dir( $full ) ) {
				$this->rrmdir( $full );
			} else {
				@unlink( $full );
			}
		}
		@rmdir( $dir );
	}
}
