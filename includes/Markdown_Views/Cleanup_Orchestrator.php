<?php
/**
 * Markdown Views LLM cleanup orchestrator per AgDR-0016/0017/0018.
 *
 * The decision + scheduling + execution surface that combines:
 *
 *  - Page_Builder_Detector (AgDR-0016) — "is this a builder post?"
 *  - Walker quality score (AgDR-0017) — "is this MD messy enough?"
 *  - Context_Profile_Settings — "is cleanup enabled and what's the threshold?"
 *  - Client_Wrapper (AgDR-0003) — actual LLM call with retry / backoff
 *  - Cleanup_Guard (AgDR-0018) — no-hallucination safety filter
 *
 * Public surface stays deterministic-first in Phase A: cleanup runs
 * async, output goes to post-meta, but the served `.md` route still
 * returns the walker's deterministic output. Phase B adds the admin
 * approval UI and the served-content swap.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

use WPContext\Admin\Context_Profile_Settings;
use WPContext\Ai\Client_Wrapper;

\defined( 'ABSPATH' ) || exit;

/**
 * Static orchestrator. No instance state — every method is callable
 * from anywhere in the plugin lifecycle (hooks, REST, CLI).
 *
 * State machine for a post's cleanup attempt, recorded in post-meta:
 *
 *   (no key)  → cleanup not attempted
 *   'pending' → scheduled; cron event queued
 *   'done'    → cleanup ran, guard passed, output stored under META_KEY_OUTPUT
 *   'needs-retry' → provider error OR guard kill-switch fired; will be
 *                    re-scheduled on next save_post or admin "regenerate"
 *   'failed'  → permanent failure (e.g. cleanup disabled mid-run);
 *                manual recovery required
 */
final class Cleanup_Orchestrator {

	/**
	 * WP Cron action fired by `schedule()` and handled by `run_cleanup()`.
	 * Listeners can register on the same action for diagnostics (e.g.
	 * the Phase B admin "rerun cleanup" REST endpoint).
	 *
	 * @var string
	 */
	public const SCHEDULE_ACTION = 'agentready_md_cleanup_run';

	/**
	 * Post-meta key holding the LLM-cleaned + guard-filtered markdown.
	 * Present only on posts where the cleanup pass produced output
	 * the guard accepted. Phase B's admin UI reads this to populate
	 * the side-by-side preview.
	 *
	 * @var string
	 */
	public const META_KEY_OUTPUT = '_agentready_md_cleanup_output';

	/**
	 * Post-meta key holding the diagnostic record from the most recent
	 * cleanup attempt. JSON-encoded array: timestamp, kept/dropped
	 * counts, per-stripped-sentence reasons, error code (if any).
	 *
	 * @var string
	 */
	public const META_KEY_DIAGNOSTICS = '_agentready_md_cleanup_diagnostics';

	/**
	 * Post-meta key holding the current cleanup state — see the
	 * state-machine docblock at the top of this class.
	 *
	 * @var string
	 */
	public const META_KEY_STATUS = '_agentready_md_cleanup_status';

	/**
	 * Post-meta key holding the content hash the cleaned output
	 * corresponds to. When the post's current hash diverges, the
	 * stored cleanup is stale and the orchestrator schedules a fresh
	 * run on next read.
	 *
	 * @var string
	 */
	public const META_KEY_OUTPUT_HASH = '_agentready_md_cleanup_hash';

	/**
	 * Cleanup prompt scaffold. Detailed prompt tuning is a v0.1.x
	 * concern (per AgDR-0018 "does NOT decide — prompt itself"). The
	 * prompt's job is "produce clean output"; the guard's job is
	 * "ensure that output is safe". A weak prompt produces stripped
	 * sentences, not hallucinations.
	 *
	 * @var string
	 */
	private const CLEANUP_PROMPT = <<<'PROMPT'
Clean up the following Markdown so it is a tidy, faithful rendering of
the source content. Do NOT add information, claims, names, numbers, or
URLs that are not present in the source. Do NOT invent. Do NOT
summarize away substantive content. Preserve every named entity
exactly as written.

If the source is already clean, return it unchanged.

Source markdown:
---
%s
---
PROMPT;

	/**
	 * Wire the cron handler. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( self::SCHEDULE_ACTION, array( self::class, 'run_cleanup' ), 10, 1 );
	}

	/**
	 * Decide whether `$post` should be sent through cleanup.
	 *
	 * Trigger logic per AgDR-0016 + AgDR-0017:
	 *   1. Module enabled (Markdown Views on)
	 *   2. LLM cleanup enabled (master switch)
	 *   3. WP AI Client is available
	 *   4. Page-builder detected OR quality score < threshold
	 *   5. No cleanup already in 'done' state for this content hash
	 */
	public static function should_clean( \WP_Post $post, Conversion_Result $conversion, string $content_hash ): bool {
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return false;
		}

		$profile = Context_Profile_Settings::get_profile();
		if ( empty( $profile['llm_cleanup_enabled'] ) ) {
			return false;
		}

		if ( ! Client_Wrapper::has_ai_client() ) {
			return false;
		}

		// If we already have a cleanup output for the current content
		// hash, no need to schedule again.
		if ( self::has_fresh_cleanup( (int) $post->ID, $content_hash ) ) {
			return false;
		}

		$threshold = Context_Profile_Settings::get_md_cleanup_threshold();
		$score     = $conversion->get_quality_score();

		if ( $score < $threshold ) {
			return true;
		}

