<?php

use Memex\CPT;
use Memex\DailyNote;

class DailyCaptureTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render_note( WP_Post $note ): string {
		set_query_var( 'slug', $note->post_name );
		ob_start();
		include dirname( __DIR__ ) . '/templates/note.php';
		return ob_get_clean();
	}

	public function test_today_capture_follows_last_entry_and_is_not_in_sidebar(): void {
		$note = DailyNote::get_or_create( DailyNote::today() );
		wp_update_post( array( 'ID' => $note->ID, 'post_content' => '<p>Last captured entry</p>' ) );
		$html = $this->render_note( get_post( $note->ID ) );
		$this->assertSame( 1, substr_count( $html, 'name="action" value="memex_quick_capture"' ) );
		$this->assertGreaterThan( strpos( $html, 'Last captured entry' ), strpos( $html, 'memex-quick-capture-inline' ) );
		$sidebar = substr( $html, strpos( $html, '<aside' ), strpos( $html, '</aside>' ) - strpos( $html, '<aside' ) );
		$this->assertStringNotContainsString( 'memex_quick_capture', $sidebar );
		$this->assertStringContainsString( '<input type="text" name="content"', $html );
		$this->assertStringNotContainsString( '<textarea name="content"', $html );
	}

	public function test_empty_today_shows_capture_instead_of_empty_note_notice(): void {
		$html = $this->render_note( DailyNote::get_or_create( DailyNote::today() ) );
		$this->assertStringContainsString( 'memex-quick-capture-inline', $html );
		$this->assertStringNotContainsString( 'This note is empty.', $html );
	}

	public function test_opening_today_without_a_daily_does_not_create_one(): void {
		set_query_var( 'date', '' );
		ob_start();
		include dirname( __DIR__ ) . '/templates/daily.php';
		$html = ob_get_clean();
		$this->assertNull( DailyNote::find( DailyNote::today() ) );
		$this->assertStringContainsString( 'memex-quick-capture-inline', $html );
		$this->assertSame( 1, substr_count( $html, 'name="action" value="memex_quick_capture"' ) );
	}

	public function test_older_daily_keeps_sidebar_capture(): void {
		$html = $this->render_note( DailyNote::get_or_create( '2024-02-20' ) );
		$this->assertStringNotContainsString( 'memex-quick-capture-inline', $html );
		$this->assertSame( 1, substr_count( $html, 'name="action" value="memex_quick_capture"' ) );
	}
}
