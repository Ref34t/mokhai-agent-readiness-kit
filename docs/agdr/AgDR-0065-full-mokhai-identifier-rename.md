# Full `mokhai_*` identifier rename (reverses AgDR-0060/0062 preservation)

> In the context of completing the Mokhai rebrand, facing internal/developer-facing identifiers still on the legacy `agentready_*` / `ai-readiness-kit` / `WPContext\` / `WPCTX_*` scheme, I decided to rename them all to a `mokhai` scheme as a breaking 0.5.0 release with an automatic upgrade migration and deprecation shims, to achieve a coherent brand down to the code, accepting a one-time migration + a deprecation-alias maintenance window.

## Context

The user-facing identity was renamed to **Mokhai** in 0.3.2–0.4.0 (display name, text domain `mokhai-agent-readiness-kit`, all admin prose, wp.org listing). AgDR-0060/0062 deliberately **preserved** the internal identifiers (`agentready_*` options/meta/table/hooks/cron, `ai-readiness-kit` REST/CLI/abilities, `WPContext\` namespace, `WPCTX_*` constants) so existing installs upgraded "with no action required."

The CEO has decided to finish the rebrand down to the code. The decisive factor is **timing**: the plugin has fewer than 10 installs and no known integrators, so the migration blast radius and the back-compat surface are at their smallest they will ever be. Renaming later only gets more expensive.

This is an explicit reversal of AgDR-0060/0062 for the internal layer. The public **slug** and **text domain** (`mokhai-agent-readiness-kit`) are NOT renamed — they are already correct and the slug is wp.org-locked.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Leave identifiers as-is (status quo, AgDR-0060/0062) | Zero risk, honors the no-migration promise | Permanent `agentready`/`ai-readiness-kit`/`WPContext` debt visible to every developer, integrator, and DB inspector; brand split deepens over time |
| Rename internal namespace/constants only (Class C) | Safe, no migration, no integrator impact | Leaves stored data + public contracts on the old brand — half-done |
| **Full rename with migration + deprecation shims (chosen)** | Coherent brand end-to-end; cheapest while installs <10; integrators kept working via shims; data preserved by migration | One-time migration to write + test; a deprecation-alias window to carry for ≥1 minor; large mechanical diff |
| Full rename, hard cut, no shims/migration | Least code | Data loss on upgrade + silent integrator breakage — unacceptable |

## Decision

Chosen: **full rename with migration + deprecation shims**, shipped as **0.5.0** across three PRs:

- **PR 1 (behavior-preserving):** `WPContext\`→`Mokhai\`, `WPCTX_*`→`MOKHAI_*`, filter/action hooks + REST namespace + WP-CLI base + ability IDs → `mokhai*`, each with a deprecation alias (`apply_filters_deprecated`/`do_action_deprecated`; duplicate legacy route/command/ability registration). No stored-data keys touched, so it is safe on existing installs without a migration.
- **PR 2 (stored data + migration):** rename option/meta/transient/cron keys + the `agentready_md_cache` table to `mokhai_*`; a version-gated, multisite-aware `Migrate_0_5_0` routine copies/renames the data on upgrade. Carries its own migration AgDR (architecture-review gate).
- **PR 3 (release):** new branding assets, readme accuracy fixes, version bump, changelog, SVN 0.5.0 release.

Naming: `agentready_`→`mokhai_`, `_agentready_`→`_mokhai_`, `ai_readiness_kit_multi_channel_providers`→`mokhai_multi_channel_providers`, `ai-readiness-kit/v1`→`mokhai/v1`, WP-CLI base `ai-readiness-kit`→`mokhai`, abilities `ai-readiness-kit`/`ai-readiness-kit/*`→`mokhai`/`mokhai/*`.

## Consequences

- The 0.5.0 changelog must state that the upgrade migrates automatically (no action required) and that legacy hooks/REST/CLI/abilities continue to work but are deprecated and will be removed in a later release.
- Deprecation shims carry for ≥1 minor; a future AgDR will remove them.
- Cron-hook renames are handled in PR 2 (scheduled events persist in `wp_options`), not PR 1.
- A migration integration test asserting zero data loss is mandatory before the SVN release.

## Artifacts

- Ref34t/mokhai-agent-readiness-kit#270 (PR 1)
- Supersedes the preservation stance in AgDR-0060 / AgDR-0062.
