---
id: AgDR-0005
timestamp: 2026-05-13T14:30:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-18-rename
trigger: ticket #18 — wp.org rejects "wp" / "wordpress" in plugin name + slug; surfaced during #3 Plugin Check CI build
status: executed
---

# AgDR-0005 — Rename plugin to AgentReady

> In the context of a wp.org plugin submission blocker (Plugin Check WARNING: "WP Context" and slug "wp-context" contain the restricted term "wp"), facing the choice of renaming pre-launch or being rejected at submission on 2026-07-03, I decided to rename the plugin to **AgentReady** (slug `agentready`) — single-word, brand-strong, mirrors the PRD positioning "Agent Readiness for WordPress" — to achieve a clean wp.org submission path while preserving the v0.1 launch target (2026-07-08), accepting the mechanical churn across plugin header, readme, text domain, repo name, registry, and option keys.

## Context

Ticket #3's CI build wired the wp.org Plugin Check Tool action. On its first run, Plugin Check emitted (as WARNING, not blocking CI but blocking wp.org submission):

```
Your chosen plugin name - "WP Context" - contains the restricted term "wp"
which cannot be used at all in your plugin name.

Your plugin slug - "wp-context" - contains the restricted term "wp"
which cannot be used at all in your plugin slug.
```

wp.org's [plugin guidelines § 17](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) prohibit "wp" and "wordpress" in plugin names or slugs. A small number of grandfathered plugins (WP Rocket, WPForms) hold exceptions, but new submissions are rejected on this policy without nuance.

PRD timeline: wp.org submission 2026-07-03, public launch 2026-07-08. A rejection at submission burns ~1-2 weeks of review re-queue time. Renaming pre-submission preserves the launch target; renaming post-rejection slips it.

