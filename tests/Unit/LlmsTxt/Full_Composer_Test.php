<?php
/**
 * Unit tests for WPContext\LlmsTxt\Full_Composer.
 *
 * Pure-function tests — `compose()` reads no WP state, so the suite runs
 * without booting WordPress (Brain Monkey provides the function shims the
 * shared `Composer` escaping helpers rely on, although none are WP calls).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\LlmsTxt;

use PHPUnit\Framework\TestCase;
use WPContext\LlmsTxt\Full_Composer;

final class Full_Composer_Test extends TestCase {

	/**
	 * Baseline inputs: one section, two documents with bodies.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_inputs(): array {
		return array(
			'identity'  => array(
				'site_name' => 'Example Site',
				'tagline'   => 'Tagline here',
			),
			'editorial' => array(),
			'documents' => array(
				array(
					'label'   => 'Posts',
					'entries' => array(
						array(
							'title'    => 'First Post',
							'url'      => 'https://example.com/first.md',
							'markdown' => "# First Post\n\nBody one.",
						),
						array(
							'title'    => 'Second Post',
							'url'      => 'https://example.com/second.md',
							'markdown' => "# Second Post\n\nBody two.",
						),
					),
				),
			),
		);
	}

	public function test_empty_inputs_compose_to_empty_string(): void {
		$this->assertSame( '', Full_Composer::compose( array() ) );
		$this->assertSame(
			'',
			Full_Composer::compose(
				array(
					'identity'  => array( 'site_name' => 'Site' ),
					'editorial' => array(),
					'documents' => array(),
				)
			)
		);
	}

	public function test_identity_block_precedes_sections(): void {
		$body = Full_Composer::compose( $this->sample_inputs() );

		$this->assertStringStartsWith( "# Example Site\n> Tagline here\n", $body );
	}

	public function test_documents_render_heading_url_and_body(): void {
		$body = Full_Composer::compose( $this->sample_inputs() );

		$this->assertStringContainsString( "## Posts\n", $body );
		$this->assertStringContainsString( "### First Post\n", $body );
		$this->assertStringContainsString( 'URL: https://example.com/first.md', $body );
		$this->assertStringContainsString( 'Body one.', $body );
		$this->assertStringContainsString( 'Body two.', $body );
	}

	public function test_separator_between_documents_not_after_last(): void {
		$body = Full_Composer::compose( $this->sample_inputs() );

		// Two documents → exactly one separator line between them.
		$this->assertSame( 1, substr_count( $body, "\n---\n" ) );
		$this->assertStringNotContainsString( "Body two.\n\n---", $body );

		$first  = strpos( $body, 'Body one.' );
		$sep    = strpos( $body, "\n---\n" );
		$second = strpos( $body, '### Second Post' );
		$this->assertNotFalse( $first );
		$this->assertNotFalse( $sep );
		$this->assertNotFalse( $second );
		$this->assertGreaterThan( $first, $sep );
		$this->assertGreaterThan( $sep, $second );
	}

	public function test_document_without_markdown_still_renders_heading_and_url(): void {
		$inputs = $this->sample_inputs();
		unset( $inputs['documents'][0]['entries'][0]['markdown'] );

		$body = Full_Composer::compose( $inputs );

		$this->assertStringContainsString( "### First Post\n", $body );
		$this->assertStringContainsString( 'URL: https://example.com/first.md', $body );
	}

	public function test_editorial_entries_render_as_link_lines(): void {
		$inputs              = $this->sample_inputs();
		$inputs['editorial'] = array(
			array(
				'title'       => 'External Guide',
				'url'         => 'https://elsewhere.example/guide',
				'description' => 'Curated pointer',
			),
		);

		$body = Full_Composer::compose( $inputs );

		$this->assertStringContainsString( "## Editorial\n", $body );
		$this->assertStringContainsString(
			'- [External Guide](https://elsewhere.example/guide): Curated pointer',
			$body
		);
		// Editorial sections precede document sections.
		$this->assertLessThan(
			(int) strpos( $body, '## Posts' ),
			(int) strpos( $body, '## Editorial' )
		);
	}

	public function test_malformed_entries_and_empty_sections_are_dropped(): void {
		$inputs              = $this->sample_inputs();
		$inputs['documents'] = array(
			'not-an-array',
			array( 'label' => '' ),
			array(
				'label'   => 'Empty Section',
				'entries' => array(
					array( 'title' => '', 'url' => 'https://example.com/x' ),
					array( 'title' => 'No URL', 'url' => '' ),
					'scalar',
				),
			),
			array(
				'label'   => 'Kept',
				'entries' => array(
					array(
						'title'    => 'Survivor',
						'url'      => 'https://example.com/s.md',
						'markdown' => 'Body.',
					),
				),
			),
		);

		$body = Full_Composer::compose( $inputs );

		$this->assertStringNotContainsString( 'Empty Section', $body );
		$this->assertStringContainsString( "## Kept\n", $body );
		$this->assertStringContainsString( '### Survivor', $body );
	}

	public function test_heading_titles_are_escaped_but_bodies_are_verbatim(): void {
		$inputs = array(
			'identity'  => array(
				'site_name' => 'Site *Star*',
				'tagline'   => '',
			),
			'editorial' => array(),
			'documents' => array(
				array(
					'label'   => 'Posts',
					'entries' => array(
						array(
							'title'    => 'Title_With_Underscores',
							'url'      => 'https://example.com/t.md',
							'markdown' => "Keep *emphasis* and _underscores_ verbatim.",
						),
					),
				),
			),
		);

		$body = Full_Composer::compose( $inputs );

		$this->assertStringContainsString( '# Site \\*Star\\*', $body );
		$this->assertStringContainsString( '### Title\\_With\\_Underscores', $body );
		$this->assertStringContainsString( 'Keep *emphasis* and _underscores_ verbatim.', $body );
	}

	public function test_body_starts_at_first_byte_no_leading_whitespace(): void {
		$body = Full_Composer::compose( $this->sample_inputs() );

		$this->assertSame( '#', substr( $body, 0, 1 ) );
	}
}
