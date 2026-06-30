<?php
/**
 * Per-sub-score anti-hallucination guard for the LLM narrative (#11 / AgDR-0032).
 *
 * Anti-hallucination guard (AgDR-0018 lineage) tailored to the narrower
 * failure surface of the Context Score narrative: two 140-char strings per
 * sub-score, against a 60-word structured breakdown. The common failure
 * modes are fabricated numbers and fabricated proper-noun sequences (plugin
 * names, product names, surface names). Both classes are catchable with a
 * token allowlist drawn from the breakdown itself.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure, deterministic guard. No side effects, no WP calls, no I/O.
 *
 * Usage:
 *
 *   $allowlist = Narrative_Guard::build_allowlist( $sub_score_entry );
 *   if ( Narrative_Guard::is_safe( $candidate_why, $allowlist )
 *        && Narrative_Guard::is_safe( $candidate_fix, $allowlist )
 *   ) {
 *       // keep the LLM line
 *   } else {
 *       // replace with Rule_Based_Narrative::compose_one(...)
 *   }
 */
final class Narrative_Guard {

	/**
	 * Cross-cutting Mokhai terms always permitted in any sub-score's
	 * narrative. Mirrors the surface area the rule-based templates
	 * reference (Context Profile, Site Health, WP-CLI commands, etc.) so
	 * the guard doesn't reject perfectly accurate LLM output that names
	 * a Mokhai-internal concept missing from a given sub-score's
	 * `signals`/`reasons` list.
	 *
	 * Lowercased on lookup, so the source casing is purely documentary.
	 *
	 * @var array<int, string>
	 */
	public const CROSS_CUTTING_ENTITIES = array(
		'Mokhai',
		'Context Profile',
		'Context Score',
		'Site Health',
		'AI Client',
		'WP AI Client',
		'Markdown Views',
		'LLMs Index',
		'JSON-LD',
		'Tools Context',
	);

	/**
	 * Build the per-sub-score allowlist.
	 *
	 * Output shape:
	 *
	 *   array{
	 *     numbers:  array<int, string>,  // numeric tokens, bare ("60") + suffixed ("60%", "60/100")
	 *     entities: array<int, string>,  // lowercased multi-word capitalised sequences
	 *   }
	 *
	 * Built from:
	 *   - The sub-score's own `value` and `weight`.
	 *   - Every numeric value in `signals` (stringified, then split on \D).
	 *   - Every \d+ run inside each `reasons` string.
	 *   - Every multi-word capitalised entity inside each `reasons` string.
	 *   - The cross-cutting entity allowlist (`CROSS_CUTTING_ENTITIES`).
	 *
	 * @param array<string, mixed> $sub Sub-score entry from the breakdown.
	 *
	 * @return array{numbers: array<int, string>, entities: array<int, string>}
	 */
	public static function build_allowlist( array $sub ): array {
		$numbers  = array();
		$entities = array();

		$value  = isset( $sub['value'] ) ? (int) $sub['value'] : null;
		$weight = isset( $sub['weight'] ) ? (int) $sub['weight'] : null;
		if ( null !== $value ) {
			$numbers[] = (string) $value;
		}
		if ( null !== $weight ) {
			$numbers[] = (string) $weight;
		}

		$signals = ( isset( $sub['signals'] ) && \is_array( $sub['signals'] ) )
			? $sub['signals']
			: array();
		foreach ( $signals as $signal_value ) {
			self::extract_numbers_from_value( $signal_value, $numbers );
		}

		$reasons = ( isset( $sub['reasons'] ) && \is_array( $sub['reasons'] ) )
			? $sub['reasons']
			: array();
		foreach ( $reasons as $reason ) {
			if ( ! \is_string( $reason ) ) {
				continue;
			}
			self::extract_numbers_from_string( $reason, $numbers );
			self::extract_entities_from_string( $reason, $entities );
		}

		foreach ( self::CROSS_CUTTING_ENTITIES as $entity ) {
			$entities[] = \strtolower( $entity );
		}

		$numbers_set = self::with_suffixes( \array_values( \array_unique( $numbers ) ) );
		$entities    = \array_values( \array_unique( $entities ) );

		return array(
			'numbers'  => $numbers_set,
			'entities' => $entities,
		);
	}

