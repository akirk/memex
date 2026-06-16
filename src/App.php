<?php

namespace Memex;

use WpApp\WpApp;
use WpApp\BaseApp;
use Memex\Importer\Importer;

class App extends BaseApp {
	private $app_registered = false;
	private $runtime_registered = false;

	public function __construct() {
		$this->app = new WpApp(
			$this->get_template_dir(),
			$this->get_url_path(),
			array(
				'require_login'                => true,
				'app_name'                     => 'Memex',
				'my_apps'                      => true,
				'my_apps_icon'                 => plugins_url( 'assets/icon.svg', dirname( __DIR__ ) . '/memex.php' ),
				'show_masterbar_for_anonymous' => false,
				'show_dark_mode_toggle'        => false,
			)
		);
	}

	protected function get_url_path(): string {
		return 'memex';
	}

	protected function get_template_dir(): string {
		return dirname( __DIR__ ) . '/templates';
	}

	protected function setup_database(): void {
		// Memex stores notes as a CPT + tag taxonomy + post_meta. No custom tables.
		// App::init() is itself invoked on `init:5`, so registering the CPT
		// directly here runs within init — which is what WP expects. Adding it
		// via `add_action('init', ...)` from inside an init callback queues a
		// callback that never fires (PHP's foreach-over-priority-5 has already
		// been snapshotted).
		CPT::register();
	}

	protected function setup_routes(): void {
		$this->app->route( '' );
		$this->app->route( 'note/{slug}' );
		$this->app->route( 'edit/{slug}' );
		$this->app->route( 'new' );
		$this->app->route( 'search' );
		$this->app->route( 'daily' );
		$this->app->route( 'daily/{date}' );
		$this->app->route( 'graph' );
		$this->app->route( 'backlinks/{slug}' );
		$this->app->route( 'tag/{slug}' );
		$this->app->route( 'orphans' );
		$this->app->route( 'broken' );
		$this->app->route( 'import' );
		$this->app->route( 'quick-capture' );
		$this->app->route( 'reminders' );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( '', __( 'All Notes', 'memex' ), home_url( '/memex/' ) );
		$this->app->add_menu_item( 'daily', __( 'Today', 'memex' ), home_url( '/memex/daily' ) );
		$this->app->add_menu_item( 'reminders', __( 'Reminders', 'memex' ), home_url( '/memex/reminders' ) );
		$this->app->add_menu_item( 'search', __( 'Search', 'memex' ), home_url( '/memex/search' ) );
		$this->app->add_menu_item( 'graph', __( 'Graph', 'memex' ), home_url( '/memex/graph' ) );
		$this->app->add_menu_item( 'orphans', __( 'Orphans', 'memex' ), home_url( '/memex/orphans' ) );
		$this->app->add_menu_item( 'broken', __( 'Broken Links', 'memex' ), home_url( '/memex/broken' ) );
		$this->app->add_menu_item( 'import', __( 'Import', 'memex' ), home_url( '/memex/import' ) );
	}

	public function register_app() {
		if ( $this->app_registered ) {
			return;
		}

		$this->setup_routes();
		$this->app->init();

		$this->app_registered = true;
	}

