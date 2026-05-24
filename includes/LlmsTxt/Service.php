<?php
/**
 * LLMs Index orchestration service.
 *
 * Single backend for the public route (`Router`), the WP-CLI command, and any
 * future admin REST surface. Owns the cache option (AgDR-0022) and the regen
 * schedule (AgDR-0023). Composition itself is delegated to the pure
 * `Composer` class so this file stays focused on side effects:
 *
 *   - Hook wiring on `save_post` / `wp_trash_post` / `before_delete_post` /
 *     `wp_after_insert_post` and on profile-update + editorial-update actions.
 *   - Async regen via `wp_schedule_single_event` with a 5-second debounce.
 *   - Daily cron backstop.
 *   - Cache-miss synchronous regen under a transient lock.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

use WPContext\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Service::get_composed_body() is the single integration point between a
 * caller (Router, REST, CLI) and the cached llms.txt body.
 */
final class Service {

	/**
	 * Option key holding the cached composed body. Non-autoloaded
	 * (`autoload === 'no'`) per AgDR-0022 — the value can be 100s of KB on
	 * a 1000-post site and is only read on the public route + admin preview.
	 *
	 * @var string
	 */
	public const CACHE_OPTION = 'agentready_llms_txt_cache';

	/**
	 * Transient guarding the regen-under-lock path. Held only for the
	 * duration of one synchronous composition (~5 s ceiling), so a 30-second
	 * TTL is far longer than the worst-case work and short enough that a
	 * crashed PHP process can recover on the next request.
	 *
	 * @var string
	 */
	public const REGEN_LOCK_TRANSIENT = 'agentready_llms_txt_regen_lock';

	/**
	 * Cron action fired by the debounced single event.
	 *
	 * @var string
	 */
	public const REGEN_ACTION = 'agentready_llms_txt_regen';

	/**
	 * Cron action fired daily as the freshness backstop.
	 *
	 * @var string
	 */
	public const DAILY_REGEN_ACTION = 'agentready_llms_txt_daily_regen';

	/**
	 * Debounce window between trigger and async regen. 5 seconds swallows a
	 * typical bulk-edit burst and keeps single-post saves feeling immediate.
	 * Not configurable (AgDR-0023 § "Why 5 seconds").
	 *
	 * @var int
	 */
	public const DEBOUNCE_DELAY = 5;

	/**
	 * Lock TTL — ceiling for one synchronous regen. Far longer than the AC
	 * #6 5-second worst-case so a single slow site doesn't trip it, short
	 * enough to recover from a crashed process within one minute.
	 *
	 * @var int
	 */
	public const LOCK_TTL = 30;

	/**
	 * Cache payload schema version. Bumped if the stored shape changes.
	 *
	 * @var int
	 */
	public const CACHE_SCHEMA_VERSION = 1;

