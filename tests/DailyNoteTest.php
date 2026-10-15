<?php

use Memex\CPT;
use Memex\DailyNote;

class DailyNoteTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_daily_tag_survives_replacing_tags(): void {
		$note = DailyNote::get_or_create( '2026-02-12' );
		$this->assertTrue( has_term( 'daily', CPT::TAXONOMY, $note->ID ) );
		wp_set_object_terms( $note->ID, array( 'work' ), CPT::TAXONOMY );
		$this->assertTrue( has_term( 'daily', CPT::TAXONOMY, $note->ID ) );
		$this->assertTrue( has_term( 'work', CPT::TAXONOMY, $note->ID ) );
	}

	public function test_existing_dailies_are_backfilled_without_losing_tags(): void {
		$note = DailyNote::get_or_create( '2026-02-12' );
		wp_set_object_terms( $note->ID, array( 'work' ), CPT::TAXONOMY );
		wp_remove_object_terms( $note->ID, 'daily', CPT::TAXONOMY );
		delete_option( 'memex_daily_tags_migrated' );
		DailyNote::register();
		$this->assertTrue( has_term( 'daily', CPT::TAXONOMY, $note->ID ) );
		$this->assertTrue( has_term( 'work', CPT::TAXONOMY, $note->ID ) );
	}

	public function test_calendar_only_links_existing_notes_in_selected_month(): void {
		$note = DailyNote::get_or_create( '2024-02-29' );
		$other = DailyNote::get_or_create( '2024-03-01' );
		update_option( 'start_of_week', 1 );
		ob_start();
		DailyNote::render_calendar( '2024-02', '2024-02-29' );
		$html = ob_get_clean();
		$this->assertStringContainsString( esc_url( CPT::url( $note ) ), $html );
		$this->assertStringNotContainsString( esc_url( CPT::url( $other ) ), $html );
		$this->assertStringContainsString( 'aria-current="date"', $html );
		$this->assertStringContainsString( 'data-calendar-month="2024-01"', $html );
		$this->assertStringContainsString( 'data-calendar-month="2024-03"', $html );
		$this->assertStringContainsString( '>29</a>', $html );
		$this->assertStringNotContainsString( '>30</span>', $html );
	}

	public function test_future_month_is_clamped_and_future_notes_are_not_linked(): void {
		$today  = new DateTimeImmutable( DailyNote::today(), wp_timezone() );
		$future = DailyNote::get_or_create( $today->modify( '+1 day' )->format( 'Y-m-d' ) );
		ob_start();
		DailyNote::render_calendar( $today->modify( '+1 month' )->format( 'Y-m' ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-month="' . $today->format( 'Y-m' ) . '"', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringNotContainsString( esc_url( CPT::url( $future ) ), $html );
		$this->assertStringContainsString( 'data-refresh-after="' . $today->modify( '+1 day' )->getTimestamp() . '"', $html );
	}

	public function test_opening_backfill_editor_does_not_create_a_post(): void {
		$_GET['daily'] = '2024-02-20';
		$before = wp_count_posts( CPT::POST_TYPE );
		ob_start();
		include dirname( __DIR__ ) . '/templates/new.php';
		$html = ob_get_clean();
		unset( $_GET['daily'] );
		$this->assertEquals( $before, wp_count_posts( CPT::POST_TYPE ) );
		$this->assertNull( DailyNote::find( '2024-02-20' ) );
		$this->assertStringContainsString( 'Save draft', $html );
		$this->assertStringContainsString( 'name="daily" value="2024-02-20"', $html );
	}

	public function test_saved_backfill_is_a_tagged_draft_and_does_not_overwrite_existing_daily(): void {
		$note = DailyNote::save_new( '2024-02-20', 'February notes', '<p>Hello</p>' );
		$this->assertInstanceOf( WP_Post::class, $note );
		$this->assertSame( 'draft', $note->post_status );
		$this->assertTrue( has_term( 'daily', CPT::TAXONOMY, $note->ID ) );
		$this->assertSame( '2024-02-20', get_post_meta( $note->ID, CPT::META_DAILY, true ) );
		$again = DailyNote::save_new( '2024-02-20', 'Replacement', '<p>Different</p>' );
		$this->assertSame( $note->ID, $again->ID );
		$this->assertSame( '<p>Hello</p>', $again->post_content );
	}

	public function test_calendar_data_contains_links_and_no_html(): void {
		$note = DailyNote::get_or_create( '2024-02-20' );
		$data = DailyNote::calendar_data( '2024-02' );
		$this->assertSame( CPT::url( $note ), $data['days']['2024-02-20'] );
		$this->assertArrayNotHasKey( 'html', $data );
	}
}
