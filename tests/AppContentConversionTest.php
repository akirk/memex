<?php

use Memex\App;
use Memex\Content;
use PHPUnit\Framework\TestCase;

class AppContentConversionTest extends TestCase {
	public function test_content_to_editor_text_preserves_unsupported_html_between_markdown_blocks(): void {
		$content = '<p>Hello</p><figure><img src="cover.jpg" alt="Cover"></figure><p>Bye</p>';

		$this->assertSame(
			"Hello\n\n<figure><img src=\"cover.jpg\" alt=\"Cover\"></figure>\n\nBye",
			App::content_to_editor_text( $content )
		);
	}

	public function test_content_to_editor_text_preserves_unsupported_html_around_markdown_blocks(): void {
		$content = '<table><tr><td>A</td></tr></table><h2>Books</h2><ul><li>The Martian</li><li>Artemis</li></ul><aside>metadata</aside>';

		$this->assertSame(
			"<table><tr><td>A</td></tr></table>\n\n## Books\n\n- The Martian\n- Artemis\n\n<aside>metadata</aside>",
			App::content_to_editor_text( $content )
		);
	}

	public function test_content_to_editor_text_keeps_wiki_links_editable(): void {
		$content = '<p>See [[Reading List]] and <a href="https://example.com">Example</a>.</p>';

		$this->assertSame(
			'See [[Reading List]] and [Example](https://example.com).',
			App::content_to_editor_text( $content )
		);
	}

	public function test_markdown_to_html_preserves_wiki_links_after_markdown_rendering(): void {
		$html = Content::markdown_to_html( "## Books\n\n- [[The Martian]]\n- [[Project Hail Mary|PHM]]" );

		$this->assertStringContainsString( '<h2>Books</h2>', $html );
		$this->assertStringContainsString( '<li>[[The Martian]]</li>', $html );
		$this->assertStringContainsString( '<li>[[Project Hail Mary|PHM]]</li>', $html );
	}

	public function test_markdown_to_html_renders_task_list_items_as_checkboxes(): void {
		$html = Content::markdown_to_html( "- [ ] Buy milk\n- [x] Call back\n- plain item" );

		$this->assertStringContainsString( '<li class="memex-task-item"><input type="checkbox" class="memex-task-checkbox"> Buy milk</li>', $html );
		$this->assertStringContainsString( '<li class="memex-task-item"><input type="checkbox" class="memex-task-checkbox" checked> Call back</li>', $html );
		$this->assertStringContainsString( '<li>plain item</li>', $html );
	}

	public function test_markdown_to_html_keeps_escaped_brackets_literal(): void {
		$html = Content::markdown_to_html( '- \\[ \\] not a task' );

		$this->assertStringNotContainsString( 'checkbox', $html );
	}

	public function test_task_list_items_round_trip_through_the_editor(): void {
		$markdown = "- [ ] Buy milk\n- [x] Call back";

		$this->assertSame( $markdown, App::content_to_editor_text( Content::markdown_to_html( $markdown ) ) );
	}

	public function test_content_to_editor_text_converts_imported_checkboxes(): void {
		$content = '<ul><li><input type="checkbox" checked disabled> Done</li><li><input type="checkbox" disabled> Open</li></ul>';

		$this->assertSame( "- [x] Done\n- [ ] Open", App::content_to_editor_text( $content ) );
	}

	public function test_set_task_checked_toggles_by_index(): void {
		$content = Content::markdown_to_html( "- [ ] One\n- [x] Two\n- [ ] Three" );

		$checked = Content::set_task_checked( $content, 2, true );
		$this->assertStringContainsString( '<input type="checkbox" class="memex-task-checkbox" checked> Three', $checked );
		$this->assertStringContainsString( '<input type="checkbox" class="memex-task-checkbox"> One', $checked );

		$unchecked = Content::set_task_checked( $content, 1, false );
		$this->assertStringContainsString( '<input type="checkbox" class="memex-task-checkbox"> Two', $unchecked );

		$this->assertNull( Content::set_task_checked( $content, 3, true ) );
	}

	public function test_filter_task_checkboxes_respects_edit_capability(): void {
		$content = '<ul><li><input type="checkbox" checked disabled> Done</li><li class="memex-task-item"><input type="checkbox" class="memex-task-checkbox"> Open</li></ul>';

		$editable = Content::filter_task_checkboxes( $content, true );
		$this->assertStringNotContainsString( 'disabled', $editable );
		$this->assertStringContainsString( '<input type="checkbox" checked class="memex-task-checkbox">', $editable );

		$readonly = Content::filter_task_checkboxes( $content, false );
		$this->assertSame( 2, substr_count( $readonly, ' disabled>' ) );
	}

	public function test_markdown_to_html_renders_markdown_links(): void {
		$html = Content::markdown_to_html( '[PR](https://example.com/pr)' );

		$this->assertStringContainsString( '<a href="https://example.com/pr">PR</a>', $html );
	}

	public function test_plain_text_to_blocks_preserves_literal_markdown(): void {
		$html = Content::plain_text_to_blocks( '[PR](https://example.com/pr)' );

		$this->assertStringContainsString( '[PR](https://example.com/pr)', $html );
		$this->assertStringNotContainsString( '<a href=', $html );
	}

	public function test_markdown_to_html_preserves_literal_backslashes(): void {
		$html = Content::markdown_to_html( 'Keep \*literal asterisk\*, C:\Temp, and \[[escaped brackets]]' );

		$this->assertStringContainsString( '\*literal asterisk\*', $html );
		$this->assertStringContainsString( 'C:\Temp', $html );
		$this->assertStringContainsString( '\[[escaped brackets]]', $html );
	}

	public function test_slashing_post_array_preserves_backslashes_through_core_unslash(): void {
		$html = Content::markdown_to_html( 'test is "this \working" and test \n' );
		$post = wp_slash(
			array(
				'post_content' => $html,
			)
		);

		$this->assertSame( $html, wp_unslash( $post['post_content'] ) );
	}
}
