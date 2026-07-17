=== Mokhai - Agent Readiness Kit ===
Contributors: mokhaled
Tags: ai, agents, llms.txt, markdown, schema
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a clean Markdown version of every page for AI agents — linked from llms.txt with per-page descriptions — plus schema and a readiness score.

== Description ==

Mokhai is an open-source WordPress plugin that turns your site into a first-class citizen of the AI-agent web — readable today, actionable next. A single **Context Profile** (configured once under Tools → Context) is the source of truth for every agent-facing surface — what's exposed, how it's served, and how it's scored.

**The core move: Mokhai publishes a Markdown version of every page on your site.** When an AI assistant fetches a typical WordPress page it receives 50–100 KB of theme markup — menus, sliders, scripts — with your actual content buried inside. Mokhai gives every public URL a clean Markdown twin (`/about-us.md`) that is 4–8 KB of pure content, and pushes those Markdown versions into your `/llms.txt` index: every entry links straight to the page's `.md` form and carries a per-page description, so an agent reading your index gets a curated map of your content AND the readable version of each page in one hop. That pipeline — content → Markdown twin → described llms.txt entry — is what the rest of the plugin audits, scores, and advertises.

Mokhai ships seven coherent modules, all driven by one Context Profile:

* **Markdown Views** — publishes a clean Markdown version of every public URL, with three URL forms (`.md` path, `?format=md` query, `Accept: text/markdown` content negotiation) and uniform 404 on denial. Per-post cache with content-hash invalidation, Gutenberg sidebar preview, WP-CLI command, REST endpoint for admin tooling.
* **LLMs Index** — `/llms.txt` generator that publishes a discovery surface for AI agents, where every entry links to the page's Markdown version and carries a description — curated by you or drafted by an optional LLM-powered pass from the post content. Conflict detection against `robots.txt`, plus an editorial entries admin UI for adding non-WordPress URLs.
* **Context Score** — 0–100 readiness audit across seven weighted sub-scores (discoverability, description coverage, schema coverage, exposure safety, integration health, Markdown conversion quality, multi-channel discovery), surfaced in an admin page, Site Health, and `wp mokhai context-score recompute`. Includes an optional LLM-generated narrative (with a rule-based fallback) explaining the score and the highest-leverage fixes.
* **Schema Coordination** — detects whether your SEO plugin already emits JSON-LD; if not, optionally emits a native WebSite + Organization + per-content schema set so the schema sub-score is achievable without a third-party SEO plugin. Defers gracefully when an SEO plugin is already covering the surface.
* **AI Assistant Preview** — an admin pane that shows any post exactly as an AI assistant consumes it: raw HTML, the Markdown View, and the live `/llms.txt` line side by side, plus an on-demand "sample AI summary" so you can sanity-check what an agent would say about the page.
* **Agent Abilities + MCP** — exposes core plugin operations (audit run, profile read, exposure toggle, `/llms.txt` regenerate, Markdown preview) through the WordPress Abilities API, and surfaces them to MCP clients via the WordPress MCP adapter. Every ability is `manage_options`-gated.
* **Multi-channel discovery** — a seventh Context Score sub-score crediting additional agent-discovery surfaces (`ai.txt`, `/.well-known/` declarations, OpenAPI) beyond `/llms.txt`, so a site running several discovery channels scores higher than one running only one.

The plugin is fully free, GPL-2.0+, with no paid tier and no hosted backend. Every module is independently toggleable from the Context Profile. No content leaves your server. The plugin makes no external HTTP calls; AI providers configured via the WP AI Client (an optional dependency) are only consulted by modules that explicitly opt in, and every deterministic surface (Markdown Views, /llms.txt, the rule-based score, the gap-fill schema) runs fully locally without an AI provider.

== Markdown Views ==

Markdown Views exposes any public URL as a clean Markdown variant. The intended consumer is an AI agent that's discovered a URL (typically via /llms.txt) and wants the raw content without HTML chrome, header/footer/sidebar markup, or JavaScript-rendered widgets.

= Three URL forms =

All three return the same body. Pick whichever matches your client.

* `https://example.com/about-us.md` — path-form (requires pretty permalinks; aligned with the llms.txt ecosystem convention)
* `https://example.com/about-us/?format=md` — query-form (works regardless of permalink structure)
* `Accept: text/markdown` header on the canonical URL — content-negotiation form

