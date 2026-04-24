<?php
/**
 * Notion export importer.
 *
 * Notion's "Export as HTML (include subpages)" produces a ZIP where:
 *   - each page is a file like `Page Title 1a2b3c4d5e6f....html`
 *   - subpages live in a sibling folder with the same hex-id suffix
 *   - internal links use relative paths ending in the same ` <hex>.html`
 *
 * Notion's "Export as Markdown & CSV" variant replaces `.html` with `.md` —
 * we handle both. The hex ID is stripped to get the original title.
 */

namespace Memex\Importer;

use Memex\CPT;
use Memex\WikiLinks;
use Parsedown;

class Notion extends Importer {
	public function source(): string {
		return 'notion';
	}

	public function import( string $path ): array {
		$root = $this->extract_zip( $path );
		if ( ! $root ) {
			return array(
				'ids'     => array(),
				'errors'  => array( 'Could not extract Notion export ZIP.' ),
				'skipped' => 0,
			);
		}

		$ids      = array();
		$errors   = array();
		$title_map = array();   // relative-path (lower) => imported post ID
		$pass1    = array();    // list of [file, rel, title, raw, is_md]

		// Pass 1: insert empty notes so [[links]] can resolve via title in pass 2.
		foreach ( $this->walk( $root, array( 'html', 'md' ) ) as $file => $rel ) {
			$is_md = preg_match( '/\.md$/i', $rel );
			$title = $this->title_from_filename( basename( $rel ) );
			if ( '' === $title ) {
				continue;
			}
			$parent_id = $this->ensure_parents( $rel, $root, $title_map );
			$id        = $this->upsert(
				$title,
				'',
				array(
					'post_parent'  => $parent_id,
					'_import_path' => $rel,
				)
			);
			if ( ! $id ) {
				$errors[] = 'Failed to create: ' . $rel;
				continue;
			}
			$title_map[ strtolower( $rel ) ] = $id;
			$pass1[] = array( $file, $rel, $title, $id, (bool) $is_md );
		}

		// Pass 2: fill content. Rewrite internal links to `[[Title]]`.
		foreach ( $pass1 as $row ) {
			list( $file, $rel, $title, $id, $is_md ) = $row;
			$raw = @file_get_contents( $file );
			if ( false === $raw ) {
				$errors[] = 'Unreadable: ' . $rel;
				continue;
			}
			$html = $is_md ? $this->md_to_html( $raw ) : $this->clean_notion_html( $raw );
			$html = $this->rewrite_internal_links( $html, $rel, $title_map );

			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => $html,
				)
			);
			$ids[] = $id;
		}

		$this->rrmdir( $root );

		return array(
			'ids'     => $ids,
			'errors'  => $errors,
			'skipped' => 0,
		);
	}

	/**
	 * Notion's filename convention strips page title + space + 32-hex ID.
	 */
	private function title_from_filename( string $basename ): string {
		$base = preg_replace( '/\.(html|md)$/i', '', $basename );
		// ` <32 hex>` suffix.
		$base = preg_replace( '/\s[0-9a-f]{32}$/i', '', $base );
		// Notion URL-encodes special chars — decode them.
		$base = rawurldecode( $base );
		return trim( $base );
	}

	private function ensure_parents( string $rel, string $root, array &$title_map ): int {
		$parent_rel = dirname( $rel );
		$parent_rel = str_replace( '\\', '/', $parent_rel );
		if ( '.' === $parent_rel || '' === $parent_rel ) {
			return 0;
		}
		$parts    = explode( '/', $parent_rel );
		$parent   = 0;
		$trail    = '';
		foreach ( $parts as $part ) {
			$trail = '' === $trail ? $part : $trail . '/' . $part;
			// Notion often writes a parent `Foo <hex>.html` alongside folder `Foo <hex>`.
			// Prefer finding that file if present.
			$title       = $this->title_from_filename( $part );
			$sibling_ids = $title_map[ strtolower( $trail . '.html' ) ]
				?? ( $title_map[ strtolower( $trail . '.md' ) ] ?? null );
			if ( null !== $sibling_ids ) {
				$parent = $sibling_ids;
				continue;
			}
			if ( '' === $title ) {
				continue;
			}
			$id = $this->upsert(
				$title,
				'',
				array(
					'post_parent'  => $parent,
					'_import_path' => $trail,
				)
			);
			$title_map[ strtolower( $trail ) ] = $id;
			$parent = $id;
		}
		return $parent;
	}

	/**
	 * Strip Notion's boilerplate wrapper (header/cover) and keep article body.
	 */
	private function clean_notion_html( string $html ): string {
		if ( ! class_exists( '\\DOMDocument' ) ) {
			return $html;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		// Notion exports use UTF-8; wrap to force the parser into UTF-8 mode.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath  = new \DOMXPath( $dom );
		$nodes  = $xpath->query( '//article | //main | //div[contains(@class,"page-body")]' );
		if ( $nodes && $nodes->length ) {
			$body_html = '';
			foreach ( $nodes as $n ) {
				$body_html .= $dom->saveHTML( $n );
			}
			return $body_html;
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( $body ) {
			$out = '';
			foreach ( $body->childNodes as $c ) {
				$out .= $dom->saveHTML( $c );
			}
			return $out;
		}
		return $html;
	}

	/**
	 * Replace internal `<a href="...Foo 1a2b....html">` with `[[Foo]]`.
	 * Keeps external links intact.
	 */
	private function rewrite_internal_links( string $html, string $from_rel, array $title_map ): string {
		$from_dir = dirname( $from_rel );
		$from_dir = ( '.' === $from_dir ) ? '' : ( str_replace( '\\', '/', $from_dir ) . '/' );

		return preg_replace_callback(
			'/<a\b([^>]*?)href\s*=\s*(["\'])([^"\']+)\2([^>]*)>(.*?)<\/a>/is',
			function ( $m ) use ( $from_dir, $title_map ) {
				$href  = $m[3];
				$inner = $m[5];

				// External or anchor links are passed through.
				if ( preg_match( '#^(https?:|mailto:|tel:|#)#i', $href ) ) {
					return $m[0];
				}

				$href_decoded = rawurldecode( $href );
				$resolved     = $this->resolve_relative( $from_dir, $href_decoded );
				$key          = strtolower( $resolved );

				if ( ! preg_match( '/\.(html|md)$/i', $key ) ) {
					return $m[0];
				}
				if ( ! isset( $title_map[ $key ] ) ) {
					// Target not in this export → still convert to wiki-link by title.
					$title = $this->title_from_filename( basename( $href_decoded ) );
					if ( '' === $title ) {
						return $m[0];
					}
					return '[[' . $title . '|' . wp_strip_all_tags( $inner ) . ']]';
				}
				$post = get_post( $title_map[ $key ] );
				if ( ! $post ) {
					return $m[0];
				}
				$display = wp_strip_all_tags( $inner );
				if ( $display === $post->post_title || '' === $display ) {
					return '[[' . $post->post_title . ']]';
				}
				return '[[' . $post->post_title . '|' . $display . ']]';
			},
			$html
		);
	}

	private function resolve_relative( string $from_dir, string $href ): string {
		if ( '' === $href ) {
			return '';
		}
		if ( 0 === strpos( $href, '/' ) ) {
			return ltrim( $href, '/' );
		}
		$path  = $from_dir . $href;
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}

	private function md_to_html( string $md ): string {
		$parser = new Parsedown();
		return $parser->text( $md );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $dir . '/' . $item;
			is_dir( $full ) ? $this->rrmdir( $full ) : @unlink( $full );
		}
		@rmdir( $dir );
	}
}
