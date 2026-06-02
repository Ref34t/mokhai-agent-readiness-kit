---
id: AgDR-0047
timestamp: 2026-06-02T00:00:00Z
agent: claude-opus-4-8
model: claude-opus-4-8
session: ticket-139-translatable-reason-codes
trigger: ticket #139 (Engine reason strings ship untranslated); surfaced during #128 POT generation
status: executed
referenced_in:
  - includes/Context_Score/Engine.php
  - includes/Context_Score/Service.php
  - src/admin/context-score/index.js
---

# Translatable Engine reason codes via an additive parallel array

> In the context of #139 (Engine reason strings render untranslated in the admin UI because `Engine.php` emits them as plain literals to honour its pure-PHP / no-WordPress-calls contract), facing a need to make those reasons localisable without putting `__()` inside Engine or churning every consumer of the `reasons` array, I decided to have Engine emit an **additive parallel `reason_keys` array** of `{code, args}` tokens alongside the unchanged English `reasons` string array, and let the React admin bundle map each `code` to a `@wordpress/i18n` `__()` template filled via `sprintf(args)` — to achieve localised reasons in the only surface that renders them verbatim, accepting that the breakdown now carries the reason inventory in two parallel representations.

## Context

`Engine.php` is documented as pure PHP with no WordPress calls so its unit tests run against fixtures without booting `WP_UnitTestCase`. Consequently every sub-score reason is a plain literal (~28 strings across 7 scorers), and `wp i18n make-pot` — which only extracts `__()`-wrapped strings — cannot see them. On non-English sites the reasons therefore render in English.

Investigation of the actual render surfaces narrowed the problem:

| Surface | Renders Engine reasons? | Translatable today? |
|---|---|---|
| Site Health (`Site_Health.php`) | No — renders `Sub_Score_Names::label()` + the narrative "why" | Yes (label + `Rule_Based_Narrative` already use `__()`) |
| Context Score admin (React, `src/admin/context-score/index.js`) | **Yes — `sub.reasons[0]` + the full list, verbatim** | **No** |
| LLM narrative / `Narrative_Guard` | Uses `reasons` as English *facts* to validate the model | N/A — facts stay English |

So the only untranslated user-facing surface is the React admin bundle — which **already** imports `{ __, sprintf } from '@wordpress/i18n'`, already translates the sub-score *labels*, and is already wired through `wp_set_script_translations`. Persistence is version-gated: `Service::get_breakdown()` drops the cache on a `CACHE_SCHEMA_VERSION` mismatch and recomputes, so a shape change needs a version bump but no data migration.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Additive parallel `reason_keys` (chosen)** | `reasons` string array unchanged → `Narrative_Guard`, the LLM fact-path, and existing Engine tests keep working; Engine stays pure (emits `{code, args}` + English text, no `__()`); purely additive schema bump (no migration); JS falls back to `reasons[i]` for any unmapped code | Reason inventory now lives in two parallel arrays; a new reason must be added in both Engine (text + code) and the JS template map |
| Replace `reasons` with `Array<{code, args, text}>` | Single source of truth | Type change string→object breaks `Narrative_Guard`, the JS `String(reasons[0])` path, ~6 Engine test assertions; larger blast radius for the same user-visible result |
| Wrap reason strings in `__()` inside Engine | Smallest diff; one array | Breaks Engine's documented pure-PHP contract (the exact constraint #126 defended); forces WordPress into the unit-test fixture path |
| Translate reasons in PHP and pass pre-translated strings to JS | No JS map | Site-locale ≠ user-locale issues; still needs Engine to stay the source, so the strings would have to be re-emitted as keys anyway |

## Decision

Chosen: **additive parallel `reason_keys`**, because it localises the one surface that needs it while preserving Engine's purity contract, the LLM fact-path, and backward compatibility for every existing consumer. The parallel-array drift risk is contained by a single pure-PHP `Engine::add_reason()` helper that appends to both arrays in lockstep, plus a unit test asserting `count(reasons) === count(reason_keys)` for every sub-score and that every emitted `code` is in a canonical set the JS map must mirror.

`BREAKDOWN_SCHEMA_VERSION` 2 → 3 (additive field) and `CACHE_SCHEMA_VERSION` 3 → 4 (force stale caches to recompute into the new shape). The POT regenerates from the new JS `__()` templates.

## Consequences

- The translatable reason templates live in `src/admin/context-score/index.js` (a `REASON_TEMPLATES` map: `code` → `__()` template), so `make-pot` now captures them — closing the #139 gap for the admin UI.
- Adding a future reason is a two-site change: Engine (`add_reason` with text + code + args) and the JS template map. The canonical-codes test fails loudly if Engine emits a code the test's set doesn't know, prompting the JS-side addition.
- Reasons rendered by the LLM / guard path stay English by design (model facts) — this AgDR does not change that.
- A separate, smaller follow-up could later collapse the two arrays if the JS layer ever stops needing the English fallback; not pursued now.

## Artifacts

- Ticket: Ref34t/agentready#139
- PR: (this change)
- Related: AgDR-0030 (breakdown shape), AgDR-0043 (multi_channel_discovery), #128 (POT generation that surfaced the gap)
