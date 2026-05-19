# AgDR-0027 — Async per-post orchestrator + two-meta-key sticky shape for /llms.txt entry descriptions

> In the context of building #8 (LLM-powered entry descriptions for /llms.txt) and facing the choice between generating descriptions inline during `/llms.txt` regen versus offloading to an async per-post pipeline, plus the choice between one merged description slot or two separate slots for LLM-generated vs admin-edited content, I decided to mirror `Markdown_Views\Cleanup_Orchestrator` with a slimmed-down `LlmsTxt\Description_Orchestrator` (per-post cron jobs, status-machine meta, `save_post` trigger + WP-CLI backfill) and store descriptions in two disjoint meta keys (`_agentready_llms_description_auto` for LLM output and `_agentready_llms_description_manual` for admin overrides), with the read-side filter preferring manual → auto → excerpt fallback, to achieve bounded `/llms.txt` regen latency on sites with hundreds of posts and an admin-edit-stickiness story that falls out of the data shape rather than requiring writer-side flag checks, accepting one extra meta read per post during `Entry_Source::resolve_description` and a delayed first-render (descriptions appear on the regen *after* the cron tick completes — entries without cached meta render with the excerpt-fallback in the meantime).

## Context

`Entry_Source::DESCRIPTION_FILTER` (`agentready_llms_txt_entry_description`) was wired in #7 Phase A as the seam for this work. It fires inside `resolve_description($post)` before the excerpt fallback; a non-empty return short-circuits and becomes the entry's description in the rendered `/llms.txt`.

`/llms.txt` regen is itself async-debounced (AgDR-0023). On a 200-post site `Service::regen_sync()` already takes ~5 s for the deterministic compose. If #8 fanned out an LLM call inline per entry, regen would jump to ~200 × ~2 s = ~400 s of LLM time inside one regen — well past WP cron's safe window and past most provider rate limits in a single tick. AgDR-0023 framed the regen lock TTL at 30 s, so inline generation isn't compatible with the existing contract.

