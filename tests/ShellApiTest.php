<?php
use Memex\CPT;

/**
 * Shell API authorization and response contracts.
 *
 * @group ajax
 */
class ShellApiTest extends WP_Ajax_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) ); }
	private function read( array $params = [] ): array {
		$_GET = $params;
		try {
			$this->_handleAjax( 'memex_shell_notes' ); } catch ( WPAjaxDieContinueException $exception ) {
			}
			return json_decode( $this->_last_response, true );
	}
	public function test_note_index_has_identity_and_metadata_but_no_note_bodies(): void {
		$id       = self::factory()->post->create(
            [
				'post_type'    => CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => 'Note body',
				'post_author'  => get_current_user_id(),
			]
        );
		$response = $this->read();
		$this->assertTrue( $response['success'] );
		$this->assertSame( get_current_user_id(), $response['data']['userId'] );
		$notes = array_column( $response['data']['notes'], null, 'id' );
		$this->assertArrayHasKey( (string) $id, $notes );
		$this->assertArrayNotHasKey( 'content', $notes[ $id ] );
		$this->assertArrayNotHasKey( 'html', $notes[ $id ] );
	}
	public function test_subscribers_cannot_download_app_notes(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$response = $this->read();
		$this->assertFalse( $response['success'] );
	}
	public function test_private_notes_are_filtered_from_another_authors_index_and_detail(): void {
		$id = self::factory()->post->create(
            [
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'private',
				'post_author' => get_current_user_id(),
			]
        );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$response = $this->read();
		$this->assertNotContains( (string) $id, array_column( $response['data']['notes'], 'id' ) );
		$this->_last_response = '';
		$response             = $this->read( [ 'id' => $id ] );
		$this->assertFalse( $response['success'] );
	}
}
