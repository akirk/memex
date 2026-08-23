<?php
/**
 * Evernote ENEX importer.
 *
 * ENEX is a single XML file with `<en-export>` root and one or more `<note>`
 * children. Each `<note>` has `<title>`, `<content>` (CDATA-wrapped ENML,
 * which is XHTML-ish), `<tag>` repeats, and `<resource>` attachments (we
 * ignore attachments in v1).
 */

namespace Memex\Importer;

use Memex\CPT;

class Evernote extends Importer {
	public function source(): string {
		return 'evernote';
	}

	/**
	 * Stream the export and stash every `<note>` as its own file, so a
	 * multi-hundred-megabyte ENEX is never held in memory at once. Resumes
	 * after the notes stashed by earlier calls.
	 */
	public function prepare( string $path, string $work_dir, array &$state, callable $within ): array {
		if ( ! is_readable( $path ) ) {
			return self::failure( $state, 'ENEX file not readable.' );
		}
		$skip = (int) ( $state['prepared'] ?? 0 );
		$r    = $this->stream_xml_elements( $path, 'note', $work_dir, 'notes', $skip, $within, $state );
		if ( ! $r['opened'] || ( 0 === $skip && ! $r['items'] && $r['complete'] && $state['errors'] ) ) {
			$state['errors'] = array( 'Failed to parse ENEX (invalid XML).' );
			return self::prepared( array() );
		}
		$state['prepared'] = $skip + count( $r['items'] );
		return self::prepared( $r['items'], $r['complete'] );
	}

	public function import_item( $item, array &$state ): int {
		$prev = libxml_use_internal_errors( true );
		$note = simplexml_load_file( $item, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $note ) {
			++$state['skipped'];
			$state['errors'][] = 'Failed to parse note ' . basename( $item, '.xml' );
			return 0;
		}

		$title = trim( (string) $note->title );
		if ( '' === $title ) {
			$title = __( 'Untitled note', 'memex' );
		}

		$html = $this->enml_to_html( (string) $note->content );

		$tags = array();
		foreach ( $note->tag as $t ) {
			$tag = trim( (string) $t );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		$args = array();
		if ( isset( $note->created ) ) {
			$gmt = $this->evernote_time_to_mysql( (string) $note->created );
			if ( $gmt ) {
				$args['post_date_gmt'] = $gmt;
				$args['post_date']     = get_date_from_gmt( $gmt );
			}
		}
		if ( isset( $note->updated ) ) {
			$gmt = $this->evernote_time_to_mysql( (string) $note->updated );
			if ( $gmt ) {
				$args['post_modified_gmt'] = $gmt;
				$args['post_modified']     = get_date_from_gmt( $gmt );
			}
		}

		$id = $this->upsert( $title, $html, $args );
		if ( ! $id ) {
			++$state['skipped'];
			$state['errors'][] = 'Failed: ' . $title;
			return 0;
		}
		$this->set_tags( $id, $tags );
		return $id;
	}

	/**
	 * Strip ENML wrapper and produce plain HTML.
	 *
	 * ENML is:
	 *   <?xml version="1.0" encoding="UTF-8"?>
	 *   <!DOCTYPE en-note SYSTEM "...">
	 *   <en-note>...</en-note>
	 */
	private function enml_to_html( string $enml ): string {
		$enml = preg_replace( '/<\?xml[^?]*\?>/i', '', $enml );
		$enml = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $enml );
		if ( preg_match( '/<en-note[^>]*>(.*)<\/en-note>/is', $enml, $m ) ) {
			$body = $m[1];
		} else {
			$body = $enml;
		}
		// Media/attachments become placeholders.
		$body = preg_replace( '/<en-media[^>]*\/?>/i', '<em>[attachment]</em>', $body );
		// Evernote "todo" list item.
		$body = preg_replace( '/<en-todo\s+checked="true"\s*\/?>/i', '<input type="checkbox" checked disabled> ', $body );
		$body = preg_replace( '/<en-todo[^>]*\/?>/i', '<input type="checkbox" disabled> ', $body );
		return trim( (string) $body );
	}

	private function evernote_time_to_mysql( string $ts ): ?string {
		// Evernote format: 20240305T133000Z
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $ts, $m ) ) {
			return null;
		}
		return sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] );
	}
}
