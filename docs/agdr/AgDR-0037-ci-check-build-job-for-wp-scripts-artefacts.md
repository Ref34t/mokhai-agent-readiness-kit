# AgDR-0037 — CI check-build job for wp-scripts artefacts

> In the context of agent-ready's React admin UIs being emitted by webpack to a gitignored `build/admin/` directory and shipping through the wp.org distribution ZIP, facing a class of "missing/stale artefact" failures that only surface at submission time or as user-reported "the sidebar doesn't render", I decided to add a CI gate that asserts every `src/admin/<name>/index.js` entry point produced its matching `.js` + `.asset.php` artefact, non-empty — derived dynamically from the source tree rather than hardcoded — to make the existing `Sidebar_Assets` fail-silent comment actually true, accepting one additional CI job (~30s) and the operational task of adding it to branch protection's `required_status_checks` once observed to be stable.

## Context

The bundled React admin UIs are emitted by `wp-scripts build` from `src/admin/<name>/index.js` to `build/admin/<name>.{js,asset.php}` (5 entry points today: `context-profile`, `context-score`, `llms-txt-descriptions`, `llms-txt-editorial`, `markdown-views-sidebar`). The artefacts are gitignored — they're build output, regenerated at every release.

`includes/Markdown_Views/Sidebar_Assets.php:56-62` documents a fail-silent enqueue: if the `.asset.php` manifest is missing, skip the script registration instead of throwing a PHP fatal. The inline comment promises *"CI's check-build job catches missing artefacts before merge"* — but that job did not exist. The comment was aspirational.

The existing `build-zip-verify` job (AgDR-0035) does exercise `npm run build` indirectly via `tools/build-zip.sh`, and its "required paths present" step asserts `agent-ready/build/admin/` is present in the ZIP at the **directory level**. That catches the catastrophic case (whole directory missing) but not the per-artefact case (one bundle silently dropped, leaving the ZIP shape correct but the file count wrong).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Hardcoded artefact list** (10 explicit paths in the CI step) | Trivially explicit; one line to read | List drifts the moment a new bundle is added; one of the failure modes this gate is supposed to prevent is "new bundle silently absent" — the same forgetfulness that loses an artefact also loses the test |
| **B — Dynamic discovery from `src/admin/*/index.js`** (chosen) | Self-maintaining; mirrors the webpack config's auto-discovery shape; new bundles automatically gated; the discovery code is 3 lines of bash | One layer of indirection; reader needs to know the discovery pattern matches webpack's |
| **C — Diff committed `build/` snapshot against fresh build** | Catches "the source compiled to different bytes" (e.g. someone forgot to rebuild before tagging) | Requires committing build artefacts to a release branch (which the AgDR-0034 / .gitignore: `build/` decision specifically excludes); huge churn cost; reproducibility-of-webpack-output isn't actually guaranteed (timestamps, hash collisions) |
| **D — Defer to the existing `build-zip-verify` job's "required paths" step** | Zero new jobs | Only catches directory-level absence, not the per-artefact case the comment promises |

Discovery convention itself was decided at the webpack-config level: `webpack.config.js` reads `src/admin/*/index.js` and treats each as an independent bundle. Option B keeps the CI gate's expectations aligned with the build's own definition of "what exists." A new admin module dropped under `src/admin/` gets a webpack bundle *and* a CI assertion in one place; neither layer has to be remembered.

## Decision

Chosen: **Option B — dynamic discovery**, because the maintenance failure-mode that hardcoding invites is the same forgetfulness this gate is meant to catch, and the discovery code is small enough that the indirection cost is negligible.

The job is **placed between `phpunit-integration` (job 6) and `build-zip-verify` (job 8)** so it gates the JS build layer before the ZIP packaging exercises both. The two are complementary: `check-build` asserts per-artefact correctness; `build-zip-verify` exercises the packaging + distribution shape.

### Rollout pattern — land first, require later

The job is added to the CI workflow but **not** added to `main`'s branch protection `required_status_checks` list in this PR. Rationale:

- Adding a job to branch protection is a settings change; the first CI run is the right moment to observe the runtime behaviour (cache warm-up time, edge cases) before declaring it "required."
- The list currently has 14 contexts. Once `Check build (wp-scripts artefacts)` runs green once on `main`, the project owner adds it as the 15th. A one-line `gh api` PATCH on the protection rule.
- Until that happens, the job runs on every PR but doesn't block merge — the operator sees the result and can pull the trigger when ready.

This matches the same shape used for `build-zip-verify` (AgDR-0035) — landed in #82, observed running green, then added to protection.

## Consequences

- The `Sidebar_Assets.php:59` comment becomes accurate. Issue #41 closes as a side-effect of this PR (no code change needed in `Sidebar_Assets.php`).
- Future admin modules under `src/admin/<name>/` are gated automatically — operator does not need to remember to update the CI job.
- One extra ~30s CI run per PR (matches the existing `build-zip-verify` cost; the `npm ci` + `npm run build` are warm-cached after the first run via `actions/setup-node@v4`'s `cache: 'npm'`).
- Branch-protection update is deferred — the gate is observable but not blocking until the operator opts in.

## Artifacts

- PR #92 (this PR; supersedes the `Sidebar_Assets.php` aspirational comment)
- Closes #43, closes #41 (side-effect)
- Related: AgDR-0001 (CI on GitHub Actions), AgDR-0035 (build-zip-verify + AgDR for CI verification pattern), AgDR-0034 (gitignore `build/` decision)
