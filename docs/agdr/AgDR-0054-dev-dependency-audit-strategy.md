# Dev-Dependency Audit Strategy

> In the context of clearing GH-173's `npm audit` advisories (62: 15 high / 47 moderate, all dev-only), facing transitive build-tooling vulnerabilities where most leaves have no published fix, I decided to bump the toolchain to latest + pin a small set of `overrides` + gate CI on the production surface only, to achieve a green shipped-surface audit and 0 high-severity dev advisories, accepting that ~48 moderate dev-only advisories remain documented rather than suppressed.

## Context

`npm audit` reported 62 advisories — **all dev-only** (`npm audit --omit=dev` = 0, `composer audit` clean). The plugin ZIP bundles no JavaScript dependencies (`node_modules/` is excluded by `.distignore`, verified by `build-zip-verify`), so none of these reach a user's site. Most advisories are transitive leaves (`ajv` $data ReDoS, `webpack-dev-server`, `ws`) with **no fix available** — resolution depends on upstream `@wordpress/scripts` / `@wordpress/env` / `@wp-playground` chains. The goal is a green, low-noise CI audit signal that still fails loudly on a *real* (production) advisory, not a cosmetic zero.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Gate full `npm audit` (dev + prod) at 0 | Simplest signal | Couples our green build to upstream release cadence we don't control; forces suppressing unfixable dev advisories wholesale |
| Add `audit-ci` / `better-npm-audit` + allowlist file | Purpose-built allowlist | Adds another devDependency (more audit surface, ironic); more config to maintain |
| Bump toolchain + targeted `overrides` + gate **prod-only** audit + document dev advisories | Zero new deps; clears all highs; prod surface guarded at 0; never hides a prod advisory; dev noise documented not suppressed | Dev `npm audit` stays non-zero (moderate, documented); `overrides` need pruning as upstream catches up |

## Decision

Chosen: **bump toolchain + targeted `overrides` + prod-only CI gate + documentation**, because it removes every high-severity advisory and guards the true shipped surface at 0 with no new dependencies, while honestly surfacing (not hiding) the remaining dev-only moderates.

- `@wordpress/env` `^10` → `^11.8`, `@wordpress/scripts` `^30.27` → `^32.4`.
- `overrides`: `minimatch ^10.0.3` (3 high ReDoS), `serialize-javascript ^7.0.5` (2 high RCE/DoS), `qs ^6.15.2` (1 moderate DoS) — each API-compatible for our webpack build, validated by `check-build` + `build-zip-verify`.
- New `npm-audit-prod` CI job runs `npm audit --omit=dev` and requires 0.
- Policy + deferred-advisory list documented in `docs/dependency-audit.md`.

Result: 62 (15 high / 47 moderate) → 48 (0 high, 0 critical), all moderate, dev-only, no upstream leaf fix.

## Consequences

- CI gains a regression guard that turns red the instant a real advisory reaches a production dependency.
- The `overrides` block must be re-evaluated on each toolchain bump and pruned once upstream resolves the leaves natively (noted in the doc).
- `uuid` (moderate, dev-only) is deferred — its fix needs a breaking v8 → v14 override that risks the build for little gain.
- A full `npm audit` will keep reporting the documented dev-only set; contributors are pointed to `docs/dependency-audit.md` so the noise is expected, not alarming.

## Artifacts

- PR for GH-173 (`chore(#173): clear dev-dependency npm audit advisories`)
- `docs/dependency-audit.md`
- `.github/workflows/ci.yml` → `npm-audit-prod` job
