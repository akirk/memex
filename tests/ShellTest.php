<?php
use Memex\CPT;
use Memex\Shell;
use Memex\OfflineCapture;

class ShellTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}
	private function note(): WP_Post {
		return get_post(
            self::factory()->post->create(
                [
					'post_type'    => CPT::POST_TYPE,
					'post_title'   => 'Original',
					'post_content' => '<p>Original content</p>',
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
				]
            )
        );
	}
	private function edit( WP_Post $post, array $extra = [] ): array {
		return array_merge(
            [
				'id'          => wp_generate_uuid4(),
				'userId'      => get_current_user_id(),
				'kind'        => 'edit',
				'noteId'      => (string) $post->ID,
				'baseVersion' => Shell::version( $post ),
				'title'       => 'Updated',
				'content'     => '**Updated content**',
				'parent'      => 0,
			],
			$extra
        );
	}
	public function test_edits_preserve_status_and_return_a_durable_receipt(): void {
		$post   = $this->note();
		$entry  = $this->edit( $post );
		$result = OfflineCapture::save( $entry );
		$this->assertIsArray( $result );
		$this->assertSame( $post->ID, $result['postId'] );
		$this->assertSame( 'publish', get_post_status( $post ) );
		$this->assertStringContainsString( '<strong>Updated content</strong>', get_post( $post->ID )->post_content );
		$this->assertSame( $result, OfflineCapture::save( $entry ) );
		$this->assertSame( Shell::version( get_post( $post->ID ) ), $result['note']['version'] );
	}
	public function test_stale_baseline_returns_current_server_note_without_overwriting_it(): void {
		$post  = $this->note();
		$entry = $this->edit( $post );
		wp_update_post(
            [
				'ID'           => $post->ID,
				'post_content' => '<p>Another device</p>',
			]
        );
		$result = OfflineCapture::save( $entry );
		$this->assertWPError( $result );
		$this->assertSame( 'conflict', $result->get_error_code() );
		$this->assertSame( '<p>Another device</p>', get_post( $post->ID )->post_content );
		$this->assertStringContainsString( 'Another device', $result->get_error_data()['note']['content'] );
	}
	public function test_offline_edits_can_chain_from_the_preceding_receipt(): void {
		$post   = $this->note();
		$first  = $this->edit( $post );
		$second = $this->edit(
            $post,
            [
				'baseOperation' => $first['id'],
				'content'       => 'Second offline edit',
			]
        );
		$this->assertIsArray( OfflineCapture::save( $first ) );
		$result = OfflineCapture::save( $second );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Second offline edit', get_post( $post->ID )->post_content );
	}
	public function test_offline_creation_can_be_followed_by_an_edit_before_the_id_is_known(): void {
		$create  = [
			'id'      => wp_generate_uuid4(),
			'kind'    => 'note',
			'userId'  => get_current_user_id(),
			'title'   => 'Created offline',
			'content' => 'First content',
		];
		$created = OfflineCapture::save( $create );
		$this->assertIsArray( $created );
		$result = OfflineCapture::save(
            [
				'id'            => wp_generate_uuid4(),
				'kind'          => 'edit',
				'userId'        => get_current_user_id(),
				'noteId'        => 'local-' . $create['id'],
				'baseVersion'   => '',
				'baseOperation' => $create['id'],
				'title'         => 'Edited offline',
				'content'       => 'Final content',
			]
        );
		$this->assertIsArray( $result );
		$this->assertSame( $created['postId'], $result['postId'] );
		$this->assertStringContainsString( 'Final content', get_post( $created['postId'] )->post_content );
		$this->assertSame( 'draft', get_post_status( $created['postId'] ) );
	}
	public function test_failed_receipt_write_rolls_back_the_note(): void {
		$post  = $this->note();
		$entry = $this->edit( $post );
		$key   = 'memex_capture_' . get_current_user_id() . '_' . $entry['id'];
		$fail  = static function () {
			return false;
		};
		add_filter( 'pre_update_option_' . $key, $fail );
		try {
			$result = OfflineCapture::save( $entry );
		} finally {
			remove_filter( 'pre_update_option_' . $key, $fail ); }
		$this->assertWPError( $result );
		$this->assertSame( 'Original', get_post( $post->ID )->post_title );
		$this->assertSame( '<p>Original content</p>', get_post( $post->ID )->post_content );
		$this->assertFalse( get_option( $key ) );
	}
	public function test_wrong_accounts_and_invalid_parent_cycles_cannot_write(): void {
		$post  = $this->note();
		$entry = $this->edit( $post, [ 'parent' => $post->ID ] );
		$this->assertWPError( OfflineCapture::save( $entry ) );
		$entry = $this->edit( $post );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertWPError( OfflineCapture::save( $entry ) );
		$this->assertSame( 'Original', get_post( $post->ID )->post_title );
	}
	public function test_metadata_snapshot_omits_note_bodies_and_versions_include_title_changes(): void {
		$post    = $this->note();
		$summary = Shell::note( $post, false );
		$this->assertArrayNotHasKey( 'content', $summary );
		$this->assertArrayNotHasKey( 'html', $summary );
		wp_update_post(
            [
				'ID'         => $post->ID,
				'post_title' => 'Changed title',
			]
        );
		$this->assertNotSame( $summary['version'], Shell::version( get_post( $post->ID ) ) );
	}
	public function test_retry_receipts_keep_the_original_baseline_after_later_server_changes(): void {
		$post  = $this->note();
		$first = $this->edit( $post );
		$this->assertIsArray( OfflineCapture::save( $first ) );
		$stored = get_option( 'memex_capture_' . get_current_user_id() . '_' . $first['id'] );
		$this->assertArrayNotHasKey( 'note', $stored );
		wp_update_post(
            [
				'ID'           => $post->ID,
				'post_content' => '<p>Changed after the first save</p>',
			]
        );
		$retry = OfflineCapture::save( $first );
		$this->assertSame( $stored['version'], $retry['version'] );
		$second = $this->edit( $post, [ 'baseOperation' => $first['id'] ] );
		$this->assertSame( 'conflict', OfflineCapture::save( $second )->get_error_code() );
		$this->assertSame( '<p>Changed after the first save</p>', get_post( $post->ID )->post_content );
	}
	public function test_framework_fallback_enforces_the_app_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		\WpApp\Pwa::register( 'memex', OfflineCapture::pwa_config() );
		$this->expectException( WPDieException::class );
		\WpApp\Pwa::maybe_handle_app_request( 'memex', 'capture' );
	}
    public function test_note_excerpt_decodes_html_entities_for_text_rendering(): void {
        $post = get_post( self::factory()->post->create( array( 'post_type' => CPT::POST_TYPE, 'post_title' => 'Daily preview', 'post_content' => '<p><strong>04:53</strong> &middot; Login &amp; sharing</p>', 'post_status' => 'draft' ) ) );
        $note = \Memex\Shell::note( $post, false );
        $this->assertSame( '04:53 · Login & sharing', $note['excerpt'] );
    }

}
