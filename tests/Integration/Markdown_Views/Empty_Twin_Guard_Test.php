<?php
/**
 * Integration tests for the empty-twin guard (#292 / AgDR-0068).
 *
 * An exposable post whose conversion is empty — the ACF/page-builder case where
 * `post_content` is empty and no source adapter contributed — must NOT be
 * served as a 0-byte `200`. The guard returns `WP_Error(empty_content)` so the
 * public route 404s, while the (empty) cache row is still written so the
 * Context Score body-quality sampler continues to count it.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Service;

final class Empty_Twin_Guard_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();

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

	public function test_empty_content_post_returns_empty_content_error(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );

		$result = Service::get_markdown_for_post( $post );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( Service::ERROR_EMPTY_CONTENT, $result->get_error_code() );
		// Rides the REST/route 404 path.
		self::assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_empty_conversion_still_writes_cache_row(): void {
		// The guard changes what is SERVED, not what is MEASURED — the empty
		// row must persist so the Context Score empty-page sampler (#255) counts it.
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );

		Service::get_markdown_for_post( $post );

		$row = $this->cache_row_for( $post->ID );
		self::assertNotNull( $row, 'Empty conversion should still be cached' );
		self::assertSame( '', trim( (string) $row['markdown'] ) );
	}

	public function test_is_empty_for_post_true_for_empty_false_for_content(): void {
		$empty = self::factory()->post->create_and_get( array( 'post_content' => '' ) );
		$full  = self::factory()->post->create_and_get( array( 'post_content' => '<p>Real body.</p>' ) );

		self::assertTrue( Service::is_empty_for_post( $empty ) );
		self::assertFalse( Service::is_empty_for_post( $full ) );
	}

	public function test_non_empty_post_is_unaffected_by_the_guard(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>A real paragraph.</p>' )
		);

		$result = Service::get_markdown_for_post( $post );

		self::assertIsString( $result );
		self::assertStringContainsString( 'A real paragraph.', $result );
	}
}
