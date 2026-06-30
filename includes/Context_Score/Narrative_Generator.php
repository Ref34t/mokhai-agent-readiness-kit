<?php
/**
 * Context Score narrative orchestrator (#11 / AgDR-0032).
 *
 * One call, one pair per sub-score. Builds the prompt from the breakdown,
 * dispatches via Client_Wrapper, parses the JSON, runs the per-line
 * Narrative_Guard, and falls back to Rule_Based_Narrative on any
 * failure — line-by-line on guard failure (mixed mode), wholesale on
 * client error / parse error / budget overrun (degraded mode).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

use Mokhai\Ai\Client_Wrapper;
use Mokhai\Ai\Provider;

\defined( 'ABSPATH' ) || exit;

/**
 * Single entry point: `generate( array $breakdown ): array`.
 *
 * Output shape (see AgDR-0032):
 *
 *   array{
 *     schema_version: int,
 *     mode: 'llm' | 'rule_based' | 'mixed',
 *     generated_at: string,                  // ISO-8601 UTC
 *     generation_duration_ms: int,           // wall-clock for the LLM round-trip
 *     degraded: bool,                        // true iff whole call fell back
 *     degraded_reason: string|null,
 *     sub_scores: array<string, array{why: string, fix: string, source: 'llm'|'rule_based'}>,
 *   }
 *
 * The $provider parameter is the same test seam used by Client_Wrapper —
 * tests inject a mock to avoid the network. Production passes null and
 * Client_Wrapper resolves the real WP AI Client provider internally.
 */
final class Narrative_Generator {

	/**
	 * Schema version of the narrative payload persisted inside the
	 * score-record. Bumped when adding fields / renaming fields. The
	 * service treats a mismatched version as a miss and recomputes —
	 * same defensive shape as Engine::BREAKDOWN_SCHEMA_VERSION.
	 *
	 * @var int
	 */
	public const NARRATIVE_SCHEMA_VERSION = 2;

	/**
	 * Hard wall-clock budget for the LLM round-trip, in milliseconds.
	 *
	 * Since #167 / AgDR-0051 the narrative is generated in a background cron
	 * job (`Service::do_generate_narrative`), NOT inside the user-facing
	 * recompute. The budget therefore no longer guards UX latency — it only
	 * trips on a pathologically hung provider. Measured generation is ~11-17s,
	 * so the ceiling is set generously above that; a call that still overshoots
	 * is discarded and we fall back to rule-based with
	 * `degraded_reason = 'budget_exceeded'`.
	 *
	 * @var int
	 */
	public const GENERATION_BUDGET_MS = 45_000;

	/**
	 * Hard per-line ceiling. Matches Rule_Based_Narrative::MAX_OUTPUT_CHARS
	 * so the LLM and fallback paths produce visually consistent rows.
	 *
	 * @var int
	 */
	public const MAX_OUTPUT_CHARS = 140;

	/**
	 * Static instruction block of the system prompt — see AgDR-0032 §
	 * "Single-call prompt". The per-sub-score JSON schema is appended at
	 * runtime by `system_prompt()` so the inventory tracks `Engine::WEIGHTS`
	 * instead of being restated here (#126).
	 *
	 * @var string
	 */
	private const SYSTEM_PROMPT_PREAMBLE = <<<'PROMPT'
You are a senior WordPress consultant. Explain a Mokhai Context Score
audit to an agency owner.

Output ONE pair per sub-score: a "why" explaining what the score reflects,
and a "fix" naming the single most useful next action.

Rules:
- Use ONLY facts present in the input breakdown (signals, reasons, values,
  weights). Do not invent plugin names, percentages, or capabilities.
- Each "why" and each "fix": at most 140 characters. Plain sentence. No
  markdown. No quotes. No emoji. No first-person. No hedging.
- If a sub-score is 100, the "fix" should suggest maintenance, not a
  remedial action.
- Output ONLY valid JSON, no preamble, no fences, no commentary.

Schema:
PROMPT;

