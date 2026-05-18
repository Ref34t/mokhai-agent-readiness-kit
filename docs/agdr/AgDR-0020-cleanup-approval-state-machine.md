# AgDR-0020 — Cleanup approval state machine + REST surface (Phase B)

> In the context of `Ref34t/agentready#6` Phase B — ACs 3 + 4 (side-by-side preview, admin approve / reject / regenerate) — facing the choice of how to encode the post-LLM-cleanup-but-pre-public-serve gate, where the swap from deterministic to cleaned output happens, and which REST surface drives the sidebar UI, I decided to extend the Phase-A state machine with two terminal admin-action states (`approved` / `rejected`) gated by content-hash freshness, swap the served route output only when status is `approved`, expose four namespaced REST routes under `agentready/v1/markdown-views/cleanup/*`, and treat regenerate as a hard reset (invalidate + reschedule), to achieve a small auditable state machine that mirrors editorial workflows admins already understand, accepting that "approved" is currently sticky for the current content hash only — a future "always-approve-this-post" preference is a v0.1.x scope.

## Context

Phase A (PR #46) shipped the engine: post-meta records `pending` / `done` / `needs-retry` / `failed`, the cleaned MD is stored on the post but never served. The public `.md` route still returns the walker's deterministic output. Phase B closes the loop:

- The editor needs a UI to **see** what the LLM produced before it goes public.
- The editor needs the **action surface** (approve / reject / regenerate) to make the call.
- The orchestrator needs the **transition logic** + persistence so the decision survives editor reload.
- The `Service` (public-route handler) needs **one new branch** — serve cleaned MD instead of deterministic when status is `approved`.

The smallest correct surface is two new states (`approved` / `rejected`) and four REST endpoints. No new DB tables, no schema migration. All state lives in post-meta the orchestrator already writes to.

## Options Considered

### State-machine shape

| Option | Pros | Cons |
|--------|------|------|
| **A — add `approved` + `rejected` as terminal states; reject is sticky for current hash** | Smallest surface; admin actions are explicit, persisted, content-hash-scoped. Hash mismatch on save → fresh cleanup runs → admin re-decides. | "Approve once, always approve" requires a follow-up v0.1.x preference. |
| B — implicit auto-approve on `done` | Zero extra state; cleaned MD ships as soon as guard passes. | Defeats the AC's whole point: AC 3 says "Admin can approve, reject, or regenerate." Auto-approval removes editor consent. |
| C — workflow-engine pattern with explicit transition log | Auditable; replay-able. | Massive over-engineering for v0.1; cleanup state is per-post, not cross-post or cross-user. |
| D — global "always approve cleanups" plugin setting | Removes per-post friction once admin trusts the guard. | Same removal-of-consent issue as B. Better as a v0.1.x preference on top of A. |

### Public-route swap rule

| Option | Pros | Cons |
|--------|------|------|
| **A — `Service::get_markdown_for_post()` returns cleaned MD iff `status == 'approved'` and `Cleanup_Orchestrator::META_KEY_OUTPUT_HASH` matches the current content hash** | One-line branch; reads through the existing fresh-cleanup helper Phase A already wrote (`get_fresh_output`). | None significant — the guard already exists. |
| B — Always serve cleaned MD if present | Simpler. | Pre-approval cleaned MD leaks to public route; defeats AC 3. |
| C — Filter-based override (`apply_filters( 'agentready_md_use_cleanup', ... )`) | Maximum extension flex. | Filter-not-set is the common path; default-true-or-false is the question; mostly hand-waving. v0.1.x candidate if needed. |

### REST surface

| Option | Pros | Cons |
|--------|------|------|
| **A — Four routes under `agentready/v1/markdown-views/cleanup/*`: `GET` (status + both MDs + diagnostics), `POST /approve`, `POST /reject`, `POST /regenerate`** | Each route has one job; REST methods carry semantic intent (GET=read, POST=mutate). Easy to test, easy to cache. | Slightly more routes than needed (could collapse approve/reject/regenerate into one route with `action=` body field) — but the cost is ~10 lines per route. |
| B — Single route with `action=` field | Fewer routes to register. | Worse REST hygiene; one capability check for both read and mutate; cache-policy gets ambiguous. |
| C — Extend the existing `/markdown-views/preview` route with optional `?include=cleanup` | No new namespaces. | Bloats one endpoint with two unrelated concerns; per-call cost goes up for callers that only want preview. |

## Decision

**A across all three dimensions.** Specifically:

### 1. State machine extension

```
(no key)  ─ save_post → no transition (state created on first read/cron)
   │
   ▼
 pending  ─ cron fires → run_cleanup
   │
   ├── guard passes ──────────► done ─────────► approved  (admin Approve)
   │                                │
   │                                └────────► rejected  (admin Reject)
   │
   ├── guard kill switch ────────► needs-retry
   │                                │
   │                                └─ admin Regenerate ─► pending (back to top)
   │
   └── provider error (non-retry) ► failed
                                    │
                                    └─ admin Regenerate ─► pending (back to top)
```

State definitions (extending Phase A's state-machine docblock in `Cleanup_Orchestrator`):

| State | Set by | Means |
|-------|--------|-------|
| `pending` | `schedule()` | Cron event queued. Public serves deterministic. |
| `done` | `run_cleanup()` success | Cleanup output present; guard passed. **Public still serves deterministic** until admin approves. |
| `approved` | `approve()` | Admin endorsed the cleanup. **Public serves cleaned MD.** |
| `rejected` | `reject()` | Admin rejected the cleanup. Sticky for current content hash. Public serves deterministic. |
| `needs-retry` | `run_cleanup()` on guard fail or rate-limit | Cleanup attempt produced output but guard kill-switch fired, OR provider was rate-limited. Public serves deterministic. |
| `failed` | `run_cleanup()` on non-retry error | Hard failure (post no longer eligible / unrecoverable provider error). Public serves deterministic. |

### 2. Sticky-reject + hash-scoped semantics

Reject is **sticky for the current content hash**. The hash is the same `Cleanup_Orchestrator::META_KEY_OUTPUT_HASH` Phase A already writes — sha1 over `post_content + post_modified_gmt + post_title`.

When the post content changes (`save_post`), `Cleanup_Orchestrator::invalidate()` deletes all four cleanup meta keys (including the status). On the next read / cron tick, a fresh cleanup runs and the admin re-decides. No `rejected` lingers across edits.

This is the simplest correct behaviour and matches how the cache invalidation already works. A future "permanently reject cleanup on this post" preference is a separate post-meta or option; out of scope for v0.1.

### 3. REST surface

Namespace: `agentready/v1/markdown-views/cleanup`.

| Method | Path | Purpose | Capability |
|--------|------|---------|------------|
| GET    | `/markdown-views/cleanup?post=<id>` | Read full cleanup state for the post. | `edit_post` on `<id>` |
| POST   | `/markdown-views/cleanup/approve`    | Transition `done` → `approved`. | `edit_post` |
| POST   | `/markdown-views/cleanup/reject`     | Transition `done` → `rejected`. | `edit_post` |
| POST   | `/markdown-views/cleanup/regenerate` | Invalidate cleanup output, mark `pending`, schedule cron run. Callable from any state. | `edit_post` |

GET response shape (frozen for v0.1):

```json
{
  "status":   "pending" | "done" | "approved" | "rejected" | "needs-retry" | "failed" | null,
  "content_hash":          "<sha1>",
  "deterministic_markdown": "...",
  "cleaned_markdown":       "..." | null,
  "diagnostics": {
    "attempted_at":      "<ISO-8601>",
    "sentences_kept":    int,
    "sentences_dropped": int,
    "stage":             "provider" | "guard" | null,
    "error_code":        string | null,
    "dropped":           [{ sentence, stage, tokens|entity }]
  } | null,
  "quality_score": int | null,
  "signals":       { ... } | null
}
```

POST responses (approve / reject / regenerate) return the same shape as GET — the UI just refreshes from the response, no second fetch needed.

### Idempotency

- `POST /approve` on a post already `approved` → 200, no-op.
- `POST /reject` on a post already `rejected` → 200, no-op.
- `POST /approve` on a post in `pending` / `needs-retry` / `failed` / no-state → **409 Conflict** (`cleanup_not_done`). Approval is only meaningful when output exists.
- `POST /reject` on a post with no `done` cleanup → 409.
- `POST /regenerate` on a post in `pending` → 200, no-op (existing cron event handles it).

### What this AgDR explicitly does NOT decide

- **Cross-post "approve all" bulk action** — deferred. Post-by-post in v0.1.
- **A separate Tools page listing all `needs-retry` / `failed` posts** — deferred to v0.1.x. The sidebar surfaces single-post state only.
- **"Permanently reject cleanup on this post" sticky-across-edits preference** — separate post-meta key; v0.1.x.
- **Cost / model preference per post** — the orchestrator still uses one prompt + one tier. Per-post tuning is a separate AgDR.
- **Filter override** for `Service::get_markdown_for_post()` deciding whether to serve cleaned MD — out of scope; v0.1.x if extension demand surfaces.

## Consequences

- `Cleanup_Orchestrator` gains three public methods: `approve( int $post_id ): bool`, `reject( int $post_id ): bool`, `regenerate( int $post_id ): bool`. Each returns true on a real transition, false on idempotent no-op.
- `Cleanup_Orchestrator` gains `get_state( int $post_id ): array` returning the full state blob used by the GET endpoint (status, hash, cleaned MD, diagnostics, quality_score, signals).
- `Service::get_markdown_for_post()` gains a single branch: after computing the deterministic MD + writing to cache, check `Cleanup_Orchestrator::get_fresh_output()` (already exists) **plus** the status; return cleaned MD only when status is `approved`. The check is two reads of post-meta plus a string comparison — negligible cost.
- New file: `includes/Markdown_Views/Cleanup_Rest_Controller.php`. Mirrors the existing `Rest_Controller` pattern. Registers four routes under the namespace; each handler does capability check → orchestrator call → response.
- `Main::register_hooks()` adds one line to register the new controller.
- `src/admin/markdown-views-sidebar/index.js` extended with a Cleanup panel section: status badge, side-by-side preview (when cleaned MD exists), three action buttons gated by current state, diagnostics expander, polling on `pending` to auto-update.
- No DB schema change. No new options. State lives entirely in the four post-meta keys Phase A already writes.
- Tests: unit tests for the new orchestrator methods (state-transition matrix), integration tests for each REST route (capability gating + transition correctness + idempotency + 409 cases).

## Artifacts

- Ticket: `Ref34t/agentready#6`
- Related AgDRs: AgDR-0017 (quality score), AgDR-0018 (no-hallucination guard), AgDR-0014 (sidebar surface convention)
- Files (planned): `includes/Markdown_Views/Cleanup_Orchestrator.php` (extend), `includes/Markdown_Views/Cleanup_Rest_Controller.php` (new), `includes/Markdown_Views/Service.php` (extend), `includes/Main.php` (one line), `src/admin/markdown-views-sidebar/index.js` (extend), tests/Integration/Markdown_Views/Cleanup_Rest_Controller_Test.php (new), tests/Unit/Markdown_Views/Cleanup_Orchestrator_State_Test.php (new)
