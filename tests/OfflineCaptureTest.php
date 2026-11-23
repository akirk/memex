<?php

use Memex\CPT;
use Memex\DailyNote;
use Memex\OfflineCapture;

class OfflineCaptureTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function entry( array $changes = array() ): array {
		return array_merge( array(
			'id' => wp_generate_uuid4(), 'userId' => get_current_user_id(),
			'kind' => 'daily', 'date' => '2024-02-20', 'clock' => '23:58',
			'title' => '', 'content' => 'Captured before midnight',
		), $changes );
	}

	public function test_delayed_daily_preserves_date_time_and_existing_content(): void {
		$note = DailyNote::get_or_create( '2024-02-20' );
		wp_update_post( array( 'ID' => $note->ID, 'post_content' => '<p>Existing entry</p>' ) );
		$result = OfflineCapture::save( $this->entry() );
		$this->assertSame( $note->ID, $result['postId'] );
		$content = get_post( $note->ID )->post_content;
		$this->assertStringContainsString( 'Existing entry', $content );
		$this->assertStringContainsString( '<strong>23:58</strong>', $content );
		$this->assertStringContainsString( 'Captured before midnight', $content );
		$this->assertNull( DailyNote::find( DailyNote::today() ) );
	}

	public function test_retry_after_lost_acknowledgment_does_not_duplicate_append(): void {
		$entry = $this->entry();
		$first = OfflineCapture::save( $entry );
		$this->assertSame( $first, OfflineCapture::save( $entry ) );
		$this->assertSame( 1, substr_count( get_post( $first['postId'] )->post_content, $entry['content'] ) );
	}

	public function test_recovers_receipt_after_interruption_following_post_write(): void {
		$entry = $this->entry();
		$first = OfflineCapture::save( $entry );
		delete_option( 'memex_capture_' . get_current_user_id() . '_' . $entry['id'] );
		$this->assertSame( $first, OfflineCapture::save( $entry ) );
		$this->assertSame( 1, substr_count( get_post( $first['postId'] )->post_content, $entry['content'] ) );
	}

	public function test_distinct_captures_append_to_one_daily(): void {
		$first = OfflineCapture::save( $this->entry() );
		$second = OfflineCapture::save( $this->entry( array( 'content' => 'Second entry' ) ) );
		$this->assertSame( $first['postId'], $second['postId'] );
		$this->assertStringContainsString( 'Second entry', get_post( $first['postId'] )->post_content );
	}

	public function test_new_note_saves_title_and_markdown_as_draft_and_retries_safely(): void {
		$entry = $this->entry( array( 'kind' => 'note', 'title' => 'Offline note', 'content' => '**Bold** and C:\\notes\\today' ) );
		$first = OfflineCapture::save( $entry );
		delete_option( 'memex_capture_' . get_current_user_id() . '_' . $entry['id'] );
		$this->assertSame( $first, OfflineCapture::save( $entry ) );
		$post = get_post( $first['postId'] );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Offline note', $post->post_title );
		$this->assertStringContainsString( '<strong>Bold</strong>', $post->post_content );
		$this->assertStringContainsString( 'C:\\notes\\today', $post->post_content );
	}

	public function test_rejects_wrong_account_and_read_only_users(): void {
		$entry = $this->entry();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( OfflineCapture::save( $entry ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( OfflineCapture::save( $this->entry() ) );
		$this->assertNull( DailyNote::find( $entry['date'] ) );
	}

	public function test_author_cannot_append_to_another_authors_daily(): void {
		$note = DailyNote::get_or_create( '2024-02-20' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertWPError( OfflineCapture::save( $this->entry() ) );
		$this->assertSame( '', get_post( $note->ID )->post_content );
	}

	public function test_author_captures_retain_recovery_marker_through_sanitization(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$entry = $this->entry();
		$first = OfflineCapture::save( $entry );
		delete_option( 'memex_capture_' . get_current_user_id() . '_' . $entry['id'] );
		$this->assertSame( $first, OfflineCapture::save( $entry ) );
		$this->assertSame( 1, substr_count( get_post( $first['postId'] )->post_content, $entry['content'] ) );
	}

	public function test_invalid_payloads_do_not_create_notes(): void {
		foreach ( array( array( 'date' => '2024-02-30' ), array( 'clock' => '25:12' ), array( 'id' => '../../bad' ), array( 'content' => array() ), array( 'kind' => 'delete' ) ) as $change ) {
			$this->assertWPError( OfflineCapture::save( $this->entry( $change ) ) );
		}
	}

	public function test_capture_shell_has_no_private_note_tree_or_contents(): void {
		self::factory()->post->create( array( 'post_type' => CPT::POST_TYPE, 'post_title' => 'Private title', 'post_content' => 'Private body' ) );
		global $wp_app_route;
		$previous_route = $wp_app_route;
		$wp_app_route = array( 'app_path' => 'memex' );
		\WpApp\Pwa::register( 'memex', OfflineCapture::pwa_config() );
		\Memex\memex_app()->enqueue_assets( dirname( __DIR__ ) . '/templates/shell.php' );
		ob_start();
		include dirname( __DIR__ ) . '/templates/shell.php';
		$html = ob_get_clean();
		$wp_app_route = $previous_route;
		$this->assertStringNotContainsString( 'Private title', $html );
		$this->assertStringNotContainsString( 'Private body', $html );
		$this->assertStringContainsString( 'data-memex-shell', $html );
		$version = OfflineCapture::asset_version();
		$this->assertStringContainsString( 'assets/memex.css?ver=' . $version, $html );
		$this->assertStringContainsString( 'assets/memex-shell.js?ver=' . $version, $html );
		$this->assertStringNotContainsString( 'window.memexOffline = ', $html );
		$this->assertStringContainsString( 'wp-app-outbox-js', $html );
		$this->assertStringContainsString( 'wp-app-offline-client-js', $html );
	}
	public function test_framework_owns_worker_and_only_caches_capture_shell(): void {
		$config = OfflineCapture::pwa_config();
		$this->assertSame( 'shell', $config['cache_policy'] );
		$this->assertTrue( $config['outbox'] );
		$this->assertFalse( $config['visited_pages'] );
		$this->assertTrue( $config['local_data'] );
		$this->assertSame( (string) get_current_user_id(), $config['cache_identity'] );
		$this->assertSame( home_url( '/memex/' ), $config['start_url'] );
		$this->assertSame( 'offline-worker', $config['service_worker_path'] );
		$this->assertSame( home_url( '/memex/capture/' ), $config['offline_url'] );
		$this->assertNotContains( home_url( '/memex/' ), $config['precache'] );
		$this->assertSame( OfflineCapture::required_urls(), $config['precache'] );
	}

    public function test_backfill_saves_a_daily_draft_and_retries_without_duplication(): void {
        $entry = $this->entry( array( 'kind' => 'note', 'daily' => '2024-02-19', 'title' => 'Backfilled day', 'content' => 'Written later' ) );
        $result = OfflineCapture::save( $entry );
        $this->assertIsArray( $result );
        $this->assertSame( 'draft', get_post_status( $result['postId'] ) );
        $this->assertSame( '2024-02-19', get_post_meta( $result['postId'], CPT::META_DAILY, true ) );
        $this->assertSame( $result, OfflineCapture::save( $entry ) );
        $this->assertSame( $result['postId'], DailyNote::find( '2024-02-19' )->ID );
    }

    public function test_backfill_can_publish_but_cannot_replace_an_existing_daily(): void {
        $entry = $this->entry( array( 'kind' => 'note', 'daily' => '2024-02-18', 'title' => 'Published day', 'publish' => true ) );
        $result = OfflineCapture::save( $entry );
        $this->assertSame( 'publish', get_post_status( $result['postId'] ) );
        $entry['id'] = wp_generate_uuid4();
        $entry['content'] = 'Do not overwrite';
        $conflict = OfflineCapture::save( $entry );
        $this->assertWPError( $conflict );
        $this->assertSame( 'conflict', $conflict->get_error_code() );
        $this->assertStringNotContainsString( 'Do not overwrite', get_post( $result['postId'] )->post_content );
    }

    public function test_backfill_rejects_invalid_or_future_dates(): void {
        foreach ( array( '2024-02-30', '2999-01-01', array() ) as $date ) {
            $this->assertWPError( OfflineCapture::save( $this->entry( array( 'kind' => 'note', 'title' => 'Invalid daily', 'daily' => $date ) ) ) );
        }
    }

}
