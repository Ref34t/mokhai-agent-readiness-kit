# AgDR-0015 — Markdown Views module disable: 404, preserve cache, keep rewrite

> In the context of the Markdown Views module being toggleable on/off via the Context Profile admin (`Ref34t/agentready#5`, part of the broader Context Profile single-source-of-truth architecture from AgDR-0002), facing the choice between hard-tearing-down (purge cache, unregister rewrite), soft-disabling (404 with cache preserved), or making the toggle admin-UI-only, I decided to soft-disable — `.md` URLs 404, cache rows stay, rewrite rule remains registered — to achieve zero-latency re-enable and zero cost-to-toggle, accepting that disabled-but-installed sites carry residual cache rows in the `wp_agentready_md_cache` table indefinitely until uninstall.

## Context

- Context Profile (`#4`) defines a per-module enable flag. The product positioning is "each module independently toggleable" — agents and users alike should be able to mix and match what's exposed.
- Toggling on/off is expected to be a routine admin action, not a one-time decision. A user might enable for an audit, disable while iterating, re-enable for go-live.
- Cache rows are cheap: one row per public post, body is the post's MD (kilobytes per row, not megabytes). A site with 5000 posts holds ~5–50 MB of cached MD total. Preserving that is operationally invisible.
- Rewrite rule registration is also cheap — it's an array entry loaded on every request anyway. Unregistering it requires another `flush_rewrite_rules()` which is the expensive operation.
- The handler already checks Context Profile exposure (per AgDR-0012). Adding a `Profile::is_module_enabled('markdown-views')` check at the same entry point is a one-line addition and a clean factoring.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **404 + preserve cache + keep rewrite rule** (soft disable) | Zero re-enable latency — flip flag back on, cache still warm. Zero `flush_rewrite_rules()` cost on toggle. Operationally invisible. Handler-side flag check is one line. | Residual cache rows in `wp_agentready_md_cache` survive while disabled. On long-disabled sites, those rows are dead weight (until uninstall drops the table). |
| 404 + purge cache + unregister rewrite rule (hard disable) | Strict cleanup: when off, nothing of the module's runtime state persists. Saves disk on long-disabled sites. | Re-enable means a full cold-cache warm-up — every public URL becomes the slow first hit again. Toggling is no longer free. `flush_rewrite_rules()` runs twice (disable, enable) — measurable on slow shared hosts. |
| Continue serving (toggle admin-UI only) | One less behaviour to model. | Defeats the toggle's purpose. Users who expect a "kill switch" get a surprise. Inconsistent with how Context Profile's other module toggles work. |

## Decision

Chosen: **404 + preserve cache + keep rewrite rule** (soft disable), because:

1. The toggle is meant to be reversible without penalty. Soft-disable preserves that property; hard-disable does not.
2. Residual cache cost on disabled sites is bounded and small. The cleanup-on-uninstall path covers the "no longer using agentready" case where the user actually wants the data gone.
3. The handler-side check is trivial; the alternative (intercepting at the rewrite-rule level) requires more code and provides no functional benefit.
4. This pattern is consistent with how Context Profile's other module toggles should behave (LLMs Index, Context Score, etc.) — soft-disable is a cross-module convention worth establishing early.

## Consequences

### Handler decision flow

In `MarkdownViewsHandler::handle( $request )`:

```
1. Profile::is_module_enabled('markdown-views') === false  →  404
2. Post resolution fails (no matching public post)         →  404
3. Profile::is_url_exposable( $post ) === false            →  404
4. Cache lookup hits                                       →  serve from cache
5. Cache miss                                              →  walker → cache write → serve
```

Step 1 is the new addition for this AgDR. The 404 response is the same shape regardless of *why* — never hint at the difference (no body content leak via response-body branching).

### What persists across disable / enable cycles

| Resource | Disabled state | On re-enable |
|----------|----------------|--------------|
| `wp_agentready_md_cache` rows | Preserved | Reused directly — no re-warm |
| Rewrite rule `^(.+)\.md/?$` | Still registered | Already live |
| Sidebar React panel | Hidden (panel checks the flag and hides itself) | Reappears |
| WP-CLI command | Refuses with a "module disabled" error | Works |
| Admin REST endpoint `agentready/v1/markdown-views/preview` | Returns 403 + a `module_disabled` error code | Works |

### What does NOT persist

- **Plugin uninstall** is a separate path: `register_uninstall_hook` runs `Schema::drop()` (AgDR-0011) and unregisters the rewrite rule. Uninstall is the "I'm leaving" gesture; soft-disable is the "I'm pausing" gesture.

### Wall the data off cleanly

- The handler's `is_module_enabled` check happens **before** any post resolution and **before** any cache read. A disabled module does not touch the cache table — no read, no write, no side effect.
- The sidebar React panel reads the flag on mount. If the flag is off, it doesn't render — no "this module is disabled" message in the editor sidebar, just absence. (Rationale: presence of a "disabled" message on every post-edit screen is admin noise; the toggle lives on the Context Profile page, which is where the user goes to re-enable.)
- The CLI command emits a clear error if invoked while the module is disabled — CLI users are intentional and benefit from explicit "this won't run because X" feedback rather than mysterious 404s.

### Stale-cache risk

If the walker logic changes (e.g. a bug fix in AgDR-0010's converter) while the module is disabled, then re-enabled later, cache rows are stale relative to the new walker. Mitigation: AgDR-0011's `walker_version` column. On re-enable, the handler's cache-validity check compares stored `walker_version` against the current; mismatched rows are treated as cache misses and regenerated lazily. No proactive purge needed.

### Multisite

- Per-site flag. Toggling Markdown Views off on one site has no effect on others.
- Network-level "force off across all sites" is **out of scope for v0.1** — covered (or not) by a future superadmin tool.

## Artifacts

- Ticket: `Ref34t/agentready#5`
- Related AgDRs: AgDR-0002 (Context Profile storage — owns the `is_module_enabled` flag), AgDR-0011 (cache table — `walker_version` column supports lazy re-validation on re-enable), AgDR-0012 (exposure — same single-point-of-check pattern), AgDR-0013 (rewrite rule lifecycle — disable doesn't unregister), AgDR-0014 (admin surfaces — all three respect the flag)
- Files (planned): module-flag check added to `MarkdownViewsHandler`, `RestController`, `MarkdownViewsCommand`, and the React sidebar component's mount guard.
