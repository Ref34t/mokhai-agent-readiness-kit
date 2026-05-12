# AgDR-0001 — CI on GitHub Actions with PHP version matrix

> In the context of a brand-new WordPress plugin repo on GitHub, facing the need for automated PHP syntax checks across the supported runtime range from day 1, I decided to use GitHub Actions with a PHP 7.4–8.3 matrix to achieve zero-friction CI on the platform the code already lives, accepting tight coupling to GitHub as the host.

## Context

WP Context targets WordPress 7.0+ (PHP 7.4+ minimum per WP requirement). The plugin will be published to wp.org, which itself runs against many PHP versions in the wild. We need a CI pipeline that:

1. Catches PHP syntax errors before they ship.
2. Tests across the supported PHP version range.
3. Has zero infrastructure overhead — solo founder, no SRE.
4. Costs nothing for a private repo (current state).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| GitHub Actions | Native to the repo host; free for private repos under the included monthly minutes; zero extra accounts; shivammathur/setup-php is a well-maintained action | Tight coupling to GitHub; if we migrate hosts, full rewrite |
| CircleCI | Powerful matrix support; container caching | Extra account + token management; overkill for `php -l` |
| Self-hosted runner / Jenkins | Maximum control | Infrastructure overhead; security exposure; solo founder can't justify |
| No CI in v0.1, manual local checks | Zero setup | Inevitable regression; no enforcement; wp.org Plugin Check Tool can't run on every commit |

## Decision

Chosen: **GitHub Actions**, because the repo already lives on GitHub, the friction is zero, and the marginal value of any other option doesn't justify the setup cost at solo-founder scale.

PHP version matrix: **7.4, 8.0, 8.1, 8.2, 8.3** — covers everything from the WP 7.0 floor up to the current widely-deployed range. PHP 8.4 will be added when its first stable point release drops.

CI scope in v0.1: **PHP syntax check only**. Static analysis (PHPStan), coding standards (WPCS), unit tests, integration tests, and the WP Plugin Check Tool will be added as they become relevant. Each addition will get its own AgDR to keep the trade-off log honest.

## Consequences

- Every push and PR on `main` triggers the matrix; failures block merge once branch protection is enabled.
- We are tied to GitHub Actions. Switching CI providers later means rewriting `.github/workflows/`.
- We pay nothing today (free tier covers a private repo of this size).
- The matrix runs `php -l` on every `.php` file outside `vendor/` and `node_modules/`. Cheap and fast.
- When PHPStan / WPCS / PHPUnit join the pipeline, each gets its own AgDR.

## Artifacts

- `.github/workflows/ci.yml`
- This AgDR
