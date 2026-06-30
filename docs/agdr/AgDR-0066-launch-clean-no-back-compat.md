# Launch-clean rename: drop the migration and the deprecation shims (0.5.0)

> In the context of shipping 0.5.0 as the public launch with fewer than 10 installs and no known integrators, facing the back-compat machinery proposed in AgDR-0065 (a data migration + deprecation shims), I decided to drop both and do a clean hard rename, to achieve the simplest possible launch codebase, accepting that any pre-0.5.0 install resets its stored settings on upgrade.

## Context

AgDR-0065 chose a careful rename with (a) an automatic data migration to preserve existing installs' stored data and (b) deprecation shims so external integrators bound to the old `agentready_*` hooks / `ai-readiness-kit` REST·CLI·abilities keep working. PR1 (merged) shipped the shims; PR2 was to ship the migration.

The CEO has reframed: **0.5.0 is the launch.** There is no meaningful install base to preserve (Plugin Directory shows "fewer than 10," no reviews) and no integrators to keep compatible. The back-compat machinery is therefore pure overhead on the critical path to launching today.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Keep migration + shims (AgDR-0065) | Zero data loss, integrators safe | Migration to write/test + a deprecation window to carry — unjustified with ~0 real installs/integrators and a launch deadline today |
| **Hard rename, no migration, no shims (chosen)** | Simplest codebase; nothing to maintain; ships today | Any pre-0.5.0 install resets its Context Profile + loses cached Markdown/scores on upgrade; old hook/REST/CLI names stop working immediately |

## Decision

Chosen: **hard rename, no back-compat.**

- Rename every stored-data identifier `agentready_*`→`mokhai_*` / `_agentready_*`→`_mokhai_*` (options, post-meta, user-meta, transients, cron hook names, the `agentready_md_cache` table) with **no migration** — old keys are simply abandoned; the cache table is recreated under the new name on activation. The Context Profile re-initialises to safe defaults (exposes nothing) on a fresh read, which is the correct safe default anyway.
- **Remove the PR1 deprecation shims**: the `apply_filters_deprecated`/`do_action_deprecated` aliases, the legacy `ai-readiness-kit/v1` REST routes, the legacy `ai-readiness-kit` WP-CLI command alias, and the legacy `ai-readiness-kit/*` ability IDs + category. Keep only the `mokhai*` names.
- Hard-rename the global helper `agentready_has_ai_client()` → `mokhai_has_ai_client()` with **no** deprecated wrapper.
- Tighten the PHPCS `PrefixAllGlobals` allow-list back to the `mokhai`/`MOKHAI`/`Mokhai` family only.

This supersedes the migration + shim approach in AgDR-0065 (the rename decision itself stands; only the back-compat path is dropped).

## Consequences

- The 0.5.0 changelog states plainly: this is the launch baseline; upgrading a pre-0.5.0 dev/test install resets its settings (re-configure under Tools → Context). No automatic migration.
- External integrators (none known) bound to the old hook/REST/CLI/ability names must move to `mokhai*` — there is no compatibility window.
- No `Migrate_0_5_0` class, no migration ticket gate, no reverse-migration affordance to maintain.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#272 (re-scoped from "migration" to "hard rename, no back-compat")
- Supersedes the back-compat portions of AgDR-0065. PR1 (#271) shims are removed by this PR.