	public function init() {
		if ( $this->runtime_registered ) {
			return;
		}

		$this->runtime_registered = true;

		$this->register_app();
		$this->setup_database();
		$this->setup_menu();

		do_action( 'base_app_initialized', $this );

		Links::register();
		Reminder::register();
		AI::register();

		// Keep direct wp-admin edits compatible with note links.
		add_filter(
			'wp_rest_search_handlers',
			static function ( $handlers ) {
				foreach ( $handlers as $i => $h ) {
					if ( $h instanceof \WP_REST_Post_Search_Handler && ! $h instanceof NoteSearch ) {
						$handlers[ $i ] = new NoteSearch();
						break;
					}
				}
				return $handlers;
			}
		);

		// Route memex_note permalinks through the WpApp (/memex/note/{slug}).
		add_filter( 'post_type_link', array( CPT::class, 'filter_permalink' ), 10, 2 );

		// Enqueue app assets when a memex template is about to render.
		add_action( 'wp_app_before_render', array( $this, 'enqueue_assets' ) );
		// If a user opens wp-admin directly, keep the block editor note-aware.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_post_memex_quick_capture', array( $this, 'handle_quick_capture' ) );
		add_action( 'admin_post_memex_create_note', array( $this, 'handle_create_note' ) );
		add_action( 'admin_post_memex_update_note', array( $this, 'handle_update_note' ) );
		add_action( 'admin_post_memex_import', array( $this, 'handle_import' ) );
		add_action( 'wp_ajax_memex_title_suggest', array( $this, 'ajax_title_suggest' ) );
	}

