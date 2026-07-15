<?php
/**
 * Unit tests for the rendered-HTML main-content extractor (#297 / AgDR-0069).
 *
 * `Rendered_Html_Source::extract_main_html()` is pure over an HTML string (no
 * WordPress calls), so it is tested directly here without a live site. These
 * tests exercise:
 *
 * 1. Semantic-first region selection: <main> > <article> > [role=main] > class
 *    /id content hints.
 * 2. Chrome stripping — nav/header/footer/aside/form/script/style and ARIA
 *    landmark roles removed, even when nested inside the chosen region.
 * 3. The density fallback — the content-heavy container wins over a link farm
 *    when no semantic region exists; the whole chrome-stripped body is the last
 *    resort.
 * 4. Robustness — empty input and malformed HTML never throw.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use Mokhai\Markdown_Views\Rendered_Html_Source;

final class Rendered_Html_Source_Test extends TestCase {

	// --- semantic region selection ---------------------------------------

	public function test_prefers_main_and_excludes_sibling_chrome(): void {
		$html = '<body>'
			. '<nav><a href="/">Menu Home</a></nav>'
			. '<main><p>The primary article body lives here.</p></main>'
			. '<footer>Copyright notice</footer>'
			. '</body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'primary article body', $out );
		self::assertStringNotContainsString( 'Menu Home', $out );
		self::assertStringNotContainsString( 'Copyright notice', $out );
	}

	public function test_falls_back_to_article_when_no_main(): void {
		$html = '<body><article><p>Article content only.</p></article></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Article content only.', $out );
	}

	public function test_selects_role_main(): void {
		$html = '<body><div role="main"><p>Role main content.</p></div></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Role main content.', $out );
	}

	public function test_selects_entry_content_class_hint(): void {
		$html = '<body>'
			. '<div class="sidebar"><p>Widgets</p></div>'
			. '<div class="entry-content"><p>Hinted content region.</p></div>'
			. '</body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Hinted content region.', $out );
	}

	// --- chrome stripping -------------------------------------------------

	public function test_strips_chrome_nested_inside_region(): void {
		$html = '<body><main>'
			. '<nav><a href="/x">Skip nav link</a></nav>'
			. '<p>Body paragraph kept.</p>'
			. '</main></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Body paragraph kept.', $out );
		self::assertStringNotContainsString( 'Skip nav link', $out );
	}

	public function test_strips_role_navigation_landmark(): void {
		$html = '<body><main>'
			. '<div role="navigation"><a href="/a">Menu entry</a></div>'
			. '<p>Actual content paragraph.</p>'
			. '</main></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Actual content paragraph.', $out );
		self::assertStringNotContainsString( 'Menu entry', $out );
	}

	public function test_strips_scripts_and_styles(): void {
		$html = '<body><main>'
			. '<script>alert(1)</script>'
			. '<style>.x{color:red}</style>'
			. '<p>Clean body.</p>'
			. '</main></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Clean body.', $out );
		self::assertStringNotContainsString( 'alert(1)', $out );
		self::assertStringNotContainsString( 'color:red', $out );
	}

	// --- density fallback -------------------------------------------------

	public function test_density_fallback_prefers_content_over_link_farm(): void {
		$paragraph = str_repeat( 'This is a real sentence of article prose. ', 8 ); // > 200 chars
		$html      = '<body>'
			. '<div class="links"><a href="/1">one</a><a href="/2">two</a><a href="/3">three</a></div>'
			. '<div class="body">' . '<p>' . $paragraph . '</p></div>'
			. '</body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'real sentence of article prose', $out );
	}

	public function test_whole_body_fallback_when_no_region_and_short(): void {
		$html = '<body><p>Short standalone note.</p></body>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertStringContainsString( 'Short standalone note.', $out );
	}

	// --- robustness -------------------------------------------------------

	public function test_empty_input_returns_empty_string(): void {
		self::assertSame( '', Rendered_Html_Source::extract_main_html( '' ) );
		self::assertSame( '', Rendered_Html_Source::extract_main_html( '   ' ) );
	}

	public function test_malformed_html_does_not_throw(): void {
		$html = '<body><main><p>Unclosed paragraph<div>and a stray div</main>';

		$out = Rendered_Html_Source::extract_main_html( $html );

		self::assertIsString( $out );
		self::assertStringContainsString( 'Unclosed paragraph', $out );
	}
}
