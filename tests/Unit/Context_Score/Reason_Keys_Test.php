<?php
/**
 * Unit tests for the additive parallel `reason_keys` contract (#139 / AgDR-0047).
 *
 * Engine emits, alongside each English `reasons` string, a `reason_keys` entry
 * of `{code, args}` that the admin bundle maps to a translatable string. These
 * tests pin that contract:
 *   - `reason_keys` is parallel to `reasons` (same count) for every sub-score.
 *   - Every entry is well-formed: non-empty string `code`, list `args`.
 *   - The set of codes Engine can emit EXACTLY equals a canonical set. The JS
 *     `REASON_TEMPLATES` map in `src/admin/context-score/index.js` must mirror
 *     this set — a new code here that the map lacks falls back to English; a
 *     dropped code here orphans a JS template. Either way this test is the
 *     tripwire that says "update the JS map too".
 *
 * The four fixtures below are designed to collectively exercise every branch
 * in every scorer, so the union of emitted codes covers the whole inventory.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use Mokhai\Context_Score\Engine;

final class Reason_Keys_Test extends TestCase {

	/**
	 * Every code Engine can emit. The JS REASON_TEMPLATES map must contain a
	 * template for each of these keys. Keep alphabetised within each sub-score
	 * prefix for review-ability.
	 *
	 * @var array<int, string>
	 */
	private const CANONICAL_CODES = array(
		// discoverability
		'disc_llms_txt_populated',
		'disc_llms_txt_empty',
		'disc_cpt_exposed',
		'disc_no_cpt_exposed',
		'disc_entries_listed',
		'disc_zero_entries',
		'disc_rewrite_conflict',
		// content_readability
		'cr_no_exposed_entries',
		'cr_coverage_good',
		'cr_coverage_medium',
		'cr_coverage_low',
		// schema_coverage
		'sc_seo_plugin_detected',
		'sc_native_jsonld',
		'sc_no_structured_data',
		// exposure_safety
		'es_only_published',
		'es_risky_statuses',
		'es_cpt_explicit',
		'es_no_cpt',
		// integration_health
		'ih_llm_configured',
		'ih_llm_disabled',
		'ih_llm_unconfigured',
		'ih_llms_txt_conflict',
		// md_conversion_quality
		'mcq_no_cache',
		'mcq_mean_quality',
		'mcq_above_threshold',
		// multi_channel_discovery
		'mcd_no_channels',
		'mcd_channels_detected',
		'mcd_openapi_bonus',
		'mcd_provider_configurable',
		'mcd_provider_detected',
	);

	public function test_reason_keys_run_parallel_to_reasons_for_every_sub_score(): void {
		foreach ( self::branch_fixtures() as $label => $signals ) {
			$breakdown = Engine::compute( $signals );
			foreach ( $breakdown['sub_scores'] as $name => $sub ) {
				self::assertArrayHasKey( 'reason_keys', $sub, "{$label}/{$name}: missing reason_keys" );
				self::assertSameSize(
					$sub['reasons'],
					$sub['reason_keys'],
					"{$label}/{$name}: reason_keys count must match reasons count"
				);
			}
		}
	}

	public function test_every_reason_key_is_well_formed(): void {
		foreach ( self::branch_fixtures() as $label => $signals ) {
			$breakdown = Engine::compute( $signals );
			foreach ( $breakdown['sub_scores'] as $name => $sub ) {
				foreach ( $sub['reason_keys'] as $i => $key ) {
					self::assertIsArray( $key, "{$label}/{$name}[{$i}]: reason_key must be an array" );
					self::assertArrayHasKey( 'code', $key );
					self::assertArrayHasKey( 'args', $key );
					self::assertIsString( $key['code'] );
					self::assertNotSame( '', $key['code'], "{$label}/{$name}[{$i}]: empty code" );
					self::assertIsArray( $key['args'] );
					self::assertSame( array_values( $key['args'] ), $key['args'], 'args must be a positional list' );
				}
			}
		}
	}

	public function test_emitted_codes_exactly_equal_the_canonical_set(): void {
		$emitted = array();
		foreach ( self::branch_fixtures() as $signals ) {
			$breakdown = Engine::compute( $signals );
			foreach ( $breakdown['sub_scores'] as $sub ) {
				foreach ( $sub['reason_keys'] as $key ) {
					$emitted[ (string) $key['code'] ] = true;
				}
			}
		}

		$emitted_codes   = array_keys( $emitted );
		$canonical       = self::CANONICAL_CODES;
		$unknown_emitted = array_diff( $emitted_codes, $canonical );
		$never_emitted   = array_diff( $canonical, $emitted_codes );

		self::assertSame(
			array(),
			array_values( $unknown_emitted ),
			'Engine emitted a code not in CANONICAL_CODES — add it here AND to the JS REASON_TEMPLATES map.'
		);
		self::assertSame(
			array(),
			array_values( $never_emitted ),
			'A CANONICAL_CODES entry was never emitted by the branch fixtures — drop it or add a fixture that hits its branch.'
		);
	}

	/**
	 * Four signal bundles that, together, hit every branch in every scorer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function branch_fixtures(): array {
		return array(
			// All "absent / zero / safe-default" branches.
			'empty'  => array(),

			// All "full credit" branches + a configurable sibling provider.
			'high'   => array(
				'profile'                 => array(
					'exposed_cpts'             => array( 'post', 'page' ),
					'exposed_statuses'         => array( 'publish' ),
					'llm_descriptions_enabled' => true,
				),
				'llms_txt'                => array(
					'cache_populated' => true,
					'entry_count'     => 42,
					'conflicts'       => array(),
				),
				'md_cache'                => array(
					'rows_total'           => 10,
					'rows_with_score'      => 10,
					'mean_quality'         => 100.0,
					'rows_above_threshold' => 10,
					'md_quality_threshold' => 70,
				),
				'schema'                  => array( 'seo_plugin' => 'yoast' ),
				'ai_client'               => array( 'configured' => true ),
				'descriptions'            => array(
					'total_entries'            => 10,
					'entries_with_description' => 10,
				),
				'multi_channel_discovery' => array(
					'llms_txt_present'     => true,
					'ai_txt_present'       => true,
					'well_known_ai_layer'  => true,
					'openapi_spec_present' => true,
					'active_provider'      => array(
						'slug'       => 'ai_layer',
						'name'       => 'AI Layer',
						'config_url' => 'https://example.test/wp-admin/admin.php?page=ai-layer',
					),
				),
			),

			// Rewrite conflict, risky status, native schema, mid coverage,
			// LLM-unconfigured, llms.txt conflict, provider-without-config-url.
			'messy'  => array(
				'profile'                 => array(
					'exposed_cpts'             => array( 'post' ),
					'exposed_statuses'         => array( 'publish', 'draft' ),
					'llm_descriptions_enabled' => true,
				),
				'llms_txt'                => array(
					'cache_populated' => true,
					'entry_count'     => 5,
					'conflicts'       => array( array( 'kind' => 'rewrite' ) ),
				),
				'schema'                  => array( 'native_emit_enabled' => true ),
				'ai_client'               => array( 'configured' => false ),
				'descriptions'            => array(
					'total_entries'            => 10,
					'entries_with_description' => 6,
				),
				'multi_channel_discovery' => array(
					'well_known_ai_layer' => true,
					'active_provider'     => array(
						'slug' => 'ai_layer',
						'name' => 'AI Layer',
					),
				),
			),

			// Low description coverage (< 50%).
			'low_cr' => array(
				'descriptions' => array(
					'total_entries'            => 10,
					'entries_with_description' => 1,
				),
			),
		);
	}
}
