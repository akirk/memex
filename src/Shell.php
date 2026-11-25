<?php
namespace Memex;

/** Note data and optimistic writes for the client-rendered application. */
class Shell {
	public static function register(): void {
		add_action( 'wp_ajax_memex_shell_bootstrap', [ __CLASS__, 'bootstrap' ] );
		add_action( 'wp_ajax_memex_shell_notes', [ __CLASS__, 'notes' ] );
	}

	public static function offline_config(): array {
		global $wp_locale;
		return [
			'userId'       => get_current_user_id(),
			'scope'        => home_url( '/memex/' ),
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'timezone'     => wp_timezone()->getName(),
			'timeFormat'   => get_option( 'time_format' ),
			'startOfWeek'  => (int) get_option( 'start_of_week', 0 ),
			'monthNames'   => $wp_locale ? array_map(
                static function ( $month ) use ( $wp_locale ) {
					return $wp_locale->get_month( $month ); },
                range( 1, 12 )
            ) : null,
			'weekdayNames' => $wp_locale ? array_map(
                static function ( $day ) use ( $wp_locale ) {
					return $wp_locale->get_weekday( $day ); },
                range( 0, 6 )
            ) : null,
			'settingsUrl'  => admin_url( 'options-general.php' ),
			'loginUrl'     => wp_login_url( home_url( '/memex/' ) ),
		];
	}

