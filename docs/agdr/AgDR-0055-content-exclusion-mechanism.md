# Content Exclusion Mechanism for Agent Output

> In the context of #180 (junk content — sample pages, lorem-ipsum, test posts — polluting `/llms.txt` and `.md` output), facing the need to exclude *published-but-junk* content (status filtering already exists), I decided to extend the single `Context_Profile_Settings::get_exposure_reason()` gate with three new exclusion sources — a per-post meta toggle, a site-level ID/slug deny-list, and a WordPress-sample toggle — plus implement the dormant `agentready_post_is_noindexed` filter for Yoast & Rank Math, to achieve uniform exclusion across every agent surface, accepting that AIOSEO and tag/category-based rules are deferred.

## Context

A managed-project staging site surfaced obvious non-content in `/llms.txt`: "Sample Page", "Hello World", lorem-ipsum posts, and "test". All were **published**, so the existing `exposed_statuses` gate (publish-only, verified on staging) didn't catch them — the gap is *published-but-junk* content, not draft filtering.

The exposability predicate is cleanly centralised: `get_exposure_reason()` is the single gate that `/llms.txt` (`Entry_Source`), `.md` serving (`Markdown_Views\Service`), and #178 alternate advertising (`Discovery\Alternate_Advertiser`) all delegate to. The `agentready_post_is_noindexed` filter existed as a hook point since #12 but had no subscriber. #176 (honour SEO-plugin noindex) was folded into this ticket on the issue thread.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Filter inside each consumer (`Entry_Source`, `Service`, `Alternate_Advertiser`) | Localised | Three places to keep in sync; guarantees drift; defeats the centralised-gate design |
| Extend `get_exposure_reason()` with new gates | One chokepoint; every surface honours exclusion for free (incl. the "excluded → no `.md` + suppressed alternate" ACs) | Adds reasons the admin REST/preview surface must understand (cheap) |
| Add an `audit-ci`-style external rule engine | Flexible | Massive over-engineering for a deny-list |

Exclude-mechanism breadth (per operator decision on the issue): **Focused MVP** — per-post meta toggle + site-level ID/slug deny-list + WP-sample toggle + Yoast/Rank Math noindex. **Full** (tag/category rules + AIOSEO) deferred.

## Decision

Chosen: **extend the central `get_exposure_reason()` gate**, because it makes exclusion apply uniformly to every agent surface with no duplication, and is the design the existing code already commits to.

Concretely:

- Two new exposure reasons: `excluded` (per-post `_agentready_excluded` meta, or the site-level `excluded_ids` / `excluded_slugs` deny-lists) and `sample` (WordPress-seeded slugs `hello-world` / `sample-page`, gated on the `exclude_wp_samples` toggle, **default on**).
- Per-post toggle: `register_post_meta` (`Exclude_Meta`) + a block-editor sidebar panel (`Exclude_Sidebar_Assets` + `src/admin/exclude-sidebar`) bound via `useEntityProp`.
- Site-level deny-list: `excluded_ids` (int[]) + `excluded_slugs` (string[]) on the Context Profile, edited through one textarea on the settings SPA (numeric line → ID, else slug).
- Noindex: a new `SEO_Noindex_Detector` subscribes to `agentready_post_is_noindexed`, reading Yoast (`_yoast_wpseo_meta-robots-noindex == '1'`) and Rank Math (`rank_math_robots` array contains `noindex`) per-post meta, gated on `Schema_Coordination_Detector`'s active-plugin posture so stale meta from a deactivated plugin can't leak a false noindex.
- `CURRENT_SCHEMA_VERSION` bumped 1 → 2 (additive fields with a behaviour-changing default — `exclude_wp_samples = true`).

### AIOSEO deferral

AIOSEO stores robots flags in its own `wp_aioseo_posts` custom table, not post-meta — a different read path (table query) than Yoast/Rank Math. Deferred to a follow-up to keep this PR's surface contained; the `default` branch of `SEO_Noindex_Detector` returns false, so an AIOSEO site simply gets no automatic noindex exclusion until then (it can still use the manual deny-list).

## Consequences

- One gate, every surface: `/llms.txt`, `.md`, and #178 advertising honour exclusions with zero per-consumer code.
- Existing sites get WP sample content dropped on upgrade (`exclude_wp_samples` defaults true via `migrate()`'s default-merge) — desirable; sample content is never legitimate agent input. Operators who want it back toggle it off.
- Near-duplicate detection stays **out of scope** (honest-mirror principle — agentready indexes what's published; fuzzy de-duplication risks dropping legitimately-repeated content).
- New deferred follow-ups: AIOSEO noindex (custom-table read) and tag/category-based exclusion rules.

## Artifacts

- PR for #180 (`feat(#180): exclude draft / placeholder / test content from agent output`)
- `includes/Admin/Context_Profile_Settings.php` (gates + deny-list sanitisers)
- `includes/Admin/SEO_Noindex_Detector.php`, `Exclude_Meta.php`, `Exclude_Sidebar_Assets.php`
- `src/admin/exclude-sidebar/index.js`, `src/admin/context-profile/app.js` (Content exclusions panel)
- Tests: `tests/Unit/Admin/Context_Profile_Exposure_Test.php`, `tests/Unit/Admin/SEO_Noindex_Detector_Test.php`, `tests/Integration/LlmsTxt/Entry_Source_Test.php`
