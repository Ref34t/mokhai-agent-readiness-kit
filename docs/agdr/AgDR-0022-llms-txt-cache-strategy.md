# AgDR-0022 — LLMs Index cache: single non-autoloaded option, hook-driven invalidation

> In the context of `Ref34t/mokhai-agent-readiness-kit#7` Phase A — caching the composed `/llms.txt` body so the public route (AgDR-0021) serves agents from a hot read instead of recomposing on every fetch, facing the choice between a transient, a non-autoloaded `wp_options` entry, a custom table, or a filesystem write, I decided to store one composed body per site in a single non-autoloaded option (`agentready_llms_txt_cache`) holding `{ body, generated_at, schema_version }`, invalidated by the same hook surface that triggers regen (decided in AgDR-0023) rather than by TTL or hash-validation-on-read, and re-populated synchronously on cache miss under a transient lock to prevent thundering-herd regens, to achieve a single canonical cached body with deterministic invalidation semantics and zero filesystem-permission surface, accepting that the cache lives in `wp_options` (one extra non-autoloaded row, ~5–200 KB depending on site size) and that uninstall must delete the option explicitly.

## Context

The public route handler (AgDR-0021) intercepts on `template_redirect` and needs to write the composed body to the response in well under 5s (AC #6 says < 5s is the worst-case fresh regen for ≤ 1000 posts — the cache-hit path must be much faster than that, low-millisecond order).

The composed body is **site-level**, not per-post — there is exactly one `/llms.txt` per WordPress site, regardless of how many posts feed it. So the cache shape is **one body per site**, not Markdown Views' shape (one row per post in a custom table, AgDR-0011).

The body is plain text composed from:
- Site identity (read live from `get_bloginfo()` / `get_locale()` — never cached, but cheap)
- Editorial entries (option `agentready_llms_txt_editorial`, written in Phase C)
- Auto-listed entries (`WP_Query` over `exposed_cpts × exposed_statuses` from the Context Profile)

The expensive bit is the auto-listed query for sites with hundreds of posts. The composition itself (string concatenation + section formatting) is cheap. So we cache the **composed string**, not the intermediate query result.

A few cache-shape options are worth considering:

- **Transient (`set_transient`)**: idiomatic WP. Uses object cache when present. But transients have a TTL — they evict on time, not on content change. We need content-change invalidation (post saves, profile changes), which means we'd be fighting the TTL: either set it long enough that stale risk is real, or short enough that we regen on schedule even when nothing changed. Both are wrong shapes for an output that should mirror content lifecycle, not clock.
- **Non-autoloaded option (`update_option(..., '', 'no')`)**: predictable, survives object-cache eviction, only loaded when the public route or admin preview reads it. One SQL query per public hit when no object cache; one object-cache hit when present.
- **Custom table**: massive overkill for a single-row cache. The justification AgDR-0011 used (one row per post, indexed by `(post_id, content_hash)`) doesn't apply to a site-level singleton.
- **Filesystem write**: rejected for the same reasons AgDR-0021 rejected static-file serving (webroot permissions, multisite path resolution, conflict-detection collision).

The hash-validated-on-read pattern that AgDR-0011 uses for Markdown Views isn't right here either: it makes sense per-post because content_hash is cheap to recompute (we already have the HTML in hand), but for the composed `/llms.txt` body the "hash of inputs" requires a `WP_Query` to compute `max(post_modified_gmt)` plus a re-read of the entire Context Profile. That's most of the cost we're trying to avoid. Better to invalidate from hooks (event-driven) than to validate on every read (poll-driven).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Non-autoloaded option `agentready_llms_txt_cache` with hook-driven invalidation** | Predictable lifecycle: hook fires → invalidate → next read regens. Survives object-cache eviction. Cheap read (1 SQL query non-object-cached, 1 cache hit otherwise). One canonical body per site. Inspectable: admins can dump the option to verify what was last composed. Multisite-correct (option is per-site). | Cache size lives in `wp_options` — could be 100s of KB on a 1000-post site (still under MySQL row limits; non-autoloaded so doesn't bloat the autoload set). Uninstall must explicitly `delete_option()`. |
| B — Transient `agentready_llms_txt_cache` with hook-driven invalidation | Idiomatic WP cache surface. Uses object cache when present. | TTL semantics fight content-driven invalidation: if we set a long TTL we'll get stale reads on edge-case missed hooks; if we set a short TTL we regen unnecessarily. Object-cache eviction is unpredictable on multi-tenant hosts. |
| C — Hash-validated read against a custom table | Bulletproof staleness story (impossible to read a stale body — hash mismatch forces regen). | Re-introduces the per-read cost we're caching to avoid (must `max(post_modified_gmt)` and re-hash the profile on every public hit). Custom-table overhead for a single-row cache. Schema migration burden. |
| D — Filesystem write to `wp-content/agentready/llms-cache.txt` (private, not webroot) | Zero DB surface. Easy to inspect. | Filesystem permissions are a support nightmare on hardened hosts. Multisite needs per-site path. wp.org review flags filesystem writes outside the standard channels. Solves no problem option-storage doesn't already solve. |

## Decision

Chosen: **Option A — non-autoloaded option `agentready_llms_txt_cache` holding `{ body, generated_at, schema_version }`, invalidated from the regen hooks decided in AgDR-0023.**

Concrete shape:

