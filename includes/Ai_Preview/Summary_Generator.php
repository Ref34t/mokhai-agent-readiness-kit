<?php
/**
 * Sample AI Summary generator for the AI Assistant Preview pane (#45 / AgDR-0046).
 *
 * Given a post's Markdown View, asks the cheap-tier LLM for a 2-3 sentence
 * preview of what an AI assistant would say about the page, then caches the
 * result in post-meta. Synchronous and admin-triggered (one post per click) —
 * NOT async via cron, so the wp-env "stuck pending" failure mode never
 * applies. Mirrors `Context_Score\Narrative_Generator`'s call discipline:
 * `temperature` omitted (reasoning models 400 on it — AgDR-0028), a hard
 * wall-clock budget, and a graceful degrade to a structured state on any
 * client failure.
 *
 * The input is the Markdown View, which already passed the #6
 * no-hallucination guard — so the summary is bounded to source-present
 * content by construction.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai_Preview;

use WPContext\Ai\Client_Wrapper;
use WPContext\Ai\Provider;

\defined( 'ABSPATH' ) || exit;

/**
 * Single entry point: `generate( int $post_id, string $markdown, ?Provider )`.
 *
 * Success return:
 *
 *   array{ text: string, generated_at: string, source: 'llm' }
 *
 * Degraded return (no cache write on this path; any prior good value is left
 * intact so the UI can still show the last successful summary):
 *
 *   array{ text: null, state: 'unconfigured'|'needs_retry'|'permanent'|'budget_exceeded'|'empty_input'|'empty_output', message: string }
 */
final class Summary_Generator {

	/**
	 * Post-meta key holding the cached summary text.
	 *
	 * @var string
	 */
	public const META_KEY_TEXT = '_agentready_ai_preview_summary';

	/**
	 * Post-meta key holding the ISO-8601 UTC generation timestamp.
	 *
	 * @var string
	 */
	public const META_KEY_GENERATED = '_agentready_ai_preview_summary_generated_gmt';

	/**
	 * Hard wall-clock budget for the LLM round-trip, in milliseconds. A
	 * successful call that overshoots is discarded and reported as a
	 * `budget_exceeded` degrade — same shape as Narrative_Generator.
	 *
	 * @var int
	 */
	public const GENERATION_BUDGET_MS = 10_000;

	/**
	 * Markdown input cap, in characters. The summary only needs the lead
	 * of the page to describe it; capping keeps the prompt cheap and well
	 * inside the model's context. Mirrors Description_Orchestrator's excerpt
	 * cap rationale.
	 *
	 * @var int
	 */
	public const MAX_INPUT_CHARS = 2000;

	/**
	 * Output cap, in characters. 2-3 sentences ≈ 360 chars; the cap is a
	 * safety rail against a runaway response, not the target length.
	 *
	 * @var int
	 */
	public const MAX_OUTPUT_CHARS = 400;

	/**
	 * System prompt. Frames the model AS the assistant a buyer is worried
	 * about, so the output reads like what ChatGPT / Claude would actually
	 * say. The "only what's present" rule keeps it honest — the Markdown
	 * input is already guard-checked, and this reinforces it.
	 *
	 * @var string
	 */
	private const SYSTEM_PROMPT = <<<'PROMPT'
You are an AI assistant describing a web page to a user who asked about it.

Given the page content below, write 2-3 plain sentences summarising what the
page is about and what a visitor would get from it.

Rules:
- Use ONLY information present in the provided content. Do not invent facts,
  figures, product names, or claims that are not in the text.
- Write in the third person about the page ("This page explains...").
- No markdown, no headings, no bullet points, no quotes, no emoji.
- If the content is too thin to describe, say so in one sentence.
- Output ONLY the summary text, no preamble and no commentary.
PROMPT;

