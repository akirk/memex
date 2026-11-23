<?php
namespace Memex;

/** Offline capture transport. Each saved operation carries a durable receipt. */
class OfflineCapture {
	public static function register(): void {
		add_action( 'wp_ajax_memex_capture_session', array( __CLASS__, 'session' ) );
		add_action( 'wp_ajax_memex_capture_sync', array( __CLASS__, 'sync' ) );
	}

	public static function pwa_config(): array {
		return array(
			'name' => 'Memex',
			'short_name' => 'Memex',
			'start_url' => home_url( '/memex/capture/' ),
			'scope' => home_url( '/memex/' ),
			'icons' => array(
				array( 'src' => plugins_url( 'assets/icon-192.png', dirname( __DIR__ ) . '/memex.php' ), 'sizes' => '192x192', 'type' => 'image/png' ),
				array( 'src' => plugins_url( 'assets/icon-512.png', dirname( __DIR__ ) . '/memex.php' ), 'sizes' => '512x512', 'type' => 'image/png' ),
			),
			// Our worker caches only the capture shell and assets, never private note pages.
			'register_service_worker' => false, // Memex displays registration/readiness itself.
			'service_worker_path' => 'offline-worker',
			'cache_policy' => 'shell',
			'offline_url' => home_url( '/memex/capture/' ),
			'precache' => self::required_urls(),
			'cache_prefix' => 'memex-capture-',
			'cache_name' => 'memex-capture-' . self::asset_version(),
			'cache_message_type' => 'memex-capture-cache',
			'cache_status_message_type' => 'memex-capture-status',
			'sync_tag' => 'memex-capture-sync',
			'sync_message_type' => 'memex-capture-sync',
		);
	}

	/** Shared asset/cache version keeps enqueued URLs identical to worker precache URLs. */
	public static function asset_version(): string {
		$root = dirname( __DIR__ );
		return md5( file_get_contents( $root . '/assets/memex-offline.js' ) . file_get_contents( $root . '/vendor/akirk/wp-app/assets/wp-app-outbox.js' ) . file_get_contents( $root . '/vendor/akirk/wp-app/src/class-pwa.php' ) . file_get_contents( $root . '/vendor/akirk/wp-app/assets/wp-app-worker.js' ) . file_get_contents( $root . '/assets/memex.css' ) . file_get_contents( $root . '/templates/capture.php' ) );
	}

	public static function required_urls(): array {
		$base = plugins_url( '', dirname( __DIR__ ) . '/memex.php' ) . '/';
		$version = self::asset_version();
		return array(
			home_url( '/memex/capture/' ),
			add_query_arg( 'ver', $version, $base . 'assets/memex.css' ),
			add_query_arg( 'ver', $version, $base . 'assets/memex-offline.js' ),
			add_query_arg( 'ver', $version, $base . 'vendor/akirk/wp-app/assets/wp-app-outbox.js' ),
			$base . 'assets/icon.svg',
		);
	}

	public static function client_config(): array {
		return array(
			'requiredUrls' => self::required_urls(),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'userId' => get_current_user_id(),
			'workerUrl' => home_url( '/memex/offline-worker/' ),
			'scope' => home_url( '/memex/' ),
			'captureUrl' => home_url( '/memex/capture/' ),
			'loginUrl' => wp_login_url( home_url( '/memex/capture/' ) ),
			'timezone' => wp_timezone()->getName(),
		);
	}

	/** Read-only, cookie-authenticated nonce renewal for pages left open for days. */
	public static function session(): void {
		nocache_headers();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Log in with permission to create notes.', 'memex' ) ), 403 );
		}
		wp_send_json_success( array( 'userId' => get_current_user_id(), 'nonce' => wp_create_nonce( 'memex_capture_sync' ) ) );
	}

	public static function sync(): void {
		check_ajax_referer( 'memex_capture_sync', 'nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by save(); content is sanitized by WordPress on persistence.
		$input = isset( $_POST['capture'] ) ? json_decode( wp_unslash( $_POST['capture'] ), true ) : null;
		$result = self::save( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( $result );
	}

	public static function save( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'edit_posts' ) || (int) ( $input['userId'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'capture_account', __( 'Sign in to the account that saved this entry.', 'memex' ) );
		}
		$id = $input['id'] ?? '';
		$kind = $input['kind'] ?? '';
		$content = $input['content'] ?? '';
		$title = $input['title'] ?? '';
		$date = $input['date'] ?? '';
		$clock = $input['clock'] ?? '';
		if ( ! is_string( $id ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id ) || ! in_array( $kind, array( 'note', 'daily' ), true ) || ! is_string( $content ) || '' === trim( $content ) || ! is_string( $title ) || ( 'note' === $kind && '' === trim( sanitize_text_field( $title ) ) ) ) {
			return new \WP_Error( 'capture_invalid', __( 'A title and note text, or daily entry text, are required.', 'memex' ) );
		}
		if ( 'daily' === $kind && ( ! is_string( $date ) || ! DailyNote::is_valid_date( $date ) || $date > DailyNote::today() || ! is_string( $clock ) || ! preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $clock ) ) ) {
			return new \WP_Error( 'capture_date', __( 'Invalid daily capture date or time.', 'memex' ) );
		}
		// Serialize captures across tabs/devices, including appends to the same daily.
		$lock = 'memex-capture-' . md5( $wpdb->prefix . DB_NAME );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks require a live database call; cached lock results cannot serialize writes.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new \WP_Error( 'capture_busy', __( 'Another capture is syncing. Please retry.', 'memex' ) );
		}
		try {
			$key = 'memex_capture_' . get_current_user_id() . '_' . $id;
			$receipt = get_option( $key );
			if ( $receipt ) {
				return $receipt;
			}
			// The marker and content are written together. Recover even if a request
			// stops after the post write but before persisting its receipt.
			$marker = '<!-- memex-capture:' . get_current_user_id() . ':' . $id . ' -->';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the exact durable marker under the lock; stale cached results could duplicate a capture after an interrupted write.
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_content LIKE %s LIMIT 1", CPT::POST_TYPE, '%' . $wpdb->esc_like( $marker ) . '%' ) );
			if ( ! $post_id && 'daily' === $kind ) {
				$note = DailyNote::find( $date );
				if ( $note && ! current_user_can( 'edit_post', $note->ID ) ) {
					return new \WP_Error( 'capture_permission', __( 'You cannot edit this daily note.', 'memex' ) );
				}
				$note = $note ?: DailyNote::get_or_create( $date );
				if ( ! $note ) {
					return new \WP_Error( 'capture_daily', __( 'Could not create the daily note.', 'memex' ) );
				}
				$post_id = wp_update_post( wp_slash( array(
					'ID' => $note->ID,
					'post_content' => trim( $note->post_content ) . "\n\n" . Content::plain_text_to_blocks( trim( $content ), $clock ) . "\n" . $marker,
				) ), true );
			} elseif ( ! $post_id ) {
				$post_id = wp_insert_post( wp_slash( array(
					'post_type' => CPT::POST_TYPE,
					'post_title' => sanitize_text_field( $title ),
					'post_content' => Content::markdown_to_html( $content ) . "\n" . $marker,
					'post_author' => get_current_user_id(),
					'post_status' => 'draft',
				) ), true );
			}
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return new \WP_Error( 'capture_save', __( 'Could not save the capture. Please retry.', 'memex' ) );
			}
			$receipt = array( 'id' => $id, 'postId' => $post_id, 'url' => CPT::url( $post_id ) );
			update_option( $key, $receipt, false );
			return $receipt;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the advisory lock on this connection, which cannot be done through a cache or post API.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
