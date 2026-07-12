<?php
/**
 * Unit tests for the permalink → mirror-file path mapper (#283 / AgDR-0067).
 *
 * `relative_path_for_permalink()` is pure. The contract under test: pretty
 * paths mirror one-to-one, the root maps to `index.md`, query-string
 * permalinks (no path identity) fall back to the post-ID name, subdirectory
 * installs strip the home path, and no derived path can escape the mirror
 * root.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use Mokhai\Markdown_Views\Static_Mirror;

final class Static_Mirror_Path_Test extends TestCase {

	public function test_top_level_page_maps_to_flat_file(): void {
		self::assertSame(
			'careers.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/careers/', '/', 42 )
		);
	}

	public function test_nested_page_maps_to_nested_path(): void {
		self::assertSame(
			'about/team.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/about/team/', '/', 42 )
		);
	}

	public function test_no_trailing_slash_is_equivalent(): void {
		self::assertSame(
			'careers.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/careers', '/', 42 )
		);
	}

	public function test_root_maps_to_index(): void {
		self::assertSame(
			'index.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/', '/', 42 )
		);
	}

	public function test_query_permalink_falls_back_to_post_id(): void {
		// Plain-permalink mode: the URL has no path identity to mirror.
		self::assertSame(
			'post-42.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/?p=42', '/', 42 )
		);
	}

	public function test_subdirectory_install_strips_home_path(): void {
		self::assertSame(
			'careers.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/blog/careers/', '/blog/', 42 )
		);
	}

	public function test_subdirectory_root_maps_to_index(): void {
		self::assertSame(
			'index.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/blog/', '/blog/', 42 )
		);
	}

	public function test_traversal_dots_cannot_survive_as_segments(): void {
		$path = Static_Mirror::relative_path_for_permalink( 'https://example.com/../../etc/passwd/', '/', 42 );

		self::assertStringNotContainsString( '..', $path );
		self::assertStringEndsWith( '.md', $path );
	}

	public function test_all_segments_sanitized_away_falls_back_to_post_id(): void {
		self::assertSame(
			'post-42.md',
			Static_Mirror::relative_path_for_permalink( 'https://example.com/../..', '/', 42 )
		);
	}
}
