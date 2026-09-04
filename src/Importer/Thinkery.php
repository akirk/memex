<?php
/**
 * Thinkery importer.
 *
 * Thinkery (thinkery.me) exports "things" either as XML:
 *
 *   <thinkery>
 *     <thing><title/><url/><tags/><date/><html/></thing>
 *     ...
 *   </thinkery>
 *
 * or as a JSON array of the same objects:
 *
 *   [ { "title": "...", "url": "...", "tags": "a b c", "date": "<RFC 2822>", "html": "..." }, ... ]
 *
 * A thing is a note (no URL) or a bookmark (URL plus the retrieved page
 * content in `html`). Tags are a space-separated, HTML-escaped list; `@user`
 * entries are sharing markers and are dropped.
 */

namespace Memex\Importer;

class Thinkery extends Importer {
	public function source(): string {
		return 'thinkery';
	}

	/**
	 * Does this file look like a Thinkery export (XML or JSON)?
	 */
	public static function sniff( string $path ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- reads the first bytes of the plugin's own upload in its work directory; WP_Filesystem has no partial read and would load the whole export into memory.
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 4096 );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$head = ltrim( (string) $head, "\xEF\xBB\xBF \t\r\n" );
		if ( preg_match( '/^(<\?xml[^>]*>\s*)?<thinkery\b/i', $head ) ) {
			return true;
		}
		if ( '[' === substr( $head, 0, 1 ) ) {
			// Thinkery objects carry "html"/"url" keys; Roam pages carry "children".
			return (bool) preg_match( '/^\[\s*\{[^{}]*"(html|url)"\s*:/', $head );
		}
		return false;
	}

	public function prepare( string $path, string $work_dir, array &$state, callable $within ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- reads the first bytes of the plugin's own upload in its work directory; WP_Filesystem has no partial read and would load the whole export into memory.
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return self::failure( $state, 'Could not read Thinkery export.' );
		}
		$head = ltrim( (string) fread( $fh, 64 ), "\xEF\xBB\xBF \t\r\n" );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( '[' !== substr( $head, 0, 1 ) ) {
			// XML: stream `<thing>` elements, resuming after earlier calls.
			$skip = (int) ( $state['prepared'] ?? 0 );
			$r    = $this->stream_xml_elements( $path, 'thing', $work_dir, 'things', $skip, $within, $state );
			if ( ! $r['opened'] || ( 0 === $skip && ! $r['items'] && $r['complete'] && $state['errors'] ) ) {
				$state['errors'] = array( 'Failed to parse Thinkery export (invalid XML).' );
				return self::prepared( array() );
			}
			$state['prepared'] = $skip + count( $r['items'] );
			return self::prepared( $r['items'], $r['complete'] );
		}

		$things = json_decode( ltrim( (string) file_get_contents( $path ), "\xEF\xBB\xBF \t\r\n" ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $things ) ) {
			return self::failure( $state, 'Invalid JSON in Thinkery export.' );
		}
		$items = array();
		foreach ( $things as $thing ) {
			if ( ! is_array( $thing ) ) {
				++$state['skipped'];
				continue;
			}
			$items[] = $this->stash( $work_dir, 'things', count( $items ), 'json', (string) wp_json_encode( $thing ) );
		}
		return self::prepared( $items );
	}

	public function import_item( $item, array &$state ): int {
		$thing = $this->read_thing( $item );
		if ( ! is_array( $thing ) ) {
			++$state['skipped'];
			return 0;
		}
		$title = trim( (string) ( $thing['title'] ?? '' ) );
		$url   = trim( (string) ( $thing['url'] ?? '' ) );
		$html  = trim( (string) ( $thing['html'] ?? '' ) );
		if ( '' === $title ) {
			$title = $url;
		}
		if ( '' === $title ) {
			++$state['skipped'];
			return 0;
		}

		if ( '' !== $url || 0 === strpos( $html, '<div id="extract"' ) ) {
			// Retrieved third-party page content: any `[[...]]` in it is the
			// page's own text (wikitext samples, template syntax), not a link
			// to one of our notes. Neutralise it so link resolution doesn't
			// create stubs for it. Renders identically.
			$html = str_replace( array( '[[', ']]' ), array( '&#91;&#91;', '&#93;&#93;' ), $html );
		}
		if ( '' !== $url ) {
			$html = '<p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>' . ( '' !== $html ? "\n" . $html : '' );
		}

		$args = array();
		$date = trim( (string) ( $thing['date'] ?? '' ) );
		if ( '' !== $date ) {
			$ts = strtotime( $date );
			if ( false !== $ts ) {
				$gmt                   = gmdate( 'Y-m-d H:i:s', $ts );
				$args['post_date_gmt'] = $gmt;
				$args['post_date']     = get_date_from_gmt( $gmt );
			}
		}

		// Thinkery titles are labels, not identities: two things with the same
		// title are two things, so never merge into an existing note.
		$id = $this->upsert( $title, $html, $args, false );
		if ( ! $id ) {
			++$state['skipped'];
			$state['errors'][] = 'Failed: ' . $title;
			return 0;
		}
		$this->set_tags( $id, $this->parse_tags( (string) ( $thing['tags'] ?? '' ) ) );
		return $id;
	}

	/**
	 * Read one stashed thing (a `<thing>` element or a JSON object).
	 *
	 * @return array<string,string>|null
	 */
	private function read_thing( string $file ): ?array {
		$raw = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw ) {
			return null;
		}
		if ( '.json' === substr( $file, -5 ) ) {
			$thing = json_decode( $raw, true );
			return is_array( $thing ) ? $thing : null;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			return null;
		}
		$row = array();
		foreach ( array( 'title', 'url', 'tags', 'date', 'html' ) as $field ) {
			$row[ $field ] = isset( $xml->{$field} ) ? (string) $xml->{$field} : '';
		}
		return $row;
	}

	/**
	 * Tags come as "tag1 tag2 tag3" (html-escaped). `@user` marks a share.
	 *
	 * @return string[]
	 */
	private function parse_tags( string $tags ): array {
		$out = array();
		foreach ( preg_split( '/\s+/', html_entity_decode( $tags, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $tag ) {
			$tag = ltrim( trim( $tag ), '#' );
			if ( '' === $tag || '@' === substr( $tag, 0, 1 ) ) {
				continue;
			}
			$out[] = $tag;
		}
		return array_values( array_unique( $out ) );
	}
}
