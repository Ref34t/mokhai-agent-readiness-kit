---
id: AgDR-0002
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-4-context-profile-admin
trigger: ticket #4 (Context Profile admin screen); reservation honoured per AgDR-0002 placeholder
status: executed
referenced_in:
  - includes/Main.php:74
  - uninstall.php:20
---

# AgDR-0002 — Context Profile storage shape: single versioned `wp_options` entry

> In the context of needing a single storage surface for the Context Profile — the architectural keystone of v0.1 that drives `/llms.txt`, `.md` views, the Context Score, schema coordination, and the LLM toggles — facing the requirement that every downstream module (#5–#11) reads from the same source-of-truth without coordination overhead, I decided to persist the profile as a single versioned `wp_options` entry (`agentready_context_profile`) containing an associative array with an explicit `schema_version` field plus typed sub-keys, sanitised through a single PHP callback before write and validated on read against a defaulted schema, to achieve a cheap-to-read autoloaded surface and a clean migration path for forward schema changes, accepting that this couples all profile fields to one cache line (acceptable: the entire profile is < 4 KB on a 100-CPT site and is always read together).

## Context

The Context Profile is **FR-1** of the PRD — the single source of truth for every agent-facing output:

- Which CPTs are exposed (default: none, per FR-9 safe-by-default rule)
- Which statuses are exposed (default: only `publish`)
- Site identity (auto-filled from WP options on read)
- Schema-coordination posture (auto-detected on read from active SEO plugins)
- LLM cleanup toggle and LLM description toggle (default on, degrade silently per AgDR-0003)
- The `schema_version` integer used for future migrations

Downstream modules read this profile on every request that produces an agent-facing surface:

- **#5** (deterministic HTML → MD) reads `exposed_cpts`, `exposed_statuses`
- **#6** (LLM cleanup pass) reads `llm_cleanup_enabled`
- **#7** (`/llms.txt`) reads everything in the profile
- **#8** (LLM entry descriptions) reads `llm_descriptions_enabled`
- **#9** (cache invalidation rules) keyed on the previous profile snapshot
- **#10** (Context Score) reads everything and produces a recompute on save
- **#11** (Context Score LLM narrative) reads `llm_*` toggles

Hot-path reads must be cheap. Cross-module coordination must be obvious. Future fields (new CPTs, new SEO-plugin coordination targets, the `/llms-full.txt` config in v0.1.1) must be addable without a destructive migration.

The two pre-existing forward references — `uninstall.php:20` and `includes/Main.php:74` — both pre-committed to "a single versioned `wp_options` entry" as the storage shape. This AgDR ratifies that commitment with concrete justification, and locks the schema-version semantics for future migrations.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Single versioned `wp_options` entry, autoloaded** | One read per request via the WP options cache; trivially backed up with `wp db export wp_options`; single sanitise callback; obvious diff on save; cheap to invalidate; standard WordPress idiom. | Whole profile loads on every request — wasteful if the profile grows to many KB. All-or-nothing: a corrupt JSON value loses every field. Updates rewrite the whole row. |
| B — Split `wp_options` keys (`agentready_profile_exposure`, `agentready_profile_llm`, etc.) | Fine-grained cache invalidation; one corrupt key doesn't poison the others; smaller diffs per save. | N reads per request (or N times the autoload weight); N sanitise callbacks to maintain; tickets #5–#11 each need to remember which key holds what; cross-key consistency (e.g. "LLM toggle on but provider unconfigured") is harder to enforce atomically. |
| C — Custom table `wp_agentready_profile` (one row per setting) | Scales to thousands of settings; per-row updates; rich query patterns. | Massive overkill for ~10 fields; activation needs a schema migration (which then needs a `/migration` ticket per the workflow gates); breaks the "no SQL in v0.1" simplicity bar; backup/restore is no longer "just options". |
| D — Post-meta on a synthetic CPT (`agentready_profile`) | Reuses WP's revisions / publishing / capability machinery for free; multiple profiles per site (multi-tenant). | Wildly over-engineered; introduces a CPT users will see in admin lists; v0.1 is single-profile-per-site by design; defeats FR-1's "single source of truth" framing. |
| E — JSON file in `wp-content/uploads/` | No DB writes; portable across environments. | Filesystem permissions become a support nightmare; backup story is split between DB and filesystem; capability / nonce model is uglier; loses transactional WP options API. |

## Decision

Chosen: **Option A — single versioned `wp_options` entry `agentready_context_profile`, autoloaded, with an explicit `schema_version` integer.** Concrete choices below.

### Storage shape

Option name: `agentready_context_profile` (namespaced per `phpcs.xml.dist` prefix lock).

Autoload: **yes** (`add_option(..., '', true)`). Reads on every agent-facing request — the autoload cache hit is the right trade.

Schema (v1):

```php
[
    'schema_version'             => 1,
    'exposed_cpts'               => [],        // array<string> of post-type slugs; empty = nothing exposed (FR-9)
    'exposed_statuses'           => ['publish'],
    'llm_cleanup_enabled'        => true,      // default on per #4 AC; degrades silently when AI Client unconfigured
    'llm_descriptions_enabled'   => true,      // default on per #4 AC; same degrade
]
```

Two categories of fields are deliberately **NOT** stored:

1. **Site identity** (`site_name`, `tagline`, `locale`) — these mirror `get_bloginfo('name')`, `get_bloginfo('description')`, `get_locale()`. Storing them would duplicate WP core state and create the "user updated General Settings, my profile is stale" failure mode. The Profile screen reads them live from WP options for display only.
2. **Schema-coordination posture** — detected from `is_plugin_active()` / `class_exists()` checks at read time (Yoast, Rank Math, AIOSEO). Storing the detected state would go stale the moment the admin switches SEO plugins. The Profile screen reads it live and renders read-only.

### Schema versioning + migration policy

- `schema_version` starts at `1`. Any field added that has a non-trivial default, or any field whose meaning changes, bumps the version.
- On read, if the stored `schema_version` is older than the code's current `CURRENT_SCHEMA_VERSION`, the storage class runs a `migrate( $stored, $stored_version, $current_version )` step that fills defaults / renames fields, returns the migrated array, and **writes it back via the same sanitise path**. This keeps writes idempotent with reads.
- Any **destructive** migration (drop a field, change a type) must file a `/migration` ticket per the workflow gates and produce a sibling AgDR. Adding optional fields with defaults is not destructive and does not require a migration ticket.

### Default value structure (what a fresh install reads)

```php
[
    'schema_version'             => 1,
    'exposed_cpts'               => [],          // <-- safe-by-default per FR-9
    'exposed_statuses'           => ['publish'],
    'llm_cleanup_enabled'        => true,
    'llm_descriptions_enabled'   => true,
]
```

`exposed_cpts === []` is the load-bearing default. Every module that consumes the profile MUST treat an empty `exposed_cpts` as "expose nothing." The `Storage::get_profile()` reader never injects implicit defaults like "expose `post` if empty" — that would silently break FR-9 the moment a future contributor forgets to special-case it.

### Sanitisation strategy (single callback)

`Context_Profile_Settings::sanitize( $input )`:

| Field | Sanitisation |
|-------|--------------|
| `schema_version` | Cast to int; clamp to known range (currently `1`). |
| `exposed_cpts` | Cast to array; map each entry through `sanitize_key()`; filter against `get_post_types( ['public' => true] )` so only registered public post types pass; deduplicate. |
| `exposed_statuses` | Cast to array; whitelist against `['publish', 'private', 'password', 'draft', 'pending']`; deduplicate. Always includes `'publish'` if the admin somehow saved an empty status list (no agent surface without at least one status is meaningful). |
| `llm_cleanup_enabled` | `(bool)`. |
| `llm_descriptions_enabled` | `(bool)`. |

Unknown keys are **dropped** (defence in depth — prevents `$_POST` injection of arbitrary fields). Missing keys fall back to the schema defaults.

The sanitise callback is also the **save** entry point — it runs on every `update_option('agentready_context_profile', ...)` write, regardless of caller (admin form, REST API, WP-CLI). One callback, one truth.

### Capability gate

`current_user_can( 'manage_options' )` is checked at three layers:

1. The settings page's `add_management_page()` capability argument.
2. The REST `register_setting()` `'show_in_rest' => false` (REST not exposed in v0.1 — Settings API is admin-only; future #21 / WP Abilities API integration will register a separate ability that does its own capability check).
3. The sanitise callback, as a defence-in-depth check. A non-`manage_options` user reaching the sanitise callback (e.g. via a forged nonce) gets `wp_die()` with a 403 — the option is never written.

### How modules #5–#11 read it

A small public reader on `Context_Profile_Settings`:

```php
WPContext\Admin\Context_Profile_Settings::get_profile(): array
```

returning the migrated + defaulted profile array. Modules NEVER call `get_option('agentready_context_profile')` directly — they go through the reader so future migrations / caching / instrumentation has a single chokepoint.

The post-save action `do_action( 'agentready_context_profile_saved', $new_profile, $old_profile )` fires after a successful write. `#9` (cache invalidation), `#10` (Context Score recompute), and `#11` (LLM narrative regen) attach listeners on this action.

### What this AgDR explicitly does NOT decide

- **Per-page exposure overrides** (post-meta saying "exclude this one URL from `.md`") — that's a feature in #5 / #7, not a profile-level concern. Storage shape for per-page overrides is its own AgDR if/when it ships.
- **Capability for non-admin users to *read* the profile** — v0.1 is admin-only. v0.2's MCP / abilities work decides whether to expose a read-only ability.
- **Profile import/export** — deferred to v0.1.1 alongside the agency-facing tooling.
- **Multisite-network-level profiles** — explicitly out of scope per PRD non-goals.

## Consequences

- `includes/Admin/Context_Profile_Settings.php` owns the option key, schema constants, sanitise callback, reader, and the `agentready_context_profile_saved` action dispatch.
- `includes/Admin/Context_Profile_Page.php` renders the admin screen via `@wordpress/components`, calling `register_setting()` and enqueuing the React bundle.
- `includes/Admin/Schema_Coordination_Detector.php` is read-only — runs at admin-page render time, never persists state.
- The forward references at `uninstall.php:20` and `includes/Main.php:74` resolve to this AgDR.
- Modules #5–#11 each link this AgDR in their PR description's Glossary.
- A future contributor adding a field follows the migration policy: bump `schema_version`, add a default, write the `migrate()` step, no destructive-migration ticket required if the field is additive.

## Artifacts

- Ticket: https://github.com/Ref34t/agentready/issues/4
- AgDR: this file
- PR: (linked here on creation)
