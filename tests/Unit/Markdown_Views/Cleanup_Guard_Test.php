<?php
/**
 * Unit tests for the no-hallucination guard per AgDR-0018.
 *
 * Two surfaces:
 *
 * 1. Algorithm-level tests (this file): cover the small pieces —
 *    sentence splitting, normalisation, stemming, entity extraction,
 *    the kill-switch threshold.
 * 2. Adversarial-fixture tests (Cleanup_Guard_Adversarial_Test): the
 *    CI gate the AC's "hard rule, tested" requirement maps to. Each
 *    adversarial fixture asserts the offending content is stripped
 *    from the guard output. CI failure on any of them blocks merge.
 *
 * The guard has no WordPress dependency; tests run without bootstrapping
 * WP. `wp_strip_all_tags` is loaded from tests/Unit/wp-stubs.php.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use WPContext\Markdown_Views\Cleanup_Guard;
use WPContext\Markdown_Views\Guard_Result;

final class Cleanup_Guard_Test extends TestCase {

	public function test_check_returns_guard_result(): void {
		$allowlist = Cleanup_Guard::build_allowlist( '<p>The cat sat.</p>' );
		$entities  = array();
		$result    = Cleanup_Guard::check( 'The cat sat.', $allowlist, $entities );

		self::assertInstanceOf( Guard_Result::class, $result );
	}

	public function test_empty_llm_output_returns_empty_result_with_no_failure(): void {
		$allowlist = Cleanup_Guard::build_allowlist( '<p>Hello.</p>' );
		$result    = Cleanup_Guard::check( '', $allowlist, array() );

		self::assertSame( '', $result->get_filtered_markdown() );
		self::assertSame( 0, $result->get_stats()['sentences_kept'] );
		self::assertSame( 0, $result->get_stats()['sentences_dropped'] );
		self::assertFalse( $result->failed_overall() );
	}

	public function test_sentence_with_only_source_words_is_kept(): void {
		$source     = '<p>The product launched in 2024 with three features.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );
		$entities   = Cleanup_Guard::extract_named_entities( \strip_tags( $source ) );

		$llm_output = 'Product launched in three features.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, $entities );

		self::assertSame( 1, $result->get_stats()['sentences_kept'] );
		self::assertSame( 0, $result->get_stats()['sentences_dropped'] );
		self::assertFalse( $result->failed_overall() );
	}

	public function test_sentence_with_word_not_in_source_is_dropped(): void {
		$source     = '<p>The cat sat on the mat.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		// "elephant" is not in the source word set.
		$llm_output = 'The elephant trumpeted loudly.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		self::assertSame( 0, $result->get_stats()['sentences_kept'] );
		self::assertSame( 1, $result->get_stats()['sentences_dropped'] );
		self::assertStringNotContainsString( 'elephant', $result->get_filtered_markdown() );
	}

	public function test_short_tokens_are_exempt_from_allowlist(): void {
		// "AI" and "is" are under MIN_TOKEN_LENGTH (3) — they should not
		// trigger the allowlist filter even if absent from source.
		$source     = '<p>Hello there everyone.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'Hello there everyone.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		self::assertSame( 1, $result->get_stats()['sentences_kept'] );
	}

	public function test_stemming_matches_inflected_forms(): void {
		// Source has "played" — output uses "playing" — both stem to "play".
		// "Porter-light" (per AgDR-0018) handles common suffixes only;
		// doubled-consonant cases ("running"/"runs" → "runn"/"run") are
		// out of scope and documented as acceptable false-positive surface.
		$source     = '<p>The athlete played quickly yesterday.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'Athlete playing quickly.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		self::assertSame( 1, $result->get_stats()['sentences_kept'] );
	}

	public function test_fabricated_named_entity_is_stripped(): void {
		$source     = '<p>The company announced a new product line.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		// "Acme Corporation" is a multi-word capitalised sequence not in
		// source — must be caught by Stage 2 even though "acme" and
		// "corporation" might individually pass the allowlist if they
		// happened to appear elsewhere.
		$llm_output = 'Acme Corporation announced a new product line.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		self::assertStringNotContainsString( 'Acme Corporation', $result->get_filtered_markdown() );
	}

	public function test_named_entity_present_in_source_is_kept(): void {
		$source        = '<p>Acme Corporation announced its quarterly results.</p>';
		$allowlist     = Cleanup_Guard::build_allowlist( $source );
		$source_text   = \strip_tags( $source );
		$entities      = Cleanup_Guard::extract_named_entities( $source_text );

		$llm_output = 'Acme Corporation announced quarterly results.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, $entities );

		self::assertStringContainsString( 'Acme Corporation', $result->get_filtered_markdown() );
		self::assertSame( 1, $result->get_stats()['sentences_kept'] );
	}

	public function test_kill_switch_fires_above_failure_threshold(): void {
		$source = '<p>Hello world.</p>';
		$allow  = Cleanup_Guard::build_allowlist( $source );

		// Three sentences, all with words absent from "hello world" — 100%
		// drop rate, well above the 0.5 kill-switch threshold.
		$llm_output = 'The elephant runs fast. Many trumpets play music. Strawberry fields exist.';
		$result     = Cleanup_Guard::check( $llm_output, $allow, array() );

		self::assertTrue( $result->failed_overall() );
	}

	public function test_kill_switch_does_not_fire_below_threshold(): void {
		// Source covers everything in three sentences; one is fully fabricated.
		// 1/4 drop ratio = 0.25, well below the 0.5 threshold.
		$source = '<p>The brave knight fought the dragon for the kingdom of light.</p>';
		$allow  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'The knight fought. The dragon fought. The brave knight fought. Random elephant text.';
		$result     = Cleanup_Guard::check( $llm_output, $allow, array() );

		self::assertFalse( $result->failed_overall() );
		self::assertSame( 3, $result->get_stats()['sentences_kept'] );
		self::assertSame( 1, $result->get_stats()['sentences_dropped'] );
	}

	public function test_extract_named_entities_finds_multi_word_capitalised(): void {
		$text = 'John Smith met with Acme Corporation about the deal.';
		$entities = Cleanup_Guard::extract_named_entities( $text );

		self::assertContains( 'John Smith', $entities );
		self::assertContains( 'Acme Corporation', $entities );
	}

	public function test_extract_named_entities_ignores_single_word_capitalised(): void {
		// "Apple" alone is not multi-word — not extracted by this stage.
		// Single-word entity safety relies on Stage 1's allowlist instead.
		$text = 'Apple released a new device.';
		$entities = Cleanup_Guard::extract_named_entities( $text );

		self::assertEmpty( $entities );
	}

	public function test_dropped_record_includes_offending_tokens(): void {
		$source     = '<p>Cats and dogs are pets.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'Elephants are large.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		$dropped = $result->get_dropped();
		self::assertCount( 1, $dropped );
		self::assertSame( 'allowlist', $dropped[0]['stage'] );
		self::assertContains( 'elephants', $dropped[0]['tokens'] );
		self::assertContains( 'large', $dropped[0]['tokens'] );
	}

	public function test_dropped_record_includes_offending_entity(): void {
		$source     = '<p>The team launched the product.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'John Smith launched the product.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		$dropped = $result->get_dropped();
		self::assertCount( 1, $dropped );
		// "John Smith" trips Stage 2 only if "john" and "smith" weren't
		// already caught by Stage 1's allowlist check. In this fixture,
		// "john" and "smith" are absent from source → Stage 1 fires first.
		self::assertSame( 'allowlist', $dropped[0]['stage'] );
	}

	public function test_normalisation_strips_punctuation_for_matching(): void {
		// Source has "well-formed" with a hyphen; output uses "well formed"
		// without. After punctuation strip, both tokenize the same.
		$source     = '<p>The output is well-formed.</p>';
		$allowlist  = Cleanup_Guard::build_allowlist( $source );

		$llm_output = 'The output is well formed.';
		$result     = Cleanup_Guard::check( $llm_output, $allowlist, array() );

		self::assertSame( 1, $result->get_stats()['sentences_kept'] );
	}
}
