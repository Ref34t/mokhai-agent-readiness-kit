<?php
/**
 * Unit tests for `WPContext\Context_Score\Narrative_Generator`.
 *
 * Covers the five paths defined in AgDR-0032:
 *   - LLM success (every sub-score line passes the guard → mode='llm').
 *   - LLM success with one fabricated line (guard kicks one → mode='mixed').
 *   - Parse error (model returned non-JSON → mode='rule_based', degraded).
 *   - Unconfigured client (no provider, no wp_ai_client_prompt callable
 *     via the env override) → mode='rule_based', degraded, reason='unconfigured'.
 *   - Provider raises a Permanent_Error → mode='rule_based', degraded,
 *     reason='permanent_error'.
 *
 * Mirrors the provider-injection seam used by Client_Wrapper_Test —
 * tests pass an anonymous Provider implementation so the real WP AI
 * Client is never reached.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use WPContext\Ai\Permanent_Error;
use WPContext\Ai\Provider;
use WPContext\Context_Score\Narrative_Generator;

final class Narrative_Generator_Test extends TestCase {

	protected function setUp(): void {
		// Reset the cron-queue stub so deferred-retry side effects from
		// Client_Wrapper don't leak between tests.
		$GLOBALS['wpctx_test_cron_queue'] = array();
	}

	public function test_llm_success_marks_mode_llm_and_keeps_every_pair(): void {
		$breakdown = self::sample_breakdown();
		$provider  = $this->json_provider( self::valid_llm_response() );

		$narrative = Narrative_Generator::generate( $breakdown, $provider );

		self::assertSame( 'llm', $narrative['mode'] );
		self::assertFalse( $narrative['degraded'] );
		self::assertNull( $narrative['degraded_reason'] );
		foreach ( array_keys( $breakdown['sub_scores'] ) as $name ) {
			self::assertSame(
				'llm',
				$narrative['sub_scores'][ $name ]['source'],
				"{$name} should be sourced from the LLM"
			);
		}
	}

	public function test_payload_carries_schema_version_and_generated_at(): void {
		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->json_provider( self::valid_llm_response() )
		);

		self::assertSame(
			Narrative_Generator::NARRATIVE_SCHEMA_VERSION,
			$narrative['schema_version']
		);
		self::assertIsString( $narrative['generated_at'] );
		self::assertNotSame( '', $narrative['generated_at'] );
		self::assertIsInt( $narrative['generation_duration_ms'] );
		self::assertGreaterThanOrEqual( 0, $narrative['generation_duration_ms'] );
	}

	public function test_strips_a_json_fence_wrapper(): void {
		$wrapped = "```json\n" . self::valid_llm_response() . "\n```";
		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->json_provider( $wrapped )
		);

		self::assertSame( 'llm', $narrative['mode'] );
	}

	public function test_fabricated_percentage_falls_back_to_rule_based_for_that_sub_score(): void {
		$breakdown = self::sample_breakdown();

		// Hallucinate "80%" in the content_readability why-line. The real
		// breakdown has coverage_pct=60, so "80%" is rejected by the guard.
		$response = self::valid_llm_response();
		$response = str_replace(
			'"why": "Coverage is at 60%, leaving room to improve."',
			'"why": "Coverage is at 80% across the exposed entries."',
			$response
		);

		$narrative = Narrative_Generator::generate(
			$breakdown,
			$this->json_provider( $response )
		);

		self::assertSame( 'mixed', $narrative['mode'] );
		self::assertFalse(
			$narrative['degraded'],
			'A guard-driven per-line replacement is NOT a degraded run.'
		);
		self::assertSame(
			'rule_based',
			$narrative['sub_scores']['content_readability']['source']
		);
		// Other sub-scores survived as LLM-sourced.
		self::assertSame(
			'llm',
			$narrative['sub_scores']['discoverability']['source']
		);
	}

	public function test_fabricated_brand_entity_falls_back_for_that_sub_score(): void {
		$breakdown = self::sample_breakdown();

		// Replace the schema_coverage fix-line with "Install Yoast SEO".
		// The breakdown has seo_plugin='' and no Yoast entity anywhere, so
		// the guard rejects it.
		$response = self::valid_llm_response();
		$response = str_replace(
			'"fix": "AI Readiness Kit will emit JSON-LD natively in a future release."',
			'"fix": "Install Yoast SEO to emit structured data."',
			$response
		);

		$narrative = Narrative_Generator::generate(
			$breakdown,
			$this->json_provider( $response )
		);

		self::assertSame( 'mixed', $narrative['mode'] );
		self::assertSame(
			'rule_based',
			$narrative['sub_scores']['schema_coverage']['source']
		);
	}

	public function test_non_json_response_degrades_to_rule_based(): void {
		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->json_provider( 'I am a friendly bot and I refuse to output JSON.' )
		);

		self::assertSame( 'rule_based', $narrative['mode'] );
		self::assertTrue( $narrative['degraded'] );
		self::assertSame( 'parse_error', $narrative['degraded_reason'] );
		foreach ( array_keys( self::sample_breakdown()['sub_scores'] ) as $name ) {
			self::assertSame(
				'rule_based',
				$narrative['sub_scores'][ $name ]['source']
			);
		}
	}

	public function test_response_missing_every_sub_score_degrades_with_parse_error(): void {
		// Syntactically valid JSON, but every entry lacks the why/fix shape
		// — so parse_response returns null and the orchestrator degrades.
		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->json_provider( '{"discoverability": "just a string", "schema_coverage": []}' )
		);

		self::assertSame( 'rule_based', $narrative['mode'] );
		self::assertTrue( $narrative['degraded'] );
		self::assertSame( 'parse_error', $narrative['degraded_reason'] );
	}

	public function test_permanent_error_degrades_with_permanent_error_reason(): void {
		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->always_permanent_provider()
		);

		self::assertSame( 'rule_based', $narrative['mode'] );
		self::assertTrue( $narrative['degraded'] );
		self::assertSame( 'permanent_error', $narrative['degraded_reason'] );
	}

	public function test_every_returned_line_is_within_max_output_chars(): void {
		// Hand the model a response with a sneakily long why-line. The
		// orchestrator sanitises and truncates to MAX_OUTPUT_CHARS.
		$long_why = str_repeat( 'A ', 100 ); // 200 chars
		$response = sprintf(
			'{"discoverability":{"why":"%s","fix":"ok"},"content_readability":{"why":"ok","fix":"ok"},"schema_coverage":{"why":"ok","fix":"ok"},"exposure_safety":{"why":"ok","fix":"ok"},"integration_health":{"why":"ok","fix":"ok"},"md_conversion_quality":{"why":"ok","fix":"ok"}}',
			trim( $long_why )
		);

		$narrative = Narrative_Generator::generate(
			self::sample_breakdown(),
			$this->json_provider( $response )
		);

		foreach ( $narrative['sub_scores'] as $name => $entry ) {
			self::assertLessThanOrEqual(
				Narrative_Generator::MAX_OUTPUT_CHARS,
				mb_strlen( $entry['why'] ),
				"{$name} why exceeded the cap"
			);
			self::assertLessThanOrEqual(
				Narrative_Generator::MAX_OUTPUT_CHARS,
				mb_strlen( $entry['fix'] ),
				"{$name} fix exceeded the cap"
			);
		}
	}

	public function test_user_prompt_only_includes_overall_and_sub_scores(): void {
		$breakdown = self::sample_breakdown();
		$breakdown['schema_version']        = 1;
		$breakdown['computed_at']           = '2026-05-19T13:00:00+00:00';
		$breakdown['recompute_duration_ms'] = 42;

		$prompt = Narrative_Generator::build_user_prompt( $breakdown );

		self::assertStringContainsString( '"overall"', $prompt );
		self::assertStringContainsString( '"sub_scores"', $prompt );
		// Metadata that's irrelevant to the narrative must NOT leak.
		self::assertStringNotContainsString( 'computed_at', $prompt );
		self::assertStringNotContainsString( 'schema_version', $prompt );
		self::assertStringNotContainsString( 'recompute_duration_ms', $prompt );
	}

	// ------------------------------------------------------------------
	// Provider doubles
	// ------------------------------------------------------------------

	/**
	 * @return Provider&object{calls: int}
	 */
	private function json_provider( string $response ): Provider {
		return new class( $response ) implements Provider {
			public int $calls = 0;
			private string $response;

			public function __construct( string $response ) {
				$this->response = $response;
			}

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				return $this->response;
			}
		};
	}

	/**
	 * @return Provider&object{calls: int}
	 */
	private function always_permanent_provider(): Provider {
		return new class implements Provider {
			public int $calls = 0;

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				throw new Permanent_Error( 'bad request 400' );
			}
		};
	}

	/**
	 * A breakdown wired so every sub-score has signals that match the
	 * `valid_llm_response()` numbers below. Used as the default input.
	 *
	 * @return array<string, mixed>
	 */
	private static function sample_breakdown(): array {
		return array(
			'overall'    => 70,
			'sub_scores' => array(
				'discoverability'       => array(
					'value'   => 75,
					'weight'  => 20,
					'signals' => array(
						'llms_txt_cache_populated' => true,
						'exposed_cpts_count'       => 1,
						'llms_txt_entry_count'     => 5,
						'rewrite_conflicted'       => false,
					),
					'reasons' => array(),
				),
				'content_readability'   => array(
					'value'   => 60,
					'weight'  => 15,
					'signals' => array(
						'total_entries'            => 10,
						'entries_with_description' => 6,
						'coverage_pct'             => 60,
					),
					'reasons' => array(),
				),
				'schema_coverage'       => array(
					'value'   => 60,
					'weight'  => 10,
					'signals' => array( 'seo_plugin' => '' ),
					'reasons' => array(),
				),
				'exposure_safety'       => array(
					'value'   => 100,
					'weight'  => 15,
					'signals' => array(
						'exposed_cpts_count'   => 1,
						'exposed_statuses'     => array( 'publish' ),
						'risky_statuses_count' => 0,
					),
					'reasons' => array(),
				),
				'integration_health'    => array(
					'value'   => 100,
					'weight'  => 15,
					'signals' => array(
						'llm_cleanup_enabled'      => false,
						'llm_descriptions_enabled' => false,
						'ai_client_configured'     => false,
						'conflict_count'           => 0,
					),
					'reasons' => array(),
				),
				'md_conversion_quality' => array(
					'value'   => 60,
					'weight'  => 25,
					'signals' => array(
						'rows_total'           => 4,
						'rows_with_score'      => 4,
						'mean_quality'         => 75,
						'rows_above_threshold' => 2,
						'above_threshold_pct'  => 50,
						'cleanup_threshold'    => 70,
					),
					'reasons' => array(),
				),
			),
		);
	}

	/**
	 * A JSON response keyed against `sample_breakdown()` — every numeric
	 * token in every line appears in the breakdown's `signals`. No
	 * fabricated brand names. Designed to pass the guard cleanly.
	 */
	private static function valid_llm_response(): string {
		return <<<'JSON'
{
  "discoverability": {
    "why": "Coverage looks healthy with 5 entries indexed.",
    "fix": "Keep the Context Profile in sync as new CPTs are added."
  },
  "content_readability": {
    "why": "Coverage is at 60%, leaving room to improve.",
    "fix": "Run the AI Readiness Kit descriptions backfill on the gaps."
  },
  "schema_coverage": {
    "why": "No structured-data plugin was detected on this site.",
    "fix": "AI Readiness Kit will emit JSON-LD natively in a future release."
  },
  "exposure_safety": {
    "why": "Only published statuses are exposed, which is the safe baseline.",
    "fix": "Re-audit when new statuses are introduced by other plugins."
  },
  "integration_health": {
    "why": "LLM features are off, so no AI Client is required.",
    "fix": "Re-run this check after toggling any LLM feature in the Context Profile."
  },
  "md_conversion_quality": {
    "why": "Mean quality is 75 across 4 cached posts.",
    "fix": "Approve LLM cleanup runs on posts below the threshold."
  }
}
JSON;
	}
}
