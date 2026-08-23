<?php
/**
 * Roam Research JSON importer.
 *
 * Roam's export is a single JSON array of page objects:
 *   [ { "title": "...", "children": [ { "string": "...", "children": [...] } ] }, ... ]
 *
 * Each `string` is a Roam block and may contain `[[Page]]` links, `#tags`,
 * and `((uid))` block references. We emit nested `<ul>` bullet lists so the
 * Roam outline structure is preserved.
 */

namespace Memex\Importer;

use Memex\CPT;

class Roam extends Importer {
	public function source(): string {
		return 'roam';
	}

	public function prepare( string $path, string $work_dir ): array {
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw ) {
			return self::failure( 'Could not read Roam JSON file.' );
		}
		$data = json_decode( $raw, true );
		unset( $raw );
		if ( ! is_array( $data ) ) {
			return self::failure( 'Invalid JSON in Roam export.' );
		}

		// One file per page so a later request can fill one page without
		// re-parsing the whole export.
		$pages = array();
		foreach ( $data as $page ) {
			$title = isset( $page['title'] ) ? trim( (string) $page['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$pages[] = array(
				$this->stash( $work_dir, 'pages', count( $pages ), 'json', (string) wp_json_encode( $page['children'] ?? array() ) ),
				$title,
			);
		}
		unset( $data );

		// Pass 1 creates empty notes for every page so [[links]] resolve; pass 2 fills content.
		$items = array();
		foreach ( array( 1, 2 ) as $pass ) {
			foreach ( $pages as $page ) {
				$items[] = array_merge( array( $pass ), $page );
			}
		}
		return self::prepared( $items, array( 'title_ids' => array() ) );
	}

	public function import_item( $item, array &$state ): int {
		list( $pass, $file, $title ) = $item;
		$key = strtolower( $title );

		if ( 1 === $pass ) {
			$id = $this->upsert( $title, '' );
			if ( $id ) {
				$state['title_ids'][ $key ] = $id;
			} else {
				$state['errors'][] = 'Failed: ' . $title;
			}
			return 0;
		}

		$id = $state['title_ids'][ $key ] ?? 0;
		if ( ! $id ) {
			return 0;
		}
		$children = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $children ) ) {
			$children = array();
		}
		$html = $this->render_children( $children );
		$tags = $this->collect_hashtags( $children );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $html,
			)
		);
		if ( $tags ) {
			$this->set_tags( $id, $tags );
		}
		return $id;
	}

	private function render_children( array $children, int $depth = 0 ): string {
		if ( ! $children ) {
			return '';
		}
		$out = '<ul>';
		foreach ( $children as $block ) {
			$str = isset( $block['string'] ) ? (string) $block['string'] : '';
			$out .= '<li>' . $this->render_block_string( $str );
			if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
				$out .= $this->render_children( $block['children'], $depth + 1 );
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/**
	 * Convert a Roam block string into HTML while preserving `[[links]]`.
	 *
	 * Roam inline syntax we handle:
	 *   **bold**, __italic__, ^^highlight^^, ~~strike~~, `code`,
	 *   {{TODO}} / {{DONE}}.
	 */
	private function render_block_string( string $s ): string {
		$s = esc_html( $s );
		// Re-enable `[[...]]` and `#tag` that got escaped.
		$s = preg_replace_callback(
			'/\[\[([^\[\]]+?)\]\]/',
			static fn( $m ) => '[[' . html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ']]',
			$s
		);
		$s = preg_replace( '/\{\{\s*TODO\s*\}\}/i', '<input type="checkbox" disabled>', $s );
		$s = preg_replace( '/\{\{\s*DONE\s*\}\}/i', '<input type="checkbox" checked disabled>', $s );
		$s = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/__(.+?)__/', '<em>$1</em>', $s );
		$s = preg_replace( '/\^\^(.+?)\^\^/', '<mark>$1</mark>', $s );
		$s = preg_replace( '/~~(.+?)~~/', '<del>$1</del>', $s );
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		return $s;
	}

	private function collect_hashtags( array $children ): array {
		$tags = array();
		foreach ( $children as $block ) {
			$str = isset( $block['string'] ) ? (string) $block['string'] : '';
			if ( preg_match_all( '/(?:^|\s)#([A-Za-z][A-Za-z0-9_\-\/]{1,40})\b/', $str, $m ) ) {
				foreach ( $m[1] as $t ) {
					$tags[] = str_replace( '/', '-', $t );
				}
			}
			if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
				$tags = array_merge( $tags, $this->collect_hashtags( $block['children'] ) );
			}
		}
		return array_values( array_unique( $tags ) );
	}
}
