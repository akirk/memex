<?php
/**
 * AI Assistant and WordPress Abilities API integration.
 */

namespace Memex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI {
	const CATEGORY = 'memex';

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_filter( 'ai_assistant_ability_domains', array( __CLASS__, 'register_ability_domains' ) );
		add_filter( 'ai_assistant_ability_instructions', array( __CLASS__, 'ability_instructions' ), 10, 4 );
		add_filter( 'ai_assistant_welcome_tips', array( __CLASS__, 'register_welcome_tips' ), 10, 2 );
	}

	public static function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Memex', 'memex' ),
				'description' => __( 'Search, read, save, and capture Memex notes and reminders.', 'memex' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_ability(
			'search-notes',
			array(
				'label'               => __( 'Search Memex Notes', 'memex' ),
				'description'         => __( 'Returns matching or recent notes with id, title, excerpt, tags, and view_url.', 'memex' ),
				'input_schema'        => self::search_notes_input_schema(),
				'output_schema'       => self::notes_list_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_search_notes' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Find/list notes. Use id with get-note.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'get-note',
			array(
				'label'               => __( 'Get Memex Note', 'memex' ),
				'description'         => __( 'Returns one note by id, slug, title, or daily_date with content, links, backlinks, tags, and view_url.', 'memex' ),
				'input_schema'        => self::note_lookup_input_schema(),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_get_note' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Read a note. id wins over slug, title, and daily_date.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'save-note',
			array(
				'label'               => __( 'Save Memex Note', 'memex' ),
				'description'         => __( 'Creates or updates a note and returns its content, tags, links, backlinks, and view_url.', 'memex' ),
				'input_schema'        => self::save_note_input_schema(),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_save_note' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Save note content/tags. id or slug updates; title updates if found or creates.', 'memex' ),
					false,
					true,
					false
				),
			)
		);

		self::register_ability(
			'capture',
			array(
				'label'               => __( 'Capture to Memex', 'memex' ),
				'description'         => __( 'Appends content to today\'s daily note and returns the updated note with view_url.', 'memex' ),
				'input_schema'        => self::capture_input_schema(),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_capture' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Append quick thoughts to today. Use save-note to replace a note body.', 'memex' ),
					false,
					false,
					false
				),
			)
		);

		self::register_ability(
			'list-reminders',
			array(
				'label'               => __( 'List Memex Reminders', 'memex' ),
				'description'         => __( 'Returns reminders grouped by overdue, upcoming, and done with ids and source note links.', 'memex' ),
				'input_schema'        => self::list_reminders_input_schema(),
				'output_schema'       => self::reminders_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_list_reminders' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'List due reminders. Use id with save-reminder.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'save-reminder',
			array(
				'label'               => __( 'Save Memex Reminder', 'memex' ),
				'description'         => __( 'Creates, updates, or completes a reminder and returns its id, due time, status, and source note.', 'memex' ),
				'input_schema'        => self::save_reminder_input_schema(),
				'output_schema'       => self::reminder_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_save_reminder' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Create/update reminders. status=done completes one. Resolve relative due times first.', 'memex' ),
					false,
					true,
					false
				),
			)
		);
	}

	public static function register_ability_domains( array $domains ): array {
		$domains[ self::CATEGORY ] = 'Memex notes and reminders. Notes use content as editable plain text; [[Title]] creates wiki links. save-note replaces content; capture appends to today. Use view_url when presenting notes.';
		return $domains;
	}

	public static function register_welcome_tips( array $tips, array $context = array() ): array {
		$memex_tips = array(
			__( 'Ask me to find related notes, summarize a note, or trace backlinks in Memex.', 'memex' ),
			__( 'Ask me to capture an idea, save a note, or set a reminder from a note.', 'memex' ),
		);

		$existing = isset( $tips[ self::CATEGORY ] ) ? $tips[ self::CATEGORY ] : array();
		$existing = is_array( $existing ) ? $existing : array( $existing );
		$tips[ self::CATEGORY ] = array_merge( $existing, $memex_tips );

		return $tips;
	}

	public static function ability_instructions( string $instructions, string $ability_id, $args, $result ): string {
		if ( 0 !== strpos( $ability_id, self::CATEGORY . '/' ) || empty( $result ) ) {
			return $instructions;
		}

		if ( 'memex/search-notes' === $ability_id ) {
			return __( 'Present Memex search results as a concise list with note titles linked by view_url. Use ids for follow-up get-note calls, and say when no matching notes were found.', 'memex' );
		}

		if ( 'memex/get-note' === $ability_id ) {
			return __( 'When presenting a Memex note, link the title with view_url, summarize only the relevant content, and mention backlinks, outgoing wiki links, tags, or reminders when they help answer the user.', 'memex' );
		}

		if ( in_array( $ability_id, array( 'memex/save-note', 'memex/capture' ), true ) ) {
			return __( 'Confirm the Memex note title and link it with view_url. If the result indicates an existing note was reused, say that instead of saying a new note was created.', 'memex' );
		}

		if ( 'memex/list-reminders' === $ability_id ) {
			return __( 'Present Memex reminders grouped by overdue, upcoming, and done. Include due_local and source note links when present.', 'memex' );
		}

		if ( 'memex/save-reminder' === $ability_id ) {
			return __( 'Confirm the reminder title, due_local time, status, and source note link when present.', 'memex' );
		}

		return $instructions;
	}

	public static function can_read(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	public static function can_edit(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public static function ability_search_notes( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$q     = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		$limit = self::limit( isset( $input['limit'] ) ? (int) $input['limit'] : 10, 1, 50 );

		if ( '' === trim( $q ) ) {
			$posts = get_posts(
				array(
					'post_type'      => CPT::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => $limit,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
		} else {
			$posts = Search::query( $q, $limit );
		}

		return array(
			'count' => count( $posts ),
			'notes' => array_map( array( __CLASS__, 'note_summary' ), $posts ),
		);
	}

	public static function ability_get_note( $input = array() ) {
		$post = self::resolve_note_from_input( is_array( $input ) ? $input : array() );
		if ( ! $post ) {
			return new \WP_Error( 'memex_note_not_found', __( 'Memex note not found.', 'memex' ) );
		}
		return self::note_payload( $post, true );
	}

	public static function ability_save_note( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$post = null;
		if ( ! empty( $input['id'] ) || ! empty( $input['slug'] ) ) {
			$post = self::resolve_note_from_input( $input );
			if ( ! $post ) {
				return new \WP_Error( 'memex_note_not_found', __( 'Memex note not found.', 'memex' ) );
			}
		} elseif ( ! empty( $input['title'] ) ) {
			$post = self::resolve_note_from_input( $input );
		}

		if ( $post ) {
			$input['id'] = (int) $post->ID;
			$payload     = self::update_note_from_input( $input );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
			$payload['created'] = false;
			$payload['existing'] = true;
			return $payload;
		}

		if ( empty( $input['title'] ) ) {
			return new \WP_Error( 'memex_missing_title', __( 'A note title is required when creating a note.', 'memex' ) );
		}

		return self::create_note_from_input( $input );
	}

	private static function create_note_from_input( array $input ) {
		$title   = sanitize_text_field( (string) $input['title'] );
		$content = isset( $input['content'] ) ? self::plain_text_to_paragraph_blocks( (string) $input['content'] ) : '';
		$id      = wp_insert_post(
			array(
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::set_note_tags( (int) $id, isset( $input['tags'] ) && is_array( $input['tags'] ) ? $input['tags'] : array() );

		$payload = self::note_payload( get_post( $id ), true );
		$payload['created'] = true;
		$payload['existing'] = false;
		return $payload;
	}

	private static function update_note_from_input( array $input ) {
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post  = $id ? get_post( $id ) : null;
		if ( ! $post || CPT::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'memex_note_not_found', __( 'Memex note not found.', 'memex' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'memex_forbidden', __( 'You are not allowed to edit this note.', 'memex' ) );
		}

		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $input ) ) {
			$title = sanitize_text_field( (string) $input['title'] );
			if ( '' === $title ) {
				return new \WP_Error( 'memex_missing_title', __( 'A note title cannot be empty.', 'memex' ) );
			}
			$update['post_title'] = $title;
		}
		if ( array_key_exists( 'content', $input ) ) {
			$update['post_content'] = self::plain_text_to_paragraph_blocks( (string) $input['content'] );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			delete_post_meta( $id, CPT::META_STUB );
		}

		if ( array_key_exists( 'tags', $input ) && is_array( $input['tags'] ) ) {
			self::set_note_tags( $id, $input['tags'] );
		}

		$payload = self::note_payload( get_post( $id ), true );
		$payload['updated'] = true;
		return $payload;
	}

	public static function ability_capture( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$raw   = isset( $input['content'] ) ? trim( (string) $input['content'] ) : '';
		if ( '' === $raw ) {
			return new \WP_Error( 'memex_missing_content', __( 'Capture content is required.', 'memex' ) );
		}

		$note = DailyNote::get_or_create( DailyNote::today() );
		if ( ! $note ) {
			return new \WP_Error( 'memex_daily_note_create_failed', __( 'Could not create today\'s daily note.', 'memex' ) );
		}

		$timestamp = wp_date( 'H:i' );
		$new_block = self::plain_text_to_paragraph_blocks( $raw, $timestamp );
		$existing  = trim( (string) $note->post_content );
		$result    = wp_update_post(
			array(
				'ID'           => $note->ID,
				'post_content' => ( '' === $existing ? '' : $existing . "\n\n" ) . $new_block,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = self::note_payload( get_post( $note->ID ), true );
		$payload['appended'] = true;
		return $payload;
	}

	public static function ability_list_reminders( $input = array() ): array {
		$input        = is_array( $input ) ? $input : array();
		$include_done = ! array_key_exists( 'include_done', $input ) || (bool) $input['include_done'];
		$groups       = Reminder::for_current_user();

		return array(
			'url'      => home_url( '/memex/reminders' ),
			'overdue'  => array_map( array( __CLASS__, 'reminder_payload' ), $groups['overdue'] ),
			'upcoming' => array_map( array( __CLASS__, 'reminder_payload' ), $groups['upcoming'] ),
			'done'     => $include_done ? array_map( array( __CLASS__, 'reminder_payload' ), $groups['done'] ) : array(),
		);
	}

	public static function ability_save_reminder( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$reminder = $id ? get_post( $id ) : null;

		if ( $id && ( ! $reminder || Reminder::POST_TYPE !== $reminder->post_type ) ) {
			return new \WP_Error( 'memex_reminder_not_found', __( 'Reminder not found.', 'memex' ) );
		}
		if ( $reminder && (int) $reminder->post_author !== get_current_user_id() && ! current_user_can( 'edit_others_pages' ) ) {
			return new \WP_Error( 'memex_forbidden', __( 'You are not allowed to edit this reminder.', 'memex' ) );
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		$status = 'done' === $status ? 'private' : ( 'pending' === $status ? 'publish' : '' );
		$due_gmt = '';
		if ( array_key_exists( 'due', $input ) ) {
			$due = sanitize_text_field( (string) $input['due'] );
			if ( '' === $due ) {
				return new \WP_Error( 'memex_invalid_due_date', __( 'Could not parse reminder due date.', 'memex' ) );
			}
			$due_gmt = false !== strpos( $due, 'T' ) || preg_match( '/[zZ]|[+\-]\d{2}:?\d{2}$/', $due )
				? Reminder::iso_to_gmt( $due )
				: Reminder::local_input_to_gmt( $due );
			if ( '' === $due_gmt ) {
				return new \WP_Error( 'memex_invalid_due_date', __( 'Could not parse reminder due date.', 'memex' ) );
			}
		}

		$update = array();
		if ( $id ) {
			$update['ID'] = $id;
		} else {
			$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
			$due   = isset( $input['due'] ) ? sanitize_text_field( (string) $input['due'] ) : '';
			if ( '' === $title || '' === $due ) {
				return new \WP_Error( 'memex_missing_reminder_fields', __( 'Reminder title and due date are required.', 'memex' ) );
			}
			$update = array(
				'post_type'   => Reminder::POST_TYPE,
				'post_status' => $status ?: 'publish',
				'post_author' => get_current_user_id(),
			);
		}

		if ( array_key_exists( 'title', $input ) ) {
			$title = sanitize_text_field( (string) $input['title'] );
			if ( '' === $title ) {
				return new \WP_Error( 'memex_missing_reminder_title', __( 'Reminder title cannot be empty.', 'memex' ) );
			}
			$update['post_title'] = $title;
		}

		if ( $status ) {
			$update['post_status'] = $status;
		}

		if ( array_key_exists( 'note_id', $input ) ) {
			$parent = absint( $input['note_id'] );
			if ( $parent && ! CPT::is_note( $parent ) ) {
				return new \WP_Error( 'memex_note_not_found', __( 'Source note not found.', 'memex' ) );
			}
			$update['post_parent'] = $parent;
		}

		if ( $id && count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( ! $id ) {
			$result = wp_insert_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$id = (int) $result;
		}

		$payload = self::reminder_payload( get_post( $id ) );
		if ( '' !== $due_gmt ) {
			update_post_meta( $id, Reminder::META_DUE_AT, $due_gmt );
			$payload = self::reminder_payload( get_post( $id ) );
		}

		$payload[ $reminder ? 'updated' : 'created' ] = true;
		if ( 'private' === $status ) {
			$payload['completed'] = true;
		}
		return $payload;
	}

	private static function register_ability( string $slug, array $args ): void {
		$args['category'] = self::CATEGORY;
		wp_register_ability( self::CATEGORY . '/' . $slug, $args );
	}

	private static function meta( string $instructions, bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'instructions' => $instructions,
				'readonly'     => $readonly,
				'destructive'  => $destructive,
				'idempotent'   => $idempotent,
			),
		);
	}

	private static function resolve_note_from_input( array $input ): ?\WP_Post {
		if ( ! empty( $input['id'] ) ) {
			$post = get_post( absint( $input['id'] ) );
			return $post && CPT::POST_TYPE === $post->post_type ? $post : null;
		}

		if ( ! empty( $input['slug'] ) ) {
			$posts = get_posts(
				array(
					'post_type'      => CPT::POST_TYPE,
					'name'           => sanitize_title( (string) $input['slug'] ),
					'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
					'posts_per_page' => 1,
				)
			);
			return $posts ? $posts[0] : null;
		}

		if ( ! empty( $input['title'] ) ) {
			$id = Links::resolve( sanitize_text_field( (string) $input['title'] ) );
			return $id ? get_post( $id ) : null;
		}

		if ( ! empty( $input['daily_date'] ) ) {
			$date = sanitize_text_field( (string) $input['daily_date'] );
			if ( 'today' === strtolower( $date ) ) {
				$date = DailyNote::today();
			}
			return DailyNote::is_valid_date( $date ) ? DailyNote::find( $date ) : null;
		}

		return null;
	}

	private static function note_summary( \WP_Post $post ): array {
		$payload = self::note_payload( $post, false );
		$payload['excerpt'] = wp_trim_words( wp_strip_all_tags( $post->post_content ), 35 );
		return $payload;
	}

	private static function note_payload( ?\WP_Post $post, bool $include_content ): array {
		if ( ! $post ) {
			return array();
		}

		$tags = wp_get_post_terms( $post->ID, CPT::TAXONOMY, array( 'fields' => 'names' ) );
		$tags = is_wp_error( $tags ) ? array() : $tags;

		$payload = array(
			'id'          => (int) $post->ID,
			'title'       => get_the_title( $post ),
			'slug'        => $post->post_name,
			'status'      => $post->post_status,
			'view_url'    => CPT::url( $post ),
			'edit_url'    => home_url( '/memex/edit/' . rawurlencode( $post->post_name ?: (string) $post->ID ) ),
			'daily_date'  => (string) get_post_meta( $post->ID, CPT::META_DAILY, true ),
			'is_stub'     => (bool) get_post_meta( $post->ID, CPT::META_STUB, true ),
			'tags'        => array_values( $tags ),
			'modified'    => get_post_modified_time( DATE_ATOM, false, $post ),
			'reminders'   => array_map( array( __CLASS__, 'reminder_payload' ), Reminder::pending_for_note( (int) $post->ID ) ),
		);

		if ( $include_content ) {
			$outgoing_ids = array_map( 'intval', get_post_meta( $post->ID, CPT::META_LINKS_TO, false ) );
			$backlinks    = Links::get_backlinks( (int) $post->ID, 50 );

			$payload['content'] = App::content_to_editor_text( (string) $post->post_content );
			$payload['content_html'] = apply_filters( 'the_content', $post->post_content );
			$payload['outgoing_links'] = array_map(
				static function ( $id ) {
					$p = get_post( $id );
					return $p ? array(
						'id'       => (int) $p->ID,
						'title'    => get_the_title( $p ),
						'view_url' => CPT::url( $p ),
					) : null;
				},
				$outgoing_ids
			);
			$payload['outgoing_links'] = array_values( array_filter( $payload['outgoing_links'] ) );
			$payload['backlinks'] = array_map( array( __CLASS__, 'note_summary' ), $backlinks );
		}

		return $payload;
	}

	private static function reminder_payload( \WP_Post $reminder ): array {
		$due_gmt = (string) get_post_meta( $reminder->ID, Reminder::META_DUE_AT, true );
		$parent  = $reminder->post_parent ? get_post( $reminder->post_parent ) : null;

		return array(
			'id'          => (int) $reminder->ID,
			'title'       => get_the_title( $reminder ),
			'status'      => 'private' === $reminder->post_status ? 'done' : 'pending',
			'due_gmt'     => $due_gmt,
			'due_local'   => $due_gmt ? get_date_from_gmt( $due_gmt, 'Y-m-d H:i' ) : '',
			'is_overdue'  => 'publish' === $reminder->post_status && '' !== $due_gmt && $due_gmt <= gmdate( 'Y-m-d H:i:s' ),
			'source_note' => $parent && CPT::POST_TYPE === $parent->post_type ? array(
				'id'       => (int) $parent->ID,
				'title'    => get_the_title( $parent ),
				'view_url' => CPT::url( $parent ),
			) : null,
		);
	}

	private static function set_note_tags( int $post_id, array $tags ): void {
		$tags = array_filter(
			array_map(
				static function ( $tag ) {
					return sanitize_text_field( (string) $tag );
				},
				$tags
			)
		);
		wp_set_object_terms( $post_id, array_values( $tags ), CPT::TAXONOMY, false );
	}

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
				$inner = '<strong>' . esc_html( $timestamp ) . '</strong> &middot; ' . $inner;
				$first = false;
			}
			$out[] = "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $out );
	}

	private static function limit( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}

	private static function search_notes_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => __( 'Search text. Empty lists recent notes.', 'memex' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( '1-50. Default 10.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function note_lookup_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array(
					'type'        => 'integer',
					'description' => __( 'Note ID.', 'memex' ),
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => __( 'Note slug.', 'memex' ),
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'Exact title.', 'memex' ),
				),
				'daily_date' => array(
					'type'        => 'string',
					'description' => __( 'YYYY-MM-DD or today.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function save_note_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'Update by ID.', 'memex' ),
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Update by slug.', 'memex' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Find or create by title.', 'memex' ),
				),
				'content'      => array(
					'type'        => 'string',
					'description' => __( 'Plain text body. Replaces existing content.', 'memex' ),
				),
				'tags'         => array(
					'type'        => 'array',
					'description' => __( 'Tag names.', 'memex' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function capture_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'content' ),
			'properties'           => array(
				'content' => array(
					'type'        => 'string',
					'description' => __( 'Plain text to append to today.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function list_reminders_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'include_done' => array(
					'type'        => 'boolean',
					'description' => __( 'Include completed reminders. Default true.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function save_reminder_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array(
					'type'        => 'integer',
					'description' => __( 'Reminder ID to update.', 'memex' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'Reminder text.', 'memex' ),
				),
				'due'     => array(
					'type'        => 'string',
					'description' => __( 'YYYY-MM-DDTHH:MM local or ISO datetime.', 'memex' ),
				),
				'note_id' => array(
					'type'        => 'integer',
					'description' => __( 'Source note ID.', 'memex' ),
				),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'pending', 'done' ),
					'description' => __( 'pending or done.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function notes_list_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'notes' => array(
					'type'  => 'array',
					'items' => self::note_schema( false ),
				),
			),
		);
	}

	private static function note_output_schema( bool $include_content ): array {
		return array(
			'type'       => 'object',
			'properties' => self::note_schema( $include_content )['properties'],
		);
	}

	private static function note_schema( bool $include_content ): array {
		$properties = array(
			'id'         => array( 'type' => 'integer' ),
			'title'      => array( 'type' => 'string' ),
			'slug'       => array( 'type' => 'string' ),
			'status'     => array( 'type' => 'string' ),
			'view_url'   => array( 'type' => 'string', 'description' => __( 'URL to open the note in Memex.', 'memex' ) ),
			'edit_url'   => array( 'type' => 'string', 'description' => __( 'URL to edit the note in Memex.', 'memex' ) ),
			'daily_date' => array( 'type' => 'string' ),
			'is_stub'    => array( 'type' => 'boolean' ),
			'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'modified'   => array( 'type' => 'string' ),
			'reminders'  => array( 'type' => 'array', 'items' => self::reminder_output_schema() ),
			'created'    => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call created a note.', 'memex' ) ),
			'existing'   => array( 'type' => 'boolean', 'description' => __( 'Whether an existing note was returned instead of creating a duplicate.', 'memex' ) ),
			'updated'    => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call updated the note.', 'memex' ) ),
			'appended'   => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call appended content to the note.', 'memex' ) ),
		);
		if ( $include_content ) {
			$properties['content'] = array( 'type' => 'string', 'description' => __( 'Editable plain-text content.', 'memex' ) );
			$properties['content_html'] = array( 'type' => 'string', 'description' => __( 'Rendered HTML content.', 'memex' ) );
			$properties['outgoing_links'] = array( 'type' => 'array', 'items' => self::linked_note_schema() );
			$properties['backlinks'] = array( 'type' => 'array', 'items' => self::note_schema( false ) );
		} else {
			$properties['excerpt'] = array( 'type' => 'string' );
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	private static function linked_note_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'view_url' => array( 'type' => 'string' ),
			),
		);
	}

	private static function reminders_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'url'      => array( 'type' => 'string' ),
				'overdue'  => array( 'type' => 'array', 'items' => self::reminder_output_schema() ),
				'upcoming' => array( 'type' => 'array', 'items' => self::reminder_output_schema() ),
				'done'     => array( 'type' => 'array', 'items' => self::reminder_output_schema() ),
			),
		);
	}

	private static function reminder_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'due_gmt'     => array( 'type' => 'string' ),
				'due_local'   => array( 'type' => 'string' ),
				'is_overdue'  => array( 'type' => 'boolean' ),
				'source_note' => self::linked_note_schema(),
				'created'     => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call created the reminder.', 'memex' ) ),
				'updated'     => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call updated the reminder.', 'memex' ) ),
				'completed'   => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call completed the reminder.', 'memex' ) ),
			),
		);
	}
}
