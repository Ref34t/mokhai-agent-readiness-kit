<?php
/**
 * Unit tests for the HTML→MD walker.
 *
 * Three test surfaces:
 *
 * 1. Golden-file fixtures under tests/fixtures/html-to-md/. Each `*.html`
 *    has a paired `*.expected.md`. The data provider yields every pair;
 *    the test asserts `Walker::convert( html )->get_markdown()` equals
 *    the expected MD.
 * 2. Inline behavioural tests for edge cases (empty input, oversize,
 *    malformed HTML, depth limit, idempotence) that don't warrant a
 *    fixture file.
 * 3. Quality-score assertions per AgDR-0017 — clean classic-editor
 *    content scores high, page-builder soup scores low, signal keys
 *    are stable.
 *
 * The walker is a pure function — these tests do not load WordPress.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use WPContext\Markdown_Views\Conversion_Result;
use WPContext\Markdown_Views\Walker;

final class Walker_Test extends TestCase {

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public function fixtures_provider(): iterable {
		$dir = \dirname( __DIR__, 2 ) . '/fixtures/html-to-md';

		if ( ! \is_dir( $dir ) ) {
			return;
		}

		$paths = \glob( $dir . '/*.html' );

		if ( false === $paths ) {
			return;
		}

		foreach ( $paths as $html_path ) {
			$name          = \basename( $html_path, '.html' );
			$expected_path = $dir . '/' . $name . '.expected.md';

			if ( ! \file_exists( $expected_path ) ) {
				continue;
			}

			yield $name => array( $html_path, $expected_path );
		}
	}

	/**
	 * @dataProvider fixtures_provider
	 */
	public function test_walker_converts_fixture( string $html_path, string $expected_path ): void {
		$html     = (string) \file_get_contents( $html_path );
		$expected = (string) \file_get_contents( $expected_path );

		$actual = Walker::convert( $html )->get_markdown();

		self::assertSame( $expected, $actual );
	}

	public function test_convert_returns_conversion_result(): void {
		$result = Walker::convert( '<p>Hi.</p>' );
		self::assertInstanceOf( Conversion_Result::class, $result );
	}

	public function test_empty_input_returns_empty_markdown(): void {
		$result = Walker::convert( '' );
		self::assertSame( '', $result->get_markdown() );
		self::assertSame( 100, $result->get_quality_score() );
	}

	public function test_input_over_size_limit_returns_empty_markdown(): void {
		$oversize = \str_repeat( 'a', Walker::MAX_INPUT_BYTES + 1 );
		$result   = Walker::convert( $oversize );
		self::assertSame( '', $result->get_markdown() );
	}

	public function test_walker_is_idempotent_across_runs(): void {
		$html   = '<h1>Title</h1><p>Body with <em>emphasis</em>.</p>';
		$first  = Walker::convert( $html );
		$second = Walker::convert( $html );
		self::assertSame( $first->get_markdown(), $second->get_markdown() );
		self::assertSame( $first->get_quality_score(), $second->get_quality_score() );
		self::assertSame( $first->get_signals(), $second->get_signals() );
	}

	public function test_walker_version_is_set(): void {
		self::assertNotSame( '', Walker::WALKER_VERSION );
	}

	public function test_malformed_html_does_not_throw(): void {
		$md = Walker::convert( '<p>open<strong>and<em>nested</strong>only' )->get_markdown();
		self::assertStringContainsString( 'open', $md );
		self::assertStringContainsString( 'nested', $md );
	}

	public function test_walker_strips_gutenberg_block_comments(): void {
		$html = "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->";
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Hello.', $md );
		self::assertStringNotContainsString( 'wp:paragraph', $md );
		self::assertStringNotContainsString( '<!--', $md );
	}

	public function test_walker_strips_gallery_shortcode_residue(): void {
		$html = '<p>Before [gallery ids="1,2,3"] after.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Before', $md );
		self::assertStringContainsString( 'after.', $md );
		self::assertStringNotContainsString( '[gallery', $md );
	}

	public function test_walker_preserves_caption_text_strips_shortcode(): void {
		$html = '[caption id="1" align="alignnone"]<img src="x.jpg" alt="" /> Caption text here[/caption]';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Caption text here', $md );
		self::assertStringNotContainsString( '[caption', $md );
		self::assertStringNotContainsString( '[/caption]', $md );
	}

	public function test_walker_strips_orphaned_attribute_shortcode(): void {
		// #145: an unregistered builder shortcode (WPBakery, plugin inactive)
		// survives do_shortcode() as a literal token; the deterministic pass
		// must strip it rather than leak it to .md / llms.txt / AI summary.
		$html = '<p>Body paragraph with [vc_btn title="X"] residue.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Body paragraph with', $md );
		self::assertStringContainsString( 'residue.', $md );
		self::assertStringNotContainsString( '[vc_btn', $md );
	}

	public function test_walker_unwraps_nested_paired_builder_shortcodes_keeping_content(): void {
		// #145: paired builder containers are dropped but their inner copy —
		// the actual content — is preserved, even when nested.
		$html = '[vc_row][vc_column width="1/2"][vc_column_text]Real copy here.[/vc_column_text][/vc_column][/vc_row]';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Real copy here.', $md );
		self::assertStringNotContainsString( '[vc_row', $md );
		self::assertStringNotContainsString( '[vc_column', $md );
		self::assertStringNotContainsString( '[/vc_', $md );
	}

	public function test_walker_strips_self_closing_shortcode(): void {
		// #145: a self-closing shortcode with no attributes exercises the
		// `[tag /]` strip path (distinct from the attribute-bearing path).
		$html = '<p>Embed [my_embed /] here.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Embed', $md );
		self::assertStringContainsString( 'here.', $md );
		self::assertStringNotContainsString( '[my_embed', $md );
	}

	public function test_walker_preserves_non_shortcode_bracketed_prose(): void {
		// #145 guard against over-stripping: bracketed prose with no `=`
		// attribute, no trailing `/`, and no matching close tag is NOT a
		// shortcode and must survive.
		$html = '<p>See footnote [1] and the [citation needed] marker.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( '[1]', $md );
		self::assertStringContainsString( '[citation needed]', $md );
	}

	public function test_walker_drops_script_subtree_including_slider_init(): void {
		// #253: Revolution Slider's inline init script must not leak as body
		// text. Without a `script` dispatch case the JS falls through to the
		// default handler and renders.
		$html = '<p>Real copy.</p><script>setREVStartSize({c:\'rev_slider_1\',rl:[1240,1024,778,480]});</script>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Real copy.', $md );
		self::assertStringNotContainsString( 'setREVStartSize', $md );
		self::assertStringNotContainsString( 'rev_slider', $md );
	}

	public function test_walker_drops_style_subtree(): void {
		// #253: inline <style> CSS is not page prose and must not render.
		$html = '<style>.rev_slider{display:block}</style><p>Body text.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Body text.', $md );
		self::assertStringNotContainsString( 'display:block', $md );
		self::assertStringNotContainsString( '.rev_slider', $md );
	}

	public function test_walker_drops_noscript_subtree(): void {
		$html = '<noscript>Enable JavaScript to view this slider.</noscript><p>Visible prose.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Visible prose.', $md );
		self::assertStringNotContainsString( 'Enable JavaScript', $md );
	}

	public function test_walker_strips_encoded_builder_blob_keeping_prose(): void {
		// #253: Uncode/WPBakery store layout as URL-encoded-then-base64
		// payloads. `JTNDZGl2…` is base64 of `%3Cdiv…`. A 60+ char base64 run
		// must be stripped; the surrounding prose in the same node survives.
		$blob = 'JTNDZGl2JTIwY2xhc3MlM0QlMjJ1bmNvZGUtc2luZ2xlLW1lZGlhLXdyYXBwZXIlMjIlM0U';
		$html = '<p>Intro sentence. ' . $blob . ' Closing sentence.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Intro sentence.', $md );
		self::assertStringContainsString( 'Closing sentence.', $md );
		self::assertStringNotContainsString( $blob, $md );
	}

	public function test_walker_strips_standalone_encoded_blob_paragraph(): void {
		// A paragraph that is ONLY an encoded blob collapses to nothing.
		$blob = \str_repeat( 'QWxhZGRpbg', 12 ); // 120 chars, pure base64 charset.
		$html = '<p>' . $blob . '</p><p>Kept paragraph.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'Kept paragraph.', $md );
		self::assertStringNotContainsString( $blob, $md );
	}

	public function test_walker_preserves_short_alphanumeric_tokens(): void {
		// #253 over-strip guard: ordinary words, slugs, and short IDs are
		// below the 60-char floor and must survive untouched.
		$html = '<p>Order ABC123XYZ shipped via tracking 1Z999AA10123456784 today.</p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'ABC123XYZ', $md );
		self::assertStringContainsString( '1Z999AA10123456784', $md );
	}

	public function test_walker_preserves_data_uri_image_source(): void {
		// #253 over-strip guard: a base64 data-URI lives in the `src`
		// ATTRIBUTE, consumed by render_image — the text-node strip must not
		// touch it, so the image link survives intact.
		$b64  = \str_repeat( 'iVBORw0KGgoAAAANSUhEUg', 5 ); // long base64 in src.
		$html = '<p><img src="data:image/png;base64,' . $b64 . '" alt="logo"></p>';
		$md   = Walker::convert( $html )->get_markdown();
		self::assertStringContainsString( 'data:image/png;base64,' . $b64, $md );
		self::assertStringContainsString( 'logo', $md );
	}

	public function test_score_is_high_on_clean_classic_post(): void {
		$html = '<h1>A clean post</h1><p>One paragraph.</p><p>Another paragraph.</p>';
		$result = Walker::convert( $html );
		self::assertGreaterThanOrEqual( 85, $result->get_quality_score() );
	}

	public function test_score_is_lower_on_heavy_inline_style_soup(): void {
		// Page-builder-style content: deeply nested wrapper divs, every
		// paragraph carrying inline style + class attributes. The exact
		// threshold this content lands below is calibration-dependent; the
		// load-bearing assertion is that the score moves meaningfully
		// downward from the clean-content baseline (test_score_is_high_*).
		$nested = '<div class="a"><div class="b"><div class="c"><div class="d"><div class="e"><div class="f">';
		$close  = '</div></div></div></div></div></div>';
		$body   = '';
		for ( $i = 0; $i < 20; $i++ ) {
			$body .= '<div class="row" style="margin:0"><p style="color:red" class="x">Paragraph ' . $i . '</p></div>';
		}
		$html = $nested . $body . $close;

		$score = Walker::convert( $html )->get_quality_score();
		self::assertLessThan( 80, $score, 'Heavy inline-style + deep-div content must score below clean baseline' );
	}

	public function test_score_is_lower_when_shortcodes_survive(): void {
		// Custom shortcodes the walker can't expand — caption/gallery are
		// preprocessed out, but other shortcodes survive verbatim.
		// 5+ shortcode tokens trigger the full -10 weight contribution.
		$html  = '<p>Before [vc_row][vc_column][vc_btn title="X"][vc_text][vc_image][vc_col] after.</p>';
		$score = Walker::convert( $html )->get_quality_score();
		self::assertLessThan( 95, $score );
	}

	public function test_signals_array_has_stable_keys(): void {
		$signals = Walker::convert( '<p>Body.</p>' )->get_signals();

		$required_keys = array(
			'tag_strip_rate',
			'tag_strip_count',
			'tag_total_count',
			'orphan_inline_style_rate',
			'orphan_inline_style_count',
			'table_fragment_rate',
			'table_fragment_count',
			'table_total_count',
			'deep_div_nesting_rate',
			'deep_div_count',
			'div_total_count',
			'image_only_paragraph_rate',
			'image_only_paragraph_count',
			'paragraph_total_count',
			'empty_line_run_rate',
			'empty_line_run_count',
			'shortcode_residue_rate',
			'shortcode_residue_count',
		);

		foreach ( $required_keys as $key ) {
			self::assertArrayHasKey( $key, $signals, "Missing stable signal key: $key" );
		}
	}

	public function test_score_is_bounded_between_zero_and_one_hundred(): void {
		// Adversarial pathological input — every signal at its max.
		$noise = '<div class="x" style="y">' . \str_repeat( '<p style="z" class="q">[vc_btn]</p>', 50 ) . '</div>';
		$html  = \str_repeat( $noise, 20 );

		$score = Walker::convert( $html )->get_quality_score();
		self::assertGreaterThanOrEqual( 0, $score );
		self::assertLessThanOrEqual( 100, $score );
	}

	public function test_image_only_paragraph_signal_fires(): void {
		$html    = '<p><img src="x.jpg" alt="alt"></p><p>Text para.</p>';
		$signals = Walker::convert( $html )->get_signals();
		self::assertSame( 2, $signals['paragraph_total_count'] );
		self::assertSame( 1, $signals['image_only_paragraph_count'] );
	}

	public function test_deep_div_signal_fires_above_threshold(): void {
		// 6 levels of div nesting — exceeds threshold of 4.
		$html = '<div><div><div><div><div><div>Body</div></div></div></div></div></div>';
		$signals = Walker::convert( $html )->get_signals();
		self::assertGreaterThan( 0, $signals['deep_div_count'] );
	}
}
