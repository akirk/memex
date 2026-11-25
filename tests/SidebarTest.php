<?php

use Memex\Sidebar;

class SidebarTest extends WP_UnitTestCase {
	public function test_sidebar_choices_are_normalized_and_belong_to_the_current_user(): void {
		$first = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$second = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $first );
		$saved = Sidebar::save( [
			'sections' => [ [ 'id' => 'search', 'visible' => true ], [ 'id' => 'quick-capture', 'visible' => false ], [ 'id' => 'search', 'visible' => false ], [ 'id' => 'unknown', 'visible' => true ] ],
			'navigation' => [ [ 'id' => 'daily', 'visible' => false ] ],
		] );
		$this->assertSame( [
			[ 'id' => 'search', 'visible' => true ],
			[ 'id' => 'quick-capture', 'visible' => false ],
			[ 'id' => 'navigation', 'visible' => true ],
			[ 'id' => 'folders', 'visible' => true ],
			[ 'id' => 'create', 'visible' => true ],
			[ 'id' => 'sync', 'visible' => true ],
		], $saved['sections'] );
		$this->assertSame( $saved, Sidebar::get() );
		$this->assertSame( [ 'id' => 'daily', 'visible' => false ], $saved['navigation'][0] );
		wp_set_current_user( $second );
		$this->assertSame( [ 'id' => 'quick-capture', 'visible' => true ], Sidebar::get()['sections'][0] );
		$this->assertSame( [ 'id' => 'search', 'visible' => false ], Sidebar::get()['sections'][1] );
	}

	public function test_users_without_app_access_cannot_change_sidebar_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = Sidebar::save( [ 'sections' => [ [ 'id' => 'search', 'visible' => true ] ] ] );
		$this->assertWPError( $result );
		$this->assertSame( 'permission', $result->get_error_code() );
		$this->assertFalse( get_user_option( 'memex_sidebar' ) );
	}
}
