<?php
/**
 * Unit tests for the pure post-processing surface of
 * `Mokhai\LlmsTxt\Description_Orchestrator::normalise_output`.
 *
 * Pins every line of the AgDR-0028 normalisation pipeline — preamble
 * stripping, whitespace collapse, EMPTY sentinel, truncation — without
 * requiring an LLM call or WP runtime. State-machine + scheduling
 * coverage lives in the integration suite, where the real post-meta
 * layer and cron queue are exercised.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\LlmsTxt;

use PHPUnit\Framework\TestCase;
use Mokhai\LlmsTxt\Description_Orchestrator;

final class Description_Orchestrator_Test extends TestCase {

	public function test_passes_through_a_clean_sentence(): void {
		$out = Description_Orchestrator::normalise_output( 'Documentation for the export API endpoints.' );
		self::assertSame( 'Documentation for the export API endpoints.', $out );
	}

	public function test_trims_leading_and_trailing_whitespace(): void {
		$out = Description_Orchestrator::normalise_output( "  A sentence.\n\n" );
		self::assertSame( 'A sentence.', $out );
	}

	public function test_collapses_internal_whitespace(): void {
		$out = Description_Orchestrator::normalise_output( "A sentence    with\textra\n\nspaces." );
		self::assertSame( 'A sentence with extra spaces.', $out );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public function preamble_provider(): iterable {
		yield 'description colon prefix' => array( 'Description: A blog post about caching.', 'A blog post about caching.' );
		yield 'summary colon prefix'      => array( 'Summary: A blog post about caching.', 'A blog post about caching.' );
		yield 'this page is about'        => array( 'This page is about caching strategies.', 'caching strategies.' );
		yield 'this page describes'       => array( 'This page describes caching strategies.', 'caching strategies.' );
		yield 'here is a description of'  => array( 'Here is a description of caching strategies.', 'caching strategies.' );
		yield 'here is'                   => array( 'Here is the documentation index.', 'the documentation index.' );
		yield 'this page'                 => array( 'This page lists the docs.', 'lists the docs.' );
		yield 'case insensitive'          => array( 'DESCRIPTION: A blog post.', 'A blog post.' );
		yield 'stacked preambles'         => array( 'Summary: This page is about caching.', 'caching.' );
	}

	/**
	 * @dataProvider preamble_provider
	 */
	public function test_strips_preamble( string $raw, string $expected ): void {
		self::assertSame( $expected, Description_Orchestrator::normalise_output( $raw ) );
	}

	public function test_strips_html_tags(): void {
		$out = Description_Orchestrator::normalise_output( '<p>A <strong>bold</strong> sentence.</p>' );
		self::assertSame( 'A bold sentence.', $out );
	}

	public function test_returns_null_on_empty_input(): void {
		self::assertNull( Description_Orchestrator::normalise_output( '' ) );
	}

	public function test_returns_null_on_whitespace_only_input(): void {
		self::assertNull( Description_Orchestrator::normalise_output( "   \n\t  " ) );
	}

	public function test_returns_null_on_empty_sentinel(): void {
		self::assertNull( Description_Orchestrator::normalise_output( 'EMPTY' ) );
	}

	public function test_returns_null_on_lowercase_empty_sentinel(): void {
		self::assertNull( Description_Orchestrator::normalise_output( 'empty' ) );
	}

	public function test_truncates_overlong_output_with_ellipsis(): void {
		// A 200-char string: 4 × 50.
		$raw = str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ____', 4 );
		self::assertSame( 200, strlen( $raw ) );

		$out = Description_Orchestrator::normalise_output( $raw );

		self::assertNotNull( $out );
		self::assertSame( 160, strlen( $out ) );
		self::assertSame( '...', substr( $out, -3 ), 'truncation should end with the ellipsis' );
	}

	public function test_does_not_truncate_at_or_below_cap(): void {
		$raw = str_repeat( 'x', 160 );
		$out = Description_Orchestrator::normalise_output( $raw );

		self::assertNotNull( $out );
		self::assertSame( 160, strlen( $out ) );
		self::assertSame( $raw, $out, 'no ellipsis appended at exactly cap length' );
	}

	public function test_preamble_strip_does_not_break_short_inputs(): void {
		// Edge: input is shorter than the longest preamble pattern.
		$out = Description_Orchestrator::normalise_output( 'X.' );
		self::assertSame( 'X.', $out );
	}

	public function test_meta_key_constants_match_namespace_prefix(): void {
		// Regression guard: the four meta keys must share the
		// `_mokhai_llms_description_` prefix so an operator searching
		// post-meta finds the whole cluster.
		$keys = array(
			Description_Orchestrator::META_KEY_AUTO,
			Description_Orchestrator::META_KEY_MANUAL,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			Description_Orchestrator::META_KEY_STATUS,
			Description_Orchestrator::META_KEY_DIAGNOSTICS,
		);

		foreach ( $keys as $key ) {
			self::assertStringStartsWith( '_mokhai_llms_description_', $key );
		}
	}
}