	/**
	 * True iff every numeric and multi-word capitalised token in $line is
	 * present in the allowlist.
	 *
	 * Tokens checked:
	 *   - Numbers: `\d+(?:%|/100)?` — bare or with %/{/100} suffix.
	 *   - Entities: `\b[A-Z][a-zA-Z]+(?:[\s/-][A-Z][a-zA-Z]+)+\b` —
	 *     multi-word capitalised sequences, with `/` and `-` joiners so
	 *     "/llms.txt"-style and "JSON-LD"-style references survive. Path
	 *     references like "/llms.txt" themselves are matched against the
	 *     cross-cutting allowlist via lowercased lookup.
	 *
	 * Sentence-starts are lowercased before the entity regex runs, so
	 * the leading capitalised verb of a sentence ("Approve", "Run",
	 * "Open") never joins its following Title-case word into a fake
	 * multi-word entity. Documented false-negative: a hallucinated
	 * brand name placed AT the start of a sentence ("Yoast SEO is …")
	 * slips through because preprocessing also lowercases its first
	 * letter. In practice the prompt drives explanatory phrasings, not
	 * brand-name openers, so the class is rare; we accept it as the
	 * cost of avoiding the sentence-start false-positive flood.
	 *
	 * Single-word capitalised tokens are NOT checked — too many false
	 * positives ("Critical", "Partial" — they're sentence starts, not
	 * entities). The risk class we care about is multi-word brand /
	 * product / surface names ("Yoast Premium", "Cloudflare Workers")
	 * which the regex catches.
	 *
	 * @param string                                                        $line      The candidate LLM line.
	 * @param array{numbers: array<int, string>, entities: array<int, string>} $allowlist From build_allowlist().
	 *
	 * @return bool
	 */
	public static function is_safe( string $line, array $allowlist ): bool {
		$numbers_allowed  = ( isset( $allowlist['numbers'] ) && \is_array( $allowlist['numbers'] ) )
			? $allowlist['numbers']
			: array();
		$entities_allowed = ( isset( $allowlist['entities'] ) && \is_array( $allowlist['entities'] ) )
			? $allowlist['entities']
			: array();

		// Numbers: bare digit runs with optional % or /100 suffix.
		\preg_match_all( '/\d+(?:%|\/100)?/', $line, $number_matches );
		foreach ( $number_matches[0] as $token ) {
			if ( ! \in_array( $token, $numbers_allowed, true ) ) {
				return false;
			}
		}

		// Entities: multi-word capitalised, with space / "-" / "/" joiners.
		// Sentence-starts are lowercased first so verb-style line openers
		// like "Approve LLM cleanup" don't get parsed as the entity
		// "Approve LLM".
		$prepared = self::lowercase_sentence_starts( $line );
		\preg_match_all(
			'/\b[A-Z][a-zA-Z]+(?:[\s\/\-][A-Z][a-zA-Z]+)+\b/',
			$prepared,
			$entity_matches
		);
		foreach ( $entity_matches[0] as $token ) {
			$lookup = \strtolower( (string) $token );
			if ( ! \in_array( $lookup, $entities_allowed, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lowercase the leading character of the input and the first
	 * character after every `. `, `? `, `! ` boundary so the entity
	 * regex doesn't treat sentence-starting verbs as the first token
	 * of a multi-word entity.
	 */
	private static function lowercase_sentence_starts( string $line ): string {
		// First non-whitespace character of the whole input.
		$line = \preg_replace_callback(
			'/^(\s*)([A-Z])/',
			static fn( array $m ): string => $m[1] . \strtolower( $m[2] ),
			$line
		) ?? $line;

		// First character after a sentence boundary.
		$line = \preg_replace_callback(
			'/([.!?]\s+)([A-Z])/',
			static fn( array $m ): string => $m[1] . \strtolower( $m[2] ),
			$line
		) ?? $line;

		return $line;
	}

	/**
	 * Walk a signal value (scalar / array) and append every digit run we
	 * find to $numbers (by reference). Booleans become "1"/"0" so an
	 * LLM saying "1 of 6 toggles" doesn't trip the guard.
	 *
	 * @param mixed                $value
	 * @param array<int, string>   $numbers
	 */
	private static function extract_numbers_from_value( $value, array &$numbers ): void {
		if ( \is_bool( $value ) ) {
			$numbers[] = $value ? '1' : '0';
			return;
		}
		if ( \is_int( $value ) || \is_float( $value ) ) {
			$numbers[] = (string) (int) $value;
			return;
		}
		if ( \is_string( $value ) ) {
			self::extract_numbers_from_string( $value, $numbers );
			return;
		}
		if ( \is_array( $value ) ) {
			foreach ( $value as $inner ) {
				self::extract_numbers_from_value( $inner, $numbers );
			}
		}
	}

	/**
	 * Extract digit runs from a string.
	 *
	 * @param array<int, string> $numbers
	 */
	private static function extract_numbers_from_string( string $text, array &$numbers ): void {
		if ( '' === $text ) {
			return;
		}
		if ( \preg_match_all( '/\d+/', $text, $m ) ) {
			foreach ( $m[0] as $digits ) {
				$numbers[] = (string) $digits;
			}
		}
	}

	/**
	 * Extract multi-word capitalised sequences from a string. Lowercased
	 * into the allowlist so the comparison is case-insensitive.
	 *
	 * @param array<int, string> $entities
	 */
	private static function extract_entities_from_string( string $text, array &$entities ): void {
		if ( '' === $text ) {
			return;
		}
		if ( \preg_match_all( '/\b[A-Z][a-zA-Z]+(?:[\s\/\-][A-Z][a-zA-Z]+)+\b/', $text, $m ) ) {
			foreach ( $m[0] as $entity ) {
				$entities[] = \strtolower( (string) $entity );
			}
		}
	}

	/**
	 * For every bare integer in the allowlist, also accept the "%" and
	 * "/100" forms. The breakdown only stores the bare integer
	 * (e.g. `coverage_pct => 80`) but a natural narrative will likely
	 * write "80%" — we want that phrasing to pass.
	 *
	 * @param array<int, string> $bare_numbers
	 * @return array<int, string>
	 */
	private static function with_suffixes( array $bare_numbers ): array {
		$out = array();
		foreach ( $bare_numbers as $n ) {
			$out[] = $n;
			$out[] = $n . '%';
			$out[] = $n . '/100';
		}
		return \array_values( \array_unique( $out ) );
	}
}
