# AgDR-0034 — Gate native JSON-LD emission behind a Context Profile toggle (default off); credit the toggle in `Engine::score_schema_coverage`

> In the context of `Ref34t/agentready#73` — *"Native Schema_Emitter so schema_coverage is achievable without a third-party SEO plugin"* — facing the choice between (a) emitting the gap-fill baseline always-on whenever no SEO plugin is detected (the behaviour PR #75 shipped) vs. requiring an explicit Context Profile opt-in, (b) gating per-content nodes (`Article` / `WebPage`) by the Profile's `exposed_cpts` + `exposed_statuses` allowlists vs. leaving emission decoupled from the exposure model, and (c) keeping `Engine::score_schema_coverage` blind to the native emitter vs. crediting the toggle as schema coverage on par with a third-party SEO plugin, I decided to **add a `schema_emit_enabled` Profile field defaulting to `false`**, **gate per-content emission by the same `exposed_cpts` / `exposed_statuses` allowlists the rest of agentready honours**, and **make `score_schema_coverage` award full credit when EITHER an SEO plugin is detected OR `schema_emit_enabled=true`**, to achieve a launch story where AgentReady stands on its own as the agent-readiness layer (no "install an SEO plugin to score 100" disclaimer) while preserving FR-9's safe-by-default exposure posture — accepting that #73's intent shifts the v0.1 default away from "always emit baseline when no SEO plugin" (PR #75's shape) toward "operator explicitly opts in", and that the score path now blends a runtime detector signal with a Profile-state signal which couples Score's correctness to Profile staleness in a way it wasn't before.

## Context

- **AC of #73** (verbatim):
  1. `Schema\Emitter` generates valid JSON-LD `Article` / `WebPage`, populated from post + site identity.
  2. Emitted on `wp_head`, **gated by Context Profile's `exposed_cpts` + `exposed_statuses`**.
  3. **Coexistence**: when an SEO plugin is detected, the native emitter stays silent.
  4. `Engine::score_schema_coverage` updated so the native emitter satisfies the sub-score the same way an SEO plugin does — the 60-value "missing capability" branch only fires when neither native nor third-party schema is active.
  5. Integration test asserts JSON-LD presence on an exposed post, absence on a non-exposed post, absence when an SEO plugin is detected.
  6. **Default off; toggle in Context Profile under "Schema emission"**.
  7. PHPCS / PHPStan clean; new code goes through an AgDR.
- PR #75 (#12 / AgDR-0033) shipped the renderable surface: `Schema_Emitter::render_for_posture()` plus the `Plugin_Coverage` gap-fill matrix. That work emitted the baseline whenever no SEO plugin was detected, with no Profile-level toggle. This AgDR's behaviour change re-classes the emitter as **opt-in**, not always-on.
- The Profile already mediates every other agent-facing exposure decision in agentready (Markdown Views, LLMs Index, Description filters). Adding the emitter to that mediation is the consistent shape — operators have one place to decide what agents see.

## Options Considered

### A. Emission default — always-on (PR #75 shape) vs. opt-in toggle

| Option | Pros | Cons |
|--------|------|------|
| A1 — Keep PR #75's always-on default; toggle is opt-OUT only | Best-effort agent-readiness out of the box. Fewer post-install steps for the operator. | Conflicts with #73 AC #6 ("Default off"). Surprises operators whose theme already emits per-content schema. Operator sees agentready JSON-LD in view-source without ever ticking a box — violates FR-9's "safe-by-default exposure" spirit. |
| **A2 — Gate by `schema_emit_enabled` Profile field, default `false` (chosen)** | Matches AC verbatim. Consistent with the rest of agentready's exposure model — every output is operator-confirmed. Theme-conflict risk dropped to zero on fresh installs. Operator scoring 60 in Context Score sees "Enable Schema emission in Context Profile" — that's an action they can take in seconds. | Changes the default from PR #75. Any operator who installed #75 between merge and #73 ship has the baseline in their view-source; flipping the default to off after they upgraded will REMOVE it without notice. Mitigation: the upgrade window is the same day. Accepted. |
| A3 — Per-CPT toggle matrix in the Profile | Most surgical opt-in. | UI bloat for v0.1; operators just want "on / off". Defer to v0.1.x if usage demands. |

### B. Per-content gate shape — Profile-driven vs. emit-everything-when-on

| Option | Pros | Cons |
|--------|------|------|
| B1 — Once `schema_emit_enabled=true`, emit `Article` / `WebPage` regardless of CPT or status | Simplest code path. | Defeats the purpose of `exposed_cpts` / `exposed_statuses` for the schema sub-system. An operator opted into native schema would suddenly leak Article markup for unpublished posts if rendered on a singular preview, etc. Inconsistent with Markdown Views + LLMs Index gating. |
| **B2 — Per-content nodes (`Article` / `WebPage`) gated by `exposed_cpts` + `exposed_statuses`; site-identity nodes (`WebSite` / `Organization`) ungated (chosen)** | Mirrors the rest of agentready's exposure model exactly. `WebSite` / `Organization` are site-level — they don't have a content exposure decision attached. | Slightly more conditional code at render time. Tests need to cover both axes. Both costs accepted. |
| B3 — Gate every node (incl. site identity) by some Profile field | Maximum consistency. | No Profile field is the right axis for site-identity nodes. Inventing one for v0.1 is over-design. Rejected. |

### C. Engine credit shape — blind to toggle vs. credit native emission

| Option | Pros | Cons |
|--------|------|------|
| C1 — `score_schema_coverage` stays blind to the toggle, only credits SEO plugin presence | Smallest diff. Score signal stays a pure runtime probe. | Misses AC #4. Operators with native emission ON would see 60 + the "install an SEO plugin or wait for native" reason text that PR #72 / Engine.php:252 ships — false negative, dishonest positioning. |
| **C2 — Award full credit when SEO plugin detected OR `schema_emit_enabled=true` (chosen)** | Matches AC. Operator who opts into native schema sees the score reflect it. Reason text becomes accurate for both paths. | Couples Score correctness to Profile freshness — if the operator toggles native off and Context Profile hasn't been re-read by Score yet (debounced recompute), Score lags by up to one debounce window. Mitigation: Score already re-reads Profile on `agentready_context_profile_saved`. The lag is already the existing pattern; this AgDR doesn't widen it. |
| C3 — Credit only native; deprecate the SEO-plugin credit branch | Eventually correct as agentready's emitter becomes capable. | Deprecates the SEO-plugin credit too early — Yoast / Rank Math / AIOSEO still emit richer schema (BreadcrumbList, FAQPage, Product) that agentready doesn't yet. Rejected for v0.1. |

### D. Signal-collection shape

The Score's `schema_signals()` already exposes the active SEO plugin slug. To pass the toggle into Score we have two options:

| Option | Pros | Cons |
|--------|------|------|
| **D1 — Add `native_emit_enabled` to the `schema` signal block (chosen)** | One new key in an existing block. `Engine` reads it the same way it reads the existing `seo_plugin` key. | Bumps the signal-block shape. Acceptable additive change. |
| D2 — Add a separate top-level signal block | Cleaner separation. | Two reads in Engine for the same conceptual concern. No semantic benefit over D1. Rejected. |

## Decision

Chosen: **A2 + B2 + C2 + D1**.

### Profile change

Add `'schema_emit_enabled' => false` to `Context_Profile_Settings::get_defaults()`. Sanitiser uses the same "default true, explicit false" pattern as the other module enable flags, **inverted** to default false:

```php
$out['schema_emit_enabled'] = ! \array_key_exists( 'schema_emit_enabled', $input )
    ? false
    : ! empty( $input['schema_emit_enabled'] );
```

No `schema_version` bump — pure additive field with a safe default.

### Emitter change

`Schema_Emitter::render_for_posture()` gains a precondition: read the Profile, return early when `schema_emit_enabled` is false. Per-content node builders (`build_article_node`, `build_webpage_node`) check the resolved post's `post_type ∈ exposed_cpts` and `post_status ∈ exposed_statuses`. Site-identity nodes stay ungated.

### Engine change

`Signal_Collector::schema_signals()` adds:

```php
'native_emit_enabled' => (bool) ( $profile['schema_emit_enabled'] ?? false ),
```

`Engine::score_schema_coverage` shape:

```php
$plugin = (string) ( $schema['seo_plugin'] ?? '' );
$native = (bool)   ( $schema['native_emit_enabled'] ?? false );

if ( '' !== $plugin ) { /* existing 100-pt branch */ }
elseif ( $native )    { $value = 100; $reasons[] = 'AgentReady is emitting native JSON-LD (WebSite + Organization + per-content). Schema coverage satisfied without a third-party SEO plugin.'; }
else                   { /* existing 60-pt "missing capability" branch */ }
```

### Admin UI change (minimal)

The Context Profile page gains one checkbox under a new "Schema emission" section, wired through `Settings API`. The Schema Coordination panel on the Context Score page (introduced #75) already reflects the `emitting` boolean — that value is now sourced from the Profile toggle instead of always-true.

## Consequences

- Operators upgrading from PR #75 → #73 lose the always-on baseline emission. View-source diff: lose 4 JSON-LD nodes on the front-end. The Context Score's `schema_coverage` reason text changes from "AgentReady native emission active" to "Enable Schema emission in Context Profile" until the operator opts in.
- The toggle becomes the single boolean operators flip to satisfy Context Score's `schema_coverage` sub-score without a third-party plugin — the v0.1 positioning ("agentready IS the agent-readiness layer") is now mechanically honest.
- `exposed_cpts` / `exposed_statuses` is now load-bearing for one more module. Tests should cover the no-exposed-cpt case (operator with native on but no CPTs exposed yet — site identity emits, per-content nodes don't).
- Future v0.1.x can add `BreadcrumbList` / `FAQPage` / `Product` to the baseline without revisiting the toggle — same opt-in gates them.

## Artifacts

- Profile: `includes/Admin/Context_Profile_Settings.php` (+ sanitiser branch, defaults)
- Emitter: `includes/Seo/Schema_Emitter.php` (precondition + per-content gates)
- Engine: `includes/Context_Score/Engine.php` + `Signal_Collector.php` (native_emit_enabled branch)
- Profile UI: `src/admin/context-profile/index.js` (Schema emission section)
- Tests: `tests/Unit/Seo/Schema_Emitter_Test.php` (Profile-off / Profile-on / exposed-cpt branches), `tests/Unit/Context_Score/Engine_Test.php` (native-emit credit)
- PR: TBD (links here on merge)