		if ( Page_Builder_Detector::is_page_builder_post( $post ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Queue an async cleanup run. Idempotent — won't double-queue an
	 * identical (post_id) event already pending in cron.
	 *
	 * Records `pending` state on post-meta so admin UI can show
	 * "cleanup queued" without a separate cron-introspection call.
	 */
	public static function schedule( \WP_Post $post ): void {
		$post_id = (int) $post->ID;
		$args    = array( $post_id );

		if ( false === \wp_next_scheduled( self::SCHEDULE_ACTION, $args ) ) {
			\wp_schedule_single_event( \time() + 1, self::SCHEDULE_ACTION, $args );
		}

		\update_post_meta( $post_id, self::META_KEY_STATUS, 'pending' );
	}

	/**
	 * Cron handler. Runs cleanup, applies the guard, persists or
	 * flags needs-retry. Catches everything — a cron handler must
	 * not throw.
	 */
	public static function run_cleanup( int $post_id ): void {
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Re-check eligibility at run time — a post that was eligible at
		// schedule time may have been edited, had cleanup disabled, etc.
		$conversion = Service::regenerate_conversion_for( $post );
		if ( null === $conversion ) {
			\update_post_meta( $post_id, self::META_KEY_STATUS, 'failed' );
			return;
		}

		$content_hash = Service::current_content_hash( $post );

		if ( ! self::should_clean( $post, $conversion, $content_hash ) ) {
			\delete_post_meta( $post_id, self::META_KEY_STATUS );
			return;
		}

		$prompt = \sprintf( self::CLEANUP_PROMPT, $conversion->get_markdown() );
		$result = Client_Wrapper::generate( $prompt, array( 'tier' => 'quality' ) );

		if ( null !== $result->error_code() ) {
			self::record_diagnostic(
				$post_id,
				array(
					'stage'        => 'provider',
					'error_code'   => $result->error_code(),
					'attempted_at' => \current_time( 'mysql', true ),
				)
			);
			\update_post_meta( $post_id, self::META_KEY_STATUS, $result->needs_retry() ? 'needs-retry' : 'failed' );
			return;
		}

		$cleaned_raw = (string) $result->content();
		$source_text = \wp_strip_all_tags( $post->post_content );

		$allowlist       = Cleanup_Guard::build_allowlist( $post->post_content );
		$source_entities = Cleanup_Guard::extract_named_entities( $source_text );

		$guard = Cleanup_Guard::check( $cleaned_raw, $allowlist, $source_entities );

		$diagnostics = array(
			'attempted_at'      => \current_time( 'mysql', true ),
			'sentences_kept'    => $guard->get_stats()['sentences_kept'],
			'sentences_dropped' => $guard->get_stats()['sentences_dropped'],
			'dropped'           => $guard->get_dropped(),
		);

		if ( $guard->failed_overall() ) {
			$diagnostics['stage']      = 'guard';
			$diagnostics['error_code'] = 'kill_switch';
			self::record_diagnostic( $post_id, $diagnostics );
			\update_post_meta( $post_id, self::META_KEY_STATUS, 'needs-retry' );
			return;
		}

		\update_post_meta( $post_id, self::META_KEY_OUTPUT, $guard->get_filtered_markdown() );
		\update_post_meta( $post_id, self::META_KEY_OUTPUT_HASH, $content_hash );
		self::record_diagnostic( $post_id, $diagnostics );
		\update_post_meta( $post_id, self::META_KEY_STATUS, 'done' );
	}

	/**
	 * Return the stored cleanup status, or '' if no attempt has been
	 * made on this post.
	 */
	public static function get_status( int $post_id ): string {
		$value = \get_post_meta( $post_id, self::META_KEY_STATUS, true );
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * Read the cleaned markdown for `$post_id` if its stored hash
	 * matches `$content_hash` (i.e. the cleanup is still fresh for the
	 * current content). Returns null otherwise — Phase B's served-MD
	 * swap reads through this method.
	 */
	public static function get_fresh_output( int $post_id, string $content_hash ): ?string {
		if ( ! self::has_fresh_cleanup( $post_id, $content_hash ) ) {
			return null;
		}

		$value = \get_post_meta( $post_id, self::META_KEY_OUTPUT, true );
		return \is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Hook on post lifecycle events to clear cleanup state when the
	 * post content changes. Wired alongside the cache-invalidation
	 * hooks in Service::register_hooks.
	 */
	public static function invalidate( int $post_id ): void {
		\delete_post_meta( $post_id, self::META_KEY_OUTPUT );
		\delete_post_meta( $post_id, self::META_KEY_OUTPUT_HASH );
		\delete_post_meta( $post_id, self::META_KEY_DIAGNOSTICS );
		\delete_post_meta( $post_id, self::META_KEY_STATUS );
	}

	/**
	 * True when a cleanup has previously succeeded on the post AND its
	 * recorded content hash matches the current content hash.
	 */
	private static function has_fresh_cleanup( int $post_id, string $content_hash ): bool {
		$stored_hash = \get_post_meta( $post_id, self::META_KEY_OUTPUT_HASH, true );

		if ( ! \is_string( $stored_hash ) || $stored_hash !== $content_hash ) {
			return false;
		}

		return 'done' === self::get_status( $post_id );
	}

	/**
	 * Persist the diagnostic blob (JSON-encoded) so the admin UI can
	 * read it without re-running the guard.
	 *
	 * @param array<string, mixed> $diagnostics
	 */
	private static function record_diagnostic( int $post_id, array $diagnostics ): void {
		\update_post_meta( $post_id, self::META_KEY_DIAGNOSTICS, (string) \wp_json_encode( $diagnostics ) );
	}
}
