---
id: AgDR-0030
timestamp: 2026-05-19T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-9-context-score-engine
trigger: ticket #9 (Context Score engine); v0.1 keystone for agency-lead demo per AgDR-0006 § "Context Score audit"
status: executed
referenced_in:
  - includes/Context_Score/Engine.php
  - includes/Context_Score/Signal_Collector.php
  - includes/Context_Score/Service.php
  - includes/Cli/Context_Score_Command.php
---

# AgDR-0030 — Context Score engine: pure rule-based scoring, six sub-scores, single option cache

> In the context of needing the v0.1 **Context Score audit** (FR-6, ticket #9) — the one-screen demo that lets an agency lead say *"your site is 64/100 agent-ready, here's what to fix"* before any architecture or LLM call — facing the requirement that the engine be deterministic, unit-testable without WordPress, cheap to recompute on ≤ 1000-post sites, and pre-shaped for the downstream admin UI (#10) and LLM narrative (#11), I decided to ship a **pure `Engine` class** (zero WP dependencies, takes a `Signals` array, returns a structured `Breakdown` array), a thin `Signal_Collector` (WP-side bridge that gathers the inputs from Context Profile + Markdown Views cache + LLMs Index + AI Client config), a `Service` orchestrator that owns the cache option + cron + debounced recompute on profile-saved, and a `wp context audit` WP-CLI command that emits the full breakdown as JSON — six sub-scores with weights `discoverability=20, content_readability=15, schema_coverage=10, exposure_safety=15, integration_health=15, md_conversion_quality=25` summing to 100 — to achieve the AC ("overall + 3–6 sub-scores", "deterministic rule-based breakdown", "JSON output", "engine pure for standalone unit tests", "daily cron + on-demand trigger"), accepting that the v0.1 weights are best-effort starting points that will drift in v0.1.x based on real-world calibration.

## Context

- Ticket #9 AC lists six sub-score domains by name: **discoverability, content readability, schema coverage, exposure safety, integration health, per-page MD conversion quality**. Three to six are allowed; we ship all six because each maps to a distinct existing v0.1 module (#7 LLMs Index → discoverability + integration, #5/#6 Markdown Views → readability + MD quality, #4 Context Profile → exposure safety + schema posture).
- The downstream consumers (#10 admin React panel, #11 LLM narrative) need a **structured breakdown** they can render or summarise without recomputing. That fixes the storage shape contract: every sub-score must carry its raw value (0–100), its weight, the raw signals that fed it, and a list of human-readable reason strings.
- The Context Profile keystone (AgDR-0002) already publishes `agentready_context_profile_saved`. Listeners on that action are the obvious recompute trigger. AgDR-0023 establishes the 5-second debounce window for /llms.txt regen — we reuse the same constant so a bulk Context-Profile-touching workflow (e.g. updating exposed_cpts then exposed_statuses in two saves) doesn't double-fire.
- AgDR-0017 already persists a per-post `quality_score` in the Markdown Views cache table. That is the load-bearing signal for the "per-page MD conversion quality" sub-score — we read the column distribution, no re-walking required.
- AgDR-0024 already exposes `Conflict_Detector::detect()` returning the list of /llms.txt conflicts. That feeds "integration health".
- AgDR-0003 publishes `Client_Wrapper::has_ai_client()`. Combined with the `llm_cleanup_enabled` / `llm_descriptions_enabled` toggles in the Context Profile (AgDR-0002), we can determine whether the LLM stack is *consistent* (toggles on + client configured = healthy; toggles on + client unconfigured = degraded; toggles off = neutral, no penalty).
- v0.1 PRD non-goal: research-grade scoring. Goal: "good enough triage to point an agency lead at the highest-leverage fix". Same bar AgDR-0017 set for the per-post quality score — mechanical, explainable, expected to drift.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Pure `Engine` + thin `Signal_Collector` + `Service` orchestrator + single autoloaded option cache, debounced on `agentready_context_profile_saved` + daily cron backstop + on-demand via WP-CLI** | Mirrors AgDR-0022 (LLMs Index Service) and AgDR-0017 (deterministic scoring) — proven shape. Pure engine is trivially unit-testable. Single option cache costs one read per consumer. Debounce coalesces bulk profile edits. Daily backstop bounds staleness. | One more option in `wp_options`. Engine return shape becomes a stability contract for #10 + #11. |
| B — Compute on every read (no cache) | No cache to invalidate, no cron, no schema concerns. | AC explicitly requires daily cron recompute. < 10s budget on a 1000-post site is tight if every admin pageload pays for it. Would force the #10 admin panel to spin a recompute on render. |
| C — Custom table with one row per sub-score | Granular per-sub-score updates; scales to dozens of sub-scores. | Massive overkill for 6 sub-scores. Triggers Gate 3a (migration ticket + AgDR for schema). v0.1 explicitly avoids new tables when an option suffices (AgDR-0002 § "Option C considered and rejected"). |
| D — Engine reads WP globals directly (no Signal_Collector layer) | One fewer file. | Defeats the AC ("engine is pure so it can be unit-tested standalone"). Every test would need WP_UnitTestCase. The Signal_Collector layer is what gates the WP coupling — without it the unit-test promise is hollow. |
| E — Trigger recompute on every `save_post` (mirror LlmsTxt) | Score stays maximally fresh. | The score is *site-level*, not *per-post*. A single bulk import would queue thousands of redundant debounced events. The daily backstop + profile-saved trigger + on-demand CLI / admin-button covers the legitimate recompute moments without storming the cron queue. |

## Decision

Chosen: **Option A** — pure `Engine`, thin `Signal_Collector`, `Service` orchestrator with single option cache, debounce on `agentready_context_profile_saved`, daily cron backstop, on-demand recompute via WP-CLI.

### Module layout

```
includes/Context_Score/
├── Engine.php           # pure: Signals (array) → Breakdown (array). No WP.
├── Signal_Collector.php # WP-side: gathers Signals from Profile + MD cache + LLMs Index + AI Client.
└── Service.php          # orchestration: option storage, cron, debounce on profile-saved, recompute_now().

includes/Cli/Context_Score_Command.php  # `wp context audit [--porcelain]` + `recompute`
```

### Storage shape — single option, autoloaded **off**

Option key: `agentready_context_score_cache`.

Autoload: **no** (`update_option(..., $payload, 'no')`). Score is consumed by WP-CLI + the #10 admin panel only — not the agent-facing hot path. Mirrors AgDR-0022 for the /llms.txt cache.

Payload schema (v1):

```php
[
    'schema_version'        => 1,
    'computed_at'           => '2026-05-19T00:00:00+00:00',
    'recompute_duration_ms' => 1234,
    'overall'               => 64,                      // 0..100 int
    'sub_scores'            => [
        'discoverability' => [
            'value'   => 80,                            // 0..100 int
            'weight'  => 20,                            // contribution to overall (sums to 100)
            'signals' => [ 'llms_txt_cache_populated' => true, 'exposed_cpts_count' => 3, ... ],
            'reasons' => [ 'Site has a populated /llms.txt cache.', ... ],
        ],
        'content_readability'    => [ /* same shape */ ],
        'schema_coverage'        => [ /* same shape */ ],
        'exposure_safety'        => [ /* same shape */ ],
        'integration_health'     => [ /* same shape */ ],
        'md_conversion_quality'  => [ /* same shape */ ],
    ],
]
```

Schema-version semantics match AgDR-0002 § "Schema versioning + migration policy": additive fields with safe defaults bump the version but do **not** require a migration ticket; destructive changes (drop a sub-score, change a value range) do.

Reader contract: `Service::get_breakdown(): ?array` returns the cached payload or `null`. The reader treats an unknown `schema_version` as a miss and triggers a fresh recompute on the next read — same defensive pattern AgDR-0022 uses for the /llms.txt cache.

### Sub-scores and weights (v0.1 starting values)

| Sub-score | Weight | Rule sketch | Primary signal sources |
|---|---|---|---|
| `discoverability` | 20 | Sites expose at least one CPT, `/llms.txt` cache populated, non-zero entry count, no rewrite-shadowing conflict | Context Profile (`exposed_cpts`), `LlmsTxt\Service::get_cache_payload()`, `Conflict_Detector::detect()` (rewrite kind) |
| `content_readability` | 15 | Exposed posts have `post_excerpt` set OR a description filled via #8; the more curated the higher | `Entry_Source::get_sections()` description coverage |
| `schema_coverage` | 10 | At least one SEO plugin detected (Yoast / Rank Math / AIOSEO); coordination posture set | `Schema_Coordination_Detector` (admin-side, surfaces detection) |
| `exposure_safety` | 15 | Exposed statuses ⊆ `[publish]` (safe), or status set deliberately broader with `password`/`private` (penalty); URL exposability gates active | `Context_Profile_Settings::get_profile()` `exposed_statuses` |
| `integration_health` | 15 | If LLM toggles on → AI client configured; `/llms.txt` conflict-free (no plugin/filesystem/rewrite conflict) | `Client_Wrapper::has_ai_client()`, Context Profile `llm_*_enabled`, `Conflict_Detector::detect()` |
| `md_conversion_quality` | 25 | Mean MD `quality_score` across cached rows; % above the Context Profile's `markdown_views_cleanup_threshold` | `Markdown_Views\Schema::table_name()` rows (`quality_score` column) |
| **Total** | **100** | | |

Weights live in `Engine::WEIGHTS` as a class constant array. The shape of the breakdown (the keys, the `value/weight/signals/reasons` quadruple) is the **durable contract** consumed by #10 and #11. The weights themselves are tunable in v0.1.x.

### Score formula

```
sub_score.value  ∈ [0, 100]   (integer, computed by per-sub-score rules)
overall = floor( Σ (sub_score.value × sub_score.weight) / 100 )
```

Floor + integer truncation. Documented in the engine so two callers cannot disagree on rounding behaviour.

Empty-input contract: when the Context Profile is fresh (`exposed_cpts === []` per FR-9), the engine still returns a valid breakdown. Sub-scores that depend on exposed content (discoverability, readability, MD quality) are scored against the *expected* zero state — a fresh site with nothing exposed scores **low** on discoverability (correct: agents can't find anything) but **does not crash**.

### Recompute triggers

Three triggers, no others:

1. **Action listener — `agentready_context_profile_saved`** — schedules a `wp_schedule_single_event` 5 seconds in the future (same constant AgDR-0023 picks for /llms.txt). Coalesces bursts (e.g. exposing two CPTs in two saves).
2. **Daily cron backstop** — `wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', ... )`, registered in `Service::schedule_daily_recompute()` from `Main::on_activate()`. Mirrors AgDR-0023.
3. **On-demand from WP-CLI** — `wp context audit recompute` calls `Service::recompute_now()` synchronously. Same hook the #10 admin button will use (read-and-recompute REST endpoint).

Explicitly **not** triggered on `save_post`. The score is site-level and a bulk import would generate redundant queue entries. The daily backstop bounds staleness at 24h; the #10 admin panel can offer a "recompute now" button on top.

### Pure engine pattern

`Engine::compute( array $signals ): array` is the only public API on the engine. No WordPress functions called from inside. All inputs arrive via the `$signals` array:

```php
$signals = [
    // Context Profile
    'profile' => [
        'exposed_cpts'     => [ 'post', 'page' ],
        'exposed_statuses' => [ 'publish' ],
        'llm_cleanup_enabled'      => true,
        'llm_descriptions_enabled' => true,
        'markdown_views_cleanup_threshold' => 70,
    ],
    // LLMs Index
    'llms_txt' => [
        'cache_populated' => true,
        'entry_count'     => 142,
        'body_bytes'      => 11823,
        'conflicts'       => [],     // from Conflict_Detector::detect()
    ],
    // Markdown Views cache aggregates
    'md_cache' => [
        'rows_total'        => 142,
        'rows_with_score'   => 138,
        'mean_quality'      => 78.4,
        'rows_above_threshold' => 121,
        'cleanup_threshold' => 70,
    ],
    // SEO plugin posture
    'schema' => [
        'seo_plugin' => 'yoast',  // or 'rank_math' | 'aioseo' | null
    ],
    // AI client posture
    'ai_client' => [
        'configured' => true,
    ],
    // Description coverage (for content readability)
    'descriptions' => [
        'total_entries'         => 142,
        'entries_with_description' => 88,
    ],
];
```

`Signal_Collector::collect(): array` is the WP-side bridge that constructs this. Tests can construct the `$signals` array directly without booting WP.

### What this AgDR explicitly does NOT decide

- **Admin UI / React panel** — ticket #10. The structured breakdown is shaped for it but no rendering happens here.
- **LLM narrative summary** — ticket #11. Reads `overall` + `sub_scores[*].reasons[]` and condenses into prose via `Client_Wrapper::generate()`.
- **Per-page MD quality breakdown panel** — ticket #6 Phase B (already shipped as the cleanup admin UI). This engine surfaces the *aggregate*; the per-page drill-down lives in #6's UI.
- **Score history / trend graph** — v0.1.x candidate. The engine writes one cache row, not a time series.
- **Per-CPT sub-scores** — v0.1.x candidate. v0.1 is site-level only.

## Consequences

- `includes/Context_Score/Engine.php`, `Signal_Collector.php`, `Service.php` — new files.
- `includes/Cli/Context_Score_Command.php` — new file, mounts `wp context audit` (AgDR-0014's CLI convention).
- `includes/Main.php` — `Context_Score\Service::register_hooks()` added to `register_hooks()`; `Context_Score\Service::schedule_daily_recompute()` added to `on_activate()`; `Context_Score\Service::clear_scheduled_recomputes()` added to `on_deactivate()`; `Context_Score_Command::register()` added alongside `Llms_Txt_Command::register()`.
- `tests/Unit/Context_Score/Engine_Test.php` — fixture-driven assertions on overall + each sub-score across edge cases (empty profile, perfect input, partial input).
- `tests/Integration/Context_Score/Service_Test.php` — round-trip recompute against a seeded WP_UnitTestCase environment, asserts the option payload + cron scheduling.
- The weights in `Engine::WEIGHTS` are documented as v0.1 starting values. A `// TODO(v0.1.x)` next to them is **not** required — calibration drift is expected per AgDR-0017's precedent and tracking it in code comments would rot.
- `wp context audit` returns JSON by default. `--porcelain` emits `key=value` for shell scripting (AgDR-0014's CLI convention).
- Score cache is preserved on plugin deactivation per AgDR-0015 (full purge only on uninstall).

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/9
- AgDR: this file
- PR: (linked here on creation)
