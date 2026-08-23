<?php

use Memex\Importer\Evernote;
use Memex\Importer\Importer;
use Memex\Importer\Job;
use Memex\Importer\Markdown;
use Memex\Importer\Notion;
use Memex\Importer\Roam;
use Memex\Importer\Thinkery;

/**
 * Importers under the chunked contract: prepare() lists work, import_item()
 * does one unit, Job drives both across several "requests".
 */
class ImportTest extends WP_UnitTestCase {
	private string $dir;

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// Imports run from the admin screen, so with unfiltered_html.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Keep import jobs and fixtures out of the real uploads directory.
		$this->tmp = sys_get_temp_dir() . '/memex-tests-' . getmypid();
		Importer::rrmdir( $this->tmp );
		mkdir( $this->tmp, 0777, true );
		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		$this->dir = $this->tmp . '/fixtures';
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		Importer::rrmdir( $this->tmp );
		parent::tearDown();
	}

	public function upload_dir( array $dirs ): array {
		$dirs['basedir'] = $this->tmp . '/uploads';
		$dirs['path']    = $dirs['basedir'] . $dirs['subdir'];
		if ( ! is_dir( $dirs['path'] ) ) {
			mkdir( $dirs['path'], 0777, true );
		}
		return $dirs;
	}

	/** ID of the note with this title (case-insensitive), preferring published; 0 if none. */
	private function find_by_title( string $title ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = 'memex_note' AND post_status <> 'trash' AND LOWER(post_title) = LOWER(%s) ORDER BY post_status = 'publish' DESC, ID ASC LIMIT 1",
				$title
			)
		);
	}

	/** @return string[] */
	private function tags( int $id ): array {
		return wp_get_object_terms( $id, 'memex_tag', array( 'fields' => 'names', 'orderby' => 'term_id' ) );
	}

	/** @return WP_Post[] */
	private function notes(): array {
		return get_posts( array( 'post_type' => 'memex_note', 'post_status' => 'any', 'numberposts' => -1 ) );
	}

	/* ─── Helpers ─── */

	private function file( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	private function zip( string $name, array $files ): string {
		$path = $this->dir . '/' . $name;
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $files as $entry => $content ) {
			$zip->addFromString( $entry, $content );
		}
		$zip->close();
		return $path;
	}

	/** Run an importer to completion in one go, the way Job does. */
	private function run_importer( Importer $importer, string $path ): array {
		$work = $this->dir . '/work-' . bin2hex( random_bytes( 3 ) );
		mkdir( $work );
		$state = Importer::initial_state();
		$items = array();
		do {
			$prepared = $importer->prepare( $path, $work, $state, static fn() => true );
			$items    = array_merge( $items, $prepared['items'] );
		} while ( ! $prepared['complete'] );
		$ids = array();
		foreach ( $items as $item ) {
			$id = $importer->import_item( $item, $state );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array(
			'ids'    => $ids,
			'items'  => $items,
			'errors' => $state['errors'],
			'state'  => $state,
		);
	}

	private function content( string $title ): string {
		return (string) get_post( $this->find_by_title( $title ) )->post_content;
	}

	/* ─── Markdown / Obsidian ─── */

	public function test_markdown_zip_prepares_one_item_per_file_and_builds_folder_hierarchy(): void {
		$zip = $this->zip(
			'vault.zip',
			array(
				'Projects/Alpha.md'    => "---\ntitle: Alpha\ntags: [one, two]\n---\nSee [[Beta]] #three",
				'Projects/Sub/Beta.md' => '# Beta heading',
				'attachment.png'       => 'binary',
			)
		);
		$r = $this->run_importer( new Markdown(), $zip );

		$this->assertCount( 2, $r['items'] );
		$this->assertCount( 2, $r['ids'] );
		$this->assertSame( array(), $r['errors'] );

		$alpha = get_post( $this->find_by_title( 'Alpha' ) );
		$this->assertStringContainsString( '[[Beta]]', $alpha->post_content );
		$this->assertSame( array( 'one', 'two', 'three' ), $this->tags( $alpha->ID ) );
		$this->assertSame( 'Projects', get_post( $alpha->post_parent )->post_title );

		$beta = get_post( $this->find_by_title( 'Beta heading' ) );
		$this->assertSame( 'Sub', get_post( $beta->post_parent )->post_title );
		$this->assertSame( 'Projects', get_post( get_post( $beta->post_parent )->post_parent )->post_title );
		$this->assertSame( 'Projects/Sub/Beta.md', get_post_meta( $beta->ID, '_memex_import_path', true ) );
	}

	public function test_markdown_single_file_is_one_item(): void {
		$r = $this->run_importer( new Markdown(), $this->file( 'note.md', "Hello *world*" ) );
		$this->assertCount( 1, $r['items'] );
		$this->assertStringContainsString( '<em>world</em>', $this->content( 'note' ) );
	}

	public function test_markdown_folder_parents_survive_a_state_round_trip(): void {
		// Folder parents are remembered in state so a later request re-uses them.
		$zip = $this->zip(
			'vault.zip',
			array(
				'Folder/A.md' => 'a',
				'Folder/B.md' => 'b',
			)
		);
		$importer = new Markdown();
		$work     = $this->dir . '/w';
		mkdir( $work );
		$state    = Importer::initial_state();
		$prepared = $importer->prepare( $zip, $work, $state, static fn() => true );
		$importer->import_item( $prepared['items'][0], $state );
		$state = unserialize( serialize( $state ) );
		$importer->import_item( $prepared['items'][1], $state );

		$folders = array_filter( $this->notes(), static fn( $p ) => 'Folder' === $p->post_title );
		$this->assertCount( 1, $folders );
	}

	public function test_markdown_bad_zip_reports_failure(): void {
		$r = $this->run_importer( new Markdown(), $this->file( 'broken.zip', 'not a zip' ) );
		$this->assertSame( array(), $r['items'] );
		$this->assertSame( array( 'Could not extract ZIP archive.' ), $r['errors'] );
	}

	/* ─── Notion ─── */

	public function test_notion_two_passes_rewrite_internal_links_to_titles(): void {
		$hex = str_repeat( 'a', 32 );
		$hex2 = str_repeat( 'b', 32 );
		$zip  = $this->zip(
			'notion.zip',
			array(
				"Home $hex.html"           => "<html><body><article>Go to <a href=\"Home%20$hex/Child%20$hex2.html\">the child</a></article></body></html>",
				"Home $hex/Child $hex2.html" => '<html><body><article><p>Child body</p></article></body></html>',
			)
		);
		$r = $this->run_importer( new Notion(), $zip );

		$this->assertCount( 4, $r['items'], 'two pages × two passes' );
		$this->assertSame( array( 1, 1, 2, 2 ), array_column( $r['items'], 0 ), 'pass 1 items come before pass 2' );
		$this->assertCount( 2, $r['ids'] );

		$this->assertStringContainsString( '[[Child|the child]]', $this->content( 'Home' ) );
		$child = get_post( $this->find_by_title( 'Child' ) );
		$this->assertSame( 'Home', get_post( $child->post_parent )->post_title );
		$this->assertStringContainsString( 'Child body', $child->post_content );
	}

	/* ─── Roam ─── */

	public function test_roam_pages_are_stashed_per_file_and_filled_in_pass_two(): void {
		$json = $this->file(
			'roam.json',
			json_encode(
				array(
					array(
						'title'    => 'First',
						'children' => array(
							array(
								'string'   => 'Links to [[Second]] #tagged',
								'children' => array( array( 'string' => '{{TODO}} nested' ) ),
							),
						),
					),
					array( 'title' => 'Second' ),
					array( 'title' => '' ),
				)
			)
		);
		$r = $this->run_importer( new Roam(), $json );

		$this->assertCount( 4, $r['items'] );
		$this->assertCount( 2, $r['ids'] );
		$first = $this->content( 'First' );
		$this->assertStringContainsString( '[[Second]]', $first );
		$this->assertStringContainsString( '<ul><li>', $first );
		$this->assertStringContainsString( '<input type="checkbox" disabled>', $first );
		$this->assertSame( array( 'tagged' ), $this->tags( $this->find_by_title( 'First' ) ) );
	}

	public function test_roam_invalid_json_fails_prepare(): void {
		$r = $this->run_importer( new Roam(), $this->file( 'roam.json', '{nope' ) );
		$this->assertSame( array( 'Invalid JSON in Roam export.' ), $r['errors'] );
	}

	/* ─── Evernote ─── */

	private function enex( array $notes ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE en-export SYSTEM "http://xml.evernote.com/pub/evernote-export3.dtd"><en-export>';
		foreach ( $notes as $n ) {
			$xml .= '<note><title>' . $n['title'] . '</title><content><![CDATA[<?xml version="1.0"?><!DOCTYPE en-note SYSTEM "x"><en-note>' . $n['body'] . '</en-note>]]></content>';
			foreach ( $n['tags'] ?? array() as $t ) {
				$xml .= "<tag>$t</tag>";
			}
			$xml .= '<created>20240305T133000Z</created></note>';
		}
		return $this->file( 'notes.enex', $xml . '</en-export>' );
	}

	public function test_evernote_streams_notes_into_one_file_each(): void {
		$path = $this->enex(
			array(
				array( 'title' => 'One', 'body' => '<div>Hi <en-todo checked="true"/> done</div>', 'tags' => array( 'x', 'y' ) ),
				array( 'title' => '', 'body' => '<en-media hash="abc"/>' ),
			)
		);
		$r = $this->run_importer( new Evernote(), $path );

		$this->assertCount( 2, $r['items'] );
		foreach ( $r['items'] as $item ) {
			$this->assertFileExists( $item );
			$this->assertStringStartsWith( '<note>', file_get_contents( $item ) );
		}
		$this->assertCount( 2, $r['ids'] );
		$one = get_post( $this->find_by_title( 'One' ) );
		$this->assertStringContainsString( '<input type="checkbox" checked disabled>', $one->post_content );
		$this->assertSame( '2024-03-05 13:30:00', $one->post_date_gmt );
		$this->assertSame( array( 'x', 'y' ), $this->tags( $one->ID ) );
		$this->assertStringContainsString( '[attachment]', $this->content( 'Untitled note' ) );
	}

	public function test_evernote_prepare_resumes_after_a_time_budget(): void {
		$path = $this->enex(
			array(
				array( 'title' => 'A', 'body' => 'a' ),
				array( 'title' => 'B', 'body' => 'b' ),
				array( 'title' => 'C', 'body' => 'c' ),
			)
		);
		$importer = new Evernote();
		$work     = $this->dir . '/w';
		mkdir( $work );
		$state = Importer::initial_state();
		$calls = 0;
		// Budget allows exactly one note per call.
		$within = function () use ( &$calls ) {
			return 0 === $calls++ % 2;
		};

		$rounds = array();
		do {
			$calls = 0;
			$r     = $importer->prepare( $path, $work, $state, $within );
			$state = unserialize( serialize( $state ) );
			$rounds[] = array_map( 'basename', $r['items'] );
		} while ( ! $r['complete'] );

		$this->assertSame( array( array( '0.xml' ), array( '1.xml' ), array( '2.xml' ) ), $rounds );
		$this->assertSame( 3, $state['prepared'] );
		$this->assertSame( array(), $state['errors'] );
	}

	public function test_evernote_invalid_xml_fails_prepare(): void {
		$r = $this->run_importer( new Evernote(), $this->file( 'bad.enex', '<en-export><note><title>x' ) );
		$this->assertSame( array(), $r['ids'] );
		$this->assertNotEmpty( $r['errors'] );
	}

	/* ─── Thinkery ─── */

	public function test_thinkery_json_and_xml_produce_the_same_notes(): void {
		$things = array(
			array( 'title' => 'Bookmark', 'url' => 'https://example.com/', 'tags' => 'a b @someone', 'date' => 'Tue, 05 Mar 2024 13:30:00 +0000', 'html' => '<p>Body</p>' ),
			array( 'title' => '', 'url' => '', 'html' => 'no title' ),
		);
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.json', json_encode( $things ) ) );
		$this->assertCount( 2, $r['items'] );
		$this->assertCount( 1, $r['ids'] );
		$this->assertSame( 1, $r['state']['skipped'] );
		$json_content = $this->content( 'Bookmark' );
		$this->assertStringStartsWith( '<p><a href="https://example.com/">https://example.com/</a></p>', $json_content );
		$this->assertSame( array( 'a', 'b' ), $this->tags( $this->find_by_title( 'Bookmark' ) ) );
		$this->assertSame( '2024-03-05 13:30:00', get_post( $this->find_by_title( 'Bookmark' ) )->post_date_gmt );

		foreach ( $this->notes() as $note ) {
			wp_delete_post( $note->ID, true );
		}
		$xml = '<thinkery>';
		foreach ( $things as $t ) {
			$xml .= '<thing>';
			foreach ( $t as $k => $v ) {
				$xml .= "<$k>" . htmlspecialchars( $v ) . "</$k>";
			}
			$xml .= '</thing>';
		}
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.xml', $xml . '</thinkery>' ) );
		$this->assertStringEndsWith( '.xml', $r['items'][0] );
		$this->assertCount( 1, $r['ids'] );
		$this->assertSame( $json_content, $this->content( 'Bookmark' ) );
	}

	public function test_thinkery_bookmark_extracts_do_not_create_stubs(): void {
		$things = array(
			array( 'title' => 'Wiki docs', 'url' => 'https://example.com/api', 'html' => '<div id="extract"><pre>Get links from the [[Some Page]]</pre></div>' ),
			array( 'title' => 'Plain note', 'url' => '', 'html' => '<p>See [[Linked Note]]</p>' ),
		);
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.json', json_encode( $things ) ) );
		$this->assertCount( 2, $r['ids'] );
		foreach ( $r['ids'] as $id ) {
			Importer::resolve_links( $id );
		}
		$this->assertStringContainsString( '&#91;&#91;Some Page&#93;&#93;', $this->content( 'Wiki docs' ) );
		$this->assertSame( 0, $this->find_by_title( 'Some Page' ) );
		$this->assertSame( '1', get_post_meta( $this->find_by_title( 'Linked Note' ), '_memex_stub', true ) );
	}

	public function test_thinkery_import_into_existing_stub_keeps_exported_date(): void {
		$stub = wp_insert_post( array( 'post_type' => 'memex_note', 'post_status' => 'draft', 'post_title' => 'Old Bookmark', 'post_content' => '' ) );
		update_post_meta( $stub, '_memex_stub', 1 );
		$things = array(
			array( 'title' => 'Old Bookmark', 'url' => 'https://example.com/', 'date' => 'Wed, 23 Nov 2005 09:18:14 +0000', 'html' => false ),
		);
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.json', json_encode( $things ) ) );
		$this->assertSame( array( $stub ), $r['ids'] );
		$post = get_post( $stub );
		$this->assertSame( '2005-11-23 09:18:14', $post->post_date_gmt );
		$this->assertSame( '', (string) get_post_meta( $stub, '_memex_stub', true ) );
	}

	public function test_thinkery_same_title_things_stay_separate_notes(): void {
		$things = array(
			array( 'title' => 'label', 'url' => false, 'date' => 'Sat, 27 Apr 2013 05:08:45 +0000', 'html' => '<p>a short note</p>' ),
			array( 'title' => 'Label', 'url' => 'https://example.com/page', 'date' => 'Sun, 28 Apr 2013 05:08:45 +0000', 'html' => false ),
		);
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.json', json_encode( $things ) ) );
		$this->assertCount( 2, $r['ids'] );
		$this->assertNotSame( $r['ids'][0], $r['ids'][1] );
		$this->assertSame( '<p>a short note</p>', get_post( $r['ids'][0] )->post_content );
		$this->assertStringNotContainsString( 'memex-import-merge', get_post( $r['ids'][0] )->post_content );
		$this->assertSame( '2013-04-28 05:08:45', get_post( $r['ids'][1] )->post_date_gmt );
	}

	public function test_thinkery_duplicate_empty_things_keep_their_dates(): void {
		$xml = '<thinkery>'
			. '<thing><title>about:debugging</title><url></url><tags></tags><date>Mon, 18 Jun 2018 16:05:08 +0000</date><html></html></thing>'
			. '<thing><title>about:debugging</title><url></url><tags></tags><date>Mon, 18 Jun 2018 16:07:55 +0000</date><html></html></thing>'
			. '</thinkery>';
		$r = $this->run_importer( new Thinkery(), $this->file( 'things.xml', $xml ) );
		$this->assertCount( 2, $r['ids'] );
		$this->assertNotSame( $r['ids'][0], $r['ids'][1] );
		$this->assertSame( '2018-06-18 16:05:08', get_post( $r['ids'][0] )->post_date_gmt );
		$this->assertSame( '2018-06-18 16:07:55', get_post( $r['ids'][1] )->post_date_gmt );
	}

	/* ─── Detection ─── */

	public function test_from_type_and_detect(): void {
		$this->assertInstanceOf( Markdown::class, Importer::from_type( 'obsidian' ) );
		$this->assertNull( Importer::from_type( 'nope' ) );
		$this->assertInstanceOf( Evernote::class, Importer::detect( $this->enex( array() ), 'x.enex' ) );
		$this->assertInstanceOf( Roam::class, Importer::detect( $this->file( 'r.json', '[{"title":"a","children":[]}]' ), 'r.json' ) );
		$this->assertInstanceOf( Thinkery::class, Importer::detect( $this->file( 't.json', '[{"url":"x"}]' ), 't.json' ) );
		$hex = str_repeat( 'c', 32 );
		$this->assertInstanceOf( Notion::class, Importer::detect( $this->zip( 'n.zip', array( "Page $hex.md" => '' ) ), 'n.zip' ) );
		$this->assertInstanceOf( Markdown::class, Importer::detect( $this->zip( 'v.zip', array( 'a.md' => '' ) ), 'v.zip' ) );
	}

	/* ─── Job ─── */

	private function job( int $pages = 5 ): Job {
		$data = array();
		for ( $i = 1; $i <= $pages; $i++ ) {
			$data[] = array(
				'title'    => "Page $i",
				'children' => array( array( 'string' => "links [[Page 1]] and [[Missing $i]]" ) ),
			);
		}
		$job = Job::create( 7, new Roam(), $this->file( 'roam.json', json_encode( $data ) ), 'roam.json' );
		$this->assertInstanceOf( Job::class, $job );
		return $job;
	}

	public function test_job_runs_in_bounded_steps_and_resumes_from_stored_state(): void {
		$job    = $this->job( 5 );
		$status = $job->status();
		$this->assertSame( 'prepare', $status['phase'] );
		$this->assertSame( $job->id(), Job::active_for( 7 )->id() );
		$this->assertFileExists( Job::base_dir() . '/' . $job->id() . '/source.json' );

		// Each "request" loads the job fresh from the option, like AJAX would.
		$phases = array();
		$steps  = 0;
		do {
			$job    = Job::load( $job->id() );
			$status = $job->step( 60, 3 );
			$phases[] = $status['phase'] . ':' . $status['done'] . '/' . $status['total'];
			++$steps;
		} while ( 'done' !== $status['phase'] && $steps < 50 );

		$this->assertSame(
			// Finishing the items and starting on links share a step when time allows.
			array( 'import:3/10', 'import:6/10', 'import:9/10', 'links:3/5', 'done:5/5' ),
			$phases
		);
		$this->assertSame( 5, $status['count'] );
		$this->assertSame( array(), $status['errors'] );

		// Links resolved: every page links to Page 1 and got a stub for its missing target.
		$page2 = $this->find_by_title( 'Page 2' );
		$this->assertContains( $this->find_by_title( 'Page 1' ), array_map( 'intval', get_post_meta( $page2, '_memex_links_to' ) ) );
		$this->assertSame( '1', get_post_meta( $this->find_by_title( 'Missing 2' ), '_memex_stub', true ) );

		// Finished: state and files gone, user free to start another.
		$this->assertNull( Job::load( $job->id() ) );
		$this->assertNull( Job::active_for( 7 ) );
		$this->assertDirectoryDoesNotExist( Job::base_dir() . '/' . $job->id() );
		$this->assertNotFalse( has_action( 'save_post_memex_note', array( Memex\Links::class, 'on_save' ) ), 'save_post sync re-attached' );
	}

	public function test_job_refuses_a_second_concurrent_import_and_cancel_frees_it(): void {
		$job = $this->job( 2 );
		$job->step( 60, 1 );

		$second = Job::create( 7, new Roam(), $this->file( 'other.json', '[]' ), 'other.json' );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'import-in-progress', $second->get_error_code() );
		$this->assertSame( $job->id(), $second->get_error_data()['job'] );

		// Another user is not blocked.
		$this->assertInstanceOf( Job::class, Job::create( 8, new Roam(), $this->file( 'u8.json', '[]' ), 'u8.json' ) );

		Job::load( $job->id() )->cancel();
		$this->assertNull( Job::active_for( 7 ) );
		$this->assertDirectoryDoesNotExist( Job::base_dir() . '/' . $job->id() );
		$this->assertInstanceOf( Job::class, Job::create( 7, new Roam(), $this->file( 'again.json', '[]' ), 'again.json' ) );
	}

	public function test_job_with_unreadable_input_finishes_with_the_error(): void {
		$job    = Job::create( 7, new Roam(), $this->file( 'bad.json', 'nope' ), 'bad.json' );
		$status = $job->step();
		$this->assertSame( 'done', $status['phase'] );
		$this->assertSame( 0, $status['count'] );
		$this->assertSame( array( 'Invalid JSON in Roam export.' ), $status['errors'] );
		$this->assertNull( Job::active_for( 7 ) );
	}

	public function test_stale_jobs_are_swept(): void {
		$job = $this->job( 1 );
		$this->assertNotFalse( wp_next_scheduled( Job::CRON_HOOK ) );

		Job::cleanup_stale();
		$this->assertNotNull( Job::load( $job->id() ), 'fresh job kept' );

		$data            = get_option( Job::OPTION_PREFIX . $job->id() );
		$data['updated'] = time() - 2 * DAY_IN_SECONDS;
		update_option( Job::OPTION_PREFIX . $job->id(), $data );
		$orphan = Job::base_dir() . '/orphan';
		mkdir( $orphan );
		touch( $orphan, time() - 2 * DAY_IN_SECONDS );

		Job::cleanup_stale();
		$this->assertNull( Job::load( $job->id() ) );
		$this->assertNull( Job::active_for( 7 ) );
		$this->assertDirectoryDoesNotExist( $orphan );
	}
}
