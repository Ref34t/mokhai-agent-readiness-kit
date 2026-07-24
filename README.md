# Mokhai - Agent Readiness Kit

> Agent Readiness for WordPress: one coherent layer that makes a site readable, discoverable, governable, and measurable for AI agents.

An open-source WordPress plugin that turns your site into a first-class citizen of the AI-agent web. A single **Context Profile** (configured once under **Tools → Context**) is the source of truth for every agent-facing surface — what's exposed, how it's served, and how it's scored. Every module is independently toggleable, and no content leaves your server.

**Status:** v0.8.0 — live on the [WordPress.org plugin directory](https://wordpress.org/plugins/mokhai-agent-readiness-kit).

## Getting started

New here? **[docs/getting-started.md](docs/getting-started.md)** walks you from install to a verified, agent-ready site in about ten minutes.

More docs:

- **[Context Score reference](docs/context-score.md)** — the seven sub-scores, their weights and signals, and how to raise each one.
- **[/llms.txt curation guide](docs/llms-txt.md)** — descriptions, editorial entries, conflict detection, and the instruction-shape advisory.
- **[Abilities & MCP](docs/abilities.md)** — the agent-facing operations and how to reach them from MCP clients.

## What it does

One Context Profile drives seven modules:

- **Markdown Views** — deterministic HTML → Markdown rendering for any exposed URL, in three forms (`.md` path, `?format=md` query, `Accept: text/markdown` content negotiation). Per-post cache, Gutenberg sidebar preview, WP-CLI command, and a REST endpoint. AI agents pull clean ~4–8 KB Markdown instead of the full HTML page.
- **LLMs Index** — a `/llms.txt` discovery surface for AI agents, with `robots.txt` conflict detection, a curated-entries admin UI, and an optional LLM-drafted description pass.
- **Context Score** — a 0–100 readiness audit across seven weighted sub-scores (discoverability, content readability, schema coverage, exposure safety, integration health, Markdown conversion quality, multi-channel discovery), shown in an admin page and Site Health, with an optional AI-written narrative explaining the highest-leverage fixes.
- **Schema Coordination** — detects whether your SEO plugin already emits JSON-LD; if not, optionally emits a native WebSite + Organization + per-content set so the schema sub-score is achievable without a third-party SEO plugin. Defers gracefully when one is already covering the surface.
- **AI Assistant Preview** — an admin pane showing any post exactly as an AI assistant consumes it (raw HTML, Markdown view, and live `/llms.txt` line side by side), plus an on-demand sample AI summary.
- **Agent Abilities + MCP** — exposes core operations (run audit, read profile, toggle exposure, regenerate `/llms.txt`, preview Markdown) through the WordPress Abilities API and to MCP clients via the WordPress MCP adapter. Every ability is `manage_options`-gated.
- **Multi-channel discovery** — credits additional agent-discovery surfaces (`ai.txt`, `/.well-known/` declarations, OpenAPI) beyond `/llms.txt` in the Context Score.

## Privacy

Fully free, GPL-2.0+, with no paid tier and no hosted backend. The plugin makes no external HTTP calls. Every deterministic surface (Markdown Views, `/llms.txt`, the rule-based score, the gap-fill schema) runs entirely locally. The AI-powered extras are opt-in and only consult an AI provider you configure via the optional WP AI Client.

## Requirements

- WordPress 6.9+
- PHP 7.4+

## License

GPL-2.0-or-later. Free, open-source.

## Contributing

Issues and PRs welcome — see the [open issues](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues) for where help is wanted.
