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
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Service as Markdown_Views_Service;
use Mokhai\Markdown_Views\Walker;

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
	public const CACHE_OPTION = 'mokhai_llms_txt_cache';

	/**
	 * Option key holding the cached composed `/llms-full.txt` body (#179 /
	 * AgDR-0057). Separate option from {@see CACHE_OPTION} — the full body
	 * inlines every indexed document's Markdown, so it can be MBs on a large
	 * site and must never ride along on reads of the small index payload.
	 * Non-autoloaded for the same reason.
	 *
	 * Listed in `Support\Uninstaller::option_keys()` per the #189 contract.
	 *
	 * @var string
	 */
	public const FULL_CACHE_OPTION = 'mokhai_llms_full_txt_cache';

	/**
	 * Transient guarding the regen-under-lock path. Held only for the
	 * duration of one synchronous composition (~5 s ceiling), so a 30-second
	 * TTL is far longer than the worst-case work and short enough that a
	 * crashed PHP process can recover on the next request.
	 *
	 * @var string
	 */
	public const REGEN_LOCK_TRANSIENT = 'mokhai_llms_txt_regen_lock';

	/**
	 * Cron action fired by the debounced single event.
	 *
	 * @var string
	 */
	public const REGEN_ACTION = 'mokhai_llms_txt_regen';

	/**
	 * Cron action fired daily as the freshness backstop.
	 *
	 * @var string
	 */
	public const DAILY_REGEN_ACTION = 'mokhai_llms_txt_daily_regen';

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
	 * Full-cache payload schema version. Independent of
	 * {@see CACHE_SCHEMA_VERSION} — the two payloads can evolve separately.
	 *
	 * @var int
	 */
	public const FULL_CACHE_SCHEMA_VERSION = 1;

	/**
	 * Module key consumed by `Context_Profile_Settings::is_module_enabled()`
	 * (profile field `llms_full_txt_enabled`).
	 *
	 * @var string
	 */
	public const FULL_MODULE = 'llms_full_txt';

	/**
	 * Per-post-type filter passed to schedule_regen-from-hook. Pre-checks
	 * the post status / type against the Context Profile so an admin saving
	 * a draft on a draft-not-exposed site doesn't trigger a regen.
	 *
	 * Phase #8 may add `mokhai_llms_txt_editorial_saved` subscribers;
	 * the action itself is wired here so Phase C only needs to fire it.
	 */
	public static function register_hooks(): void {
		\add_action( self::REGEN_ACTION, array( self::class, 'do_regen' ) );
		\add_action( self::DAILY_REGEN_ACTION, array( self::class, 'do_regen' ) );

		\add_action( 'save_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'wp_trash_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'before_delete_post', array( self::class, 'on_post_change' ), 10, 1 );
		\add_action( 'wp_after_insert_post', array( self::class, 'on_post_change' ), 10, 1 );

		\add_action( 'mokhai_context_profile_saved', array( self::class, 'schedule_regen' ) );
		\add_action( 'mokhai_llms_txt_editorial_saved', array( self::class, 'schedule_regen' ) );
		// A per-post description change (regen / manual set-clear / invalidate)
		// must recompose the cached document, or /llms.txt serves stale
		// descriptions until another trigger or the daily backstop (#151).
		\add_action( 'mokhai_llms_txt_description_changed', array( self::class, 'schedule_regen' ) );

		// A programmatic exclude-meta write (update_post_meta / WP-CLI / REST
		// meta endpoints) fires none of the save_post-family hooks above, so
		// the composed cache kept listing a freshly-excluded post for up to
		// 24h until the daily backstop (#190). The block-editor sidebar path
		// is already covered via save_post; these listeners close the
		// editor-less write paths. Same trigger class as #151/#103.
		\add_action( 'added_post_meta', array( self::class, 'on_exclude_meta_change' ), 10, 3 );
		\add_action( 'updated_post_meta', array( self::class, 'on_exclude_meta_change' ), 10, 3 );
		\add_action( 'deleted_post_meta', array( self::class, 'on_exclude_meta_change' ), 10, 3 );

		// Term-assignment listener (#188) — programmatic wp_set_post_terms()
		// fires set_object_terms but NOT save_post, so a post gaining/losing
		// an excluded category/tag outside the editor would leave the
		// composed cache stale for up to 24h. Same trigger class as the
		// exclude-meta listeners above (#190).
		\add_action( 'set_object_terms', array( self::class, 'on_terms_change' ), 10, 4 );
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

		self::regen_under_lock();

		$cache = \get_option( self::CACHE_OPTION, null );
		return self::is_valid_cache_payload( $cache ) ? (string) $cache['body'] : '';
	}

	/**
	 * Public reader for the consolidated `/llms-full.txt` body (#179), used
	 * by `Router` and WP-CLI. Same cache-hit / regen-under-lock semantics as
	 * {@see get_composed_body()} — one regen writes both caches, so the two
	 * documents can never drift more than one debounce window apart.
	 *
	 * The module toggle is NOT consulted here — `Router::build_full_response()`
	 * 404s on a disabled module before this reader runs, and `regen_sync()`
	 * skips composing (and clears) the full cache while the module is off, so
	 * a disabled module reads as an empty body on any non-Router caller.
	 */
	public static function get_composed_full_body(): string {
		$cache = \get_option( self::FULL_CACHE_OPTION, null );

		if ( self::is_valid_full_cache_payload( $cache ) ) {
			return (string) $cache['body'];
		}

		self::regen_under_lock();

		$cache = \get_option( self::FULL_CACHE_OPTION, null );
		return self::is_valid_full_cache_payload( $cache ) ? (string) $cache['body'] : '';
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
	 * Validate a stored full-cache payload — mirror of
	 * {@see is_valid_cache_payload()} against {@see FULL_CACHE_SCHEMA_VERSION}.
	 *
	 * @param mixed $cache Raw `get_option` result.
	 */
	private static function is_valid_full_cache_payload( $cache ): bool {
		if ( ! is_array( $cache ) ) {
			return false;
		}
		if ( ! isset( $cache['body'] ) ) {
			return false;
		}
		$version = isset( $cache['schema_version'] ) ? (int) $cache['schema_version'] : 0;
		return self::FULL_CACHE_SCHEMA_VERSION === $version;
	}

	/**
	 * Synchronous regen used by the cache-miss path and the WP-CLI command.
	 * Always writes the cache before returning; returns the freshly-composed
	 * index body. Unlike `regen_under_lock()`, this does not consult the lock
	 * and always runs the composer — callers that need lock-aware semantics
	 * use `get_composed_body()` instead.
	 *
	 * The full document (#179) regenerates in the same pass: AC "regenerated
	 * on the same cadence/triggers as llms.txt" holds by construction because
	 * there is exactly one regen pipeline. With the `llms_full_txt` module
	 * off, the full cache is cleared instead — a toggle-off takes effect on
	 * the next regen, not just on the routing 404.
	 */
	public static function regen_sync(): string {
		$body = self::compose_now();
		self::write_cache( $body );

		if ( Context_Profile_Settings::is_module_enabled( self::FULL_MODULE ) ) {
			self::write_full_cache( self::compose_full_now() );
		} else {
			\delete_option( self::FULL_CACHE_OPTION );
		}

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
	 * Hook callback for the `{added,updated,deleted}_post_meta` actions.
	 * Schedules a regen when the touched key is the per-post exclude flag
	 * (#180), so an exclusion takes effect on /llms.txt promptly no matter
	 * which write path set it. All other meta keys are ignored.
	 *
	 * Delegates to {@see on_post_change()} so the exposed-CPT pre-check
	 * applies — toggling the flag on a never-exposed post type doesn't drag
	 * a regen cycle into the cron queue.
	 *
	 * @param int|array<int, int> $meta_id   Meta row ID (array of IDs on
	 *                                       `deleted_post_meta`). Unused.
	 * @param int                 $object_id Post ID whose meta changed.
	 * @param string              $meta_key  Meta key being written.
	 */
	public static function on_exclude_meta_change( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );

		if ( Context_Profile_Settings::EXCLUDE_META_KEY !== (string) $meta_key ) {
			return;
		}

		self::on_post_change( (int) $object_id );
	}

	/**
	 * Hook callback for `set_object_terms` (#188). Schedules a regen when a
	 * post's categories / tags change while term deny-lists are configured —
	 * the assignment may flip the post's excluded verdict without any
	 * save_post firing (programmatic `wp_set_post_terms()`).
	 *
	 * No-ops on non-content taxonomies and — the common case — when both term
	 * deny-lists are empty, so sites without term exclusion pay one profile
	 * read and nothing else.
	 *
	 * @param int                    $object_id Post ID whose terms changed.
	 * @param array<int, int|string> $terms     New terms. Unused.
	 * @param array<int, int>        $tt_ids    New term-taxonomy IDs. Unused.
	 * @param string                 $taxonomy  Taxonomy being assigned.
	 */
	public static function on_terms_change( $object_id, $terms, $tt_ids, $taxonomy ): void {
		unset( $terms, $tt_ids );

		if ( ! \in_array( (string) $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return;
		}

		$profile = Context_Profile_Settings::get_profile();
		if ( array() === $profile['excluded_term_ids'] && array() === $profile['excluded_term_slugs'] ) {
			return;
		}

		self::on_post_change( (int) $object_id );
	}

	/**
	 * Force-invalidate the caches. Drops both options (index + full) so the
	 * next reader regens. Exposed for WP-CLI and admin REST callers — the
	 * regen path proper uses `write_cache()` / `write_full_cache()` directly
	 * to avoid the read-after-write window an `invalidate()` → `regen_sync()`
	 * sequence would open.
	 */
	public static function invalidate(): void {
		\delete_option( self::CACHE_OPTION );
		\delete_option( self::FULL_CACHE_OPTION );
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
	 * Run the full-document composer with the current WP state (#179).
	 *
	 * Reuses `Entry_Source::get_sections()` — the same query, exposure gates,
	 * exclusion rules, and per-CPT cap that drive `/llms.txt` — so the parity
	 * AC ("every page in llms.txt appears in llms-full.txt") holds by
	 * construction. Each entry's `post_id` resolves the source post, whose
	 * Markdown body comes from {@see markdown_for_post()}.
	 */
	public static function compose_full_now(): string {
		$identity  = self::resolve_identity();
		$editorial = self::resolve_editorial();
		$sections  = Entry_Source::get_sections();

		$documents = array();
		foreach ( $sections as $section ) {
			$entries = array();
			foreach ( $section['entries'] as $entry ) {
				$doc = array(
					'title' => $entry['title'],
					'url'   => $entry['url'],
				);

				$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
				$post    = $post_id > 0 ? \get_post( $post_id ) : null;
				if ( $post instanceof \WP_Post ) {
					$doc['markdown'] = self::markdown_for_post( $post );
				}

				$entries[] = $doc;
			}

			$documents[] = array(
				'label'   => $section['label'],
				'entries' => $entries,
			);
		}

		return Full_Composer::compose(
			array(
				'identity'  => $identity,
				'editorial' => $editorial,
				'documents' => $documents,
			)
		);
	}

	/**
	 * Resolve the full Markdown body for one document (#179).
	 *
	 * With Markdown Views enabled, delegates to
	 * `Markdown_Views\Service::get_markdown_for_post()` so the per-post cache
	 * table amortises conversions across regens and the inlined body is
	 * byte-identical to the served `.md` companion. With the module disabled
	 * (or on the unexpected error path), falls back to a direct Walker
	 * conversion — `/llms-full.txt` content doesn't disappear just because
	 * the per-page `.md` routes are off, and the fallback never writes the
	 * Markdown Views cache table.
	 */
	private static function markdown_for_post( \WP_Post $post ): string {
		if ( Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			$md = Markdown_Views_Service::get_markdown_for_post( $post );
			if ( is_string( $md ) ) {
				return $md;
			}
		}

		// `the_content` is a WordPress core filter we consume, not one we
		// define — same rationale as Markdown_Views\Service.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = (string) \apply_filters( 'the_content', $post->post_content );
		return Walker::convert( $html )->get_markdown();
	}

	/**
	 * Lock-guarded regen: if the lock is unheld, take it and run one
	 * `regen_sync()` pass (which writes both caches), then release. If
	 * another process holds the lock, return immediately — the caller
	 * re-reads its cache option and serves empty until the holder finishes.
	 */
	private static function regen_under_lock(): void {
		if ( false !== \get_transient( self::REGEN_LOCK_TRANSIENT ) ) {
			return;
		}

		\set_transient( self::REGEN_LOCK_TRANSIENT, \time(), self::LOCK_TTL );

		try {
			self::regen_sync();
		} finally {
			\delete_transient( self::REGEN_LOCK_TRANSIENT );
		}
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
		$stored = \get_option( Editorial_Settings::OPTION_KEY, array() );
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

	/**
	 * Persist a freshly-composed full body to the full-cache option (#179).
	 * Same structured shape as {@see write_cache()}; `doc_count` counts the
	 * `###` document headings the Full_Composer emits.
	 */
	private static function write_full_cache( string $body ): void {
		$payload = array(
			'schema_version' => self::FULL_CACHE_SCHEMA_VERSION,
			'body'           => $body,
			'generated_at'   => \gmdate( 'c' ),
			'doc_count'      => substr_count( $body, "\n### " ),
		);

		\update_option( self::FULL_CACHE_OPTION, $payload, 'no' );
	}
}
