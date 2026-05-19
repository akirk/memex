<?php
/**
 * Import dispatcher.
 *
 * Flow:
 *   1. `begin()` detaches the save_post link sync so we can insert notes
 *      fast without an N^2 resolve pass.
 *   2. Run a specific importer — it returns a batch of inserted post IDs.
 *      Importers stage `[[Title]]` shorthand in content because they don't yet
 *      know which titles will end up existing.
 *   3. `end()` re-attaches the sync, then walks each imported note, resolving
 *      shorthand links into backlink rows and creating stubs for truly-missing
 *      targets. Imported wiki-link text remains editable in the Memex app.
 */

namespace Memex\Importer;

use Memex\CPT;
use Memex\Links;

abstract class Importer {
	/** Human-readable source name (obsidian/notion/evernote/roam/markdown). */
	abstract public function source(): string;

	/**
	 * Run the import for the given path.
	 *
	 * @return array{ids:int[],errors:string[],skipped:int}
	 */
	abstract public function import( string $path ): array;

	/**
	 * Sniff a file path and return the right importer, or null.
	 */
	public static function detect( string $path, string $original_name = '' ): ?Importer {
		$name_lc = strtolower( $original_name ?: basename( $path ) );
		$ext     = pathinfo( $name_lc, PATHINFO_EXTENSION );

		if ( 'enex' === $ext || self::sniff_xml_root( $path, 'en-export' ) ) {
			return new Evernote();
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
	 * Detach save_post link sync for the duration of the import.
	 */
	public static function begin(): void {
		remove_action( 'save_post_' . CPT::POST_TYPE, array( Links::class, 'on_save' ), 20 );
	}

	/**
	 * Re-attach the save_post hook, then for each imported note: resolve
	 * `[[Title]]` shorthand into backlink rows, creating stubs for missing
	 * targets now that all imported titles exist.
	 *
	 * @param int[] $ids
	 */
	public static function end( array $ids ): void {
		add_action( 'save_post_' . CPT::POST_TYPE, array( Links::class, 'on_save' ), 20, 2 );
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			Links::sync_links_from_content( (int) $id, (string) $p->post_content );
		}
	}

	/**
	 * Insert-or-update a note by title. Existing notes with the same title are
	 * promoted from stub → real note; non-stub existing notes are left alone
	 * (we don't want to overwrite user edits on re-import).
	 */
	protected function upsert( string $title, string $content_html, array $args = array() ): int {
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
				wp_update_post( $data );
				delete_post_meta( $existing_id, CPT::META_STUB );
				update_post_meta( $existing_id, CPT::META_IMPORT_SOURCE, $this->source() );
				if ( isset( $args['_import_path'] ) ) {
					update_post_meta( $existing_id, CPT::META_IMPORT_PATH, $args['_import_path'] );
				}
				return $existing_id;
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
	 * Extract a ZIP to a temp dir. Returns the dir path, or null on failure.
	 */
	protected function extract_zip( string $zip_path ): ?string {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return null;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'memex-import/' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $dir );
		$zip->extractTo( $dir );
		$zip->close();
		return $dir;
	}

	/**
	 * Walk a directory, yielding file paths that match the extension list.
	 *
	 * @param string[] $exts
	 * @return \Generator<string,string>  path => relative path
	 */
	protected function walk( string $dir, array $exts ): \Generator {
		$exts = array_map( 'strtolower', $exts );
		$rii  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $rii as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), $exts, true ) ) {
				continue;
			}
			$path = $file->getPathname();
			$rel  = ltrim( str_replace( $dir, '', $path ), '/\\' );
			yield $path => $rel;
		}
	}
}
