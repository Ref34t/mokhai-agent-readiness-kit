<?php
/**
 * Integration tests for the Markdown Views REST preview endpoint.
 *
 * Exercises the real `register_rest_route()` registration, permission
 * callback, and response shape against a live WP test instance. Verifies:
 *
 *   - Route is registered under `agentready/v1/markdown-views/preview`
 *   - 200 + structured response on exposable post (cache + visibility data)
 *   - 200 + `visibility.verdict=not_exposable` with reason code for hidden posts
 *   - 403 on module disabled (AgDR-0015 distinct from public-route 404)
 *   - 403 on insufficient capability (`edit_post` gate)
 *   - 404 on non-existent post
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Markdown_Views;

use WP_REST_Request;
use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Markdown_Views\Rest_Controller;
use WPContext\Markdown_Views\Schema;
use WPContext\Markdown_Views\Service;

final class Rest_Controller_Test extends WP_UnitTestCase {

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

		// The REST controller is wired from Main::register_hooks() in
		// production. Ensure the route is registered before each test
		// regardless of whether Main loaded.
		Rest_Controller::register_routes();
	}

	protected function tearDown(): void {
		Schema::drop();
		remove_all_filters( 'agentready_post_is_noindexed' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return (int) $user_id;
	}

	private function subscriber_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		return (int) $user_id;
	}

	private function dispatch( int $post_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE . Rest_Controller::ROUTE );
		$request->set_param( 'post', $post_id );
		return rest_get_server()->dispatch( $request );
	}

	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey(
			'/' . Rest_Controller::NAMESPACE . Rest_Controller::ROUTE,
			$routes
		);
	}

	public function test_exposable_post_returns_markdown_and_cache_state(): void {
		$this->admin_user();

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<h2>Heading</h2><p>Body</p>',
				'post_status'  => 'publish',
			)
		);

		$response = $this->dispatch( $post_id );

		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'markdown', $data );
		self::assertArrayHasKey( 'visibility', $data );
		self::assertArrayHasKey( 'cache_state', $data );

		self::assertStringContainsString( '## Heading', $data['markdown'] );
		self::assertSame( 'exposable', $data['visibility']['verdict'] );
		self::assertNull( $data['visibility']['reason'] );

		self::assertIsArray( $data['cache_state'] );
		self::assertTrue( $data['cache_state']['cached'] );
		self::assertNotEmpty( $data['cache_state']['content_hash'] );
		self::assertNotEmpty( $data['cache_state']['walker_version'] );
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$data['cache_state']['generated_at'],
			'generated_at must be ISO-8601 UTC'
		);
	}

	public function test_draft_post_returns_not_exposable_with_status_reason(): void {
		$this->admin_user();

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p>Draft body.</p>',
				'post_status'  => 'draft',
			)
		);

		$response = $this->dispatch( $post_id );

		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertSame( 'not_exposable', $data['visibility']['verdict'] );
		self::assertSame( 'status', $data['visibility']['reason'] );
		self::assertSame( '', $data['markdown'] );
		self::assertNull( $data['cache_state'] );
	}

	public function test_password_protected_post_returns_password_reason(): void {
		$this->admin_user();

		$post_id = self::factory()->post->create(
			array(
				'post_content'  => '<p>Secret.</p>',
				'post_password' => 'sekret',
				'post_status'   => 'publish',
			)
		);

		$response = $this->dispatch( $post_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'password', $response->get_data()['visibility']['reason'] );
	}

	public function test_unexposed_cpt_returns_cpt_reason(): void {
		$this->admin_user();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array( 'exposed_cpts' => array() )
			)
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->dispatch( $post_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'cpt', $response->get_data()['visibility']['reason'] );
	}

	public function test_noindex_filter_returns_noindex_reason(): void {
		$this->admin_user();

		add_filter( 'agentready_post_is_noindexed', '__return_true' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->dispatch( $post_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'noindex', $response->get_data()['visibility']['reason'] );
	}

	public function test_module_disabled_returns_403_module_disabled(): void {
		$this->admin_user();

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

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->dispatch( $post_id );

		self::assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame( Service::ERROR_MODULE_DISABLED, $data['code'] );
	}

	public function test_subscriber_cannot_preview(): void {
		$this->subscriber_user();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->dispatch( $post_id );

		// REST returns the rest_authorization_required_code, typically 401 (no auth)
		// or 403 (insufficient). Either way it should NOT be 200.
		self::assertNotSame( 200, $response->get_status() );
		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_nonexistent_post_returns_404(): void {
		$this->admin_user();

		$response = $this->dispatch( 99999 );

		self::assertSame( 404, $response->get_status() );
	}

	public function test_missing_post_param_fails_validation(): void {
		$this->admin_user();

		$request = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE . Rest_Controller::ROUTE );
		// No `post` param.
		$response = rest_get_server()->dispatch( $request );

		self::assertNotSame( 200, $response->get_status() );
	}
}
