<?php
/**
 * Context Score orchestration (#9 / AgDR-0030).
 *
 * Owns the `mokhai_context_score_cache` option, the cron registrations,
 * and the debounced recompute on `mokhai_context_profile_saved`.
 * Composition itself is delegated to the pure `Engine` and the WP-bridge
 * `Signal_Collector` so this file stays focused on side effects.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

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
	public const CACHE_OPTION = 'mokhai_context_score_cache';

	/**
	 * Cron action fired by the debounced single event after the Context
	 * Profile is saved.
	 *
	 * @var string
	 */
	public const RECOMPUTE_ACTION = 'mokhai_context_score_recompute';

	/**
	 * Cron action fired daily as the freshness backstop.
	 *
	 * @var string
	 */
	public const DAILY_RECOMPUTE_ACTION = 'mokhai_context_score_daily_recompute';

	/**
	 * Cron action that generates the LLM narrative off the recompute critical
	 * path (#167 / AgDR-0051). `recompute_now()` writes the score with a
	 * rule-based `llm_pending` narrative and schedules this single event; the
	 * `do_generate_narrative` callback runs the LLM call and merges the result
	 * back into the cache without re-running the score.
	 *
	 * @var string
	 */
	public const NARRATIVE_ACTION = 'mokhai_context_score_narrative';

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
	 *   4 — adds the additive parallel `reason_keys` array per sub-score from
	 *       #139 / AgDR-0047 (translatable reason codes). Old payloads (v3
	 *       shape, no `reason_keys`) read as null and recompute on first
	 *       access so the admin UI can localise the reasons.
	 *   5 — renames the `md_conversion_quality` signal key `cleanup_threshold`
	 *       → `md_quality_threshold` and drops `llm_cleanup_enabled` from the
	 *       `integration_health` signals, as the Markdown Views cleanup pass is
	 *       retired in #153 / AgDR-0049. Old payloads recompute on first access
	 *       so the admin UI never renders the stale signal key.
	 *   6 — the narrative slot gains an `llm_pending` flag and is generated
	 *       asynchronously (#167 / AgDR-0051). Old payloads (v5, synchronous
	 *       narrative, no `llm_pending`) read as null and recompute on first
	 *       access, scheduling the background narrative job.
	 *   7 — the `md_conversion_quality` sub-score now samples rendered bodies
	 *       and folds empty/noise deductions into its value, gaining
	 *       `empty_pct`/`noisy_pct`/`sampled`/`worst_urls` signals (#255 /
	 *       AgDR-0064). The bump is load-bearing: without it every existing
	 *       install keeps serving its stale pre-#255 breakdown (and the new
	 *       deductions never reach users) until the cron interval elapses.
	 *
	 * @var int
	 */
	public const CACHE_SCHEMA_VERSION = 7;

	/**
	 * Wire the WordPress hooks owned by this service.
	 *
	 * Called once from `Main::register_hooks()`. Subscribes the cron callbacks
	 * and the profile-saved listener.
	 */
	public static function register_hooks(): void {
		\add_action( self::RECOMPUTE_ACTION, array( self::class, 'do_recompute' ) );
		\add_action( self::DAILY_RECOMPUTE_ACTION, array( self::class, 'do_recompute' ) );
		\add_action( self::NARRATIVE_ACTION, array( self::class, 'do_generate_narrative' ) );

		\add_action( 'mokhai_context_profile_saved', array( self::class, 'schedule_recompute' ) );
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
		\wp_clear_scheduled_hook( self::NARRATIVE_ACTION );
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
	 *    `wp mokhai context-score recompute` manually.
	 *    See Ref34t/mokhai-agent-readiness-kit#115 (sibling of #103).
	 *
	 * Extra arguments passed by `do_action()` (e.g. the profile arrays
	 * from `mokhai_context_profile_saved`) are intentionally ignored —
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

		// The LLM narrative is generated asynchronously (#167 / AgDR-0051): the
		// blocking call took ~11-17s and overshot the budget on every recompute,
		// and blocked the synchronous "Recompute now" request. Here we write the
		// instant rule-based narrative marked `llm_pending`, then schedule the
		// background `NARRATIVE_ACTION` job to replace it with the LLM result.
		$narrative = Narrative_Generator::pending( $breakdown );

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

		// Kick the background narrative job. Deduped — a job already queued for
		// a prior recompute will pick up the freshest cache row when it runs.
		if ( false === \wp_next_scheduled( self::NARRATIVE_ACTION ) ) {
			\wp_schedule_single_event( \time(), self::NARRATIVE_ACTION );
		}

		return $payload;
	}

	/**
	 * Cron callback for `NARRATIVE_ACTION` (#167 / AgDR-0051). Runs the LLM
	 * narrative off the recompute critical path and merges it into the cached
	 * breakdown — without re-running the score.
	 *
	 * Guarded twice:
	 *   1. Only acts when the cached narrative is still `llm_pending` (a fresh
	 *      recompute since the last enrichment, or a first run).
	 *   2. Re-reads the cache after the (slow) LLM call and only writes when
	 *      `computed_at` is unchanged — so a job that finishes after a newer
	 *      recompute is discarded rather than desyncing the narrative from the
	 *      breakdown it was generated against.
	 */
	public static function do_generate_narrative(): void {
		$cache = \get_option( self::CACHE_OPTION, null );
		if ( ! is_array( $cache ) || self::CACHE_SCHEMA_VERSION !== (int) ( $cache['schema_version'] ?? 0 ) ) {
			return;
		}
		if ( empty( $cache['narrative']['llm_pending'] ) ) {
			return; // Already enriched (or a concurrent job won) — nothing to do.
		}

		$computed_at = (string) ( $cache['computed_at'] ?? '' );
		$breakdown   = array(
			'overall'    => (int) ( $cache['overall'] ?? 0 ),
			'sub_scores' => isset( $cache['sub_scores'] ) && is_array( $cache['sub_scores'] ) ? $cache['sub_scores'] : array(),
		);

		$narrative = Narrative_Generator::generate( $breakdown );

		// Merge guard: only write if the row we generated against is still current.
		$fresh = \get_option( self::CACHE_OPTION, null );
		if ( ! is_array( $fresh ) || (string) ( $fresh['computed_at'] ?? '' ) !== $computed_at ) {
			return;
		}
		$fresh['narrative'] = $narrative;
		\update_option( self::CACHE_OPTION, $fresh, 'no' );
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
