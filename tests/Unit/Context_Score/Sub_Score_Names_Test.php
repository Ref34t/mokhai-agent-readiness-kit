<?php
/**
 * Unit tests for `Mokhai\Context_Score\Sub_Score_Names` and the
 * WEIGHTS-driven copy surfaces that consume it (#126).
 *
 * The single-source-of-truth contract: the admin subtitle and the LLM
 * `system_prompt()` schema both iterate `Engine::WEIGHTS` rather than
 * restating the sub-score inventory. These tests assert:
 *   - `label()` returns a non-empty label for every current weight, and a
 *     `_`→space fallback for an unknown key (so an Nth weight renders even
 *     before it gets a dedicated `case`).
 *   - `all_labels()` is keyed identically to `Engine::WEIGHTS`.
 *   - The admin subtitle enumerates every sub-score label + the live count.
 *   - The narrative `system_prompt()` schema contains every WEIGHTS key.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Context_Score;

use PHPUnit\Framework\TestCase;
use Mokhai\Admin\Context_Score_Page;
use Mokhai\Context_Score\Engine;
use Mokhai\Context_Score\Narrative_Generator;
use Mokhai\Context_Score\Sub_Score_Names;

final class Sub_Score_Names_Test extends TestCase {

	public function test_label_returns_a_non_empty_label_for_every_weight(): void {
		foreach ( \array_keys( Engine::WEIGHTS ) as $name ) {
			$label = Sub_Score_Names::label( $name );
			self::assertNotSame( '', $label, "Missing label for sub-score '{$name}'." );
		}
	}

	public function test_unknown_name_falls_back_to_humanised_machine_name(): void {
		// An as-yet-unlisted weight still renders as readable text — this is
		// what makes adding an Nth sub-score a zero-copy-change operation.
		self::assertSame( 'brand new axis', Sub_Score_Names::label( 'brand_new_axis' ) );
	}

	public function test_all_labels_is_keyed_identically_to_weights(): void {
		self::assertSame(
			\array_keys( Engine::WEIGHTS ),
			\array_keys( Sub_Score_Names::all_labels() )
		);
	}

	public function test_joined_labels_contains_every_label(): void {
		$joined = Sub_Score_Names::joined_labels();
		foreach ( Sub_Score_Names::all_labels() as $label ) {
			self::assertStringContainsString( $label, $joined );
		}
	}

	public function test_admin_subtitle_enumerates_every_sub_score_and_the_live_count(): void {
		$subtitle = Context_Score_Page::subtitle();

		self::assertStringContainsString( (string) \count( Engine::WEIGHTS ), $subtitle );
		foreach ( Sub_Score_Names::all_labels() as $label ) {
			self::assertStringContainsString( $label, $subtitle );
		}
	}

	public function test_system_prompt_schema_contains_every_weights_key(): void {
		$prompt = Narrative_Generator::system_prompt();

		foreach ( \array_keys( Engine::WEIGHTS ) as $name ) {
			self::assertStringContainsString( "\"{$name}\"", $prompt );
		}
	}

	public function test_system_prompt_keeps_the_static_instruction_preamble(): void {
		$prompt = Narrative_Generator::system_prompt();

		self::assertStringContainsString( 'senior WordPress consultant', $prompt );
		self::assertStringContainsString( 'Output ONLY valid JSON', $prompt );
		self::assertStringContainsString( 'Schema:', $prompt );
	}
}
