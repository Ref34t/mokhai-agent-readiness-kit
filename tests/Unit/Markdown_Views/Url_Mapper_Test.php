<?php
/**
 * Unit tests for the canonical permalink → Markdown-View URL mapper (#178).
 *
 * Pure function — no WordPress load beyond the `wp_parse_url` stub. Mirrors the
 * rewrite contract in `Markdown_Views\Router` that the `.md` route resolves.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use WPContext\Markdown_Views\Url_Mapper;

final class Url_Mapper_Test extends TestCase {

	public function test_pretty_permalink_gets_md_suffix(): void {
		self::assertSame(
			'https://example.com/lessons/foo.md',
			Url_Mapper::to_md_url( 'https://example.com/lessons/foo/' )
		);
	}

	public function test_pretty_permalink_without_trailing_slash(): void {
		self::assertSame(
			'https://example.com/lessons/foo.md',
			Url_Mapper::to_md_url( 'https://example.com/lessons/foo' )
		);
	}

	public function test_plain_permalink_gets_format_query(): void {
		self::assertSame(
			'https://example.com/?p=42&format=md',
			Url_Mapper::to_md_url( 'https://example.com/?p=42' )
		);
	}

	public function test_pretty_permalink_carrying_query_takes_query_branch(): void {
		// Presence of a query string — not permalink mode — picks the branch.
		self::assertSame(
			'https://example.com/lessons/foo/?ver=2&format=md',
			Url_Mapper::to_md_url( 'https://example.com/lessons/foo/?ver=2' )
		);
	}

	public function test_root_url_with_trailing_slash_uses_query_form(): void {
		// Front page: `https://host/` must NOT become `https://host.md`. (#241)
		self::assertSame(
			'https://example.com/?format=md',
			Url_Mapper::to_md_url( 'https://example.com/' )
		);
	}

	public function test_root_url_without_trailing_slash_uses_query_form(): void {
		// Same root case with no trailing slash. (#241)
		self::assertSame(
			'https://example.com/?format=md',
			Url_Mapper::to_md_url( 'https://example.com' )
		);
	}

	public function test_idempotent_on_md_suffix(): void {
		self::assertSame(
			'https://example.com/lessons/foo.md',
			Url_Mapper::to_md_url( 'https://example.com/lessons/foo.md' )
		);
	}

	public function test_idempotent_on_format_query(): void {
		self::assertSame(
			'https://example.com/?p=42&format=md',
			Url_Mapper::to_md_url( 'https://example.com/?p=42&format=md' )
		);
	}

	public function test_idempotent_on_format_query_case_insensitive(): void {
		self::assertSame(
			'https://example.com/?p=42&FORMAT=MD',
			Url_Mapper::to_md_url( 'https://example.com/?p=42&FORMAT=MD' )
		);
	}
}
