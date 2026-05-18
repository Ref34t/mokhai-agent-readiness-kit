# AgDR-0021 — `/llms.txt` serving via rewrite rule + `template_redirect` (vs filesystem write)

> In the context of `Ref34t/agentready#7` Phase A — serving a single canonical `/llms.txt` document composed at request time from the Context Profile, editorial entries, and auto-listed content, facing the choice between (a) a WordPress rewrite rule + `template_redirect` handler that streams the composed body, (b) writing the composed file to the webroot on every regen so the web server serves it as a static file, and (c) a query-var endpoint only, I decided to ship a rewrite rule (`^llms\.txt/?$`) + `template_redirect` handler — the same shape AgDR-0013 chose for `/path.md` — and serve the body from the transient cache decided in AgDR-0022, to achieve a single resolution path that survives multisite, subdir installs, hosting variance, and the WP capability/filter model with zero filesystem-permission surface, accepting that we depend on pretty permalinks being enabled (which we already require for `/path.md`) and that every request pays one transient read (negligible — autoloaded path).

## Context

Per `Ref34t/agentready#7`'s AC #1, the document must be reachable at `https://site.com/llms.txt` and return a valid llms.txt v1 document. The spec ([llmstxt.org](https://llmstxt.org)) pins this path — no version segment, no query string is acceptable to the agent fetchers we're targeting (GPTBot, ClaudeBot, PerplexityBot, the Anthropic docs spec fetcher).

We have prior art in the codebase: Markdown Views (`#5` / AgDR-0013) already ships a rewrite rule (`^(.+)\.md/?$`) handled in `template_redirect`. The same machinery — `Router::register_hooks()` adds the rule on `init`, `flush_on_activation()` persists it into `wp_options`, `template_redirect` intercepts and exits before WP loads the theme. The trade-offs are the same ones AgDR-0013 walked through, plus one new dimension specific to llms.txt: the document is **site-level**, not post-level. There is no slug to extract, no per-URL routing decision — just one URL with one response.

The new dimension this AgDR has to decide that AgDR-0013 didn't: should we write the composed body to `/llms.txt` on the filesystem so the web server serves it as a static file? That gets us zero PHP overhead per fetch and matches what some competing plugins do (e.g. `wp-llms-txt`). Static-file serving is genuinely appealing for an endpoint that:

- Is fetched repeatedly by crawlers
- Has no per-request personalisation
- Has no auth surface

But it carries filesystem-permission baggage (webroot must be writable, conflicts with hardened hosts, multisite path resolution is messy), and it splits the response source between "live PHP" (admin preview / REST) and "static file" (public route) — exactly the failure mode AgDR-0014 called out for Markdown Views previews. Filesystem writes also collide directly with AC #4 (conflict detection — if another plugin already wrote `/llms.txt` to the webroot, ours fights it on every regen).

A query-var-only endpoint (e.g. `/?agentready_llms_txt=1`) is the always-works fallback but violates AC #1: agents fetch the literal `/llms.txt` URL, not a query form. Same argument that ruled it out as the primary form for `/path.md` in AgDR-0013.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Rewrite rule + `template_redirect` handler, body from transient cache** | Same machinery as `/path.md`; one resolution path; zero filesystem surface; works on multisite, subdir, hardened hosts; admin preview + public route share one source; cache hit is one autoloaded `wp_options` read. | Pretty permalinks required (already required for `/path.md`); rewrite-rule flush on activation; PHP boot per request (transient hit < 1ms, but still a PHP boot — acceptable for an endpoint crawled at human-news intervals, not hot-path). |
| B — Filesystem write to webroot (`/llms.txt`) on every regen, web server serves static | Zero PHP overhead per fetch; matches some competing plugins (`wp-llms-txt`) so admins migrating from those expect this shape. | Webroot must be writable (fails on hardened hosts, immutable-infra setups, container images with read-only fs); multisite path resolution is messy (one fs path, N sites); conflicts directly with AC #4 (if a competing plugin already wrote the file, ours fights it on every regen); admin preview + public route diverge (one is live, one is the last-flushed file); deactivation has to delete the file and we own its presence forever after first activation. |
| C — Query-var only (`/?agentready_llms_txt=1`) | Zero rewrite-rule surface; works on every WP regardless of permalink setting. | Violates AC #1 — the spec and every real agent fetcher use the literal `/llms.txt` URL. No agent will discover the query form. Same argument that ruled it out for `/path.md` in AgDR-0013. |
| D — Rewrite + `template_redirect` **plus** opportunistic static-file write on regen | Static-file fast path when filesystem writable; PHP fallback always available. | All the cons of B (webroot permissions, conflict detection, divergent sources) without the simplicity payoff; two code paths to test; "sometimes static, sometimes PHP" debugging story is brutal. |

## Decision

Chosen: **Option A — rewrite rule + `template_redirect` handler, body from transient cache.**

Concrete shape:

```php
// includes/LlmsTxt/Router.php (planned)
add_rewrite_rule( '^llms\.txt/?$', 'index.php?agentready_llms_txt=1', 'top' );
add_rewrite_tag( '%agentready_llms_txt%', '1' );
```

