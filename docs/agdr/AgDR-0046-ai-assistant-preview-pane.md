# AI Assistant Preview pane — surface, summary generation, and raw-HTML source

> In the context of #45 (make the invisible AI-readability upgrade *visible* to non-technical buyers), facing a buyer who needs concrete evidence behind the Context Score number, I decided to add a second React mount-point to the existing Context Score Tools page driven by a new aggregating REST controller — reusing the Markdown Views preview endpoint and llms.txt `Entry_Source`, and generating the optional Sample AI Summary **synchronously** on demand — to achieve a side-by-side "what an assistant sees" view with zero new public-site surface, accepting that the summary blocks its own admin request on one LLM round-trip rather than running async.

## Context

#45 is the last open P1 feature for v0.1.1. It builds on surfaces that already ship:

| Pane | Existing surface reused | Notes |
|---|---|---|
| Markdown View | `GET ai-readiness-kit/v1/markdown-views/preview?post=` (`Markdown_Views\Rest_Controller`) | Already runs the #6 no-hallucination guard inside `Service::get_markdown_for_post()` — that AC is satisfied for free |
| llms.txt entry | `LlmsTxt\Entry_Source` + the `agentready_llms_txt_entry_description` filter | Same line the live `/llms.txt` emits, including the #8 LLM-description meta |
| Raw HTML | `apply_filters( 'the_content', … )` | What bots parse from the page body without the plugin |
| Sample AI Summary | `Ai\Client_Wrapper::generate()` (the #11 cheap-tier surface) | New; cached in post-meta, regenerable |

The hypothesis the feature validates: **non-technical buyers convert when they can see the invisible upgrade**. Context Score gives a number; this gives the evidence behind it.

## Options Considered

### Surface placement

| Option | Pros | Cons |
|--------|------|------|
| **2nd mount-point on the Context Score page** (chosen) | AC says "panel added to the Context Score Tools page (#10)"; webpack auto-discovers a new `src/admin/ai-preview/` bundle; the existing `context-score` bundle stays untouched | `Context_Score_Page` now enqueues two bundles + paints two mount divs |
| New Tools submenu page | Clean isolation | Contradicts the AC; adds a menu entry; splits the score + evidence across two screens |
| Extend the existing `context-score` bundle in place | One bundle | Couples buyer-demo UI to the audit UI; churns a stable bundle; larger blast radius on the #70 design-system alignment |

### Sample AI Summary generation

| Option | Pros | Cons |
|--------|------|------|
| **Synchronous on demand** (chosen) | Single admin-triggered post, not a fan-out — no cron, **sidesteps the wp-env manual-cron caveat** entirely; immediate result; simplest UI (no polling) | The admin REST request blocks on one LLM round-trip (bounded by a wall-clock budget); a timeout degrades to the "needs retry" hint |
| Async via an orchestrator (mirror `Description_Orchestrator`) | Non-blocking; matches the bulk-description shape | Cron + polling UI is overkill for a one-post button, and re-introduces the wp-env "stuck pending" failure mode |

Bounded like `Narrative_Generator`: `temperature` omitted (reasoning models 400 on it — AgDR-0028), `max_tokens = 200` (covers a 2–3 sentence reply with reasoning-model headroom), a wall-clock budget after which we degrade. Cached in post-meta `_agentready_ai_preview_summary` (+ `_agentready_ai_preview_summary_generated_gmt`); the `POST` route regenerates. **Unconfigured** (`! Client_Wrapper::has_ai_client()`) → the box still renders with a "Connect an AI provider to preview a model summary" hint, never an error. Input is the **Markdown View** (capped), so the summary inherits the no-hallucination guard's source-bounded content.

### Raw HTML source

| Option | Pros | Cons |
|--------|------|------|
| **`the_content` filter output** (chosen) | Zero network; admin-safe; deterministic; represents the main content block bots parse | Not a byte-for-byte full-page capture (omits theme chrome/head) |
| Full-page loopback `wp_remote_get( permalink )` | Closest to "what the bot literally fetched" | HTTP loopback inside an admin request; auth-cookie + page-cache + Cloudflare-403 (the very signal that filed this ticket) complications; slower |

Full-page loopback is deferred — if a buyer demo needs the literal fetched bytes, file a follow-up. v1 truncates + scroll-collapses the content HTML in the UI.

## Decision

Chosen: **2nd mount-point on the Context Score page + a new `WPContext\Ai_Preview` module** with:

- `Preview_Builder` — pure-ish aggregator: given a `WP_Post`, returns `{ raw_html, markdown + visibility, llms_entry, summary }`. Unit-testable without REST.
- `Summary_Generator` — synchronous `Client_Wrapper` call, post-meta cache, unconfigured/permanent-error degrade. Mirrors `Narrative_Generator`'s budget + no-`temperature` discipline.
- `Rest_Controller` — three routes under `ai-readiness-kit/v1/ai-preview`, all `manage_options`:
  - `GET /ai-preview/posts` — selectable posts for the dropdown (id, title, type, url)
  - `GET /ai-preview/preview?post=` — the three panes + cached summary in one call
  - `POST /ai-preview/summary?post=` — (re)generate + cache the Sample AI Summary
- `Context_Score\Site_Health` — add a "See what AI assistants read on your site" action linking to the panel anchor.

Namespace `ai-readiness-kit/v1` (public agent-facing surface, per the slug-internal split AgDR-0036/0039); PHP namespace stays `WPContext\`; post-meta/option keys stay `agentready_*`.

## Consequences

- AC "no-hallucination guard from #6 applies" is satisfied by construction — both the Markdown pane and the summary input come from `Service::get_markdown_for_post()`.
- Zero public-site impact: every route is `manage_options`-gated and the bundle enqueues only on the Context Score screen. No `template_redirect` / front-end hooks.
- The summary's synchronous shape means no new cron action — the four-call-site stale-cron pattern (post-#107) does not grow a fifth scheduler.
- Ships in two PRs, both closing #45: **PR A** (this module's PHP + Site Health + tests, REST/CLI-verifiable headless), **PR B** (the `ai-preview` bundle + page wiring + live wp-env walkthrough).
- New translatable strings land here; POT regen folds into the existing #128 chore.

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/45
- Builds on: #6 (MV preview), #7 (llms.txt), #9/#10 (Context Score), #11 (`Narrative_Generator` LLM pattern), AgDR-0028 (LLM budget discipline)
- PRs: _(PR A / PR B links added on creation)_
