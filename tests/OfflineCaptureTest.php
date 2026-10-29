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
		ob_start();
		include dirname( __DIR__ ) . '/templates/capture.php';
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Private title', $html );
		$this->assertStringNotContainsString( 'Private body', $html );
		$this->assertStringContainsString( 'data-memex-capture="choice"', $html );
		$version = OfflineCapture::asset_version();
		$this->assertStringContainsString( 'assets/memex.css?ver=' . $version, $html );
		$this->assertStringContainsString( 'assets/memex-offline.js?ver=' . $version, $html );
		$this->assertStringContainsString( 'window.memexOffline = ', $html );
		$this->assertSame( $version, wp_styles()->registered['memex-capture-shell']->ver );
		$this->assertSame( $version, wp_scripts()->registered['memex-capture-shell']->ver );
	}
	public function test_worker_endpoint_never_redirects_but_other_routes_keep_their_redirect(): void {
		$this->assertFalse( OfflineCapture::worker_redirect( 'canonical', home_url( '/memex/offline-worker' ) ) );
		$this->assertFalse( OfflineCapture::worker_redirect( 'canonical', home_url( '/memex/offline-worker/' ) ) );
		$this->assertSame( 'canonical', OfflineCapture::worker_redirect( 'canonical', home_url( '/memex/note/example' ) ) );
	}

}
