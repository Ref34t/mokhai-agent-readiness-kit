<?php
/**
 * Context Score orchestration (#9 / AgDR-0030).
 *
 * Owns the `agentready_context_score_cache` option, the cron registrations,
 * and the debounced recompute on `agentready_context_profile_saved`.
 * Composition itself is delegated to the pure `Engine` and the WP-bridge
 * `Signal_Collector` so this file stays focused on side effects.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Service::get_breakdown() / recompute_now() / register_hooks() — the public
 * surface consumed by the WP-CLI command, the #10 admin REST endpoint
 * (when it lands), and the cron callbacks.
 */
final class Service {

	/**
	 * Option key holding the cached breakdown. Non-autoloaded — the score is
	 * consumed by WP-CLI + the (future) #10 admin panel, never on the
	 * agent-facing hot path. Mirrors AgDR-0022's CACHE_OPTION shape.
	 *
	 * @var string
	 */
	public const CACHE_OPTION = 'agentready_context_score_cache';

	/**
	 * Cron action fired by the debounced single event after the Context
	 * Profile is saved.
	 *
	 * @var string
	 */
	public const RECOMPUTE_ACTION = 'agentready_context_score_recompute';

	/**
	 * Cron action fired daily as the freshness backstop.
	 *
	 * @var string
	 */
	public const DAILY_RECOMPUTE_ACTION = 'agentready_context_score_daily_recompute';

	/**
	 * Debounce window between trigger and async recompute. Matches AgDR-0023's
	 * 5-second window so a profile-save that ripples through both /llms.txt
	 * and the Context Score doesn't fire two parallel recomputes on different
	 * cadences.
	 *
	 * @var int
	 */
	public const DEBOUNCE_DELAY = 5;

	/**
	 * Cache payload schema version. Bumped when the persisted breakdown shape
	 * changes. Engine::BREAKDOWN_SCHEMA_VERSION is the in-memory shape; this
	 * is the on-disk schema version — they happen to track 1:1 in v0.1 but
	 * are separated so a future change to one doesn't force the other.
	 *
	 * Version history:
	 *   1 — initial shape from #9 / AgDR-0030 (overall + sub_scores).
	 *   2 — adds the LLM narrative slot from #11 / AgDR-0032. Old payloads
	 *       read as null and trigger a fresh recompute on first access.
	 *   3 — adds the `multi_channel_discovery` sub-score from #22 /
	 *       AgDR-0043. Old payloads (v2 shape, 6 sub-scores) read as null
	 *       and trigger a fresh recompute so the new sub-score is populated
	 *       on first access after upgrade.
	 *
	 * @var int
	 */
	public const CACHE_SCHEMA_VERSION = 3;

	/**
	 * Wire the WordPress hooks owned by this service.
	 *
	 * Called once from `Main::register_hooks()`. Subscribes the cron callbacks
	 * and the profile-saved listener.
	 */
	public static function register_hooks(): void {
		\add_action( self::RECOMPUTE_ACTION, array( self::class, 'do_recompute' ) );
		\add_action( self::DAILY_RECOMPUTE_ACTION, array( self::class, 'do_recompute' ) );

		\add_action( 'agentready_context_profile_saved', array( self::class, 'schedule_recompute' ) );
	}

	/**
	 * Register the daily backstop. Called from `Main::on_activate()`.
	 *
	 * Idempotent — safe to call on every reactivation.
	 */
	public static function schedule_daily_recompute(): void {
		if ( false === \wp_next_scheduled( self::DAILY_RECOMPUTE_ACTION ) ) {
			\wp_schedule_event(
				\time() + \DAY_IN_SECONDS,
				'daily',
				self::DAILY_RECOMPUTE_ACTION
			);
		}
	}

	/**
	 * Clear both scheduled cron events. Called from `Main::on_deactivate()`.
	 * Persistent cache option is intentionally preserved per AgDR-0015's
	 * "deactivation must be lossless" rule.
	 */
	public static function clear_scheduled_recomputes(): void {
		\wp_clear_scheduled_hook( self::RECOMPUTE_ACTION );
		\wp_clear_scheduled_hook( self::DAILY_RECOMPUTE_ACTION );
	}

