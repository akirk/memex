<?php

use Memex\CPT;
use Memex\Links;

/**
 * /memex/graph draws only notes that link to or from another note, and says
 * so when there are none.
 */
class GraphTest extends WP_UnitTestCase {
	private function render(): string {
		ob_start();
		include dirname( __DIR__ ) . '/templates/graph.php';
		return ob_get_clean();
	}

	private function graph_data( string $html ): array {
		$this->assertMatchesRegularExpression( '/data-graph="([^"]*)"/', $html );
		preg_match( '/data-graph="([^"]*)"/', $html, $m );
		return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}

	private function note( string $title, string $content = '' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	public function test_no_notes_at_all(): void {
		$html = $this->render();
		$this->assertStringNotContainsString( 'data-graph=', $html );
		$this->assertStringContainsString( 'No notes to graph yet.', $html );
	}

	public function test_notes_without_links_point_to_tags(): void {
		$this->note( 'Alpha' );
		$this->note( 'Beta' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'data-graph=', $html );
		$this->assertStringContainsString( 'by tag', $html );
		$this->assertStringContainsString( home_url( '/memex/tags' ), $html );
		$this->assertStringContainsString( '0 linked notes, 0 links; 2 notes without links are not shown.', $html );
		$this->assertStringContainsString( '2 notes without links', $html );
		$this->assertStringContainsString( '>Alpha</a>', $html );
		$this->assertStringContainsString( '>Beta</a>', $html );
	}

	public function test_only_linked_notes_are_drawn(): void {
		$a = $this->note( 'Alpha' );
		$b = $this->note( 'Beta' );
		$this->note( 'Lonely' );
		Links::sync_links_from_content( $a, '<a href="' . CPT::url( $b ) . '">Beta</a>' );

		$html = $this->render();
		$data = $this->graph_data( $html );

		$this->assertSame( array( $a, $b ), array_column( $data['nodes'], 'id' ) );
		$this->assertSame( array( array( 'from' => $a, 'to' => $b ) ), $data['edges'] );
		$this->assertStringContainsString( '2 linked notes, 1 links; 1 notes without links are not shown.', $html );
		$this->assertStringContainsString( '1 note without links', $html );
		$this->assertMatchesRegularExpression( '#<ul class="memex-graph-unlinked">\s*<li><a href="[^"]+">Lonely</a></li>\s*</ul>#', $html );
	}

	public function test_links_to_trashed_notes_are_dropped(): void {
		$a = $this->note( 'Alpha' );
		$b = $this->note( 'Beta' );
		Links::sync_links_from_content( $a, '<a href="' . CPT::url( $b ) . '">Beta</a>' );
		wp_update_post( array( 'ID' => $b, 'post_status' => 'trash' ) );

		$html = $this->render();
		$this->assertStringNotContainsString( 'data-graph=', $html );
	}
}
