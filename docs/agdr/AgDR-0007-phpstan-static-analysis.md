---
id: AgDR-0007
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-13-phpstan-static-analysis
trigger: ticket #13 (PHPStan static analysis at level 5); promised by AgDR-0001 ("each gets its own AgDR") and AgDR-0004 ("PHPStan — separate ticket #13 with its own AgDR")
status: executed
---

# AgDR-0007 — PHPStan static analysis at level 5 with szepeviktor/phpstan-wordpress

> In the context of hardening the type floor on the Mokhai scaffold (#1) and the AI Client Wrapper (#2/#6) before product tickets (#4–#11) pile on top, facing the choice of either staying with PHPCS alone or layering PHPStan on top to catch the class of bugs that coding-standard linters cannot see, I decided to add PHPStan at level 5 with `szepeviktor/phpstan-wordpress` as a dedicated CI job — mirroring the WordPress/ai plugin's shape but starting at level 5 (instead of WordPress/ai's level 8) — to achieve a credible static-analysis floor that catches null derefs / wrong-arg-types / unreachable code without drowning a small-team plugin in WP-core-typing churn, accepting that we will iterate the level upward in follow-up tickets as the codebase grows.

## Context

AgDR-0001 explicitly deferred PHPStan to its own AgDR:

> "CI scope in v0.1: PHP syntax check only. Static analysis (PHPStan), coding standards (WPCS), unit tests, integration tests, and the WP Plugin Check Tool will be added as they become relevant. Each addition will get its own AgDR to keep the trade-off log honest."

AgDR-0004 (layered CI) added PHPCS / Plugin Check / PHPUnit but explicitly left PHPStan out:

> "PHPStan — separate ticket (#13) with its own AgDR. Static analysis is a different concern from coding standards."

Ticket #13 is that point. The scaffold is small (six PHP files under `includes/`, two unit tests under `tests/Unit/`), so the type floor we set here is the contract every future ticket will inherit. Setting it too loose (level 0–2) lets null-deref bugs through; setting it too tight (level 8, WordPress/ai's choice) makes every product ticket fight WP-core's loose return-type stubs before it can land its actual feature.

PHPCS catches stylistic / convention issues. PHPStan catches type-level bugs: undefined methods, wrong argument types, dead code, never-returned values, nullability mistakes. They are complementary, not overlapping.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — PHPStan level 5 + szepeviktor/phpstan-wordpress** (chosen) | Catches the "real bug" class (null deref, wrong args, dead code, never-return); WP type stubs via the szepeviktor extension; matches the ticket's stated level; small enough scaffold that level 5 is realistic with an empty baseline. | Less strict than WordPress/ai (level 8); we will need to bump the level deliberately in follow-up tickets as the codebase matures. |
| B — PHPStan level 8 (mirror WordPress/ai exactly) | Maximum credibility; matches the upstream reference plugin's posture exactly. | WordPress/ai's level-8 config carries 6 `ignoreErrors` regex patterns for WP-AI-Client stub gaps — they live with that friction because they ship the core AI plugin. For a small-team plugin, level 8 on day one means baseline-juggling on every PR until WordPress/ai's own type stubs stabilise. We can climb to 8 later when the type floor is paid for. |
| C — PHPStan level 0–2 only | Trivial to keep green; zero baseline entries; almost no PR friction. | Catches almost no real bugs — level 0 only catches obvious syntax-adjacent things. Wastes a CI cell. |
| D — Psalm instead of PHPStan | Stricter type inference; better generics; arguably the gold standard for PHP type analysis. | WordPress/ai uses PHPStan, the WP ecosystem's lingua franca is PHPStan + szepeviktor, and `szepeviktor/phpstan-wordpress` is the canonical WP type-stub extension. Switching to Psalm would mean reinventing the WP-core type-stub layer ourselves. |
| E — Skip static analysis entirely (PHPCS only) | One less CI job; no baseline file to maintain. | The whole point of #13 is the bug-class PHPCS does not see. Skipping defeats the ticket. |

## Decision

Chosen: **Option A — PHPStan level 5 + szepeviktor/phpstan-wordpress as a dedicated CI job.**

### Concrete choices encoded in this PR

| Element | Choice | Why |
|---------|--------|-----|
| **PHPStan version** | `phpstan/phpstan:^2.1` (resolved to 2.1.54) | PHPStan 2.x supports PHP 7.4+ and is the current major. Matches ticket spec. |
| **WordPress extension** | `szepeviktor/phpstan-wordpress:^2.0` (resolved to 2.0.3) | The ticket asked for `^2.1` but no stable `2.1.x` exists yet on Packagist — the latest stable is `v2.0.3`, and `2.x-dev` is the only `2.1`-prefixed tag. Pinned to `^2.0` to stay on a stable release. Will revisit when 2.1.x ships. |
| **Level** | `5` | Catches real bugs (null deref, wrong types, missing returns) without WP-core stub churn. Climb to 6/7/8 in follow-up tickets as the codebase matures. |
| **Scan paths** | `includes/`, `tests/Unit/` | Production code + the only test suite that doesn't load WP. Integration tests are skipped — they load wp-phpunit which has its own type-stub gymnastics; analysing them under PHPStan would produce noise without catching real bugs. |
| **Exclude paths** | `vendor/`, `node_modules/`, `tests/Integration/`, `tests/Fixtures/` (if it exists) | Standard exclusions. Integration tests excluded per above. |
| **Baseline strategy** | Empty baseline (or smallest possible). Each entry, if any, gets a one-line inline comment naming the WP-core stub gap. Code-level issues get fixed in code, never baselined. | Baseline is a debt log. Treating it as the dumping ground for "stuff PHPStan didn't like" turns a useful tool into noise. Each entry must be defensible. |
| **CI integration** | New `phpstan` job in `.github/workflows/ci.yml`, runs in parallel with `phpcs` / `phpunit` jobs, PHP 7.4 (matches the floor) | Parallel = no extra wall-clock CI time. PHP 7.4 = scan reflects the floor the code must support. |
| **Caching** | `actions/cache@v4` keyed on `composer.lock` + the existing `CACHE_DATE` weekly stamp | Reuses the cache key shape from `phpcs` / `phpunit-unit` jobs (AgDR-0004). Warm cache → composer install ~5s → phpstan ~10s → < 60s total per AC #3. |
| **Composer script** | `composer phpstan` → `phpstan analyse --memory-limit=512M` | Local-loop parity with CI. The 512M memory limit is PHPStan's recommended default; the scaffold is small enough that 256M would do today, but 512M leaves headroom as the codebase grows. |
| **Branch protection** | Adopter-action item — flagged in the PR body for the CEO to action via GitHub repo settings. The framework can't change branch protection from a PR. | Matches the pattern from AgDR-0004 (the PHPCS / Plugin Check / PHPUnit checks are similarly action-by-CEO post-PR). |

### What this AgDR explicitly does NOT decide

- **The level we eventually settle on.** Level 5 is the starting floor. A follow-up ticket will evaluate climbing to 6/7/8 once we have data on how often baseline entries accumulate.
- **PHPStan-pro / strict-rules extensions** (`phpstan/phpstan-strict-rules`, `phpstan/phpstan-deprecation-rules`). These add value but are out of v0.1 scope; revisit alongside the level bump.
- **Integration-test analysis.** Scanning `tests/Integration/` under PHPStan would require wp-phpunit type stubs and a bootstrap dance that's not worth the cost for v0.1.
- **PHPStan-baseline auto-regeneration in CI.** The baseline is hand-edited; a regenerate-and-PR-it bot is out of scope.

## Consequences

- Every PR gets a PHPStan check inline with PHPCS and PHPUnit. Reviewers see type-level bug classes at the offending line.
- The empty baseline means any new code that doesn't pass level 5 must be fixed in code, not papered over. Mitigation if this becomes painful: drop to level 4 (the ticket allows the smallest possible baseline) OR add the offending sniff to baseline with a defensible comment.
- We deliberately set the floor below WordPress/ai's level 8 — every future "should we bump the level?" debate gets compared against "is the codebase stable enough that the bump is paid-for?".
- The `szepeviktor/phpstan-wordpress` extension provides WP-core type stubs (WP_Query, WP_Post, hook signatures). When WP-AI-Client stubs land in szepeviktor's package (they do not yet), the WP-AI-Client wrapper code (#2) gets fully-typed support for free.
- CI cost: one additional ~30s job on warm cache. Minimal.

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/13
- AgDR: this file
- PR: (linked here on creation)
- Reference implementation we adapted (different level + scope): https://github.com/WordPress/ai/blob/trunk/phpstan.neon.dist

---

## Amendment — 2026-05-13 (issue #33)

Two days after this AgDR landed, PR #27 (Context Profile admin) merged on top of #26 (this AgDR's PR). The PHPStan job on `main` immediately went red with **two** errors that exposed two implicit assumptions in the original decision. Both are policy-level observations worth folding back here rather than spinning a fresh AgDR — the conclusions reinforce the original posture rather than reverse it.

### Observation 1 — the baseline can go stale from *good* refactors

The original baseline (in PR #26) held a single regex covering bare-line `add_action()` / `add_filter()` / `register_*_hook()` calls. PR #27 introduced `Main::register_hooks()` / `Context_Profile_Page::register_hooks()` patterns that move those calls inside method bodies — the rule no longer fires for any path in `includes/`. PHPStan 2.x's default `reportUnmatchedIgnoredErrors: true` then flagged the baseline entry itself as drift.

This is **working as designed**, not a regression. The "code fixes preferred over baseline" policy this AgDR established means baseline entries are debt that the codebase is expected to pay down over time. PHPStan's enforcement is what surfaces the moment that debt is paid. Keeping `reportUnmatchedIgnoredErrors: true` (the original choice) is the correct posture — flipping it to `false` would let stale entries accumulate silently.

The mitigation, codified in #33, is to **delete the baseline file outright** when the last entry stops firing, AND remove the `phpstan-baseline.neon` line from `phpstan.neon.dist`'s `includes:` block. If a future ticket reintroduces a third-party stub gap, the baseline gets re-added with the one-line-comment policy already established in this AgDR.

### Observation 2 — `require` paths that resolve at runtime need an indirection seam at analysis time

PR #27 introduced `require WPCTX_DIR . 'build/admin/context-profile.asset.php'` for the `@wordpress/scripts` build artefact. The `build/` directory is gitignored (output of `npm run build`). The PHPStan CI job runs `composer install` + `composer phpstan` only — no Node, no build. PHPStan resolves `WPCTX_DIR` from the bootstrap file as a known constant string, follows the require path, finds the file missing on the analysis cell, and raises `require.fileNotFound`.

The original AgDR didn't anticipate runtime-only artefacts in the analysed paths. The options were:

| Option | Trade-off |
|--------|-----------|
| **A — Add Node + `npm run build` to the PHPStan CI job** | Rejected. Doubles job runtime (~30s npm install + ~10s build), adds Node setup to a PHP-only job, and couples analysis to a build step it logically doesn't need. |
| **B — Defensive require + indirection seam (chosen)** | Wrap the require in an `is_readable()` ternary with a safe-default fallback array (matches Gutenberg / WP core convention for graceful first-boot degradation), AND route the path through a trivial identity function (`Context_Profile_Page::asset_path()`) so PHPStan cannot statically resolve the require target. PHPStan analyses the fallback array shape, never tries to follow the require, exits clean. |
| C — `@phpstan-ignore-next-line` annotation on the require | Rejected — silences a real concern instead of structuring around it. Cheapest, worst signal. |
| D — Commit a stub `*.asset.php` placeholder under `build/` | Rejected — pollutes the gitignored build/ directory with a tracked stub, and the stub's shape can silently diverge from the real `@wordpress/scripts` output. |

Option B is now the convention for **any** runtime-only artefact required from PHP in this codebase. The defensive shape — `file_exists` short-circuit early, `is_readable + ?:` for the require, identity-function indirection on the path — has three benefits:

1. **Runtime resilience.** A missing build artefact degrades to an empty `wp_enqueue_script` call with neutral defaults, instead of fatal-erroring.
2. **Analysis-time soundness.** Both branches of the ternary resolve to a `[ 'dependencies' => array, 'version' => string ]` shape that PHPStan can reason about for downstream calls.
3. **CI decoupling.** The PHPStan job stays Node-free, keeping the original "parallel ~30s job" posture from this AgDR's Consequences section.

### What this amendment does NOT change

- **The level (still 5).** The two errors were structural, not type-level.
- **`reportUnmatchedIgnoredErrors: true`** stays the default. Catching baseline drift is the feature, not the bug.
- **The baseline policy** (one-line comments per entry, code fixes preferred). Reinforced — the deletion-when-empty rule is just the natural endpoint of that policy.
- **The "no `@phpstan-ignore`" rule.** Rejected at the time, rejected again here.
