# AgDR-0023 — LLMs Index regen debounce via `wp_schedule_single_event` + daily cron backstop

> In the context of `Ref34t/agentready#7` Phase A — keeping the `/llms.txt` cache (AgDR-0022) coherent with the underlying content without regenerating on every individual post save, facing the choice between (a) WP's native `wp_schedule_single_event` with a short delay and `wp_next_scheduled` idempotency check (the same idiom `Markdown_Views\Cleanup_Orchestrator` uses), (b) Action Scheduler as an external dependency, (c) a transient-lock debounce with no scheduled fallback, or (d) synchronous regen on every trigger, I decided to schedule a single async regen event 5 seconds out via `wp_schedule_single_event` — coalescing bursts of `save_post` / `wp_trash_post` / profile-update events through the `wp_next_scheduled` idempotency check, plus a daily cron backstop on `agentready_llms_txt_daily_regen` for hook misses and long-idle sites — to achieve burst coalescing on bulk-edit, predictable freshness on single edits, zero external dependencies, and a guaranteed daily floor on cache age, accepting that we depend on WP cron firing within roughly the normal ~5s margin (which can stretch on low-traffic sites) and that we own one async action and one cron schedule.

## Context

AgDR-0022 commits to hook-driven invalidation: regen runs when content or profile changes, not on a TTL. But "fires on every hook" is too aggressive — a bulk-edit of 50 posts triggers 50 `save_post` events in ~1 second, and we don't want 50 regens. The composer is roughly O(N posts) where N is the size of `exposed_cpts × exposed_statuses`; on a 1000-post site that's ~3-4 seconds per regen. Fifty back-to-back regens would put `/llms.txt` regen at the top of the slow-query report.

We need a debounce: collapse a burst of triggers into one regen at the end of the burst.

The codebase already has prior art. `Markdown_Views\Cleanup_Orchestrator::schedule()` (Phase A of `#6`) coalesces per-post LLM cleanup runs using:

```php
if ( false !== wp_next_scheduled( SCHEDULE_ACTION, $args ) ) {
    return; // already pending — coalesce
}
wp_schedule_single_event( time() + 1, SCHEDULE_ACTION, $args );
```

That's per-post coalescing. For LLMs Index, the regen target is **site-level** (one regen covers everything) — so we don't need per-post args, we need a single named action with no args. Any trigger schedules the same event; subsequent triggers within the debounce window find an existing pending event and noop.

The daily cron backstop matters because:
- WP cron only fires when requests hit the site. A site with no traffic for 12 hours has its cron quiet for 12 hours; if a hook was missed during that window, the cache stays stale. The daily event re-evaluates on the next request after midnight UTC.
- External writes that bypass `save_post` (direct SQL, REST plugin shims that skip hooks, `wp_insert_post` with `wp_after_insert_post` filters off) leave the cache stale. The daily regen catches those.
- Editorial-entries option (`agentready_llms_txt_editorial`) is updated via a Phase C `update_option` hook — but if a future module bypasses that surface (e.g. WP-CLI editing the option directly), the daily cron is the safety net.

