---
id: AgDR-0043
timestamp: 2026-05-25T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-22-multi-channel-discovery
trigger: ticket #22 (Context Score multi-channel discovery); P1 v0.1.1 fast-follow surfaced by AgDR-0006 § "AI Layer coexistence"
status: executed
referenced_in:
  - includes/Context_Score/Engine.php
  - includes/Context_Score/Signal_Collector.php
  - includes/Context_Score/Service.php
  - includes/Context_Score/Site_Health.php
  - includes/Context_Score/Rule_Based_Narrative.php
  - includes/Admin/Multi_Channel_Provider_Detector.php
amends: AgDR-0030
---

# AgDR-0043 — Context Score: `multi_channel_discovery` sub-score, filterable sibling-provider registry

> In the context of `Ref34t/mokhai-agent-readiness-kit#22` — *"the Context Score audit should detect the presence of agent-discovery surfaces that sibling AI-readiness plugins emit (ai.txt, /.well-known/ai-layer, /.well-known/llms-policy.json, OpenAPI) alongside our own /llms.txt, so a site running Mokhai alongside AI Layer is not silently penalised for using a complementary plugin"* — surfaced by AgDR-0006's competitive analysis (AI Layer ships ~10 discovery channels; v0.1 credits one), facing the choice between (a) splitting the existing `discoverability` weight 20 → 10 + 10 to fund a new `multi_channel_discovery` sub-score (keeps the existing engine logic untouched, just rebalances weight; the new sub-score is purely additive credit and emits no warnings, so the AC's "no double-warning" requirement is satisfied by construction), (b) reducing `md_conversion_quality` from 25 → 15 (treats multi-channel as a new dimension rather than a subdivision of discoverability — defensible but higher fixture churn), (c) shaving 1–2 points off every existing sub-score (maximally fair but maximum churn — every Engine_Test assertion that touches `overall` would need updating), I decided **option (a) — split `discoverability` 20 → 10 + 10** plus a **filterable sibling-provider registry** (`Multi_Channel_Provider_Detector::PROVIDERS_FILTER`) that mirrors the `Schema_Coordination_Detector::SIGNATURES` shape — class-exists primary, `is_plugin_active()` corroboration — to achieve the AC (5 surfaces credited, AI Layer detection emits a one-line config-link affordance, no penalty for sibling-plugin coexistence) while accepting that the `/llms.txt` re-credit is intentional double-counting with `discoverability` (the AC explicitly lists `/llms.txt` as one of the five surfaces), the OpenAPI probe is deliberately narrow (static-file probe at three known paths; the always-present `/wp-json/` REST root is **not** credited because it would zero-out the signal across every WP site), and the `ai.txt` filesystem probe misses subdirectory-WordPress installs where the document root differs from `ABSPATH` (documented limitation, v0.1.2 follow-up).

## Context