	/**
	 * Assemble the full system prompt: the static preamble plus a JSON
	 * schema with one line per `Engine::WEIGHTS` entry.
	 *
	 * Built at runtime so adding an Nth sub-score needs zero edits here —
	 * the schema gains its line automatically (#126). Public so tests and
	 * WP-CLI debug surfaces can see exactly what the LLM is asked, matching
	 * the `build_user_prompt` precedent.
	 *
	 * @return string The complete system prompt.
	 */
	public static function system_prompt(): string {
		$lines = array();
		foreach ( \array_keys( Engine::WEIGHTS ) as $name ) {
			$lines[] = \sprintf( '  "%s": {"why": "...", "fix": "..."}', $name );
		}

		return self::SYSTEM_PROMPT_PREAMBLE . "\n{\n" . \implode( ",\n", $lines ) . "\n}";
	}

	/**
	 * Compose the narrative for the given breakdown.
	 *
	 * @param array<string, mixed> $breakdown Output of Engine::compute or Service::recompute_now (without the narrative slot).
	 * @param Provider|null        $provider  Optional AI provider override (test seam).
	 *
	 * @return array<string, mixed> Narrative payload (see class doc-block).
	 */
	public static function generate( array $breakdown, ?Provider $provider = null ): array {
		$now_iso = \gmdate( 'c' );

		// Path 1: AI client unavailable → full rule-based, no LLM call.
		if ( null === $provider && ! Client_Wrapper::has_ai_client() ) {
			return self::full_fallback( $breakdown, $now_iso, 'unconfigured', 0 );
		}

		// Path 2: LLM call.
		$prompt   = self::build_user_prompt( $breakdown );
		$start_us = (int) ( \microtime( true ) * 1_000_000 );

		// `temperature` deliberately omitted — reasoning-class models reject
		// it with a 400 (AgDR-0028). `max_tokens` 800 covers one ~70-token
		// field per Engine::WEIGHTS sub-score with reasoning headroom.
		$result = Client_Wrapper::generate(
			$prompt,
			array(
				'system'     => self::system_prompt(),
				'max_tokens' => 800,
			),
			$provider
		);

		$duration_ms = (int) \max( 0, ( (int) ( \microtime( true ) * 1_000_000 ) - $start_us ) / 1000 );

		if ( null !== $result->error_code() ) {
			return self::full_fallback( $breakdown, $now_iso, self::degraded_reason_from_error( (string) $result->error_code() ), $duration_ms );
		}

		if ( $duration_ms > self::GENERATION_BUDGET_MS ) {
			return self::full_fallback( $breakdown, $now_iso, 'budget_exceeded', $duration_ms );
		}

		$parsed = self::parse_response( (string) $result->content() );
		if ( null === $parsed ) {
			return self::full_fallback( $breakdown, $now_iso, 'parse_error', $duration_ms );
		}

		return self::merge_with_guard( $breakdown, $parsed, $now_iso, $duration_ms );
	}

	/**
	 * Build the user-prompt body. Public so tests + WP-CLI debug surfaces
	 * can see exactly what the LLM is asked.
	 *
	 * The body is the breakdown's `overall` + `sub_scores`, JSON-encoded
	 * compactly. We deliberately do NOT pass `schema_version` or any other
	 * metadata — the LLM doesn't need it and stripping it keeps the prompt
	 * focused on the facts the narrative is allowed to use.
	 *
	 * @param array<string, mixed> $breakdown
	 */
	public static function build_user_prompt( array $breakdown ): string {
		$payload = array(
			'overall'    => isset( $breakdown['overall'] ) ? (int) $breakdown['overall'] : 0,
			'sub_scores' => ( isset( $breakdown['sub_scores'] ) && \is_array( $breakdown['sub_scores'] ) )
				? $breakdown['sub_scores']
				: array(),
		);

		$encoded = \wp_json_encode( $payload, \JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return '{}';
		}

		return "Breakdown:\n" . $encoded;
	}

