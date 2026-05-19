# AgDR-0033 — Defer JSON-LD to Yoast / Rank Math / AIOSEO via a coverage matrix; emit a minimal baseline only when no SEO plugin is active

> In the context of `Ref34t/agentready#12` — *"Defer JSON-LD coordination to Yoast / Rank Math / AIOSEO (gap-fill only)"* — facing the choice between (a) emitting AgentReady's own JSON-LD unconditionally vs. emitting only types the active SEO plugin does not cover, (b) hard-coding the deference branch as `"any SEO plugin → emit nothing"` vs. encoding a per-plugin coverage matrix, and (c) including per-content-type schema in v0.1's baseline vs. shipping site-identity-only first, I decided to ship a **coverage-matrix-driven gap-fill emitter** that for each detected SEO plugin computes `emit = baseline_types \ covered_types`, with v0.1's matrix declaring **all three supported plugins cover the entire baseline (WebSite, Organization, WebPage, Article, BreadcrumbList)** so the emitter ships zero JSON-LD when any of them is active, and emits **WebSite + Organization (site-identity) + WebPage/Article (content-type for `is_singular()` views)** as the minimal baseline only when no SEO plugin is detected, to achieve a wp.org-review-safe coexistence with the dominant SEO plugins while keeping a forward-extensible shape for future v0.1.x types (FAQPage, HowTo, Product) — accepting that the v0.1 matrix is a conservative over-deference (some plugins emit only partial Article markup; we treat it as full coverage to guarantee no duplicates) and that the baseline emit set is intentionally minimal (no per-author Person, no per-comment Comment) until usage demonstrates the gap is worth filling.

## Context

- **AC of #12** (verbatim):
  1. Detect Yoast, Rank Math, and AIOSEO on activation and at runtime.
  2. Defer JSON-LD coordination to whichever SEO plugin is active for the schema types it already provides.
  3. Only inject schema types the active SEO plugin does **not** provide (gap-fill mode).
  4. Admin Context Score panel documents which schema types are deferred vs filled by WP Context.
  5. If no SEO plugin is active, WP Context emits a minimal baseline schema set (site identity + content type).
  6. Plugin Check Tool reports no duplicate-schema warnings when WP Context is active alongside any of the supported SEO plugins.