The 200 response always carries `Content-Type: text/markdown; charset=utf-8`, `X-Robots-Tag: noindex` (so search engines don't index the raw view as a duplicate of the HTML), and `Cache-Control: no-store, must-revalidate`.

= Exposure rules =

A URL returns 404 with no body — never a partial content leak — when any of the following is true:

* The post's CPT is not in the Context Profile's "Exposed CPTs" list
* The post's status is not in the "Exposed statuses" list (defaults to `publish` only)
* The post is password-protected
* The `mokhai_post_is_noindexed` filter returns true (Mokhai auto-detects per-post noindex from Yoast, Rank Math, and AIOSEO and drops the post from agent surfaces; `mokhai_post_is_noindexed` is the extension point for additional sources)
* The Markdown Views module is toggled off in the Context Profile

All denial paths produce the same 404 shape — admin debugging via the REST endpoint or the `wp mokhai md preview` command surfaces the specific reason.

= Inspection surfaces =

* **Gutenberg sidebar panel** — opens automatically in the document settings sidebar when editing a post. Shows the current MD rendering, the visibility verdict, and the cache state (cached vs miss, walker version, generated_at).
* **WP-CLI** — `wp mokhai md preview <post-id-or-url>`. Supports `--format=wrapped` for YAML-front-matter output suitable for piping into LLM tooling, `--show-meta` for cache diagnostics on stderr, and `--bypass-exposure` (requires manage_options) for inspecting hidden posts without serving them.
* **REST endpoint** — `GET /wp-json/mokhai/v1/markdown-views/preview?post=<id>`. Authentication via WP cookie / nonce; permission gated on `edit_post` for the target post. Used by the Gutenberg sidebar; available to third-party admin tooling.

== LLMs Index (/llms.txt) ==

Mokhai publishes a `/llms.txt` discovery surface for AI agents — the de-facto convention for declaring which URLs on a site are worth fetching as agent context. The generator is driven by the Context Profile: only CPTs and statuses you've exposed appear in the index. Conflict detection surfaces when `robots.txt` already covers the same paths; an admin notice points to the conflict so coverage isn't silently inconsistent.

= Editorial entries =

Most sites have URLs that aren't WordPress posts but are valuable agent context — pricing pages, brand guidelines, support knowledge bases hosted elsewhere. The editorial entries admin UI lets site owners add curated entries with custom titles and descriptions; they appear in `/llms.txt` alongside the auto-generated post entries.

= LLM-powered descriptions =

Optionally, an LLM pass drafts the per-entry descriptions from the post content (uses the WP AI Client provider configured at the site level). The deterministic floor — title-only, no description — runs without an AI provider.

Posts whose body is below a minimum length are skipped by the LLM pass rather than padded with filler (e.g. a bare "Title is available at URL."). Such entries show a "skipped" status in the Descriptions tab and fall back to the title-only floor in `/llms.txt`. Adjust the threshold with the `mokhai_description_min_content_chars` filter.

= WP-CLI =

* `wp mokhai llms-txt status` — current generation state, conflict report, entry count
* `wp mokhai llms-txt regen` — force regeneration
* `wp mokhai llms-txt preview` — output the current `/llms.txt` content to stdout

== Context Score ==

Context Score is the 0–100 readiness audit answering "how prepared is this site for AI agent traffic?". It combines seven weighted sub-scores:

1. **Discoverability (weight 10)** — `/llms.txt` cache populated, at least one CPT exposed, entries published, no rewrite conflicts overriding the route
2. **Description coverage (weight 15)** — share of exposed entries that have a curated description (post excerpt or LLM-generated cache from the descriptions module)
3. **Schema coverage (weight 10)** — JSON-LD is being emitted, either by a detected SEO plugin (Yoast / Rank Math / AIOSEO / The SEO Framework) or by Mokhai's native gap-fill emitter when the Context Profile toggle is on
4. **Exposure safety (weight 15)** — exposed statuses are limited to `publish` (no risky non-publish exposures) and at least one CPT is configured explicitly rather than implicitly
5. **Integration health (weight 15)** — LLM features ↔ AI Client posture are consistent (no silent-degrade trap) and no `/llms.txt` conflicts are unresolved
6. **Markdown conversion quality (weight 25)** — mean quality score across the Markdown Views cache and the percentage of cached posts above the cleanup threshold
7. **Multi-channel discovery (weight 10)** — how many of the four plugin-served agent-discovery surfaces are present (`/llms.txt`, `ai.txt`, `/.well-known/ai-layer`, `/.well-known/llms-policy.json`); all four = 100, so a plugin-only site can reach full marks. OpenAPI is detected and credited as a bonus channel for sites exposing an API but does not change the score. Sibling-provider plugins (e.g. AI Layer) are detected and credited via the filterable `mokhai_multi_channel_providers` registry.

The score is surfaced in three places:

* **Tools → Context → Context Score** — the full breakdown with per-sub-score detail
* **Site Health** — the headline score and the highest-leverage area to improve
* **WP-CLI** — `wp mokhai context-score recompute` for scripted audits

An LLM-generated narrative (uses the WP AI Client provider) explains the score in plain English and names the highest-leverage fixes. A rule-based narrative ships as a fallback for sites without an AI provider configured.

== Schema Coordination ==

When you have an SEO plugin (Yoast, Rank Math, AIOSEO, The SEO Framework) active and emitting JSON-LD, Mokhai defers schema emission to them entirely — no competing markup, no duplicate type declarations. When no SEO plugin is emitting schema, Mokhai can optionally emit a native WebSite + Organization + per-content schema set so the schema sub-score in Context Score is achievable without a third-party SEO plugin. The toggle lives in the Context Profile; default is off (gap-fill behaviour kicks in only when explicitly enabled).

== AI Assistant Preview ==

The AI Assistant Preview pane (Tools → Context) answers a question every site owner eventually asks: "what does an AI assistant actually see when it reads this page?" Pick any exposed URL and the pane renders three views side by side — the raw HTML, the Markdown View an agent fetches (proxied through the same converter as the live `.md` surface, so the no-hallucination guard applies), and the exact `/llms.txt` line for that entry. A "Sample AI Summary" box generates an on-demand, synchronous summary using the configured WP AI Client provider (no cron, no background queue — it runs and caches in place), so you can sanity-check the agent's-eye view of a page before publishing. The summary degrades gracefully (a structured hint, never a raw error) when no AI provider is configured.

== Agent Abilities (MCP) ==

Mokhai registers a `mokhai` ability category and five core WordPress Abilities (WP 6.9+): audit-run, profile-read, profile-set-exposure, llms-txt-regenerate, and md-view-preview. Each is a thin wrapper over an existing service, gated on `manage_options`, and exposed via the core `wp-abilities/v1` REST surface. When the WordPress MCP adapter is installed, these abilities are also reachable by MCP clients (the abilities are flagged `meta.mcp.public`), making the plugin's operations callable by agent runtimes — a step from agent-*readable* toward agent-*usable*. The MCP flag is inert when no adapter is present, so the abilities work standalone.

== Privacy and Storage ==

Mokhai stores rendered Markdown in a custom table named `{$wpdb->prefix}mokhai_md_cache`, with one row per published post that has been requested at least once as Markdown — holding the Markdown body, an integrity hash of the source content, and the timestamp at which it was generated. The cache is invalidated automatically when a post is saved, trashed, or deleted.

Context Score audit results are cached in the `mokhai_context_score_cache` `wp_options` entry (the most-recent breakdown only — overwritten on each recompute).

No content leaves your server. The plugin makes no external HTTP calls and ships no third-party analytics. AI providers configured via the WP AI Client (an optional dependency) are only consulted by modules that explicitly opt in; the deterministic surfaces (Markdown Views, /llms.txt, rule-based score narrative, gap-fill schema) all run fully locally without an AI provider.

Both cache tables are dropped on plugin uninstall (not on deactivation — deactivate is reversible, uninstall is the explicit "I'm done" gesture).

== Configuration ==

Under **Tools → Context**, set:

* **Exposed CPTs** — the list of post types to expose to agents. Default: empty (safe-by-default — a fresh install exposes nothing).
* **Exposed statuses** — the list of post statuses to expose. Default: `publish` only.

The same screen exposes the LLM cleanup toggle (Markdown Views auto-cleanup pass), the LLM descriptions toggle (auto-drafted `/llms.txt` entry descriptions), and the native Schema emission toggle (default off — opt in to satisfy Context Score's schema sub-score without a third-party SEO plugin). Each toggle gracefully degrades when the WP AI Client is unconfigured.

To turn Markdown Views off without uninstalling:

`wp eval "$p = get_option('mokhai_context_profile'); $p['markdown_views_enabled'] = false; update_option('mokhai_context_profile', $p);"`

The module respects the toggle without latency — flipping back to true is instant; the cache table is preserved across toggle cycles.

== Frequently Asked Questions ==

= What is llms.txt and why should I care? =

AI assistants like ChatGPT, Claude, and Perplexity increasingly visit websites to answer questions for their users. Most WordPress themes serve them the same heavy HTML built for humans — menus, sliders, widgets — and the actual content gets lost in the noise. `llms.txt` is a simple convention: a plain-text file at your site's root (like `robots.txt`, but for content) that tells AI assistants what your site is about and where to find your best pages. Mokhai generates and maintains it for you, alongside clean Markdown versions of your pages that agents can actually read. If AI assistants are answering questions in your topic area, this determines whether your site is the source they cite — or the one they skip.

= Does Mokhai require an AI API key? =

No. Every deterministic surface (Markdown Views, /llms.txt floor, rule-based Context Score narrative, gap-fill JSON-LD emission) runs fully locally with no external calls. Modules that benefit from an LLM (the Markdown cleanup pass, /llms.txt entry-description drafting, the LLM-narrated Context Score) require the optional WP AI Client to be configured, but each is independently toggleable and not load-bearing for the core agent-readiness contract.

= How does Mokhai interact with my SEO plugin? =

For JSON-LD: when an SEO plugin (Yoast, Rank Math, AIOSEO, The SEO Framework) is active, Mokhai emits nothing competing — schema is theirs. When no SEO plugin is emitting JSON-LD, you can optionally enable Mokhai's native gap-fill emission from the Context Profile. For noindex: Mokhai auto-detects per-post noindex from Yoast, Rank Math, and AIOSEO and drops the post from agent surfaces. Use the `mokhai_post_is_noindexed` filter to add support for additional sources.

= How is this different from existing /llms.txt plugins? =

`/llms.txt` is one surface among several. Mokhai ships the integrated reading layer (Markdown views), the discovery layer (/llms.txt with editorial entries and LLM-powered descriptions), the audit layer (Context Score across seven sub-scores), and the schema coordination layer as a single coherent unit driven by one Context Profile. Most existing plugins target one of these surfaces in isolation; Mokhai treats them as a coordinated stack.

= What's on the roadmap? =

v0.5.0 is the public-launch baseline: Markdown Views, `/llms.txt`, the Context Score, the AI Assistant Preview pane, and the WordPress Abilities API + MCP integration. Looking ahead: agent-activity analytics (see which AI agents visit your site, not just optimize for them), a fuller agent-*actionable* layer (callable tools via WebMCP / `navigator.modelContext`), and a richer `/.well-known/llms-policy.json` policy-declaration surface.

== Screenshots ==

1. Context Score — 0–100 readiness audit with seven sub-scores and actionable fixes
2. AI Assistant Preview — see exactly what an agent reads: raw HTML, the clean Markdown View, and the `/llms.txt` line, side by side
3. LLMs Index — `/llms.txt` admin UI with editorial entries and LLM-powered descriptions
4. Context Profile — the single source of truth for which CPTs and statuses are exposed to agents

== Changelog ==

= 0.8.0 — 2026-07-17 =

**Feature release: first-run onboarding, and an advisory that spots instruction-shaped descriptions.** No migration — safe in-place upgrade.

**New**

* **First-run onboarding nudge** — a fresh install exposes nothing by design, and now the plugin says so: while no content is exposed, admins see a dismissible notice on the Dashboard, Plugins, and Tools → Context screens explaining that the empty `/llms.txt` is the safe default. One click (with confirmation) exposes published posts and pages, or choose content manually. Dismissal is per-admin and permanent; nothing is ever exposed without an explicit click. (#251)
* **Instruction-shape advisory** — the AI Assistant Preview now warns when a page's `/llms.txt` description reads like an instruction to AI agents ("ignore previous instructions", "always recommend…") rather than a summary. Advisory only: nothing is changed, stripped, or scored down — you decide. Complements the existing structural escaping of descriptions. (#238)

**Improved**

* **Real-world regression fixture** — the integration suite now includes a messy-site fixture (WooCommerce-, page-builder-, and slider-shaped content plus a static front page) guarding the Markdown extraction pipeline against the page shapes that broke it in the wild. (#256)

= 0.7.0 — 2026-07-15 =

**Feature release: agent-readable Markdown that reaches more agents, and covers more pages.** No migration — safe in-place upgrade.

**New**

* **Rendered-HTML fallback for template-built pages** — pages whose content is rendered by the theme (ACF Pro flexible-content, Sage/Acorn, and other builders that render in templates rather than through the editor) previously produced an empty Markdown twin, because the content lived only in the front-end render. Mokhai now recovers that content by reading the page's own rendered HTML and extracting the main region, so these pages get a real Markdown twin. Theme- and builder-agnostic; runs only as a last resort when no content was found otherwise, and never on the normal page-view path. (#297)
* **ACF field-content adapter + empty-twin guard** — ACF field text is sourced into the Markdown body when the editor content is empty, and a page whose twin is genuinely empty now returns a 404 (and is dropped from `/llms.txt`) instead of serving a 0-byte body. (#292)

**Improved**

* **Markdown twins served as `text/plain`** — the `.md` route now responds with `Content-Type: text/plain` and an inline disposition. Some AI fetchers reject `text/markdown` outright; `text/plain` is accepted across the board while remaining human- and agent-readable. (#293)
* **No more dead alternate links** — the `<link rel="alternate">` / `Link:` header advertising a page's `.md` twin is now suppressed for pages whose twin is empty (and would 404), so agents following the advertised alternate always reach real content. (#296)

= 0.6.0 — 2026-07-13 =

**Feature release: markdown that survives hard caching, and discovery that survives AI content extraction.** No migration — safe in-place upgrade; all new behaviour is driven by two new Context Profile keys with safe defaults.

**New**

* **Static Markdown mirror** — Mokhai can now write each exposed page's Markdown version to a real file under `wp-content/uploads/mokhai/md/`, served statically by any host with no PHP involved. This keeps agent-readable content available on sites behind hard full-page caches or static exports, where the request-time `.md` route may never reach PHP. Files follow the full content lifecycle: regenerated on save, moved on slug change, deleted on trash or exposure revoke, with a daily backstop sync and full cleanup on deactivation/uninstall. (#283)
* **Hard-cache detection** — the mirror activates automatically only when a hard page cache is detected, across both layers: caching plugins (WP Rocket, W3TC, WP Super Cache, LiteSpeed, Cache Enabler, and more) and host/CDN caches (Kinsta, Cloudflare, Varnish) via a self-probe of the site's own response headers. Override with the `static_md_mode` profile key (`auto`/`on`/`off`, default `auto`). (#283)
* **In-content discovery link** — an invisible, accessibility-neutral link to the page's Markdown version is added inside the content of exposable singular views. Empirical testing showed AI fetchers (Claude, ChatGPT) strip `<head>` alternate links and HTTP `Link` headers before the model sees the page — an in-content link is the only discovery signal that survives, and because it's part of the page HTML it also survives inside cached copies. Disable with the `content_link_enabled` profile key (default on). (#283)

**Improved**

* wp.org listing copy now leads with the markdown-publishing capability — what the plugin does, stated first. (#282, #284)

= 0.5.0 — 2026-06-30 =

**Public-launch baseline.** This is the first release where every developer-facing and end-user-facing identifier aligns under the `mokhai` scheme. No new features ship in this release.

* **Identifier rename — internal scheme** — the PHP namespace (`Mokhai\`), WP-CLI root (`wp mokhai`), REST namespace (`mokhai/v1`), WordPress Abilities category (`mokhai`), option/meta/table keys (`mokhai_*`), and all hook names (`mokhai_*`) now match the plugin's public name. The previous `ai-readiness-kit`, `agentready`, and `ai_readiness_kit` identifiers are gone.
* **NO automatic migration** — option keys, post meta, and the Markdown cache table were all stored under `agentready_*` or `ai_readiness_kit_*` names in releases prior to 0.5.0. A pre-0.5.0 install re-initialises its settings on first page-load after upgrade; re-configure the Context Profile under Tools → Context (Exposed CPTs, Exposed statuses, and toggles). The Markdown cache rebuilds on demand from the first request to each `.md` URL.
* **Branding** — refreshed Plugin Directory icon and banner assets align with the Mokhai identity established in 0.3.2.

= 0.4.0 — 2026-06-29 =

**Public-launch release.** The plugin is now live on the WordPress.org Plugin Directory as "Mokhai - Agent Readiness Kit" (slug `mokhai-agent-readiness-kit`). This release is launch housekeeping — no functional change, no migration:

* Refreshed Plugin Directory assets — new banner, icon, and screenshots.
* Polished readme copy for the public listing.
* Repository links consolidated under the `mokhai-agent-readiness-kit` name (Plugin URI, package metadata). REST routes, WP-CLI commands, abilities, option keys, and all stored data are unchanged — existing installs upgrade in place with no action required.

= 0.3.2 — 2026-06-21 =

**Rename to "Mokhai - Agent Readiness Kit"** (text domain `mokhai-agent-readiness-kit`). wp.org plugin review rejected "Agentable" as colliding with existing third-party projects and required a name and slug that are clearly ours, with a distinctive leading term. "Mokhai" satisfies that. REST, WP-CLI, abilities, option keys, and all stored data are unchanged — existing installs upgrade with no migration. (#259)

Also ships the following changes that landed on `main` since 0.3.1:

* `/llms.txt` now warns in the admin when a static `robots.txt` blocks the `/llms.txt` reference, so the conflict isn't silent. (#245, #250)
* `/llms.txt` emits the site header on an otherwise-empty index instead of returning a blank body. (#244, #249)
* WooCommerce transactional pages (cart, checkout, account) are now excluded from the `/llms.txt` index. (#243, #248)
* Markdown Views falls back to the raw `post_title` when a filtered title comes back empty, so titled posts no longer render untitled. (#242, #247)
* Markdown URL mapper now guards the root / front-page URL, fixing mapping on the home route. (#241, #246)

= 0.3.1 — 2026-06-14 =

Rename to **Agentable** (text domain `agentable`). The previous "AgentReady" brand collided with an existing third party (agentready.org), so the plugin is renamed to a distinct, ownable name that also fits the roadmap — sites readable today, actionable next. REST, WP-CLI, abilities, and stored data keys are unchanged, so existing installs upgrade with no migration. Also makes root-served-file detection (`llms.txt`, `ai.txt`, `.well-known/*`) subdirectory-install aware via `get_home_path()` instead of assuming the install root is the web root. (#230)

= 0.3.0 — 2026-06-12 =

Polish release. Refinements surfaced through a full live UX walkthrough of the v0.2.0 admin surfaces — score-narrative correctness, clearer labels, and a more discoverable plugin. Also renamed to "AgentReady – AI Readiness Kit"; REST, WP-CLI, and stored data keys are unchanged. (#224)

**Improved**

* **Multi-channel discovery is reachable at 100 for plugin-only sites** — the four plugin-served discovery channels (`/llms.txt`, `ai.txt`, `/.well-known/ai-layer`, `/.well-known/llms-policy.json`) now score 100 on their own; OpenAPI is credited as a bonus channel for API-exposing sites rather than capping the score at 80. (#212)
* **"Content readability" renamed to "Description coverage"** across the Context Score page, Site Health, and readme, to match what the sub-score actually measures (curated `/llms.txt` description coverage). Internal key unchanged. (#211)
* **Plugin is now findable in wp-admin** — the Tools entries read "AI Readiness — Context" / "AI Readiness — Score", and the Plugins list carries a "Settings" link to the Context Profile. (#207)
* **Quality floor for auto-descriptions** — near-empty posts are skipped (distinct "skipped" status) instead of padded with filler like "Title is available at URL."; threshold filterable via `agentready_description_min_content_chars`. (#214)

**Fixes**

* Schema-coverage narrative no longer reads "No structured data was detected" beside a 100/100 score when native JSON-LD emission is on; the stale "future release" fix advice is replaced with the reachable Context Profile action. (#208)
* Content-readability fix advice now points to the Descriptions tab "Regenerate stale descriptions" GUI path and stops telling users to enable a setting that is already on. (#209)
* The multi-channel discovery sub-score renders a human label instead of the raw `multi_channel_discovery` key. (#210)
* AI Assistant Preview shows an explicit "no content" message for empty posts instead of blank dark panes. (#213)
* Relative-time copy uses proper `_n()` plural forms ("1 minute ago" / "5 minutes ago"); excluded posts in the Descriptions table are labelled "excluded" and the intro copy matches. (#215)

= 0.2.0 — 2026-06-03 =

Feature release. Three new agent-facing modules plus a batch of correctness fixes surfaced through live testing.

**New**

* **AI Assistant Preview pane** — view any post as an AI assistant consumes it (raw HTML / Markdown View / live `/llms.txt` line, side by side) with an on-demand synchronous "sample AI summary". No cron; degrades gracefully without an AI provider. (#45)
* **WordPress Abilities API + MCP** — five `manage_options`-gated abilities (audit-run, profile-read, profile-set-exposure, llms-txt-regenerate, md-view-preview) exposed via core `wp-abilities/v1`, and reachable by MCP clients through the WordPress MCP adapter when installed. (#21, #131)
* **Multi-channel discovery sub-score** — Context Score gains a seventh sub-score crediting agent-discovery surfaces beyond `/llms.txt` (`ai.txt`, `/.well-known/` declarations, OpenAPI), with a filterable sibling-provider registry. Discoverability re-weighted 20 → 10 to fund it; total stays 100. (#22)

**Improved**

* Tools → Context rebuilt as a single Card + TabPanel single-page admin, aligned with the WP AI Client / OpenAI Connector design system, with REST write controllers for the Context Profile and editorial entries. (#142)
* Context Score reason strings are now fully translatable via reason codes, and the sub-score copy across the admin UI and the LLM narrative prompt derives from a single source of truth (`Engine::WEIGHTS`). (#137, #139, #140)

**Fixes**

* JSON-LD is now emitted without `esc_html()` (angle brackets escaped via `JSON_HEX_TAG`), so the structured-data payload validates in every standards-compliant validator. (#118)
* Stale past-timestamp cron events are cleared before re-scheduling across all four schedulers (`/llms.txt` recompute, Context Score recompute, Markdown cleanup, description generation) — fixes "stuck pending forever" on sites where wp-cron sat stale. (#115, #120, #121)
* The Markdown cleanup allowlist now preserves word boundaries at block edges, so headings and paragraphs adjacent to block tags are no longer dropped by the no-hallucination guard. (#135)
* Orphaned shortcodes are stripped from both the deterministic Markdown walker and `/llms.txt` entry descriptions via a shared helper. (#145, #147)
* `/llms.txt` now recomposes when a post's description changes, and carries a generator-version staleness signal so out-of-date descriptions are detectable. (#149, #151)
* `package.json` version now tracks the plugin version (was drifting at 0.1.0). (#113)

= 0.1.1 — 2026-05-24 =

Bug-fix release. Four issues surfaced during post-merge smoke testing of v0.1.0 (PR #102 rebrand bundle) and the external LLM review of the live `/llms.txt` output.

**Fixes**

* `/llms.txt` regen now fires reliably after Context Profile saves on sites where wp-cron sits stale (e.g. wp-env without traffic, any site where cron failed for a window). `Service::schedule_regen()` now clears stale past-timestamp events before scheduling a fresh one. (#103)
* `Schema_Emitter` now emits per-content JSON-LD for custom CPTs. Built-in `post` maps to `Article`; `page` and every other CPT (including custom ones like `lesson`, `product`, `recipe`) map to `WebPage` by default. Adds an `agentready_schema_type_for_cpt` filter for plugin/theme authors to specialize. Honors `'Article'` / `'WebPage'` / `null` (suppress) in v0.1.1; full custom-`@type` support lands in v0.1.2. (#104)
* `/llms.txt` entry links now point at the `.md` form when Markdown Views is enabled — AI agents fetch a 4–8 KB Markdown body instead of the 50–100 KB HTML page. Pretty permalinks use the `<slug>.md` shape; plain permalinks fall through to `?format=md`. Idempotent: URLs already in either form are returned unchanged. When `markdown_views_enabled` is false, the canonical permalink is preserved. (#105)
* HTML entities (`&#8217;`, `&amp;`, `&quot;`, `&mdash;`, etc.) no longer leak into the plain-text `/llms.txt` body. WordPress's `wptexturize` filter HTML-encodes typographic characters; the composer now decodes them at the bottom of the escape pipeline so every text surface (site name, tagline, section label, entry title, description) is clean. (#106)

= 0.1.0 — 2026-05-20 =

First public release. Four coherent modules driven by one Context Profile.

**Markdown Views**

* Deterministic HTML → Markdown rendering with three URL forms (path `.md`, query `?format=md`, `Accept: text/markdown`)
* Custom cache table with content-hash invalidation and walker-version lazy revalidation
* Gutenberg sidebar panel for in-editor preview
* `wp ai-readiness-kit md preview` WP-CLI command with `--format=wrapped`, `--show-meta`, `--bypass-exposure`
* REST endpoint for admin tooling at `/wp-json/ai-readiness-kit/v1/markdown-views/preview`
* LLM cleanup pass with safety guard (rate-limited, opt-in via Context Profile)
* Admin UI for cleanup approval workflow

**LLMs Index (/llms.txt)**

* `/llms.txt` generator with Context Profile-driven inclusion rules
* Conflict detection against `robots.txt` with admin notice
* Editorial entries admin UI for curated non-WordPress URLs
* LLM-powered per-entry descriptions (Phase A engine + Phase B admin UI)
* WP-CLI: `wp ai-readiness-kit llms-txt {status,regen,preview}`

**Context Score**

* Six weighted sub-scores (discoverability 20, content readability 15, schema coverage 10, exposure safety 15, integration health 15, Markdown conversion quality 25) → 0–100 composite
* Admin page at Tools → Context Score with full sub-score breakdown
* Site Health integration with headline score and highest-leverage fix surfacing
* REST endpoint for programmatic access
* LLM-narrated explanation with rule-based fallback
* WP-CLI: `wp ai-readiness-kit context-score recompute`

**Schema Coordination**

* SEO plugin detection (Yoast, Rank Math, AIOSEO, The SEO Framework)
* Native gap-fill JSON-LD emitter (WebSite + Organization + per-content) gated behind a Context Profile toggle, credited in Context Score's schema sub-score

**Infrastructure**

* Layered CI: PHPCS (WordPress + WordPressVIPMinimum) + Plugin Check + PHPUnit + PHPStan level 5
* `requires_wp` / `requires_php` runtime gate with admin-notice degradation
* Translation policy documented: managed via wp.org under slug `agentable`

== Upgrade Notice ==

= 0.8.0 =

Adds a first-run onboarding nudge with one-click expose (nothing is exposed without your explicit confirmation) and an advisory that flags instruction-shaped `/llms.txt` descriptions. No migration — safe in-place upgrade.

= 0.7.0 =

Recovers Markdown twins for theme/ACF-rendered pages, serves `.md` as text/plain so more AI fetchers accept it, and stops advertising empty twins. No migration — safe in-place upgrade.

= 0.6.0 =

Adds the static Markdown mirror (auto-enabled only behind hard page caches), hard-cache detection, and the in-content discovery link. No migration — safe in-place upgrade.

= 0.5.0 =

All internal identifiers renamed to the `mokhai` scheme. NO automatic migration — re-configure the Context Profile (Tools → Context) after upgrading from a pre-0.5.0 install.

= 0.4.0 =

Public launch on WordPress.org. Refreshed listing assets and readme; no functional change and no migration — safe in-place upgrade.

= 0.2.0 =

Adds AI Assistant Preview, WordPress Abilities API + MCP, and a multi-channel discovery sub-score, plus fixes for JSON-LD output, stale cron recovery, and Markdown cleanup. Safe in-place upgrade — the Context Score cache auto-invalidates.

= 0.1.0 =

First public release. Install on WordPress 6.9+ with PHP 7.4+.
