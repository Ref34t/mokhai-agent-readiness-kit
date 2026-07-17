# First-run onboarding surface: dismissible admin notice with a documented agent-setup seam

> In the context of first-run onboarding (#251), facing the fact that a fresh install exposes nothing (`exposed_cpts` defaults to empty, so `/llms.txt` stays header-only until the owner discovers Tools → Context), I decided to ship a dismissible admin notice with a confirm-gated one-click expose action — modelled on the existing `Conflict_Notice` pattern — rather than a dedicated first-run screen, to achieve a discoverable manual setup path that the 1.0 Mokhai Agent can later join as a second choice, accepting that a notice is less prominent than a full onboarding screen.

## Context

- Safe-by-default is deliberate: `Context_Profile_Settings::get_defaults()` ships `exposed_cpts => []` and every consumer treats empty as "expose nothing". The onboarding surface must reinforce (not apologise for) that default.
- The road-to-v1 initiative requires this surface to be forward-compatible with 1.0: on first activation the owner will choose **agent-guided** or **manual** setup. 0.8 builds the manual path for real; the agent-guided path must be able to slot in without a rebuild.
- The plugin already has every building block: `LlmsTxt/Conflict_Notice.php` (admin-notice pattern with per-user dismissal, capability gating, screen gating, vanilla-JS ajax dismiss) and `Context_Profile_Settings::set_exposure()` (exposure-only merge that fires `mokhai_context_profile_saved`, cascading Context Score recompute and `/llms.txt` regeneration).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Dismissible admin notice (chosen) | Reuses the proven `Conflict_Notice` pattern wholesale; no React bundle work; fits the one-week 0.8 budget; naturally extensible to a second CTA | Less prominent than a dedicated screen; competes with other plugins' notices |
| Dedicated first-run screen (React) | Most prominent; the 1.0 choice UI could be built once | Heaviest option (bundle, routing, state); pre-commits 1.0's UX before the Agent PRD exists; real risk of blowing the 0.8 week |
| Activation redirect to Tools → Context | Very cheap | Activation redirects are a WP anti-pattern (break bulk-activation, WP-CLI, multisite); no persistent nudge if the user navigates away |

## Decision

Chosen: **dismissible admin notice**, because it delivers the discoverability fix inside the 0.8 budget using patterns already proven in this codebase, and it defers 1.0 UX decisions to the Agent PRD where they belong.

Load-bearing details:

- **Render condition**: only when exposure is effectively empty (nothing exposed) AND the current user has not dismissed it. Hidden permanently for that user on dismissal (user-meta), hidden for everyone once content is exposed.
- **Gating**: `manage_options` + screen-gated (Dashboard, Plugins, and the plugin's own Tools page), mirroring `Conflict_Notice`.
- **Primary CTA — one-click expose**: nonce-gated, explicit confirmation, then `Context_Profile_Settings::set_exposure(['post','page'], ['publish'])` (CEO-confirmed default: posts + pages at publish). Never auto-exposes; the click + confirm is the consent moment. Reusing `set_exposure()` (not direct option writes) is REQUIRED so the saved-event cascade fires.
- **Secondary CTA**: link to Tools → Context for owners who want to choose manually.
- **The 1.0 seam**: the notice renders its CTAs from a filterable list (`mokhai_first_run_actions`). In 1.0 the Mokhai Agent registers an "Set up with the Mokhai Agent" action into that filter and (per its own PRD) may replace the notice with a fuller choice screen. Nothing else about the agent path is pre-built in 0.8 — the filter is the whole seam.
- **Copy**: states explicitly that the empty `/llms.txt` is intentional (safe-by-default), not a malfunction.

## Consequences

- 0.8 ships onboarding with zero new dependencies and no React changes.
- The `mokhai_first_run_actions` filter becomes a public extension point; it is documented and covered by a test so 1.0 can rely on it.
- If 1.0's PRD chooses a full-screen setup flow, the notice remains as the fallback nudge and the screen supersedes it — the notice's render condition (nothing exposed + not dismissed) already yields to any flow that exposes content.
- Sites that legitimately want nothing exposed dismiss once per admin user; dismissal is durable.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#251
- Initiative: road-to-v1 (0.8 milestone) — `projects/agentready/initiatives/mokhai-road-to-v1.md` in the private portfolio repo
- Implementation PR: (added when opened)
