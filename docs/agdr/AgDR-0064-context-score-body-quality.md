# Context Score grades Markdown body quality (emptiness + noise), not just route existence

> In the context of the `md_conversion_quality` sub-score (weight 25 — the single largest dimension of the 0–100 Context Score), facing a signal-quality gap where the score read 86/100 on a site serving 0-byte product Markdown (#252) and noise-leaking builder pages (#253), I decided to sample the rendered Markdown **bodies** already stored in the cache and fold an emptiness rate and a non-prose-noise rate into the sub-score as proportional deductions, to achieve a score that only passes when agents actually receive usable content, accepting that the sample is limited to cache-populated URLs and that noise detection is a deterministic heuristic.

## Context

The Context Score's `md_conversion_quality` dimension is meant to certify how good the agent-facing `.md` output is. Today (`Engine::score_md_conversion_quality()`) it reads only **aggregate cache statistics** — `mean_quality` and `% of rows above MD_QUALITY_THRESHOLD (70)` — both derived from the Walker's per-conversion `quality_score`.

Two facts combine into the gap reported in #255:

1. The Walker's `quality_score` measures **conversion cleanliness** (tag-strip rate, shortcode residue, deep-div nesting, …). An empty conversion of empty input returns `quality_score = 100` — "no issues to report" — because emptiness is a *content/exposure* problem, not a *conversion* defect. Conflating the two by changing the Walker's semantics is the wrong fix.
2. The sub-score never inspected a single rendered **body**. So 0-byte Woo products (#252) and builder pages whose entire body was noise (#253) contributed `quality_score = 100` to the mean and sat above the threshold — the metric certified garbage as agent-ready.

This ticket **elevates** the working score; it does not fix the renderers (#252/#253 did that). It makes the score *detect* the failure class those bugs belonged to.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A. Fix the Walker so empty input scores low** | One-line change; the existing mean-based sub-score would then drop | Conflates conversion-cleanliness with content-emptiness; an intentionally-empty page is a clean conversion; pollutes the AgDR-0017 semantics and every other consumer of `quality_score` |
| **B. Read cache bodies, fold emptiness + noise rates into the sub-score as proportional deductions** | No re-render storm (bodies are already in the cache `markdown` column); deterministic; surfaces the exact failing URLs; keeps Walker semantics intact | Sample limited to cache-populated URLs; noise detection is a heuristic |
| **C. Re-render a live sample of N exposed URLs each audit** | Truest read, independent of cache freshness | Render cost per audit; slow on large sites; duplicates work the cache already did |
| **D. Hard-cap the sub-score if any URL is empty/noisy** | Simple, strict | A single stray page fails the whole 25-pt dimension on a large healthy site — over-penalises |

## Decision

Chosen: **Option B**, with a **proportional** hit (not a hard cap, rejecting D).

Named constants (each owned + documented like the existing `Engine::MD_QUALITY_THRESHOLD`): `MIN_BODY_CHARS = 48` (trimmed-body length below which a body is near-empty), `MAX_NOISE_RATIO = 0.30` (non-prose char fraction above which a body is noise-dominated), `SAMPLE_LIMIT = 50` (bounded rows read for the rate estimate), `WORST_URL_LIMIT = 5` (failing URLs named in the narrative).

**`Signal_Collector`** gains body-derived signal, read from the existing Markdown Views cache (no re-render), in two bounded queries — **never an all-rows LONGTEXT scan**:

- **Rate sample (representative, unbiased):** one query pulls up to `SAMPLE_LIMIT` rows ordered by `post_id ASC` (deterministic; **not** quality-ordered, so the sample is representative of the site, resolving B2), fetching `post_id, quality_score, markdown`. PHP computes per row: *empty* = `mb_strlen(trim(markdown)) < MIN_BODY_CHARS`; *noisy* = non-prose ratio `> MAX_NOISE_RATIO`, where the ratio = (chars in `Walker::BUILDER_BLOB_MIN_LEN`+ base64 runs + residual `<script>`/`<style>`/`setREV…` tokens + shortcode-residue tokens) / total chars. The noise detector **imports `Walker::BUILDER_BLOB_MIN_LEN`** rather than hardcoding `60`, so the score and the #253 stripper cannot drift (req 4). Aggregates: `empty_pct = empty_in_sample / sample_size`, `noisy_pct = noisy_in_sample / sample_size`, plus `sampled` (= sample_size) and `rows_total` so the narrative can state coverage.
- **Worst-URL display list (separate, worst-first):** one query selects `post_id` for the lowest `quality_score ASC` rows, `LIMIT WORST_URL_LIMIT`; titles/permalinks are resolved in **one batched `get_posts( post__in )`** — never `get_permalink()`/`get_the_title()` in a loop (req 3, no N+1). Worst-first ordering is correct *here* (we want the worst URLs surfaced) and, because it feeds only the display list and not a rate, carries no estimation bias.

**`Engine::score_md_conversion_quality()`** keeps the existing base (mean + above-threshold), then applies proportional deductions:

```
value = clamp( base - round(40 * empty_pct) - round(30 * noisy_pct) )
```

The deductions (max 40 + 30 = 70) are applied to the already-0–100-clamped base and the result passes through `clamp()`, so `value` cannot underflow below 0 or overflow above 100. The base measures *conversion cleanliness* and the deductions measure *content emptiness/noise* — orthogonal axes, so there is no double-counting (this is the same separation that rejects Option A). A handful of empty URLs on a large site dents the dimension; a mostly-empty site fails it. Both deductions are deterministic and LLM-free (AC5). New reason codes (`mcq_empty_bodies`, `mcq_noisy_bodies`) follow the AgDR-0047 code+args pattern.

**`Rule_Based_Narrative::for_md_conversion_quality()`** reads the worst-URL list from the signals and names the specific failing URLs + the highest-leverage fix (AC4), truncated to the existing 140-char line cap. The narrative states sampling coverage explicitly ("scored N of M exposed URLs") so a passing score is honestly scoped to what was sampled rather than implying whole-site coverage.

**Two version bumps (both required, per the AgDR-0043/0047 precedent):**

- `Engine::BREAKDOWN_SCHEMA_VERSION` 3 → 4 — the sub-score's `signals` shape gains `empty_pct`, `noisy_pct`, `sampled`, and the worst-URL list.
- `Service::CACHE_SCHEMA_VERSION` 6 → 7 — **the load-bearing invalidator.** The Service cache option gates freshness on `CACHE_SCHEMA_VERSION`, not `BREAKDOWN_SCHEMA_VERSION`. Without this bump every existing install keeps serving its stale (pre-#255) cached breakdown for up to the cron interval, and the new deductions never reach users — defeating the ticket. Bumping it invalidates every cached payload so the next read recomputes with body sampling.

## Consequences

- The score now fails when agents would receive empty/noise bodies — closing the gap that let #252/#253 read 86/100. Both version bumps ship together so existing installs recompute immediately rather than serving stale cache.
- **Bounded cost:** body reading is capped at `SAMPLE_LIMIT` (50) rows + a `WORST_URL_LIMIT` (5) `post__in` lookup per audit — no all-rows LONGTEXT scan, well inside the recompute budget (AgDR-0051) regardless of cache size.
- **Sample coverage:** the cache is populated lazily (a `.md` is cached on first visit) and the rate is estimated over a bounded representative sample. A never-visited empty URL isn't in the cache and so isn't sampled — the same pre-existing limitation the aggregate already carries ("visit a few `.md` URLs to populate the cache"). The narrative states "scored N of M exposed URLs" so coverage is visible, not silent.
- **Stale rows help, not hurt:** rows written before the `WALKER_VERSION` 4→5 bump (#253) may still carry noise in their stored `markdown`; the raw cache scan reads them, so the noise detector correctly flags them until they regenerate.
- **Noise heuristic over-strip risk** is bounded — the ratio only needs to exceed 0.30 of the body, and the per-token patterns reuse the #253 definitions. A prose-heavy page with one code snippet stays well under.
- One `BREAKDOWN_SCHEMA_VERSION` bump invalidates the cached breakdown (recomputed on next read) — consistent with prior sub-score additions (AgDR-0043).

## Artifacts

- Issue #255 (relates to #252, #253)
- Branch: `feature/GH-255-context-score-body-quality`
- Code: `includes/Context_Score/Signal_Collector.php`, `includes/Context_Score/Engine.php`, `includes/Context_Score/Rule_Based_Narrative.php`
- Tests: `tests/Unit/Context_Score/Engine_Test.php`, `tests/Unit/Context_Score/Rule_Based_Narrative_Test.php`, `tests/Integration/Context_Score/Signal_Collector_Test.php`
