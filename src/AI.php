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
				'description' => __( 'Search, read, create, update, and connect Memex notes and reminders.', 'memex' ),
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
				'description'         => __( 'Searches Memex notes by title and content, or lists recent notes when no query is provided.', 'memex' ),
				'input_schema'        => self::search_notes_input_schema(),
				'output_schema'       => self::notes_list_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_search_notes' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks to find, list, summarize, or choose Memex notes. Use returned id values with memex/get-note, and use view_url when linking notes.', 'memex' ),
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
				'description'         => __( 'Returns one Memex note by ID, slug, or title, including editable text, rendered text, wiki links, backlinks, tags, daily date, and view URL.', 'memex' ),
				'input_schema'        => self::note_lookup_input_schema(),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_get_note' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Use this after search-notes when full note content, backlinks, outgoing links, tags, or the editable plain-text form is needed. If more than one lookup field is present, id is authoritative.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'get-daily-note',
			array(
				'label'               => __( 'Get Memex Daily Note', 'memex' ),
				'description'         => __( 'Returns the Memex daily note for a YYYY-MM-DD date, or today when no date is provided.', 'memex' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'date' => array(
							'type'        => 'string',
							'description' => __( 'Date in YYYY-MM-DD format. Defaults to today in the site timezone.', 'memex' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_get_daily_note' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks about today\'s note, a journal entry, or a specific daily note. This ability does not create missing daily notes; use quick-capture to append to today and create it if needed.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'create-note',
			array(
				'label'               => __( 'Create Memex Note', 'memex' ),
				'description'         => __( 'Creates a new Memex note with optional plain-text content and tags.', 'memex' ),
				'input_schema'        => self::save_note_input_schema( false ),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_create_note' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks to create a new Memex note. If the title already exists, return the existing note instead of creating a duplicate. Use content_text for plain text with wiki-link syntax like [[Target]].', 'memex' ),
					false,
					false,
					false
				),
			)
		);

		self::register_ability(
			'update-note',
			array(
				'label'               => __( 'Update Memex Note', 'memex' ),
				'description'         => __( 'Updates a Memex note title, full plain-text content, and/or tags.', 'memex' ),
				'input_schema'        => self::save_note_input_schema( true ),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_update_note' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks to edit an existing note. content_text replaces the whole note body, so inspect get-note first unless the user supplied the complete replacement content.', 'memex' ),
					false,
					true,
					false
				),
			)
		);

		self::register_ability(
			'quick-capture',
			array(
				'label'               => __( 'Quick Capture to Memex', 'memex' ),
				'description'         => __( 'Appends plain text to today\'s Memex daily note, creating the daily note if needed.', 'memex' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'content_text' ),
					'properties'           => array(
						'content_text' => array(
							'type'        => 'string',
							'description' => __( 'Plain text to append to today\'s daily note. Wiki-link syntax like [[Target]] is preserved.', 'memex' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => self::note_output_schema( true ),
				'execute_callback'    => array( __CLASS__, 'ability_quick_capture' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Use this for quick notes, inbox capture, journal additions, and appending short text to today\'s daily note. Link the returned view_url.', 'memex' ),
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
				'description'         => __( 'Lists the signed-in user\'s Memex reminders grouped as overdue, upcoming, and done.', 'memex' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_done' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to include completed reminders. Defaults to true.', 'memex' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => self::reminders_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_list_reminders' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks what reminders are due, overdue, upcoming, or completed. Use reminder id values with memex/complete-reminder.', 'memex' ),
					true,
					false,
					true
				),
			)
		);

		self::register_ability(
			'create-reminder',
			array(
				'label'               => __( 'Create Memex Reminder', 'memex' ),
				'description'         => __( 'Creates a Memex reminder for the signed-in user, optionally linked to a note.', 'memex' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'title', 'due' ),
					'properties'           => array(
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'Reminder label.', 'memex' ),
						),
						'due'     => array(
							'type'        => 'string',
							'description' => __( 'Due date/time as YYYY-MM-DDTHH:MM in the site timezone, or an ISO date/time with timezone.', 'memex' ),
						),
						'note_id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional Memex note ID to attach as the reminder source.', 'memex' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => self::reminder_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_create_reminder' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks to be reminded. Resolve relative dates before calling and pass a concrete due date/time. Link source_note.view_url when present.', 'memex' ),
					false,
					false,
					false
				),
			)
		);

		self::register_ability(
			'complete-reminder',
			array(
				'label'               => __( 'Complete Memex Reminder', 'memex' ),
				'description'         => __( 'Marks a Memex reminder done.', 'memex' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Reminder ID from memex/list-reminders or memex/create-reminder.', 'memex' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => self::reminder_output_schema(),
				'execute_callback'    => array( __CLASS__, 'ability_complete_reminder' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'meta'                => self::meta(
					__( 'Use this when the user asks to mark a reminder done. Confirm the reminder title and due time after completion.', 'memex' ),
					false,
					false,
					true
				),
			)
		);
	}

	public static function register_ability_domains( array $domains ): array {
		$domains[ self::CATEGORY ] = 'Memex notes, personal knowledge base, wiki links, backlinks, graph, tags, daily notes, journal entries, quick capture, reminders, note import, orphan notes, broken links';
		return $domains;
	}

	public static function register_welcome_tips( array $tips, array $context = array() ): array {
		$memex_tips = array(
			__( 'Ask me to find related notes, summarize a note, or trace backlinks in Memex.', 'memex' ),
			__( 'Ask me to quick-capture an idea, create a note, or set a reminder from a note.', 'memex' ),
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

		if ( in_array( $ability_id, array( 'memex/get-note', 'memex/get-daily-note' ), true ) ) {
			return __( 'When presenting a Memex note, link the title with view_url, summarize only the relevant content, and mention backlinks, outgoing wiki links, tags, or reminders when they help answer the user.', 'memex' );
		}

		if ( in_array( $ability_id, array( 'memex/create-note', 'memex/update-note', 'memex/quick-capture' ), true ) ) {
			return __( 'Confirm the Memex note title and link it with view_url. If the result indicates an existing note was reused, say that instead of saying a new note was created.', 'memex' );
		}

		if ( 'memex/list-reminders' === $ability_id ) {
			return __( 'Present Memex reminders grouped by overdue, upcoming, and done. Include due_local and source note links when present.', 'memex' );
		}

		if ( in_array( $ability_id, array( 'memex/create-reminder', 'memex/complete-reminder' ), true ) ) {
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

	public static function ability_get_daily_note( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$date  = isset( $input['date'] ) && '' !== (string) $input['date'] ? sanitize_text_field( (string) $input['date'] ) : DailyNote::today();
		if ( ! DailyNote::is_valid_date( $date ) ) {
			return new \WP_Error( 'memex_invalid_date', __( 'Date must be in YYYY-MM-DD format.', 'memex' ) );
		}

		$post = DailyNote::find( $date );
		if ( ! $post ) {
			return new \WP_Error( 'memex_daily_note_not_found', __( 'Daily note not found.', 'memex' ) );
		}

		return self::note_payload( $post, true );
	}

	public static function ability_create_note( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'memex_missing_title', __( 'A note title is required.', 'memex' ) );
		}

		$existing = Links::resolve( $title );
		if ( $existing ) {
			$payload = self::note_payload( get_post( $existing ), true );
			$payload['created'] = false;
			$payload['existing'] = true;
			return $payload;
		}

		$content = isset( $input['content_text'] ) ? self::plain_text_to_paragraph_blocks( (string) $input['content_text'] ) : '';
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

	public static function ability_update_note( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
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
		if ( array_key_exists( 'content_text', $input ) ) {
			$update['post_content'] = self::plain_text_to_paragraph_blocks( (string) $input['content_text'] );
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

	public static function ability_quick_capture( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$raw   = isset( $input['content_text'] ) ? trim( (string) $input['content_text'] ) : '';
		if ( '' === $raw ) {
			return new \WP_Error( 'memex_missing_content', __( 'Quick capture content is required.', 'memex' ) );
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

	public static function ability_create_reminder( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$due   = isset( $input['due'] ) ? sanitize_text_field( (string) $input['due'] ) : '';
		if ( '' === $title || '' === $due ) {
			return new \WP_Error( 'memex_missing_reminder_fields', __( 'Reminder title and due date are required.', 'memex' ) );
		}

		$due_gmt = false !== strpos( $due, 'T' ) || preg_match( '/[zZ]|[+\-]\d{2}:?\d{2}$/', $due )
			? Reminder::iso_to_gmt( $due )
			: Reminder::local_input_to_gmt( $due );
		if ( '' === $due_gmt ) {
			return new \WP_Error( 'memex_invalid_due_date', __( 'Could not parse reminder due date.', 'memex' ) );
		}

		$parent = isset( $input['note_id'] ) ? absint( $input['note_id'] ) : 0;
		if ( $parent && ! CPT::is_note( $parent ) ) {
			return new \WP_Error( 'memex_note_not_found', __( 'Source note not found.', 'memex' ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => Reminder::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
				'post_parent' => $parent,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, Reminder::META_DUE_AT, $due_gmt );

		$payload = self::reminder_payload( get_post( $id ) );
		$payload['created'] = true;
		return $payload;
	}

	public static function ability_complete_reminder( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$reminder = $id ? get_post( $id ) : null;
		if ( ! $reminder || Reminder::POST_TYPE !== $reminder->post_type ) {
			return new \WP_Error( 'memex_reminder_not_found', __( 'Reminder not found.', 'memex' ) );
		}
		if ( (int) $reminder->post_author !== get_current_user_id() && ! current_user_can( 'edit_others_pages' ) ) {
			return new \WP_Error( 'memex_forbidden', __( 'You are not allowed to edit this reminder.', 'memex' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = self::reminder_payload( get_post( $id ) );
		$payload['completed'] = true;
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

			$payload['content_text'] = App::content_to_editor_text( (string) $post->post_content );
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
					'description' => __( 'Search phrase for note titles and content. Omit or leave empty to list recent notes.', 'memex' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum notes to return, from 1 to 50. Defaults to 10.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function note_lookup_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'        => 'integer',
					'description' => __( 'Memex note ID from search-notes, create-note, update-note, quick-capture, or get-daily-note.', 'memex' ),
				),
				'slug'  => array(
					'type'        => 'string',
					'description' => __( 'Memex note slug from a /memex/note/{slug} URL.', 'memex' ),
				),
				'title' => array(
					'type'        => 'string',
					'description' => __( 'Exact Memex note title when ID or slug is not known.', 'memex' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function save_note_input_schema( bool $require_id ): array {
		$properties = array(
			'title'        => array(
				'type'        => 'string',
				'description' => __( 'Note title.', 'memex' ),
			),
			'content_text' => array(
				'type'        => 'string',
				'description' => __( 'Plain-text note content. Blank lines create paragraphs; wiki-link syntax like [[Target]] is preserved.', 'memex' ),
			),
			'tags'         => array(
				'type'        => 'array',
				'description' => __( 'Tag names to assign to the note.', 'memex' ),
				'items'       => array( 'type' => 'string' ),
			),
		);
		if ( $require_id ) {
			$properties = array_merge(
				array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'Memex note ID to update.', 'memex' ),
					),
				),
				$properties
			);
		}

		return array(
			'type'                 => 'object',
			'required'             => $require_id ? array( 'id' ) : array( 'title' ),
			'properties'           => $properties,
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
			$properties['content_text'] = array( 'type' => 'string', 'description' => __( 'Editable plain-text content.', 'memex' ) );
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
				'completed'   => array( 'type' => 'boolean', 'description' => __( 'Whether this ability call completed the reminder.', 'memex' ) ),
			),
		);
	}
}
