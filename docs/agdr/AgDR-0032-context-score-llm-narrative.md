# AgDR-0032 — Context Score LLM narrative + rule-based fallback (single-call prompt, per-line guard)

> In the context of `Ref34t/agentready#11` — *"LLM-powered Context Score narrative with rule-based fallback"* — facing the choice between (a) one LLM call per sub-score (6 round-trips) vs. a single structured-JSON call covering all six, (b) a deterministic guard reused from #6 (AgDR-0018) vs. a sub-score-scoped guard tailored to numeric + entity facts, and (c) caching the narrative inside the score-record vs. a sibling option, I decided to ship a **single structured-JSON call** producing all six pairs in one round-trip, gated by a **per-sub-score guard that whitelists numeric + entity tokens drawn from that sub-score's `signals` and `reasons`** plus a small **cross-cutting AgentReady allowlist**, with the narrative **persisted inside the score-record** so the read path is free, to achieve a sub-10s recompute that degrades gracefully line-by-line (LLM line fails the guard → rule-based line for that sub-score; whole call fails → full rule-based with a degraded notice) — accepting that the single-call prompt is brittle to parser failures (no JSON → whole call falls back) and that the guard's false-positive bias will strip some legitimate LLM phrasings whose synonyms happen to not appear in the breakdown.

## Context

