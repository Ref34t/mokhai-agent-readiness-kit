# AgDR-0013 — Markdown Views URL forms: query + Accept header + `/path.md` rewrite

> In the context of serving the Markdown view of a public URL (`Ref34t/agentready#5`), facing the choice between query-string-only, query+Accept+dotted-suffix path, or query+Accept+virtual-segment path, I decided to support all three forms — `?format=md`, `Accept: text/markdown`, and `/path.md` via a `WP_Rewrite` rule — to achieve alignment with the llms.txt ecosystem convention (where agents expect `/path.md` after discovering URLs in `/llms.txt`), accepting that we ship one rewrite rule with the operational caveats that WP rewrites carry (permalink-flush on activation, hosting-environment variance, edge cases under non-pretty permalinks).

## Context

- The ecosystem agentready is positioning into (llms.txt + Markdown-first agent documentation) has converged on `/path.md` as the canonical raw-markdown URL. Bun's docs, Vercel's docs, the Anthropic docs spec, the LangChain docs, and the original llms.txt examples all serve `/path.md` directly.
- Agents that fetch `/llms.txt` discover URLs and then follow them with an expectation of a `.md` suffix yielding the raw form. If we only ship `?format=md`, those agents either:
  - need site-specific knowledge to know our convention, or
  - fall back to scraping HTML and converting client-side, defeating the deterministic-pass value.
- The Accept-header path is the most "correct" HTTP-wise, but in practice most LLM/agent fetchers do not set custom Accept headers — they fetch URLs literally as they appear in `/llms.txt`. So the path-based form carries the load.
- WordPress's rewrite system handles `/path.md` cleanly when pretty permalinks are enabled (which is `Settings → Permalinks` set to anything but "Plain"). On plain-permalink sites the rewrite doesn't fire; the query form (`?format=md`) is the fallback that always works.
- Adding a `WP_Rewrite` rule is a well-understood pattern but has real operational caveats:
  - Rewrite rules must be flushed when the plugin is activated. Forgetting this is the #1 cause of "blank page on .md URLs". We flush in `register_activation_hook`.
  - Some shared-hosting environments disable `mod_rewrite` or `nginx try_files`, breaking pretty permalinks plugin-wide. Detection isn't reliable; we surface the fallback (`?format=md`) in admin UI when rewrite-rule-test fails.
  - `.md` is rarely conflicting with WP content, but themes occasionally use `.md` for download links or static asset routing. Our rewrite rule includes a precedence check.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Query + Accept + `/path.md` rewrite** | All three forms supported. Ecosystem-aligned. Query form is the always-works fallback. Most flexible. | One `WP_Rewrite` rule to maintain. Flush-on-activation hook. Edge cases with `.md`-using themes (rare, handled by rule precedence). |
| Query + Accept only | Zero rewrite-rule surface. Works on every WP regardless of permalink setting or hosting. | Doesn't match ecosystem `/path.md` convention. Agents that fetch llms.txt and follow URLs with a `.md` suffix get 404. Significant adoption friction in the agent ecosystem. |
| Query + Accept + suffixed virtual segment `/path/md/` | WP-rewrite-friendlier (uses `WP_Rewrite::add_endpoint`). Less likely to conflict with theme routing. | Doesn't match the `.md` convention at all. Neither the ecosystem nor the HTTP-purist crowd. |

## Decision

Chosen: **Query + Accept + `/path.md` rewrite**, because:

1. agentready's positioning is "Agent Readiness for WordPress" and the agent ecosystem has converged on `/path.md`. Diverging from the convention is a positioning own-goal.
2. Real agent fetchers (GPTBot, ClaudeBot, PerplexityBot crawl traces) follow URLs literally — without custom Accept headers. Path-based is what they actually use.
3. The query form (`?format=md`) is the always-works fallback for sites without pretty permalinks. Three forms means we serve every reachable agent regardless of site config.
4. The operational caveats are well-understood WordPress plugin territory. Flushing rewrite rules on activation + a smoke-test in admin that confirms the rule is live is the standard mitigation.

## Consequences

### Rewrite rule

Registered in `MarkdownViewsRouter::register_rewrite_rules()`, called from `init`:

```php
add_rewrite_rule(
  '^(.+)\.md/?$',
  'index.php?agentready_md_request=$matches[1]',
  'top'
);
add_rewrite_tag( '%agentready_md_request%', '([^&]+)' );
```

- Precedence `'top'` so we match before WP's permalink rules try to resolve `/path.md` as a page slug ending in `.md` (unlikely but possible).
- The query var `agentready_md_request` is intercepted in `parse_request` (or `template_redirect`) and routed to the same handler that serves `?format=md`.
- Both forms therefore funnel through one resolution path → one cache lookup → one walker → one response.

### Activation hook

```php
register_activation_hook( AGENTREADY_FILE, function () {
  MarkdownViewsRouter::register_rewrite_rules();
  flush_rewrite_rules();
});
```

- `flush_rewrite_rules()` is expensive; called only on activation, not on every load.
- Deactivation hook unregisters the rule and flushes again, so deactivating the plugin returns the site to vanilla permalink behaviour.

### Content negotiation precedence

When multiple forms hit at once (e.g. a request to `/about-us.md?format=md` with `Accept: text/markdown`), they all produce the same response. They don't fight — they redundantly indicate the same intent. The handler does not 400 / disambiguate / prefer one over another.

### Rewrite-rule liveness check

The Markdown Views admin section in the Context Profile screen (added by `#5` as part of this work) shows a smoke-test row:

- ✅ `?format=md` route reachable
- ✅ `Accept: text/markdown` content negotiation working
- ✅ `/path.md` rewrite rule active (flushes detected)
- ⚠️ `/path.md` rewrite rule registered but not flushed — click "Re-flush rewrites" to fix

When pretty permalinks are off entirely, the third row degrades to:

- ⚠️ `/path.md` requires pretty permalinks (set Settings → Permalinks → anything but "Plain")

### Edge cases handled

- **Sites with `.md` posts/pages by slug**: precedence `'top'` matches our rule first. A page slugged `something.md` becomes unreachable via the path form — surfaced as a warning in admin if detected.
- **Subdirectory installs** (`https://example.com/blog/`): rewrites work — `add_rewrite_rule` is subdirectory-aware.
- **Multisite (subdir and subdomain)**: rewrites register per-site automatically.
- **WP REST API URLs** (`/wp-json/...`): our rule only matches paths ending in `.md`, no collision.
- **Trailing slash variant** (`/about-us.md/` vs `/about-us.md`): regex `\.md/?$` accepts both.

### What this does NOT do

- We do not redirect between forms. `/about-us.md` does not 301 to `?format=md`, nor vice versa. Each form serves the same body at its own URL — agents and humans link to whichever they prefer.
- We do not introduce `.markdown` or `.txt` aliases. `.md` only.
- We do not strip `.html` from the request to produce a "matched" MD path. Requests are resolved literally — `/about-us.md` resolves to the same post as `/about-us/` (the trailing-slash-canonical), not to `/about-us.html`.

## Artifacts

- Ticket: `Ref34t/agentready#5`
- Related AgDRs: AgDR-0010 (walker), AgDR-0011 (cache table — same key regardless of URL form), AgDR-0012 (exposure — same verdict regardless of URL form)
- Implementation files (planned): `includes/markdown-views/Router.php`, `includes/markdown-views/Handler.php`, activation hook in `agentready.php`
