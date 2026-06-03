<?php
/**
 * Integration tests for WPContext\Cli\Cleanup_Meta_Migration_Command.
 *
 * Exercises the static `run()` sweep against a real wp_postmeta table:
 * deletion + per-key counts, idempotency, dry-run, and that unrelated
 * meta is left untouched. The thin `sweep()` WP-CLI wrapper just formats
 * `run()`'s result, so testing `run()` covers the behaviour without a
 * WP-CLI shim. See #159 / AgDR-0050.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Cli;

use WP_UnitTestCase;
use WPContext\Cli\Cleanup_Meta_Migration_Command;
use WPContext\Markdown_Views\Schema;

/**
 * @covers \WPContext\Cli\Cleanup_Meta_Migration_Command
 */
final class Cleanup_Meta_Migration_Command_Test extends WP_UnitTestCase {

	/**
	 * Provision the Markdown Views cache table. Creating posts fires
	 * `save_post` → `Service::invalidate()`, which DELETEs from that table;
	 * without it wp-phpunit prints a wpdb "table doesn't exist" warning and
	 * PHPUnit flags the test risky. DDL is bracketed in setUp/tearDown (it
	 * auto-commits past the per-test transaction).
	 */
	protected function setUp(): void {
		parent::setUp();
		Schema::create();
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	/**
	 * Seed every dead cleanup key (plus one unrelated key) on a fresh post.
	 *
	 * @return int Post ID.
	 */
	private function seed_post(): int {
		$post_id = self::factory()->post->create();

		foreach ( Cleanup_Meta_Migration_Command::META_KEYS as $key ) {
			\add_post_meta( $post_id, $key, 'dead-value' );
		}
		\add_post_meta( $post_id, '_agentready_md_cache_marker', 'keep-me' );

		return $post_id;
	}

	public function test_run_deletes_all_cleanup_meta_and_reports_per_key_counts(): void {
		$post_id = $this->seed_post();

		$counts = Cleanup_Meta_Migration_Command::run();

		// One row deleted per key.
		foreach ( Cleanup_Meta_Migration_Command::META_KEYS as $key ) {
			self::assertSame( 1, $counts[ $key ], "expected one deleted row for {$key}" );
			self::assertSame( array(), \get_post_meta( $post_id, $key ), "{$key} should be gone" );
		}

		// Unrelated plugin meta is untouched.
		self::assertSame( array( 'keep-me' ), \get_post_meta( $post_id, '_agentready_md_cache_marker' ) );
	}

	public function test_run_is_idempotent(): void {
		$this->seed_post();
		Cleanup_Meta_Migration_Command::run();

		$second = Cleanup_Meta_Migration_Command::run();

		foreach ( Cleanup_Meta_Migration_Command::META_KEYS as $key ) {
			self::assertSame( 0, $second[ $key ], "re-run should delete nothing for {$key}" );
		}
	}

	public function test_dry_run_counts_without_deleting(): void {
		$post_id = $this->seed_post();

		$counts = Cleanup_Meta_Migration_Command::run( true );

		foreach ( Cleanup_Meta_Migration_Command::META_KEYS as $key ) {
			self::assertSame( 1, $counts[ $key ], "dry-run should report one row for {$key}" );
			self::assertSame( array( 'dead-value' ), \get_post_meta( $post_id, $key ), "{$key} must still exist after dry-run" );
		}
	}

	public function test_run_reports_zero_when_no_cleanup_meta_present(): void {
		self::factory()->post->create();

		$counts = Cleanup_Meta_Migration_Command::run();

		self::assertSame( array(), \array_filter( $counts ), 'all keys should report zero on a clean install' );
	}
}
