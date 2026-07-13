<?php
/**
 * Unit tests for the hard-cache header judge (#283 / AgDR-0067).
 *
 * `evaluate_probe_headers()` is pure — the loopback transport and the
 * snapshot option are integration concerns; here we pin the header → verdict
 * contract, especially the HIT-family requirement that keeps a merely
 * PROXYING Cloudflare (cf-cache-status: DYNAMIC) from activating the mirror.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use Mokhai\Markdown_Views\Cache_Posture;

final class Cache_Posture_Test extends TestCase {

	public function test_no_headers_is_no_cache(): void {
		self::assertNull( Cache_Posture::evaluate_probe_headers( array() ) );
	}

	public function test_plain_origin_headers_are_no_cache(): void {
		self::assertNull(
			Cache_Posture::evaluate_probe_headers(
				array(
					'content-type' => 'text/html; charset=UTF-8',
					'server'       => 'nginx',
				)
			)
		);
	}

	public function test_kinsta_header_detected_regardless_of_value(): void {
		// Kinsta emits the header on HIT and MISS alike — presence means the
		// host cache layer exists, and a MISS today is a HIT on the next hit.
		self::assertSame(
			'Kinsta',
			Cache_Posture::evaluate_probe_headers( array( 'X-Kinsta-Cache' => 'MISS' ) )
		);
	}

	public function test_cloudflare_hit_detected(): void {
		self::assertSame(
			'Cloudflare',
			Cache_Posture::evaluate_probe_headers( array( 'CF-Cache-Status' => 'HIT' ) )
		);
	}

	public function test_cloudflare_dynamic_is_not_a_page_cache(): void {
		// DYNAMIC = Cloudflare proxies but does NOT cache the HTML. The
		// mirror must not activate for a plain orange-cloud site.
		self::assertNull(
			Cache_Posture::evaluate_probe_headers( array( 'CF-Cache-Status' => 'DYNAMIC' ) )
		);
	}

	public function test_varnish_header_detected(): void {
		self::assertSame(
			'Varnish',
			Cache_Posture::evaluate_probe_headers( array( 'X-Varnish' => '123456 654321' ) )
		);
	}

	public function test_generic_x_cache_hit_detected(): void {
		self::assertSame(
			'proxy cache (x-cache)',
			Cache_Posture::evaluate_probe_headers( array( 'X-Cache' => 'HIT from cloudfront' ) )
		);
	}

	public function test_generic_x_cache_miss_is_not_enough(): void {
		// A MISS from an unknown proxy proves a proxy exists, not that it
		// caches HTML — plenty of setups run x-cache: MISS forever on HTML.
		self::assertNull(
			Cache_Posture::evaluate_probe_headers( array( 'X-Cache' => 'MISS from cloudfront' ) )
		);
	}

	public function test_positive_age_header_detected(): void {
		self::assertSame(
			'shared cache (Age header)',
			Cache_Posture::evaluate_probe_headers( array( 'Age' => '137' ) )
		);
	}

	public function test_zero_age_header_is_not_a_cache_hit(): void {
		self::assertNull(
			Cache_Posture::evaluate_probe_headers( array( 'Age' => '0' ) )
		);
	}

	public function test_header_names_and_values_are_case_insensitive(): void {
		self::assertSame(
			'LiteSpeed server',
			Cache_Posture::evaluate_probe_headers( array( 'X-LITESPEED-CACHE' => 'Hit' ) )
		);
	}
}
