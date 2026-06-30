<?php
/**
 * Integration tests for the REST seam of the output-buffer hygiene helper (#175).
 *
 * Exercises `clean_before_rest_serve()` — the `rest_pre_serve_request` filter
 * callback — with a real `WP_REST_Request`, which needs WordPress loaded (hence
 * the integration suite). The scoping predicate (`is_plugin_rest_route`) and the
 * discard logic (`discard_pending`) are covered directly by the unit suite; here
 * we verify the callback composes them safely and never alters `$served`.
 *
 * The `dispatch()` paths for `/llms.txt` and the `.md` views are not exercised
 * (they end in `exit`, like `Router_Test` skips) — their hardening is two calls
 * into `Output_Buffer`, whose logic the unit suite covers.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Support;

use WP_REST_Request;
use WP_UnitTestCase;
use Mokhai\Support\Output_Buffer;

final class Output_Buffer_Test extends WP_UnitTestCase {

	public function test_returns_served_unchanged_for_plugin_route(): void {
		$request = new WP_REST_Request( 'POST', '/mokhai/v1/context-profile' );

		$this->assertFalse( Output_Buffer::clean_before_rest_serve( false, null, $request ) );
		$this->assertTrue( Output_Buffer::clean_before_rest_serve( true, null, $request ) );
	}

	public function test_leaves_foreign_route_buffer_untouched(): void {
		$baseline = ob_get_level();

		ob_start();
		echo 'other-plugin output';

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$served  = Output_Buffer::clean_before_rest_serve( true, null, $request );

		$level    = ob_get_level();
		$contents = ob_get_clean(); // tidy up our own seeded buffer

		while ( ob_get_level() < $baseline ) {
			ob_start();
		}

		// A foreign route is never cleaned, so our seeded buffer is intact.
		$this->assertSame( $baseline + 1, $level );
		$this->assertSame( 'other-plugin output', $contents );
		$this->assertTrue( $served );
	}

	public function test_returns_served_unchanged_for_non_rest_request_arg(): void {
		// Defensive: a non-WP_REST_Request value must not throw.
		$this->assertTrue( Output_Buffer::clean_before_rest_serve( true, null, null ) );
	}
}