`Markdown_Views\Cleanup_Orchestrator` (#6) already established the async-per-post pattern for an LLM-touched feature: cron event per post → status meta → diagnostic meta → terminal states. Mirroring it for #8 means the same operator mental model (look in post-meta, look in `wp cron event list`) applies.

The acceptance criterion "Admin can inline-edit any generated description from the Context Profile screen; edits are sticky and never overwritten by regeneration" needs a data-shape answer. Two candidates:

1. **Two separate meta keys** — one slot for LLM output, one for admin override. The filter resolves manual → auto → excerpt. Stickiness is structural: there is no write path from the orchestrator to the manual slot.
2. **One slot with a source flag** — single description value plus `_source ∈ {auto, manual}`. Stickiness depends on every writer checking the flag before writing. One bug in one writer breaks stickiness.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Async per-post orchestrator (cron jobs, status meta) + two-meta-key storage** | Bounded regen latency (cron tick generates 1 description at a time). Sticky behaviour is structural — no flag-check discipline needed. Same operator surface as `Cleanup_Orchestrator`. WP-CLI backfill for catch-up on pre-existing posts. | Two-cache write paths (orchestrator on save_post, WP-CLI on backfill). Filter incurs one extra meta read per entry on regen (cheap: WP loads post-meta in a single query when posts are fetched in batch via `WP_Query`). Descriptions don't appear on the first regen after install — entries that haven't been re-saved yet render with the excerpt fallback until the WP-CLI backfill or a subsequent save_post fills the cache. |
| B — Inline generation during `/llms.txt` regen, single meta key | Simplest code: regen → for each entry → LLM call → store. One trigger, one writer. | Unbounded regen latency. A 200-post site burns hundreds of seconds inside `regen_sync()`, well past the 30 s lock TTL and provider rate limits. Single-flag stickiness requires every writer to defend. |
| C — Async orchestrator + single meta key with `_source` flag | Same latency story as A, but one fewer meta key per post. | Stickiness depends on writer discipline. A future contributor adding a "regen this post" REST endpoint must remember to check `_source === manual` before overwriting — exactly the kind of invariant that drifts. Auditing "what's the admin's last edit" requires re-reading the same slot the orchestrator owns. |
| D — Per-post inline generation lazily, on-demand at /llms.txt read time, no async pipeline | No prerender cost; entries get LLM descriptions only when a request actually reads `/llms.txt`. | Public route latency jumps to N × LLM-call seconds on cold cache. Public route SHOULD NOT block on LLM calls — that's the cache's job. Pushes the latency problem onto end-users (agents fetching `/llms.txt`) instead of operators. |

## Decision

Chosen: **Option A — async per-post orchestrator + two-meta-key storage.**

Reasons:

1. The async pattern is already in the codebase (`Cleanup_Orchestrator`). Re-using the mental model — cron event, status meta, terminal states, diagnostic meta — minimises the operator's cognitive load.
2. Two-meta-key storage makes stickiness a property of the data layout, not of every writer's discipline. The orchestrator writes `_auto`; the admin REST/UI writes `_manual` (Phase B). They never collide because they're different keys.
3. The first-regen-with-no-descriptions trade-off is acceptable. Entries render with the excerpt fallback (or a bare `- [title](url)` line if no excerpt is set) — degraded but functional. The orchestrator catches up on save_post (incremental) and via the WP-CLI backfill (bulk).
4. Cost containment: the orchestrator only fires when WP AI Client is available, when `llm_descriptions_enabled` is true, and when the post is in `exposed_cpts × exposed_statuses`. A misconfigured site (no API key, no LLM toggle) does zero LLM work.

### Meta keys (Phase A frozen)

```
_agentready_llms_description_auto                       — string, LLM output, regen-overwritable
_agentready_llms_description_manual                     — string, admin override, sticky
_agentready_llms_description_generated_for_modified_gmt — string (mysql datetime UTC),
                                                           post_modified_gmt at generation time;
                                                           Phase B compares to current to detect stale
_agentready_llms_description_status                     — string state-machine value
_agentready_llms_description_diagnostics                — JSON; last attempt's error code + timestamp
```

### Status state machine

```
(no key)       → no LLM attempt ever made (use excerpt fallback)
'pending'      → cron event scheduled; auto slot may or may not have a stale value
'done'         → cron ran, auto slot populated
'needs-retry'  → provider error with retryable code (network / rate_limit) — Client_Wrapper queued the deferred retry
'failed'       → permanent error (4xx via AgDR-0026 / Permanent_Error) or empty LLM output; manual recovery via Phase B "regenerate"
```

`approved` / `rejected` from `Cleanup_Orchestrator` are intentionally **omitted** here. Cleanup descriptions are long-form prose with a risk of hallucination → require human approval before serving. Entry descriptions are one-sentence factual summaries with a 160-character ceiling — the cost of a mediocre one is "an agent reads a slightly weak description"; the cost of a hallucinated one is bounded by the truncation. The approval-state machine isn't worth the UI complexity here.

### Schedule triggers (Phase A)

| Trigger | Action |
|---------|--------|
| `save_post` on an exposed post | Schedule a cron job for that post if `_manual` empty AND (`_auto` empty OR `_generated_for_modified_gmt` < `post_modified_gmt`) AND WP AI Client available AND `llm_descriptions_enabled` |
| WP-CLI `wp agentready llms-txt descriptions backfill` | Iterate `exposed_cpts × exposed_statuses` posts; schedule a job for any missing `_auto` and no `_manual` |
| WP-CLI `wp agentready llms-txt descriptions regen <post>` | Force-regenerate one post regardless of current cache state (Phase A diagnostic tool; Phase B's "Regenerate this row" button is the user surface) |

Phase B adds: bulk-regenerate button (UI veneer over the WP-CLI command), Context Profile toggle UI (the option key `llm_descriptions_enabled` already exists in the profile defaults).

### Read-side filter resolution order

`Description_Filter` subscribes to `Entry_Source::DESCRIPTION_FILTER` and returns:

```
1. Non-empty $manual → return $manual
2. Non-empty $auto → return $auto
3. '' → Entry_Source falls back to post excerpt → empty if no excerpt set
```

The filter is the ONLY read path. Composer never bypasses the filter to read meta directly.

### Cron action

`agentready_llms_description_run` — args `[post_id]`. Same shape as `Cleanup_Orchestrator::SCHEDULE_ACTION`.

### Cap + back-pressure

Mirroring `Cleanup_Orchestrator::pending_count()`: refuse to schedule a new job when ≥ `markdown_views_cleanup_max_per_run` (default 10) jobs are already pending **for this action**. Re-using the same Context Profile setting keeps the operator's tuning surface a single number. A misconfigured site (10000 posts saved at once) gets at most 10 jobs queued per tick; subsequent save_posts on the same posts find them already pending or already cached and no-op.

(Phase B may introduce a dedicated `llm_descriptions_max_per_run` setting if telemetry shows the descriptions workload wants a different ceiling than cleanup — speculative until we have data.)

### What this AgDR explicitly does NOT decide

- **Prompt text + max_tokens + temperature** — see [AgDR-0028](./AgDR-0028-llm-entry-description-prompt.md).
- **No-hallucination guard** — entry descriptions are bounded to 160 chars; the truncation acts as the guard. A separate sentence-allowlist filter (the `Cleanup_Guard` shape from AgDR-0018) would be overkill at this length. If telemetry shows hallucinated entity names ending up in descriptions, v0.1.x can introduce one.
- **Admin UI shape** — Phase B owns this. Phase A's `_manual` slot is wire-format only; the UI that writes it doesn't exist yet.
- **Stale-detection visibility in UI** — Phase A computes the stale flag from `_generated_for_modified_gmt` vs `post_modified_gmt` at schedule time; surfacing "this description is stale" to the admin is Phase B.

## Consequences

- New files: `includes/LlmsTxt/Description_Orchestrator.php`, `includes/LlmsTxt/Description_Filter.php`, `includes/Cli/Llms_Txt_Descriptions_Command.php`.
- Modified: `includes/Main.php` — register hooks for the new orchestrator + filter; register the new WP-CLI subcommand tree.
- The `agentready_llms_description_run` cron action joins the existing `agentready_md_cleanup_run` / `agentready_llms_txt_regen` / `wpctx_ai_retry` actions in the WP cron registry — operators see four agentready-related actions in `wp cron event list`.
- One extra `get_post_meta` per entry during `Entry_Source::resolve_description` — cheap; meta is already eager-loaded by `WP_Query` for the post list.
- Phase B will subscribe to the same `_manual` slot the filter already reads — no further changes to `Entry_Source` or the filter contract needed.

## Artifacts

- Ticket: `Ref34t/agentready#8`
- Mirrors design: AgDR-0017 / AgDR-0020 (Cleanup_Orchestrator + state machine — slimmed for #8)
- Composes with: AgDR-0019 (error classification), AgDR-0026 (Permanent_Error — terminal failure path), AgDR-0023 (regen debounce — unrelated, just sharing the cron register)
- Files: `includes/LlmsTxt/Description_Orchestrator.php`, `includes/LlmsTxt/Description_Filter.php`, `includes/Cli/Llms_Txt_Descriptions_Command.php`, `includes/Main.php`, plus unit tests under `tests/Unit/LlmsTxt/`