	/**
	 * Per-post-type filter passed to schedule_regen-from-hook. Pre-checks
	 * the post status / type against the Context Profile so an admin saving
	 * a draft on a draft-not-exposed site doesn't trigger a regen.
	 *
	 * Phase #8 may add `agentready_llms_txt_editorial_saved` subscribers;
	 * the action itself is wired here so Phase C only needs to fire it.
	 */
	public static function register_hooks(): void {
		\add_action( self::REGEN_ACTION, array( self::class, 'do_regen' ) );
		\add_action( self::DAILY_REGEN_ACTION, array( self::class, 'do_regen' ) );

		\add_action( 'save_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'wp_trash_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'before_delete_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'wp_after_insert_post', array( self::class, 'on_post_change' ), 10, 1 );

		\add_action( 'agentready_context_profile_saved', array( self::class, 'schedule_regen' ) );
		\add_action( 'agentready_llms_txt_editorial_saved', array( self::class, 'schedule_regen' ) );
	}

	/**
	 * Cron-event scheduler for activation. Registers the daily backstop if
	 * it isn't already pending. Idempotent — safe to call on every
	 * reactivation.
	 */
	public static function schedule_daily_regen(): void {
		if ( false === \wp_next_scheduled( self::DAILY_REGEN_ACTION ) ) {
			\wp_schedule_event(
				\time() + \DAY_IN_SECONDS,
				'daily',
				self::DAILY_REGEN_ACTION
			);
		}
	}

	/**
	 * Cron-event clearer for deactivation. Removes both the debounced
	 * single-event and the daily backstop.
	 */
	public static function clear_scheduled_regens(): void {
		\wp_clear_scheduled_hook( self::REGEN_ACTION );
		\wp_clear_scheduled_hook( self::DAILY_REGEN_ACTION );
	}

	/**
	 * Public reader used by `Router` and the WP-CLI status command.
	 *
	 * Cache hit: returns the cached body string.
	 * Cache miss (or stale schema_version): runs the synchronous regen
	 *   under lock; returns the fresh body, or an empty string when another
	 *   process holds the lock.
	 *
	 * Unknown `schema_version` is treated as a miss (AgDR-0022 §
	 * "Schema version field") — a future format change can read the
	 * stale payload, decide it can't trust the shape, and force a regen
	 * rather than crashing on an unexpected key.
	 */
	public static function get_composed_body(): string {
		$cache = \get_option( self::CACHE_OPTION, null );

		if ( self::is_valid_cache_payload( $cache ) ) {
			return (string) $cache['body'];
		}

		return self::regen_under_lock();
	}

	/**
	 * Validate that a stored cache payload is the shape this reader can
	 * trust. Treats missing/extra/mismatched `schema_version` as a miss.
	 *
	 * @param mixed $cache Raw `get_option` result.
	 */
	private static function is_valid_cache_payload( $cache ): bool {
		if ( ! is_array( $cache ) ) {
			return false;
		}
		if ( ! isset( $cache['body'] ) ) {
			return false;
		}
		$version = isset( $cache['schema_version'] ) ? (int) $cache['schema_version'] : 0;
		return self::CACHE_SCHEMA_VERSION === $version;
	}

	/**
	 * Synchronous regen used by the cache-miss path and the WP-CLI command.
	 * Always writes the cache before returning; returns the freshly-composed
	 * body. Unlike `regen_under_lock()`, this does not consult the lock and
	 * always runs the composer — callers that need lock-aware semantics use
	 * `get_composed_body()` instead.
	 */
	public static function regen_sync(): string {
		$body = self::compose_now();
		self::write_cache( $body );
		return $body;
	}

	/**
	 * Schedule an async regen one debounce window from now.
	 *
	 * Two behaviours bundled here:
	 *
	 * 1. **Debounce / coalesce.** A second call inside the debounce window
	 *    sees a future-scheduled event and noops, so a burst of saves only
	 *    triggers one regen.
	 * 2. **Stale-event recovery.** If a previously-scheduled event is still
	 *    sitting in the cron queue with a past timestamp (the cron didn't
	 *    fire it — common on wp-env without traffic, or on any site where
	 *    cron failed for a window), the old event is cleared before a
	 *    fresh one is scheduled. Without this, WP de-dups
	 *    `wp_schedule_single_event` against the stale entry and the new
	 *    schedule is silently dropped, leaving the regen never to fire.
	 *    See Ref34t/agentready#103.
	 *
	 * `do_action()` may pass extra arguments (e.g. `save_post` passes the
	 * post ID). They are intentionally ignored — the regen is site-level
	 * and reads the current state of all inputs at run time.
	 */
	public static function schedule_regen(): void {
		$existing = \wp_next_scheduled( self::REGEN_ACTION );

		// Future event already scheduled — debounce is working, noop.
		if ( false !== $existing && $existing > \time() ) {
			return;
		}

		// Stale past-timestamp event still in the queue — clear it so the
		// fresh schedule below isn't silently de-duped by WP. See #103.
		if ( false !== $existing ) {
			\wp_clear_scheduled_hook( self::REGEN_ACTION );
		}

		\wp_schedule_single_event(
			\time() + self::DEBOUNCE_DELAY,
			self::REGEN_ACTION
		);
	}

	/**
	 * Cron callback for `REGEN_ACTION` and `DAILY_REGEN_ACTION`.
	 */
	public static function do_regen(): void {
		self::regen_sync();
	}

	/**
	 * Hook callback for the post-lifecycle events. Filters out edits that
	 * can't possibly affect the index (post types/statuses outside the
	 * Context Profile's exposed set) before scheduling.
	 *
	 * The filter is a cheap pre-check — the composer's WP_Query in
	 * `regen_sync()` re-evaluates the full exposure verdict at run time.
	 * This early-out exists so a draft-post save on a publish-only site
	 * doesn't drag a regen cycle into the cron queue unnecessarily.
	 *
	 * @param int $post_id Post ID. Other args from save_post/before_delete_post
	 *                      are ignored.
	 */
	public static function on_post_change( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$profile  = Context_Profile_Settings::get_profile();
		$cpts     = isset( $profile['exposed_cpts'] ) && is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );

		// Either side of the transition counts: a post LEAVING the exposed
		// set (e.g. publish → draft, untrash → trash) should also trigger a
		// regen. We can't see the previous state from a save_post hook so
		// we trigger on either-CPT-or-status overlap.
		$cpt_match    = in_array( (string) $post->post_type, $cpts, true );
		$status_match = in_array( (string) $post->post_status, $statuses, true );

		if ( ! $cpt_match ) {
			return;
		}

		// Post type matches an exposed CPT. Even if the current status
		// doesn't match the exposed list (the post is being unpublished),
		// the index might still need updating — schedule.
		unset( $status_match );

		self::schedule_regen();
	}

