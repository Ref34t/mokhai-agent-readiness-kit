# AI Assistant Preview pane

> Make the invisible AI-readability upgrade *visible*. The preview renders any
> page the way an AI assistant consumes it — raw HTML vs the Markdown View vs
> the `/llms.txt` line — with an optional sample model summary, so a
> non-technical buyer can see the uplift instead of taking it on faith.
>
> Feature: [#45](https://github.com/Ref34t/agentready/issues/45) · Design: [AgDR-0046](agdr/AgDR-0046-ai-assistant-preview-pane.md)

## Where it lives

A panel on **Tools → Context Score**, below the score breakdown (mount-point
`#agentready-ai-preview-root`). The Site Health "Context Score" test links to
it with a *"See what AI assistants read on your site"* action.

Admin-only. Every route is gated by `manage_options`; there is **no
public-site surface** and zero impact on front-end page load.

## What it shows

For a selected URL (any published post / page / public CPT):

| Pane | Source | Meaning |
|------|--------|---------|
| **Raw HTML** | `the_content` filter output, truncated | What bots parse *without* the plugin |
| **Markdown View** | `Markdown_Views\Service` (the #6 converter) | What bots get *with* the plugin — runs the no-hallucination guard |
| **llms.txt entry** | `LlmsTxt\Entry_Source::entry_for_post()` | The exact line describing this URL in the published `/llms.txt` |
| **Sample AI Summary** (optional) | cheap-tier LLM over the Markdown View | A 2-3 sentence preview of what an assistant would say. Cached in post-meta, regenerable on demand |

When a post is not exposable (draft, password-protected, `noindex`, excluded
CPT) the Markdown / llms.txt panes show an explanatory empty state; the Raw
HTML pane still renders (that's the "before" picture). When Markdown Views is
disabled in the Context Profile the Markdown pane points the admin at the
toggle.

## REST API

Namespace `ai-readiness-kit/v1`, all `manage_options`:

| Method · Route | Purpose |
|----------------|---------|
| `GET /ai-preview/posts?search=&page=&per_page=` | Selectable posts for the URL dropdown. Public post types, excludes attachments. |
| `GET /ai-preview/preview?post=<id>` | The four-pane payload for one URL. |
| `POST /ai-preview/summary?post=<id>` | (Re)generate + cache the Sample AI Summary. Returns the new summary, or a structured degrade state. |

### Sample AI Summary states

The summary box always renders. When generation can't produce text the POST
returns `{ "text": null, "state": <state>, "message": <hint> }`:

| State | Cause | UI |
|-------|-------|----|
| `unconfigured` | No WP AI Client connected | "Connect an AI provider…" hint |
| `empty_input` | No Markdown View to summarise (module off / not exposable) | Hint, no error |
| `needs_retry` | Transient network / rate-limit | "Try again in a moment" |
| `permanent` | Provider rejected the request (key/model) | "Check the API key and model configuration" |
| `budget_exceeded` / `empty_output` | Slow or empty model reply | "Try again" |

Generation is **synchronous** — one admin click, one LLM round-trip, no cron.
This deliberately sidesteps the async "stuck pending" failure mode; the result
is cached in post-meta (`_agentready_ai_preview_summary` +
`…_generated_gmt`) so subsequent previews are instant until regenerated.

## Not in scope (v1)

- Public-facing preview (admin-only buyer demos).
- Before/after toggle (same URL pre-install vs post-install).
- Full-page loopback capture for Raw HTML — the content-filter output is used;
  a literal fetched-bytes capture is a follow-up if a demo needs it.
- WP-CLI surface — the REST routes cover the panel; add a command if a
  scripted demo needs one.
