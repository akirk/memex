<?php
/**
 * Reminders.
 *
 * A reminder is its own `memex_reminder` post. We keep notes as the only
 * "user content" CPT and treat reminders as lightweight records:
 *
 *   - `post_title`     → the label
 *   - `post_author`    → who gets emailed
 *   - `post_parent`    → optional source `memex_note`
 *   - `post_status`    → `publish` = pending, `private` = done
 *   - meta `_memex_due_at`    → UTC 'Y-m-d H:i:s'
 *   - meta `_memex_fired_at`  → UTC 'Y-m-d H:i:s' once email has been sent
 *   - meta `_memex_block_id`  → reserved for the future Gutenberg block;
 *                               lets save_post reconciliation match a stored
 *                               reminder to the block that produced it.
 *
 * We store the due time in post_meta rather than `post_date` to sidestep WP's
 * `future` post-status transition for date-in-the-future publish posts. The
 * dispatch cron uses a meta_query DATETIME comparison to find what's due.
 */

namespace Memex;

class Reminder {
	const POST_TYPE     = 'memex_reminder';
	const META_DUE_AT   = '_memex_due_at';
	const META_FIRED_AT = '_memex_fired_at';
	const META_BLOCK_ID = '_memex_block_id';
	const CRON_HOOK     = 'memex_reminders_dispatch';
	const CRON_INTERVAL = 'memex_5min';

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Reminders', 'memex' ),
					'singular_name' => __( 'Reminder', 'memex' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);