	/**
	 * Parse the model's JSON response.
	 *
	 * Tolerates a single layer of ```json fences (some models add them
	 * despite the "no fences" instruction). Returns the decoded
	 * associative array, or null on any failure.
	 *
	 * @return array<string, array{why: string, fix: string}>|null
	 */
	private static function parse_response( string $raw ): ?array {
		$trimmed = \trim( $raw );
		if ( '' === $trimmed ) {
			return null;
		}

		// Strip a single ```json … ``` fence if present.
		if ( 0 === \stripos( $trimmed, '```' ) ) {
			$first_newline = \strpos( $trimmed, "\n" );
			if ( false !== $first_newline ) {
				$trimmed = \substr( $trimmed, $first_newline + 1 );
			}
			$last_fence = \strrpos( $trimmed, '```' );
			if ( false !== $last_fence ) {
				$trimmed = \substr( $trimmed, 0, $last_fence );
			}
			$trimmed = \trim( $trimmed );
		}

		$decoded = \json_decode( $trimmed, true );
		if ( ! \is_array( $decoded ) ) {
			return null;
		}

		$out = array();
		foreach ( $decoded as $name => $entry ) {
			if ( ! \is_string( $name ) || ! \is_array( $entry ) ) {
				continue;
			}
			$why = isset( $entry['why'] ) && \is_string( $entry['why'] ) ? \trim( $entry['why'] ) : '';
			$fix = isset( $entry['fix'] ) && \is_string( $entry['fix'] ) ? \trim( $entry['fix'] ) : '';
			if ( '' === $why || '' === $fix ) {
				continue;
			}
			$out[ $name ] = array(
				'why' => self::sanitise_line( $why ),
				'fix' => self::sanitise_line( $fix ),
			);
		}

		return array() === $out ? null : $out;
	}

