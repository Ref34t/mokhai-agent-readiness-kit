# Generate the Context Score LLM narrative asynchronously

> In the context of the Context Score recompute (Ref34t/agentready#167), facing an LLM narrative that consistently takes ~11–17s to generate — exceeding the 10s budget and degrading to deterministic templates on every run, while also blocking the synchronous "Recompute now" request — I decided to decouple narrative generation from the score recompute and run it as a background cron job that merges into the cache when ready, to achieve a narrative that actually renders without blocking the user or risking request timeouts, accepting that the narrative now appears a moment after the score (eventual, not immediate) and requires a cache-merge guard + a small UI upgrade-on-ready step.

## Context

`Service::recompute_now()` computed the score, then **synchronously** called `Narrative_Generator::generate()` (an LLM call) before writing the cache. Measured behaviour (wp-env, valid OpenAI key):

- Narrative generation: **~11–17s**, regardless of model (`gpt-4o-mini` measured 11–15s; default 15–17s).
- `GENERATION_BUDGET_MS = 10_000` → **every** recompute hit `budget_exceeded` and fell back to rule-based templates. The headline LLM narrative effectively never rendered.
- `Service::do_recompute` (profile-saved debounce + daily backstop) already runs async via cron, so blocking there is invisible — but the REST `POST /context-score/recompute` ("Recompute now") is **synchronous**, so a raised budget would block the request ~16–20s and risk web-server/proxy 504s on real hosts.

Model-pinning was rejected on two grounds: it didn't get under budget, and hardcoding an OpenAI model name (`gpt-4o-mini`) would break agentready's provider-agnostic contract (all AI flows go through `wp_ai_client` so Anthropic/Gemini/etc. work — see the AI-integration design).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Async narrative (chosen)** | No blocking; no 504 risk; provider-latency-proof; uses the existing recompute-cron scaffolding; narrative actually renders | Narrative is eventual (appears after the score); needs a cache-merge guard + UI upgrade-on-ready |
| Raise `GENERATION_BUDGET_MS` to ~25s | One constant | "Recompute now" blocks ~16–20s synchronously → 504 risk on hosts with short timeouts |
| Pin `gpt-4o-mini` | — | Still >10s (measured 11–15s); breaks provider-agnosticism |
| Shrink prompt/output | Smaller change | Uncertain it clears the network floor; reduces narrative richness |

## Decision

Chosen: **Async narrative.** `recompute_now()` writes the score with the **rule-based** narrative immediately (marked `llm_pending: true`) and schedules a single `agentready_context_score_narrative` cron event. The new `Service::do_generate_narrative()` callback runs `Narrative_Generator::generate()` off the critical path and **merges only the narrative slot** into the cache — guarded by `computed_at` so a narrative job that finishes after a newer recompute is discarded rather than clobbering fresh data. The narrative budget is raised generously (background job; the budget now only guards a pathologically hung provider, not UX latency).

## Consequences

- **`CACHE_SCHEMA_VERSION` 5 → 6** — the narrative slot gains an `llm_pending` flag; old payloads read as a miss and recompute on first access (existing AgDR-0022 defensive pattern).
- **Eventual narrative** — score is instant; the LLM narrative appears after the next cron tick. The Context Score UI shows the rule-based narrative with a subtle "AI narrative generating…" hint and refreshes when `llm_pending` clears (reuses the silent-poll pattern from the Descriptions admin UI).
- **CLI `recompute`** returns the score + rule-based immediately and the narrative fills in on the next cron run (on wp-env: `wp cron event run --due-now`).
- **Cache-merge guard** is load-bearing: re-read the cache in the narrative job and only write if `computed_at` is unchanged, so concurrent/late jobs can't desync the narrative from the breakdown it was generated against.
- Supersedes the synchronous-narrative portion of **AgDR-0032**; the budget/degradation mechanics from **AgDR-0028** remain but the budget value changes.

## Artifacts

- Issue: Ref34t/agentready#167
- Files: `includes/Context_Score/Service.php`, `includes/Context_Score/Narrative_Generator.php`, `src/admin/context-score/index.js`
