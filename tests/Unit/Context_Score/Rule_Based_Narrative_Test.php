<?php
/**
 * Unit tests for `WPContext\Context_Score\Rule_Based_Narrative`.
 *
 * The rule-based narrative is the deterministic fallback that runs when
 * (a) the WP AI Client is unconfigured, (b) the LLM call fails / overruns
 * the 10s budget, or (c) an LLM line fails the per-sub-score guard. Each
 * test pins the three buckets (working / partial / critical) for one
 * sub-score to keep regressions on one template from dragging the suite.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use WPContext\Context_Score\Rule_Based_Narrative;

final class Rule_Based_Narrative_Test extends TestCase {

	public function test_compose_emits_a_pair_per_sub_score_in_the_breakdown(): void {
		$breakdown = self::sample_breakdown();

		$out = Rule_Based_Narrative::compose( $breakdown );

		foreach ( array_keys( $breakdown['sub_scores'] ) as $name ) {
			self::assertArrayHasKey( $name, $out, "missing pair for {$name}" );
			self::assertArrayHasKey( 'why', $out[ $name ] );
			self::assertArrayHasKey( 'fix', $out[ $name ] );
			self::assertNotSame( '', trim( $out[ $name ]['why'] ) );
			self::assertNotSame( '', trim( $out[ $name ]['fix'] ) );
		}
	}

	public function test_every_line_respects_the_max_output_chars_ceiling(): void {
		$out = Rule_Based_Narrative::compose( self::sample_breakdown() );

		foreach ( $out as $name => $pair ) {
			self::assertLessThanOrEqual( Rule_Based_Narrative::MAX_OUTPUT_CHARS, \strlen( $pair['why'] ), "{$name} why too long" );
			self::assertLessThanOrEqual( Rule_Based_Narrative::MAX_OUTPUT_CHARS, \strlen( $pair['fix'] ), "{$name} fix too long" );
		}
	}

	public function test_discoverability_working_bucket_when_value_is_100(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'discoverability',
			array(
				'value'   => 100,
				'weight'  => 20,
				'signals' => array(),
			)
		);

		self::assertStringContainsString( 'Working well', $pair['why'] );
	}

	public function test_discoverability_calls_out_empty_cache(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'discoverability',
			array(
				'value'   => 0,
				'weight'  => 20,
				'signals' => array(
					'llms_txt_cache_populated' => false,
					'exposed_cpts_count'       => 1,
					'llms_txt_entry_count'     => 0,
					'rewrite_conflicted'       => false,
				),
			)
		);

		self::assertStringContainsString( '/llms.txt', $pair['why'] );
		self::assertStringContainsString( 'empty', $pair['why'] );
	}

	public function test_discoverability_calls_out_rewrite_conflict_first(): void {
		// A site that's missing everything PLUS has a rewrite conflict
		// should hear about the conflict first — it's the actionable signal.
		$pair = Rule_Based_Narrative::compose_one(
			'discoverability',
			array(
				'value'   => 0,
				'weight'  => 20,
				'signals' => array(
					'llms_txt_cache_populated' => false,
					'exposed_cpts_count'       => 0,
					'llms_txt_entry_count'     => 0,
					'rewrite_conflicted'       => true,
				),
			)
		);

		self::assertStringContainsString( 'overriding', $pair['why'] );
		self::assertStringContainsString( 'plugin', $pair['why'] );
	}

	public function test_content_readability_zero_total_says_nothing_to_read(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'content_readability',
			array(
				'value'   => 0,
				'weight'  => 15,
				'signals' => array(
					'total_entries'            => 0,
					'entries_with_description' => 0,
					'coverage_pct'             => 0,
				),
			)
		);

		self::assertStringContainsString( 'nothing for agents to read', $pair['why'] );
	}

	public function test_content_readability_critical_bucket_uses_coverage_pct(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'content_readability',
			array(
				'value'   => 30,
				'weight'  => 15,
				'signals' => array(
					'total_entries'            => 10,
					'entries_with_description' => 3,
					'coverage_pct'             => 30,
				),
			)
		);

		self::assertStringContainsString( '30%', $pair['why'] );
	}

	public function test_content_readability_fix_points_to_descriptions_tab_when_already_enabled(): void {
		// Regression (#209): when auto-descriptions are already on, the fix must
		// not tell the user to "enable" them again — it points at the GUI
		// Descriptions tab "Regenerate" path that actually resolves the gap.
		$pair = Rule_Based_Narrative::compose_one(
			'content_readability',
			array(
				'value'   => 0,
				'weight'  => 15,
				'signals' => array(
					'total_entries'            => 10,
					'entries_with_description' => 0,
					'coverage_pct'             => 0,
					'llm_descriptions_enabled' => true,
				),
			)
		);

		self::assertStringContainsString( 'Regenerate stale descriptions', $pair['fix'] );
		self::assertStringNotContainsString( 'Enable', $pair['fix'] );
	}

	public function test_content_readability_fix_says_enable_when_descriptions_off(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'content_readability',
			array(
				'value'   => 0,
				'weight'  => 15,
				'signals' => array(
					'total_entries'            => 10,
					'entries_with_description' => 0,
					'coverage_pct'             => 0,
					'llm_descriptions_enabled' => false,
				),
			)
		);

		self::assertStringContainsString( 'Enable auto-descriptions', $pair['fix'] );
	}

	public function test_schema_coverage_working_names_detected_plugin(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'schema_coverage',
			array(
				'value'   => 100,
				'weight'  => 10,
				'signals' => array( 'seo_plugin' => 'Yoast SEO' ),
			)
		);

		self::assertStringContainsString( 'Yoast SEO', $pair['why'] );
	}

	public function test_schema_coverage_unknown_plugin_falls_back_to_generic_phrasing(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'schema_coverage',
			array(
				'value'   => 60,
				'weight'  => 10,
				'signals' => array( 'seo_plugin' => '' ),
			)
		);

		self::assertStringContainsString( 'No structured data', $pair['why'] );
		// Regression (#208): the fix line must not promise a future release —
		// native emission already shipped and is reachable from the profile.
		self::assertStringNotContainsString( 'future release', $pair['fix'] );
		self::assertStringContainsString( 'Schema emission', $pair['fix'] );
	}

	public function test_schema_coverage_native_emission_does_not_claim_no_structured_data(): void {
		// Regression (#208): native JSON-LD on (no SEO plugin) scores 100/100;
		// the narrative previously fell through to "No structured data".
		$pair = Rule_Based_Narrative::compose_one(
			'schema_coverage',
			array(
				'value'   => 100,
				'weight'  => 10,
				'signals' => array(
					'seo_plugin'          => '',
					'native_emit_enabled' => true,
				),
			)
		);

		self::assertStringContainsString( 'native JSON-LD', $pair['why'] );
		self::assertStringNotContainsString( 'No structured data', $pair['why'] );
	}

	public function test_exposure_safety_risky_statuses_drives_the_fix(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'exposure_safety',
			array(
				'value'   => 40,
				'weight'  => 15,
				'signals' => array(
					'exposed_cpts_count'   => 1,
					'exposed_statuses'     => array( 'publish', 'private' ),
					'risky_statuses_count' => 1,
				),
			)
		);

		self::assertStringContainsString( 'non-publish', $pair['why'] );
		self::assertStringContainsString( 'Context Profile', $pair['fix'] );
	}

	public function test_integration_health_silent_degrade_is_named_in_why(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'integration_health',
			array(
				'value'   => 40,
				'weight'  => 15,
				'signals' => array(
					'llm_descriptions_enabled' => true,
					'ai_client_configured'     => false,
					'conflict_count'           => 0,
				),
			)
		);

		self::assertStringContainsString( 'silently degrade', $pair['why'] );
		self::assertStringContainsString( 'AI Client', $pair['fix'] );
	}

	public function test_md_conversion_quality_zero_rows_says_visit_md_urls(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'md_conversion_quality',
			array(
				'value'   => 0,
				'weight'  => 25,
				'signals' => array(
					'rows_total'           => 0,
					'rows_with_score'      => 0,
					'mean_quality'         => 0,
					'rows_above_threshold' => 0,
					'above_threshold_pct'  => 0,
					'md_quality_threshold' => 70,
				),
			)
		);

		self::assertStringContainsString( '.md', $pair['fix'] );
	}

	public function test_unknown_sub_score_returns_generic_pair_with_name_and_value(): void {
		$pair = Rule_Based_Narrative::compose_one(
			'invented_sub_score',
			array(
				'value'   => 42,
				'weight'  => 10,
				'signals' => array(),
			)
		);

		self::assertStringContainsString( 'invented_sub_score', $pair['why'] );
		self::assertStringContainsString( '42', $pair['why'] );
	}

	/**
	 * @return array<string, mixed> A breakdown with all sub-scores at
	 *                              representative values.
	 */
	private static function sample_breakdown(): array {
		return array(
			'overall'    => 60,
			'sub_scores' => array(
				'discoverability'         => array(
					'value'   => 75,
					'weight'  => 10,
					'signals' => array(
						'llms_txt_cache_populated' => true,
						'exposed_cpts_count'       => 1,
						'llms_txt_entry_count'     => 5,
						'rewrite_conflicted'       => false,
					),
				),
				'content_readability'   => array(
					'value'   => 60,
					'weight'  => 15,
					'signals' => array(
						'total_entries'            => 10,
						'entries_with_description' => 6,
						'coverage_pct'             => 60,
					),
				),
				'schema_coverage'       => array(
					'value'   => 60,
					'weight'  => 10,
					'signals' => array( 'seo_plugin' => '' ),
				),
				'exposure_safety'       => array(
					'value'   => 100,
					'weight'  => 15,
					'signals' => array(
						'exposed_cpts_count'   => 1,
						'exposed_statuses'     => array( 'publish' ),
						'risky_statuses_count' => 0,
					),
				),
				'integration_health'    => array(
					'value'   => 100,
					'weight'  => 15,
					'signals' => array(
						'llm_descriptions_enabled' => false,
						'ai_client_configured'     => false,
						'conflict_count'           => 0,
					),
				),
				'md_conversion_quality'   => array(
					'value'   => 60,
					'weight'  => 25,
					'signals' => array(
						'rows_total'           => 4,
						'rows_with_score'      => 4,
						'mean_quality'         => 75,
						'rows_above_threshold' => 2,
						'above_threshold_pct'  => 50,
						'md_quality_threshold' => 70,
					),
				),
				'multi_channel_discovery' => array(
					'value'   => 40,
					'weight'  => 10,
					'signals' => array(
						'llms_txt_present'       => true,
						'ai_txt_present'         => true,
						'well_known_ai_layer'    => false,
						'well_known_llms_policy' => false,
						'openapi_spec_present'   => false,
						'surfaces_present_count' => 2,
						'active_provider'        => null,
					),
				),
			),
		);
	}
}
