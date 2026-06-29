# Spike memo: Re-validate LLM cleanup pass for page-builder sites (revisits AgDR-0049)

> **Disposition: DISCARD** — hypothesis rejected; not pursuing further. Deterministic-only Markdown conversion stands for page-builder content too.

- **Spike ticket**: Ref34t/mokhai-agent-readiness-kit#174
- **Author**: Mohamed Khaled
- **Closed**: 2026-06-10

## Hypothesis (from the spike ticket)

An **opt-in LLM cleanup pass materially lifts `md_conversion_quality` on page-builder sites** (Visual Composer / Elementor / Divi), where deterministic HTML→Markdown is structurally terse (stat-card widgets linearise into fragmented `## €5k` / one-liner pairs) — enough to overturn the marginal-value RETIRE verdict (AgDR-0049 / #153) for the page-builder segment specifically.

## Findings

Ran the resurrected cleanup pass (verbatim `CLEANUP_PROMPT` from `Cleanup_Orchestrator` @ `04fc22e^`, `Client_Wrapper` tier=quality, `Cleanup_Guard` filter) against **9 real Visual Composer pages** captured from the trigger site (rendered `the_content` of the housing-authority staging scheme pages — the exact stat-card layouts that motivated the spike). Baseline confirmed the trigger evidence: walker scores 57–67 (8/9 below `MD_QUALITY_THRESHOLD` 70), fragmented-heading rates 0.33–0.67.

- **Quality lift: none.** Mean change on a served-MD structural rubric ≈ **−2 pts** (best +5, three pages −9..−10; the negative deltas are list-splitting artifacts — link-walls → bullet lists — which are arguably *more* agent-readable but score as more short blocks). Nowhere near the spike's ~10 pt material-lift bar. **The observed failure mode (fragmented stat-cards) was untouched on every page.**
- **Fidelity: perfect at the LLM layer.** Zero fact tokens (€ amounts, percentages, years) added or dropped across all 9 pages. The model's only real changes were cosmetic (fixing a mangled `[**](url)|![]()` line, splitting mashed link-walls into lists).
- **New finding — the guard is the destructive component.** `Cleanup_Guard`'s per-sentence allowlist filter (false-positive bias by design, AgDR-0018) amputated every page: on one page it reduced a faithful full-page cleanup to 3 prose sentences, deleting all headings, stat-cards, and download links; another page tripped the kill-switch outright. Pre-#153, an admin-approved cleanup would have *served* that mutilated document.

## Why we're not pursuing

The fragmentation lives in the **source content, not the conversion**: a VC stat-card widget *is* `## €175k` + a one-liner in the rendered HTML, so a faithful rendering of fragmented source is fragmented. The prompt's fidelity constraints ("do NOT add… do NOT invent") leave a correctly-behaving LLM no degrees of freedom to fix the only thing that's wrong — and the better the model obeys, the smaller the lift. Two structural points make the approach unfixable as designed:

1. **The score can't move.** 80/100 of the walker quality score's weight comes from walk counters over the source HTML (tag strips, deep div nesting, orphan styles); only empty-line runs + shortcode residue (20 pts) are derivable from markdown. A post-hoc MD rewrite cannot lift `md_conversion_quality` as scored without re-architecting the scoring.
2. **The safety layer destroys page-builder documents.** The per-sentence allowlist guard is structurally hostile to heading-dense, link-dense, low-prose content — exactly the page-builder shape. Shipping the old design would trade "faithful but terse" for "fluent but amputated".

## What would change the answer

Nothing about LLM cleanup — the right lever for page-builder terseness is **conversion-side and deterministic**: teach the Walker to recognise stat-card / value-widget patterns (a heading with no prose followed by a ≤8-word block) and merge them into definition-list or table shapes (e.g. `**>18Y** — Persons who are over 18 years`). That fixes fragmentation at the source, has zero hallucination surface, and moves the real walk-side quality signals. If the page-builder segment matters commercially, file *that* as a `[Feature]` — not a cleanup-pass revival. Separately, any future LLM-rewrite feature would need a guard redesign (block-level diffing, not per-sentence allowlists) before it could be trusted on page-builder content.

## Artefacts

- Original spike ticket: Ref34t/mokhai-agent-readiness-kit#174 (full numbers table in the results comment)
- Spike branch: `spike/GH-174-revalidate-llm-cleanup-page-builders` — harness at `tools/spike-174/harness.php` + resurrected guard/orchestrator classes (delete the branch after this memo merges; page captures and LLM outputs were local-only, never committed)
- Related decisions: AgDR-0049 (cleanup retirement — stands), AgDR-0017 (walker quality score), AgDR-0018 (guard design — the false-positive bias documented there is what amputates page-builder content)
