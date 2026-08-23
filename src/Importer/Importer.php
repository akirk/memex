<?php
/**
 * Import dispatcher.
 *
 * An import is a list of work items processed in chunks so big files do not
 * tie up a single request (see `Job`). Importers implement two steps:
 *
 *   1. `prepare()` inspects the uploaded file and materialises it as a list
 *      of JSON-serialisable work items inside a work directory (extracted ZIP
 *      entries, one file per note, ...). Nothing is inserted here. It gets a
 *      time budget and may return early with `complete => false`; it is then
 *      called again with the same state and continues where it stopped.
 *   2. `import_item()` processes one item. Importers that need two passes
 *      (create every note first so `[[links]]` resolve by title, then fill in
 *      content) simply emit the items twice with a pass marker.
 *
 * Importers stage `[[Title]]` shorthand in content because they don't yet know
 * which titles will end up existing. `Job` detaches the save_post link sync
 * while items run and, at the end, rewrites the shorthand of every imported
 * note into anchors (creating stubs for truly-missing targets) so nothing but
 * HTML is stored.
 */

namespace Memex\Importer;

use Memex\CPT;
use Memex\Links;

abstract class Importer {
	/** Human-readable source name (obsidian/notion/evernote/roam/thinkery/markdown). */
	abstract public function source(): string;

	/**
	 * Inspect the file and produce work items.
	 *
	 * The work directory exists, is private to this import, and is deleted when
	 * the job finishes. Items must survive a JSON round-trip. `$state` starts
	 * as `initial_state()` and persists between calls and into `import_item()`;
	 * `$within()` turns false when the time budget is used up — stop and return
	 * `complete => false`, the next call continues. The items returned by each
	 * call are appended.
	 *
	 * @return array{items:array,complete:bool}
	 */
	abstract public function prepare( string $path, string $work_dir, array &$state, callable $within ): array;

	/**
	 * Process one work item.
	 *
	 * `$state` is whatever `prepare()` returned plus anything previous items
	 * stored; it persists across requests. Errors go to `$state['errors'][]`,
	 * silently skipped input increments `$state['skipped']`.
	 *
	 * @param mixed $item
	 * @return int Post ID of an imported note (collected for link resolution), or 0.
	 */
	abstract public function import_item( $item, array &$state ): int;

	/**
	 * Shape every importer's state starts with.
	 */
	public static function initial_state(): array {
		return array(
			'errors'  => array(),
			'skipped' => 0,
		);
	}

	/**
	 * Abort preparation: record the error and report completion with no items.
	 *
	 * @return array{items:array,complete:bool}
	 */
	protected static function failure( array &$state, string $message ): array {
		$state['errors'][] = $message;
		return self::prepared( array() );
	}

	/**
	 * @return array{items:array,complete:bool}
	 */
	protected static function prepared( array $items, bool $complete = true ): array {
		return array(
			'items'    => array_values( $items ),
			'complete' => $complete,
		);
	}

	/**
	 * Importer for a user-facing type slug, or null.
	 */
	public static function from_type( string $type ): ?Importer {
		switch ( $type ) {
			case 'markdown':
			case 'obsidian':
				return new Markdown();
			case 'notion':
				return new Notion();
			case 'evernote':
				return new Evernote();
			case 'roam':
				return new Roam();
			case 'thinkery':
				return new Thinkery();
		}
		return null;
	}

	/**
	 * Sniff a file path and return the right importer, or null.
	 */
	public static function detect( string $path, string $original_name = '' ): ?Importer {
		$name_lc = strtolower( $original_name ?: basename( $path ) );
		$ext     = pathinfo( $name_lc, PATHINFO_EXTENSION );

		if ( 'enex' === $ext || self::sniff_xml_root( $path, 'en-export' ) ) {
			return new Evernote();
		}
		if ( Thinkery::sniff( $path ) ) {
			return new Thinkery();
		}
		if ( 'json' === $ext ) {
			return new Roam();
		}
		if ( 'md' === $ext || 'markdown' === $ext || 'txt' === $ext ) {
			return new Markdown();
		}
		if ( 'zip' === $ext ) {
			return self::sniff_zip( $path );
		}
		return null;
	}

