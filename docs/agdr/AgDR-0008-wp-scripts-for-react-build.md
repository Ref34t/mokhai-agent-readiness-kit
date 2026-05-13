---
id: AgDR-0008
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-4-context-profile-admin
trigger: ticket #4 (Context Profile admin screen) introduces the plugin's first React UI — block-time decision: which JS build pipeline
status: executed
---

# AgDR-0008 — `@wordpress/scripts` for the React admin-UI build pipeline

> In the context of needing a JS build pipeline for the Context Profile admin screen (the plugin's first React UI) and every downstream admin UI in v0.1.1+ (Context Score panel #10, Agent Activity #11, Bot Policy v0.1.1), facing the choice between adopting `@wordpress/scripts` (the WordPress core team's opinionated webpack wrapper), rolling our own webpack/vite config, or going build-step-free with native ES modules, I decided to adopt `@wordpress/scripts` as the sole devDependency for the JS pipeline (`build`, `start`, `lint:js`, `format` npm scripts) emitting to `build/admin/` with the generated `.asset.php` dependency manifest, to achieve a build pipeline that matches `WordPress/ai`'s shape (per AgDR-0004's positioning), produces a tiny bundle (6.78 KiB for the Context Profile UI), and integrates `@wordpress/components` / `@wordpress/element` / `@wordpress/i18n` without manually pinning their versions, accepting that we inherit a heavy node_modules footprint (~1.4k packages) on the dev path even though the runtime is React + a handful of WP packages.

## Context

Ticket #4 introduces the first React UI in AgentReady — the Context Profile editor under WP admin → Tools → Context. AC says "built with `@wordpress/components`," which means we ship a JS bundle that depends on the React + WP-components packages WP exposes at runtime via the dependency-extraction plugin.

Three downstream tickets need the same build pipeline:

- **#10** — Context Score admin panel (a React component injected into the Tools page and the Site Health UI)
- **#11** — Agent Activity dashboard (charts + filterable table)
- **v0.1.1 Bot Policy module** — robots.txt editor UI

If each ticket reinvents its build pipeline, three slightly-different webpack configs ship and we burn time on tooling drift instead of on the plugin. A shared pipeline is the only sane shape.

The relevant constraint from the PRD:

> "Admin UI built with WP core React components (`@wordpress/components`) for visual consistency — no custom design system."

…and from AgDR-0004:

> "Mirror WordPress/ai's CI shape wholesale." (the WP core team's AI plugin)

WordPress/ai uses `@wordpress/scripts` for its build. The "core-team-grade" positioning we adopted in AgDR-0004 extends naturally to the JS build pipeline.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — `@wordpress/scripts` (webpack wrapper)** | Default WP plugin/block pipeline; ships `.asset.php` dependency manifest WP enqueue expects; auto-handles `@wordpress/*` externals (no version pinning headaches); built-in ESLint + Prettier + Jest configs; matches WordPress/ai's shape. | Heavy node_modules footprint (~1.4k packages, ~20 audit warnings on transient deps); webpack is the slowest of the modern bundlers. |
| B — Roll our own webpack config | Total control; trim node_modules to only what we use. | Re-implement WP-externals handling, dependency-manifest emission, i18n script-translations integration — three places we'd reinvent `@wordpress/scripts`' wheel. Maintenance cost dominates. |
| C — Vite + a hand-rolled `.asset.php` emitter | Faster dev server (HMR), smaller footprint, modern. | No upstream support for the `.asset.php` manifest format; would need a custom plugin; community plugins exist but aren't audited; loses the "core-team-grade" positioning. |
| D — Native ESM, no build step | Zero build complexity; works in modern browsers. | `@wordpress/components` is published as CJS + ESM but the JSX must be transpiled; React itself requires bundling; admin UI must support WP's expected dependency-injection shape which is bundler-friendly, not native-ESM-friendly. Net: would only work for the simplest of UIs, not the Context Profile editor. |
| E — Parcel | Zero-config bundler; tiny footprint. | Same `.asset.php` and WP-externals gap as C; smaller community for WP-specific issues. |

## Decision

Chosen: **Option A — `@wordpress/scripts@^30.27.0` as the sole JS-pipeline devDependency.** Concrete choices:

| Element | Choice |
|---------|--------|
| **Entry point** | `src/admin/context-profile/index.js` for the Context Profile UI. One entry per admin screen; future screens add their own entries. |
| **Output** | `build/admin/context-profile.js` + `build/admin/context-profile.asset.php` |
| **`.asset.php` consumption** | The PHP enqueue (`Context_Profile_Page::enqueue_assets`) reads `dependencies` and `version` from the manifest. Falls back to a `WPCTX_VERSION`-versioned no-deps enqueue if the manifest is missing (degrade with admin notice). |
| **npm scripts** | `build` (production), `start` (watch), `lint:js`, `format` |
| **Lint config** | `@wordpress/scripts`'s default ESLint (`plugin:@wordpress/eslint-plugin/recommended`) + Prettier. Inherits the same rules WordPress/ai applies. |
| **Externals** | The default `@wordpress/scripts` dependency-extraction-webpack-plugin handles `@wordpress/*` packages as externals automatically. Nothing custom. |
| **Build artefact tracking** | `build/` stays gitignored per the existing `.gitignore`. CI / wp.org distribution builds the artefact at release time. |

### What this AgDR explicitly does NOT decide

- **Whether to ship the React bundle in the wp.org plugin ZIP.** It must be shipped (wp.org reviewers run the plugin without `npm install`). Release packaging — which currently runs `npm run build` then `composer install --no-dev` before zipping — is a separate concern that lands when the release ticket arrives. The build script is in place; the release zipping isn't.
- **The exact ESLint rule exceptions.** None so far — the WP defaults pass cleanly on the Context Profile UI. Future exceptions need their own ad-hoc justification, not their own AgDR.
- **Test coverage of the React UI.** PHP unit tests cover the storage contract (sanitiser, migrations, capability gate). The React UI is covered by the QA verification step + admin smoke tests; no Jest harness in v0.1. Adding `wp-scripts test-unit` later doesn't bump this AgDR.

## Consequences

- `package.json` adds `@wordpress/scripts: ^30.27.0` as a devDependency, plus `build`, `start`, `lint:js`, `format` scripts.
- `package-lock.json` grows by ~24k lines (transient dep tree). This is dev-only; runtime install size is unchanged.
- Future admin React UIs (#10 Context Score panel, #11 Agent Activity dashboard) reuse the same pipeline by adding their own entry under `src/admin/<screen>/index.js` and a parallel build target.
- Release packaging (separate future ticket) must run `npm install --omit=optional && npm run build` before the zip step so the artefact ships with the compiled bundle. Until that ticket arrives, distribution from this repo requires the maintainer to run `npm run build` locally.
- The `npm audit` warnings on transient deps are upstream WP responsibility; we don't pin patch versions ourselves.

## Artifacts

- Ticket: https://github.com/Ref34t/agentready/issues/4
- AgDR: this file
- PR: (linked here on creation)
- Build artefact path: `build/admin/context-profile.{js,asset.php}` (gitignored, built locally / in CI / before release packaging)
