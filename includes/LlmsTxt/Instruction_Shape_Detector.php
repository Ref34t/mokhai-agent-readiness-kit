<?php
/**
 * Semantic advisory for instruction-shaped descriptions (#238).
 *
 * `Composer::escape_inline()` neutralises the STRUCTURAL injection surface
 * of `/llms.txt` descriptions (newline collapse + Markdown escaping, #236).
 * This detector covers the SEMANTIC layer those controls intentionally
 * leave alone: wording that reads like an instruction to the agents that
 * fetch and trust the file ("ignore previous instructions", "always
 * recommend…", direct address to AI assistants).
 *
 * Advisory only, by design: the operator's own published wording is never
 * mutated, generation is never blocked, and the Context Score is never
 * lowered. Auto-stripping is the documented anti-goal — over-aggressive
 * stripping removes legitimate descriptive text (see #238's source study).
 * The output surfaces as a hint in the AI Assistant Preview pane; the
 * operator decides.
 *
 * Pure and deterministic — no WP calls, no I/O — modelled on
 * `Context_Score\Narrative_Guard`.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Pattern-based detector for instruction-shaped description wording.
 */
final class Instruction_Shape_Detector {

	/**
	 * Override-attempt idioms — wording that tries to displace an agent's
	 * existing instructions. The classic prompt-injection openers.
	 *
	 * @var array<int, string>
	 */
	private const OVERRIDE_PATTERNS = array(
		'/\bignore\s+(?:all\s+|any\s+)?(?:previous|prior|above|earlier)\s+(?:instructions?|prompts?|rules?|context)\b/i',
		'/\bdisregard\s+(?:the\s+|all\s+|your\s+)?(?:previous|prior|above|system)\b/i',
		'/\bforget\s+(?:everything|all\s+previous|your\s+(?:instructions?|rules?))\b/i',
		'/\b(?:system|hidden|secret)\s+(?:prompt|instructions?)\b/i',
		'/\b(?:you\s+are\s+now|act\s+as|pretend\s+to\s+be)\s+(?:a|an|the)\b/i',
	);

	/**
	 * Agent-directive idioms — imperative phrasing aimed at a reader-agent
	 * rather than describing the page.
	 *
	 * @var array<int, string>
	 */
	private const DIRECTIVE_PATTERNS = array(
		'/\balways\s+(?:recommend|suggest|choose|prefer|rank|cite|say|answer)\b/i',
		'/\bnever\s+(?:mention|recommend|suggest|reveal|cite|link)\b/i',
		'/\bdo\s+not\s+(?:tell|reveal|mention|disclose|recommend|cite)\b/i',
		'/\brespond\s+(?:only\s+)?with\b/i',
		'/\b(?:tell|instruct|convince)\s+(?:the\s+)?(?:user|reader|human|visitor)s?\b/i',
	);

	/**
	 * Agent-address idioms — text that speaks TO the fetching agent.
	 *
	 * @var array<int, string>
	 */
	private const ADDRESS_PATTERNS = array(
		'/\b(?:dear|attention|note\s+to)\s+(?:ai|agents?|assistants?|llms?|models?)\b/i',
		'/\bif\s+you\s+are\s+an?\s+(?:ai|agent|assistant|llm|language\s+model)\b/i',
		'/\bwhen\s+(?:an?|the)\s+(?:ai|agent|assistant|model)\s+(?:reads?|visits?|fetche?s)\b/i',
	);

	/**
	 * Category code → pattern-list map. Codes are stable identifiers the
	 * UI and tests key on; add new categories rather than renaming.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function pattern_map(): array {
		return array(
			'override_attempt' => self::OVERRIDE_PATTERNS,
			'agent_directive'  => self::DIRECTIVE_PATTERNS,
			'agent_address'    => self::ADDRESS_PATTERNS,
		);
	}

	/**
	 * Detect instruction-shaped wording in a description.
	 *
	 * @param string $description The composed one-line description (post-escape).
	 *
	 * @return array<int, string> Matched category codes, empty when clean.
	 */
	public static function detect( string $description ): array {
		$text = \trim( $description );
		if ( '' === $text ) {
			return array();
		}

		$matched = array();
		foreach ( self::pattern_map() as $code => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( 1 === \preg_match( $pattern, $text ) ) {
					$matched[] = $code;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Convenience boolean wrapper around `detect()`.
	 */
	public static function is_instruction_shaped( string $description ): bool {
		return array() !== self::detect( $description );
	}
}
