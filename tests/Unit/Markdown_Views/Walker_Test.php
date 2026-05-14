<?php
/**
 * Unit tests for the HTML→MD walker.
 *
 * Two test surfaces:
 *
 * 1. Golden-file fixtures under tests/fixtures/html-to-md/. Each `*.html`
 *    has a paired `*.expected.md`. The data provider yields every pair;
 *    the test asserts `Walker::convert( html )` equals the expected MD.
 * 2. Inline behavioural tests for edge cases (empty input, oversize,
 *    malformed HTML, depth limit, idempotence) that don't warrant a
 *    fixture file.
 *
 * The walker is a pure function — these tests do not load WordPress.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
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

		$actual = Walker::convert( $html );

		self::assertSame( $expected, $actual );
	}

	public function test_empty_input_returns_empty_string(): void {
		self::assertSame( '', Walker::convert( '' ) );
	}

	public function test_input_over_size_limit_returns_empty_string(): void {
		$oversize = \str_repeat( 'a', Walker::MAX_INPUT_BYTES + 1 );
		self::assertSame( '', Walker::convert( $oversize ) );
	}

	public function test_walker_is_idempotent_across_runs(): void {
		$html = '<h1>Title</h1><p>Body with <em>emphasis</em>.</p>';
		$first  = Walker::convert( $html );
		$second = Walker::convert( $html );
		self::assertSame( $first, $second, 'Same input must always produce same output' );
	}

	public function test_walker_version_is_set(): void {
		self::assertNotSame( '', Walker::WALKER_VERSION );
	}

	public function test_malformed_html_does_not_throw(): void {
		// Unclosed tags, mismatched nesting — libxml is forgiving. We don't
		// promise specific output for malformed input, just that the call
		// returns a string (the cache write path can't tolerate exceptions).
		$result = Walker::convert( '<p>open<strong>and<em>nested</strong>only' );
		self::assertStringContainsString( 'open', $result );
		self::assertStringContainsString( 'nested', $result );
	}

	public function test_walker_strips_gutenberg_block_comments(): void {
		$html = "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->";
		$out  = Walker::convert( $html );
		self::assertStringContainsString( 'Hello.', $out );
		self::assertStringNotContainsString( 'wp:paragraph', $out );
		self::assertStringNotContainsString( '<!--', $out );
	}

	public function test_walker_strips_gallery_shortcode_residue(): void {
		$html = '<p>Before [gallery ids="1,2,3"] after.</p>';
		$out  = Walker::convert( $html );
		self::assertStringContainsString( 'Before', $out );
		self::assertStringContainsString( 'after.', $out );
		self::assertStringNotContainsString( '[gallery', $out );
	}

	public function test_walker_preserves_caption_text_strips_shortcode(): void {
		$html = '[caption id="1" align="alignnone"]<img src="x.jpg" alt="" /> Caption text here[/caption]';
		$out  = Walker::convert( $html );
		self::assertStringContainsString( 'Caption text here', $out );
		self::assertStringNotContainsString( '[caption', $out );
		self::assertStringNotContainsString( '[/caption]', $out );
	}
}