	public static function bootstrap(): void {
		nocache_headers();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json( [ 'message' => 'Sign in to Memex.' ], 403 ); }
		wp_send_json( array_merge( self::offline_config(), [ 'serverTime' => time() ] ) );
	}

	public static function version( \WP_Post $post ): string {
		return hash( 'sha256', wp_json_encode( [ $post->post_title, $post->post_content, (int) $post->post_parent, $post->post_status ] ) );
	}

	public static function note( \WP_Post $post, bool $body = true ): array {
		$terms = get_the_terms( $post->ID, CPT::TAXONOMY );
		$data  = [
			'id'       => (string) $post->ID,
			'postId'   => (int) $post->ID,
			'slug'     => $post->post_name,
			'title'    => $post->post_title,
			'parent'   => (int) $post->post_parent,
			'status'   => $post->post_status,
			'version'  => self::version( $post ),
			'modified' => $post->post_modified_gmt,
			'created'  => $post->post_date_gmt,
			'daily'    => (string) get_post_meta( $post->ID, CPT::META_DAILY, true ),
			'tags'     => is_array( $terms ) ? array_values(
                array_map(
                    static function ( $term ) {
						return [
							'name' => $term->name,
							'slug' => $term->slug,
						]; },
					$terms
                )
            ) : [],
			'links'    => array_map( 'intval', get_post_meta( $post->ID, CPT::META_LINKS_TO, false ) ),
			'stub'     => (bool) get_post_meta( $post->ID, CPT::META_STUB, true ),
			'editable' => current_user_can( 'edit_post', $post->ID ),
			'excerpt'  => wp_trim_words( html_entity_decode( wp_strip_all_tags( $post->post_content ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ), 30, '…' ),
		];
		if ( $body ) {
			$data['revisions'] = [];
			if ( $data['editable'] ) {
				foreach ( wp_get_post_revisions( $post->ID, [ 'posts_per_page' => 20 ] ) as $revision ) {
					if ( $revision->post_content !== $post->post_content || $revision->post_title !== $post->post_title ) {
						$data['revisions'][] = [
							'title'    => $revision->post_title,
							'content'  => App::content_to_editor_text( $revision->post_content ),
							'modified' => $revision->post_modified_gmt,
						]; }
				}
			}
			$data['content'] = App::content_to_editor_text( $post->post_content );
			// Task inputs are absent from the default post allowlist. Keep only
			// their display attributes; the client enables editable checkboxes.
			$allowed = wp_kses_allowed_html( 'post' );
			$allowed['input'] = array(
				'type'     => true,
				'class'    => true,
				'checked'  => true,
				'disabled' => true,
			);
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Render stored note HTML with the core content filters.
			$data['html']    = wp_kses( apply_filters( 'the_content', $post->post_content ), $allowed );
		}
		return $data;
	}

	public static function notes(): void {
		nocache_headers();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Sign in to Memex.' ], 403 ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, capability-gated lookup for long-lived offline sessions.
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		if ( '' !== $id ) {
			$matches = ctype_digit( $id ) ? [] : get_posts(
                [
					'post_type'        => CPT::POST_TYPE,
					'name'             => $id,
					'post_status'      => CPT::readable_statuses(),
					'posts_per_page'   => 1,
					'suppress_filters' => false,
				]
            );
			$post    = ctype_digit( $id ) ? get_post( (int) $id ) : ( $matches[0] ?? null );
			if ( ! $post || CPT::POST_TYPE !== $post->post_type || ! in_array( $post->post_status, CPT::readable_statuses(), true ) || ! current_user_can( 'read_post', $post->ID ) ) {
				wp_send_json_error( [ 'message' => 'Note not found or unavailable to this account.' ], 404 );
			}
			wp_send_json_success(
                [
					'userId' => get_current_user_id(),
					'note'   => self::note( $post ),
				]
            );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, capability-gated pagination for long-lived offline sessions.
		$page  = isset( $_GET['page'] ) ? max( 1, absint( wp_unslash( $_GET['page'] ) ) ) : 1;
		$query = new \WP_Query(
            [
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => CPT::readable_statuses(),
				'posts_per_page' => 100,
				'paged'          => $page,
				'perm'           => 'readable',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
        );
		$notes = [];
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$notes[] = self::note( $post, false ); }
		}
		wp_send_json_success(
            [
				'userId' => get_current_user_id(),
				'notes'  => $notes,
				'next'   => $page < $query->max_num_pages ? $page + 1 : null,
			]
        );
	}

	/** Enrich a small durable receipt with the currently readable canonical note. */
	public static function receipt( array $receipt ): array {
		$post = get_post( (int) $receipt['postId'] );
		if ( $post && CPT::POST_TYPE === $post->post_type && in_array( $post->post_status, CPT::readable_statuses(), true ) && current_user_can( 'read_post', $post->ID ) ) {
			$receipt['note'] = self::note( $post );
		}
		return $receipt;
	}

	public static function valid_parent( int $parent, int $self = 0 ): bool {
		return ! $parent || ( CPT::is_note( $parent ) && current_user_can( 'read_post', $parent ) && $parent !== $self && ! in_array( $self, CPT::ancestor_ids( $parent ), true ) );
	}

	/** Compare the baseline and commit content and its small receipt together. */
	public static function save_edit( array $input ) {
		global $wpdb;
		$id   = $input['id'] ?? '';
		$uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';
		if ( ! is_string( $id ) || ! preg_match( $uuid, $id ) || ! is_string( $input['title'] ?? null ) || '' === trim( sanitize_text_field( $input['title'] ) ) || ! is_string( $input['content'] ?? null ) || ! is_string( $input['baseVersion'] ?? null ) ) {
			return new \WP_Error( 'invalid', 'Invalid edit operation.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect actual table engines before starting an atomic save; cached metadata could be stale.
		$engines = $wpdb->get_col( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)', $wpdb->posts, $wpdb->options, $wpdb->postmeta ) );
		if ( 3 !== count( $engines ) || array_diff( array_map( 'strtoupper', $engines ), [ 'INNODB' ] ) ) {
			return new \WP_Error( 'storage', 'Offline editing needs transactional InnoDB tables. Your local edit has been retained.' );
		}
		$lock = 'memex-capture-' . md5( $wpdb->prefix . DB_NAME );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks require live, uncached database calls.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new \WP_Error( 'busy', 'Another save is running. Retry shortly.' );
		}
		$key         = 'memex_capture_' . get_current_user_id() . '_' . $id;
		$transaction = false;
		$nested      = false;
		try {
			$receipt = get_option( $key );
			if ( $receipt ) {
				return self::receipt( $receipt );
			}
			$previous = null;
			if ( ! empty( $input['baseOperation'] ) && is_string( $input['baseOperation'] ) && preg_match( $uuid, $input['baseOperation'] ) ) {
				$previous = get_option( 'memex_capture_' . get_current_user_id() . '_' . $input['baseOperation'] );
			}
			$target = ctype_digit( (string) ( $input['noteId'] ?? '' ) ) ? (int) $input['noteId'] : (int) ( $previous['postId'] ?? 0 );
			if ( ! $target || ! current_user_can( 'edit_post', $target ) ) {
				return new \WP_Error( 'permission', 'You cannot edit this note, or an earlier local save has not synced.' );
			}
			// A savepoint exists only in an active transaction; probing does not change data.
			$previous_errors = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
			$wpdb->query( 'SAVEPOINT memex_transaction_probe' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
			$nested = false !== $wpdb->query( 'RELEASE SAVEPOINT memex_transaction_probe' );
			$wpdb->suppress_errors( $previous_errors );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
			$started = $nested ? $wpdb->query( 'SAVEPOINT memex_note_edit' ) : $wpdb->query( 'START TRANSACTION' );
			if ( false === $started ) {
				return new \WP_Error( 'storage', 'Could not start an atomic save.' );
			}
			$transaction = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock and read the current row in the transaction; a cached post cannot provide a write lock.
			$row         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d FOR UPDATE", $target ) );
			if ( ! $row || CPT::POST_TYPE !== $row->post_type || ! in_array( $row->post_status, CPT::readable_statuses(), true ) ) {
				return new \WP_Error( 'missing', 'This note is no longer available.' );
			}
			$post = new \WP_Post( $row );
			clean_post_cache( $target );
			if ( ! current_user_can( 'edit_post', $target ) ) {
				return new \WP_Error( 'permission', 'Your permission to edit this note changed.' );
			}
			$base = $previous && (int) $previous['postId'] === $target ? ( $previous['version'] ?? $previous['note']['version'] ?? '' ) : $input['baseVersion'];
			if ( ( ! empty( $input['baseOperation'] ) && ( ! $previous || (int) $previous['postId'] !== $target ) ) || ! hash_equals( self::version( $post ), $base ) ) {
				return new \WP_Error( 'conflict', 'This note changed on the server. Review both versions before saving.', [ 'note' => self::note( $post ) ] );
			}
			$parent = isset( $input['parent'] ) ? (int) $input['parent'] : (int) $post->post_parent;
			if ( ! self::valid_parent( $parent, $target ) ) {
				return new \WP_Error( 'parent', 'The selected parent would create an invalid note hierarchy.' );
			}
			if ( ! empty( $input['publish'] ) && ! current_user_can( get_post_type_object( CPT::POST_TYPE )->cap->publish_posts ) ) {
				return new \WP_Error( 'permission', 'You cannot publish this note.' );
			}
			preg_match_all( '/<!-- memex-capture:[0-9]+:[0-9a-f-]+ -->/', $post->post_content, $markers );
			$content = Content::markdown_to_html( $input['content'] ) . "\n" . implode( "\n", $markers[0] );
			remove_action( 'save_post_' . CPT::POST_TYPE, [ Reminder::class, 'on_save_note' ], 25 );
			try {
				$saved = wp_update_post(
                    wp_slash(
                        [
							'ID'           => $target,
							'post_title'   => sanitize_text_field( $input['title'] ),
							'post_content' => $content,
							'post_parent'  => $parent,
							'post_status'  => ! empty( $input['publish'] ) ? 'publish' : $post->post_status,
						]
                    ),
                    true
                );
			} finally {
				add_action( 'save_post_' . CPT::POST_TYPE, [ Reminder::class, 'on_save_note' ], 25, 2 );
			}
			if ( is_wp_error( $saved ) || ! $saved ) {
				return is_wp_error( $saved ) ? $saved : new \WP_Error( 'storage', 'The note could not be saved.' );
			}
			delete_post_meta( $target, CPT::META_STUB );
			clean_post_cache( $target );
			$receipt = [
				'id'      => $id,
				'postId'  => $target,
				'url'     => CPT::url( $target ),
				'version' => self::version( get_post( $target ) ),
			];
			if ( ! update_option( $key, $receipt, false ) ) {
				return new \WP_Error( 'storage', 'Could not persist the save receipt.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
			$committed = $nested ? $wpdb->query( 'RELEASE SAVEPOINT memex_note_edit' ) : $wpdb->query( 'COMMIT' );
			if ( false === $committed ) {
				return new \WP_Error( 'storage', 'Could not commit the note and its receipt.' );
			}
			$transaction = false;
			return self::receipt( $receipt );
		} finally {
			if ( $transaction ) {
				if ( $nested ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
					$wpdb->query( 'ROLLBACK TO SAVEPOINT memex_note_edit' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
					$wpdb->query( 'RELEASE SAVEPOINT memex_note_edit' );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and savepoint statements must execute on the current database connection.
					$wpdb->query( 'ROLLBACK' );
				}
				wp_cache_delete( $key, 'options' );
				if ( ! empty( $target ) ) {
					clean_post_cache( $target );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks require live, uncached database calls.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
