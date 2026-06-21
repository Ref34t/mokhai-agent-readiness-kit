# AgDR-0062 — Rename to "Mokhai - Agent Readiness Kit" (drop the Agentable brand)

> In the context of `Ref34t/agentready#259` — the wp.org plugin reviewer rejected "Agentable" (the AgDR-0060 rename) as colliding with existing third-party projects/services and confusing about ownership/affiliation, and required a name and slug that are *clearly ours* with a **distinctive term at the beginning** — facing the choice between (a) defend "Agentable", (b) lead with the org name ("9H …"), (c) another descriptive coinage, or (d) lead with an owner-derived distinctive prefix — I decided to rename the display name, slug, and Text Domain to **"Mokhai - Agent Readiness Kit"** / `mokhai-agent-readiness-kit` — leading with "Mokhai" (a coinage from the author handle *mokhaled*) so the mark is unambiguously ours, accepting a third wp.org-forced naming change and a third full Text-Domain sweep. This **supersedes AgDR-0060**.

## Context

- "Agentable" is not ownable enough for wp.org: the reviewer confirmed it is already used by other projects/services, and wp.org checks third-party brand confusion more broadly than just the plugin directory. Being a coined word is not sufficient by itself.
- The reviewer's bar is explicit: a **distinctive term at the beginning** that is clearly the author's own (their examples led with the author handle: "Mokhaled AI Context Toolkit", "Mokhaled AI Readiness Audit").
- "Mokhai" is a coinage derived from the author's wp.org handle `mokhaled` — distinctive, leading, and unambiguously ours, while "Agent Readiness Kit" stays an accurate descriptor of the product (audit a site's readiness for AI agents).
- wp.org enforces the slug, display name, and Text Domain. Internal identifiers (`agentready_*` options/hooks/handles, REST namespace, WP-CLI base, Abilities, PHP namespace, repo name) are not enforced and were preserved across AgDR-0059/0060 via the visible-vs-internal split.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| A — Defend "Agentable" | No rework | Reviewer already rejected it; confirmed third-party usage → guaranteed re-bounce |
| B — "9H …" (org-led) | Clearly ours | Two-char prefix; reviewer warns a few letters isn't a distinctive enough lead |
| C — Another descriptive coinage | Distinctive | Risks the same "already used / coined ≠ ownable" rejection; no guarantee it's free |
| **D — "Mokhai - Agent Readiness Kit" (chosen)** | Owner-derived distinctive lead (matches the reviewer's own examples); slug `mokhai-agent-readiness-kit` free; descriptor stays accurate; zero internal migration | Third naming change; third full Text-Domain sweep; v0.3.2 re-cut |

## Decision & Scope (minimal propagation)

Change the wp.org-enforced surfaces **plus** the user-facing product name, preserving the visible-vs-internal split:

| Surface | New value |
|---------|-----------|
| Plugin Name (display) | `Mokhai - Agent Readiness Kit` |
| wp.org slug | `mokhai-agent-readiness-kit` |
| Text Domain | `mokhai-agent-readiness-kit` (header + all PHP/JS gettext + `wp_set_script_translations` + phpcs `text_domain` + POT) |
| Main plugin file | `mokhai-agent-readiness-kit.php` |
| ZIP top-level folder | `mokhai-agent-readiness-kit/` (build-zip derives from Text Domain) |
| readme.txt `=== title ===` | `Mokhai - Agent Readiness Kit` |
| User-facing product name in strings | "Agentable" → "Mokhai" (admin page copy, activation notices, conflict notices, score-narrative prompt + guard allowlist) |

**Unchanged (deliberately):** REST namespace, WP-CLI base, Abilities category + IDs, the served `generator` identifier, and all internal `agentready_*` (options, hooks, table prefix `agentready_md_cache`, post-meta, JS globals, CSS classes, script handles), PHP namespace `WPContext\`, GitHub repo, composer name. **Zero data/API migration — existing installs upgrade in place.** Verified by the phpcs `WordPress.WP.I18n` oracle (zero `TextDomainMismatch` after the sweep) and a clean rebuild of `build/` + the POT.

## Version

Released as **v0.3.2**. The v0.3.1 tag was a pending, rejected wp.org submission; this release also bundles the unreleased `main` changes that landed after 0.3.1 (#241–#245 — markdown URL/title hardening, WooCommerce index exclusion, empty-llms.txt site header, static-robots.txt conflict warning), now documented in the changelog.

## Artifacts

- Ticket: `Ref34t/agentready#259`
- Branch: `chore/GH-259-rename-mokhai-agent-readiness-kit`
- Build: `dist/mokhai-agent-readiness-kit-0.3.2.zip`
