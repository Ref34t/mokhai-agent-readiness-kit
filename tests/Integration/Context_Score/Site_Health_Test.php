<?php
/**
 * Integration tests for the Context Score Site Health integration
 * (#10 / AgDR-0031).
 *
 * Exercises the `site_status_tests` filter contract and the direct
 * test callback's branches (null cache, good/recommended/critical
 * buckets) using the real `Service::get_breakdown()` path.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Context_Score;

use WP_UnitTestCase;
use Mokhai\Context_Score\Service;
use Mokhai\Context_Score\Site_Health;

final class Site_Health_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Service::invalidate();
	}

	protected function tearDown(): void {
		Service::invalidate();
		parent::tearDown();
	}

	public function test_filter_registers_direct_test(): void {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		self::assertIsArray( $tests );
		self::assertArrayHasKey( 'direct', $tests );
		self::assertArrayHasKey( Site_Health::TEST_ID, $tests['direct'] );
		self::assertArrayHasKey( 'label', $tests['direct'][ Site_Health::TEST_ID ] );
		self::assertSame(
			array( Site_Health::class, 'run_test' ),
			$tests['direct'][ Site_Health::TEST_ID ]['test']
		);
	}

	public function test_filter_initialises_direct_key_when_missing(): void {
		// WP core's filter input always has 'direct' + 'async', but a
		// third-party can pass nonsense; the controller should tolerate
		// it rather than warn.
		$tests = apply_filters( 'site_status_tests', array() );

		self::assertIsArray( $tests );
		self::assertArrayHasKey( 'direct', $tests );
		self::assertArrayHasKey( Site_Health::TEST_ID, $tests['direct'] );
	}

	public function test_run_test_returns_recommended_when_no_cache(): void {
		self::assertNull( Service::get_breakdown() );

		$result = Site_Health::run_test();

		self::assertIsArray( $result );
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( Site_Health::TEST_ID, $result['test'] );
		self::assertArrayHasKey( 'badge', $result );
		self::assertArrayHasKey( 'description', $result );
		self::assertArrayHasKey( 'actions', $result );
		self::assertStringContainsString( 'tools.php', $result['actions'] );
	}

	public function test_run_test_returns_good_for_score_above_threshold(): void {
		$this->seed_cache(
			95,
			array(
				'discoverability'       => 100,
				'content_readability'   => 100,
				'schema_coverage'       => 100,
				'exposure_safety'       => 100,
				'integration_health'    => 100,
				'md_conversion_quality' => 100,
			)
		);

		$result = Site_Health::run_test();

		self::assertSame( 'good', $result['status'] );
		self::assertSame( 'green', $result['badge']['color'] );
		self::assertStringContainsString( '95', $result['label'] );
	}

	public function test_run_test_returns_recommended_for_mid_score(): void {
		$this->seed_cache(
			65,
			array(
				'discoverability'       => 60,
				'content_readability'   => 70,
				'schema_coverage'       => 80,
				'exposure_safety'       => 100,
				'integration_health'    => 50,
				'md_conversion_quality' => 60,
			)
		);

		$result = Site_Health::run_test();

		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( 'orange', $result['badge']['color'] );
		self::assertStringContainsString( '65', $result['label'] );
	}

	public function test_run_test_returns_critical_for_low_score(): void {
		$this->seed_cache(
			30,
			array(
				'discoverability'       => 30,
				'content_readability'   => 20,
				'schema_coverage'       => 30,
				'exposure_safety'       => 40,
				'integration_health'    => 0,
				'md_conversion_quality' => 50,
			)
		);

		$result = Site_Health::run_test();

		self::assertSame( 'critical', $result['status'] );
		self::assertSame( 'red', $result['badge']['color'] );
		self::assertStringContainsString( '30', $result['label'] );
	}

	public function test_description_names_highest_leverage_subscore(): void {
		// md_conversion_quality has weight 25 and value 0 → leverage 2500.
		// integration_health has weight 15 and value 0 → leverage 1500.
		// md_conversion_quality should be surfaced as the worst.
		$this->seed_cache(
			40,
			array(
				'discoverability'       => 100,
				'content_readability'   => 100,
				'schema_coverage'       => 100,
				'exposure_safety'       => 100,
				'integration_health'    => 0,
				'md_conversion_quality' => 0,
			)
		);

		$result = Site_Health::run_test();

		self::assertStringContainsString( 'Markdown', $result['description'] );
	}

	public function test_actions_links_to_context_score_admin_page(): void {
		$this->seed_cache(
			85,
			array(
				'discoverability'       => 100,
				'content_readability'   => 100,
				'schema_coverage'       => 100,
				'exposure_safety'       => 100,
				'integration_health'    => 100,
				'md_conversion_quality' => 100,
			)
		);

		$result = Site_Health::run_test();

		self::assertStringContainsString( 'agentready-context-score', $result['actions'] );
	}

	/**
	 * Plant a fake Context Score payload directly into the option so
	 * the Site_Health tests can target specific buckets without
	 * having to construct signal inputs that bend the engine in the
	 * desired direction.
	 *
	 * @param int                $overall    Overall score 0..100.
	 * @param array<string, int> $sub_values Per-sub-score value, keyed by name.
	 */
	private function seed_cache( int $overall, array $sub_values ): void {
		$weights    = array(
			'discoverability'         => 10,
			'content_readability'     => 15,
			'schema_coverage'         => 10,
			'exposure_safety'         => 15,
			'integration_health'      => 15,
			'md_conversion_quality'   => 25,
			'multi_channel_discovery' => 10,
		);
		$sub_scores = array();
		foreach ( $sub_values as $name => $value ) {
			$sub_scores[ $name ] = array(
				'value'   => (int) $value,
				'weight'  => $weights[ $name ] ?? 0,
				'signals' => array(),
				'reasons' => array(),
			);
		}

		update_option(
			Service::CACHE_OPTION,
			array(
				'schema_version'        => Service::CACHE_SCHEMA_VERSION,
				'computed_at'           => gmdate( 'c' ),
				'recompute_duration_ms' => 0,
				'overall'               => $overall,
				'sub_scores'            => $sub_scores,
			),
			'no'
		);
	}
}
