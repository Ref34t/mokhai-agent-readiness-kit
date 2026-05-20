# AgDR-0036 — wp.org slug rename `agentready` → `agent-ready`

> In the context of `Ref34t/agentready#88` — *"wp.org slug rename, release blocker"* — facing the choice between (a) reverting the Plugin Name display rename from "Agent Ready" back to "AgentReady" so the auto-derived slug stays `agentready` and the existing Text Domain matches, vs. (b) refactoring the codebase to slug `agent-ready` (with hyphen) so the Plugin Name and slug both carry the space-then-hyphen shape wp.org expects, vs. (c) submitting with the textdomain_mismatch warning unfixed and gambling on the human reviewer accepting it, I decided to **refactor everything to slug `agent-ready`** — keeping the CEO-chosen "Agent Ready" Plugin Name unchanged and aligning the Text Domain, plugin file name, REST namespace, WP-CLI command base, and ZIP top-level folder with wp.org's standard slug-derivation convention — to achieve a clean wp.org submission with no automated-scanner warnings, accepting a substantial mechanical diff (~250 string-literal updates across PHP + JS + CI workflow + wp-env config) and the supersession of [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md)'s "Text Domain locked to `agentready`" decision.

## Context

- v0.1.0 was tagged and a GH release was cut. The first ZIP upload to wp.org's submission form (`wordpress.org/plugins/developers/add/`) returned an automated-scan failure:

```
readme.txt    ERROR: invalid_tested_upto_minor — Tested up to: 6.9.4, should be 6.9
agentready.php WARN: textdomain_mismatch — Text Domain "agentready" must match expected slug "agent-ready"
composer.json  WARN: missing_composer_json_file — /vendor present but composer.json missing
```

- The textdomain warning is the consequential one. wp.org's automated scanner derives the canonical slug from the Plugin Name header by lowercasing and replacing whitespace with hyphens. Plugin Name "Agent Ready" produces canonical slug "agent-ready". The Text Domain header MUST match this canonical slug.
- The CEO's earlier decision in this session ("make the frontend name of plugin Agent Ready not Agentready" — see [PR #81](https://github.com/Ref34t/agentready/pull/81)) established "Agent Ready" with a space as the display brand. That decision was made before wp.org's slug-derivation rule was surfaced. Reversing the Plugin Name to "AgentReady" would cleanly satisfy the scanner but undo a brand choice the CEO made deliberately.
- [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) (2026-05-13) locked the Text Domain to `agentready` — but that AgDR predates the Plugin Name display rename. The original Text Domain choice was internally consistent at the time (Plugin Name was "AgentReady" then, so `agentready` matched both display and auto-slug). The space-in-display change broke that consistency; this AgDR is the resolution.

## Options Considered

### A. Revert Plugin Name to `AgentReady` (one word)

| Pros | Cons |
|------|------|
| Smallest diff (one-line header change + readme heading). All internal identifiers stay. AgDR-0009 stays valid. | Reverses today's CEO-chosen display brand. wp.org listing displays "AgentReady" (no space), marketing copy can still say "Agent Ready" but the wp.org-native surface (search, plugin page header, install confirmation) reads as the one-word form. |

### B. Refactor to slug `agent-ready` (chosen)

