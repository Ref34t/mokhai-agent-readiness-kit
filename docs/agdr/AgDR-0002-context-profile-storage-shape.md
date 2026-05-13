---
id: AgDR-0002
status: reserved
reserved_for: "#4 — Context Profile admin screen (single source of truth + safe-by-default exposure)"
referenced_in:
  - includes/Main.php:74
  - uninstall.php:20
---

# AgDR-0002 — Context Profile storage shape (RESERVED)

> **This is a placeholder.** The real AgDR is written when ticket [#4](https://github.com/Ref34t/agentready/issues/4) starts. Until then, this file exists so `docs/agdr/` doesn't have a confusing numbering gap between 0001 and 0003.

## Why this ID is reserved

Two already-shipped pieces of plugin code carry forward-references to a "Context Profile storage shape" AgDR-0002 that hasn't been written yet:

- [`uninstall.php:20`](../../uninstall.php) — *"Kept in sync with the Context Profile storage shape (#4 / AgDR-002)."*
- [`includes/Main.php:74`](../../includes/Main.php) — *"Context Profile (#4 / AgDR-002) stores settings in a single versioned wp_options entry."*

The Context Profile is **FR-1** in the PRD — "the architectural keystone every other v0.1 module reads from." The two comments above pre-committed to logging the storage decision as AgDR-0002 before the decision itself was made (a reasonable forward reference, because #4 will need that record anyway).

When #4 begins, the developer picks the storage shape (single versioned `wp_options` entry vs custom table vs split keys, schema versioning approach, migration strategy for future fields, etc.), records the decision here, replaces this placeholder, and the forward references resolve to a real decision.

## What this placeholder is NOT

- It is **not** a decision. The actual choice is open until #4 starts.
- It is **not** binding on the #4 author. They can amend the reservation scope or split the decision across multiple AgDRs if needed.
- It does **not** "consume" an AgDR ID that gets wasted — this file is replaced by the real content when written.

## Cross-references

- Forward references in code: `includes/Main.php:74`, `uninstall.php:20`
- Reserving ticket: [#4](https://github.com/Ref34t/agentready/issues/4) — Context Profile admin screen
- Placeholder ticket (this file): [#24](https://github.com/Ref34t/agentready/issues/24)
- PRD requirement: FR-1 (Context Profile)
