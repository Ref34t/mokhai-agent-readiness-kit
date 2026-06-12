# AgDR-0059 — Rename to "AgentReady – AI Readiness Kit" for wp.org naming compliance

> In the context of `Ref34t/agentready#224` — wp.org rejected the submission citing the directory rule that generic, descriptive names resembling existing plugins are not accepted and the name must lead with a unique/coined/brand term — facing the choice between (a) keep "AI Readiness Kit" and appeal, (b) accept the username-prefixed fallback slug `mokhaled-ai-readiness-toolkit`, (c) lead with the org name ("9H AI Readiness Kit"), or (d) lead with the established coined brand "AgentReady", I decided to rename the display name to **"AgentReady – AI Readiness Kit"** (slug + Text Domain `agentready-ai-readiness-kit`) — to achieve a compliant, brand-led name that reuses the product's original coined mark (the repo name + the term the team already uses for it) and keeps the descriptive tail wp.org indexes on, accepting that this is the third naming change and requires a full Text-Domain sweep + a v0.3.0 re-cut.

## Context

- "AI Readiness Kit" is composed entirely of generic terms (AI / Readiness / Kit) and reads like other directory AI plugins. This is the same class of problem that got "Agent Ready" auto-reassigned to the username-prefixed slug in an earlier round.
- The reviewer's quoted examples ("WriteralAI – AI Writter", "Acme Image Optimization") establish the required shape: **CoinedBrand + descriptive**.
- "AgentReady" is already the product's coined brand — the GitHub repo name (`Ref34t/agentready`), the original product name, and every internal identifier (`agentready_*` options/hooks/handles). It is a single coined camelCase mark, not two generic words.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| A — Keep "AI Readiness Kit", appeal | No rework | Directly contradicts the quoted rule; will bounce again |
| B — Accept `mokhaled-ai-readiness-toolkit` | Zero naming effort | Ugly username-prefixed slug; weak brand; the fallback signals rejection, not endorsement |
| C — "9H AI Readiness Kit" (org-led) | Matches the "Acme …" example most literally; lowest risk | Reverses the deliberate v0.1 move from 9H-brand to personal attribution |
| **D — "AgentReady – AI Readiness Kit" (chosen)** | Reuses the existing coined brand; strong continuity with repo + internal identifiers; descriptive tail preserved | Third rename; full Text-Domain sweep; v0.3.0 re-cut |

## Decision & Scope (minimal propagation)

Change **only** the wp.org-enforced surfaces; preserve the established wp.org-visible-vs-internal split (AgDR-0036/0039 pattern):

| Surface | New value |
|---------|-----------|
| Plugin Name (display) | `AgentReady – AI Readiness Kit` |
| wp.org slug | `agentready-ai-readiness-kit` |
| Text Domain | `agentready-ai-readiness-kit` (header + all ~454 PHP/JS gettext + `wp_set_script_translations` domains + phpcs `text_domain` + POT) |
| Main plugin file | `agentready-ai-readiness-kit.php` |
| ZIP top-level folder | `agentready-ai-readiness-kit/` (build-zip derives from Text Domain) |
| readme.txt `=== title ===` | `AgentReady – AI Readiness Kit` |

**Unchanged (deliberately):** REST namespace `ai-readiness-kit/v1`, WP-CLI base `wp ai-readiness-kit`, Abilities category `ai-readiness-kit` + ability IDs `ai-readiness-kit/*`, the served `generator` identifier, and all internal `agentready_*` (options, hooks, table prefix, post-meta, JS globals, CSS classes, script handles, PHP namespace `WPContext\`, GitHub repo, composer/npm names). wp.org does not enforce these and a slug-length CLI command (`wp agentready-ai-readiness-kit …`) would be poor ergonomics.

This supersedes the **value choice** in AgDR-0039 (display name + slug + textdomain) while preserving its **pattern** (visible-vs-internal split). Released as a re-cut of v0.3.0 (the tag was never published).
