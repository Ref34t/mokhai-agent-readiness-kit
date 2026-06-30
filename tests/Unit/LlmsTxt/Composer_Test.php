<?php
/**
 * Unit tests for the pure llms.txt body composer.
 *
 * The composer takes a structured input array (identity + editorial +
 * sections) and returns the body string. No WordPress is loaded.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\LlmsTxt;

use PHPUnit\Framework\TestCase;
use Mokhai\LlmsTxt\Composer;

final class Composer_Test extends TestCase {

	public function test_empty_sections_with_identity_emit_header_only(): void {
		// #244: a configured site with nothing exposed must still emit its
		// identity header, not a blank body.
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'My Site', 'tagline' => 'A tagline' ),
				'editorial' => array(),
				'sections'  => array(),
			)
		);

		$this->assertSame( "# My Site\n> A tagline\n", $out );
	}

	public function test_no_identity_and_no_sections_returns_empty_body(): void {
		// Only a site with no identity AND no sections yields a truly empty body.
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => '', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(),
			)
		);

		$this->assertSame( '', $out );
	}

	public function test_identity_block_renders_with_tagline(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'Example', 'tagline' => 'Words' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
						),
					),
				),
			)
		);

		$this->assertStringStartsWith( "# Example\n> Words\n", $out );
		$this->assertStringContainsString( "## Pages\n\n- [About](https://example.com/about/)\n", $out );
	}

	public function test_identity_block_omits_tagline_when_empty(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'Example', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
						),
					),
				),
			)
		);

		$this->assertStringStartsWith( "# Example\n", $out );
		$this->assertStringNotContainsString( '> ', $out );
	}

	public function test_editorial_entries_render_before_auto_sections(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(
					array(
						'title'       => 'Editorial One',
						'url'         => 'https://x.test/e1',
						'description' => 'Hand-picked',
					),
				),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'Auto One', 'url' => 'https://x.test/a1' ),
						),
					),
				),
			)
		);

		$editorial_pos = strpos( $out, 'Editorial One' );
		$auto_pos      = strpos( $out, 'Auto One' );

		$this->assertNotFalse( $editorial_pos );
		$this->assertNotFalse( $auto_pos );
		$this->assertLessThan( $auto_pos, $editorial_pos );
	}

	public function test_editorial_default_section_label(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(
					array(
						'title' => 'Curated',
						'url'   => 'https://x.test/c',
					),
				),
				'sections'  => array(),
			)
		);

		$this->assertStringContainsString( '## ' . Composer::DEFAULT_EDITORIAL_SECTION . "\n", $out );
	}

	public function test_editorial_with_explicit_section_label_renders_under_it(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(
					array(
						'title'   => 'Press release',
						'url'     => 'https://x.test/pr',
						'section' => 'News',
					),
				),
				'sections'  => array(),
			)
		);

		$this->assertStringContainsString( "## News\n\n- [Press release](https://x.test/pr)", $out );
	}

	public function test_editorial_and_auto_share_label_collapse_into_one_section(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(
					array(
						'title'   => 'Curated page',
						'url'     => 'https://x.test/c',
						'section' => 'Pages',
					),
				),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'Auto page', 'url' => 'https://x.test/a' ),
						),
					),
				),
			)
		);

		// Only one "## Pages" header should appear.
		$this->assertSame( 1, substr_count( $out, "## Pages\n" ) );
		$this->assertStringContainsString( 'Curated page', $out );
		$this->assertStringContainsString( 'Auto page', $out );
	}

	public function test_entry_without_description_renders_bare_line(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'About', 'url' => 'https://x.test/about/' ),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( "- [About](https://x.test/about/)\n", $out );
		$this->assertStringNotContainsString( '[About](https://x.test/about/):', $out );
	}

	public function test_entry_with_description_renders_after_colon(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array(
								'title'       => 'About',
								'url'         => 'https://x.test/about/',
								'description' => 'Who we are',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( "- [About](https://x.test/about/): Who we are\n", $out );
	}

	public function test_entries_missing_title_or_url_are_dropped(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => '', 'url' => 'https://x.test/a' ),
							array( 'title' => 'B', 'url' => '' ),
							array( 'title' => 'C', 'url' => 'https://x.test/c' ),
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'https://x.test/a', $out );
		$this->assertStringNotContainsString( '[B]', $out );
		$this->assertStringContainsString( '[C](https://x.test/c)', $out );
	}

	public function test_section_with_zero_valid_entries_is_dropped_entirely(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Empty',
						'entries' => array(
							array( 'title' => '', 'url' => '' ),
						),
					),
					array(
						'label'   => 'Real',
						'entries' => array(
							array( 'title' => 'Yes', 'url' => 'https://x.test/y' ),
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( '## Empty', $out );
		$this->assertStringContainsString( '## Real', $out );
	}

	public function test_inline_markdown_chars_in_title_are_escaped(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array(
								'title' => 'Hello *world*',
								'url'   => 'https://x.test/hello',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '[Hello \\*world\\*]', $out );
	}

	public function test_link_brackets_in_title_are_escaped(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array(
								'title' => 'A [B] C',
								'url'   => 'https://x.test/abc',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '[A \\[B\\] C](https://x.test/abc)', $out );
	}

	public function test_parens_in_url_are_percent_encoded(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array(
								'title' => 'Doc',
								'url'   => 'https://x.test/doc(v2)',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '(https://x.test/doc%28v2%29)', $out );
		$this->assertStringNotContainsString( '(https://x.test/doc(v2))', $out );
	}

	public function test_newlines_in_inline_strings_are_collapsed(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => "Site\nName", 'tagline' => '' ),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array(
								'title'       => "Title\nwith newline",
								'url'         => 'https://x.test/',
								'description' => "Desc\nwith newline",
							),
						),
					),
				),
			)
		);

		// First line should be "# Site Name" not "# Site\nName".
		$this->assertStringStartsWith( "# Site Name\n", $out );
		$this->assertStringContainsString( '[Title with newline]', $out );
		$this->assertStringContainsString( ': Desc with newline', $out );
	}

	/**
	 * Regression for Ref34t/agentready#106.
	 *
	 * /llms.txt is served as `text/plain`, so HTML entities (`&#8217;`,
	 * `&amp;`, `&quot;`, etc.) introduced upstream by `wptexturize` or
	 * the block editor render literally as visible artefacts to the
	 * consumer. The composer must decode entities before writing the
	 * body — coverage spans identity (site name + tagline), section
	 * labels, entry titles, and entry descriptions, so the decode runs
	 * at the bottom of the escape pipeline (`escape_inline`).
	 */
	public function test_html_entities_are_decoded_across_all_text_surfaces(): void {
		$out = Composer::compose(
			array(
				'identity'  => array(
					'site_name' => 'Architect&#8217;s notebook',
					'tagline'   => 'Tools &amp; tactics',
				),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'GPU &amp; RAM',
						'entries' => array(
							array(
								'title'       => 'Designer&#8217;s note: GPU&#8217;s',
								'url'         => 'https://x.test/post/',
								'description' => 'Architect&#8217;s view &mdash; with &quot;quotes&quot;',
							),
						),
					),
				),
			)
		);

		// Every surface decoded — no entity codes remain.
		$this->assertStringContainsString( "# Architect\xE2\x80\x99s notebook", $out, 'Site name must decode &#8217; -> typographic apostrophe.' );
		$this->assertStringContainsString( '> Tools & tactics', $out, 'Tagline must decode &amp; -> &.' );
		$this->assertStringContainsString( '## GPU & RAM', $out, 'Section label must decode &amp;.' );
		$this->assertStringContainsString( "[Designer\xE2\x80\x99s note: GPU\xE2\x80\x99s]", $out, 'Entry title must decode &#8217;.' );
		$this->assertStringContainsString( "Architect\xE2\x80\x99s view \xE2\x80\x94 with \"quotes\"", $out, 'Description must decode mixed entities.' );

		// Belt-and-braces: no `&...;` sequences anywhere in the body.
		$this->assertDoesNotMatchRegularExpression( '/&[a-z]+;/i', $out );
		$this->assertDoesNotMatchRegularExpression( '/&#[0-9]+;/', $out );
	}

	public function test_missing_identity_keys_default_to_empty_strings(): void {
		$out = Composer::compose(
			array(
				'identity'  => array(),
				'editorial' => array(),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							array( 'title' => 'X', 'url' => 'https://x.test/' ),
						),
					),
				),
			)
		);

		// Empty site_name yields "# \n" — odd but valid llms.txt.
		$this->assertStringStartsWith( "# \n", $out );
	}

	public function test_non_array_entries_are_skipped_silently(): void {
		$out = Composer::compose(
			array(
				'identity'  => array( 'site_name' => 'X', 'tagline' => '' ),
				'editorial' => array( 'not-an-array', 42 ),
				'sections'  => array(
					array(
						'label'   => 'Pages',
						'entries' => array(
							'also-not-an-array',
							array( 'title' => 'Valid', 'url' => 'https://x.test/v' ),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '[Valid]', $out );
	}
}
