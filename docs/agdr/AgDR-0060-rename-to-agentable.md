# AgDR-0060 — Rename to "Agentable" (drop the AgentReady brand)

> In the context of `Ref34t/agentready#230` — the wp.org pre-review pended the submission, flagging "AgentReady" as a potential third-party trademark and as too similar to existing directory plugins, and a search confirmed a real, unrelated brand at **agentready.org** — facing the choice between (a) defend "AgentReady" as our own brand, (b) lead with the org name ("9H …"), (c) a discovery-flavoured coinage (Crawlworthy / Contextful), or (d) a coinage that also spans the product's read→act roadmap — I decided to rename the display name, slug, and Text Domain to **`Agentable`** — to escape the agentready.org collision with a distinct, ownable mark that fits both today's capability (sites *readable* by agents) and the planned next step (sites *actionable* by agents), accepting a fourth naming change and a second full Text-Domain sweep. This **supersedes AgDR-0059**.

## Context

- "AgentReady" is not ownable: **agentready.org** is a live, unrelated brand. Defending our use would require a registered trademark we do not hold, and would not answer the reviewer's separate "too similar to existing plugins" flag.
- The product roadmap moves beyond read-only readiness: the next phase makes WordPress sites **actionable** by agents (not just legible). A name anchored on "Ready"/"Readiness"/"Crawl" would mis-describe that future.
- wp.org enforces the slug, display name, and Text Domain. Internal identifiers (`agentready_*` options/hooks/handles, REST namespace, WP-CLI base, abilities, PHP namespace, repo + composer/npm names) are not enforced and were preserved in AgDR-0059's visible-vs-internal split.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| A — Defend "AgentReady" as our brand | No rework | agentready.org exists; no registered TM to cite; similarity flag unanswered → likely re-bounce |
| B — "9H …" (org-led) | Clearly ours | "9H" alone is two chars (reviewer warns a few letters isn't enough); descriptive tail stays generic |
| C — Crawlworthy / Contextful | Distinctive, slug-free | Locks the brand into read-only discovery; contradicts the read→act roadmap |
| **D — "Agentable" (chosen)** | Coined (`agent` + `-able`); spans *readable AND actionable*; wp.org slug `agentable` free; no plugin/brand collision; ownable SaaS domain (`agentablehq.com`) | Fourth naming change; second full Text-Domain sweep; v0.3.1 re-cut |

## Decision & Scope (minimal propagation)

Change the wp.org-enforced surfaces **plus** the user-facing product name, preserving the visible-vs-internal split:

| Surface | New value |
|---------|-----------|
| Plugin Name (display) | `Agentable` |
| wp.org slug | `agentable` |
| Text Domain | `agentable` (header + all PHP/JS gettext + `wp_set_script_translations` + phpcs `text_domain` + POT) |
| Main plugin file | `agentable.php` |
| ZIP top-level folder | `agentable/` (build-zip derives from Text Domain) |
| readme.txt `=== title ===` | `Agentable` |
| User-facing product name in strings | "AI Readiness Kit" → "Agentable" (admin page titles, activation notices, score-narrative prompt + guard allowlist) |

**Unchanged (deliberately):** REST namespace `ai-readiness-kit/v1`, WP-CLI base `wp ai-readiness-kit`, Abilities category + IDs, the served `generator` identifier, and all internal `agentready_*` (options, hooks, table prefix, post-meta, JS globals, CSS classes, script handles), PHP namespace `WPContext\`, GitHub repo, composer/npm names. **Zero data/API migration — existing installs upgrade in place.** Descriptive wp-admin submenu labels ("AI Readiness — Context/Score") are kept as-is for findability.

## Bundled fix (same PR)

ABSPATH → public-root correctness: `Signal_Collector::multi_channel_signals` and `Conflict_Detector::filesystem_conflict` treated `ABSPATH` as the public web root when probing root-served agent files (`llms.txt`, `ai.txt`, `.well-known/*`). On subdirectory installs the document root differs from the WP install dir, so detection misfired. Both now resolve the document root via `get_home_path()`. This closes the wp.org review's "determine file locations correctly" finding and the pre-existing AgDR-0043 subdirectory-install limitation.

Released as **v0.3.1** (a behaviour change beyond the rename; the v0.3.0 tag was a pending, unpublished submission).
