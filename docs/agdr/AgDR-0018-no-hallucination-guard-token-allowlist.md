# AgDR-0018 — No-hallucination guard via source-token allowlist + named-entity recheck

> In the context of `Ref34t/agentready#6` AC 5 — *"Post-processing strips any LLM-output text not present in the source HTML — no hallucinated content reaches public output (hard rule, tested)"* — facing the choice between strict whole-output rejection, sentence-level token allowlisting, semantic similarity scoring, or LLM-as-judge verification, I decided to ship a deterministic two-stage filter: (1) a **content-word allowlist** built from the source HTML's stripped text against which each output sentence is checked, and (2) a **named-entity recheck** that rejects any capitalised multi-word sequence in the output not present in the source, to achieve a tested, fast, explainable safety floor that the public route can rely on, accepting that some legitimate LLM rewordings (synonyms, paraphrases) will be stripped as false positives — we prefer a stricter false-positive bias over even a small false-negative rate, because a single hallucination on a public `.md` route is a brand-damage event for the user's site.

## Context

- AC 5 is the **load-bearing safety mechanism** of the entire LLM cleanup feature. If the guard fails, hallucinated content reaches public output and the feature is unshippable on real sites.
- The guard cannot rely on LLM judgement (recursive cost, defeats the purpose). It must be deterministic and testable.
- The guard runs on cleanup output before it reaches the cache or the public route. Any sentence that fails the check is *removed*, not flagged — the output is the deterministic floor for that sentence's region.
- "Hallucination" here means: a content-bearing word or phrase in the LLM output that does not appear in the source HTML. Function words (the, a, and, of) and structural markdown (`#`, `*`, `-`) are exempt.
- The signal we cannot let pass: a proper noun, number, URL, brand name, or claim that did not exist in the source. The signal we can tolerate stripping by accident: a synonym ("automobile" for "car" in the source) — the deterministic version of that sentence still appears, so the public surface is never empty.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| A — Strict whole-output rejection (any check fails → discard whole LLM output, fall back to deterministic) | Simplest contract. Zero risk of partial hallucination. | Loses the *whole* cleanup benefit on any single rejected token. Most outputs will trip on at least one synonym → cleanup feature effectively never ships. |
| **B — Sentence-level content-word allowlist + named-entity recheck** | Granular: strip only the offending sentence, keep the rest. Allowlist is deterministic and explainable. Named-entity check catches the high-risk class (proper nouns, brand names, claimed facts). Testable with curated adversarial fixtures. | Stripped sentences may leave gaps; we rely on the deterministic version filling the equivalent region. False-positive rate non-zero on heavy synonym/paraphrase rewriting. |
| C — Semantic similarity (embedding cosine threshold) | Catches paraphrase-level hallucinations more accurately. | Requires an embedding model call per sentence pair — costly, slow, and depends on external service availability. Defeats "deterministic guard" goal. |
| D — LLM-as-judge ("did this LLM hallucinate?") | Most accurate on novel hallucinations. | Recursive cost, recursive failure mode. Rejected outright. |
| E — Diff against deterministic MD, reject sentences whose noun set diverges | Closer to the AC's literal wording ("not present in the source"). | Requires sentence alignment between deterministic and LLM output — alignment is the hardest sub-problem and shifts the failure mode. |

## Decision

Chosen: **Option B — sentence-level content-word allowlist + named-entity recheck**, because:

1. The granularity matches the failure mode we want to contain: a single bad sentence shouldn't tank the whole cleanup, but a single hallucinated proper noun *must* be caught.
2. Two stages cover two distinct risk classes — common-word substitution (low risk, caught by allowlist) and named-entity invention (high risk, caught by recheck).
3. Deterministic: same input → same output. Testable with a fixed adversarial fixture set. No external dependency at guard time.
4. The output of the guard, on failure, is to *remove* the offending sentence, not to discard the whole cleanup result. The deterministic MD for that sentence's region remains in the public output via the fallback layer — so the public surface is always populated.
5. False-positive bias is acceptable: a legitimate paraphrase that gets stripped doesn't break anything (the deterministic sentence is still there). A false negative (hallucination passing through) does break things (public misinformation on a site we don't control).

### Algorithm

**Stage 1 — Source-token allowlist build (once per post):**

```
1. Strip ALL HTML tags from source post_content (using strip_tags after the_content filter).
2. Lowercase, normalize unicode (NFC), strip punctuation.
3. Tokenize on whitespace.
4. Remove stopwords (small fixed English list; non-English content uses the
   same list — over-rejection is acceptable, we documented the bias).
5. Apply light stemming (strip trailing 's', 'es', 'ing', 'ed' — Porter-light,
   not full Porter).
6. The remaining set is the content-word allowlist for this post.
```

**Stage 2 — Sentence-by-sentence check (over LLM output):**

```
For each sentence in the cleaned output:
  a. Tokenize the same way (lowercase, normalize, strip punct, stopwords, light-stem).
  b. For each content-word token in the sentence:
       If token NOT in the allowlist AND length(token) >= 3:
         Mark sentence as failed.
  c. If sentence failed:
       Remove it from the output.
       Log the offending tokens to a per-post diagnostic array.
```

**Stage 3 — Named-entity recheck (final pass):**

```
1. Extract all capitalised multi-word sequences from the (post-stage-2) output —
   pattern: \b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b
2. Extract the same sequences from the source HTML stripped text (before lowercasing).
3. For each entity in the output set:
     If entity NOT in the source set:
       Remove the entire sentence containing it.
       Log the entity to the diagnostic array.
```

