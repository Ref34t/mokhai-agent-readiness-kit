<?php
/**
 * Integration tests for the LLMs Index editorial REST controller
 * (#142 / AgDR-0048).
 *
 *   - GET + PUT registered under `ai-readiness-kit/v1/llms-txt/editorial`
 *   - 403 for a non-admin on both methods
 *   - GET returns the versioned settings shape + the section vocabulary
 *   - PUT persists entries through the `sanitize()` source-of-truth (drops an
 *     entry missing a title/URL) and returns the saved value + sections
 *   - PUT fires the same `agentready_llms_txt_editorial_saved` cascade the
 *     options.php form path fires (parity guarantee)
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_REST_Request;
use WP_UnitTestCase;
use WPContext\LlmsTxt\Editorial_Rest_Controller;
use WPContext\LlmsTxt\Editorial_Settings;

final class Editorial_Rest_Controller_Test extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( Editorial_Settings::OPTION_KEY );
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
		return '/' . Editorial_Rest_Controller::NAMESPACE . Editorial_Rest_Controller::ROUTE_BASE;
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

		$put = rest_get_server()->dispatch(
			$this->put_request( array( 'entries' => array() ) )
		);
		self::assertSame( 403, $put->get_status() );
	}

	public function test_get_returns_settings_and_sections(): void {
		$this->admin_user();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $this->base() ) );
		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertArrayHasKey( 'entries', $data );
		self::assertArrayHasKey( 'sections', $data );
		self::assertSame( Editorial_Settings::SECTIONS, $data['sections'] );
	}

	public function test_put_persists_valid_entries_and_drops_incomplete(): void {
		$this->admin_user();

		$response = rest_get_server()->dispatch(
			$this->put_request(
				array(
					'entries' => array(
						array(
							'title'   => 'Docs',
							'url'     => 'https://example.com/docs',
							'section' => 'Resources',
						),
						// Missing URL — must be dropped by sanitize().
						array(
							'title' => 'Orphan',
							'url'   => '',
						),
					),
				)
			)
		);

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		self::assertCount( 1, $data['entries'] );
		self::assertSame( 'Docs', $data['entries'][0]['title'] );
		self::assertSame( 'https://example.com/docs', $data['entries'][0]['url'] );

		// Persisted — a fresh read reflects the write.
		$stored = Editorial_Settings::get_settings();
		self::assertCount( 1, $stored['entries'] );
	}

	public function test_put_fires_the_saved_cascade(): void {
		$this->admin_user();

		$fired = 0;
		add_action(
			Editorial_Settings::SAVED_ACTION,
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$response = rest_get_server()->dispatch(
			$this->put_request(
				array(
					'entries' => array(
						array(
							'title' => 'One',
							'url'   => 'https://example.com/one',
						),
					),
				)
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertGreaterThan( 0, $fired, 'PUT must fire the editorial saved action like the form save.' );
	}
}
