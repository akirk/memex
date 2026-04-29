<?php

namespace Memex;

use WpApp\WpApp;
use WpApp\BaseApp;
use Memex\Importer\Importer;

class App extends BaseApp {
	public function __construct() {
		$this->app = new WpApp(
			$this->get_template_dir(),
			$this->get_url_path(),
			array(
				'require_login'                => true,
				'app_name'                     => __( 'Memex', 'memex' ),
				'my_apps'                      => true,
				'my_apps_icon'                 => plugins_url( 'assets/icon.svg', dirname( __DIR__ ) . '/memex.php' ),
				'show_masterbar_for_anonymous' => false,
				'show_dark_mode_toggle'        => true,
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
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( '', __( 'All Notes', 'memex' ), home_url( '/memex/' ) );
		$this->app->add_menu_item( 'daily', __( 'Today', 'memex' ), home_url( '/memex/daily' ) );
		$this->app->add_menu_item( 'search', __( 'Search', 'memex' ), home_url( '/memex/search' ) );
		$this->app->add_menu_item( 'graph', __( 'Graph', 'memex' ), home_url( '/memex/graph' ) );
		$this->app->add_menu_item( 'orphans', __( 'Orphans', 'memex' ), home_url( '/memex/orphans' ) );
		$this->app->add_menu_item( 'broken', __( 'Broken Links', 'memex' ), home_url( '/memex/broken' ) );
		$this->app->add_menu_item( 'import', __( 'Import', 'memex' ), home_url( '/memex/import' ) );
	}

	public function init() {
		// BaseApp::init runs setup_database → routes → menu → WpApp::init.
		parent::init();

		Links::register();

		// Make memex_note findable in Gutenberg's link picker.
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

		// Route memex_note permalinks through the WpApp (/memex/note/{slug})
		// so Gutenberg inserts the app URL when linking to a note.
		add_filter( 'post_type_link', array( CPT::class, 'filter_permalink' ), 10, 2 );

		// Enqueue app assets when a memex template is about to render.
		add_action( 'wp_app_before_render', array( $this, 'enqueue_assets' ) );
		// Override Gutenberg's link picker on memex_note edit screens so the
		// "Create new" button creates a memex_note and suggestions include notes.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_post_memex_quick_capture', array( $this, 'handle_quick_capture' ) );
		add_action( 'admin_post_memex_create_note', array( $this, 'handle_create_note' ) );
		add_action( 'admin_post_memex_import', array( $this, 'handle_import' ) );
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
			array( 'wp-data', 'wp-dom-ready', 'wp-api-fetch', 'wp-url', 'wp-i18n' ),
			MEMEX_VERSION,
			true
		);
	}

	public function enqueue_assets() {
		$base   = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
		$suffix = '?v=' . MEMEX_VERSION;
		if ( function_exists( 'wp_app_enqueue_style' ) ) {
			wp_app_enqueue_style( 'memex', $base . 'assets/memex.css' . $suffix );
			wp_app_enqueue_script( 'memex', $base . 'assets/memex.js' . $suffix, array(), false, true );
		} else {
			// Fallback if called outside an app request.
			wp_enqueue_style( 'memex', $base . 'assets/memex.css', array(), MEMEX_VERSION );
			wp_enqueue_script( 'memex', $base . 'assets/memex.js', array(), MEMEX_VERSION, true );
		}
	}

	public function activate(): void {
		CPT::register();
		flush_rewrite_rules();
	}

	public function deactivate(): void {
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
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
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
	 * Turn a plain-text textarea value into a valid Gutenberg paragraph-block sequence.
	 *
	 * Gutenberg parses `<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->` natively;
	 * bare `<p>` gets collapsed into a single "Classic" block. This helper emits one
	 * `wp:paragraph` per blank-line-separated paragraph, preserves single-line breaks
	 * as `<br>`, HTML-escapes user input, and prepends `HH:MM · ` to the first block.
	 */
	private static function plain_text_to_paragraph_blocks( string $text, string $timestamp ): string {
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
			if ( $first ) {
				$inner = '<strong>' . esc_html( $timestamp ) . '</strong> · ' . $inner;
				$first = false;
			}
			$out[] = "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $out );
	}

}
