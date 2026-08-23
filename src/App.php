<?php

namespace Memex;

use WpApp\WpApp;
use WpApp\BaseApp;
use Memex\Importer\Importer;
use Memex\Importer\Job;

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
		$this->app->route( 'tags' );
		$this->app->route( 'tag/{slug}' );
		$this->app->route( 'orphans' );
		$this->app->route( 'broken' );
		$this->app->route( 'import' );
		$this->app->route( 'export' );
		$this->app->route( 'quick-capture' );
		$this->app->route( 'reminders' );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( '', __( 'All Notes', 'memex' ), home_url( '/memex/' ) );
		$this->app->add_menu_item( 'daily', __( 'Today', 'memex' ), home_url( '/memex/daily' ) );
		$this->app->add_menu_item( 'reminders', __( 'Reminders', 'memex' ), home_url( '/memex/reminders' ) );
		$this->app->add_menu_item( 'search', __( 'Search', 'memex' ), home_url( '/memex/search' ) );
		$this->app->add_menu_item( 'graph', __( 'Graph', 'memex' ), home_url( '/memex/graph' ) );
		$this->app->add_menu_item( 'tags', __( 'Tags', 'memex' ), home_url( '/memex/tags' ) );
		$this->app->add_menu_item( 'orphans', __( 'Orphans', 'memex' ), home_url( '/memex/orphans' ) );
		$this->app->add_menu_item( 'broken', __( 'Broken Links', 'memex' ), home_url( '/memex/broken' ) );
		$this->app->add_menu_item( 'import', __( 'Import', 'memex' ), home_url( '/memex/import' ) );
		$this->app->add_menu_item( 'export', __( 'Export', 'memex' ), home_url( '/memex/export' ) );
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
		add_action( 'admin_post_memex_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_memex_export_note', array( $this, 'handle_export_note' ) );
		add_action( 'wp_ajax_memex_title_suggest', array( $this, 'ajax_title_suggest' ) );
		add_action( 'wp_ajax_memex_toggle_task', array( $this, 'ajax_toggle_task' ) );
		add_action( 'wp_ajax_memex_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_memex_import_step', array( $this, 'ajax_import_step' ) );
		add_action( 'wp_ajax_memex_import_cancel', array( $this, 'ajax_import_cancel' ) );
		add_action( Job::CRON_HOOK, array( Job::class, 'cleanup_stale' ) );
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
			wp_app_enqueue_script( 'memex', $base . 'assets/memex.js' . $suffix, array( 'memex-overtype' ), false, true );
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
		Job::unschedule_cleanup();
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
				'post_parent' => isset( $_POST['parent'] ) ? self::valid_parent( (int) $_POST['parent'] ) : 0,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_die( esc_html__( 'Could not create note.', 'memex' ) );
		}
		wp_safe_redirect( home_url( '/memex/edit/' . rawurlencode( get_post_field( 'post_name', $id ) ?: (string) $id ) ) );
		exit;
	}

	/**
	 * Sanitize a requested parent: must be a readable note and must not be the
	 * note itself or one of its descendants (which would create a cycle).
	 *
	 * @return int Parent ID, or 0 for top level.
	 */
	private static function valid_parent( int $parent, int $self = 0 ): int {
		if ( ! $parent || ! CPT::is_note( $parent ) ) {
			return 0;
		}
		if ( $self && in_array( $self, CPT::ancestor_ids( $parent ), true ) ) {
			return 0;
		}
		return $parent;
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
		$update  = array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		);
		if ( isset( $_POST['parent'] ) ) {
			$update['post_parent'] = self::valid_parent( (int) $_POST['parent'], $id );
		}
		// The in-app editor does not manage reminder blocks; keep existing
		// reminder records intact instead of reconciling against plain text.
		remove_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25 );
		$result = wp_update_post( wp_slash( $update ), true );
		add_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25, 2 );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Could not save note.', 'memex' ) );
		}
		delete_post_meta( $id, CPT::META_STUB );
		wp_safe_redirect( CPT::url( $id ) );
		exit;
	}

	/* ─── Chunked import (driven by assets/memex.js) ─── */

	/**
	 * Receive the upload, detect the importer and create a job. The browser
	 * then calls `memex_import_step` until the job reports `done`.
	 */
	public function ajax_import_start() {
		check_ajax_referer( 'memex_import' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'memex' ) ), 403 );
		}
		if ( empty( $_FILES['import_file']['tmp_name'] ) || ! empty( $_FILES['import_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a file to import.', 'memex' ) ), 400 );
		}

		$file_tmp  = $_FILES['import_file']['tmp_name'];
		$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( $_FILES['import_file']['name'] ) : '';

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'auto';
		$importer = 'auto' === $type
			? Importer::detect( $file_tmp, $file_name )
			: Importer::from_type( $type );
		if ( ! $importer ) {
			wp_send_json_error( array( 'message' => __( 'Could not detect the file type. Please pick one explicitly.', 'memex' ) ), 400 );
		}

		$job = Job::create( get_current_user_id(), $importer, $file_tmp, $file_name );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error(
				array(
					'code'    => $job->get_error_code(),
					'message' => $job->get_error_message(),
					'status'  => $job->get_error_data(),
				),
				409
			);
		}
		wp_send_json_success( $job->status() );
	}

	public function ajax_import_step() {
		$job = $this->import_job_from_request();
		// The browser can ask for shorter steps when its connection keeps timing out.
		$seconds = isset( $_POST['budget'] ) ? (float) $_POST['budget'] : Job::TIME_BUDGET;
		$seconds = max( 1.0, min( 10.0, $seconds ) );
		/**
		 * Filter how many seconds of work one import step may do.
		 *
		 * @param float $seconds Requested budget, already clamped to 1–10.
		 */
		$seconds = (float) apply_filters( 'memex_import_step_seconds', $seconds );
		wp_send_json_success( $job->step( $seconds ) );
	}

	public function ajax_import_cancel() {
		$job = $this->import_job_from_request();
		$job->cancel();
		wp_send_json_success( array( 'job' => $job->id() ) );
	}

	private function import_job_from_request(): Job {
		check_ajax_referer( 'memex_import' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'memex' ) ), 403 );
		}
		$job = Job::load( isset( $_POST['job'] ) ? (string) wp_unslash( $_POST['job'] ) : '' );
		if ( ! $job || $job->owner() !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Import not found. It may have finished or been cancelled.', 'memex' ) ), 404 );
		}
		return $job;
	}

	public function handle_export() {
		check_admin_referer( 'memex_export' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memex' ) );
		}

		$zip_path = wp_tempnam( 'memex-export.zip' );
		$count    = Exporter::build_zip( $zip_path );
		if ( is_wp_error( $count ) ) {
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_safe_redirect( add_query_arg( 'error', $count->get_error_code(), home_url( '/memex/export' ) ) );
			exit;
		}

		$filename = sanitize_file_name( get_bloginfo( 'name' ) ?: 'memex' ) . '-notes-' . wp_date( 'Y-m-d' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		exit;
	}

	public function handle_export_note() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'memex_export_note_' . $id );

		$post = get_post( $id );
		if ( ! $post || CPT::POST_TYPE !== $post->post_type || ! current_user_can( 'read_post', $id ) ) {
			wp_die( esc_html__( 'Note not found.', 'memex' ) );
		}

		$markdown = Exporter::note_to_markdown( $post );
		$filename = Exporter::filename( $post->post_title, (int) $post->ID ) . '.md';
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
		return Content::plain_text_to_blocks( $text, $timestamp );
	}

	private static function markdown_to_html( string $markdown ): string {
		return Content::markdown_to_html( $markdown );
	}

	public static function content_to_editor_text( string $content ): string {
		return Content::editor_text_from_html( $content );
	}

	public function ajax_title_suggest() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'auth' ), 401 );
		}
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$results = Search::title_suggest( $q, 10 );
		wp_send_json_success( $results );
	}

	/**
	 * Toggle a task list checkbox from the note view.
	 *
	 * The checkbox is addressed by its position among all checkboxes in the
	 * stored content, which matches DOM order in the rendered note.
	 */
	public function ajax_toggle_task() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_ajax_referer( 'memex_toggle_task_' . $id );

		$post = get_post( $id );
		if ( ! $post || CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'not-found' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$index   = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$checked = ! empty( $_POST['checked'] ) && '0' !== $_POST['checked'] && 'false' !== $_POST['checked'];
		$content = Content::set_task_checked( (string) $post->post_content, $index, $checked );
		if ( null === $content ) {
			wp_send_json_error( array( 'message' => 'no-such-task' ), 409 );
		}
		if ( $content === $post->post_content ) {
			wp_send_json_success( array( 'checked' => $checked ) );
		}

		remove_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25 );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $content,
				)
			),
			true
		);
		add_action( 'save_post_' . CPT::POST_TYPE, array( Reminder::class, 'on_save_note' ), 25, 2 );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( array( 'checked' => $checked ) );
	}

}
