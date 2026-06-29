---
id: AgDR-0009
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-29-plugin-check-warnings
trigger: ticket #29 (clear pre-existing WP Plugin Check warnings); recorded posture on translation loading
status: executed
---

# AgDR-0009 — Translations via wp.org auto-load (drop `load_plugin_textdomain()`)

> In the context of clearing the two pre-existing WP Plugin Check warnings on `main` ahead of v0.1 wp.org submission, facing the `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` rule plus the orphaned `plugin_header_nonexistent_domain_path` rule, I decided to remove the manual `load_plugin_textdomain()` call entirely and drop the `Domain Path` header line, relying on WordPress 4.6+'s auto-loading of translations for wp.org-hosted plugins under the plugin slug, to achieve a zero-warning Plugin Check pass at no functional cost, accepting that we cannot ship translations outside wp.org without re-introducing the call.

## Context

WP Plugin Check emits two warnings on every PR (and on `main`) since the scaffold landed in #2:

1. `plugin_header_nonexistent_domain_path` — `agentready.php` declares `Domain Path: /languages` but no `languages/` directory exists at the plugin root.
2. `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` at `includes/Main.php:109` — manual `load_plugin_textdomain()` calls are discouraged for wp.org-hosted plugins because WordPress 4.6+ auto-loads translations from `wp-content/languages/plugins/{slug}-{locale}.mo` under the plugin slug declared in the `Text Domain` header.

Mokhai's `Requires at least` is `7.0` (well above the 4.6 floor where auto-load shipped) and the plugin's distribution channel for v0.1 is wp.org. Per the wp.org plugin reviewer guidelines and the [WP 4.6 i18n improvements post](https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/), the manual loader is now redundant for the wp.org channel and is flagged precisely so submissions don't ship it.

wp.org auto-translates hosted plugins under the slug `agentready` (matches the `Text Domain:` header in `agentready.php`). Translations contributed via translate.wordpress.org are delivered to user sites by the language packs mechanism, no plugin-side code required.

This AgDR was promised in passing by AgDR-0001 ("each addition will get its own AgDR to keep the trade-off log honest") and is the i18n companion to AgDR-0004's broader CI / wp.org-readiness posture.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Remove `load_plugin_textdomain()` + drop `Domain Path` header** (this decision) | Clears both Plugin Check warnings; aligns with WordPress/ai's approach (the WP core team's own reference plugin); zero functional change for wp.org users; smaller plugin zip; one less moving part. | Cannot ship translations outside wp.org (e.g. via a private GitHub release) without re-introducing the call. Not a v0.1 concern. |
| B — Keep `load_plugin_textdomain()` and create an empty `languages/` directory with `.gitkeep` | Header stays accurate; directory exists for future bundled translations. | Doesn't clear the discouraged-function warning; still blocks wp.org submission. Empty directory ships in the zip for no functional benefit. |
| C — Lazy-load via `init` hook only when a non-default locale is detected | Reduces overhead on default-locale requests. | Doesn't clear the discouraged-function warning; adds complexity; wp.org auto-load already covers this case more efficiently. |
| D — Status quo (keep both, suppress warnings via `phpcs:ignore` / `// @phpcs:disable`) | Lowest churn. | Defeats the purpose of running Plugin Check; suppressions accumulate; wp.org reviewer would flag manually. |

## Decision

Chosen: **Option A — remove the manual loader and the `Domain Path` header.** Same posture as the WordPress core team's [WordPress/ai](https://github.com/WordPress/ai) reference plugin. Mokhai is wp.org-first for v0.1; the auto-load mechanism is sufficient and is the wp.org reviewer's expectation.

Concrete changes encoded in this PR:

- `agentready.php` — drop the `* Domain Path:       /languages` line from the plugin header block. `Text Domain:       agentready` stays (wp.org reads it from there for the auto-load slug).
- `includes/Main.php` — remove the `load_textdomain()` method, the `add_action( 'plugins_loaded', ... )` registration that called it, and the inline comment in `register_hooks()` referencing the i18n loader.
- No `languages/` directory created — wp.org delivers language packs through `wp-content/languages/plugins/agentready-{locale}.mo` at the WP-install layer, not from the plugin zip.

## Consequences

- WP Plugin Check passes with 0 warnings / 0 errors for both of the rules ticket #29 enumerates.
- For wp.org-hosted installs (the only v0.1 distribution channel), translations are delivered identically to before — the user-facing behaviour does not change.
- **If we later add a non-wp.org distribution channel** (private GitHub release, GitHub-direct install, vendored in a managed-hosting bundle), we must re-introduce the loader. Revisit then with a follow-up AgDR; we are not designing for that case in v0.1.
- The `Text Domain: agentready` header in `agentready.php` is now the single source of truth for the textdomain string — keep it locked unless renaming the plugin slug on wp.org (which is itself a wp.org-policy-heavy move).
- One less hook on `plugins_loaded`. Negligible perf gain, but a marginal simplification of the bootstrap chain.

## Artifacts

- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/29
- PR: (linked here on creation)
- AgDR: this file
- Reference: https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/
- Reference implementation: https://github.com/WordPress/ai (the WP core team's own AI plugin — same no-manual-loader posture)
