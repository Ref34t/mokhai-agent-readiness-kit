<?php
/**
 * Unit tests for the output-buffer hygiene helper (#175).
 *
 * `strip_leading_bom()` and `is_plugin_rest_route()` are pure — tested directly
 * with no WordPress load. `discard_pending()` short-circuits once output has
 * been sent (its graceful-degrade for the unrecoverable upstream case), and by
 * the time the runner reaches this test its progress output has already been
 * flushed — so its buffer-discarding effect is only observable in a clean
 * process. That one case runs with `@runInSeparateProcess`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Mokhai\Support\Output_Buffer;

final class Output_Buffer_Test extends TestCase {

	public function test_strips_leading_utf8_bom(): void {
		self::assertSame( '# Heading', Output_Buffer::strip_leading_bom( "\xEF\xBB\xBF# Heading" ) );
	}

	public function test_strips_bom_followed_by_whitespace(): void {
		self::assertSame( '# Heading', Output_Buffer::strip_leading_bom( "\xEF\xBB\xBF\n\n# Heading" ) );
	}

	public function test_strips_leading_whitespace_without_bom(): void {
		self::assertSame( '# Heading', Output_Buffer::strip_leading_bom( "\n\n  # Heading" ) );
	}

	public function test_clean_body_passes_through_unchanged(): void {
		self::assertSame( '# Heading', Output_Buffer::strip_leading_bom( '# Heading' ) );
	}

	public function test_empty_body_passes_through(): void {
		self::assertSame( '', Output_Buffer::strip_leading_bom( '' ) );
	}

	public function test_only_leading_bom_removed_trailing_preserved(): void {
		// A BOM mid-body must NOT be touched, and trailing whitespace stays.
		self::assertSame(
			"start\n\nend  ",
			Output_Buffer::strip_leading_bom( "\xEF\xBB\xBFstart\n\nend  " )
		);
	}

	public function test_recognises_plugin_rest_routes(): void {
		self::assertTrue( Output_Buffer::is_plugin_rest_route( '/ai-readiness-kit/v1/context-profile' ) );
		self::assertTrue( Output_Buffer::is_plugin_rest_route( '/ai-readiness-kit/v1/markdown' ) );
	}

	public function test_rejects_foreign_and_empty_rest_routes(): void {
		self::assertFalse( Output_Buffer::is_plugin_rest_route( '/wp/v2/posts' ) );
		self::assertFalse( Output_Buffer::is_plugin_rest_route( '/oembed/1.0/embed' ) );
		self::assertFalse( Output_Buffer::is_plugin_rest_route( '' ) );
		// Substring-but-not-prefix must not match.
		self::assertFalse( Output_Buffer::is_plugin_rest_route( '/x/ai-readiness-kit/v1/y' ) );
	}

	/**
	 * Runs in a clean child process so no prior output has been flushed and
	 * `headers_sent()` is false — the only state in which the discard is
	 * observable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_discard_pending_empties_pending_output_buffers(): void {
		$baseline = ob_get_level();

		ob_start();
		echo "\xEF\xBB\xBFleaked pollution";

		Output_Buffer::discard_pending();
		$after = ob_get_level();

		// Re-establish the baseline so the runner's stack is left as found.
		while ( ob_get_level() < $baseline ) {
			ob_start();
		}

		// The seeded buffer (baseline + 1) and any below it were discarded.
		self::assertLessThanOrEqual( $baseline, $after );
	}
}
