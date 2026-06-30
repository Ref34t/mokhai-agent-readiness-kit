<?php
/**
 * Integration tests for the AI Assistant Preview REST controller (#45).
 *
 * Exercises the real route registration, the `manage_options` gate, and the
 * response shapes against a live WP test instance:
 *
 *   - All three routes registered under `ai-readiness-kit/v1/ai-preview`
 *   - 403 for a non-admin on every route
 *   - GET /posts returns published posts in the dropdown shape
 *   - GET /preview returns the four-pane payload for an exposable post
 *   - GET /preview → 404 on a missing post
 *   - GET /preview reflects a pre-seeded cached summary
 *   - POST /summary degrades to 'empty_input' when there's no markdown
 *
 * The summary success path depends on a configured WP AI Client, so it is
 * verified live on wp-env (PR B) rather than asserted here — these tests
 * pin only the deterministic transport behaviour.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Ai_Preview;

use WP_REST_Request;
use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Ai_Preview\Rest_Controller;
use Mokhai\Ai_Preview\Summary_Generator;
use Mokhai\Markdown_Views\Schema;

final class Rest_Controller_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post', 'page' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => true,
				)
			)
		);
		// Routes register via the production hook chain on rest_api_init.
	}

	protected function tearDown(): void {
		Schema::drop();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function admin_user(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return (int) $id;
	}

	private function subscriber_user(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );
		return (int) $id;
	}

	private function base(): string {
		return '/' . Rest_Controller::NAMESPACE . Rest_Controller::ROUTE_BASE;
	}

	public function test_all_three_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey( $this->base() . '/posts', $routes );
		self::assertArrayHasKey( $this->base() . '/preview', $routes );
		self::assertArrayHasKey( $this->base() . '/summary', $routes );
	}

	public function test_non_admin_is_forbidden_on_every_route(): void {
		$this->subscriber_user();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$posts   = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $this->base() . '/posts' ) );
		$preview = new WP_REST_Request( 'GET', $this->base() . '/preview' );
		$preview->set_param( 'post', $post_id );
		$summary = new WP_REST_Request( 'POST', $this->base() . '/summary' );
		$summary->set_param( 'post', $post_id );

		self::assertSame( 403, $posts->get_status() );
		self::assertSame( 403, rest_get_server()->dispatch( $preview )->get_status() );
		self::assertSame( 403, rest_get_server()->dispatch( $summary )->get_status() );
	}

	public function test_posts_route_returns_published_posts(): void {
		$this->admin_user();
		self::factory()->post->create( array( 'post_title' => 'Hello', 'post_status' => 'publish' ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $this->base() . '/posts' ) );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertArrayHasKey( 'posts', $data );
		self::assertArrayHasKey( 'total', $data );
		self::assertGreaterThanOrEqual( 1, count( $data['posts'] ) );
		self::assertArrayHasKey( 'id', $data['posts'][0] );
		self::assertArrayHasKey( 'title', $data['posts'][0] );
		self::assertArrayHasKey( 'url', $data['posts'][0] );
	}

	public function test_preview_returns_four_pane_payload_for_exposable_post(): void {
		$this->admin_user();
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Widgets Explained',
				'post_content' => 'Some content about widgets.',
				'post_status'  => 'publish',
			)
		);

		$request = new WP_REST_Request( 'GET', $this->base() . '/preview' );
		$request->set_param( 'post', $post_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $post_id, $data['post']['id'] );
		self::assertArrayHasKey( 'html', $data['raw_html'] );
		self::assertArrayHasKey( 'full_length', $data['raw_html'] );
		self::assertSame( 'exposable', $data['markdown']['visibility']['verdict'] );
		self::assertTrue( $data['llms_entry']['present'] );
		self::assertStringContainsString( 'Widgets Explained', $data['llms_entry']['line'] );
		self::assertNull( $data['summary'] );
	}

	public function test_preview_404_on_missing_post(): void {
		$this->admin_user();
		$request = new WP_REST_Request( 'GET', $this->base() . '/preview' );
		$request->set_param( 'post', 99999 );

		self::assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_preview_reflects_cached_summary(): void {
		$this->admin_user();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, Summary_Generator::META_KEY_TEXT, 'A cached summary.' );
		update_post_meta( $post_id, Summary_Generator::META_KEY_GENERATED, '2026-06-02T10:00:00+00:00' );

		$request = new WP_REST_Request( 'GET', $this->base() . '/preview' );
		$request->set_param( 'post', $post_id );
		$data = rest_get_server()->dispatch( $request )->get_data();

		self::assertNotNull( $data['summary'] );
		self::assertSame( 'A cached summary.', $data['summary']['text'] );
		self::assertSame( 'llm', $data['summary']['source'] );
	}

	public function test_summary_degrades_to_empty_input_when_module_disabled(): void {
		$this->admin_user();
		// Disable Markdown Views → no markdown to summarise → empty_input.
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => false,
				)
			)
		);
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'POST', $this->base() . '/summary' );
		$request->set_param( 'post', $post_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertNull( $data['text'] );
		self::assertSame( 'empty_input', $data['state'] );
	}
}
