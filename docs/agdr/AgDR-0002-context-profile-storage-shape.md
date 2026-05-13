---
id: AgDR-0002
status: reserved
reserved_for: "#4 — Context Profile admin screen (single source of truth + safe-by-default exposure)"
referenced_in: AgDR-0003
---

# AgDR-0002 — Context Profile storage shape (RESERVED)

> **This is a placeholder.** The real AgDR is written when ticket [#4](https://github.com/Ref34t/agentready/issues/4) starts. Until then, this file exists so `docs/agdr/` doesn't have a confusing numbering gap between 0001 and 0003.

## Why this is reserved

[AgDR-0003 § Artifacts](AgDR-0003-ai-client-wrapper.md) explicitly references the reservation:

> "(AgDR-0002 reserved for #4's Context Profile storage shape.)"

The Context Profile is **FR-1** in the PRD — "the architectural keystone every other v0.1 module reads from." Several already-shipped pieces of code reference the not-yet-written storage decision:

- `uninstall.php` lists `agentready_settings` + `agentready_version` with the comment *"Kept in sync with the Context Profile storage shape (#4 / AgDR-002)."*
- `includes/Main.php::on_activate` does the same.

When #4 begins, the developer writing the Profile admin screen will pick the storage shape (single versioned `wp_options` entry vs custom table vs split keys, schema versioning approach, migration strategy for future fields, etc.), record the decision here, replace this placeholder, and the cross-references resolve to a real decision rather than a forward reference.

## What this placeholder is NOT

- It is **not** a decision. The actual choice is open until #4 starts.
- It is **not** binding on the #4 author. They can amend the reservation scope or split the decision across multiple AgDRs if needed.
- It does **not** consume an AgDR ID that gets "wasted" — the file replaces this placeholder when written for real.

## Cross-references

- Reservation source: [AgDR-0003](AgDR-0003-ai-client-wrapper.md) § Artifacts
- Reserving ticket: [#4](https://github.com/Ref34t/agentready/issues/4) — Context Profile admin screen
- Placeholder ticket (this file): [#24](https://github.com/Ref34t/agentready/issues/24)
- PRD requirement: FR-1 (Context Profile)