	/**
	 * Schedule a debounced async recompute one window from now.
	 *
	 * Two behaviours bundled here (mirrors `LlmsTxt\Service::schedule_regen()`
	 * post-#107):
	 *
	 * 1. **Debounce / coalesce.** A second call inside the debounce window
	 *    finds a future-scheduled event and noops, so a burst of profile
	 *    saves only triggers one recompute.
	 * 2. **Stale-event recovery.** If a previously-scheduled event is
	 *    still sitting in the cron queue with a past timestamp (the cron
	 *    didn't fire it — common on wp-env without traffic, or on any
	 *    site where cron failed for a window), the old event is cleared
	 *    before a fresh one is scheduled. Without this, WP de-dups
	 *    `wp_schedule_single_event` against the stale entry and the new
	 *    schedule is silently dropped, leaving the recompute never to
	 *    fire — Context Score would freeze at the previous breakdown
	 *    until the daily backstop kicks in or someone runs
	 *    `wp ai-readiness-kit context-score recompute` manually.
	 *    See Ref34t/agentready#115 (sibling of #103).
	 *
	 * Extra arguments passed by `do_action()` (e.g. the profile arrays
	 * from `agentready_context_profile_saved`) are intentionally ignored —
	 * `recompute_now()` reads the current state at run time.
	 */
	public static function schedule_recompute(): void {
		$existing = \wp_next_scheduled( self::RECOMPUTE_ACTION );

		// Future event already scheduled — debounce is working, noop.
		if ( false !== $existing && $existing > \time() ) {
			return;
		}

		// Stale past-timestamp event still in the queue — clear it so the
		// fresh schedule below isn't silently de-duped by WP. See #115.
		if ( false !== $existing ) {
			\wp_clear_scheduled_hook( self::RECOMPUTE_ACTION );
		}

		\wp_schedule_single_event(
			\time() + self::DEBOUNCE_DELAY,
			self::RECOMPUTE_ACTION
		);
	}

	/**
	 * Cron callback for both `RECOMPUTE_ACTION` and `DAILY_RECOMPUTE_ACTION`.
	 */
	public static function do_recompute(): void {
		self::recompute_now();
	}

	/**
	 * Synchronous recompute used by the cron callbacks and the WP-CLI command.
	 *
	 * Always writes the cache option before returning. Returns the full
	 * payload so callers that need to display the result (CLI, REST) don't
	 * have to read the option back.
	 *
	 * @return array<string, mixed>
	 */
	public static function recompute_now(): array {
		$start_us = (int) ( \microtime( true ) * 1_000_000 );

		$signals   = Signal_Collector::collect();
		$breakdown = Engine::compute( $signals );

		// Narrative is generated against the just-computed breakdown so the
		// LLM (or rule-based fallback) and the numeric breakdown can never
		// drift apart inside a single cache row. The generator absorbs all
		// failure modes — LLM unavailable, rate-limited, parse failure,
		// budget overrun — and always returns a usable shape per AgDR-0032.
		$narrative = Narrative_Generator::generate( $breakdown );

		$duration_ms = (int) max( 0, ( (int) ( \microtime( true ) * 1_000_000 ) - $start_us ) / 1000 );

		$payload = array(
			'schema_version'        => self::CACHE_SCHEMA_VERSION,
			'computed_at'           => \gmdate( 'c' ),
			'recompute_duration_ms' => $duration_ms,
			'overall'               => (int) $breakdown['overall'],
			'sub_scores'            => $breakdown['sub_scores'],
			'narrative'             => $narrative,
		);

		\update_option( self::CACHE_OPTION, $payload, 'no' );

		return $payload;
	}

	/**
	 * Public reader. Returns the cached breakdown, or null when no cache is
	 * present or the stored schema version doesn't match the current code.
	 *
	 * Mismatched schema versions are treated as a miss (same defensive
	 * pattern AgDR-0022 uses) — a future format bump can ship without a
	 * destructive migration.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_breakdown(): ?array {
		$cache = \get_option( self::CACHE_OPTION, null );
		if ( ! is_array( $cache ) ) {
			return null;
		}

		$version = isset( $cache['schema_version'] ) ? (int) $cache['schema_version'] : 0;
		if ( self::CACHE_SCHEMA_VERSION !== $version ) {
			return null;
		}

		return $cache;
	}

	/**
	 * Drop the cached breakdown. Exposed for WP-CLI (`wp context audit reset`)
	 * and for the integration tests' setUp paths.
	 */
	public static function invalidate(): void {
		\delete_option( self::CACHE_OPTION );
	}
}
