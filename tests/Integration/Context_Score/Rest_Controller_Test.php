<?php
/**
 * Integration tests for the Context Score REST controller (#10 / AgDR-0031).
 *
 * Runs inside wp-phpunit so we exercise the real REST infrastructure,
 * real capability checks, and the real `wp_options` cache that the
 * Service writes to.
 *
 * Covers:
 *   GET  /agentready/v1/context-score
 *   POST /agentready/v1/context-score/recompute
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Context_Score;

use WP_REST_Request;
use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Context_Score\Rest_Controller;
use WPContext\Context_Score\Service;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Rest_Controller_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Signal_Collector reads from the Markdown Views cache table.
		Markdown_Views_Schema::create();

		Service::invalidate();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		wp_clear_scheduled_hook( Service::RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		wp_clear_scheduled_hook( Service::RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );
		wp_set_current_user( 0 );

		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	private function make_admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function make_subscriber(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function path( string $suffix = '' ): string {
		return '/' . Rest_Controller::NAMESPACE . Rest_Controller::ROUTE_BASE . $suffix;
	}

	public function test_get_rejects_anonymous(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_get_rejects_subscriber(): void {
		wp_set_current_user( $this->make_subscriber() );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_recompute_rejects_subscriber(): void {
		wp_set_current_user( $this->make_subscriber() );

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path( '/recompute' ) ) );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_get_returns_breakdown_for_admin(): void {
		wp_set_current_user( $this->make_admin() );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'overall', $data );
		self::assertArrayHasKey( 'sub_scores', $data );
		self::assertArrayHasKey( 'computed_at', $data );
		self::assertArrayHasKey( 'schema_version', $data );
		self::assertIsInt( $data['overall'] );
		self::assertGreaterThanOrEqual( 0, $data['overall'] );
		self::assertLessThanOrEqual( 100, $data['overall'] );
	}

	public function test_get_populates_cache_on_first_call(): void {
		wp_set_current_user( $this->make_admin() );
		self::assertNull( Service::get_breakdown() );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertNotNull( Service::get_breakdown() );
	}

	public function test_get_serves_cached_payload_without_recomputing(): void {
		wp_set_current_user( $this->make_admin() );

		// Seed the cache with a recognisable value.
		Service::recompute_now();
		$cached = Service::get_breakdown();
		self::assertIsArray( $cached );

		$response = rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// computed_at matches the cached payload — proves the GET handler
		// did not call recompute_now() (which would advance computed_at).
		self::assertSame( $cached['computed_at'], $data['computed_at'] );
		self::assertSame( $cached['overall'], $data['overall'] );
	}

	public function test_recompute_writes_fresh_payload(): void {
		wp_set_current_user( $this->make_admin() );

		// Seed an old payload, then assert recompute replaces it.
		Service::recompute_now();
		$before = Service::get_breakdown();
		self::assertIsArray( $before );

		// gmdate('c') has 1-second resolution; sleep 1s so the comparison
		// can distinguish the two timestamps deterministically.
		sleep( 1 );

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path( '/recompute' ) ) );

		self::assertSame( 200, $response->get_status() );
		$after = $response->get_data();
		self::assertIsArray( $after );
		self::assertGreaterThanOrEqual( $before['computed_at'], $after['computed_at'] );
		self::assertNotSame( $before['computed_at'], $after['computed_at'] );
	}

	public function test_recompute_response_matches_persisted_cache(): void {
		wp_set_current_user( $this->make_admin() );

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path( '/recompute' ) ) );
		$body     = $response->get_data();
		$stored   = Service::get_breakdown();

		self::assertSame( $body['overall'], $stored['overall'] );
		self::assertSame( $body['computed_at'], $stored['computed_at'] );
		self::assertSame( $body['sub_scores'], $stored['sub_scores'] );
	}

	public function test_response_shape_includes_recompute_duration_ms(): void {
		wp_set_current_user( $this->make_admin() );

		$response = rest_do_request( new WP_REST_Request( 'POST', $this->path( '/recompute' ) ) );
		$data     = $response->get_data();

		self::assertArrayHasKey( 'recompute_duration_ms', $data );
		self::assertIsInt( $data['recompute_duration_ms'] );
		self::assertGreaterThanOrEqual( 0, $data['recompute_duration_ms'] );
	}
}