| Pros | Cons |
|------|------|
| Preserves the CEO-chosen "Agent Ready" display. wp.org listing reads as "Agent Ready" both in display AND in URL (`/plugins/agent-ready/`). Consistent surface presentation. Removes any future ambiguity about slug-vs-display. | Substantial mechanical diff: 121 PHP gettext calls + 126 JS gettext calls + 4 REST namespaces + 4 WP-CLI commands + plugin file rename + CI workflow updates. Supersedes AgDR-0009 (worth flagging — historical AgDRs don't get retconned, but a successor AgDR can mark scope-supersession). |

### C. Submit with the textdomain warning unfixed

| Pros | Cons |
|------|------|
| Zero work. | Unpredictable. wp.org's manual reviewer has discretion; if they enforce slug=Plugin-Name-slugified strictly, the submission gets rejected after the multi-day review wait — wasting 1–2 weeks. Risk of compounding rejections if other things surface. |

## Decision

Chosen: **B**.

Rationale — three reasons in priority order:

1. **Preserve the CEO's display decision.** "Agent Ready" (with space) was chosen consciously this session. Reversing it as a workaround for a wp.org rule the team didn't know about feels like the wp.org rule winning over the brand choice. Path B keeps the display intact and aligns the slug to match.

2. **One-time cost, indefinite payoff.** The refactor is ~250 string updates done atomically in a single PR. After that, every future feature touches the new slug naturally — `__('foo', 'agent-ready')`, `WP_CLI::add_command('agent-ready ...')`, `agent-ready/v1`. Path A would also need that consistency surface but in the opposite direction (every future feature touches `'agentready'`).

3. **wp.org URL discoverability.** The public URL `/plugins/agent-ready/` is the discovery surface. It will be linked from blog posts, the marketing site, social shares, etc. A hyphenated slug that matches the display name is the convention readers expect; a one-word slug for a two-word brand is the convention they don't.

The supersession of [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) is bounded: AgDR-0009's auto-loading translation policy still applies (wp.org-managed translation files; no manual `load_plugin_textdomain` call), the only change is the Text Domain VALUE the auto-loader keys on (`agent-ready` instead of `agentready`).

## Out of scope (does NOT change)

- PHP namespace `WPContext\`. Language-level identifier, not wp.org-visible. No reason to rename.
- `WPCTX_*` constants. Same.
- Option keys (`agentready_context_profile`, `agentready_version`, etc.). wp.org doesn't enforce option-key naming to match slug; renaming them would force a destructive data migration on existing installs. Since v0.1.0 has not shipped to wp.org yet, there are no existing installs — but the cost-benefit still favours leaving option keys alone (the prefix is a uniqueness device, not a slug-link).
- Custom table prefixes `{prefix}agentready_md_cache`, `{prefix}agentready_context_score_cache`. Same.
- Hook names (`agentready_post_is_noindexed`, `agentready_schema_emit`, `agentready_context_profile_saved`). Same — hooks named after the plugin's internal identifier, not its slug.

The principle: anything wp.org sees changes to `agent-ready`; anything PHP-level or storage-level stays at `agentready`. The two namespaces are decoupled.

## Consequences

- **wp.org URL:** `https://wordpress.org/plugins/agent-ready/` (previously planned: `/plugins/agentready/`).
- **WP-CLI:** `wp agent-ready md preview ...` (previously: `wp agentready md preview ...`). Adopters with bash aliases would need to update; we have no adopters yet.
- **REST namespace:** `/wp-json/agent-ready/v1/...` (previously: `/wp-json/agentready/v1/...`). Same caveat — no adopters yet.
- **Plugin folder name in the ZIP:** `agent-ready/` (top-level). wp.org SVN `/trunk/` will hold this name.
- **i18n:** wp.org translation system keys translations off the Text Domain. New translations land under text-domain `agent-ready`. No legacy translations exist (we're pre-release).
- **AgDR-0009 supersession:** the "Text Domain locked to `agentready`" portion of AgDR-0009 is superseded by this AgDR. The "managed translations via wp.org auto-loader" portion of AgDR-0009 stays valid.
- **Tag re-cut:** the v0.1.0 GH tag + release at commit `c172449` references the pre-rename slug. After this PR merges, the v0.1.0 tag + release get re-cut at the new HEAD so the wp.org-submittable ZIP matches the public release artefact.

## Artifacts

- This AgDR: `docs/agdr/AgDR-0036-slug-rename-agent-ready.md`
- Issue: `Ref34t/agentready#88`
- Superseded scope of: [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) (specifically the Text Domain value lock)

Related (unchanged scope, useful cross-references):

- [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) — translations via wp.org auto-load (still valid for the auto-loader policy; only the Text Domain value is updated)
- [AgDR-0035](AgDR-0035-build-zip-script-and-ci-verification.md) — build-zip script + CI verification. This rename invalidates the assertion patterns the CI job uses (e.g., `agentready/agentready\.php` → `agent-ready/agent-ready\.php`); those get updated in the same PR.