The named-entity recheck is a second-line defence specifically targeting the case where stemming or stopword removal in stage 2 might mask a fabricated entity ("Acme Corporation" tokens — "acme" and "corporation" — might individually appear in source for unrelated reasons but the *sequence* doesn't).

### What gets stored

Each cleanup attempt persists a diagnostic record (post-meta) so the admin UI can show the editor:

```php
[
  'attempted_at'    => '2026-05-17T12:34:56Z',
  'sentences_kept'  => 12,
  'sentences_dropped'=> 3,
  'dropped_reasons' => [
    [ 'sentence' => '...', 'stage' => 'allowlist', 'tokens' => [ 'foobar' ] ],
    [ 'sentence' => '...', 'stage' => 'entity',    'entity' => 'Acme Corp' ],
  ],
]
```

If `sentences_dropped / (sentences_kept + sentences_dropped) > 0.5`, the cleanup is considered a failure (LLM hallucinated heavily); the cleaned version is discarded entirely and the deterministic version is served. The post is flagged `needs-retry` per AC 7. This 50% threshold is a configurable constant (`Cleanup_Guard::FAILURE_RATIO_THRESHOLD`); it can move based on production data.

### API shape

```php
namespace WPContext\Markdown_Views;

final class Cleanup_Guard {
    public const FAILURE_RATIO_THRESHOLD = 0.5;

    public static function build_allowlist( string $source_html ): array;
    public static function check( string $llm_output, array $allowlist, array $source_entities ): Guard_Result;
}

final class Guard_Result {
    public function get_filtered_markdown(): string;
    public function get_stats(): array;          // sentences_kept, sentences_dropped
    public function get_dropped(): array;        // diagnostic array (above)
    public function failed_overall(): bool;      // true if drop ratio > threshold
}
```

### Test fixtures (committed alongside the code)

A `tests/fixtures/cleanup-guard/` directory with categorized fixtures:

- **legit-rewording/** — paraphrased output that should pass: synonym swaps that ARE in the source (e.g. source says "automobile and car"; output uses just "car"). At least 5 fixtures.
- **legit-restructure/** — same content, reorganised paragraphs. At least 3 fixtures.
- **adversarial-fact/** — fabricated facts ("the product launched in 1972" when source says nothing about 1972). At least 5 fixtures. **Must be stripped.**
- **adversarial-entity/** — fabricated proper nouns ("CEO John Smith" when source has no John Smith). At least 5 fixtures. **Must be stripped.**
- **adversarial-url/** — fabricated URLs / domains. At least 3 fixtures. **Must be stripped.**
- **adversarial-number/** — fabricated statistics / numbers. At least 3 fixtures. **Must be stripped.**
- **borderline-synonym/** — synonyms NOT in source (e.g. output uses "vehicle" when source only says "car"). **May be stripped** (acceptable false positive — test asserts the deterministic version survives, not that the synonym survives).

The CI test for #6 fails if any adversarial-* fixture passes through unchanged. The CI test does NOT fail if borderline-synonym fixtures get stripped — that's the documented trade-off.

### What this AgDR explicitly does NOT decide

- **The LLM cleanup prompt itself** — drafted alongside the implementation; reviewed but not load-bearing because the guard catches hallucinations regardless of prompt quality. The prompt's job is "produce clean output"; the guard's job is "ensure that output is safe".
- **Non-English content handling** — the stopword list is English-only in v0.1. Multilingual sites will see higher false-positive rates on non-English posts. Documented in `readme.txt` and tracked as a v0.1.x backlog item.
- **Performance budget** — the guard runs over typical post content in milliseconds. We measure but don't fix a budget for v0.1; if it exceeds 500ms for a 10kB post we revisit.

## Consequences

- New file: `includes/Markdown_Views/Cleanup_Guard.php` — static class, pure functions, no side effects.
- New file: `includes/Markdown_Views/Guard_Result.php` — value object.
- Test fixtures committed to `tests/fixtures/cleanup-guard/` with the categorisation above. PHPUnit data providers iterate each directory.
- CI gate: a dedicated PHPUnit test class `Cleanup_Guard_Adversarial_Test` runs the adversarial-* fixtures; failures block merge.
- Diagnostic post-meta key `_agentready_md_cleanup_diagnostics` is registered and visible in the admin UI (Phase B of #6). Cleaned on plugin uninstall.
- The 50% failure ratio is `Cleanup_Guard::FAILURE_RATIO_THRESHOLD`; if production data shows a different curve, the constant moves in a follow-up.
- The named-entity regex is intentionally narrow (multi-word capitalised sequences). Single-word capitalised tokens ("Apple") are caught by the allowlist stage if absent from source. This is documented.

## Artifacts

- Ticket: `Ref34t/agentready#6`
- Related AgDRs: AgDR-0003 (AI Client wrapper — provides the cleanup output the guard consumes), AgDR-0016 (detection), AgDR-0017 (quality score)
- Files (planned): `includes/Markdown_Views/Cleanup_Guard.php`, `includes/Markdown_Views/Guard_Result.php`, `tests/fixtures/cleanup-guard/**`, `tests/unit/Markdown_Views/Cleanup_Guard_Test.php`, `tests/unit/Markdown_Views/Cleanup_Guard_Adversarial_Test.php`