- `Schema_Coordination_Detector` (#4 / AgDR-0002) already detects Yoast / Rank Math / AIOSEO at runtime via class-presence + `is_plugin_active` corroboration. Output is `{posture, label, detected_via}` consumed by the Context Profile and Context Score (`schema` signal). No JSON-LD emitter exists today.
- Context Score's `score_schema_coverage` (Engine.php:235) is hardcoded to "no native emission yet — relies on whichever SEO plugin is present". This AgDR turns the "yet" into an emitter, but leaves the score path's reasoning intact (presence of an SEO plugin still earns the 100; presence of AgentReady's baseline emit also earns the 100; "neither" stays at 60).
- The dominant SEO plugins all emit a comparable baseline set on the WP frontend: `WebSite`, `Organization` (or `Person`), `BreadcrumbList`, and content-type-specific (`Article` for posts, `WebPage` for pages, `CollectionPage` / `ItemList` for archives). They diverge on richer types (`FAQPage`, `HowTo`, `Product`) which the matrix CAN encode but v0.1 doesn't need to.
- Plugin Check Tool flags duplicate-schema as a hard finding for wp.org review. The bar for v0.1 is "zero duplicates when any of the three is active". Over-defering is safe; under-defering is a review blocker.

## Options Considered

### A. Deference shape — how do we decide what AgentReady emits?

| Option | Pros | Cons |
|--------|------|------|
| A1 — Hard-coded "SEO plugin active → emit nothing; none active → emit baseline" | Trivial code, ~20 lines, zero matrix maintenance. Zero risk of duplicates today. | Inflexible. Future v0.1.x types (`FAQPage`, `HowTo`) can't be gap-filled — the branch is binary. To add gap-fill of one new type we have to refactor to a matrix anyway. The AC says "gap-fill mode", not "all-or-nothing mode". |
| **A2 — Coverage matrix `plugin_slug => covered_types[]`, compute `emit = baseline ∖ covered` per request (chosen)** | Extensible: adding a new emit-able type means a one-line baseline entry + matrix update. Self-documenting (the matrix IS the deference truth, surfaced to the admin UI verbatim). Future-safe: if Yoast removes `BreadcrumbList` coverage in a major release we update one row. | A few-dozen lines more than A1. Matrix maintenance burden grows with the type list. Today's matrix entries are mostly "covered=all" — feels like over-engineering until you imagine v0.2 adding `FAQPage`. |
| A3 — Inspect each plugin's emitted JSON-LD live (parse `wp_head` buffer) and emit complements | Adapts to plugin configuration (e.g. user disabled Yoast's `Organization`). | Requires output-buffer capture + JSON-LD parsing on every front-end request. Performance + parser-fragility cost is unacceptable for v0.1. Plugin updates can break the parse. Rejected. |

### B. Type set in v0.1's baseline (what does AgentReady emit when no SEO plugin is active?)

| Option | Pros | Cons |
|--------|------|------|
| B1 — Site identity only (`WebSite` + `Organization`) | Smallest surface, lowest risk of conflict if a theme already emits its own per-content schema. | Doesn't satisfy AC #5 (which says "site identity + content type"). Crawlers visiting a single post get a site-level graph but no per-content type → less useful as an "agent-readiness" signal. |
| **B2 — Site identity + content type per `is_singular()` (chosen)** | Satisfies AC #5 verbatim. Per-content type is straightforward: `Article` for posts, `WebPage` for pages, `CollectionPage` for `is_archive()` / `is_home()`. | Theme conflicts possible (some themes ship `Article` markup). Mitigation: emit only when no SEO plugin AND a filter `agentready_schema_emit` returns true (escape hatch for theme-emit-already cases). |
| B3 — Full LD graph (Author Person, Comments, ImageObject, etc.) | Richer agent payload. | Substantial surface; collides with theme-level schema; pushes us into per-author / per-comment ownership we don't have. Out of scope for v0.1; revisit per usage data. |

### C. Filter / extensibility shape

| Option | Pros | Cons |
|--------|------|------|
| **C1 — Three filters: `agentready_schema_coverage_matrix` (matrix override), `agentready_schema_baseline_types` (baseline override), `agentready_schema_emit` (per-request opt-out) (chosen)** | Each filter has a single responsibility. A site owner overriding the matrix doesn't have to also override the baseline. The per-request filter lets a theme suppress emission when it ships its own schema, without affecting other sites. | Three filters to document. Mitigated: the AgDR + the inline phpdoc on each filter site are the docs. |
| C2 — Single mega-filter passing the whole computed plan | One hook to document. | A consumer wanting to override only the matrix has to reconstruct the entire plan including the baseline + compute step. Composition becomes harder. |

### D. Where the runtime detection cache lives

| Option | Pros | Cons |
|--------|------|------|
| **D1 — No cache; call `Schema_Coordination_Detector::detect()` per request (chosen)** | Detection is cheap (class_exists + an options-array read). Always up-to-date. No invalidation surface. Matches today's pattern (Profile + Score also call detect() on-render). | A few microseconds per `wp_head`. Acceptable. |
| D2 — Cache the posture on activation into an option | Saves the microseconds. | Adds an invalidation surface (plugin install / activate / network-activate). Posture goes stale if an admin activates Yoast WITHOUT re-activating AgentReady — Plugin Check Tool would then flag duplicates. Worse than the saved cost. Rejected. |
| D3 — Cache the posture in a short transient (5 min) | Middle ground. | Same invalidation surface as D2, just shifted to a 5-min window. Not worth it given detection is already cheap. Rejected. |

> Note on "Detect on activation" (AC #1): AC #1 reads "on activation and at runtime". The activation-time call is satisfied by `Requirements::check_activation()` flow which already loads the plugin context (so `class_exists()` works) — but we don't *persist* the result. The point of "on activation" is so the admin UI can render a coherent posture immediately after enable, which D1 already achieves: every page-load runs detect() fresh.

## Decision

Chosen: **A2 + B2 + C1 + D1**.

### Module shape

```
includes/Seo/
  Plugin_Coverage.php       ← pure matrix + gap computation (no WP deps)
  Schema_Emitter.php        ← wp_head hook, renders JSON-LD blocks, applies filters
```

`WPContext\Admin\Schema_Coordination_Detector` stays where it is — the Admin namespace already exposes it via Profile + Score, no need to move.

### Coverage matrix (v0.1)

```php
const COVERAGE = [
    'yoast'     => [ 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ],
    'rank_math' => [ 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ],
    'aioseo'    => [ 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ],
];

const BASELINE = [ 'WebSite', 'Organization', 'WebPage', 'Article' ];
// BreadcrumbList is intentionally NOT in BASELINE — we'd need theme cooperation
// to know the trail. Better to under-emit than to fabricate.
```

The matrix appears in the Context Score admin panel as a Plain-Text-readable table: `WebSite (deferred to Yoast)`, `Article (deferred to Yoast)`, etc.

### Filters

| Filter | Signature | Purpose |
|--------|-----------|---------|
| `agentready_schema_coverage_matrix` | `array<string, array<int, string>>` | Override the per-plugin coverage list. |
| `agentready_schema_baseline_types` | `array<int, string>` | Override the baseline emit set. |
| `agentready_schema_emit` | `bool` (default true) | Per-request opt-out — theme can return false to suppress AgentReady's emission entirely. |

### Render shape

`Schema_Emitter::render()` on `wp_head` priority `10`:

1. `if ( ! apply_filters( 'agentready_schema_emit', true ) ) return;`
2. `posture = Schema_Coordination_Detector::detect()`
3. `gap = Plugin_Coverage::compute_gap( baseline, posture['posture'] )`
4. For each type in `gap`, build the corresponding minimal JSON-LD node (`WebSite`, `Organization`, `WebPage` / `Article` per `is_singular()` context).
5. Emit one `<script type="application/ld+json">` block containing the array of nodes (single script tag, easier to detect + escape).

Properties on each node are deliberately minimal:

- `WebSite`: `@type`, `@id`, `url`, `name`, `inLanguage` (from `get_bloginfo('language')`).
- `Organization`: `@type`, `@id`, `name`, `url`. (Logo / sameAs deliberately out of scope for v0.1 — Context Profile doesn't have those fields yet. Future ticket.)
- `Article` (when `is_singular(['post'])`): `@type`, `@id`, `headline`, `datePublished`, `dateModified`, `mainEntityOfPage`. Author is intentionally omitted (avoid per-author Person ownership).
- `WebPage` (when `is_singular(['page'])` or `is_front_page()`): `@type`, `@id`, `url`, `name`.
- `is_archive()` / non-singular falls through to no per-content emission (site identity only).

### Admin UI

Context Score page bootstrap grows a new `schemaCoordination` block:

```js
{
    posture: 'yoast' | 'rank_math' | 'aioseo' | 'none',
    label: 'Yoast SEO' | … | '',
    detected_via: 'class' | 'plugin_file' | '',
    baseline: ['WebSite','Organization','WebPage','Article'],
    deferred: ['WebSite','Organization','WebPage','Article'],   // posture-driven
    filled: [],                                                  // empty when posture covers all
    emitting: true|false,                                        // result of agentready_schema_emit
}
```

React panel renders a small table on the Context Score page: posture badge, two lists (Deferred / Filled), with a "minimal baseline (no SEO plugin detected)" annotation when posture is `none`.

## Consequences

- v0.1's "gap-fill" is structurally there but in practice the gap is always empty when any of the three plugins is active. That's the *correct* outcome for AC #6 (no duplicate-schema warnings).
- The matrix abstraction pays off only in v0.1.x+ when we add types Yoast/RankMath/AIOSEO don't all cover. Carrying it from day one is the cheaper migration path than retrofitting it later.
- Context Score's `score_schema_coverage` (Engine.php:235) keeps its current logic. We may want to upgrade it in a follow-up to also credit AgentReady's own baseline emission (currently it only credits an external SEO plugin). Leaving that to a follow-up ticket because it touches the score weights and would justify a separate AgDR.
- The Plugin Check Tool finding from AC #6 cannot be unit-tested — it's an external review. We assert the absence by integration-testing that `wp_head` output contains NO `<script type="application/ld+json">` block emitted by AgentReady when any of the three plugins is detected.

## Artifacts

- Includes: `includes/Seo/Plugin_Coverage.php`, `includes/Seo/Schema_Emitter.php`
- Admin: extension of `includes/Admin/Context_Score_Page.php::bootstrap_data()` + a new sub-panel in `src/admin/context-score/index.js`
- Tests: `tests/Unit/Seo/Plugin_Coverage_Test.php`, `tests/Unit/Seo/Schema_Emitter_Test.php`, integration assertion under `tests/Integration/Seo/`
- PR: TBD (links here on merge)
