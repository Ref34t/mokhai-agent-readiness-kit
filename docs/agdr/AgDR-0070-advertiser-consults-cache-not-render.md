# AgDR-0070 — Advertiser suppresses known-empty twins via a cheap cache read (not a render)

> In the context of **`Alternate_Advertiser` emitting a `.md` `rel=alternate` (and `Link:` header) that 404s for pages whose Markdown twin is empty, since AgDR-0068's empty-twin guard made those routes 404 (#296)**, facing **the fact that the obvious fix — calling `Service::is_empty_for_post()` on every HTML render — now triggers a full render on a cache miss, which (post-AgDR-0069) means a synchronous loopback HTTP fetch on the front-end hot path** — we decided **to have the advertiser consult a new cheap `Service::is_known_empty_twin()` that reads the already-written cache row directly (one indexed PK lookup, no render, no loopback) and suppress advertising only for twins KNOWN to be empty**, to achieve **an honest advertise-guarantee without adding render/HTTP cost to page views**, accepting **that a not-yet-rendered genuinely-empty page may still 404 once on first agent fetch before it self-suppresses, and that the read is deliberately content-hash-agnostic (tolerates a stale verdict).**

## Context

- #296 was surfaced in the Rex review of PR #295. `Alternate_Advertiser::current_md_url()` re-implements the exposure gate (`is_module_enabled` + `is_url_exposable`) and does not consult AgDR-0068's empty-content guard, so its documented invariant — *"an advertised `.md` always resolves (no 404)"* — went stale: a genuinely-empty page is now advertised but 404s.
- The issue's suggested fix, `Service::is_empty_for_post()`, calls `get_markdown_for_post()`, which on a cache miss renders — and **after AgDR-0069 that render can fire a loopback HTTP fetch**. Putting that on `wp_head` / `send_headers` would add a synchronous fetch to normal page views (and lean on the AgDR-0069 B1 lock to avoid a storm). That is the wrong cost on the hot path.
- The cache row is the source of truth for "is this twin empty." `Service::get_markdown_for_post()` writes the (possibly empty) conversion to the cache **before** the empty guard returns its `WP_Error` — so once a page has been rendered once (by the static mirror at publish, the daily sync cron, or the first `.md` fetch), its row reflects emptiness. Reading that row is cheap and render-free.
- Severity is LOW (a broken alternate link on genuinely-empty pages only; `/llms.txt` already drops them via #292). And post-AgDR-0069 the empty set shrank sharply — template-rendered pages now recover content — so the residual empty case is rare.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — cheap cache read (`is_known_empty_twin`), suppress only KNOWN-empty; optimistic when unknown** | No render / no loopback on the hot path — one indexed PK `SELECT`. Preserves advertising for every content page (row non-empty OR not-yet-rendered). Self-corrects: an empty page's row is written on first render, then suppressed. No recursion with the AgDR-0069 loopback. | A never-rendered genuinely-empty page 404s once on first fetch before self-suppressing. Read is content-hash-agnostic (tolerates a stale verdict). |
| B — `is_empty_for_post()` (issue's suggestion) | Exact, hash-accurate verdict. | Renders on cache miss → **synchronous loopback fetch on every HTML page view** post-AgDR-0069; leans on the B1 lock to avoid a storm. Wrong hot-path cost. |
| C — pessimistic: advertise only when a non-empty row is known | Strictly honours "advertised `.md` always resolves." | Under-advertises **every** content page until its twin is first cached — badly hurts the main feature for the common case. |
| D — advertiser renders/pre-warms the twin before advertising | Always accurate. | Even worse hot-path cost than B; couples advertising to rendering. |

## Decision

Chosen: **Option A**. It removes the hot-path render/HTTP cost that B and D add, preserves common-case advertising that C sacrifices, and its one downside (a single first-fetch 404 on a not-yet-rendered empty page) is rare, low-harm, and self-correcting. The advertise-guarantee is restated honestly (below) rather than silently broken.

Reading the cache row directly — instead of `is_empty_for_post()` — also **sidesteps the AgDR-0069 loopback entirely**: `is_known_empty_twin()` never calls the render path, so no loopback fires from `wp_head`/`send_headers` and the B1 recursion lock is left as defence-in-depth, not a load-bearing dependency of this fix.

### Shape

- **`Markdown_Views\Service::is_known_empty_twin(\WP_Post $post): bool`** — a single indexed lookup `SELECT markdown FROM <cache> WHERE post_id = %d`, returning `true` **only** when a row exists AND its markdown is empty (reuses the private `is_markdown_empty()` empty-detection point). No row (unknown) or non-empty → `false`. Deliberately **content-hash-agnostic**: it reads the last cached render regardless of the current hash, because advertising tolerates a stale verdict (both stale directions self-correct on the next render) and this keeps the hot path free of the `content_hash` computation — which, on ACF sites, would otherwise incur the AgDR-0068 F3 per-call ACF read. The hash-exact path stays where correctness matters: the actual `.md` route in `get_markdown_for_post()`.
- **`Discovery\Alternate_Advertiser::current_md_url()`** — after the existing exposability gate, returns `''` when `Service::is_known_empty_twin($post)` is `true`. One change covers both surfaces (`render_head_links` + `send_link_header`) since both resolve through `current_md_url()`.
- **Docblock invariant corrected** — from *"an advertised `.md` always resolves (no 404)"* to *"never advertises a twin known to be empty; a not-yet-rendered genuinely-empty page may 404 once on first agent fetch, then self-suppresses once its empty row is cached."*

## Consequences

- Known-empty pages stop advertising a dead `.md`; content pages (the common case) are unaffected.
- One new indexed PK `SELECT` per exposable singular HTML view — cheap, render-free, loopback-free, object-cache-friendly. No `content_hash`/ACF read on this path.
- A not-yet-rendered genuinely-empty page can 404 once on first agent fetch (documented; self-corrects). Acceptable for a LOW-severity agent-UX polish.
- The advertiser now depends on `Markdown_Views\Service` (it already depended on `Url_Mapper`). Reading the cache directly, it does not risk recursion with the AgDR-0069 loopback.

## Artifacts

- Issue: `Ref34t/mokhai-agent-readiness-kit#296`
- Fast-follow of: [AgDR-0068](AgDR-0068-acf-source-adapter-and-empty-twin-guard.md) (empty-twin guard), [AgDR-0069](AgDR-0069-rendered-html-markdown-fallback.md) (whose B1 analysis named this exact hot-path-cost trap)
- Existing seam: `includes/Discovery/Alternate_Advertiser.php:209` (`current_md_url`), `includes/Markdown_Views/Service.php` (cache read + `is_markdown_empty`)
