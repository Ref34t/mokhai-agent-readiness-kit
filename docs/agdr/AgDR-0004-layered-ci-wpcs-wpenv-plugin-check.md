---
id: AgDR-0004
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-3-ci-matrix
trigger: ticket #3 (CI: PHPCS + Plugin Check + PHPUnit matrix); promised by AgDR-0001 ("each gets its own AgDR")
status: executed
---

# AgDR-0004 — Layered CI: WPCS / wp-env PHPUnit / Plugin Check, adopted from WordPress/ai

> In the context of adding code-style enforcement (WPCS), an integration-test harness (PHPUnit), and the wp.org submission gate (Plugin Check Tool) to a CI pipeline that already runs PHP syntax across a PHP-version matrix, facing the choice of either hand-rolling our own conventions or mirroring an existing battle-tested plugin's CI, I decided to adopt the exact CI shape used by the WordPress core team's [WordPress/ai](https://github.com/WordPress/ai) plugin — layered PHPCS rulesets (VIP-Go → Core → Extra → PHPCompatibilityWP → Slevomat), `@wordpress/env` for the PHPUnit harness, pinned-SHA `wordpress/plugin-check-action`, and `cs2pr` for inline PR annotations — to achieve credibility ("core-team-grade CI") plus zero invention cost, accepting that we inherit some of WordPress/ai's strictness which may slow some PRs while contributors learn the rules.

## Context

AgDR-0001 closed the "do we use GitHub Actions" question and chose a PHP-syntax-only matrix for v0.1 launch. It explicitly deferred PHPCS / PHPUnit / Plugin Check to their own AgDRs:

> "CI scope in v0.1: PHP syntax check only. Static analysis (PHPStan), coding standards (WPCS), unit tests, integration tests, and the WP Plugin Check Tool will be added as they become relevant. Each addition will get its own AgDR to keep the trade-off log honest."

Ticket #3 is that point — wp.org submission (PRD timeline 2026-07-03) needs the Plugin Check Tool passing cleanly; PR-time PHPCS + PHPUnit prevents an end-of-cycle compliance scramble.

The PRD itself is silent on which conventions to adopt. It only says:
- "Must pass the wp.org Plugin Check Tool before submission (security, performance, GPL compatibility)" (NFR)
- "Translation-ready" (NFR)
- "WordPress 7.0+ / PHP 7.4+" (NFR)

So the call on *which* coding standard, *which* test harness, and *which* PHPCS layering is a Tech-Design-level architectural decision that needs recording.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Mirror WordPress/ai** (layered PHPCS: VIP-Go→Core→Extra→PHPCompatibilityWP→Slevomat; `@wordpress/env`; pinned Plugin Check action; `cs2pr` annotations) | Battle-tested by the WP core team; future contributors recognize the shape; signals "core-team-grade" to wp.org reviewers and prospective users; zero invention cost. | Inherits WordPress/ai's strictness — VIP-Go-first means a lot of opt-outs may be needed for a smaller-team plugin; Slevomat adds strict-type / namespace nags that aren't strictly required by WPCS. |
| B — WordPress Coding Standards only (`WordPress` ruleset, no VIP-Go, no Slevomat) | Lower barrier for contributors; matches what most wp.org plugins do; less PR friction. | Doesn't match the credibility positioning ("Agent Readiness for WordPress" — adopting strict conventions signals seriousness); we'd reinvent the layering decisions we deliberately want to import. |
| C — Yoast-style ruleset (`yoast/yoastcs`) | Yoast is a well-known WP-ecosystem standard-setter | Distinct conventions; harder to defend "why Yoast rules?" if a wp.org reviewer asks; we're not a Yoast team. |
| D — `@wordpress/scripts` test harness instead of `@wordpress/env` | Single dependency; thin layer; well-trodden for block / theme projects. | Optimized for JS-first / block-editor projects, not PHP-heavy plugins; PHPUnit support is bolted-on; `@wordpress/env` is the canonical PHP harness. |
| E — Hand-rolled WP install for PHPUnit (download core, configure DB, etc.) | Maximum control; no Docker dependency at CI time. | Significant maintenance burden; brittle across WP versions; matrix-cell complexity explodes; the WP core team itself moved off this years ago. |

## Decision

Chosen: **Option A — Mirror WordPress/ai's CI shape wholesale.** The credibility positioning of WP Context is "core-team-grade reference plugin for the WP-AI space" — adopting the conventions of the WP core team's own AI plugin reinforces that positioning at zero invention cost. Specific concrete choices below.

### Concrete choices encoded in this PR

| Element | Choice | Why |
|---------|--------|-----|
| **PHPCS ruleset order** | `WordPress-VIP-Go` → `WordPress-Core` → `WordPress-Extra` → `PHPCompatibilityWP` → `slevomat/coding-standard` | Strictest first; later layers add ON TOP. Excluding individual sniffs from a strict-first layout produces a smaller, more readable phpcs.xml.dist than the alternative ("add strict sniffs to a permissive base"). Same pattern as [WordPress/ai/phpcs.xml.dist](https://github.com/WordPress/ai/blob/trunk/phpcs.xml.dist). |
| **Prefix lock** | `WPCTX` (constants/classes), `wpctx` (functions/hooks), `WPContext` (namespace), `mokhai-agent-readiness-kit` (text domain) | Already established in #1 + #2; PHPCS locks it via `WordPress.NamingConventions.PrefixAllGlobals`. |
| **PHPUnit harness** | `@wordpress/env` v0.10+ (Docker-based) + `wp-phpunit/wp-phpunit:^6.9` + `phpunit/phpunit:^8.5\|^9.6` | Canonical WP test harness; matches WordPress/ai; avoids hand-rolled WP install fragility. |
| **CI matrix shape** | PHP `[7.4, 8.1]` × WP `[7.0, trunk]` = **4 cells** | Smaller than the syntax-check matrix (PHP 7.4-8.3) on purpose — PHPUnit is the slow leg; running 4 cells (PHP floor + a modern PHP × WP floor + WP trunk) catches the realistic regression surface without burning 10 cells of CI minutes. Mirrors WordPress/ai. |
| **Plugin Check action** | `wordpress/plugin-check-action@v1.1.5` with `wp-version: 'trunk'` | Pinned SHA / tag for supply-chain safety; trunk to catch wp.org-review regressions early. Mirrors WordPress/ai. |
| **PR annotations** | `cs2pr` step on PHPCS failures | Inline PR comments on offending lines — significantly faster review cycles than "scroll through job logs". Standard WordPress/ai pattern. |
| **Caching** | Composer + npm caches keyed on weekly date stamp | Matches WordPress/ai pattern; balances cache hit-rate against staleness; rebuilds weekly. |
| **Branch protection** | Required: PHPCS, Plugin Check, PHPUnit (all 4 matrix cells) | Mechanical enforcement of the launch gate. Configured separately via `gh api` after this PR's CI is observed green — not encoded in workflow files (it's a repo setting). |

### What this AgDR explicitly does NOT decide

- **PHPStan** — separate ticket (#13) with its own AgDR. Static analysis is a different concern from coding standards.
- **Code coverage reporting / coverage thresholds** — out of v0.1 scope; revisit when the project has enough tests for coverage to be meaningful.
- **E2E / Playwright / Cypress UI tests** — out of v0.1 scope; the admin UI is too thin for E2E to add value yet.
- **Security scanning (Semgrep, npm audit, etc.)** — separate concern; will be added when the dependency surface justifies it (likely after v0.1.1 features bring in more deps).
- **Performance benchmarks** — out of v0.1 scope.

## Consequences

- Every PR gets PHPCS feedback inline via `cs2pr` — reviewers see issues at the offending line, not in a CI log.
- The full layered ruleset on the scaffold (#1) and the #2 wrapper code must pass clean. Where the inherited strictness conflicts with a legitimate v0.1 pattern, the resolution is **either** code change OR a narrowly-scoped exclusion in `phpcs.xml.dist` with an inline comment naming the AgDR and the rationale.
- `@wordpress/env` introduces a Docker dependency for local PHPUnit. Contributors who don't run PHPUnit locally don't pay this cost; those who do need Docker Desktop / Colima / OrbStack on macOS. Same trade-off WordPress/ai accepts.
- The 4-cell PHPUnit matrix runs in ~4-7 minutes per cell (cold) / ~2-4 minutes (warm cache). Total CI wall-time target < 5 minutes warm per AC #7.
- PR friction: contributors will hit Slevomat strict-type nags, VIP-Go sniff opt-outs, and PHPCompatibilityWP version-specific deprecations until they internalize the rules. Mitigation: keep the `phpcs.xml.dist` rationale comments rich so PHPCS failures explain themselves.
- We are deliberately not adopting Yoast's, Automattic's standalone, or any other ruleset — every future "should we add X linter?" debate gets compared against "is X used by WordPress/ai?".

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/3
- AgDR: this file
- PR: (linked here on creation)
- Reference implementation we adopted: https://github.com/WordPress/ai/tree/trunk/.github/workflows + https://github.com/WordPress/ai/blob/trunk/phpcs.xml.dist
