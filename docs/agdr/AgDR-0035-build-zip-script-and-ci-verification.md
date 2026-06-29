# AgDR-0035 — Reproducible wp.org ZIP build script + CI verification

> In the context of `Ref34t/mokhai-agent-readiness-kit#80` — *"tools/build-zip.sh + .distignore — reproducible wp.org distribution ZIP"* — facing the choice between (a) building the wp.org ZIP ad-hoc at release time via manual `zip -r` invocations vs. a committed reproducible script, (b) placing the exclude list inside the script vs. in a separate `.distignore` file, (c) using a temp-dir staging step (rsync → composer install → zip) vs. building in-place and excluding from `zip -x`, and (d) running ZIP verification in CI on every PR vs. only on release branches, I decided to ship a committed **`tools/build-zip.sh` + `.distignore`** pair where the script stages the tree to a temp dir, runs `composer install --no-dev --optimize-autoloader` in the staging dir, and zips the result, with a new **`Build ZIP + size check`** CI job that runs the script on every push to `main` and every PR — to achieve a deterministic, regression-tested release pipeline where the same script an operator runs locally is what CI runs in the cloud, accepting that the CI job adds ~30s per PR and that the `.distignore`/build-script split introduces a second place to keep exclusion patterns coherent.

## Context

- v0.1 release sequence requires a wp.org-shippable ZIP (issue #80, this AgDR). Without a script, every release is a manual ceremony of "remember which dirs to exclude, remember to run `composer install --no-dev`, remember the slug-vs-display-name distinction" — high mistake surface, especially as the codebase grows.
- The wp.org plugin directory enforces a 10 MB per-file ZIP ceiling. Plugins beyond it are rejected at submission time. The current ZIP is 208 KB, but `vendor/` growth or unexpected inclusion of `node_modules/` would blow past that.
- The plugin slug is `agentready` (one word) per [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md); the wp.org submission convention is that the ZIP's single top-level folder matches the slug. Encoding this in the script (parsed from `Text Domain:` in the plugin header) prevents drift if the slug ever changes.
- Existing `WP Plugin Check` CI job ([ci.yml#L128-L157](../../.github/workflows/ci.yml)) tests the working tree with no-dev `vendor/` against wp.org's Plugin Check sniffer — but it does **not** test the actual shippable ZIP shape. Different signal.

## Options Considered

### A. Build-script location + shape

| Option | Pros | Cons |
|--------|------|------|
| A1 — Ad-hoc `zip -r` at release time, exclusion list lives in operator's head | Zero infrastructure | Mistake-prone; non-reproducible; exclusion drift across releases; new maintainer can't tell what should ship. Rejected. |
| **A2 — `tools/build-zip.sh` with `.distignore`** (chosen) | Reproducible. Standard WP plugin convention (`wp dist-archive` reads `.distignore`). Operator + CI run the same code. | Two-file split (script + distignore); second exclude-list location to keep coherent if someone edits the script without touching distignore. |
| A3 — Build via `wp dist-archive` from the wp-cli plugin-check package | Even more standard. | Adds a `wp-cli` dependency to the release flow; one more tool to install on a fresh runner. Doesn't fit our v0.1 dev-machine setup ergonomically. |
| A4 — A Makefile target | Same reproducibility as A2. | macOS ships an ancient `make`; cross-platform Makefile syntax has gotchas. Bash is more portable for what's essentially a procedural script. |

### B. Staging strategy (where to assemble the ZIP)

| Option | Pros | Cons |
|--------|------|------|
| B1 — Build in-place, use `zip -x` with the exclusion list | One step. No temp dirs. | `zip -x` doesn't honour rsync-style nested patterns cleanly. Worse, doing `composer install --no-dev` in-place would replace the operator's working `vendor/` with a no-dev one — destructive side effect every time a release ZIP is built. Rejected. |
| **B2 — rsync to temp dir, install no-dev composer there, zip from temp** (chosen) | Working tree untouched. Production-only `vendor/` materialises in the temp dir only. Idempotent. | Two-step (rsync + composer + zip). Each step has its own failure mode the script needs to handle. |
| B3 — `git archive` to a tarball, untar, install composer, zip | The tarball cleanly takes the gitignore into account by default. | Requires a clean commit; loses any uncommitted changes (which is sometimes a feature, sometimes a footgun). Not idiomatic for a release that wants the latest committed state plus `npm run build` artefacts. |

### C. CI verification scope

| Option | Pros | Cons |
|--------|------|------|
| C1 — No CI verification; rely on operator running the script locally | Cheapest. | Drift between operator's machine and the CI runner remains invisible until a real submission. Rejected. |
| **C2 — `Build ZIP + size check` job, runs on every push + PR** (chosen) | Catches `.distignore` regressions, missing `build/` after a webpack refactor, dev-deps slipping into `vendor/`. Same ~30s budget as the existing PHPCS job. | One more CI cell; one more job to wait on for merge gate. |
| C3 — Run on release branches only | Cheaper per-PR. | The drift the job catches is the kind that lands silently and only surfaces at submission time. Worth checking on every PR even if it costs 30s. |

### D. `wp plugin-check` on the built ZIP in CI

| Option | Pros | Cons |
|--------|------|------|
| D1 — Run `wp plugin-check` against the built ZIP in CI | Strongest pre-submission signal. | Requires WP-CLI setup + the plugin-check package installed in CI. The existing `WP Plugin Check` CI job already runs Plugin Check against the working tree with comparable exclude lists. Marginal additional signal vs noticeable CI complexity. |
| **D2 — Defer to `--verify` flag on the local script, skip in CI for v0.1** (chosen) | Operator can run `bash tools/build-zip.sh --verify` before tagging the release. CI stays simple. | The CI gate is "ZIP shape is right"; the "ZIP passes Plugin Check" gate is the operator's responsibility. If the operator forgets, the wp.org reviewer catches it on submission day. |

## Decision

Chosen: **A2 + B2 + C2 + D2**.

`tools/build-zip.sh` reads `Text Domain` + `Version` from `agentready.php`, runs `npm run build`, rsyncs to a temp dir per `.distignore`, installs `--no-dev` composer deps in the temp dir, strips `composer.json` + `composer.lock` from the destination, zips into `dist/<slug>-<version>.zip`, and verifies size under wp.org's 10 MB ceiling. `--verify` flag extracts the ZIP and runs `wp plugin-check` (operator-local only; CI doesn't run this for v0.1).

`.distignore` lists exclusions in rsync-compatible glob form. `composer.json` + `composer.lock` are deliberately **absent** from `.distignore` — they're needed during the build's composer-install step and stripped from the destination by the build script after `vendor/` is populated. This is the load-bearing detail: putting them in `.distignore` would make the build silently install composer deps from a non-existent `composer.json` in the temp dir.

CI's `Build ZIP + size check` job runs the script on every push to `main` and every PR. It asserts (1) every banned `.distignore` path is **absent** from the ZIP, (2) every required runtime path is **present**, (3) size < 10 MB, and (4) uploads the built ZIP as a 7-day workflow artifact. Catches the regression class the existing `WP Plugin Check` job can't see: working-tree-passes vs ZIP-shape-correct.

## Consequences

- Every release runs the same `tools/build-zip.sh` whether on the operator's macOS dev machine or the Linux CI runner. Diverging behaviour between environments now requires the script itself to be non-deterministic — easier to spot than the previous "operator memory diverges from CI memory" failure mode.
- `.distignore` becomes the single source of truth for exclude patterns. The existing exclude lists in [ci.yml `WP Plugin Check`](../../.github/workflows/ci.yml#L156) (`exclude-directories` + `exclude-files`) **still exist** and are not synced from `.distignore` — they serve a different mechanism (Plugin Check action reads them directly). Future maintenance burden: both lists need to stay coherent. Mitigation: a follow-up could refactor Plugin Check to consume `.distignore` directly via a tiny shell loop, but that's not necessary for v0.1.
- The `composer install --no-dev` step in the build script runs every time, even when nothing in `composer.lock` has changed. ~3s per build. Tolerable given the script is invoked at release time, not on every dev iteration. Caching would optimise this but isn't worth the complexity for a quarterly-or-so release cadence.
- The 7-day workflow artifact uploaded by CI consumes GitHub's per-repo artifact storage. Cheap (~200 KB per build × N PRs per week). Bounded by retention.
- New maintainers reading the codebase can now answer "what ships to wp.org?" by reading `.distignore` and `tools/build-zip.sh` — no tribal knowledge required.

## Artifacts

- This AgDR: `docs/agdr/AgDR-0035-build-zip-script-and-ci-verification.md`
- `tools/build-zip.sh` (new)
- `.distignore` (new)
- `.github/workflows/ci.yml` — added `build-zip-verify` job

Related:

- [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) — slug `agentready` is locked, drives the ZIP's top-level folder name
- [AgDR-0007](AgDR-0007-phpstan-static-analysis.md) — CI layered job convention this AgDR extends