	public function enqueue_block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$base = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
		wp_enqueue_script(
			'memex-block-editor',
			$base . 'assets/memex-editor.js',
			array(
				'wp-data',
				'wp-dom-ready',
				'wp-api-fetch',
				'wp-url',
				'wp-i18n',
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
			),
			MEMEX_VERSION,
			true
		);
	}

	public function enqueue_assets() {
		$base   = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
		$suffix = '?v=' . MEMEX_VERSION;
		if ( function_exists( 'wp_app_enqueue_style' ) ) {
			wp_app_enqueue_style( 'memex', $base . 'assets/memex.css' . $suffix );
			wp_app_enqueue_script( 'memex-overtype', $base . 'assets/vendor/overtype/overtype.min.js' . $suffix, array(), false, true );
			wp_app_enqueue_script( 'memex', $base . 'assets/memex.js' . $suffix, array(), false, true );
		} else {
			// Fallback if called outside an app request.
			wp_enqueue_style( 'memex', $base . 'assets/memex.css', array(), MEMEX_VERSION );
			wp_enqueue_script( 'memex-overtype', $base . 'assets/vendor/overtype/overtype.min.js', array(), MEMEX_VERSION, true );
			wp_enqueue_script( 'memex', $base . 'assets/memex.js', array( 'memex-overtype' ), MEMEX_VERSION, true );
		}
	}

	public function activate(): void {
		CPT::register();
		Reminder::activate();
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		Reminder::deactivate();
		flush_rewrite_rules();
	}

	/* ─── Form handlers ─── */

	public function handle_quick_capture() {
		check_admin_referer( 'memex_quick_capture' );
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'memex' ) );
		}
		$raw = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';
		if ( '' === $raw ) {
			wp_safe_redirect( home_url( '/memex/' ) );
			exit;
		}

		$note = DailyNote::get_or_create( DailyNote::today() );
		if ( ! $note ) {
			wp_die( esc_html__( 'Could not create daily note.', 'memex' ) );
		}

		$timestamp = wp_date( 'H:i' );
		$new_block = self::plain_text_to_paragraph_blocks( $raw, $timestamp );
		$existing  = trim( $note->post_content );
		$appended  = ( '' === $existing ? '' : $existing . "\n\n" ) . $new_block;
		wp_update_post(
			array(
				'ID'           => $note->ID,
				'post_content' => $appended,
			)
		);
		wp_safe_redirect( CPT::url( $note->ID ) );
		exit;
	}

	public function handle_create_note() {
		check_admin_referer( 'memex_create_note' );
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'memex' ) );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_safe_redirect( home_url( '/memex/' ) );
			exit;
		}
		$existing = Links::resolve( $title );
		if ( $existing ) {
			wp_safe_redirect( CPT::url( $existing ) );
			exit;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_die( esc_html__( 'Could not create note.', 'memex' ) );
		}
		wp_safe_redirect( home_url( '/memex/edit/' . rawurlencode( get_post_field( 'post_name', $id ) ?: (string) $id ) ) );
		exit;
	}

	public function handle_update_note() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'memex_update_note_' . $id );

		$post = get_post( $id );
		if ( ! $post || CPT::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Note not found.', 'memex' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memex' ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$text  = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';
		if ( '' === $title ) {
			wp_safe_redirect( add_query_arg( 'error', 'missing-title', home_url( '/memex/edit/' . rawurlencode( $post->post_name ?: (string) $id ) ) ) );
			exit;
		}

		$content = self::markdown_to_html( $text );
		// The in-app editor does not manage reminder blocks; keep existing
		// reminder records intact instead of reconciling against plain text.
		remove_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25 );
		$result  = wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			),
			true
		);
		add_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25, 2 );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Could not save note.', 'memex' ) );
		}
		delete_post_meta( $id, CPT::META_STUB );
		wp_safe_redirect( CPT::url( $id ) );
		exit;
	}

	public function handle_import() {
		check_admin_referer( 'memex_import' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memex' ) );
		}
		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'error', 'no-file', home_url( '/memex/import' ) ) );
			exit;
		}

		$file_tmp = $_FILES['import_file']['tmp_name'];
		$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( $_FILES['import_file']['name'] ) : '';

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'auto';
		$importer = 'auto' === $type
			? Importer::detect( $file_tmp, $file_name )
			: $this->importer_from_type( $type );
		if ( ! $importer ) {
			wp_safe_redirect( add_query_arg( 'error', 'unknown-type', home_url( '/memex/import' ) ) );
			exit;
		}

		Importer::begin();
		$result = $importer->import( $file_tmp );
		Importer::end( $result['ids'] );

		$count = count( $result['ids'] );
		set_transient(
			'memex_import_result_' . get_current_user_id(),
			array(
				'source' => $importer->source(),
				'count'  => $count,
				'errors' => $result['errors'],
			),
			300
		);
		wp_safe_redirect( add_query_arg( 'imported', $count, home_url( '/memex/import' ) ) );
		exit;
	}

	private function importer_from_type( string $type ): ?Importer {
		switch ( $type ) {
			case 'markdown':
			case 'obsidian':
				return new Importer\Markdown();
			case 'notion':
				return new Importer\Notion();
			case 'evernote':
				return new Importer\Evernote();
			case 'roam':
				return new Importer\Roam();
		}
		return null;
	}

	/**
	 * Turn a plain-text textarea value into a valid WordPress paragraph-block sequence.
	 *
	 * WordPress parses `<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->` natively;
	 * bare `<p>` can be treated as a single classic block. This helper emits one
	 * `wp:paragraph` per blank-line-separated paragraph, preserves single-line breaks
	 * as `<br>`, and HTML-escapes user input. Quick capture can pass a timestamp
	 * to prefix the first block.
	 */
	private static function plain_text_to_paragraph_blocks( string $text, string $timestamp = '' ): string {
		$text  = str_replace( "\r\n", "\n", $text );
		$paras = preg_split( '/\n\s*\n/', $text );
		$out   = array();
		$first = true;
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$lines = array_map( 'esc_html', explode( "\n", $p ) );
			$inner = implode( '<br>', $lines );
			if ( $first && '' !== $timestamp ) {
				$inner = '<strong>' . esc_html( $timestamp ) . '</strong> · ' . $inner;
				$first = false;
			}
			$out[] = "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $out );
	}

	private static function markdown_to_html( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = preg_replace_callback(
			'/\[\[([^\[\]]+?)\]\]/',
			static function ( $m ) {
				return 'MEMEXLINKTOKEN' . base64_encode( $m[1] ) . 'ENDMEMEXLINKTOKEN';
			},
			$markdown
		);

		$parser = new \Parsedown();
		if ( method_exists( $parser, 'setSafeMode' ) ) {
			$parser->setSafeMode( false );
		}
		$html = $parser->text( $markdown );
		$html = preg_replace_callback(
			'/MEMEXLINKTOKEN([A-Za-z0-9+\/=]+)ENDMEMEXLINKTOKEN/',
			static function ( $m ) {
				return '[[' . base64_decode( $m[1] ) . ']]';
			},
			$html
		);
		return (string) $html;
	}

	public static function content_to_editor_text( string $content ): string {
		$content = Links::internal_anchors_to_shorthand( $content );
		$markdown = self::html_to_editor_markdown( $content );
		if ( '' !== $markdown ) {
			return $markdown;
		}
		$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
		$content = preg_replace( '/<\/(p|div|li|h[1-6])\s*>/i', "\n\n", $content );
		$content = preg_replace( '/<hr\b[^>]*>/i', "\n\n---\n\n", $content );
		$text    = wp_strip_all_tags( $content, true );
		$text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text    = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	private static function html_to_editor_markdown( string $content ): string {
		$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return '';
		}

		$blocks  = array();
		$offset  = 0;
		$pattern = '/<(h[1-6]|p|ul|ol|blockquote|pre)\b([^>]*)>(.*?)<\/\1>|<hr\b[^>]*>/is';
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$raw_match = $m[0][0];
				$position  = $m[0][1];
				self::append_editor_html_fragment( $blocks, substr( $content, $offset, $position - $offset ) );
				$offset = $position + strlen( $raw_match );

				$tag = isset( $m[1][0] ) ? strtolower( $m[1][0] ) : '';
				if ( '' === $tag && 0 === stripos( $raw_match, '<hr' ) ) {
					$blocks[] = '---';
					continue;
				}
				$inner = $m[3][0] ?? '';
				if ( preg_match( '/^h([1-6])$/', $tag, $h ) ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = str_repeat( '#', (int) $h[1] ) . ' ' . $text;
					}
					continue;
				}
				if ( 'p' === $tag ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = $text;
					}
					continue;
				}
				if ( 'ul' === $tag || 'ol' === $tag ) {
					$items = array();
					if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $inner, $li_matches ) ) {
						foreach ( $li_matches[1] as $i => $li ) {
							$text = self::html_inline_to_markdown( $li );
							if ( '' !== $text ) {
								$items[] = ( 'ol' === $tag ? ( $i + 1 ) . '. ' : '- ' ) . $text;
							}
						}
					}
					if ( $items ) {
						$blocks[] = implode( "\n", $items );
					}
					continue;
				}
				if ( 'blockquote' === $tag ) {
					$text = self::html_inline_to_markdown( $inner );
					if ( '' !== $text ) {
						$blocks[] = '> ' . str_replace( "\n", "\n> ", $text );
					}
					continue;
				}
				if ( 'pre' === $tag ) {
					$code = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
					$blocks[] = "```\n" . trim( $code, "\n" ) . "\n```";
				}
			}
		}
		self::append_editor_html_fragment( $blocks, substr( $content, $offset ) );

		$markdown = trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n\n", $blocks ) ) );
		$markdown = preg_replace( '/^([ \t]*)[–—][ \t]+/mu', '$1- ', $markdown );
		return $markdown;
	}

	private static function append_editor_html_fragment( array &$blocks, string $html ): void {
		$html = trim( $html );
		if ( '' !== $html ) {
			$blocks[] = $html;
		}
	}

	private static function html_inline_to_markdown( string $html ): string {
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '`$1`', $html );
		$html = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $html );
		$html = preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			static function ( $m ) {
				if ( 0 === strpos( wp_strip_all_tags( $m[2] ), '[[' ) ) {
					return wp_strip_all_tags( $m[2] );
				}
				if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $m[1], $href ) ) {
					return wp_strip_all_tags( $m[2] );
				}
				return '[' . wp_strip_all_tags( $m[2] ) . '](' . $href[2] . ')';
			},
			$html
		);
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( $text );
	}

	public function ajax_title_suggest() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'auth' ), 401 );
		}
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$results = Search::title_suggest( $q, 10 );
		wp_send_json_success( $results );
	}

}
