<?php
/**
 * Integration tests for the rendered-HTML loopback source (#297 / AgDR-0069).
 *
 * Runs inside wp-phpunit so we exercise the real transient API, the real
 * `get_permalink()` / `home_url()` host resolution, and the real HTTP API — the
 * loopback `wp_remote_get` is short-circuited via the `pre_http_request` filter
 * so no network I/O occurs. Verifies the guard chain that the design calls
 * blocking:
 *
 *  - last-resort: no-op (and no fetch) when the body is already non-empty
 *  - success: HTTP 200 → main-content region extracted and substituted
 *  - B2: a non-200 response (500 error page / 401 auth wall) is REFUSED, never
 *    extracted or cached as the twin
 *  - B2: a transport WP_Error is refused
 *  - B1: an in-request self-fetch marker no-ops (secondary guard)
 *  - B1: a held per-post render lock no-ops (load-bearing cross-process guard)
 *  - B1: the lock is released on completion, not left to expire
 *  - config: the `mokhai_markdown_loopback_enabled` filter disables the fetch
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use WP_Error;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Rendered_Html_Source;
use Mokhai\Markdown_Views\Schema;

final class Rendered_Html_Source_Test extends WP_UnitTestCase {

	/**
	 * Count of loopback HTTP attempts intercepted this test.
	 *
	 * @var int
	 */
	private static $fetch_count = 0;

	/**
	 * Canned response the intercept returns; a WP_Error or a response array.
	 *
	 * @var mixed
	 */
	private static $canned_response = null;

	private int $page_id = 0;

	protected function setUp(): void {
		parent::setUp();

		self::$fetch_count     = 0;
		self::$canned_response = null;

		// The cache-invalidation hooks (save_post → Service::invalidate) fire when
		// the factory creates the page below, issuing a DELETE against the cache
		// table — create it so that DELETE succeeds silently.
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

		$this->page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Loopback Target',
				'post_content' => '',
			)
		);

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		remove_all_filters( 'mokhai_markdown_loopback_enabled' );
		unset( $_GET[ Rendered_Html_Source::MARKER ] );
		delete_transient( 'mokhai_render_lock_' . $this->page_id );
		Schema::drop();
		parent::tearDown();
	}

	/**
	 * Short-circuit every outbound HTTP request with the canned response.
	 *
	 * @param mixed  $pre  Filter default (false = let WP proceed).
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 *
	 * @return mixed
	 */
	public function intercept_http( $pre, $args, $url ) {
		self::$fetch_count++;
		return self::$canned_response;
	}

	private function ok_response( string $body ): array {
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	private function status_response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => 'X' ),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	private function post(): \WP_Post {
		return get_post( $this->page_id );
	}

	// --- last resort ------------------------------------------------------

	public function test_noop_and_no_fetch_when_body_already_present(): void {
		self::$canned_response = $this->ok_response( '<main><p>should not be used</p></main>' );

		$out = Rendered_Html_Source::append_rendered_html( '<p>existing body</p>', $this->post() );

		self::assertSame( '<p>existing body</p>', $out );
		self::assertSame( 0, self::$fetch_count, 'must not fetch when a body already exists' );
	}

	// --- success ----------------------------------------------------------

	public function test_extracts_main_region_on_200(): void {
		self::$canned_response = $this->ok_response(
			'<html><body><nav>Menu</nav><main><p>Recovered rendered content.</p></main></body></html>'
		);

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertStringContainsString( 'Recovered rendered content.', $out );
		self::assertStringNotContainsString( 'Menu', $out );
		self::assertSame( 1, self::$fetch_count );
	}

	// --- B2: non-200 refused ---------------------------------------------

	public function test_refuses_500_error_page(): void {
		self::$canned_response = $this->status_response( 500, '<html><body><main><p>Fatal error stack trace</p></main></body></html>' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out, 'a 500 error page must never be extracted as the twin' );
	}

	public function test_refuses_401_auth_wall(): void {
		self::$canned_response = $this->status_response( 401, '<html><body><main><p>Please log in</p></main></body></html>' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out );
	}

	public function test_refuses_transport_error(): void {
		self::$canned_response = new WP_Error( 'http_request_failed', 'could not resolve host' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out );
	}

	// --- B1: recursion guards --------------------------------------------

	public function test_self_fetch_marker_noops(): void {
		$_GET[ Rendered_Html_Source::MARKER ] = '1';
		self::$canned_response                = $this->ok_response( '<main><p>nested</p></main>' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out );
		self::assertSame( 0, self::$fetch_count, 'a self-fetch must never trigger another fetch' );
	}

	public function test_held_lock_noops(): void {
		set_transient( 'mokhai_render_lock_' . $this->page_id, 1, 30 );
		self::$canned_response = $this->ok_response( '<main><p>content</p></main>' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out );
		self::assertSame( 0, self::$fetch_count, 'a held render lock must block the fetch' );
	}

	public function test_lock_released_after_run(): void {
		self::$canned_response = $this->ok_response( '<main><p>Recovered.</p></main>' );

		Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertFalse(
			get_transient( 'mokhai_render_lock_' . $this->page_id ),
			'the render lock must be released on completion, not left to expire'
		);
	}

	// --- config -----------------------------------------------------------

	public function test_disabled_via_filter_noops(): void {
		add_filter( 'mokhai_markdown_loopback_enabled', '__return_false' );
		self::$canned_response = $this->ok_response( '<main><p>content</p></main>' );

		$out = Rendered_Html_Source::append_rendered_html( '', $this->post() );

		self::assertSame( '', $out );
		self::assertSame( 0, self::$fetch_count );
	}
}
