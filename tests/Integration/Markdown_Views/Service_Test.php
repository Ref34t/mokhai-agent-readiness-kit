<?php
/**
 * Integration tests for Mokhai\Markdown_Views\Service.
 *
 * Runs inside the wp-phpunit test instance so we exercise the real `$wpdb`,
 * the real `apply_filters('the_content', ...)` pipeline, and the real
 * cache-invalidation hooks. Verifies the full round trip:
 *   - get_markdown_for_post on a disabled module → WP_Error(module_disabled)
 *   - get_markdown_for_post on a non-exposable post → WP_Error(not_exposable)
 *   - get_markdown_for_post on an exposable post → MD string, writes cache
 *   - Second call → returns cached MD, no walker re-run
 *   - invalidate() drops the row
 *   - save_post hook auto-invalidates
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Service;
use Mokhai\Markdown_Views\Walker;

final class Service_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();

		// Ensure a clean profile per test — exposed CPTs configured so
		// posts are exposable unless a specific test denies them.
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post', 'page' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);
	}

	protected function tearDown(): void {
		// Reset filter state so a test that adds a noindex filter doesn't
		// leak across tests.
		remove_all_filters( 'agentready_post_is_noindexed' );
		Schema::drop();
		parent::tearDown();
	}

	private function cache_row_for( int $post_id ): ?array {
		global $wpdb;
		$table = Schema::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $row ) ? $row : null;
	}

	public function test_module_disabled_returns_wp_error(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post' ),
					'markdown_views_enabled' => false,
				)
			)
		);

		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Hello.' ) );

		$result = Service::get_markdown_for_post( $post );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( Service::ERROR_MODULE_DISABLED, $result->get_error_code() );
	}

	public function test_unexposable_cpt_returns_wp_error(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts' => array(), // post type not in list
				)
			)
		);

		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Hello.' ) );

		$result = Service::get_markdown_for_post( $post );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( Service::ERROR_NOT_EXPOSABLE, $result->get_error_code() );
	}

	public function test_password_protected_post_returns_wp_error(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content'  => 'Hello.',
				'post_password' => 'sekret',
			)
		);

		$result = Service::get_markdown_for_post( $post );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( Service::ERROR_NOT_EXPOSABLE, $result->get_error_code() );
	}

	public function test_exposable_post_returns_markdown_and_writes_cache(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<h2>A heading</h2><p>A paragraph with <strong>strong</strong> text.</p>',
			)
		);

		$result = Service::get_markdown_for_post( $post );

		self::assertIsString( $result );
		self::assertStringContainsString( '## A heading', $result );
		self::assertStringContainsString( '**strong**', $result );

		$row = $this->cache_row_for( $post->ID );
		self::assertNotNull( $row, 'Cache row should be written' );
		self::assertSame( Walker::WALKER_VERSION, $row['walker_version'] );
		self::assertSame( $result, $row['markdown'] );
	}

	public function test_second_call_returns_cached_value(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>First version.</p>' )
		);

		$first = Service::get_markdown_for_post( $post );
		self::assertIsString( $first );

		// Hand-mutate the cache row to a sentinel so we can prove the second
		// call returns from cache, not from the walker.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Schema::table_name(),
			array( 'markdown' => 'CACHED-SENTINEL' ),
			array( 'post_id' => $post->ID ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		$second = Service::get_markdown_for_post( $post );

		self::assertSame( 'CACHED-SENTINEL', $second );
	}

	public function test_invalidate_drops_cache_row(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Hello.</p>' )
		);

		Service::get_markdown_for_post( $post );
		self::assertNotNull( $this->cache_row_for( $post->ID ) );

		Service::invalidate( $post->ID );

		self::assertNull( $this->cache_row_for( $post->ID ) );
	}

	public function test_save_post_hook_invalidates_cache(): void {
		Service::register_hooks();

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Hello.</p>' )
		);

		Service::get_markdown_for_post( $post );
		self::assertNotNull( $this->cache_row_for( $post->ID ) );

		// Trigger save_post by updating the post.
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => '<p>Updated.</p>',
			)
		);

		self::assertNull(
			$this->cache_row_for( $post->ID ),
			'Cache row should be removed by save_post hook'
		);
	}

	public function test_walker_version_mismatch_treats_as_cache_miss(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Hello.</p>' )
		);

		Service::get_markdown_for_post( $post );

		// Hand-mutate stored walker_version to a value the live class doesn't
		// recognise. Next read should treat as miss and rewrite with the
		// current WALKER_VERSION.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Schema::table_name(),
			array( 'walker_version' => 'legacy-0' ),
			array( 'post_id' => $post->ID ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		Service::get_markdown_for_post( $post );

		$row = $this->cache_row_for( $post->ID );
		self::assertSame(
			Walker::WALKER_VERSION,
			$row['walker_version'],
			'Stale walker version should be overwritten on next read'
		);
	}
}
