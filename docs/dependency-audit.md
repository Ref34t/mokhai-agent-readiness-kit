# Dependency Audit Policy

This plugin ships **no JavaScript dependencies**. The webpack-built admin
bundles under `build/admin/` are self-contained, and `node_modules/` is
excluded from the distribution ZIP by `.distignore` (verified by the
`build-zip-verify` CI job). The only dependencies that reach a user's site are
the production PHP packages in `vendor/`, which are audited separately by
`composer audit` and are clean.

## What CI gates

CI runs **`npm audit --omit=dev`** (the `npm-audit-prod` job) and requires it to
stay at **0 advisories**. This is the true shipped surface. Any advisory that
reaches a production dependency turns the build red immediately — the gate never
hides a real, user-facing advisory.

CI deliberately does **not** gate the full `npm audit` (dev tree included). Doing
so would couple our green build to the release cadence of upstream toolchains we
don't control, and would force us to either pin transitive deps we can't safely
override or suppress advisories wholesale.

## Dev-only advisories (build tooling)

`npm audit` (including dev deps) reports advisories in the build toolchain —
`@wordpress/scripts` (→ webpack, terser, stylelint) and `@wordpress/env`
(→ `@wp-playground/*`, `@php-wasm/*`). These run only on a developer's machine or
on a CI runner during the build; they are **never bundled** into the shipped
plugin, so they carry **no runtime risk to users**.

History (GH-173, 2026-06-08):

| | High | Moderate | Total |
|---|---|---|---|
| Before (env 10.x, scripts 30.x) | 15 | 47 | 62 |
| After (env 11.x, scripts 32.x + overrides) | **0** | 48 | 48 |

### Actions taken

- Bumped `@wordpress/env` `^10` → `^11.8` and `@wordpress/scripts` `^30.27` →
  `^32.4` (latest), pulling in patched webpack / wp-playground transitive chains.
- Ran `npm audit fix` for the non-breaking transitive resolutions.
- Added `overrides` for transitives where only a breaking bump fixed the
  advisory but the bump is API-compatible for our build:
  - `minimatch` `^10.0.3` — clears 3 high-severity ReDoS advisories.
  - `serialize-javascript` `^7.0.5` — clears 2 high-severity (RCE / DoS) advisories.
  - `qs` `^6.15.2` — clears 1 moderate DoS advisory.

  These overrides are validated by the `check-build` and `build-zip-verify` CI
  jobs (webpack compiles, artefacts byte-identical, ZIP structure intact).

### Remaining advisories (no leaf fix available)

These have **no fix available** at the leaf and depend on upstream
`@wordpress/env` / `@wp-playground` / webpack chains releasing bumped
transitives. They are dev-only and tracked for re-evaluation on the next
toolchain bump:

| Package | Severity | Advisory | Why deferred |
|---|---|---|---|
| `ajv` | moderate | ReDoS via `$data` option | No fix published; pulled by webpack `schema-utils` and `@wp-playground/cli`. |
| `webpack-dev-server` | moderate | source-code exposure on malicious site (×3) | No fix; only relevant to `wp-scripts start` dev server, never CI/prod. |
| `ws` | moderate | uninitialized memory disclosure | No fix; pulled by `@php-wasm/*` / `jsdom` / `puppeteer-core` (test + wp-env only). |
| `uuid` | moderate | missing buffer bounds check in v3/v5/v6 | Fix needs a breaking `uuid` v8 → v14 override; deferred — moderate, dev-only, and the API jump risks the build for little gain. |

## Re-checking

```bash
npm audit --omit=dev   # must be 0 — the gate
npm audit              # dev tree; expect the documented set above
composer audit         # production PHP; must be clean
```

When bumping `@wordpress/scripts` or `@wordpress/env`, re-run the audit and prune
any `overrides` entries that the upstream chain has since resolved on its own.
