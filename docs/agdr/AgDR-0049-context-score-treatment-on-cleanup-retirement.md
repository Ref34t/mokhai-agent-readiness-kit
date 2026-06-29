# Context Score treatment when the LLM cleanup pass is retired

> In the context of retiring the Markdown Views LLM cleanup pass (#153, after the PM keep-or-retire validation), facing the fact that Context Score's Engine reads the cleanup profile flag and the cleanup quality threshold in two sub-scores, I decided to **decouple** those two touchpoints (rather than drop-and-re-weight) so the score becomes self-contained w.r.t. cleanup, to achieve a retirement that does **not** shift any real site's score, accepting one tiny intentional scoring change (a cleanup-only-no-client site loses a now-meaningless penalty) and a signals-key rename.

## Context

#153 retires the cleanup pass (#6): it removes `llm_cleanup_enabled` and the cleanup-threshold config (`markdown_views_cleanup_threshold` / `get_md_cleanup_threshold()`). Context Score is the product's keystone metric, so how it behaves across the retirement is the load-bearing decision that shapes every other sub-task. The Engine reads the cleanup surface in exactly two places:

1. **`score_integration_health` (weight 15)** — `$wants_llm = $cleanup_on || $desc_on`; if an LLM feature is enabled but the AI client is unconfigured, it withholds the 60-pt "consistency" credit (the AgDR-0003 silent-degrade trap). `llm_cleanup_enabled` is also echoed in the sub-score's emitted `signals`.
2. **`score_md_conversion_quality` (weight 25)** — its "% of cached rows above threshold" component (40 of its points) uses the cleanup threshold (default 70) as the bar for "is this deterministic conversion good enough." Despite the name, this bar is about deterministic-MD quality, not cleanup.

`Signal_Collector::collect()` feeds both (passes `get_md_cleanup_threshold()` into `md_cache` signals; echoes `llm_cleanup_enabled` + the threshold in `profile` signals).

Separately (not a scoring concern, noted for the follow-up): `LlmsTxt\Description_Orchestrator` reuses `get_md_cleanup_max_per_run()` as its own pending cap.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Decouple, preserve scoring** (chosen) | No real site's score shifts; MD-quality sub-score becomes self-contained; minimal, mechanical changes | Renames one signal key (`cleanup_threshold` → `md_quality_threshold`); one tiny intentional behaviour change in integration_health |
| B — Drop the cleanup contributions + re-normalise WEIGHTS | "Clean break" | Shifts **every** site's Context Score for no user-visible reason; md_conversion_quality would lose its 40-pt component or need re-weighting; churns the keystone metric gratuitously |
| C — Keep `llm_cleanup_enabled` as a dormant no-op flag | Zero score change, zero Engine edits | Leaves dead config + a meaningless toggle in the profile; doesn't actually retire anything; future readers trip over it |

## Decision

Chosen: **A — decouple, preserve scoring**, because the cleanup signal is *additive* in one sub-score and a *borrowed threshold* in the other — neither is structural — so retirement need not perturb the score at all.

Concretely:
- **integration_health:** reduce `$wants_llm` to `$desc_on` (descriptions already enforces the identical "LLM-on → client-required" rule). Drop `llm_cleanup_enabled` from the emitted signals. Behaviour-preserving except a *cleanup-on, descriptions-off, client-unconfigured* site stops being penalised — correct, since the feature it enabled no longer exists.
- **md_conversion_quality:** add a dedicated `MD_QUALITY_THRESHOLD` constant seeded to the current default (`70`); `Signal_Collector` passes it instead of `get_md_cleanup_threshold()`. Rename the `cleanup_threshold` signal + the `mcq_above_threshold` reason copy to `md_quality_threshold` / "MD-quality threshold." Identical computation → identical points.
- **WEIGHTS unchanged** (`integration_health` 15, `md_conversion_quality` 25).

## Consequences

- Context Score is **stable across the retirement** — no surprise drops; the only delta is the narrow, correct penalty removal above.
- The MD-quality sub-score no longer depends on cleanup config — it owns its quality bar.
- Signals-shape change: `cleanup_threshold` → `md_quality_threshold` in the score breakdown; any consumer/snapshot of that key updates, and the reason-keys catalogue copy changes (translatable string).
- **Unblocks #153's remaining sub-tasks** (in dependency order): (1) decouple the descriptions pending cap off `get_md_cleanup_max_per_run()` → its own constant; (2) apply this Context Score treatment; (3) remove the cleanup REST + ability fields (`Md_View_Ability`); (4) delete the cleanup classes (`Cleanup_Orchestrator`/`Guard`/`Rest_Controller`/`Guard_Result`) + `Page_Builder_Detector` (dead once cleanup goes) + the sidebar cleanup panel + cron + the profile toggle/threshold keys; (5) `/migration` to sweep `_agentready_md_cleanup_*` post-meta.
- Reversible: the score logic is small and git-preserved; if cleanup is ever reinstated, re-adding the flag to `$wants_llm` restores the prior behaviour.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#153 (retirement)
- Validation: `9h-portfolio/projects/_inbox/validation/markdown-views-llm-cleanup-pass-keep-or-retire.md` (verdict RETIRE) + IDEA-005 marginal-value test
- Engine touchpoints: `includes/Context_Score/Engine.php` (`score_integration_health` ~373, `score_md_conversion_quality` ~445); `includes/Context_Score/Signal_Collector.php` (`collect`/`profile_signals` ~50-85)
- Related: AgDR-0003 (silent-degrade trap the integration_health check guards), AgDR-0030 (Context Score engine), AgDR-0017 (walker quality score the MD-quality bar reads)
