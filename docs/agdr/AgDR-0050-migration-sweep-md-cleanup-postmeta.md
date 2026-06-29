# Sweep dead Markdown Views cleanup post-meta

> In the context of having retired the Markdown Views LLM cleanup pass (#153, code removed in PR #158), facing four post-meta keys that are now orphaned in `wp_postmeta` with no reader or writer, I decided to execute a **data** migration deleting those keys via an idempotent WP-CLI sweep, to achieve a clean `wp_postmeta` with no rows for a feature that no longer exists, accepting that the deletion is forward-only (rollback is a no-op because the data is already dead).

**Migration type**: data
**Affected tables / entities**: `wp_postmeta` — keys `_agentready_md_cleanup_status`, `_agentready_md_cleanup_hash`, `_agentready_md_cleanup_output`, `_agentready_md_cleanup_diagnostics`
**Estimated downtime**: none — batched `delete_post_meta`, no schema lock, no table rewrite
**Data volume**: bounded by the count of posts ever scheduled for cleanup (small; zero on sites that never enabled the pass). No live wp.org installs yet, so real-world volume ≈ 0.
**Target environment(s)**: dev/wp-env now; ships in the plugin for any future install to run once.

## Context

PR #158 deleted `Cleanup_Orchestrator` and the rest of the cleanup feature. While the feature was live it wrote four post-meta keys per post it processed. Those keys are now write-once-read-never: no surviving code path touches them. Leaving them is harmless functionally but pollutes `wp_postmeta` with dead rows and leaves a trace of a removed feature. This is the final step (PR 3 of 3) of the #153 retirement, sequenced by AgDR-0049.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — WP-CLI sweep command** (chosen) | Operator-runnable; explicit per-key counts (observability); idempotent; zero auto-execution blast radius; matches the "one-shot dead-data cleanup" shape | Requires someone to run it; doesn't auto-clean on plugin upgrade |
| B — Auto-run on plugin upgrade (version-gated init hook) | Cleans existing installs with no operator action | Adds an always-loaded version check + a destructive auto-delete on upgrade — blast radius for a cosmetic cleanup; overkill pre-launch with zero installs |
| C — Leave the data; drop it in `uninstall.php` only | Zero new code | Dead rows persist for the life of every install; never cleaned unless the plugin is uninstalled |

## Decision

Chosen: **A — a dedicated WP-CLI sweep command**, because the data is already inert (no correctness pressure to auto-clean), there are no live installs to migrate, and an operator-invoked, idempotent, per-key-reporting command is the lowest-blast-radius mechanism that still satisfies the "leave no dead data" goal. If a future need arises to auto-clean on upgrade, B can wrap the same sweep function — the command delegates to a reusable static `run()` so that path stays open.

## Rollback Plan

1. No-op. The deleted keys have no reader or writer after PR #158, so their removal changes no behaviour — there is nothing to restore.
2. The sweep is idempotent and forward-only; re-running it simply reports zero deletions.
3. (Theoretical) a deleted value was derived from post content by the retired cleanup pass; it is not recoverable, but it is also never consumed, so recovery is moot.

**Rollback tested against**: unit fixture + wp-env (seed keys → sweep → re-run reports zero)
**Rollback window**: n/a — deletion is permanent and intended; no reverse mapping needed because the data is dead.

## Cross-Service Consumers

- **none** — agentready is a self-contained WordPress plugin. The four keys were written and read solely by the now-deleted `Cleanup_Orchestrator`. No external service queries `wp_postmeta` for them.

Deploy-order constraint:

- Must land after PR #158 (the code that wrote the keys is already removed) — satisfied; this is PR 3 of 3.

## Testing Plan

- **Dev smoke**: on wp-env, `add_post_meta` the four keys on a test post, run the sweep command, assert the keys are gone, the reported per-key counts match, and unrelated meta is untouched. Re-run and assert zero deletions (idempotency).
- **Staging verify**: n/a — no staging; wp-env is the verification environment.
- **Canary / phased rollout**: n/a — dead data, no installs.

## Observability

- **During apply**: the command prints rows deleted per key and a total to stdout.
- **Post-apply**: a second invocation prints zeros for all four keys, confirming the sweep is complete and idempotent.
- **Alerts armed**: none — one-shot maintenance command, not a recurring job.

## Consequences

- `wp_postmeta` carries no rows for the retired cleanup feature once the command is run.
- The reusable `run()` keeps the door open for a future auto-on-upgrade hook (option B) without rework.
- Operators must run the command explicitly; it does not auto-fire. Documented in the command's help text.
- Completes #153 — the Markdown Views LLM cleanup pass is fully retired (code in #158, data here).

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#159 (migration) · umbrella Ref34t/mokhai-agent-readiness-kit#153
- Decision lineage: AgDR-0049 (sequenced this as step 5) · AgDR-0018 (the cleanup guard, removed in #158)
- Commits / PRs: filled in as PR 3 ships
- Run log: wp-env sweep output (before/after counts)