- **Ticket scope** (`#22`): credit five discovery surfaces in a new sub-score worth ~10 weight; detect AI Layer specifically as the active provider of `/.well-known/ai-layer` and surface a config-link affordance; ensure conflict warnings stay in `#7`'s existing detection path (no double-warning for competing `/llms.txt` plugins).
- **AgDR-0006 driver**: the competitive analysis vs AI Layer scored coexistence higher than competition for sibling agent-readiness plugins, on the same FR-8 logic that drove the Yoast / Rank Math / AIOSEO deference posture. Multi-channel discovery is the score-engine surface of that coexistence posture.
- **AgDR-0030 surface**: the engine's pure-function shape, six sub-scores summing to 100, BREAKDOWN_SCHEMA_VERSION = 1, schema-shape contract for the cached payload consumed by #10 (admin UI) and #11 (LLM narrative).
- **Service-level cache** (Service::CACHE_SCHEMA_VERSION = 2): the cached breakdown payload version tracks Service-shape changes (e.g. narrative slot in #11); bumping it forces stale caches to recompute. The two version constants are independent — the engine schema (BREAKDOWN_SCHEMA_VERSION) tracks the sub-score map, the Service schema (CACHE_SCHEMA_VERSION) tracks the outer payload envelope.
- **AI Layer surface guess**: the public AI Layer plugin's canonical class isn't included in the wp.org plugin directory at the time of this writing. The shipped signature `AILayer\Plugin` + plugin file `ai-layer/ai-layer.php` is a best-effort match against the convention used by similar plugins. The filterable registry exists precisely so adopters who run AI Layer can correct or extend the signature without waiting for a plugin release.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **(a) Split `discoverability` 20 → 10 + 10** (chosen) | Most semantic fit — `multi_channel_discovery` IS a richer form of discoverability. Existing `score_discoverability()` logic stays UNCHANGED (still emits 0..100 internal). Only the WEIGHTS map and the `compute()` sub-score list change. Every existing discoverability test still passes — the internal range hasn't moved, the weight multiplier has. Fixture churn confined to `perfect_signals()` (additive entry) and the rename of one test name. | The 10 points moved out of `discoverability` reduce the perceived signal-weight for the "find /llms.txt" gate; a site with only /llms.txt now contributes 100×10 = 1000 to overall instead of 100×20 = 2000. Mitigated by the new sub-score crediting `/llms.txt` separately at 20 internal × 10 weight = 200 ≈ recovers half the lost weight when only /llms.txt is present. |
| (b) Reduce `md_conversion_quality` 25 → 15 | Treats multi-channel as a new dimension rather than a subdivision of discoverability — closer to the AC's framing. | MD quality is the v0.1 differentiator (post-#107 cleanup quality is the single feature buyers can verify in the admin UI); shaving 10 points off its weight weakens the demo. Higher fixture churn — every md_conversion_quality assertion's contribution to `overall` shifts. |
| (c) Proportional shave across all 6 sub-scores | Maximally "fair" — every sub-score gives up ~1–2 points to fund the new 10. | Maximum fixture churn — every assertion that touches `overall` would change. Three weights would round to non-integers (15 → 13.33), forcing arbitrary precision calls. |

Operator-locked decision in session: option (a). Memorialised here so a future re-litigation has the trade-offs on file.

## Decision

Chosen: **option (a) — split `discoverability` 20 → 10 + 10**, plus a filterable sibling-provider registry following the `Schema_Coordination_Detector` precedent.

### Engine changes

| Constant | Before | After |
|----------|--------|-------|
| `Engine::BREAKDOWN_SCHEMA_VERSION` | 1 | **2** |
| `Engine::WEIGHTS['discoverability']` | 20 | **10** |
| `Engine::WEIGHTS['multi_channel_discovery']` | (absent) | **10** |
| `Service::CACHE_SCHEMA_VERSION` | 2 | **3** |

`score_discoverability()` body is **unchanged**. Its internal 0..100 range and existing signal contributions (50 pts /llms.txt cache, 25 pts ≥1 CPT, 15 pts ≥1 entry, 10 pts no rewrite-shadowing) are preserved. The weight multiplier in `Engine::compute()` halves the contribution to `overall` automatically.

`score_multi_channel_discovery()` is a new pure private static method:

- Reads `$signals['multi_channel_discovery']` (bundle keyed off the WP-side collector).
- Credits 20 internal points per detected surface across `llms_txt_present` + `ai_txt_present` + `well_known_ai_layer` + `well_known_llms_policy` + `openapi_spec_present` (5 × 20 = 100 internal max).
- Emits a per-channel-count reason on every call.
- Emits an additional `"<name> detected — coordinating multi-channel discovery. Configure at <url>"` reason when `active_provider` is non-null **and** `well_known_ai_layer` is true (the provider-detected branch).
- Caps double-credit at 100 internal via the existing `clamp()` helper.

### Detection method matrix per surface

| Surface | Detection method | Why |
|---------|-----------------|-----|
| `/llms.txt` | Re-reads `llms_txt.cache_populated` from the existing collector | Already authoritative in v0.1; reusing the bool keeps both sub-scores in agreement. |
| `ai.txt` | `file_exists( ABSPATH . 'ai.txt' )` | Static-file convention; filesystem probe is fast, deterministic, and zero-HTTP. |
| `/.well-known/ai-layer` | `file_exists( ABSPATH . '.well-known/ai-layer' )` OR registered sibling provider detected | File probe handles static deployments; class/plugin-file probe handles dynamic-rewrite providers like AI Layer that serve the URI through PHP, not a file on disk. |
| `/.well-known/llms-policy.json` | `file_exists( ABSPATH . '.well-known/llms-policy.json' )` | We don't emit this yet (v0.2+ FR-14); crediting sites that emit it themselves. After FR-14 ships, the probe will also credit our own emission. |
| OpenAPI / Swagger | `file_exists( ABSPATH . 'openapi.json' )` OR `openapi.yaml` OR `swagger.json` | Static-spec convention; deliberately does **not** credit `/wp-json/` since the WP REST root is always present and would zero out the signal across every WP site. |

### Sibling-provider registry

`Multi_Channel_Provider_Detector::DEFAULT_SIGNATURES` ships a single entry for AI Layer:

```php
'ai_layer' => array(
    'name'        => 'AI Layer',
    'class'       => 'AILayer\\Plugin',
    'plugin_file' => 'ai-layer/ai-layer.php',
    'config_path' => 'admin.php?page=ai-layer',
),
```

Adopters extend via `apply_filters( 'ai_readiness_kit_multi_channel_providers', $registry )`. Detection precedence is class-exists first, `is_plugin_active()` corroboration second — identical to `Schema_Coordination_Detector::detect()`.

The `class` / `plugin_file` for AI Layer is a best-effort guess (see Context above); the filter exists precisely so production users with the plugin installed can correct the signature without a plugin release. The shipped entry is conservative — false-negative is the failure mode (no credit when AI Layer is in fact active), not false-positive.

### Schema bump rationale

The engine-level bump (BREAKDOWN_SCHEMA_VERSION 1 → 2) follows AgDR-0030's own "additive sub-score additions with safe defaults bump the version" rule. The Service-level bump (CACHE_SCHEMA_VERSION 2 → 3) is the load-bearing safety: it invalidates every existing cached payload (which only carries 6 sub-scores), forcing a fresh recompute that populates the 7th sub-score on first read after upgrade. Without it, stale 6-sub-score caches would read as fresh for up to 24 hours (daily cron backstop) and the admin UI would render with the new sub-score visibly missing.

### Site_Health.php + Rule_Based_Narrative.php — hardcoded sub-score lists

Both files had explicit `switch ($name)` statements over the six sub-score names — fall-through defaults exist but emit generic copy. To keep the i18n + deterministic-narrative quality consistent across all seven sub-scores, both files get explicit cases for `multi_channel_discovery`:

- `Site_Health::humanize_sub_score_name` — adds `'multi-channel discovery'` to the translatable label map.
- `Rule_Based_Narrative::compose_one` — adds a `case 'multi_channel_discovery'` dispatch + new `for_multi_channel_discovery()` template method covering the perfect / partial / empty / sibling-active branches.

### CLI + REST controller — no shape change

The CLI command (`includes/Cli/Context_Score_Command.php`) and REST controller (`includes/Context_Score/Rest_Controller.php`) both iterate `$payload['sub_scores']` generically — the new sub-score appears automatically in `wp ai-readiness-kit context-score audit` (JSON, porcelain, and pretty modes) and `GET /wp-json/ai-readiness-kit/v1/context-score`.

## Consequences

### Positive

- A site running AI Layer alongside Mokhai no longer loses score for using a complementary discovery surface — coexistence ≠ competition, matching AgDR-0006's positioning.
- Five concrete discovery surfaces are now first-class signals in the engine, making the breakdown actionable for non-technical buyers ("you have /llms.txt; add ai.txt and an OpenAPI spec to broaden coverage").
- The filterable provider registry lets adopters credit additional sibling plugins (Knowledge Lens, future AI-readiness plugins) without a plugin release.
- The double-credit for `/llms.txt` (in `discoverability` and `multi_channel_discovery`) is a deliberate design choice that gives sites a coherent "how many channels" reading rather than penalising the canonical /llms.txt site for being one-channel-only.

### Negative / accepted trade-offs

- Half the original `discoverability` weight is reallocated; a v0.1 site running only `/llms.txt` sees its overall score drop by up to 10 points until they add additional surfaces. This is the intended behaviour — the new sub-score raises the bar for "agent-discoverable" beyond a single channel.
- The `ai.txt` filesystem probe misses subdirectory-WordPress installs (where `home_url()` resolves to a path that isn't `ABSPATH`). Filed as a v0.1.2 candidate.
- The OpenAPI probe is deliberately narrow — it credits a static spec at three known paths. Sites that publish OpenAPI through a custom REST route or a separate documentation domain won't be credited. Acceptable for v0.1.1; an HTTP-based probe is a v0.2+ candidate.
- The shipped AI Layer signature is a best-effort guess. If the canonical class is something other than `AILayer\Plugin`, sites running AI Layer won't get the provider-detected branch until they extend the filter. Documented in the file's docblock + this AgDR.

### Out of scope (explicit non-goals for #22)

- HTTP loopback detection of `/.well-known/*` URIs — too brittle for v0.1.1, deferred to v0.2+.
- Subdirectory-WordPress install path resolution for `ai.txt` — documented limitation, v0.1.2 follow-up.
- Emitting our own `/.well-known/llms-policy.json` — v0.2+ FR-14, separate ticket.
- Counting "additional channels beyond /llms.txt" rather than "total channels including /llms.txt" — AC explicitly lists `/llms.txt` as one of the five surfaces; double-credit is intentional.

## Artifacts

- PR: `Ref34t/mokhai-agent-readiness-kit#TBD` (will link the merge commit on close).
- Ticket: [Ref34t/mokhai-agent-readiness-kit#22](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/22).
- Driver: AgDR-0006 (competitive analysis vs AI Layer) § "Multi-channel discovery surfaces".
- Amends: AgDR-0030 § "Sub-scores and weights" table — the original `discoverability=20` row is rebalanced to `discoverability=10` + `multi_channel_discovery=10`. AgDR-0030 is otherwise preserved (pure-engine pattern, single option cache, debounce + daily cron triggers all unchanged).
