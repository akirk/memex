<?php

use Memex\AI;

class AIAbilitySchemaTest extends WP_UnitTestCase {
	public function test_save_note_schema_describes_content_as_markdown_like_editor_text(): void {
		$ability = wp_get_ability( 'memex/save-note' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$schema = $ability->get_input_schema();

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
