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
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 4096 );
		fclose( $fh );

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

	public function import( string $path ): array {
		$raw = @file_get_contents( $path );
		if ( false === $raw ) {
			return array(
				'ids'     => array(),
				'errors'  => array( 'Could not read Thinkery export.' ),
				'skipped' => 0,
			);
		}

		$trimmed = ltrim( $raw, "\xEF\xBB\xBF \t\r\n" );
		if ( '[' === substr( $trimmed, 0, 1 ) ) {
			$things = json_decode( $trimmed, true );
			if ( ! is_array( $things ) ) {
				return array(
					'ids'     => array(),
					'errors'  => array( 'Invalid JSON in Thinkery export.' ),
					'skipped' => 0,
				);
			}
		} else {
			$things = $this->parse_xml( $raw );
			if ( null === $things ) {
				return array(
					'ids'     => array(),
					'errors'  => array( 'Failed to parse Thinkery export (invalid XML).' ),
					'skipped' => 0,
				);
			}
		}

		$ids     = array();
		$errors  = array();
		$skipped = 0;
		foreach ( $things as $thing ) {
			if ( ! is_array( $thing ) ) {
				++$skipped;
				continue;
			}
			$title = trim( (string) ( $thing['title'] ?? '' ) );
			$url   = trim( (string) ( $thing['url'] ?? '' ) );
			$html  = trim( (string) ( $thing['html'] ?? '' ) );
			if ( '' === $title ) {
				$title = $url;
			}
			if ( '' === $title ) {
				++$skipped;
				continue;
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

			$id = $this->upsert( $title, $html, $args );
			if ( $id ) {
				$ids[] = $id;
				$this->set_tags( $id, $this->parse_tags( (string) ( $thing['tags'] ?? '' ) ) );
			} else {
				++$skipped;
				$errors[] = 'Failed: ' . $title;
			}
		}

		return array(
			'ids'     => $ids,
			'errors'  => $errors,
			'skipped' => $skipped,
		);
	}

	/**
	 * @return array<int,array<string,string>>|null
	 */
	private function parse_xml( string $raw ): ?array {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			return null;
		}
		$things = array();
		foreach ( $xml->thing as $thing ) {
			$row = array();
			foreach ( array( 'title', 'url', 'tags', 'date', 'html' ) as $field ) {
				$row[ $field ] = isset( $thing->{$field} ) ? (string) $thing->{$field} : '';
			}
			$things[] = $row;
		}
		return $things;
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
