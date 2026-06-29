# AgDR-0017 — Markdown quality score computed by walker, default LLM-cleanup threshold 70/100

> In the context of needing the second trigger for the Markdown Views LLM cleanup pass (`Ref34t/mokhai-agent-readiness-kit#6` AC 1: *"… OR when deterministic conversion quality score < 70/100 (threshold admin-tunable from the Context Profile)"*), facing the choice of where the score is computed, what signals it weighs, and where the threshold is stored, I decided to compute the score inside the deterministic walker (extending its return shape from `string` to `{ markdown, quality_score, signals }`), default the threshold to **70** stored in the Context Profile, and persist the score alongside the cached row in a new `quality_score` column, to achieve a single source of truth tied to walker output, accepting one schema migration (additive column) on the cache table.

## Context

- AC names a 0–100 quality score with a default trigger threshold of 70/100, admin-tunable from the Context Profile.
- The walker (AgDR-0010) already inspects every input token while converting HTML→MD — adding signal capture is incremental work on a pass we're already doing. A separate "score pass" over the output MD would re-tokenize content for no gain.
- Score signals must be **mechanical and explainable**. An admin who sees "this post scored 42" should be able to see *why* — the score is the head number on a small set of named sub-signals. Black-box "vibes-based" scoring is a debugging nightmare.
- The score is persisted so the admin UI (Phase B of #6) can show "this post fell below threshold because…" without re-running the walker.
- This is a v0.1 mechanic: the goal is "good enough triage for the LLM budget", not a research-grade quality measure. We'd rather over-trigger cleanup on borderline posts than under-trigger and ship messy output.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Walker computes score during conversion, returns `{markdown, quality_score, signals}`; threshold in Context Profile** | One pass over content. Score grounded in actual conversion artefacts (stripped tag count, residue, etc.) rather than post-hoc heuristics. Threshold tunable. Score persistable for admin UI. | Walker return shape changes — every existing caller updates. Cache table needs an additive `quality_score` column (single dbDelta migration). |
| B — Separate `Quality_Scorer` class runs over walker output | Walker stays a pure HTML→MD function. Easy to swap scoring algorithms. | Re-tokenizes the same content. Loses signals that are only visible mid-conversion (e.g. how many tags were stripped). Score becomes a function of the MD, not the conversion — weakens its meaning. |
| C — LLM-as-scorer | Most accurate. | Defeats the purpose: scoring is supposed to *decide* whether to call the LLM. Recursive cost. Rejected outright. |
| D — Hardcoded threshold, no admin tuning | Simplest. | AC explicitly requires admin-tunable. Rejected. |

## Decision

Chosen: **Option A — walker computes score; threshold default 70 stored in Context Profile**, because:

1. The walker is the only place that sees both the input HTML *and* the conversion decisions made on it. The signals that meaningfully predict "this is messy output" (orphan inline styles, table fragments, deep div nesting, image-only paragraphs) are conversion-time observations, not MD-string observations.
2. Returning a structured shape from `Walker::convert()` is a breaking change to its signature, but the only callers are `Service.php` and the test suite. The migration is local and obvious.
3. Persisting the score means the admin UI (Phase B) can render the "why this triggered cleanup" panel without running the walker again — important because the cleaned-up version replaces the deterministic one on success, so the deterministic signals are gone unless we capture them at write time.
4. Threshold of 70 is a starting point informed by the v0.1 PRD edge-case table examples; it will move based on real-world data in v0.1.x. Storing it in the Context Profile (already an admin-tunable settings store) costs ~zero — just a new field.

### Score formula

```
quality_score = 100
  - 25 * tag_strip_rate          // % of tags stripped vs retained, normalized
  - 20 * orphan_inline_style_rate // count of inline style="…" / class="…" residue per kB of MD
  - 15 * table_fragment_rate     // tables that lost structure (header row missing, mismatched cols)
  - 10 * deep_div_nesting_rate   // <div> depth > 4 in source
  - 10 * image_only_paragraphs   // paragraphs containing only <img> or <figure>
  - 10 * empty_line_run_rate     // runs of 3+ empty lines in MD output
  - 10 * shortcode_residue_rate  // [shortcode] tokens left untransformed in MD
```

All rates are normalized 0..1, weights sum to 100. Floor at 0, ceiling at 100. Signals are exposed in the `signals` array of the walker return value as raw counts plus the normalized rate, so the admin UI can show absolute numbers ("12 untransformed shortcodes detected") not just the aggregated score.

The formula will drift in v0.1.x — that's expected. The shape of the signals array is the durable contract; the weights are tunable constants in `Walker::QUALITY_WEIGHTS`.

### Walker return shape (frozen for v0.1)

```php
namespace WPContext\Markdown_Views;

final class Walker {
    public const WALKER_VERSION = '0.2.0'; // bumped from 0.1.0 — invalidates all v0.1 cache rows

    public static function convert( string $html ): Conversion_Result;
}

final class Conversion_Result {
    public function get_markdown(): string;
    public function get_quality_score(): int;       // 0..100
    public function get_signals(): array;           // ['tag_strip_rate' => 0.34, 'tag_strip_count' => 47, ...]
}
```

### Threshold storage (Context Profile)

A new field is added to the Context Profile settings array (AgDR-0002 storage shape):

```php
'markdown_views' => [
    'enabled' => true,
    'llm_cleanup' => [
        'enabled'   => true,           // master switch for the cleanup feature
        'threshold' => 70,              // 0..100; LLM cleanup triggers below this score
        'max_per_run' => 10,            // safety cap: per cron tick, how many posts to clean
    ],
],
```

### Cache-table schema migration (additive)

```sql
ALTER TABLE {$wpdb->prefix}agentready_md_cache
  ADD COLUMN quality_score TINYINT UNSIGNED NULL AFTER markdown,
  ADD COLUMN signals       LONGTEXT         NULL AFTER quality_score;
```

- `quality_score`: 0..100, null on rows written before this migration (the walker-version bump invalidates them anyway, so the null state is transient).
- `signals`: JSON-encoded signal map. Long text because the signals array is small but unbounded in shape (future-proofing).
- The walker-version bump from `0.1.0` to `0.2.0` invalidates all pre-#6 rows, so backfill is not needed — rows get rewritten with score on next read.

Per AgDR-0011, this additive `dbDelta()` change is folded into the v0.1.x feature work (greenfield v0.1, no production rollback story needed). Schema-version option bumps from whatever #5 set to the next integer.

### What this AgDR explicitly does NOT decide

- **The cleanup trigger logic** — combining detection (AgDR-0016) + quality score + threshold into the "should I clean?" decision lives in `Service.php`. This AgDR provides the score and the threshold; it does not orchestrate the decision.
- **Per-post-type threshold overrides** — global default only in v0.1. Per-CPT tuning is a v0.1.x candidate.
- **Score-based admin alerts** ("you have 47 posts below threshold") — deferred to Phase B's admin UI.

## Consequences

- `Walker::convert()` signature changes from `(string): string` to `(string): Conversion_Result`. Two callers (`Service.php`, test suite) update; PR includes both.
- New file: `includes/Markdown_Views/Conversion_Result.php`.
- `Walker::WALKER_VERSION` bumps from `0.1.0` → `0.2.0`. Per AgDR-0011, this invalidates the entire cache on next read — no migration write needed.
- `Schema::create()` adds the two new columns via `dbDelta()` on activation. Existing v0.1 sites pick this up on plugin update via an admin notice prompt to "run database update" or via `wp agentready cache rebuild-schema`.
- Context Profile settings gain the `markdown_views.llm_cleanup` subtree. Default values shipped via the existing settings-initialiser pattern (AgDR-0002).
- Tests: walker unit tests assert score on a curated fixture set:
  - Clean classic-editor post → score ≥ 85
  - Heavy Elementor export (lots of `<div>` nesting + inline styles) → score ≤ 50
  - WPBakery shortcode-residue post → score ≤ 40
  - Empty post → score 100 (no signals firing)
- The "70" default is documented in `readme.txt` so site admins know it's tunable.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#6`
- Related AgDRs: AgDR-0010 (walker — being extended), AgDR-0011 (cache table — additive migration), AgDR-0002 (Context Profile storage), AgDR-0016 (detection), AgDR-0018 (cleanup guard)
- Files (planned): `includes/Markdown_Views/Walker.php` (modify), `includes/Markdown_Views/Conversion_Result.php` (new), `includes/Markdown_Views/Schema.php` (modify), `includes/Admin/Context_Profile_Settings.php` (modify), tests under `tests/unit/Markdown_Views/`
