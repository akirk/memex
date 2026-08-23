<?php

use Memex\Exporter;

class ExporterTest extends WP_UnitTestCase {
	public function test_frontmatter_emits_scalars_and_lists(): void {
		$yaml = Exporter::frontmatter(
			array(
				'title'   => 'Project Ideas',
				'tags'    => array( 'wordpress', 'ideas' ),
				'aliases' => array(),
			)
		);

		$this->assertSame( "---\ntitle: Project Ideas\ntags:\n  - wordpress\n  - ideas\n---\n\n", $yaml );
	}

	public function test_frontmatter_quotes_values_the_importer_would_misread(): void {
		$yaml = Exporter::frontmatter(
			array(
				'title'   => 'Notes: a "quoted" title',
				'created' => '2026-04-24T00:27:54+00:00',
				'daily'   => '2026-04-24',
				'empty'   => '',
			)
		);

		$this->assertStringContainsString( 'title: "Notes: a \"quoted\" title"', $yaml );
		$this->assertStringContainsString( 'created: "2026-04-24T00:27:54+00:00"', $yaml );
		$this->assertStringContainsString( 'daily: "2026-04-24"', $yaml );
		$this->assertStringContainsString( 'empty: ""', $yaml );
	}

	public function test_filename_strips_path_separators_and_falls_back_to_id(): void {
		$this->assertSame( 'A - B - C', Exporter::filename( 'A / B: C', 7 ) );
		$this->assertSame( 'note-7', Exporter::filename( '...', 7 ) );
		$this->assertSame( 'note-7', Exporter::filename( '', 7 ) );
		$this->assertSame( 120, strlen( Exporter::filename( str_repeat( 'x', 200 ), 7 ) ) );
	}
}