- AC of #11: LLM narrative per sub-score (one-line "why" + one-line "fix"), rule-based fallback when WP AI Client is unconfigured, cached on the score-record, regenerated only on recompute, anti-hallucination guard, 10s generation budget.
- The score-record (AgDR-0030 / #9) is already cached in `agentready_context_score_cache` and read by Site Health, the React admin UI, the REST endpoint, and the WP-CLI command. Whatever shape the narrative takes must round-trip through that option without breaking those readers.
- Existing AI integration (AgDR-0003 / #6 / #8) routes every LLM call through `Client_Wrapper::generate()` → `wp_ai_client_prompt()`. New code must do the same; introducing a direct provider SDK is explicitly out of scope (memory: agentready AI integration rule).
- Six sub-scores, each independent. The model occasionally hallucinates: a fabricated plugin name ("Yoast Premium" when only "Yoast" is in the reasons), a fabricated number ("80%" when no 80 appears anywhere), a fabricated capability ("schema validation passes" when no such signal is collected).
- Generation runs synchronously inside `Service::recompute_now()` — called from cron, WP-CLI, and the REST recompute endpoint. The 10s budget protects the cron tick + the REST POST UX. A breakdown-only recompute today takes ~50ms; adding an LLM call is the new dominant cost.

## Options Considered

### A. Number of LLM calls

| Option | Pros | Cons |
|--------|------|------|
| A1 — One call per sub-score (6 calls) | Per-call retries / rate-limit isolation. Per-call narrower prompts → cheaper tokens per call. | 6× wall-clock; ~6× rate-limit risk. Hard to keep under the 10s budget on a cold cache. |
| **A2 — Single JSON-output call covering all 6 (chosen)** | One round-trip ≈ 2–4s on cheap-tier chat models. Fits the budget. One prompt to maintain. | A single parser failure on the JSON shape blows the whole call. Single rate-limit / 4xx kills all 6. Mitigation: line-by-line guard + per-sub-score rule_based fallback. |
| A3 — Streaming response with partial parse | Lower TTFB. | WP AI Client doesn't expose streaming in our wrapper. Worth revisiting when the client gains it. |

### B. Anti-hallucination guard shape

| Option | Pros | Cons |
|--------|------|------|
| B1 — Reuse AgDR-0018's content-word allowlist (#6 cleanup guard) | One guard implementation across modules. Already battle-tested on adversarial fixtures. | Built for a 10kB-HTML source. The Context Score breakdown is a tiny structured dict — stopword stemming over 60 words of "reasons" strips signal. Wrong fit. |
| **B2 — Per-sub-score numeric + entity guard, scoped to that sub-score's `signals` + `reasons`, with a small cross-cutting AgentReady allowlist (chosen)** | Tight match for the narrative's risk surface: bogus numbers + bogus product names. Failure is per-line, fallback is per-line. Easy to test with a small adversarial fixture set per sub-score. | False positives when the LLM uses a numeric value derived (e.g. "80%" when only "80/100" appears) — mitigated by tokenising "80" and "80%" both, and accepting either. Doesn't catch hallucinated *claims* phrased without numbers/entities (e.g. "this site is GDPR-compliant"). Accepted: that class is rare in this prompt because the prompt asks for facts about the breakdown and the breakdown has no GDPR signal — but a future regression could ship it. We rely on the model + prompt for that class, not the guard. |
| B3 — LLM-as-judge ("did your narrative hallucinate?") | Catches paraphrase-level hallucinations. | Recursive cost + recursive failure. Rejected (same reasoning as AgDR-0018). |

### C. Caching shape

| Option | Pros | Cons |
|--------|------|------|
| C1 — Sibling option `agentready_context_score_narrative_cache` | Independent invalidation. | Two reads on every panel render. Two schema bumps to manage. Two cron cleanups. Skewed staleness window. |
| **C2 — Inline on the score-record `agentready_context_score_cache` (chosen)** | Single read for the panel. Atomic write at recompute time — narrative + breakdown can never disagree. Same schema-version migration story as today. | The breakdown row grows by a few KB. Acceptable: cached as `autoload=no`, read only when the panel / Site Health asks for it. |

## Decision

Chosen: **A2 + B2 + C2**.

### Module shape

```
includes/Context_Score/
  Engine.php                  ← unchanged (pure scoring)
  Service.php                 ← attach Narrative_Generator::generate to recompute_now() payload
  Narrative_Generator.php     ← orchestrator (LLM call, per-line guard, fallback)
  Rule_Based_Narrative.php    ← pure, deterministic per-sub-score templates
  Narrative_Guard.php         ← pure, allowlist + check
```

`Narrative_Generator::generate( array $breakdown ): array` is the single entry point. Same call shape regardless of AI availability — the orchestrator owns the branching.

### Narrative payload shape (stored inside the score-record)

```php
'narrative' => [
  'schema_version'        => 1,
  'mode'                  => 'llm' | 'rule_based' | 'mixed',
  'generated_at'          => '2026-05-19T13:55:01+00:00',
  'generation_duration_ms'=> 2840,
  'degraded'              => false,
  'degraded_reason'       => null | 'unconfigured' | 'budget_exceeded'
                               | 'permanent_error' | 'rate_limit'
                               | 'network_error' | 'parse_error',
  'sub_scores' => [
    'discoverability' => [
      'why'    => 'one-line why',
      'fix'    => 'one-line what to fix',
      'source' => 'llm' | 'rule_based',
    ],
    // ... 5 more
  ],
]
```

`degraded === true` iff the whole call failed (any non-success error code from `Client_Wrapper::generate()`, or JSON parse failure, or wall-clock overrun). When the call succeeded but one or more lines failed the guard, `mode === 'mixed'`, individual `source` values differ, and `degraded === false`. The React UI renders the degraded notice only on `degraded`, not on `mixed` — a mixed result is a *successful* AI run, just with one sub-score's line failing the safety check.

### Single-call prompt

System prompt (committed in `Narrative_Generator::SYSTEM_PROMPT`):

```
You are a senior WordPress consultant. Explain an AgentReady Context Score
audit to an agency owner.

Output ONE pair per sub-score: a "why" explaining what the score reflects,
and a "fix" naming the single most useful next action.

Rules:
- Use ONLY facts present in the input breakdown (signals, reasons, values,
  weights). Do not invent plugin names, percentages, or capabilities.
- Each "why" and "fix": at most 140 characters. Plain sentence. No
  markdown. No quotes. No emoji. No first-person. No hedging.
- If a sub-score is 100, the "fix" should suggest maintenance, not a
  remedial action.
- Output ONLY valid JSON, no preamble, no fences, no commentary.

Schema:
{
  "discoverability":       {"why": "…", "fix": "…"},
  "content_readability":   {"why": "…", "fix": "…"},
  "schema_coverage":       {"why": "…", "fix": "…"},
  "exposure_safety":       {"why": "…", "fix": "…"},
  "integration_health":    {"why": "…", "fix": "…"},
  "md_conversion_quality": {"why": "…", "fix": "…"}
}
```

User prompt: a compact JSON dump of `{overall, sub_scores}` from the
breakdown.

`Client_Wrapper::generate()` is called with `max_tokens => 800` (six 140-char
fields ≈ 6 × 70 tokens ≈ 420; doubled for reasoning-model headroom per
AgDR-0028's existing budget pattern). `temperature` is deliberately omitted
so the reasoning-model class doesn't 4xx (same lesson as AgDR-0028).

### Anti-hallucination guard

Implemented in `Narrative_Guard::is_safe( string $line, array $allowlist ): bool`.

Per-sub-score `$allowlist` is built by `Narrative_Guard::build_allowlist( array $sub_score ): array` and contains:

1. **Numeric tokens** — every distinct integer in (`value`, `weight`, every numeric in `signals` stringified, every `\d+` extracted from each `reasons` string). Stored as bare integers AND as "N%" forms so the LLM can phrase "60%" when "60" appears.
2. **Entity tokens** — every multi-word capitalised sequence (regex `\b[A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)+\b`) in `reasons`. Lowercased into a set so the comparison is case-insensitive on lookup but the source casing is preserved in the cross-cutting list.
3. **Cross-cutting AgentReady allowlist** (shared across sub-scores):
   - "AgentReady", "Context Profile", "Context Score", "Site Health"
   - "AI Client", "WP AI Client", "Markdown Views", "LLMs Index"
   - "/llms.txt", "JSON-LD"

The guard checks each "why" / "fix" line:

1. Extract numeric tokens via `\d+(?:%|/100)?`. Each must be in the numeric allowlist (with the "%"/"/100" suffix stripped when looking up bare integers).
2. Extract entity tokens (multi-word capitalised). Each must be in the entity allowlist OR the cross-cutting allowlist.
3. Returns true iff every extracted token is allowed.

A line that fails is silently replaced by the rule-based template for that sub-score, and the `source` is recorded as `rule_based`.

The guard is intentionally narrower than AgDR-0018: AgDR-0018 protects a 10kB HTML source feeding a multi-paragraph cleanup; the narrative is two 140-char strings against a 60-word structured breakdown. The matching shape is different and the optimisation target ("how much LLM phrasing freedom can we preserve while killing hallucinations") is different.

### Generation budget

`Narrative_Generator::generate()` wraps the LLM call in a `microtime(true)` measurement. The budget constant `GENERATION_BUDGET_MS = 10_000` is checked **after** the call returns. If the call exceeded the budget OR returned an error code, the whole narrative falls back to rule-based with the corresponding `degraded_reason`. The Service-level `recompute_duration_ms` already reports total wall-clock so the operator sees both the engine cost and the LLM cost.

We do NOT cancel an in-flight HTTP request at the budget — `Client_Wrapper` and the underlying WP AI Client don't expose timeout-cancellation primitives that ride on top of WP's HTTP API. The post-hoc check is the floor: even on a 20s overrun, the next read serves rule-based (because we never persist a payload whose generation overflowed), and the cron tick eventually completes.

### Rule-based fallback content

`Rule_Based_Narrative::compose( array $breakdown ): array` produces the same shape with deterministic templates keyed off each sub-score's `value` and `signals`. Three buckets per sub-score:

- `value === 100` — "Working well — …" + maintenance suggestion
- `value >= 50`   — "Partial — …" + named gap from the largest-weight missing signal
- `value < 50`    — "Critical — …" + named gap + Context Profile deep-link suggestion

Templates are committed alongside the engine — they're not user-editable v0.1. They run when:

- WP AI Client is unconfigured (`degraded_reason === 'unconfigured'`).
- The LLM call fails or overshoots the budget (`degraded_reason` ∈ `{permanent_error, rate_limit, network_error, parse_error, budget_exceeded}`).
- The LLM line fails the guard for a specific sub-score (per-line replacement, `mode === 'mixed'`).

### What this AgDR explicitly does NOT decide

- **Streaming UX** — the React admin UI shows a single "Recomputing…" spinner; no per-sub-score progressive reveal. Future work.
- **Customisable prompts** — operators cannot tune the system prompt v0.1. Doing so is a Pro-tier knob and lives outside this AgDR.
- **Per-sub-score regeneration** — there is no "regenerate just this sub-score's narrative" endpoint. The single-call shape makes that an awkward optimisation that doesn't pay for its complexity yet.
- **Persisting raw LLM output / diagnostics** — we persist only the post-processed narrative. The raw response is discarded after parsing. Adding a diagnostic blob would mirror `META_KEY_DIAGNOSTICS` from #8 but isn't a v0.1 must-have; the `degraded_reason` field is enough for the UI to explain the fallback.

## Consequences

- New files:
  - `includes/Context_Score/Narrative_Generator.php`
  - `includes/Context_Score/Rule_Based_Narrative.php`
  - `includes/Context_Score/Narrative_Guard.php`
- Modified: `includes/Context_Score/Service.php` — calls the generator from `recompute_now()` and attaches `narrative` to the cache payload. `CACHE_SCHEMA_VERSION` bumps to `2`; old cached payloads return `null` from `get_breakdown()` and trigger a fresh recompute on first read (same defensive pattern AgDR-0022 uses).
- Modified: `src/admin/context-score/index.js` — renders narrative `why`/`fix` per sub-score in "What's missing" and "Full breakdown"; surfaces the degraded notice on the overall card; small "AI-generated" / "Rule-based" badge per row.
- Modified: `includes/Context_Score/Site_Health.php` — `description` paragraph optionally appends the worst-leverage sub-score's `why` line if a narrative is present and the source is `llm`. Falls back to today's behaviour otherwise.
- New tests:
  - `tests/Unit/Context_Score/Narrative_Guard_Test.php` — allowlist build + adversarial token rejection.
  - `tests/Unit/Context_Score/Rule_Based_Narrative_Test.php` — deterministic output per bucket per sub-score.
  - `tests/Unit/Context_Score/Narrative_Generator_Test.php` — LLM-success path, unconfigured fallback, parse-error fallback, guard-failure per-line replacement, budget-overrun fallback.
- The cache option payload grows by ~1–2 kB. `autoload=no` is preserved.

## Artifacts

- Ticket: [`Ref34t/agentready#11`](https://github.com/Ref34t/agentready/issues/11)
- Related AgDRs: AgDR-0003 (AI Client wrapper), AgDR-0018 (sibling guard for #6), AgDR-0022 (cache schema-version defensiveness), AgDR-0026 (`permanent` error class — the narrative respects it), AgDR-0028 (LLM prompt design — single-call JSON shape borrowed), AgDR-0030 (breakdown shape this narrative is built against), AgDR-0031 (admin UI this narrative is rendered into).