```php
// Cache option payload
[
    'schema_version' => 1,
    'body'           => "# Site Name\n\n…composed llms.txt…\n",
    'generated_at'   => '2026-05-18T17:24:33Z',
    'entry_count'    => 142,  // diagnostic only, not load-bearing
]
```

Stored via:

```php
update_option(
    'agentready_llms_txt_cache',
    $payload,
    /* autoload */ 'no'
);
```

Reader (called from `LlmsTxt\Router` template_redirect handler):

```php
public static function get_composed_body(): string {
    $cache = get_option( 'agentready_llms_txt_cache', null );
    if ( is_array( $cache ) && isset( $cache['body'] ) ) {
        return (string) $cache['body'];
    }
    // Cache miss: regen synchronously under lock, return result.
    return self::regen_under_lock();
}
```

Regen-under-lock prevents thundering herd if a crawler swarm hits a cold cache:

```php
private static function regen_under_lock(): string {
    $lock = 'agentready_llms_txt_regen_lock';
    if ( false === get_transient( $lock ) ) {
        set_transient( $lock, time(), 30 ); // 30s ceiling — far longer than the 5s AC budget
        $body = LlmsTxt\Composer::compose();
        update_option(
            'agentready_llms_txt_cache',
            [
                'schema_version' => 1,
                'body'           => $body,
                'generated_at'   => gmdate( 'c' ),
                'entry_count'    => substr_count( $body, "\n- " ), // diagnostic
            ],
            'no'
        );
        delete_transient( $lock );
        return $body;
    }
    // Another process is regenerating. Return an empty body — the next
    // fetch (or the cron backstop) will get the fresh one. Empty is a
    // valid llms.txt per FR-9, so no error response.
    return '';
}
```

## Consequences

### Cache size

The composed body for a site with 1000 posts in a single CPT is roughly:

- ~100 bytes of identity block
- ~80 bytes per auto-listed entry (URL + one-line description)
- → ~80 KB total for 1000 posts

This fits comfortably in `wp_options.option_value` (LONGTEXT). Non-autoloaded means the autoload cache stays slim; the value is only read when the public route or admin preview asks for it.

The Phase B conflict detector surfaces an admin warning if the cache approaches 1 MB — a deliberate ceiling because at that scale the spec's "agent-readable index" framing breaks down and admins should be using `/llms-full.txt` (v0.1.1 scope) or paginated discovery instead.

### Invalidation triggers (deferred to AgDR-0023)

This AgDR commits to: invalidation is **hook-driven**, not **read-validated**. The full hook list and debounce strategy is AgDR-0023's call. This AgDR commits only to the data shape and the cache-miss → regen-under-lock contract.

For the regen-trigger interface, `LlmsTxt\Service` exposes:

```php
LlmsTxt\Service::invalidate();         // delete the option; next read regens
LlmsTxt\Service::regen_async();        // schedule background regen (AgDR-0023)
LlmsTxt\Service::regen_sync(): string; // immediate regen, returns body (used by cache-miss path)
```

### Activation behaviour

On activation, `Main::on_activate()` calls `LlmsTxt\Service::regen_sync()` so the cache is populated before any crawler hits. This means fresh installs with empty `exposed_cpts` get an empty composed body cached — the public route serves the empty body on the first hit (correct per AC #5, empty default).

### Uninstall behaviour

`uninstall.php` adds:

```php
delete_option( 'agentready_llms_txt_cache' );
delete_transient( 'agentready_llms_txt_regen_lock' );
```

Per-site iteration on multisite (matches the AgDR-0011 multisite pattern).

### Schema version field

The `schema_version` integer on the payload exists so future cache-format changes (e.g. storing pre-compressed body in v2) don't blow up on read. The reader treats unknown `schema_version` as a miss and regens. Same pattern as the Context Profile's `schema_version` (AgDR-0002).

### Observability

WP-CLI command (to be added in Phase A): `wp agentready llms-txt status` exposes:

- Whether the cache is populated
- `generated_at` timestamp
- Entry count
- Body size in bytes
- Whether the regen lock is held

This makes the "is my llms.txt fresh?" question answerable without inspecting `wp_options` directly.

### Object-cache interaction

The reader calls `get_option()`, which uses the standard WP options object cache. Sites with persistent object cache (Redis, Memcached) get an in-memory hit. Sites without it pay one SQL query per public-route hit — still ~1 ms order, well within the cache-hit latency budget.

### What this does NOT do

- We do not validate the cached body's freshness on read. Hook-driven invalidation is trusted; if a hook is missed, the daily cron backstop (AgDR-0023) catches it.
- We do not cache per-User-Agent. The body is uniform for every fetcher.
- We do not cache HTTP-level headers. `Cache-Control` is set fresh by the handler on each response (AgDR-0021).
- We do not gzip the cached body in storage. Compression happens at the response layer if the server / CDN handles it; storing pre-compressed bodies wins ~3× on disk but loses inspectability and forces a content-encoding decision on every read.
- We do not version the cache key per-Context-Profile-hash. Invalidation is total (delete the option), not partial. There's only one document per site — partial invalidation doesn't apply.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#7`
- Related AgDRs: AgDR-0021 (serving mechanism reads from this cache), AgDR-0023 (regen debounce — invalidation triggers), AgDR-0011 (Markdown Views cache — different shape because per-post, not site-level), AgDR-0002 (Context Profile read in `Composer::compose()`)
- Implementation files (planned): `includes/LlmsTxt/Service.php` (cache I/O), `includes/LlmsTxt/Composer.php` (regen logic), `uninstall.php` (cleanup)