	private static function sniff_xml_root( string $path, string $root ): bool {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 4096 );
		fclose( $fh );
		return false !== stripos( $head, '<' . $root );
	}

	/**
	 * ZIP sniffing:
	 *   - Notion HTML export: files end in ` <hex-id>.html`
	 *   - Notion Markdown export: files end in ` <hex-id>.md`
	 *   - Otherwise treat as Obsidian/Markdown vault
	 */
	private static function sniff_zip( string $path ): ?Importer {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return null;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}
		$notion = false;
		$hex_id = '/ [0-9a-f]{32}\.(html|md)$/i';
		$count  = min( $zip->numFiles, 50 );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( $name && preg_match( $hex_id, $name ) ) {
				$notion = true;
				break;
			}
		}
		$zip->close();
		return $notion ? new Notion() : new Markdown();
	}

	/**
	 * Detach save_post link sync while items are inserted. Must be called in
	 * every request that runs `import_item()`, or each insert triggers a full
	 * resolve pass.
	 */
	public static function begin(): void {
		remove_action( 'save_post_' . CPT::POST_TYPE, array( Links::class, 'on_save' ), 20 );
	}

	/**
	 * Re-attach the save_post hook.
	 */
	public static function end(): void {
		add_action( 'save_post_' . CPT::POST_TYPE, array( Links::class, 'on_save' ), 20, 2 );
	}

	/**
	 * Turn the `[[Title]]` shorthand staged in one imported note into real
	 * anchors (creating stubs for missing targets) and record its backlinks.
	 * Run once every note exists, so titles resolve to the imported notes.
	 */
	public static function resolve_links( int $id ): void {
		$p = get_post( $id );
		if ( ! $p ) {
			return;
		}
		$content = Links::shorthand_to_html( (string) $p->post_content );
		if ( $content !== $p->post_content ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => $content,
				)
			);
		}
		Links::sync_links_from_content( $id, $content );
	}

	/**
	 * Insert-or-update a note by title. Existing notes with the same title are
	 * promoted from stub → real note; non-stub existing notes are left alone
	 * (we don't want to overwrite user edits on re-import) and the imported
	 * content is appended below a separator. Importers whose titles are not
	 * unique identities (bookmark labels, say) pass `$merge = false` to get a
	 * separate note instead.
	 */
	protected function upsert( string $title, string $content_html, array $args = array(), bool $merge = true ): int {
		$title = trim( $title );
		if ( '' === $title ) {
			return 0;
		}
		$defaults = array(
			'post_type'    => CPT::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content_html,
		);
		$data = array_merge( $defaults, $args );

		$existing_id = Links::resolve( $title );
		if ( $existing_id ) {
			$existing = get_post( $existing_id );
			$is_stub  = (bool) get_post_meta( $existing_id, CPT::META_STUB, true );
			$empty    = $existing && '' === trim( wp_strip_all_tags( $existing->post_content ) );
			if ( $is_stub || $empty ) {
				$data['ID'] = $existing_id;
				// Stubs are drafts with a zero GMT date; without this flag
				// wp_update_post() discards the supplied date and uses "now".
				if ( isset( $data['post_date'] ) ) {
					$data['edit_date'] = true;
				}
				wp_update_post( $data );
				delete_post_meta( $existing_id, CPT::META_STUB );
				update_post_meta( $existing_id, CPT::META_IMPORT_SOURCE, $this->source() );
				if ( isset( $args['_import_path'] ) ) {
					update_post_meta( $existing_id, CPT::META_IMPORT_PATH, $args['_import_path'] );
				}
				return $existing_id;
			}
			if ( ! $merge ) {
				return $this->insert( $data, $args );
			}
			// Real content exists; append imported variant below a separator.
			$merged = $existing->post_content . "\n\n<hr class=\"memex-import-merge\" />\n\n" . $content_html;
			wp_update_post(
				array(
					'ID'           => $existing_id,
					'post_content' => $merged,
				)
			);
			return $existing_id;
		}

		return $this->insert( $data, $args );
	}

	private function insert( array $data, array $args ): int {
		unset( $data['_import_path'] );
		$id = wp_insert_post( $data, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, CPT::META_IMPORT_SOURCE, $this->source() );
		if ( isset( $args['_import_path'] ) ) {
			update_post_meta( $id, CPT::META_IMPORT_PATH, $args['_import_path'] );
		}
		return (int) $id;
	}

	/**
	 * Set tags on a note (create-as-needed).
	 *
	 * @param string[] $tags
	 */
	protected function set_tags( int $post_id, array $tags ): void {
		$tags = array_values(
			array_filter(
				array_map( 'trim', $tags ),
				static fn( $t ) => '' !== $t
			)
		);
		if ( ! $tags ) {
			return;
		}
		wp_set_object_terms( $post_id, $tags, CPT::TAXONOMY, false );
	}

	/**
	 * Extract a ZIP into `$dest`. Returns false on failure.
	 */
	protected function extract_zip( string $zip_path, string $dest ): bool {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}
		wp_mkdir_p( $dest );
		$ok = $zip->extractTo( $dest );
		$zip->close();
		return (bool) $ok;
	}

	/**
	 * Write one work item's payload to `<work_dir>/<subdir>/<index>.<ext>` and
	 * return the file path. Used by single-file formats (ENEX, JSON) so that a
	 * later request can process one note without re-parsing the whole export.
	 */
	protected function stash( string $work_dir, string $subdir, int $index, string $ext, string $payload ): string {
		$dir = trailingslashit( $work_dir ) . $subdir;
		wp_mkdir_p( $dir );
		$file = $dir . '/' . $index . '.' . $ext;
		file_put_contents( $file, $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $file;
	}

	/**
	 * Stream `<$element>` children of an XML file, stashing each one as its own
	 * file under `$subdir`, starting after the first `$skip` matches and stopping
	 * when `$within()` turns false. Returns the stashed paths and whether the end
	 * of the document was reached; `$state['errors']` gets a note if the XML was
	 * invalid.
	 *
	 * @return array{items:string[],complete:bool,opened:bool}
	 */
	protected function stream_xml_elements( string $path, string $element, string $work_dir, string $subdir, int $skip, callable $within, array &$state ): array {
		$items  = array();
		$result = array(
			'items'    => array(),
			'complete' => true,
			'opened'   => false,
		);
		if ( ! class_exists( '\\XMLReader' ) ) {
			$state['errors'][] = 'XMLReader is not available.';
			return $result;
		}

		$prev   = libxml_use_internal_errors( true );
		$reader = new \XMLReader();
		$opened = $reader->open( $path, null, LIBXML_NONET );
		if ( $opened ) {
			$seen = 0;
			$more = $reader->read();
			while ( $more ) {
				if ( \XMLReader::ELEMENT !== $reader->nodeType || $element !== $reader->name ) {
					$more = $reader->read();
					continue;
				}
				if ( $seen >= $skip ) {
					if ( ! $within() ) {
						$result['complete'] = false;
						break;
					}
					$xml = $reader->readOuterXml();
					if ( '' === $xml ) {
						break;
					}
					$items[] = $this->stash( $work_dir, $subdir, $seen, 'xml', $xml );
				}
				++$seen;
				$more = $reader->next(); // Skip the subtree.
			}
			$reader->close();
		}
		$parse_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$result['items']  = $items;
		$result['opened'] = (bool) $opened;
		if ( $parse_errors && $result['complete'] ) {
			$state['errors'][] = 'The file contained invalid XML; entries after the error were skipped.';
		}
		return $result;
	}

	/**
	 * Walk a directory, yielding file paths that match the extension list.
	 *
	 * @param string[] $exts
	 * @return \Generator<string,string>  path => relative path
	 */
	protected function walk( string $dir, array $exts ): \Generator {
		$exts  = array_map( 'strtolower', $exts );
		$rii   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$files = array();
		foreach ( $rii as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), $exts, true ) ) {
				continue;
			}
			$files[] = $file->getPathname();
		}
		// Deterministic order: parents (shorter paths) before children.
		sort( $files, SORT_STRING );
		foreach ( $files as $path ) {
			$rel = ltrim( str_replace( $dir, '', $path ), '/\\' );
			yield $path => $rel;
		}
	}

	/**
	 * Recursively delete a directory.
	 */
	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $dir . '/' . $item;
			if ( is_dir( $full ) && ! is_link( $full ) ) {
				self::rrmdir( $full );
			} else {
				@unlink( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
