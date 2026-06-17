<?php

use Memex\AI;
use PHPUnit\Framework\TestCase;

class AIAbilitySchemaTest extends TestCase {
	public function test_save_note_schema_describes_content_as_markdown_like_editor_text(): void {
		$method = new ReflectionMethod( AI::class, 'save_note_input_schema' );
		$method->setAccessible( true );
		$schema = $method->invoke( null );

		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertSame(
			'Editable Markdown-like body. Replaces existing content.',
			$schema['properties']['content']['description']
		);
		$this->assertArrayNotHasKey( 'content_format', $schema['properties'] );
	}

	public function test_ability_domain_tells_agents_to_use_markdown_for_full_note_saves(): void {
		$domains = AI::register_ability_domains( array() );

		$this->assertStringContainsString( 'editable Markdown-like text', $domains[ AI::CATEGORY ] );
		$this->assertStringContainsString( 'capture appends plain quick text', $domains[ AI::CATEGORY ] );
	}
}
