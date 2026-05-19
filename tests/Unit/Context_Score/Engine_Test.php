<?php
/**
 * Unit tests for the pure Context Score engine (#9 / AgDR-0030).
 *
 * The engine takes a structured signals array and returns the breakdown.
 * No WordPress is loaded. Each sub-score has its own focused test so a
 * regression on one sub-score doesn't drag down the rest of the suite.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use WPContext\Context_Score\Engine;

final class Engine_Test extends TestCase {

	public function test_weights_sum_to_100(): void {
		$total = 0;
		foreach ( Engine::WEIGHTS as $weight ) {
			$total += $weight;
		}
		$this->assertSame( 100, $total, 'Sub-score weights must total 100 for the overall formula to map to a 0..100 range.' );
	}

	public function test_breakdown_includes_all_six_sub_scores(): void {
		$out = Engine::compute( array() );

		$this->assertArrayHasKey( 'sub_scores', $out );
		foreach ( array_keys( Engine::WEIGHTS ) as $name ) {
			$this->assertArrayHasKey( $name, $out['sub_scores'], "missing sub-score: {$name}" );
		}
	}

	public function test_breakdown_schema_version_matches_constant(): void {
		$out = Engine::compute( array() );

		$this->assertSame( Engine::BREAKDOWN_SCHEMA_VERSION, $out['schema_version'] );
	}

	public function test_empty_signals_do_not_crash_and_return_low_overall(): void {
		$out = Engine::compute( array() );

		$this->assertIsInt( $out['overall'] );
		$this->assertGreaterThanOrEqual( 0, $out['overall'] );
		$this->assertLessThan( 50, $out['overall'], 'Empty signals should produce a low overall score.' );
	}

	public function test_each_sub_score_carries_value_weight_signals_reasons(): void {
		$out = Engine::compute( array() );

		foreach ( $out['sub_scores'] as $name => $sub ) {
			$this->assertArrayHasKey( 'value', $sub, "{$name} missing value" );
			$this->assertArrayHasKey( 'weight', $sub, "{$name} missing weight" );
			$this->assertArrayHasKey( 'signals', $sub, "{$name} missing signals" );
			$this->assertArrayHasKey( 'reasons', $sub, "{$name} missing reasons" );
			$this->assertIsInt( $sub['value'] );
			$this->assertGreaterThanOrEqual( 0, $sub['value'] );
			$this->assertLessThanOrEqual( 100, $sub['value'] );
			$this->assertSame( Engine::WEIGHTS[ $name ], $sub['weight'] );
		}
	}

	public function test_perfect_input_yields_100_overall(): void {
		$out = Engine::compute( self::perfect_signals() );

		$this->assertSame( 100, $out['overall'] );
		foreach ( $out['sub_scores'] as $name => $sub ) {
			$this->assertSame( 100, $sub['value'], "{$name} should be 100 on perfect input" );
		}
	}

	public function test_overall_is_weighted_floor_of_sub_scores(): void {
		// Construct a signals bundle that yields a deterministic mix of values.
		$signals = self::perfect_signals();
		// Knock content_readability down to 50 by halving description coverage.
		$signals['descriptions'] = array(
			'total_entries'            => 10,
			'entries_with_description' => 5,
		);
		// Knock md_conversion_quality down to 80 (mean 100 + 50% above threshold):
		//   mean_pts = floor(100 * 60 / 100) = 60.
		//   above_pts = floor(50 * 40 / 100) = 20.
		$signals['md_cache']['rows_above_threshold'] = 5;

		$out = Engine::compute( $signals );

		// Sub-scores: discoverability=100, content_readability=50, schema=100,
		// exposure=100, integration=100, md_quality=80.
		// Overall = floor( (100*20 + 50*15 + 100*10 + 100*15 + 100*15 + 80*25) / 100 )
		//         = floor( (2000 + 750 + 1000 + 1500 + 1500 + 2000) / 100 )
		//         = floor( 8750 / 100 ) = 87.
		$this->assertSame( 87, $out['overall'] );
	}

	public function test_discoverability_rewards_populated_cache_and_exposed_cpts(): void {
		$signals = array(
			'profile'  => array( 'exposed_cpts' => array( 'post' ) ),
			'llms_txt' => array(
				'cache_populated' => true,
				'entry_count'     => 5,
				'conflicts'       => array(),
			),
		);

		$value = Engine::compute( $signals )['sub_scores']['discoverability']['value'];

		// 50 (cache) + 25 (one CPT) + 15 (entries) + 10 (no conflict) = 100.
		$this->assertSame( 100, $value );
	}

	public function test_discoverability_penalises_rewrite_conflict(): void {
		$signals = array(
			'profile'  => array( 'exposed_cpts' => array( 'post' ) ),
			'llms_txt' => array(
				'cache_populated' => true,
				'entry_count'     => 5,
				'conflicts'       => array(
					array( 'kind' => 'rewrite', 'rule' => '^llms\.txt/?$' ),
				),
			),
		);

		$sub = Engine::compute( $signals )['sub_scores']['discoverability'];

		// 50 + 25 + 15 + 0 (rewrite conflict) = 90.
		$this->assertSame( 90, $sub['value'] );
		$this->assertSame( true, $sub['signals']['rewrite_conflicted'] );
	}

	public function test_discoverability_is_low_for_empty_state(): void {
		// Empty signals: no cache, no CPTs, no entries → 0 from those three
		// gates. The conflict gate awards 10 because an empty conflicts list
		// is in fact "no rewrite conflict detected" — that 10-point floor is
		// the v0.1 baseline for a freshly-installed site that hasn't run
		// detection yet.
		$value = Engine::compute( array() )['sub_scores']['discoverability']['value'];

		$this->assertSame( 10, $value );
	}

	public function test_content_readability_returns_zero_when_no_entries(): void {
		$signals = array(
			'descriptions' => array( 'total_entries' => 0, 'entries_with_description' => 0 ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['content_readability'];

		$this->assertSame( 0, $sub['value'] );
	}

	public function test_content_readability_scales_with_coverage(): void {
		$signals = array(
			'descriptions' => array( 'total_entries' => 10, 'entries_with_description' => 7 ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['content_readability'];

		$this->assertSame( 70, $sub['value'] );
		$this->assertSame( 70, $sub['signals']['coverage_pct'] );
	}

	public function test_content_readability_caps_coverage_at_total(): void {
		// Defensive: if a stale signal reports more descriptions than entries,
		// the result clamps to 100% rather than going over.
		$signals = array(
			'descriptions' => array( 'total_entries' => 10, 'entries_with_description' => 99 ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['content_readability'];

		$this->assertSame( 100, $sub['value'] );
	}

	public function test_schema_coverage_rewards_detected_seo_plugin(): void {
		$with = Engine::compute( array( 'schema' => array( 'seo_plugin' => 'yoast' ) ) )['sub_scores']['schema_coverage']['value'];
		$without = Engine::compute( array( 'schema' => array( 'seo_plugin' => '' ) ) )['sub_scores']['schema_coverage']['value'];

		$this->assertSame( 100, $with );
		$this->assertSame( 60, $without );
		$this->assertGreaterThan( $without, $with );
	}

	public function test_schema_coverage_rewards_native_emit_when_no_seo_plugin(): void {
		// #73 / AgDR-0034: native emission is on par with a third-party SEO
		// plugin for schema_coverage credit.
		$result = Engine::compute(
			array(
				'schema' => array(
					'seo_plugin'          => '',
					'native_emit_enabled' => true,
				),
			)
		)['sub_scores']['schema_coverage'];

		$this->assertSame( 100, $result['value'] );
		$this->assertNotEmpty( $result['reasons'] );
		$this->assertStringContainsString( 'native JSON-LD', $result['reasons'][0] );
	}

	public function test_schema_coverage_60_when_no_seo_plugin_and_no_native_emit(): void {
		$result = Engine::compute(
			array(
				'schema' => array(
					'seo_plugin'          => '',
					'native_emit_enabled' => false,
				),
			)
		)['sub_scores']['schema_coverage'];

		$this->assertSame( 60, $result['value'] );
		$this->assertStringContainsString( 'Enable Schema emission', $result['reasons'][0] );
	}

	public function test_schema_coverage_seo_plugin_credit_wins_when_both_present(): void {
		// Defensive: if both signals fire, the SEO-plugin branch wins (it
		// emits richer schema than agentready's v0.1 baseline).
		$result = Engine::compute(
			array(
				'schema' => array(
					'seo_plugin'          => 'yoast',
					'native_emit_enabled' => true,
				),
			)
		)['sub_scores']['schema_coverage'];

		$this->assertSame( 100, $result['value'] );
		$this->assertStringContainsString( 'yoast', $result['reasons'][0] );
	}

	public function test_exposure_safety_full_credit_when_publish_only_and_cpts_set(): void {
		$signals = array(
			'profile' => array(
				'exposed_cpts'     => array( 'post' ),
				'exposed_statuses' => array( 'publish' ),
			),
		);

		$value = Engine::compute( $signals )['sub_scores']['exposure_safety']['value'];

		$this->assertSame( 100, $value );
	}

	public function test_exposure_safety_penalises_risky_statuses(): void {
		$signals = array(
			'profile' => array(
				'exposed_cpts'     => array( 'post' ),
				'exposed_statuses' => array( 'publish', 'draft' ),
			),
		);

		$sub = Engine::compute( $signals )['sub_scores']['exposure_safety'];

		// 60 - 15 (one risky) + 40 (cpts) = 85.
		$this->assertSame( 85, $sub['value'] );
		$this->assertSame( 1, $sub['signals']['risky_statuses_count'] );
	}

	public function test_exposure_safety_penalty_caps_at_baseline(): void {
		// Five risky statuses → penalty min(60, 75) = 60, baseline gone.
		$signals = array(
			'profile' => array(
				'exposed_cpts'     => array( 'post' ),
				'exposed_statuses' => array( 'private', 'password', 'draft', 'pending', 'future' ),
			),
		);

		$sub = Engine::compute( $signals )['sub_scores']['exposure_safety'];

		// 60 - 60 + 40 = 40.
		$this->assertSame( 40, $sub['value'] );
	}

	public function test_integration_health_full_credit_when_llm_off_and_clean(): void {
		// Toggles off + no conflicts is a valid steady state — no penalty.
		$signals = array(
			'profile'  => array(
				'llm_cleanup_enabled'      => false,
				'llm_descriptions_enabled' => false,
			),
			'llms_txt' => array( 'conflicts' => array() ),
			'ai_client' => array( 'configured' => false ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['integration_health'];

		// 60 (consistent: no LLM, no client needed) + 40 (no conflicts) = 100.
		$this->assertSame( 100, $sub['value'] );
	}

	public function test_integration_health_penalises_llm_on_but_client_unconfigured(): void {
		$signals = array(
			'profile'  => array(
				'llm_cleanup_enabled'      => true,
				'llm_descriptions_enabled' => true,
			),
			'llms_txt' => array( 'conflicts' => array() ),
			'ai_client' => array( 'configured' => false ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['integration_health'];

		// 0 (toggles on + client off = inconsistent) + 40 (no conflicts) = 40.
		$this->assertSame( 40, $sub['value'] );
	}

	public function test_integration_health_penalises_conflicts(): void {
		$signals = array(
			'profile'   => array(
				'llm_cleanup_enabled'      => true,
				'llm_descriptions_enabled' => true,
			),
			'llms_txt'  => array(
				'conflicts' => array(
					array( 'kind' => 'plugin', 'slug' => 'x/x.php' ),
				),
			),
			'ai_client' => array( 'configured' => true ),
		);

		$sub = Engine::compute( $signals )['sub_scores']['integration_health'];

		// 60 (consistent) + 0 (conflicts) = 60.
		$this->assertSame( 60, $sub['value'] );
	}

	public function test_md_conversion_quality_returns_zero_when_cache_empty(): void {
		$signals = array(
			'md_cache' => array(
				'rows_total'           => 0,
				'rows_with_score'      => 0,
				'mean_quality'         => 0.0,
				'rows_above_threshold' => 0,
				'cleanup_threshold'    => 70,
			),
		);

		$value = Engine::compute( $signals )['sub_scores']['md_conversion_quality']['value'];

		$this->assertSame( 0, $value );
	}

	public function test_md_conversion_quality_combines_mean_and_above_threshold_pct(): void {
		$signals = array(
			'md_cache' => array(
				'rows_total'           => 10,
				'rows_with_score'      => 10,
				'mean_quality'         => 80.0,
				'rows_above_threshold' => 8,
				'cleanup_threshold'    => 70,
			),
		);

		$sub = Engine::compute( $signals )['sub_scores']['md_conversion_quality'];

		// mean_pts = floor( 80 * 60 / 100 ) = 48.
		// above_pct = floor( 8 * 100 / 10 ) = 80.
		// above_pts = floor( 80 * 40 / 100 ) = 32.
		// Total = 80.
		$this->assertSame( 80, $sub['value'] );
		$this->assertSame( 80, $sub['signals']['above_threshold_pct'] );
	}

	public function test_md_conversion_quality_clamps_to_100(): void {
		// Floor maths can't actually exceed 100 with the current weights,
		// but the clamp guards against future weight bumps.
		$signals = array(
			'md_cache' => array(
				'rows_total'           => 100,
				'rows_with_score'      => 100,
				'mean_quality'         => 100.0,
				'rows_above_threshold' => 100,
				'cleanup_threshold'    => 70,
			),
		);

		$sub = Engine::compute( $signals )['sub_scores']['md_conversion_quality'];

		$this->assertSame( 100, $sub['value'] );
	}

	/**
	 * A signals bundle that every sub-score scores 100 on.
	 *
	 * Used as the high-water-mark fixture for the overall-formula test and
	 * the per-sub-score "max value" assertions.
	 *
	 * @return array<string, mixed>
	 */
	private static function perfect_signals(): array {
		return array(
			'profile'      => array(
				'exposed_cpts'                     => array( 'post', 'page' ),
				'exposed_statuses'                 => array( 'publish' ),
				'llm_cleanup_enabled'              => true,
				'llm_descriptions_enabled'         => true,
				'markdown_views_cleanup_threshold' => 70,
			),
			'llms_txt'     => array(
				'cache_populated' => true,
				'entry_count'     => 42,
				'body_bytes'      => 4096,
				'conflicts'       => array(),
			),
			'md_cache'     => array(
				'rows_total'           => 10,
				'rows_with_score'      => 10,
				'mean_quality'         => 100.0,
				'rows_above_threshold' => 10,
				'cleanup_threshold'    => 70,
			),
			'schema'       => array( 'seo_plugin' => 'yoast' ),
			'ai_client'    => array( 'configured' => true ),
			'descriptions' => array(
				'total_entries'            => 10,
				'entries_with_description' => 10,
			),
		);
	}
}
