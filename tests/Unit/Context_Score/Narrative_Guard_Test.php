<?php
/**
 * Unit tests for `WPContext\Context_Score\Narrative_Guard`.
 *
 * The guard is the load-bearing safety mechanism for the LLM narrative
 * (AgDR-0032 § "Anti-hallucination guard"). Failures here mean the
 * orchestrator silently falls back to the rule-based template for that
 * sub-score — the panel never shows a hallucinated line.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use WPContext\Context_Score\Narrative_Guard;

final class Narrative_Guard_Test extends TestCase {

	public function test_allowlist_includes_value_and_weight_as_bare_numbers(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 60,
				'weight'  => 20,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		self::assertContains( '60', $allowlist['numbers'] );
		self::assertContains( '20', $allowlist['numbers'] );
	}

	public function test_allowlist_includes_percent_and_slash_100_suffixes(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 80,
				'weight'  => 15,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		self::assertContains( '80%', $allowlist['numbers'] );
		self::assertContains( '80/100', $allowlist['numbers'] );
		self::assertContains( '15%', $allowlist['numbers'] );
	}

	public function test_allowlist_walks_signal_dict_and_extracts_numbers(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array(
					'total_entries'            => 24,
					'entries_with_description' => 12,
					'coverage_pct'             => 50,
				),
				'reasons' => array(),
			)
		);

		self::assertContains( '24', $allowlist['numbers'] );
		self::assertContains( '12', $allowlist['numbers'] );
		self::assertContains( '50', $allowlist['numbers'] );
	}

	public function test_allowlist_extracts_numbers_from_reason_strings(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array(),
				'reasons' => array(
					'Only 50% of exposed entries have a curated description.',
				),
			)
		);

		self::assertContains( '50', $allowlist['numbers'] );
	}

	public function test_allowlist_extracts_multi_word_entities_from_reasons_lowercased(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 100,
				'weight'  => 10,
				'signals' => array( 'seo_plugin' => 'Yoast SEO' ),
				'reasons' => array(
					'Detected SEO plugin (Yoast SEO) — structured data is likely being emitted.',
				),
			)
		);

		self::assertContains( 'yoast seo', $allowlist['entities'] );
	}

	public function test_allowlist_always_includes_cross_cutting_brand_terms(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 0,
				'weight'  => 0,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		self::assertContains( 'agentable', $allowlist['entities'] );
		self::assertContains( 'context profile', $allowlist['entities'] );
		self::assertContains( 'ai client', $allowlist['entities'] );
		self::assertContains( 'site health', $allowlist['entities'] );
	}

	public function test_is_safe_passes_a_line_whose_numbers_are_all_in_the_allowlist(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array( 'coverage_pct' => 50, 'total_entries' => 24 ),
				'reasons' => array(),
			)
		);

		self::assertTrue(
			Narrative_Guard::is_safe(
				'Coverage is at 50%, with 24 entries total.',
				$allowlist
			)
		);
	}

	public function test_is_safe_rejects_a_fabricated_percentage(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array( 'coverage_pct' => 50 ),
				'reasons' => array(),
			)
		);

		// "80%" is nowhere in the breakdown; this must be rejected.
		self::assertFalse(
			Narrative_Guard::is_safe(
				'Coverage is at 80% across the exposed entries.',
				$allowlist
			)
		);
	}

	public function test_is_safe_rejects_a_fabricated_brand_entity(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 60,
				'weight'  => 10,
				'signals' => array( 'seo_plugin' => '' ),
				'reasons' => array( 'No structured data detected on this site.' ),
			)
		);

		// "Yoast SEO" never appears in the breakdown; must be rejected.
		self::assertFalse(
			Narrative_Guard::is_safe(
				'Install Yoast SEO to emit structured data.',
				$allowlist
			)
		);
	}

	public function test_is_safe_accepts_cross_cutting_entity_even_when_absent_from_sub_score(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 0,
				'weight'  => 0,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		// "Context Profile" is in the cross-cutting list, so even a totally
		// signal-less sub-score should allow it.
		self::assertTrue(
			Narrative_Guard::is_safe(
				'Open the Context Profile and review the configuration.',
				$allowlist
			)
		);
	}

	public function test_is_safe_ignores_single_word_capitalised_tokens(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		// "Critical" / "Partial" are single-word sentence-starts; the guard
		// only checks multi-word entities (false-positive bias rationale in
		// AgDR-0032). Should pass.
		self::assertTrue(
			Narrative_Guard::is_safe(
				'Partial coverage. Critical paths need attention.',
				$allowlist
			)
		);
	}

	public function test_is_safe_accepts_boolean_signals_as_zero_or_one(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 100,
				'weight'  => 20,
				'signals' => array(
					'llms_txt_cache_populated' => true,
					'rewrite_conflicted'       => false,
				),
				'reasons' => array(),
			)
		);

		// True -> "1", False -> "0".
		self::assertContains( '1', $allowlist['numbers'] );
		self::assertContains( '0', $allowlist['numbers'] );
	}

	public function test_is_safe_accepts_value_in_slash_100_form_when_only_bare_present(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 60,
				'weight'  => 25,
				'signals' => array(),
				'reasons' => array(),
			)
		);

		// "60/100" is auto-generated as a suffixed form of bare "60".
		self::assertTrue(
			Narrative_Guard::is_safe( 'Mean quality is 60/100.', $allowlist )
		);
	}

	public function test_is_safe_rejects_when_any_one_token_is_missing(): void {
		$allowlist = Narrative_Guard::build_allowlist(
			array(
				'value'   => 50,
				'weight'  => 15,
				'signals' => array( 'coverage_pct' => 50 ),
				'reasons' => array(),
			)
		);

		// "50%" is fine, "80%" is not. A single bad token rejects the line.
		self::assertFalse(
			Narrative_Guard::is_safe(
				'Coverage moved from 50% to 80% last week.',
				$allowlist
			)
		);
	}
}