Handler on `template_redirect`:

```php
if ( '1' === get_query_var( 'agentready_llms_txt' ) ) {
    // Cache hit path — one transient read.
    $body = LlmsTxt\Service::get_composed_body(); // AgDR-0022 decides storage.
    nocache_headers();
    header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
    header( 'X-Robots-Tag: noindex, nofollow' );
    echo $body; // Already sanitised by composer; no esc_html (raw text/plain by design).
    exit;
}
```

- Precedence `'top'` so we match before WP's permalink rules try to resolve `/llms.txt` as a page slug.
- `flush_rewrite_rules()` on activation + deactivation, same pattern as `Markdown_Views\Router`.
- `X-Robots-Tag: noindex` because `/llms.txt` is for agents — we don't want it appearing in Google site-search results.
- `Content-Type: text/plain` (not `text/markdown`) because the spec serves llms.txt as plain text; clients that care about MD interpretation parse the body themselves.
- `nocache_headers()` because the response is cheap to recompute on cache miss and the agent fetchers we serve don't honour `Cache-Control` consistently — better to let our transient be the cache, not the HTTP layer.

## Consequences

### Same activation surface as Markdown Views

`Main::on_activate()` already calls `Markdown_Views\Router::flush_on_activation()`. Phase A adds `LlmsTxt\Router::flush_on_activation()` next to it. Both rules persist into one `rewrite_rules` option write per activation — no extra cost.

### Same deactivation behaviour

Deactivation unregisters the `init` hook and flushes again. The rewrite rule disappears; the cached body in transients survives (cheap to recompute on re-activation).

### Public route is uniformly `200` or `404`, never `500`

If the transient is empty AND auto-regen on activation hasn't run (e.g. activation hook errored mid-way), the handler returns an empty body with `200`. An empty `/llms.txt` is a valid llms.txt — it just lists no entries. We do NOT 404 on an empty composition because a fresh install with `exposed_cpts = []` is exactly the FR-9 "empty default" case in AC #5.

### Admin preview shares the same source

Phase C will add a `agentready/v1/llms-txt/preview` REST route that calls `LlmsTxt\Service::get_composed_body()` — the same method the public handler calls. No divergence between preview and public output. (This is the AgDR-0014 lesson applied: one source, two surfaces.)

### Conflict with other plugins' rewrite rules

If a competing plugin registers `^llms\.txt/?$` at `'top'` precedence and adds its hook before ours, our handler never fires — WP serves the other plugin's response. AC #4 handles this: the Phase B conflict detector (AgDR-tbd, sibling of this AgDR) scans `$wp_rewrite->wp_rewrite_rules()` for a competing rule and surfaces an admin notice with one-click resolution. We do NOT fight rewrites at runtime (last-registered-wins races are unreviewable).

If a competing plugin writes a static `/llms.txt` to the webroot, the web server serves the file before WP boots — our rewrite never fires. Same Phase B conflict detector handles this by `file_exists( ABSPATH . 'llms.txt' )`.

### Edge cases handled

- **Sites with a page slugged `llms.txt`**: `'top'` precedence ensures our rule wins. We log a one-time admin notice in Phase C if such a page exists at activation time.
- **Subdirectory installs** (`https://example.com/blog/`): rewrites work — `add_rewrite_rule` is subdirectory-aware. The URL is `/blog/llms.txt`. Agents that respect the llms.txt spec discover via `/llms.txt` at the canonical site root; subdirectory installs are a known second-class case the spec doesn't bless. We surface this in the Phase C admin notice (suggesting site-root `.well-known/`-style discovery as a v0.1.1 follow-up).
- **Multisite (subdir and subdomain)**: rewrites register per-site automatically. Each site composes its own document from its own Context Profile.
- **Plain permalinks**: rewrite doesn't fire. Phase C admin notice flags this as a hard requirement (same flag Markdown Views already shows). No query-var fallback — the spec doesn't accept it.
- **Trailing slash variant** (`/llms.txt/`): regex `\.txt/?$` accepts both.

### What this does NOT do

- We do not write to the filesystem at any point. The webroot stays read-only from our perspective. (Conflict detection in Phase B is a *read* of `file_exists`, not a write.)
- We do not serve the document over `/wp-json/` (the JSON REST surface is for the admin preview, not the public agent route).
- We do not gzip the response ourselves — let the web server / CDN handle compression on the `text/plain` Content-Type as usual.
- We do not respond to HEAD requests with custom headers — WP's default HEAD handling on `template_redirect` exit is sufficient.

## Artifacts

- Ticket: `Ref34t/agentready#7`
- Related AgDRs: AgDR-0013 (`.md` URL forms — same machinery), AgDR-0014 (admin preview shares source with public route), AgDR-0022 (cache storage — pending), AgDR-0023 (regen debounce — pending), AgDR-0002 (Context Profile reads)
- Implementation files (planned): `includes/LlmsTxt/Router.php`, `includes/LlmsTxt/Service.php`, activation wiring in `includes/Main.php`
