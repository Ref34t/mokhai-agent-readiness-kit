<?php
/**
 * Unit tests for the YAML front-matter emission in
 * Markdown_Views_Command::format_yaml_header().
 *
 * Covers the regression class flagged in GH#42 — the prior naïve
 * `str_replace('"', '\"', ...)` approach produced invalid YAML on
 * titles containing backslashes, literal newlines, control characters,
 * or unbalanced double quotes. The fix routes string scalars through
 * `wp_json_encode()` with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
 * which produces YAML 1.2-compatible double-quoted scalars in every
 * input case. See AgDR-0038.
 *
 * No WordPress dependency: `wp_json_encode` is loaded from
 * tests/Unit/wp-stubs.php (same shim used elsewhere in the unit suite).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use WPContext\Cli\Markdown_Views_Command;

require_once __DIR__ . '/../wp-stubs.php';

final class Markdown_Views_Command_Test extends TestCase {

	public function test_simple_title_is_quoted_and_round_trips_through_yaml(): void {
		$out = Markdown_Views_Command::format_yaml_header(
			42,
			'Hello, world',
			'https://example.com/hello',
			'2026-05-14T14:00:00+00:00'
		);

		self::assertStringContainsString( "id: 42\n", $out );
		self::assertStringContainsString( "title: \"Hello, world\"\n", $out );
		self::assertStringContainsString( "canonical_url: \"https://example.com/hello\"\n", $out );
		self::assertStringContainsString( "generated_at: \"2026-05-14T14:00:00+00:00\"\n", $out );
		self::assertStringStartsWith( "---\n", $out );
		self::assertStringEndsWith( "---\n\n", $out );
	}

	public function test_title_with_backslash_is_escaped(): void {
		// Naïve `str_replace('"', '\"', ...)` would leave `C:\Users` untouched
		// and downstream YAML parsers would interpret `\U` as a Unicode escape.
		// wp_json_encode emits `"C:\\Users\\admin"` — valid YAML 1.2 scalar.
		$out = Markdown_Views_Command::format_yaml_header(
			1,
			'C:\\Users\\admin',
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "C:\\\\Users\\\\admin"', $out );
	}

	public function test_title_with_embedded_double_quote_is_escaped(): void {
		// Naïve approach turned this into "He said \"hi\"" which IS valid
		// YAML — the regression test cases here are the harder ones below.
		// This case still has to keep working.
		$out = Markdown_Views_Command::format_yaml_header(
			2,
			'He said "hi"',
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "He said \\"hi\\""', $out );
	}

	public function test_title_with_literal_newline_is_escaped(): void {
		// Naïve approach emitted the literal newline into the YAML output,
		// breaking the scalar mid-string. wp_json_encode emits `\n`.
		$out = Markdown_Views_Command::format_yaml_header(
			3,
			"line one\nline two",
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "line one\\nline two"', $out );
		// Confirm the title line is on ONE physical line in the output.
		$lines = \explode( "\n", $out );
		$title_lines = \array_filter( $lines, static fn( $l ) => \strpos( $l, 'title:' ) === 0 );
		self::assertCount( 1, $title_lines );
	}

	public function test_title_with_unbalanced_quote_is_escaped(): void {
		// A stray `"` would have terminated the YAML scalar early under
		// the naïve approach. wp_json_encode escapes it.
		$out = Markdown_Views_Command::format_yaml_header(
			4,
			'before " after',
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "before \\" after"', $out );
	}

	public function test_title_with_tab_is_escaped(): void {
		$out = Markdown_Views_Command::format_yaml_header(
			5,
			"col1\tcol2",
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "col1\\tcol2"', $out );
	}

	public function test_unicode_in_title_is_preserved_unescaped(): void {
		// JSON_UNESCAPED_UNICODE keeps non-ASCII readable rather than
		// emitting \uXXXX escape sequences.
		$out = Markdown_Views_Command::format_yaml_header(
			6,
			'Café — résumé',
			'https://example.com/café',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'title: "Café — résumé"', $out );
		self::assertStringNotContainsString( '\\u', $out );
	}

	public function test_canonical_url_with_query_string_is_preserved_unescaped(): void {
		// JSON_UNESCAPED_SLASHES keeps URL `/` and `&` readable.
		$out = Markdown_Views_Command::format_yaml_header(
			7,
			'Plain',
			'https://example.com/path?a=1&b=2',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( 'canonical_url: "https://example.com/path?a=1&b=2"', $out );
		// JSON's `\/` escape sequence — must NOT appear.
		self::assertStringNotContainsString( '\\/', $out );
	}

	public function test_id_is_emitted_as_unquoted_integer(): void {
		// YAML integer scalars must be unquoted to parse as int (not string).
		$out = Markdown_Views_Command::format_yaml_header(
			12345,
			'Plain',
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		self::assertStringContainsString( "id: 12345\n", $out );
		// And NOT the quoted-string form.
		self::assertStringNotContainsString( 'id: "12345"', $out );
	}

	public function test_full_header_is_bracketed_correctly(): void {
		$out = Markdown_Views_Command::format_yaml_header(
			1,
			'Plain',
			'https://example.com/',
			'2026-05-14T00:00:00+00:00'
		);

		// Leading `---\n`, then 4 metadata lines, then `---\n`, then a blank line.
		$expected = "---\n"
			. "id: 1\n"
			. 'title: "Plain"' . "\n"
			. 'canonical_url: "https://example.com/"' . "\n"
			. 'generated_at: "2026-05-14T00:00:00+00:00"' . "\n"
			. "---\n\n";
		self::assertSame( $expected, $out );
	}
}
