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

	public function test_calendar_data_only_links_readable_notes_in_selected_month(): void {
		$note = DailyNote::get_or_create( '2024-02-29' );
		DailyNote::get_or_create( '2024-03-01' );
		$data = DailyNote::calendar_data( '2024-02' );
		$this->assertSame( array( '2024-02-29' => CPT::url( $note ) ), $data['days'] );
	}

	public function test_calendar_data_clamps_future_month_and_omits_future_notes(): void {
		$today = new DateTimeImmutable( DailyNote::today(), wp_timezone() );
		$future = $today->modify( '+1 day' )->format( 'Y-m-d' );
		DailyNote::get_or_create( $future );
		$data = DailyNote::calendar_data( $today->modify( '+1 month' )->format( 'Y-m' ) );
		$this->assertSame( $today->format( 'Y-m' ), $data['month'] );
		$this->assertArrayNotHasKey( $future, $data['days'] );
		$this->assertSame( $today->modify( '+1 day' )->getTimestamp(), $data['refreshAfter'] );
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
