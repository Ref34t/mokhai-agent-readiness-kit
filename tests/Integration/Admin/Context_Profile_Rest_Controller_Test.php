<?php
/**
 * Integration tests for the Context Profile REST controller (#142 / AgDR-0048).
 *
 * Exercises the real route registration, the `manage_options` gate, and the
 * read/write behaviour against a live WP test instance:
 *
 *   - GET + PUT registered under `ai-readiness-kit/v1/context-profile`
 *   - 403 for a non-admin on both methods
 *   - GET returns the migrated profile
 *   - PUT persists the whole profile through the `sanitize_internal()`
 *     whitelist (drops bogus CPT/status + unknown keys) and returns it
 *   - PUT fires the same `agentready_context_profile_saved` cascade the
 *     options.php form path fires (parity guarantee)
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Admin;

use WP_REST_Request;
use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Rest_Controller;
use WPContext\Admin\Context_Profile_Settings;

final class Context_Profile_Rest_Controller_Test extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( Context_Profile_Settings::OPTION_KEY );
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
		return '/' . Context_Profile_Rest_Controller::NAMESPACE . Context_Profile_Rest_Controller::ROUTE_BASE;
	}

	private function put_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'PUT', $this->base() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	public function test_get_and_put_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey( $this->base(), $routes );

		$methods = array();
		foreach ( $routes[ $this->base() ] as $handler ) {
			$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
		}
		self::assertContains( 'GET', $methods );
		self::assertContains( 'PUT', $methods );
	}

	public function test_non_admin_is_forbidden_on_both_methods(): void {
		$this->subscriber_user();

		$get = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $this->base() ) );
		self::assertSame( 403, $get->get_status() );

		$put = rest_get_server()->dispatch( $this->put_request( array( 'exposed_cpts' => array( 'post' ) ) ) );
		self::assertSame( 403, $put->get_status() );
	}

	public function test_get_returns_migrated_profile(): void {
		$this->admin_user();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $this->base() ) );
		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertArrayHasKey( 'profile', $data );
		self::assertArrayHasKey( 'exposed_cpts', $data['profile'] );
		self::assertArrayHasKey( 'schema_version', $data['profile'] );
	}

	public function test_put_persists_and_applies_whitelist(): void {
		$this->admin_user();

		$response = rest_get_server()->dispatch(
			$this->put_request(
				array(
					'schema_version'           => 1,
					'exposed_cpts'             => array( 'post', 'bogus-cpt' ),
					'exposed_statuses'         => array( 'publish', 'not-a-status' ),
					'schema_emit_enabled'      => true,
					'llm_descriptions_enabled' => true,
					'evil_unknown_key'         => 'dropped',
				)
			)
		);

		self::assertSame( 200, $response->get_status() );
		$profile = $response->get_data()['profile'];

		// Valid values kept; bogus CPT + invalid status dropped by the whitelist.
		self::assertContains( 'post', $profile['exposed_cpts'] );
		self::assertNotContains( 'bogus-cpt', $profile['exposed_cpts'] );
		self::assertContains( 'publish', $profile['exposed_statuses'] );
		self::assertNotContains( 'not-a-status', $profile['exposed_statuses'] );
		self::assertTrue( $profile['schema_emit_enabled'] );
		self::assertTrue( $profile['llm_descriptions_enabled'] );
		// Unknown keys are never copied into the stored shape.
		self::assertArrayNotHasKey( 'evil_unknown_key', $profile );

		// Persisted — a fresh read reflects the write (parity with the form path).
		$stored = Context_Profile_Settings::get_profile();
		self::assertContains( 'post', $stored['exposed_cpts'] );
		self::assertTrue( $stored['schema_emit_enabled'] );
	}

	public function test_put_fires_the_saved_cascade(): void {
		$this->admin_user();

		$fired = 0;
		add_action(
			'agentready_context_profile_saved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$response = rest_get_server()->dispatch(
			$this->put_request(
				array(
					'exposed_cpts'     => array( 'page' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertGreaterThan( 0, $fired, 'PUT must fire agentready_context_profile_saved like the form save.' );
	}
}
