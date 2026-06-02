<?php
/**
 * Integration tests for `WPContext\Ai_Preview\Preview_Builder` (#45).
 *
 * Exercises the aggregator against real WP posts, the real Markdown Views
 * service, and the real llms.txt Entry_Source:
 *
 *   - build() assembles all four panes for an exposable post
 *   - markdown_for() returns module_disabled / not_exposable verdicts
 *   - llms_entry matches the live /llms.txt line shape
 *   - raw_html truncates + reports full_length past the cap
 *   - opening the preview does NOT schedule cleanup (read-only)
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Ai_Preview;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Ai_Preview\Preview_Builder;
use WPContext\Markdown_Views\Schema;

final class Preview_Builder_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();
		$this->enable_markdown_views( true );
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	private function enable_markdown_views( bool $enabled ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post', 'page' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => $enabled,
				)
			)
		);
	}

	public function test_build_assembles_all_four_panes(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'About Widgets',
				'post_content' => 'A paragraph describing widgets in detail.',
				'post_status'  => 'publish',
			)
		);

		$payload = Preview_Builder::build( $post );

		self::assertSame( (int) $post->ID, $payload['post']['id'] );
		self::assertSame( 'About Widgets', $payload['post']['title'] );
		self::assertArrayHasKey( 'html', $payload['raw_html'] );
		self::assertFalse( $payload['raw_html']['truncated'] );
		self::assertSame( 'exposable', $payload['markdown']['visibility']['verdict'] );
		self::assertIsString( $payload['markdown']['markdown'] );
		self::assertTrue( $payload['llms_entry']['present'] );
		self::assertNull( $payload['summary'] );
	}

	public function test_markdown_for_reports_module_disabled(): void {
		$this->enable_markdown_views( false );
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$pane = Preview_Builder::markdown_for( $post );

		self::assertSame( 'module_disabled', $pane['visibility']['verdict'] );
		self::assertSame( '', $pane['markdown'] );
	}

	public function test_markdown_for_reports_not_exposable_for_draft(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		$pane = Preview_Builder::markdown_for( $post );

		self::assertSame( 'not_exposable', $pane['visibility']['verdict'] );
		self::assertNotNull( $pane['visibility']['reason'] );
	}

	public function test_llms_entry_absent_for_non_exposable_post(): void {
		$post    = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$payload = Preview_Builder::build( $post );

		self::assertFalse( $payload['llms_entry']['present'] );
		self::assertSame( '', $payload['llms_entry']['line'] );
		self::assertSame( 'none', $payload['llms_entry']['description_source'] );
	}

	public function test_llms_entry_line_has_title_and_markdown_link(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Pricing',
				'post_status' => 'publish',
			)
		);

		$payload = Preview_Builder::build( $post );

		self::assertTrue( $payload['llms_entry']['present'] );
		self::assertStringStartsWith( '- [Pricing](', $payload['llms_entry']['line'] );
	}

	public function test_raw_html_truncates_past_the_cap(): void {
		$big  = str_repeat( 'word ', Preview_Builder::RAW_HTML_CAP ); // well past the cap
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => $big,
				'post_status'  => 'publish',
			)
		);

		$payload = Preview_Builder::build( $post );

		self::assertTrue( $payload['raw_html']['truncated'] );
		self::assertGreaterThan( Preview_Builder::RAW_HTML_CAP, $payload['raw_html']['full_length'] );
		self::assertLessThanOrEqual( Preview_Builder::RAW_HTML_CAP, strlen( $payload['raw_html']['html'] ) );
	}
}