	/**
	 * Strip tags, collapse whitespace, truncate to the 140-char ceiling.
	 * Returns the cleaned string. Matches the AgDR-0028 pipeline shape.
	 */
	private static function sanitise_line( string $raw ): string {
		$text      = \wp_strip_all_tags( $raw );
		$collapsed = \preg_replace( '/\s+/', ' ', $text );
		$text      = \is_string( $collapsed ) ? \trim( $collapsed ) : '';

		if ( \strlen( $text ) > self::MAX_OUTPUT_CHARS ) {
			$text = \rtrim( \substr( $text, 0, self::MAX_OUTPUT_CHARS - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * Merge the parsed LLM output with the rule-based fallback per sub-score.
	 *
	 * For every sub-score in the breakdown:
	 *   - if the LLM produced a pair AND both lines pass the guard → keep LLM, source='llm'.
	 *   - otherwise → swap in the rule-based pair, source='rule_based'.
	 *
	 * If every sub-score ends up `rule_based`, the whole thing is treated
	 * as degraded with `parse_error` (the LLM responded but every line
	 * either was missing or failed the guard). Otherwise `mode` is `llm`
	 * (every line LLM) or `mixed` (some lines fell back).
	 *
	 * @param array<string, mixed>                            $breakdown
	 * @param array<string, array{why: string, fix: string}>  $parsed
	 *
	 * @return array<string, mixed>
	 */
	private static function merge_with_guard( array $breakdown, array $parsed, string $now_iso, int $duration_ms ): array {
		$sub_scores = ( isset( $breakdown['sub_scores'] ) && \is_array( $breakdown['sub_scores'] ) )
			? $breakdown['sub_scores']
			: array();

		$narrative_subs = array();
		$llm_kept       = 0;
		$total          = 0;

		foreach ( $sub_scores as $name => $sub ) {
			if ( ! \is_string( $name ) || ! \is_array( $sub ) ) {
				continue;
			}
			++$total;

			$rule_pair = Rule_Based_Narrative::compose_one( $name, $sub );

			$candidate = $parsed[ $name ] ?? null;
			if ( null === $candidate ) {
				$narrative_subs[ $name ] = array(
					'why'    => $rule_pair['why'],
					'fix'    => $rule_pair['fix'],
					'source' => 'rule_based',
				);
				continue;
			}

			$allowlist = Narrative_Guard::build_allowlist( $sub );
			$why       = (string) $candidate['why'];
			$fix       = (string) $candidate['fix'];

			$safe = Narrative_Guard::is_safe( $why, $allowlist )
				&& Narrative_Guard::is_safe( $fix, $allowlist );

			if ( ! $safe ) {
				$narrative_subs[ $name ] = array(
					'why'    => $rule_pair['why'],
					'fix'    => $rule_pair['fix'],
					'source' => 'rule_based',
				);
				continue;
			}

			$narrative_subs[ $name ] = array(
				'why'    => $why,
				'fix'    => $fix,
				'source' => 'llm',
			);
			++$llm_kept;
		}

		// Whole call effectively useless — degrade.
		if ( 0 === $llm_kept ) {
			return self::full_fallback( $breakdown, $now_iso, 'parse_error', $duration_ms );
		}

		$mode = $llm_kept === $total ? 'llm' : 'mixed';

		return array(
			'schema_version'         => self::NARRATIVE_SCHEMA_VERSION,
			'mode'                   => $mode,
			'generated_at'           => $now_iso,
			'generation_duration_ms' => $duration_ms,
			'degraded'               => false,
			'degraded_reason'        => null,
			'llm_pending'            => false,
			'sub_scores'             => $narrative_subs,
		);
	}

	/**
	 * Full fallback: every sub-score gets a rule-based pair, the payload
	 * is marked degraded with the given reason.
	 *
	 * @param array<string, mixed> $breakdown
	 * @return array<string, mixed>
	 */
	private static function full_fallback( array $breakdown, string $now_iso, string $reason, int $duration_ms, bool $llm_pending = false ): array {
		$rule_pairs     = Rule_Based_Narrative::compose( $breakdown );
		$narrative_subs = array();
		foreach ( $rule_pairs as $name => $pair ) {
			$narrative_subs[ $name ] = array(
				'why'    => $pair['why'],
				'fix'    => $pair['fix'],
				'source' => 'rule_based',
			);
		}

		return array(
			'schema_version'         => self::NARRATIVE_SCHEMA_VERSION,
			'mode'                   => 'rule_based',
			'generated_at'           => $now_iso,
			'generation_duration_ms' => $duration_ms,
			'degraded'               => true,
			'degraded_reason'        => $reason,
			'llm_pending'            => $llm_pending,
			'sub_scores'             => $narrative_subs,
		);
	}

	/**
	 * Immediate, LLM-free narrative for the synchronous recompute path
	 * (#167 / AgDR-0051). Returns the deterministic rule-based pairs marked
	 * `llm_pending = true` so the caller can write the cache instantly and
	 * the UI can show "AI narrative generating…" until the background
	 * `Service::do_generate_narrative` job replaces this with the LLM result.
	 *
	 * `degraded_reason = 'llm_pending'` is distinct from a genuine failure
	 * (`budget_exceeded`, `rate_limited`, …): it means "not attempted yet",
	 * not "attempted and fell back".
	 *
	 * @param array<string, mixed> $breakdown Engine::compute output.
	 * @return array<string, mixed> Narrative payload (rule-based, pending LLM).
	 */
	public static function pending( array $breakdown ): array {
		return self::full_fallback( $breakdown, \gmdate( 'c' ), 'llm_pending', 0, true );
	}

	/**
	 * Translate a Client_Wrapper error code into the narrative's
	 * `degraded_reason` vocabulary. They differ only at the edges
	 * (`unknown` → `parse_error`, since an unknown failure also
	 * surfaces as no usable content).
	 */
	private static function degraded_reason_from_error( string $error_code ): string {
		switch ( $error_code ) {
			case 'unconfigured':
				return 'unconfigured';
			case 'rate_limit':
				return 'rate_limit';
			case 'permanent':
				return 'permanent_error';
			case 'network':
				return 'network_error';
			default:
				return 'parse_error';
		}
	}
}
