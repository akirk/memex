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

	public function import( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array(
				'ids'     => array(),
				'errors'  => array( 'ENEX file not readable.' ),
				'skipped' => 0,
			);
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_file( $path, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			return array(
				'ids'     => array(),
				'errors'  => array( 'Failed to parse ENEX (invalid XML).' ),
				'skipped' => 0,
			);
		}

		$ids     = array();
		$errors  = array();
		$skipped = 0;
		foreach ( $xml->note as $note ) {
			$title = trim( (string) $note->title );
			if ( '' === $title ) {
				$title = __( 'Untitled note', 'memex' );
			}

			$content_xml = (string) $note->content;
			$html        = $this->enml_to_html( $content_xml );

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
			if ( $id ) {
				$ids[] = $id;
				$this->set_tags( $id, $tags );
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
