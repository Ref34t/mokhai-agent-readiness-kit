=== AgentReady ===
Contributors: author
Tags: ai, agents, markdown, llms.txt, content-negotiation
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent Readiness for WordPress: one coherent layer that makes a site readable, discoverable, governable, and measurable for AI agents.

== Description ==

AgentReady is an open-source WordPress plugin that turns your site into a first-class citizen of the AI-agent web. A single **Context Profile** (configured once under Tools → Context) is the source of truth for every agent-facing surface: a deterministic Markdown rendering of any public URL, /llms.txt index generation, schema-coordination posture with your SEO plugin, agent-activity analytics, and more.

The plugin is fully free, GPL-2.0+, with no paid tier and no hosted backend. Every module is independently toggleable from the Context Profile.

This is an early-development release. The deterministic Markdown Views module is the first surface delivered; the LLMs Index, Context Score, and SEO-coordination modules ship in subsequent releases.

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
* The post is flagged noindex by an SEO plugin (Yoast / Rank Math / AIOSEO support shipping in a follow-up release)
* The Markdown Views module is toggled off in the Context Profile

All four denial paths produce the same 404 shape — admin debugging via the REST endpoint or the `wp agentready md preview` command surfaces the specific reason.

= Inspection surfaces =

* **Gutenberg sidebar panel** — opens automatically in the document settings sidebar when editing a post. Shows the current MD rendering, the visibility verdict, and the cache state (cached vs miss, walker version, generated_at).
* **WP-CLI** — `wp agentready md preview <post-id-or-url>`. Supports `--format=wrapped` for YAML-front-matter output suitable for piping into LLM tooling, `--show-meta` for cache diagnostics on stderr, and `--bypass-exposure` (requires manage_options) for inspecting hidden posts without serving them.
* **REST endpoint** — `GET /wp-json/agentready/v1/markdown-views/preview?post=<id>`. Authentication via WP cookie / nonce; permission gated on `edit_post` for the target post. Used by the Gutenberg sidebar; available to third-party admin tooling.

== Privacy and Storage ==

AgentReady stores rendered Markdown in a custom table named `{$wpdb->prefix}agentready_md_cache`. The table has one row per published post that has been requested at least once as Markdown, holding the Markdown body, an integrity hash of the source content, and the timestamp at which it was generated. The cache is invalidated automatically when a post is saved, trashed, or deleted.

No content leaves your server. The plugin makes no external HTTP calls and ships no third-party analytics. AI providers configured via the WP AI Client (an optional dependency) are only consulted by modules that explicitly opt in; the deterministic Markdown Views surface is fully local and does not require an AI provider.

The cache table is dropped on plugin uninstall (not on deactivation — deactivate is reversible, uninstall is the explicit "I'm done" gesture).

== Configuration ==

Under **Tools → Context**, set:

* **Exposed CPTs** — the list of post types to expose to agents. Default: empty (safe-by-default — a fresh install exposes nothing).
* **Exposed statuses** — the list of post statuses to expose. Default: `publish` only.

Toggling individual modules (Markdown Views, LLMs Index, Context Score, etc.) is currently scriptable via WP-CLI; a per-module checkbox UI lands in a follow-up release.

To turn Markdown Views off without uninstalling:

`wp eval "$p = get_option('agentready_context_profile'); $p['markdown_views_enabled'] = false; update_option('agentready_context_profile', $p);"`

The module respects the toggle without latency — flipping back to true is instant; the cache table is preserved across toggle cycles.

== Frequently Asked Questions ==

= Does AgentReady require an AI API key? =

No. The deterministic Markdown Views surface ships with v0.1 and runs entirely locally — no API key, no external calls, no per-request cost. Future modules that use AI (e.g. LLM-cleanup pass on top of the deterministic Markdown, LLM-narrated Context Score) require the optional WP AI Client to be configured, but are independently toggleable and not load-bearing for the core agent-readiness contract.

= How does Markdown Views interact with my SEO plugin? =

It defers to your SEO plugin's noindex meta. A post marked noindex by Yoast / Rank Math / AIOSEO returns 404 in Markdown form. AgentReady doesn't emit its own structured-data competing with what your SEO plugin already does — schema coordination is handled in a separate module shipping in a follow-up release.

= How is this different from existing /llms.txt plugins? =

`/llms.txt` is one surface among several. AgentReady ships the integrated reading layer (Markdown views), the discovery layer (/llms.txt index — follow-up release), the policy layer (`/.well-known/llms-policy.json` — v0.2+), and the analytics layer (agent-activity counters — v0.2+) as a single coherent unit driven by one Context Profile. Most existing plugins target one of these surfaces in isolation.

== Changelog ==

= 0.1.0 (in development) =

* Markdown Views module — deterministic HTML → Markdown rendering for public URLs
* Three URL forms (path, query, content negotiation) with uniform 404 on denial
* Custom cache table with content-hash invalidation and walker-version lazy revalidation
* Gutenberg sidebar panel for in-editor preview
* `wp agentready md preview` WP-CLI command
* REST endpoint for admin tooling
* Context Profile single source of truth with safe-by-default exposure