		foreach ( array( self::META_DUE_AT, self::META_FIRED_AT, self::META_BLOCK_ID ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'         => 'string',
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}

		// Server-side block registration — purely declarative for a static block,
		// but it teaches WP about our attribute schema so parse_blocks() returns
		// well-typed attrs and the editor picks up the right defaults.
		register_block_type(
			'memex/reminder',
			array(
				'attributes' => array(
					'blockId' => array(
						'type'    => 'string',
						'default' => '',
					),
					'due'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'label'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch' ) );
		add_action( 'admin_post_memex_create_reminder', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_memex_complete_reminder', array( __CLASS__, 'handle_complete' ) );

		// Reconcile reminder CPT rows from the parsed blocks of a note on save.
		// Priority 25: run after Links::on_save (priority 20) — order doesn't
		// matter for correctness, just keeps logs grouped.
		add_action( 'save_post_' . CPT::POST_TYPE, array( __CLASS__, 'on_save_note' ), 25, 2 );

		// Self-heal the cron event without requiring deactivate/reactivate. The
		// cron_schedules filter must be in place before wp_schedule_event runs,
		// hence the ordering above.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Memex)', 'memex' ),
			);
		}
		return $schedules;
	}

	public static function activate(): void {
		self::register();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cron callback: email any reminders whose due time has passed and that
	 * haven't been fired yet. Capped per run; leftovers fire next tick.
	 */
	public static function dispatch(): void {
		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		$q       = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'meta_value',
				'meta_key'       => self::META_DUE_AT,
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_DUE_AT,
						'value'   => $now_gmt,
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => self::META_FIRED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $q->posts as $reminder ) {
			self::send_email( $reminder );
			update_post_meta( $reminder->ID, self::META_FIRED_AT, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	private static function send_email( \WP_Post $reminder ): void {
		$author = get_userdata( (int) $reminder->post_author );
		if ( ! $author || empty( $author->user_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s = reminder label */
			__( '[Memex] Reminder: %s', 'memex' ),
			$reminder->post_title
		);

		$lines = array( $reminder->post_title, '' );
		$due   = (string) get_post_meta( $reminder->ID, self::META_DUE_AT, true );
		if ( $due ) {
			$lines[] = sprintf(
				/* translators: %s = local datetime */
				__( 'Due: %s', 'memex' ),
				get_date_from_gmt( $due, 'Y-m-d H:i' )
			);
		}
		$parent = $reminder->post_parent ? get_post( $reminder->post_parent ) : null;
		if ( $parent ) {
			$lines[] = sprintf(
				/* translators: %s = note title */
				__( 'From note: %s', 'memex' ),
				get_the_title( $parent )
			);
			$lines[] = CPT::url( $parent );
		}
		$lines[] = '';
		$lines[] = home_url( '/memex/reminders' );

		wp_mail( $author->user_email, $subject, implode( "\n", $lines ) );
	}

	public static function handle_create(): void {
		check_admin_referer( 'memex_create_reminder' );
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'memex' ) );
		}
		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$due_local = isset( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '';
		$parent    = isset( $_POST['parent'] ) ? (int) $_POST['parent'] : 0;
		if ( '' === $title || '' === $due_local ) {
			wp_safe_redirect( add_query_arg( 'error', 'missing', home_url( '/memex/reminders' ) ) );
			exit;
		}
		$due_gmt = self::local_input_to_gmt( $due_local );
		if ( '' === $due_gmt ) {
			wp_safe_redirect( add_query_arg( 'error', 'baddate', home_url( '/memex/reminders' ) ) );
			exit;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
				'post_parent' => $parent && CPT::is_note( $parent ) ? $parent : 0,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_die( esc_html__( 'Could not create reminder.', 'memex' ) );
		}
		update_post_meta( $id, self::META_DUE_AT, $due_gmt );
		wp_safe_redirect( home_url( '/memex/reminders' ) );
		exit;
	}

	public static function handle_complete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'memex_complete_reminder_' . $id );
		$reminder = get_post( $id );
		if ( ! $reminder || self::POST_TYPE !== $reminder->post_type ) {
			wp_die( esc_html__( 'Reminder not found.', 'memex' ) );
		}
		if ( (int) $reminder->post_author !== get_current_user_id() && ! current_user_can( 'edit_others_pages' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'memex' ) );
		}
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			)
		);
		wp_safe_redirect( home_url( '/memex/reminders' ) );
		exit;
	}

	/**
	 * Reconcile child reminders from the blocks in a note's content.
	 *
	 * The note is the source of truth: every `memex/reminder` block becomes
	 * (or updates) a `memex_reminder` post keyed by the block's `blockId`
	 * attribute. Reminders that previously had a block_id but no longer
	 * appear in the note are trashed. Reminders without a block_id (e.g.
	 * created via the standalone form) are left alone.
	 */
	public static function on_save_note( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}
		self::reconcile( (int) $post_id, (string) $post->post_content );
	}

	public static function reconcile( int $note_id, string $content ): void {
		$blocks  = parse_blocks( $content );
		$found   = self::collect_reminders( $blocks );
		$by_bid  = self::existing_by_block_id( $note_id );
		$author  = (int) get_post_field( 'post_author', $note_id );

		foreach ( $found as $block_id => $r ) {
			if ( isset( $by_bid[ $block_id ] ) ) {
				$reminder_id = $by_bid[ $block_id ];
				wp_update_post(
					array(
						'ID'         => $reminder_id,
						'post_title' => $r['label'],
					)
				);
				$current_due = (string) get_post_meta( $reminder_id, self::META_DUE_AT, true );
				if ( $current_due !== $r['due_gmt'] ) {
					update_post_meta( $reminder_id, self::META_DUE_AT, $r['due_gmt'] );
					// Due time changed — let the cron try again with the new time.
					delete_post_meta( $reminder_id, self::META_FIRED_AT );
				}
				unset( $by_bid[ $block_id ] );
			} else {
				$new_id = wp_insert_post(
					array(
						'post_type'   => self::POST_TYPE,
						'post_title'  => $r['label'],
						'post_status' => 'publish',
						'post_author' => $author,
						'post_parent' => $note_id,
					),
					true
				);
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					update_post_meta( $new_id, self::META_DUE_AT, $r['due_gmt'] );
					update_post_meta( $new_id, self::META_BLOCK_ID, $block_id );
				}
			}
		}

		// Anything left was removed from the note → trash it.
		foreach ( $by_bid as $reminder_id ) {
			wp_trash_post( $reminder_id );
		}
	}

	/**
	 * Walk a parsed block tree, returning [block_id => ['label' => …, 'due_gmt' => …]]
	 * for every well-formed `memex/reminder` block. Duplicate blockIds keep
	 * the first occurrence.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function collect_reminders( array $blocks, array $out = array() ): array {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( 'memex/reminder' === $name ) {
				$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$bid     = isset( $attrs['blockId'] ) ? (string) $attrs['blockId'] : '';
				$label   = isset( $attrs['label'] ) ? trim( (string) $attrs['label'] ) : '';
				$due_iso = isset( $attrs['due'] ) ? (string) $attrs['due'] : '';
				if ( '' !== $bid && '' !== $label && '' !== $due_iso && ! isset( $out[ $bid ] ) ) {
					$due_gmt = self::iso_to_gmt( $due_iso );
					if ( '' !== $due_gmt ) {
						$out[ $bid ] = array(
							'label'   => $label,
							'due_gmt' => $due_gmt,
						);
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = self::collect_reminders( $block['innerBlocks'], $out );
			}
		}
		return $out;
	}

	/**
	 * Existing child reminders of a note, indexed by their block_id meta.
	 *
	 * @return array<string, int>
	 */
	private static function existing_by_block_id( int $note_id ): array {
		$q       = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'post_parent'    => $note_id,
				'posts_per_page' => 200,
			)
		);
		$by_bid = array();
		foreach ( $q->posts as $r ) {
			$bid = (string) get_post_meta( $r->ID, self::META_BLOCK_ID, true );
			if ( '' !== $bid ) {
				$by_bid[ $bid ] = (int) $r->ID;
			}
		}
		return $by_bid;
	}

	/**
	 * Convert an ISO-like string from the block (e.g. "2026-05-01T14:30:00",
	 * "2026-05-01T14:30:00Z", or with explicit offset) to UTC 'Y-m-d H:i:s'.
	 * Strings without a timezone designator are interpreted in the WP site
	 * timezone, matching the DateTimePicker's default display.
	 */
	public static function iso_to_gmt( string $iso ): string {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return '';
		}
		try {
			if ( preg_match( '/[zZ]|[+\-]\d{2}:?\d{2}$/', $iso ) ) {
				$dt = new \DateTimeImmutable( $iso );
			} else {
				$dt = new \DateTimeImmutable( $iso, wp_timezone() );
			}
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Convert a `<input type="datetime-local">` value (e.g. "2026-05-01T14:30")
	 * from the WP site timezone to a UTC 'Y-m-d H:i:s' string. Empty on parse
	 * failure.
	 */
	public static function local_input_to_gmt( string $input ): string {
		$input = str_replace( 'T', ' ', trim( $input ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $input ) ) {
			return '';
		}
		if ( 16 === strlen( $input ) ) {
			$input .= ':00';
		}
		try {
			$dt = new \DateTimeImmutable( $input, wp_timezone() );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * All reminders authored by the current user, grouped overdue/upcoming/done.
	 *
	 * @return array{overdue: \WP_Post[], upcoming: \WP_Post[], done: \WP_Post[]}
	 */
	public static function for_current_user(): array {
		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		$q       = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'author'         => get_current_user_id(),
				'orderby'        => 'meta_value',
				'meta_key'       => self::META_DUE_AT,
				'order'          => 'ASC',
			)
		);
		$overdue  = array();
		$upcoming = array();
		$done     = array();
		foreach ( $q->posts as $r ) {
			$due = (string) get_post_meta( $r->ID, self::META_DUE_AT, true );
			if ( 'private' === $r->post_status ) {
				$done[] = $r;
			} elseif ( $due && $due <= $now_gmt ) {
				$overdue[] = $r;
			} else {
				$upcoming[] = $r;
			}
		}
		return compact( 'overdue', 'upcoming', 'done' );
	}

	/**
	 * Pending reminders for a single note (used by the daily-note + note views
	 * to show "you have reminders here"). Returned in due-time ascending order.
	 *
	 * @return \WP_Post[]
	 */
	public static function pending_for_note( int $note_id ): array {
		$q = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'post_parent'    => $note_id,
				'orderby'        => 'meta_value',
				'meta_key'       => self::META_DUE_AT,
				'order'          => 'ASC',
			)
		);
		return $q->posts;
	}
}
