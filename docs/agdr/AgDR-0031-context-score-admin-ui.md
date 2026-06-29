---
id: AgDR-0031
timestamp: 2026-05-19T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-10-context-score-admin-ui
trigger: ticket #10 (Context Score admin UI); the agency-lead demo surface that AgDR-0006 (audit demo) + AgDR-0030 (engine) were both shaped to feed
status: executed
referenced_in:
  - includes/Context_Score/Rest_Controller.php
  - includes/Context_Score/Site_Health.php
  - includes/Admin/Context_Score_Page.php
  - src/admin/context-score/index.js
---

# AgDR-0031 — Context Score admin UI: own Tools page, two REST routes, one Site Health test, link-shaped fixes

> In the context of needing the v0.1 **Context Score admin surface** (FR-6, ticket #10) — the agency-lead screen that turns the breakdown shipped in AgDR-0030 into something showable in a deck or a sales call — facing the requirement that the surface be **WCAG AA accessible**, **manage_options gated**, **non-destructive in its "fix" affordances** (a one-click action on an admin panel must never silently rewrite a multi-field setting), and **consistent with the existing admin shape on Tools → Context** without nesting under it (Site Health needs a stable URL to deep-link to), I decided to ship a **standalone Tools → Context Score page**, **two REST routes** (`GET /context-score`, `POST /context-score/recompute`), a **one-direct-Site-Health-test** integration that reads the cached breakdown only (no synchronous recompute on the Site Health page), and **link-shaped fixes** that anchor-jump into the existing Context Profile editor — to achieve the AC ("Tools panel built with `@wordpress/components`", "Site Health panel section", "on-demand recompute + last-recompute timestamp", "WCAG 2.1 AA", "capability-checked"), accepting that the "one-click fix" wording in the ticket is satisfied by **deep-link jumps + the recompute button** rather than by destructive auto-mutation buttons.

## Context

- AgDR-0030 already shipped the engine, the storage shape, the cron triggers, and the `wp agentready context-score audit | recompute | reset` CLI. The CLI satisfies the AC's `wp context audit` wording — same engine, same JSON. No new CLI in this ticket.
- The breakdown shape `[overall, sub_scores[name][value/weight/signals/reasons]]` is the durable contract per AgDR-0030. The UI renders it verbatim.
- The existing admin shape (Tools → Context, `agentready-context` slug) mounts three React panels: Context Profile editor, LLMs Index editorial, LLM descriptions. Adding a fourth panel below those would push the screen past two scroll-heights on a 1080p display and make the "show this to the agency lead in a deck" demo screenshot impractical.
- Site Health's `site_status_tests` filter supports two test kinds — `direct` (sync, runs when the Site Health page renders) and `async` (REST-driven, runs in the background). Our cache option is autoload=no but reads are cheap; the direct kind is the right fit. Async would also require a public REST route which would force CSRF gating that the existing `manage_options` gate on the screen already gives us implicitly.
- AgDR-0029 (descriptions admin UI) established the bootstrap-payload pattern: `wp_add_inline_script` with `wp_create_nonce( 'wp_rest' )` so `apiFetch` works against the namespaced REST routes. We reuse it identically.
- AgDR-0006 (audit demo flow) is the deferred original spec for this screen; this AgDR is its v0.1 implementation. Two divergences from AgDR-0006 worth noting: (1) AgDR-0006 sketched the panel as part of the Profile screen; we promote it to its own page because Site Health needs a stable URL. (2) AgDR-0006 sketched "fix-it" buttons that would mutate the Profile directly; we ship link-shaped fixes instead per the "destructive admin button" caveat below.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Standalone Tools → Context Score page; two REST routes; one direct Site Health test; link-shaped fixes** | Stable URL for Site Health deep-link. Page fits a single screenshot. Sub-page-level cap of `manage_options` matches the engine cache's intended audience. Link-shaped fixes avoid the "I clicked Fix and now my Profile is wrong" failure mode. | One more menu entry under Tools (third agentready entry — Profile, Context Score, plus any future). |
| B — Add as a fourth panel on Tools → Context | One menu entry, one screen, fewer files. Mirrors the Profile-screen extension pattern already used by the editorial + descriptions panels. | Site Health needs an anchor — `tools.php?page=agentready-context#context-score` works but breaks if anchor IDs drift. Screenshot exceeds one screen. Recompute timestamp lives next to settings the user can't modify from inside the score panel — confusing affordance. |
| C — Top-level menu entry (Mokhai → Context Score) | Maximum discoverability. Future-proof slot for the rest of the agentready surface. | Premature — Tools → is the WordPress convention for admin tooling that isn't user-content. Adopting a top-level entry on v0.1 commits us to maintaining the surface before we have evidence anyone needs it. Defer to v0.2 if the panel proves popular. |
| D — Block-editor sidebar (Gutenberg post-edit screen) | Embeds the score next to the content the score reflects. | Score is site-level (AgDR-0030), not per-post. Surfacing a site-level metric inside the post-edit context is misleading. Per-page MD quality already lives in the cleanup admin UI from #6 Phase B. |
| E — Direct mutation "Fix it" buttons that rewrite Context Profile state | One-click feels great in a demo. | The Profile is a multi-field option; mutating one field on a button-click without surfacing the full diff is a footgun. Operator can't undo cleanly. Also conflicts with the Settings API options.php nonce flow that the Profile screen actually saves through — we'd be writing the option through a parallel path. |

## Decision

Chosen: **Option A** — standalone Tools page, two REST routes, one direct Site Health test, link-shaped fixes.

### Module layout

```
includes/
├── Admin/
│   └── Context_Score_Page.php           # Tools → Context Score menu + render + asset enqueue
├── Context_Score/
│   ├── Engine.php                       # (existing, AgDR-0030)
│   ├── Service.php                      # (existing, AgDR-0030)
│   ├── Signal_Collector.php             # (existing, AgDR-0030)
│   ├── Rest_Controller.php              # GET + POST /recompute, manage_options gated
│   └── Site_Health.php                  # site_status_tests filter, one direct test
└── ...

src/admin/context-score/
└── index.js                             # React UI built via @wordpress/scripts multi-entry
```

### REST surface

Two routes under the existing `agentready/v1` namespace:

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/agentready/v1/context-score`           | Read cached breakdown. If cache is empty / schema-stale, recompute synchronously before returning. |
| `POST` | `/agentready/v1/context-score/recompute` | Force a synchronous recompute regardless of cache state. Returns the fresh payload. |

Both `permission_callback => current_user_can( 'manage_options' )`. No payload validation on POST (no body fields). The response shape mirrors `Service::get_breakdown()` exactly — `{ schema_version, computed_at, recompute_duration_ms, overall, sub_scores: { … } }` — so the React UI can render either response with the same code path.

The READ route is **deliberately not paginated and not filtered**. The breakdown is six sub-scores, max — pagination is theatre, filtering is the React UI's job after the fetch.

### Tools page

`Admin\Context_Score_Page::PAGE_SLUG = 'agentready-context-score'`. Registered via `add_management_page` so it lives under Tools alongside `agentready-context`. Hook suffix captured for the asset-enqueue gate (same pattern as `Context_Profile_Page`).

The render method:
- Hard `current_user_can( 'manage_options' )` check at the top, `wp_die` on failure.
- `<div class="wrap">` shell, `<h1>` page title.
- Description `<p class="description">` paragraph explaining what the score is.
- Mount-point `<div id="agentready-context-score-root" role="region" aria-label="…">`.
- `<noscript>` fallback notice pointing at `wp agentready context-score audit` as the CLI alternative.

Bootstrap payload (window.agentreadyContextScore):

```
{
  restNamespace: 'agentready/v1',
  restBase: '/context-score',
  restNonce: <wp_rest>,
  profilePageUrl: <tools.php?page=agentready-context>,
  siteHealthUrl: <site-health.php>,
  weights: <Engine::WEIGHTS>,
  initialBreakdown: <Service::get_breakdown() or null>,
}
```

`initialBreakdown` is a server-side first paint so the UI renders without a fetch round-trip when the cache is populated. When null, the UI renders a "computing…" spinner and fires the GET route. The fetch is the same code path that powers the recompute button, so failure modes converge.

### Site Health integration

`Context_Score\Site_Health::register_hooks()` subscribes to `site_status_tests` and adds **one** direct test:

```php
'context-score' => [
    'label' => __( 'Mokhai Context Score', 'agentready' ),
    'test'  => [ self::class, 'run_test' ],
]
```

`run_test()` reads `Service::get_breakdown()` (never recomputes — Site Health is not the recompute moment). Branches:

| Cache state                  | Status                | Badge color | Description                                       |
|------------------------------|-----------------------|-------------|---------------------------------------------------|
| null (no cache yet)          | `recommended`         | `gray`      | "Score has not been computed yet — visit Tools → Context Score or run `wp agentready context-score recompute`." |
| overall ≥ 80                 | `good`                | `green`     | "Context Score: <N>/100. Site is well-prepared for AI agent traffic." |
| overall 50–79                | `recommended`         | `orange`    | "Context Score: <N>/100. <count> sub-score(s) below target." |
| overall < 50                 | `critical`            | `red`       | "Context Score: <N>/100. <count> sub-score(s) below target — review Tools → Context Score." |

Every non-null branch surfaces the **single worst sub-score** by name in the `description`, plus an `actions` link to Tools → Context Score.

Worst-sub-score selection: `argmax_{name} ( (100 - sub.value) * sub.weight / 100 )` — the "leverage" axis. Mirrors the same prioritisation the "what's missing" list uses in the React UI, so Site Health and the admin page tell the same story.

### React UI shape

Three vertical regions on the page:

1. **Overall card** — `<Panel>` with the integer score, a `<ProgressBar>` (or styled `<div>` if the components version doesn't ship one), the computed-at relative timestamp ("2 minutes ago"), and a "Recompute now" `<Button variant="primary">`. Button disables during the request; on completion swaps to a success `<Notice>` that auto-dismisses after 4s.
2. **What's missing** — sorted list of sub-scores whose `value < 100`, ordered by leverage descending (same axis Site Health uses). Each item:
   - Sub-score name + value/100
   - The single highest-priority reason string
   - A `<Button variant="link">` that anchors to the relevant Context Profile setting (deep-link to `agentready-context` with a `#section` anchor)
   - Empty state: "All sub-scores at 100. ✓" with no list rows.
3. **Full breakdown** — `<Panel>` with one `<PanelBody>` per sub-score (initially collapsed). Each body lists:
   - Value/100 + weight
   - All `reasons[]`
   - Raw `signals` rendered as a `<dl>` (key/value pairs) for the technical reader

WCAG AA touch-points:
- All interactive elements rendered by `@wordpress/components` (already AA-audited).
- All decorative colour-coded badges paired with text labels (no colour-only signalling).
- Progress bars expose `role="progressbar"` + `aria-valuenow/min/max`.
- The mount-point `<div>` has `role="region"` + `aria-label`.
- Headings cascade `h1` → `h2` (per section); no level skips.
- Focus order follows DOM order; no `tabindex` overrides.
- All strings via `__()` / `_e()`; bundle calls `wp_set_script_translations`.

### What this AgDR explicitly does NOT decide

- **LLM narrative** — ticket #11. Renders prose from the same breakdown.
- **Per-CPT or per-page sub-scores** — v0.1.x candidate (AgDR-0030 deferred this).
- **Score history / trend graph** — v0.1.x candidate. The engine writes one cache row.
- **"True" one-click fixes** (auto-mutating Context Profile settings) — v0.2 if data justifies it. v0.1 deliberately ships link-shaped fixes only, for the failure-mode reason above.
- **i18n for the Site Health description strings** — same `agentready` text-domain as everything else; pot regeneration is the existing `npm run makepot` flow, no new tooling.

## Consequences

- `includes/Context_Score/Rest_Controller.php`, `includes/Context_Score/Site_Health.php`, `includes/Admin/Context_Score_Page.php` — new files.
- `src/admin/context-score/index.js` — new entry, auto-picked up by the multi-entry webpack config (no `webpack.config.js` change needed).
- `includes/Main.php` — three `register_hooks()` calls added; the existing `Context_Score\Service::register_hooks()` line stays as-is.
- `tests/Integration/Context_Score/Rest_Controller_Test.php` and `tests/Integration/Context_Score/Site_Health_Test.php` — new integration tests covering capability gating, happy-path read, recompute mutation, and Site Health test registration shape.
- No new options. No new cron events. No new CLI subcommands. No new uninstall paths. Everything routes through `Service::get_breakdown()` / `Service::recompute_now()` / `Service::invalidate()` from AgDR-0030.
- Bundle output: `build/admin/context-score.{js,asset.php,css}`. Existing webpack config (`webpack.config.js`) auto-discovers from `src/admin/<name>/index.js` so no config change.

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/10
- Engine AgDR: docs/agdr/AgDR-0030-context-score-engine.md
- PR: (linked here on creation)
