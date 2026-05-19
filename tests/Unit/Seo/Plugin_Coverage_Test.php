<?php
/**
 * Unit tests for WPContext\Seo\Plugin_Coverage.
 *
 * Covers:
 *   - Default coverage matrix shape (Yoast / Rank Math / AIOSEO all cover the baseline)
 *   - Default baseline is the four canonical types
 *   - compute_gap returns the full baseline when posture is `none` / unknown / empty
 *   - compute_gap returns an empty list when posture is fully covered
 *   - compute_deferred mirrors compute_gap (intersection vs complement)
 *   - Filter overrides on the matrix and baseline are honoured
 *   - Filter outputs of the wrong shape fall back to defaults / get sanitised
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use WPContext\Seo\Plugin_Coverage;

final class Plugin_Coverage_Test extends TestCase {

	protected function setUp(): void {
		// Reset the filter registry between tests so a previous test's
		// override doesn't leak into the next assertion.
		$GLOBALS['wpctx_test_filters'] = array();
	}

	public function test_default_baseline_types(): void {
		$baseline = Plugin_Coverage::baseline_types();

		self::assertSame(
			array( 'WebSite', 'Organization', 'WebPage', 'Article' ),
			$baseline
		);
	}

	public function test_default_coverage_matrix_covers_all_supported_plugins(): void {
		$matrix = Plugin_Coverage::coverage_matrix();

		self::assertArrayHasKey( 'yoast', $matrix );
		self::assertArrayHasKey( 'rank_math', $matrix );
		self::assertArrayHasKey( 'aioseo', $matrix );

		foreach ( array( 'yoast', 'rank_math', 'aioseo' ) as $slug ) {
			foreach ( Plugin_Coverage::baseline_types() as $type ) {
				self::assertContains(
					$type,
					$matrix[ $slug ],
					"Plugin {$slug} should cover baseline type {$type}"
				);
			}
		}
	}

	public function test_compute_gap_returns_full_baseline_when_posture_is_none(): void {
		self::assertSame(
			Plugin_Coverage::baseline_types(),
			Plugin_Coverage::compute_gap( 'none' )
		);
	}

	public function test_compute_gap_returns_full_baseline_when_posture_is_empty_string(): void {
		self::assertSame(
			Plugin_Coverage::baseline_types(),
			Plugin_Coverage::compute_gap( '' )
		);
	}

	public function test_compute_gap_returns_empty_when_yoast_active(): void {
		self::assertSame( array(), Plugin_Coverage::compute_gap( 'yoast' ) );
	}

	public function test_compute_gap_returns_empty_when_rank_math_active(): void {
		self::assertSame( array(), Plugin_Coverage::compute_gap( 'rank_math' ) );
	}

	public function test_compute_gap_returns_empty_when_aioseo_active(): void {
		self::assertSame( array(), Plugin_Coverage::compute_gap( 'aioseo' ) );
	}

	public function test_compute_gap_returns_full_baseline_for_unknown_posture(): void {
		self::assertSame(
			Plugin_Coverage::baseline_types(),
			Plugin_Coverage::compute_gap( 'some_unknown_seo_plugin' )
		);
	}

	public function test_compute_deferred_is_empty_when_posture_is_none(): void {
		self::assertSame( array(), Plugin_Coverage::compute_deferred( 'none' ) );
		self::assertSame( array(), Plugin_Coverage::compute_deferred( '' ) );
	}

	public function test_compute_deferred_lists_baseline_when_posture_fully_covers(): void {
		self::assertSame(
			Plugin_Coverage::baseline_types(),
			Plugin_Coverage::compute_deferred( 'yoast' )
		);
	}

	public function test_matrix_filter_can_remove_a_covered_type(): void {
		// Simulate a Yoast site that has Organization disabled — Plugin_Coverage
		// should now report Organization as a gap to fill.
		\add_filter(
			Plugin_Coverage::FILTER_COVERAGE_MATRIX,
			static function ( array $matrix ): array {
				$matrix['yoast'] = array( 'WebSite', 'WebPage', 'Article', 'BreadcrumbList' );
				return $matrix;
			}
		);

		self::assertSame( array( 'Organization' ), Plugin_Coverage::compute_gap( 'yoast' ) );
		self::assertSame(
			array( 'WebSite', 'WebPage', 'Article' ),
			Plugin_Coverage::compute_deferred( 'yoast' )
		);
	}

	public function test_baseline_filter_can_add_a_new_type(): void {
		\add_filter(
			Plugin_Coverage::FILTER_BASELINE_TYPES,
			static function (): array {
				return array( 'WebSite', 'Organization', 'WebPage', 'Article', 'FAQPage' );
			}
		);

		// All three supported plugins cover the original four; FAQPage is
		// not in their coverage so it falls into the gap.
		self::assertSame( array( 'FAQPage' ), Plugin_Coverage::compute_gap( 'yoast' ) );
		self::assertSame( array( 'FAQPage' ), Plugin_Coverage::compute_gap( 'rank_math' ) );
		self::assertSame( array( 'FAQPage' ), Plugin_Coverage::compute_gap( 'aioseo' ) );
	}

	public function test_garbage_matrix_filter_value_falls_back_to_defaults(): void {
		\add_filter(
			Plugin_Coverage::FILTER_COVERAGE_MATRIX,
			static function () {
				return 'not-an-array';
			}
		);

		$matrix = Plugin_Coverage::coverage_matrix();

		self::assertArrayHasKey( 'yoast', $matrix );
		self::assertContains( 'WebSite', $matrix['yoast'] );
	}

	public function test_matrix_filter_sanitises_non_string_type_entries(): void {
		\add_filter(
			Plugin_Coverage::FILTER_COVERAGE_MATRIX,
			static function (): array {
				return array(
					'yoast' => array( 'WebSite', 123, '', 'Article', null ),
					42      => array( 'ShouldBeDropped' ),
				);
			}
		);

		$matrix = Plugin_Coverage::coverage_matrix();

		self::assertSame( array( 'WebSite', 'Article' ), $matrix['yoast'] );
		self::assertArrayNotHasKey( 42, $matrix );
	}

	public function test_baseline_filter_deduplicates(): void {
		\add_filter(
			Plugin_Coverage::FILTER_BASELINE_TYPES,
			static function (): array {
				return array( 'WebSite', 'WebSite', 'Organization' );
			}
		);

		self::assertSame(
			array( 'WebSite', 'Organization' ),
			Plugin_Coverage::baseline_types()
		);
	}
}