The choice between native WP cron and Action Scheduler is a real one. Action Scheduler (the woocommerce/action-scheduler library) handles back-pressure, batches naturally, has its own admin UI, and is the industry choice for high-throughput async work. But it's a heavyweight dependency: ~150 KB extra, a custom table set, and a wp.org-review surface for the bundled library. For a site-level singleton regen that fires at human-edit rate (not machine-burst rate), it's overkill. Markdown Views (#6 cleanup) made the same call.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — `wp_schedule_single_event` + `wp_next_scheduled` idempotency + daily cron backstop** | Same idiom `Markdown_Views\Cleanup_Orchestrator` already uses (codebase consistency). Zero external deps. Cron is fired by every WP request — adequate for human-edit rate. Daily backstop guarantees freshness floor. Inspectable via WP-CLI cron commands. | WP cron isn't a true scheduler — depends on requests hitting the site to fire. On a zero-traffic site, the scheduled event can stretch beyond the debounce delay. Acceptable: a zero-traffic site has no agents fetching `/llms.txt` either, so freshness latency is invisible. |
| B — Action Scheduler dependency | Battle-tested back-pressure handling. Built-in admin UI. Handles thousands of pending actions cleanly. | ~150 KB additional code shipped. Custom table set adds wp.org-review surface beyond what we already have (Markdown Views cache table). Overkill for human-edit-rate regen. Locks us into a major dependency for a feature that needs maybe 1-10 actions per day. |
| C — Transient-lock debounce with no scheduled fallback | Simplest implementation (no cron at all). Regen fires inline on the next public-route hit if the transient lock has expired. | Requires a public-route hit to trigger regen. Low-traffic sites that update content overnight serve stale `/llms.txt` until the first crawler the next day forces a regen — that's the regen-on-first-hit pattern AgDR-0022 already sets up for cache misses, but here we'd be using it for content-driven invalidation, which is wrong. Misses the "daily floor on cache age" requirement entirely. |
| D — Synchronous regen on every trigger | Trivially correct (cache is always fresh). | Bulk-edit case = ~3-4s × 50 posts = ~150s of `save_post` latency. Admins doing bulk operations would experience the plugin as slow. Catastrophic UX on the one action plugins should never make slower. |

## Decision

Chosen: **Option A — `wp_schedule_single_event` with a 5-second delay and `wp_next_scheduled` idempotency check, plus a `daily` `wp_schedule_event` backstop.**

### Trigger surface (Phase A)

| Hook | Why it triggers regen |
|------|-----------------------|
| `save_post` | Any post update may change the auto-listed entries. Filter to exposed statuses before scheduling — saves on draft posts that won't appear in the index don't trigger regen. |
| `wp_trash_post` | A trashed post leaves the index. |
| `before_delete_post` | A deleted post leaves the index. (We hook `before_delete_post` rather than `deleted_post` so the index is regenerated with the deletion already pending, matching the deletion-visible-to-agents window.) |
| `wp_after_insert_post` | Programmatic post creation (REST, WP-CLI, `wp_insert_post` callers) lands here. |
| `agentready_context_profile_saved` | Profile changes — `exposed_cpts` / `exposed_statuses` — are the most impactful invalidation; the entire composition rewrites. |
| `agentready_llms_txt_editorial_saved` | Phase C action, fired when editorial entries are added/edited/removed. (Hook name reserved here; Phase C wires it.) |

### Action surface

```php
// includes/LlmsTxt/Service.php
public const REGEN_ACTION       = 'agentready_llms_txt_regen';
public const DAILY_REGEN_ACTION = 'agentready_llms_txt_daily_regen';

public const DEBOUNCE_DELAY = 5; // seconds

public static function schedule_regen(): void {
    if ( false !== wp_next_scheduled( self::REGEN_ACTION ) ) {
        return; // already pending — coalesce
    }
    wp_schedule_single_event( time() + self::DEBOUNCE_DELAY, self::REGEN_ACTION );
}

public static function register_hooks(): void {
    // Async regen action — fires once per debounce window.
    add_action( self::REGEN_ACTION,       [ self::class, 'do_regen' ] );
    add_action( self::DAILY_REGEN_ACTION, [ self::class, 'do_regen' ] );

    // Content triggers.
    add_action( 'save_post',            [ self::class, 'on_post_change' ], 10, 2 );
    add_action( 'wp_trash_post',        [ self::class, 'on_post_change' ] );
    add_action( 'before_delete_post',   [ self::class, 'on_post_change' ] );
    add_action( 'wp_after_insert_post', [ self::class, 'on_post_change' ], 10, 1 );

    // Profile triggers.
    add_action( 'agentready_context_profile_saved',    [ self::class, 'schedule_regen' ] );
    add_action( 'agentready_llms_txt_editorial_saved', [ self::class, 'schedule_regen' ] );
}

public static function on_post_change( int $post_id, ?\WP_Post $post = null ): void {
    // Filter: only schedule if the post is in (or was in) an exposed status.
    // Cheap pre-check — avoids scheduling on draft saves for sites that don't
    // expose drafts. The composer's WP_Query in do_regen() is the source of
    // truth; this is just a no-op early-out for the common case.
    $profile = Context_Profile_Settings::get_profile();
    $post    = $post ?: get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_status, $profile['exposed_statuses'], true ) ) {
        return;
    }
    if ( ! in_array( $post->post_type, $profile['exposed_cpts'], true ) ) {
        return;
    }
    self::schedule_regen();
}

public static function do_regen(): void {
    self::regen_sync(); // delegates to the AgDR-0022 cache-write path
}
```

### Daily cron backstop

Activation hook adds:

```php
if ( false === wp_next_scheduled( LlmsTxt\Service::DAILY_REGEN_ACTION ) ) {
    wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', LlmsTxt\Service::DAILY_REGEN_ACTION );
}
```

Deactivation hook clears:

```php
wp_clear_scheduled_hook( LlmsTxt\Service::REGEN_ACTION );
wp_clear_scheduled_hook( LlmsTxt\Service::DAILY_REGEN_ACTION );
```

### Why 5 seconds and not 1 second

`Markdown_Views\Cleanup_Orchestrator` uses 1 second. That's right for per-post cleanup (one event per post — debounce only matters if the post is saved twice in rapid succession, rare in human-edit traffic). For LLMs Index, the bulk-edit case is the load-bearing one — a 50-post bulk update should coalesce into one regen, not fifty regens-staggered-by-one-second. 5 seconds is long enough to swallow a typical bulk-edit burst (admin clicks Apply, all 50 saves complete within ~3-4 seconds in our profiling on Markdown Views #6 work, leaving 1-2 seconds of margin) and short enough that single-post saves see a fresh `/llms.txt` quickly.

Not configurable. Phase C does not expose this as an admin setting — admins don't have the mental model to tune cron debounce windows, and a "regen delay" UI invites confusion ("why isn't my llms.txt updated yet?"). If post-launch data shows 5s is wrong, AgDR-tbd revisits.

## Consequences

### Coalescing in practice

A 50-post bulk edit triggers `save_post` 50 times. The first call schedules an event 5s out. The next 49 calls see `wp_next_scheduled` return a truthy value and noop. At T+5s, the cron fires once, runs `do_regen()`, and the cache is fresh.

A single-post save: same flow, just with N=1.

A profile save: `agentready_context_profile_saved` fires once, schedules a regen 5s out. If the admin then immediately edits a post, both triggers see the same scheduled event — coalesced into one regen.

### Interaction with the AgDR-0022 cache-miss path

The transient regen-lock in AgDR-0022 handles a different scenario: a crawler hits `/llms.txt` when the cache is empty (e.g. just after activation, before the first cron fired). That path regenerates synchronously. The two paths can race only briefly:

- T+0: cache empty. Crawler hits `/llms.txt`. AgDR-0022's lock-and-regen path starts.
- T+1: `save_post` fires. AgDR-0023's `schedule_regen()` schedules an event 5s out.
- T+3: AgDR-0022's regen finishes; cache is populated; lock released.
- T+5: scheduled event fires; `do_regen()` runs again; cache is overwritten with the post-save state.

The double-regen at the race window is acceptable — it's a one-time cost when activating into an already-busy site, and the end state is correct. No coordination between the two paths is needed.

### Cron failure modes

- **Site with zero traffic between scheduled fire-time and the next request**: the event fires whenever the next request comes in. Stale-cache window = idle window. Agents fetching `/llms.txt` *are* the request that triggers cron, so freshness latency is invisible to them.
- **WP-CLI sites with `wp cron disable`**: cron events don't fire. Sites in that posture run `wp cron event run` from a system cron — our event fires on the system cron's cadence. Documented in Phase C readme. Same posture as Markdown Views `#6`.
- **Daily event missed entirely** (e.g. site offline for a week): when the site comes back, WP cron resumes; the daily event re-fires once (WP doesn't replay missed events) and the cache is fresh.

### Observability

The Phase A WP-CLI command `wp agentready llms-txt status` (added under AgDR-0022) gains two more fields:

- Next scheduled regen (timestamp / "none pending")
- Next daily cron (timestamp)

Plus a manual trigger: `wp agentready llms-txt regen` — calls `do_regen()` synchronously, bypassing cron entirely. Useful for support workflows and CI smoke-tests.

### What this does NOT do

- We do not introduce a per-trigger event queue. The cron action is fire-and-forget; the regen reads current state from the cache-write path, not from event arguments.
- We do not adjust the debounce window dynamically based on activity. 5s is fixed.
- We do not bypass cron in favour of a pageload-end hook (`shutdown`). Inline regen on `shutdown` would add ~3-4s of post-response latency to whichever poor request triggered the save — same UX hazard Option D rejected.
- We do not add a "pause regen" admin toggle. The composer respects empty `exposed_cpts` (FR-9) — if an admin wants `/llms.txt` to stop updating, they remove the exposed CPTs from the Context Profile, which itself triggers a regen to the empty body. Pausing the cron without pausing the inputs would be a foot-gun.

## Artifacts

- Ticket: `Ref34t/agentready#7`
- Related AgDRs: AgDR-0021 (serving mechanism — regen-driven cache feeds this), AgDR-0022 (cache shape — where regen writes), AgDR-0002 (Context Profile fields read by `on_post_change` filter), AgDR-0016 / 0017 / 0018 / 0020 (Markdown Views cleanup orchestrator — same debounce idiom)
- Implementation files (planned): `includes/LlmsTxt/Service.php` (hooks + scheduling), `includes/LlmsTxt/Composer.php` (regen body), activation/deactivation wiring in `includes/Main.php`