This is also the moment with **lowest churn cost**: pre-launch means no users, no published versions, no third-party plugins depending on our hook names or options, no SEO links to preserve. The rename will never be cheaper than now.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Rename to AgentReady** (chosen) | Single word, mirrors PRD positioning ("Agent Readiness"), short slug, brand-strong, signals value prop clearly | Mechanical churn across ~15 files + the GitHub repo + apexyard registry |
| B — Rename to "Agent Context" (`agent-context`) | Preserves the "Context" half of the original name; descriptive | Two-word slug; "Agent" is a crowded word; less brand-distinct |
| C — Rename to "Context Forge" (`context-forge`) | Industrial / builder vibe matches the agency-lead persona | "Forge" is overused in the plugin space; less clearly tied to "agents" |
| D — Rename to "Agent Readiness" (`agent-readiness`) | Literally the PRD positioning | Two-word; "Readiness" alone feels generic |
| E — Appeal to wp.org for "WP Context" exception | Zero churn if granted | Very unlikely to succeed (we're new + unestablished; appeals are reserved for existing well-known brands); failure costs review-queue time |
| F — Keep "WP Context", submit anyway, hope reviewer overlooks | Zero churn | High rejection risk; harms Anonymous's standing with wp.org reviewers; not worth the gamble |

## Decision

Chosen: **A — AgentReady** (slug `agentready`).

Rationale:

- **Mirrors PRD positioning** ("Agent Readiness for WordPress" → AgentReady). Marketing copy from the analysis post + future stakeholder updates can read "AgentReady is the answer to the question 'is your site agent-ready?'".
- **Single-word + lowercase slug** is the most brand-strong shape. Compare `agentready` to `agent-context` or `context-forge` — the single token is a stronger asset for SEO, social-handle availability, and verbal memorability.
- **No restricted terms.** wp.org policy passes cleanly.
- **Brand-distinct.** "Forge" and "Context" are overused; "Agent" alone is generic. The compound "AgentReady" is distinctive without inventing a word.
- **Future-compatible.** If v0.2+ ships MCP / Tool Exposure modules (FR-15 in the PRD), "AgentReady" still describes them — the agent's tooling capability is part of "readiness".

Slug availability not yet verified on wp.org (slugs are only visible for published plugins; reserved-but-unpublished slugs aren't queryable). If `agentready` is taken at submission time, fallback candidates are `agent-ready` (hyphenated) or `agentready-wp` (allowed because the trademark policy restricts "wp" as a leading or word-bounded term — verify with reviewer).

### Internal naming — KEPT as-is

The following internal names stay because Plugin Check did NOT flag them and they're not user-facing:

- `WPCTX_*` constants — short prefix, not a "wp" word
- `wpctx_*` action / filter prefix — short prefix, not a "wp" word
- `WPContext\` PHP namespace — internal to the plugin; not visible to end users
- The phpcs.xml.dist `WPCTX` / `wpctx` / `WPContext` entries in the PrefixAllGlobals rule

Renaming these would cost: every file under `includes/`, every AgDR (0001-0004), every existing PR's diff annotations, every reference in the apexyard governance docs. The cost-to-benefit ratio is poor and Plugin Check doesn't require it.

### Internal naming — CHANGED

- Plugin slug + folder + main file: `wp-context` → `agentready`
- Text domain (every `__()`, `_e()`, `_n()`, `_x()` call): `'wp-context'` → `'agentready'`
- Option keys: `wp_context_settings` / `wp_context_version` → `agentready_settings` / `agentready_version`
- Global helper function: `wp_context_has_ai_client()` → `agentready_has_ai_client()`
- phpcs.xml.dist's prefix list: dropped `wp_context`, added `agentready`
- phpcs.xml.dist's text-domain rule: `wp-context` → `agentready`
- composer package name: `ref34t/wp-context` → `ref34t/agentready`
- npm package name: `wp-context` → `agentready`
- wp-env mapping path: `wp-content/plugins/wp-context` → `wp-content/plugins/agentready`
- CI workflow `--env-cwd` paths: same
- makepot output: `languages/wp-context.pot` → `languages/agentready.pot`
- README.md title, readme.txt title block

### Out of scope for this PR (operator follow-ups)

- **GitHub repo rename**: `Ref34t/wp-context` → `Ref34t/agentready`. Done via `gh repo rename` after this PR merges. GitHub handles redirects for the old URL.
- **apexyard registry update**: `apexyard.projects.yaml` entry (`name`, `repo`, `workspace`, `docs`, slug-derived `tags`). Lives in the operator's split-portfolio sibling repo (`9h-portfolio`).
- **Local clone path**: `workspace/wp-context/` → `workspace/agentready/`. Local-filesystem mv.
- **Per-project docs path**: `projects/wp-context/` → `projects/agentready/`. Lives in `9h-portfolio`.

### Data migration — NOT NEEDED (pre-launch)

Option key rename `wp_context_*` → `agentready_*` would normally require a one-time migration in `on_activate()` (copy old → new, delete old). **Skipped here because** the plugin is pre-launch — no published version, no users with persisted options. The first activation post-merge writes the new key fresh.

Documented for future readers: if a reverse rename or another option-key change ever lands post-launch, **the migration step IS required** and goes in `Main::on_activate()` before the version-option write.

## Consequences

- wp.org Plugin Check passes the `trademarked_term` rule going forward — no more WARNING on the plugin name or slug.
- Plugin file structure now matches the slug: `agentready.php` is the main file, loaded by WP via the standard `wp-content/plugins/agentready/agentready.php` path.
- Composer's `vendor/composer/installed.json` updated to the new package name on next `composer install`.
- CI's wp-env cells will mount the plugin at `wp-content/plugins/agentready/` matching the new wp-env mapping; PHPUnit integration cells continue green because the path is consistent across `.wp-env.json` and the CI workflow.
- Future code that uses the global helper writes `agentready_has_ai_client()` instead of `wp_context_has_ai_client()`. The helper is the public-facing API for non-namespaced template code (themes, mu-plugins, drop-ins).
- Existing AgDRs (0001 - 0004) keep their historical references to "WP Context" — they're records of decisions made at a specific time, NOT current-state documentation. New AgDRs reference the plugin as "AgentReady".
- Branding consistency: all marketing surfaces, the "WordPress for Machines" launch post, the analysis post, and the wp.org listing read as "AgentReady" from the moment this lands.

## Artifacts

- Ticket: https://github.com/Ref34t/wp-context/issues/18
- Branch: `chore/GH-18-rename-to-agentready`
- AgDR: this file
- PR: (linked here on creation)
