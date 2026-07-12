<?php
/**
 * Unit tests for the hidden in-content discovery anchor builder (#283 /
 * AgDR-0067).
 *
 * `build_anchor()` is pure. The contract under test encodes the empirical
 * findings the feature exists for: the literal URL must appear in the anchor
 * TEXT (ChatGPT's viewer strips hrefs but keeps text), hiding must ride on
 * the stylesheet class (never an inline style attribute, which extraction
 * pipelines check), and the machine-facing link must stay out of the
 * accessibility tree.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Mokhai\Discovery\Content_Link;

final class Content_Link_Test extends TestCase {

	public function test_anchor_carries_url_in_text_not_just_href(): void {
		$anchor = Content_Link::build_anchor( 'https://example.com/careers.md' );

		self::assertStringContainsString( 'href="https://example.com/careers.md"', $anchor );
		self::assertStringContainsString( 'Markdown version of this page: https://example.com/careers.md', $anchor );
	}

	public function test_anchor_hides_via_class_not_inline_style(): void {
		$anchor = Content_Link::build_anchor( 'https://example.com/careers.md' );

		self::assertStringContainsString( 'class="' . Content_Link::CSS_CLASS . '"', $anchor );
		self::assertStringNotContainsString( 'style=', $anchor );
		self::assertStringNotContainsString( ' hidden', $anchor );
	}

	public function test_anchor_is_out_of_the_accessibility_tree(): void {
		$anchor = Content_Link::build_anchor( 'https://example.com/careers.md' );

		self::assertStringContainsString( 'aria-hidden="true"', $anchor );
		self::assertStringContainsString( 'tabindex="-1"', $anchor );
	}

	public function test_url_is_escaped_in_both_contexts(): void {
		$anchor = Content_Link::build_anchor( 'https://example.com/a.md?x="><script>' );

		self::assertStringNotContainsString( '"><script>', $anchor );
	}
}