	/**
	 * Force-invalidate the cache. Drops the option so the next reader regens.
	 * Exposed for WP-CLI and admin REST callers — the regen path proper
	 * uses `write_cache()` directly to avoid the read-after-write window
	 * an `invalidate()` → `regen_sync()` sequence would open.
	 */
	public static function invalidate(): void {
		\delete_option( self::CACHE_OPTION );
	}

	/**
	 * Public inspector for the cached payload. Returns the full stored
	 * array (including `generated_at` + `entry_count`) for WP-CLI / admin
	 * status displays. Returns null when no cache is present.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_cache_payload(): ?array {
		$cache = \get_option( self::CACHE_OPTION, null );
		if ( ! is_array( $cache ) ) {
			return null;
		}
		return $cache;
	}

	/**
	 * Run the composer with the current WP state. Pulled out of regen_sync
	 * so callers that want the body without writing the cache (e.g. the
	 * admin preview REST endpoint in Phase C) can call it directly.
	 */
	public static function compose_now(): string {
		$identity  = self::resolve_identity();
		$editorial = self::resolve_editorial();
		$sections  = Entry_Source::get_sections();

		return Composer::compose(
			array(
				'identity'  => $identity,
				'editorial' => $editorial,
				'sections'  => $sections,
			)
		);
	}

	/**
	 * Lock-guarded compose: if the lock is unheld, take it, compose, write,
	 * release, and return the fresh body. If another process holds the
	 * lock, return an empty body — the next request (or the scheduled
	 * regen) will get the fresh one.
	 */
	private static function regen_under_lock(): string {
		if ( false !== \get_transient( self::REGEN_LOCK_TRANSIENT ) ) {
			return '';
		}

		\set_transient( self::REGEN_LOCK_TRANSIENT, \time(), self::LOCK_TTL );

		try {
			$body = self::compose_now();
			self::write_cache( $body );
		} finally {
			\delete_transient( self::REGEN_LOCK_TRANSIENT );
		}

		return $body;
	}

	/**
	 * Resolve the site identity block. Site name and tagline read live from
	 * WP core options (AgDR-0002 § "Two categories deliberately NOT stored")
	 * so they cannot go stale relative to General Settings.
	 *
	 * @return array{site_name: string, tagline: string}
	 */
	private static function resolve_identity(): array {
		$name    = \get_bloginfo( 'name' );
		$tagline = \get_bloginfo( 'description' );

		return array(
			'site_name' => is_string( $name ) ? $name : '',
			'tagline'   => is_string( $tagline ) ? $tagline : '',
		);
	}

	/**
	 * Resolve the editorial entries for the Composer.
	 *
	 * Phase C (AgDR-0025) introduces the versioned shape
	 * `{schema_version: 1, entries: [...]}` written by
	 * `Editorial_Settings::sanitize`. Phase A shipped this method against
	 * the bare-list shape; both are accepted to avoid breaking fixtures or
	 * `wp option update` calls made before Phase C landed.
	 *
	 * The Composer expects each entry to carry `title`, `url`, optional
	 * `description`, optional `section`. The `Custom`-section escape hatch
	 * (per AgDR-0025) substitutes the entry's `section_label` for the
	 * `section` value before handing off.
	 *
	 * @return array<int, array{title: string, url: string, description?: string, section?: string}>
	 */
	private static function resolve_editorial(): array {
		$stored = \get_option( 'agentready_llms_txt_editorial', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$raw_entries = isset( $stored['entries'] ) && is_array( $stored['entries'] )
			? $stored['entries']
			: $stored;

		$out = array();
		foreach ( $raw_entries as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? (string) $row['title'] : '';
			$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( '' === $title || '' === $url ) {
				continue;
			}
			$entry = array(
				'title' => $title,
				'url'   => $url,
			);
			if ( isset( $row['description'] ) && '' !== (string) $row['description'] ) {
				$entry['description'] = (string) $row['description'];
			}

			$section_label = '';
			if ( isset( $row['section'] ) && 'Custom' === (string) $row['section'] ) {
				$section_label = isset( $row['section_label'] ) ? (string) $row['section_label'] : '';
			} elseif ( isset( $row['section'] ) && '' !== (string) $row['section'] ) {
				$section_label = (string) $row['section'];
			}
			if ( '' !== $section_label ) {
				$entry['section'] = $section_label;
			}

			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Persist a freshly-composed body to the cache option.
	 *
	 * Stored as a structured array (not a bare string) so future schema
	 * changes can be detected via the `schema_version` field without
	 * blowing up the reader.
	 */
	private static function write_cache( string $body ): void {
		$payload = array(
			'schema_version' => self::CACHE_SCHEMA_VERSION,
			'body'           => $body,
			'generated_at'   => \gmdate( 'c' ),
			'entry_count'    => substr_count( $body, "\n- " ),
		);

		\update_option( self::CACHE_OPTION, $payload, 'no' );
	}
}
