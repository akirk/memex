<?php

use Memex\App;
use Memex\CPT;
use Memex\DailyNote;

class NestedNoteTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function create_nested( string $title, int $parent ): void {
		$_POST = array( 'title' => $title, 'parent' => $parent, '_wpnonce' => wp_create_nonce( 'memex_create_note' ) );
		$_REQUEST = $_POST;
		$redirect = static function ( $location ) {
			throw new RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			( new App() )->handle_create_note();
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( '/memex/', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			$_POST = array();
			$_REQUEST = array();
		}
	}

	public function test_nested_creation_does_not_reuse_a_top_level_note_with_the_same_title(): void {
		$existing = self::factory()->post->create( array( 'post_type' => CPT::POST_TYPE, 'post_title' => 'test', 'post_status' => 'publish' ) );
		$daily = DailyNote::get_or_create( DailyNote::today() );
		$this->create_nested( 'test', $daily->ID );
		$children = get_posts( array( 'post_type' => CPT::POST_TYPE, 'post_parent' => $daily->ID ) );
		$this->assertCount( 1, $children );
		$this->assertSame( 'test', $children[0]->post_title );
		$this->assertSame( 0, (int) get_post( $existing )->post_parent );
	}

	public function test_nested_creation_reuses_a_note_already_under_the_requested_parent(): void {
		$daily = DailyNote::get_or_create( DailyNote::today() );
		$this->create_nested( 'Child note', $daily->ID );
		$this->create_nested( 'Child note', $daily->ID );
		$children = get_posts( array( 'post_type' => CPT::POST_TYPE, 'post_parent' => $daily->ID ) );
		$this->assertCount( 1, $children );
	}
}
