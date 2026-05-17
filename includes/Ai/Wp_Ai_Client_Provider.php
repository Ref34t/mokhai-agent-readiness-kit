<?php
/**
 * Real WP AI Client provider — the production implementation of the
 * `Provider` test seam declared in AgDR-0003.
 *
 * Wraps `wp_ai_client_prompt()`, which is shipped in WP core 7.0+ and
 * as a backport in the `wordpress/wp-ai-client` package on 6.x. The
 * function's return shape is `string|WP_Error`; this class translates
 * `WP_Error` returns into the typed exceptions
 * (`Network_Error` / `Rate_Limit_Error`) the rest of the wrapper
 * expects, so the retry + deferred-retry logic in
 * `Client_Wrapper::generate()` works unchanged whether the caller
 * passes a mock provider (tests) or this real one (production).
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Stateless adapter. Each `generate()` call constructs a fresh prompt
 * builder, applies a small fixed set of options (temperature,
 * max_tokens), terminates with `generate_text()`, and translates the
 * outcome to either a plain string return or a thrown error.
 *
 * Error-classification heuristic for v0.1: the WP AI Client returns
 * `WP_Error` with a coarse code (`prompt_builder_error` /
 * `prompt_prevented`) plus the underlying provider's message string.
 * We pattern-match common rate-limit indicators ("rate limit", "429",
 * "quota") on the message to classify as `Rate_Limit_Error`; everything
 * else maps to `Network_Error`, which queues a deferred retry — the
 * safe default. Finer-grained classification (auth errors, content
 * filter rejections, etc.) lands as v0.1.x once production traffic
 * shows which message shapes need distinct handling.
 */
final class Wp_Ai_Client_Provider implements Provider {

	/**
	 * Message-substring patterns (case-insensitive) that classify an
	 * outcome as a rate-limit. Anything not matching here falls through
	 * to `Network_Error` and the deferred-retry path.
	 *
	 * @var array<int, string>
	 */
	private const RATE_LIMIT_MARKERS = array(
		'rate limit',
		'rate-limit',
		'rate_limit',
		'429',
		'quota',
		'too many requests',
		'exceeded',
	);

	/**
	 * Send the prompt to the WP AI Client and return the generated text.
	 *
	 * Options recognised:
	 *   - `temperature` (float, 0.0..1.0) — passed to
	 *     `using_temperature()`.
	 *   - `max_tokens`  (int)             — passed to
	 *     `using_max_tokens()`.
	 *   - `system`      (string)          — passed to
	 *     `using_system_instruction()`.
	 *
	 * Unknown options are silently ignored — the WP AI Client surface
	 * is broader than this wrapper exposes, and v0.1 keeps the option
	 * map narrow to avoid leaking provider-specific knobs into our
	 * call sites.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @throws Network_Error    On any non-rate-limit failure.
	 * @throws Rate_Limit_Error On a rate-limit / quota outcome.
	 */
	public function generate( string $prompt, array $options = array() ): string {
		if ( ! \function_exists( 'wp_ai_client_prompt' ) ) {
			// The caller is expected to gate on `Client_Wrapper::has_ai_client()`
			// before invoking, but defend here too: a missing entry point
			// is structurally identical to a network outage from our
			// perspective. Queue deferred retry.
			throw new Network_Error( 'wp_ai_client_prompt() is not available.' );
		}

		$builder = \wp_ai_client_prompt( $prompt );

		if ( isset( $options['temperature'] ) && \is_numeric( $options['temperature'] ) ) {
			$builder = $builder->using_temperature( (float) $options['temperature'] );
		}

		if ( isset( $options['max_tokens'] ) && \is_numeric( $options['max_tokens'] ) ) {
			$builder = $builder->using_max_tokens( (int) $options['max_tokens'] );
		}

		if ( isset( $options['system'] ) && \is_string( $options['system'] ) && '' !== $options['system'] ) {
			$builder = $builder->using_system_instruction( $options['system'] );
		}

		$result = $builder->generate_text();

		if ( \is_wp_error( $result ) ) {
			$this->throw_for_wp_error( $result );
		}

		return (string) $result;
	}

	/**
	 * Classify a `WP_Error` from `generate_text()` and throw the
	 * matching typed exception. Never returns.
	 *
	 * @throws Network_Error
	 * @throws Rate_Limit_Error
	 */
	private function throw_for_wp_error( \WP_Error $error ): void {
		// Defensive escape: provider error messages can contain
		// quoted user-supplied content (e.g. a model name from a
		// request log). Escape at the throw site so any handler
		// that surfaces the message via wp_die() / admin_notices
		// renders it safely. The WPCS ExceptionNotEscaped sniff
		// also requires the escape call at the throw point itself.
		$raw   = (string) $error->get_error_message();
		$lower = \strtolower( $raw );

		foreach ( self::RATE_LIMIT_MARKERS as $marker ) {
			if ( false !== \strpos( $lower, $marker ) ) {
				throw new Rate_Limit_Error( \esc_html( $raw ) );
			}
		}

		throw new Network_Error( \esc_html( $raw ) );
	}
}
