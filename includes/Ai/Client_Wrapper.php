<?php
/**
 * WP AI Client wrapper — shared call surface for #6 / #8 / #11.
 *
 * Implements the contract described in AgDR-0003: a static `generate()`
 * entry point returning a `Result` value object, immediate retry on network
 * errors, deferred retry via wp_schedule_single_event on rate-limit / repeat
 * network failures.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Static facade over WP AI Client.
 *
 * Three failure modes are encoded in the Result's error_code:
 *   - 'unconfigured'  — WP AI Client unavailable; caller gets the
 *                       deterministic fallback path.
 *   - 'network'       — first attempt network error AND immediate retry
 *                       also failed; a deferred retry is queued.
 *   - 'rate_limit'    — provider rate-limited; deferred retry queued.
 *
 * In all failure paths the caller receives `$result->needs_retry() === true`
 * (except 'unconfigured', which is the steady-state degrade) so it can mark
 * the post / score row as needs-retry.
 */
final class Client_Wrapper {

	/**
	 * Deferred-retry cron action name. Public so callers and tests can
	 * register their own listeners against the same action.
	 *
	 * @var string
	 */
	public const RETRY_ACTION = 'wpctx_ai_retry';

	/**
	 * Number of attempts per generate() call (initial + 1 immediate retry).
	 * Network errors burn an attempt; rate-limit returns immediately.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 2;

	/**
	 * Deferred-retry delay in seconds (5 minutes — see AgDR-0003).
	 *
	 * @var int
	 */
	private const RETRY_DELAY_SECONDS = 300;

	/**
	 * Whether the WP AI Client is available.
	 *
	 * Detection target is the `wp_ai_client_prompt()` entry point, which
	 * ships in WP core 7.0+ natively and as a backport in the
	 * `wordpress/wp-ai-client` package for 6.x. Earlier symbols
	 * (`wp_ai_client()` function and `WP_AI_Client` class) were
	 * pre-release names that did not survive into the final API and
	 * are not consulted.
	 *
	 * A "configured" client may still fail at provider-call time if no
	 * API credentials are saved or the chosen model is unavailable —
	 * those failures route through `Wp_Ai_Client_Provider`'s WP_Error
	 * classification and end up on the deferred-retry path. Detection
	 * here is "the entry point is callable", not "a generation will
	 * succeed".
	 */
	public static function has_ai_client(): bool {
		return \function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Generate text via the WP AI Client.
	 *
	 * @param string        $prompt   The prompt to send.
	 * @param array         $options  Provider-specific options (model, max_tokens, etc.).
	 * @param Provider|null $provider Optional provider override — used by
	 *                                tests to inject a mock. Defaults to
	 *                                the real WP AI Client when null.
	 *
	 * @return Result Value object describing the outcome — see class doc-block.
	 */
	public static function generate( string $prompt, array $options = array(), ?Provider $provider = null ): Result {
		if ( null === $provider && ! self::has_ai_client() ) {
			return new Result( false, false, null, 'unconfigured' );
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			++$attempt;

			try {
				$content = self::call_provider( $prompt, $options, $provider );
				return new Result( true, false, $content, null );

			} catch ( Rate_Limit_Error $e ) {
				// No immediate retry on rate-limit — go straight to deferred.
				self::queue_deferred_retry( $prompt, $options );
				return new Result( false, true, null, 'rate_limit' );

			} catch ( Network_Error $e ) {
				$last_error = 'network';
				// Loop continues — immediate retry within the same request.
				continue;

			} catch ( \Throwable $e ) {
				$last_error = 'unknown';
				break;
			}
		}

		// Exhausted in-request attempts. Queue deferred retry + return fallback.
		self::queue_deferred_retry( $prompt, $options );
		return new Result( false, true, null, $last_error ?? 'unknown' );
	}

	/**
	 * Single provider call. Separated so the test seam ($provider) is the
	 * only place that touches the network.
	 *
	 * @param string        $prompt   The prompt.
	 * @param array         $options  Options.
	 * @param Provider|null $provider Injected provider, or null for the real client.
	 *
	 * @throws Network_Error    On transient network failure.
	 * @throws Rate_Limit_Error On provider rate-limit.
	 *
	 * @return string The generated content.
	 */
	private static function call_provider( string $prompt, array $options, ?Provider $provider ): string {
		if ( null === $provider ) {
			$provider = new Wp_Ai_Client_Provider();
		}

		return $provider->generate( $prompt, $options );
	}

	/**
	 * Register the cron-action handler. Called once from Main::register_hooks.
	 */
	public static function register_hooks(): void {
		\add_action( self::RETRY_ACTION, array( self::class, 'handle_retry' ), 10, 1 );
	}

	/**
	 * Deferred-retry cron handler.
	 *
	 * Per AgDR-0003 this is a no-op stub for v0.1 — #6 / #8 / #11 will
	 * register their own listeners on the same action with module-scoped
	 * re-generation logic. Keeping a registered (no-op) handler here means
	 * the action is always discoverable via wp_action_hooks_list, and the
	 * cron event always fires even if no module is loaded.
	 *
	 * @param array $context Retry context (prompt + options as queued).
	 */
	public static function handle_retry( array $context ): void {
		// Intentional no-op. Argument kept on the signature so future module
		// listeners share a stable parameter shape.
		unset( $context );
	}

	/**
	 * Queue a deferred retry. Idempotent — won't double-queue an identical
	 * (prompt, options) pair if one is already pending.
	 *
	 * @param string $prompt  The prompt to retry.
	 * @param array  $options The options to retry with.
	 */
	private static function queue_deferred_retry( string $prompt, array $options ): void {
		$args = array(
			array(
				'prompt'  => $prompt,
				'options' => $options,
			),
		);

		if ( false === \wp_next_scheduled( self::RETRY_ACTION, $args ) ) {
			\wp_schedule_single_event( \time() + self::RETRY_DELAY_SECONDS, self::RETRY_ACTION, $args );
		}
	}
}