	/**
	 * Generate (and cache) the Sample AI Summary for a post.
	 *
	 * @param int           $post_id  Post being previewed.
	 * @param string        $markdown The post's Markdown View (already guard-checked).
	 * @param Provider|null $provider Optional AI provider override (test seam).
	 *
	 * @return array<string, mixed> Success or degraded payload — see class doc-block.
	 */
	public static function generate( int $post_id, string $markdown, ?Provider $provider = null ): array {
		$markdown = \trim( $markdown );
		if ( '' === $markdown ) {
			return self::degrade(
				'empty_input',
				\__( 'This page has no Markdown View to summarise yet.', 'mokhai-agent-readiness-kit' )
			);
		}

		// Path 1: AI client unavailable → no call, structured hint. This is
		// a deliberate short-circuit: Client_Wrapper::generate() would also
		// return an 'unconfigured' Result, but checking here avoids building
		// the prompt and lets the UI show the "connect a provider" hint with
		// no round-trip. When a provider is injected (tests) we skip it.
		if ( null === $provider && ! Client_Wrapper::has_ai_client() ) {
			return self::degrade(
				'unconfigured',
				\__( 'Connect an AI provider to preview a model summary of this page.', 'mokhai-agent-readiness-kit' )
			);
		}

		// Path 2: LLM call.
		$prompt   = self::build_prompt( $markdown );
		$start_us = (int) ( \microtime( true ) * 1_000_000 );

		// `temperature` deliberately omitted (AgDR-0028). `max_tokens` 200
		// covers a 2-3 sentence reply with reasoning-model headroom.
		$result = Client_Wrapper::generate(
			$prompt,
			array(
				'system'     => self::SYSTEM_PROMPT,
				'max_tokens' => 200,
			),
			$provider
		);

		$duration_ms = (int) \max( 0, ( (int) ( \microtime( true ) * 1_000_000 ) - $start_us ) / 1000 );

		if ( null !== $result->error_code() ) {
			return self::degrade_from_error( (string) $result->error_code() );
		}

		if ( $duration_ms > self::GENERATION_BUDGET_MS ) {
			return self::degrade(
				'budget_exceeded',
				\__( 'The summary took too long to generate. Try again.', 'mokhai-agent-readiness-kit' )
			);
		}

		$text = self::sanitise_output( (string) $result->content() );
		if ( '' === $text ) {
			return self::degrade(
				'empty_output',
				\__( 'The model returned an empty summary. Try again.', 'mokhai-agent-readiness-kit' )
			);
		}

		$generated_at = \gmdate( 'c' );
		\update_post_meta( $post_id, self::META_KEY_TEXT, $text );
		\update_post_meta( $post_id, self::META_KEY_GENERATED, $generated_at );

		return array(
			'text'         => $text,
			'generated_at' => $generated_at,
			'source'       => 'llm',
		);
	}

	/**
	 * Read the cached summary for a post, or null if never generated.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{text: string, generated_at: string, source: 'llm'}|null
	 */
	public static function get_cached( int $post_id ): ?array {
		$text = \get_post_meta( $post_id, self::META_KEY_TEXT, true );
		if ( ! \is_string( $text ) || '' === \trim( $text ) ) {
			return null;
		}

		$generated_at = \get_post_meta( $post_id, self::META_KEY_GENERATED, true );

		return array(
			'text'         => $text,
			'generated_at' => \is_string( $generated_at ) ? $generated_at : '',
			'source'       => 'llm',
		);
	}

	/**
	 * Build the user-prompt body. Public so tests can assert the exact
	 * payload the model is asked to summarise.
	 *
	 * @param string $markdown The (already-trimmed) Markdown View.
	 */
	public static function build_prompt( string $markdown ): string {
		if ( \mb_strlen( $markdown ) > self::MAX_INPUT_CHARS ) {
			$markdown = \rtrim( \mb_substr( $markdown, 0, self::MAX_INPUT_CHARS ) );
		}

		return "Page content:\n\n" . $markdown;
	}

	/**
	 * Collapse the model output to a single clean paragraph and cap length.
	 */
	private static function sanitise_output( string $raw ): string {
		$text = \wp_strip_all_tags( $raw );
		$text = \preg_replace( '/\s+/', ' ', $text );
		$text = \is_string( $text ) ? \trim( $text ) : '';

		if ( \mb_strlen( $text ) > self::MAX_OUTPUT_CHARS ) {
			$text = \rtrim( \mb_substr( $text, 0, self::MAX_OUTPUT_CHARS - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * Map a Client_Wrapper error_code to a degrade payload.
	 */
	private static function degrade_from_error( string $error_code ): array {
		switch ( $error_code ) {
			case 'unconfigured':
				return self::degrade(
					'unconfigured',
					\__( 'Connect an AI provider to preview a model summary of this page.', 'mokhai-agent-readiness-kit' )
				);
			case 'permanent':
				return self::degrade(
					'permanent',
					\__( 'The AI provider rejected the request. Check the API key and model configuration.', 'mokhai-agent-readiness-kit' )
				);
			case 'rate_limit':
			case 'network':
			default:
				return self::degrade(
					'needs_retry',
					\__( 'The summary could not be generated right now. Try again in a moment.', 'mokhai-agent-readiness-kit' )
				);
		}
	}

	/**
	 * Shape a degraded return value.
	 *
	 * @return array{text: null, state: string, message: string}
	 */
	private static function degrade( string $state, string $message ): array {
		return array(
			'text'    => null,
			'state'   => $state,
			'message' => $message,
		);
	}
}
