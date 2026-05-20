=== Agent Ready ===
Contributors: 9hdigital
Tags: ai, agents, llms.txt, markdown, schema
Requires at least: 6.9
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent Readiness for WordPress: one coherent layer that makes a site readable, discoverable, governable, and measurable for AI agents.

== Description ==

Agent Ready is an open-source WordPress plugin that turns your site into a first-class citizen of the AI-agent web. A single **Context Profile** (configured once under Tools → Context) is the source of truth for every agent-facing surface — what's exposed, how it's served, and how it's scored.

v0.1 ships four coherent modules driven by one profile:

* **Markdown Views** — deterministic HTML → Markdown rendering for any public URL, with three URL forms (`.md` path, `?format=md` query, `Accept: text/markdown` content negotiation) and uniform 404 on denial. Per-post cache with content-hash invalidation, Gutenberg sidebar preview, WP-CLI command, REST endpoint for admin tooling.
* **LLMs Index** — `/llms.txt` generator that publishes a discovery surface for AI agents, with conflict detection against `robots.txt`, an editorial entries admin UI for site owners to add curated entries, and an optional LLM-powered pass that drafts entry descriptions from post content.
* **Context Score** — 0–100 readiness audit across six sub-scores (exposure, schema, discoverability, freshness, narrative-friendliness, agent-policy posture), surfaced in an admin page, Site Health, and `wp agentready context-score recompute`. Includes an optional LLM-generated narrative (with a rule-based fallback) explaining the score and the highest-leverage fixes.
* **Schema Coordination** — detects whether your SEO plugin already emits JSON-LD; if not, optionally emits a native WebSite + Organization + per-content schema set so the schema sub-score is achievable without a third-party SEO plugin. Defers gracefully when an SEO plugin is already covering the surface.

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
* The post is flagged noindex by an SEO plugin (Yoast / Rank Math / AIOSEO detection)
* The Markdown Views module is toggled off in the Context Profile

All denial paths produce the same 404 shape — admin debugging via the REST endpoint or the `wp agentready md preview` command surfaces the specific reason.

= Inspection surfaces =

* **Gutenberg sidebar panel** — opens automatically in the document settings sidebar when editing a post. Shows the current MD rendering, the visibility verdict, and the cache state (cached vs miss, walker version, generated_at).
* **WP-CLI** — `wp agentready md preview <post-id-or-url>`. Supports `--format=wrapped` for YAML-front-matter output suitable for piping into LLM tooling, `--show-meta` for cache diagnostics on stderr, and `--bypass-exposure` (requires manage_options) for inspecting hidden posts without serving them.
* **REST endpoint** — `GET /wp-json/agentready/v1/markdown-views/preview?post=<id>`. Authentication via WP cookie / nonce; permission gated on `edit_post` for the target post. Used by the Gutenberg sidebar; available to third-party admin tooling.

== LLMs Index (/llms.txt) ==

Agent Ready publishes a `/llms.txt` discovery surface for AI agents — the de-facto convention for declaring which URLs on a site are worth fetching as agent context. The generator is driven by the Context Profile: only CPTs and statuses you've exposed appear in the index. Conflict detection surfaces when `robots.txt` already covers the same paths; an admin notice points to the conflict so coverage isn't silently inconsistent.

= Editorial entries =

Most sites have URLs that aren't WordPress posts but are valuable agent context — pricing pages, brand guidelines, support knowledge bases hosted elsewhere. The editorial entries admin UI lets site owners add curated entries with custom titles and descriptions; they appear in `/llms.txt` alongside the auto-generated post entries.

= LLM-powered descriptions =

Optionally, an LLM pass drafts the per-entry descriptions from the post content (uses the WP AI Client provider configured at the site level). The deterministic floor — title-only, no description — runs without an AI provider.

= WP-CLI =

* `wp agentready llms-txt status` — current generation state, conflict report, entry count
* `wp agentready llms-txt regen` — force regeneration
* `wp agentready llms-txt preview` — output the current `/llms.txt` content to stdout

== Context Score ==

Context Score is the 0–100 readiness audit answering "how prepared is this site for AI agent traffic?". It combines six sub-scores:

1. **Exposure** — how many CPTs and statuses are configured for agent access
2. **Schema** — whether structured data (JSON-LD) is being emitted, by your SEO plugin or natively
3. **Discoverability** — `/llms.txt` published, robots.txt conflicts resolved, AI agent layer surfaces in place
4. **Freshness** — when the cache was last regenerated; staleness flagged
5. **Narrative-friendliness** — content shape signals (heading structure, average post length, presence of summaries)
6. **Agent-policy posture** — `/.well-known/llms-policy.json` declaration, agent-activity counters

The score is surfaced in three places:

* **Tools → Context → Context Score** — the full breakdown with per-sub-score detail
* **Site Health** — the headline score and the highest-leverage area to improve
* **WP-CLI** — `wp agentready context-score recompute` for scripted audits

An LLM-generated narrative (uses the WP AI Client provider) explains the score in plain English and names the highest-leverage fixes. A rule-based narrative ships as a fallback for sites without an AI provider configured.

== Schema Coordination ==

When you have an SEO plugin (Yoast, Rank Math, AIOSEO, The SEO Framework) active and emitting JSON-LD, Agent Ready defers schema emission to them entirely — no competing markup, no duplicate type declarations. When no SEO plugin is emitting schema, Agent Ready can optionally emit a native WebSite + Organization + per-content schema set so the schema sub-score in Context Score is achievable without a third-party SEO plugin. The toggle lives in the Context Profile; default is off (gap-fill behaviour kicks in only when explicitly enabled).

== Privacy and Storage ==

