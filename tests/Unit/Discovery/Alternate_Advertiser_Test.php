<?php
/**
 * Unit tests for the pure builders of the agent-surface advertiser (#178).
 *
 * The hook callbacks (resolve globals, echo, send headers) are exercised in the
 * integration suite; here we pin the exact strings the builders emit, including
 * escaping (via the `esc_url` / `esc_url_raw` stubs).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Mokhai\Discovery\Alternate_Advertiser;

final class Alternate_Advertiser_Test extends TestCase {

	public function test_md_link_tag_shape(): void {
		self::assertSame(
			'<link rel="alternate" type="text/markdown" href="https://example.com/foo.md" />' . "\n",
			Alternate_Advertiser::build_md_link_tag( 'https://example.com/foo.md' )
		);
	}

	public function test_md_link_tag_escapes_ampersand_for_attribute_context(): void {
		// Plain-permalink .md URLs carry `&` — esc_url HTML-encodes it.
		self::assertSame(
			'<link rel="alternate" type="text/markdown" href="https://example.com/?p=42&#038;format=md" />' . "\n",
			Alternate_Advertiser::build_md_link_tag( 'https://example.com/?p=42&format=md' )
		);
	}

	public function test_llms_link_tag_shape(): void {
		self::assertSame(
			'<link rel="alternate" type="text/plain" href="https://example.com/llms.txt" />' . "\n",
			Alternate_Advertiser::build_llms_link_tag( 'https://example.com/llms.txt' )
		);
	}

	public function test_link_header_value_shape(): void {
		// Header context uses esc_url_raw — no HTML entity encoding of `&`.
		self::assertSame(
			'<https://example.com/foo.md>; rel="alternate"; type="text/markdown"',
			Alternate_Advertiser::build_md_link_header( 'https://example.com/foo.md' )
		);
	}

	public function test_augment_robots_txt_appends_absolute_url_comment(): void {
		$out = Alternate_Advertiser::augment_robots_txt(
			"User-agent: *\nDisallow:\n",
			'https://example.com/llms.txt'
		);

		self::assertStringContainsString(
			'# AI-readable content index (agentready): https://example.com/llms.txt',
			$out
		);
		// Original content preserved, single trailing newline, no blank-line gap.
		self::assertStringStartsWith( "User-agent: *\nDisallow:\n", $out );
		self::assertStringEndsWith( "\n", $out );
		self::assertStringNotContainsString( "\n\n", $out );
	}
}