Agent Ready stores rendered Markdown in a custom table named `{$wpdb->prefix}agentready_md_cache`, with one row per published post that has been requested at least once as Markdown — holding the Markdown body, an integrity hash of the source content, and the timestamp at which it was generated. The cache is invalidated automatically when a post is saved, trashed, or deleted.

Context Score audit results are cached in a custom table named `{$wpdb->prefix}agentready_score_audit` (one row per audit run, retained for trend analysis).

No content leaves your server. The plugin makes no external HTTP calls and ships no third-party analytics. AI providers configured via the WP AI Client (an optional dependency) are only consulted by modules that explicitly opt in; the deterministic surfaces (Markdown Views, /llms.txt, rule-based score narrative, gap-fill schema) all run fully locally without an AI provider.

Both cache tables are dropped on plugin uninstall (not on deactivation — deactivate is reversible, uninstall is the explicit "I'm done" gesture).

== Configuration ==

Under **Tools → Context**, set:

* **Exposed CPTs** — the list of post types to expose to agents. Default: empty (safe-by-default — a fresh install exposes nothing).
* **Exposed statuses** — the list of post statuses to expose. Default: `publish` only.

Per-module toggles (Markdown Views, LLMs Index, Context Score, native Schema emission) are accessible under the same screen.

To turn Markdown Views off without uninstalling:

`wp eval "$p = get_option('agentready_context_profile'); $p['markdown_views_enabled'] = false; update_option('agentready_context_profile', $p);"`

The module respects the toggle without latency — flipping back to true is instant; the cache table is preserved across toggle cycles.

== Frequently Asked Questions ==

= Does Agent Ready require an AI API key? =

No. Every deterministic surface (Markdown Views, /llms.txt floor, rule-based Context Score narrative, gap-fill JSON-LD emission) runs fully locally with no external calls. Modules that benefit from an LLM (the Markdown cleanup pass, /llms.txt entry-description drafting, the LLM-narrated Context Score) require the optional WP AI Client to be configured, but each is independently toggleable and not load-bearing for the core agent-readiness contract.

= How does Agent Ready interact with my SEO plugin? =

It defers to your SEO plugin's noindex meta — a post marked noindex by Yoast / Rank Math / AIOSEO returns 404 in Markdown form. When your SEO plugin is emitting JSON-LD, Agent Ready emits nothing competing. When no SEO plugin is emitting JSON-LD, you can optionally enable Agent Ready's native gap-fill emission from the Context Profile.

= How is this different from existing /llms.txt plugins? =

`/llms.txt` is one surface among several. Agent Ready ships the integrated reading layer (Markdown views), the discovery layer (/llms.txt with editorial entries and LLM-powered descriptions), the audit layer (Context Score across six sub-scores), and the schema coordination layer as a single coherent unit driven by one Context Profile. Most existing plugins target one of these surfaces in isolation; Agent Ready treats them as a coordinated stack.

= What's on the roadmap after v0.1? =

v0.1.1 fast-follow: AI Assistant Preview pane (render a page as ChatGPT / Claude consume it), ai.txt + `/.well-known/` discovery surface detection, MCP / WordPress Abilities API integration. v0.2+: agent-activity analytics (per-bot counters), `/.well-known/llms-policy.json` policy declaration surface.

== Screenshots ==

1. Context Profile — the single source of truth for which CPTs and statuses are exposed to agents
2. Markdown Views — any post rendered as clean Markdown for AI consumption
3. LLMs Index — `/llms.txt` admin UI with editorial entries and LLM-powered descriptions
4. Context Score — 0–100 readiness audit with six sub-scores and actionable fixes

== Changelog ==

= 0.1.0 — 2026-05-20 =

First public release. Four coherent modules driven by one Context Profile.

**Markdown Views**

* Deterministic HTML → Markdown rendering with three URL forms (path `.md`, query `?format=md`, `Accept: text/markdown`)
* Custom cache table with content-hash invalidation and walker-version lazy revalidation
* Gutenberg sidebar panel for in-editor preview
* `wp agentready md preview` WP-CLI command with `--format=wrapped`, `--show-meta`, `--bypass-exposure`
* REST endpoint for admin tooling at `/wp-json/agentready/v1/markdown-views/preview`
* LLM cleanup pass with safety guard (rate-limited, opt-in via Context Profile)
* Admin UI for cleanup approval workflow

**LLMs Index (/llms.txt)**

* `/llms.txt` generator with Context Profile-driven inclusion rules
* Conflict detection against `robots.txt` with admin notice
* Editorial entries admin UI for curated non-WordPress URLs
* LLM-powered per-entry descriptions (Phase A engine + Phase B admin UI)
* WP-CLI: `wp agentready llms-txt {status,regen,preview}`

**Context Score**

* Six sub-scores (exposure, schema, discoverability, freshness, narrative, agent-policy) → 0–100 composite
* Admin page at Tools → Context Score with full sub-score breakdown
* Site Health integration with headline score and highest-leverage fix surfacing
* REST endpoint for programmatic access
* LLM-narrated explanation with rule-based fallback
* WP-CLI: `wp agentready context-score recompute`

**Schema Coordination**

* SEO plugin detection (Yoast, Rank Math, AIOSEO, The SEO Framework)
* Native gap-fill JSON-LD emitter (WebSite + Organization + per-content) gated behind a Context Profile toggle, credited in Context Score's schema sub-score

**Infrastructure**

* Layered CI: PHPCS (WordPress + WordPressVIPMinimum) + Plugin Check + PHPUnit + PHPStan level 5
* `requires_wp` / `requires_php` runtime gate with admin-notice degradation
* Translation policy documented (AgDR-0009): managed via wp.org under slug `agentready`
* Competitive landscape captured (AgDR-0006)

== Upgrade Notice ==

= 0.1.0 =

First public release. Install on WordPress 6.9+ with PHP 7.4+.
