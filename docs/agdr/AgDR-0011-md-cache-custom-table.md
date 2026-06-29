# AgDR-0011 — Markdown cache in a dedicated `wp_agentready_md_cache` table

> In the context of caching the deterministic HTML→MD output for the Markdown Views feature (`Ref34t/mokhai-agent-readiness-kit#5`), facing the choice between post-meta, transients, and a dedicated schema, I decided to introduce a custom table `{$wpdb->prefix}agentready_md_cache` created via `dbDelta()` on plugin activation, to achieve a clean separation between agentready cache state and core WordPress tables, indexed lookup tuned for our exact access pattern, and a hash-keyed invalidation path that doesn't depend on `_meta` semantics — accepting that we own a schema, an activation-time migration, and a wp.org-review surface for the table.

## Context

- AC: <200ms TTFB on second hit, invalidate on post update or delete, deterministic output cacheable indefinitely until content changes.
- The cache key is logically `(post_id, content_hash)` where `content_hash` is a hash of the rendered HTML the walker consumes. Same content → cache hit; content change → cache miss → regenerate.
- Post-meta would bloat `wp_postmeta` with one row per cached post, and the cascade-on-delete behaviour mixes plugin cache state with core data. Acceptable but not clean.
- Transients evict on TTL even when content is unchanged; on sites without persistent object cache, every cached entry round-trips through `wp_options` (a critical hot table).
- A custom table is the conventional WordPress pattern when the access shape doesn't fit post-meta semantics and the data isn't ephemeral. We can index exactly what we read, vacuum/prune on our own cadence, and inspect cache state with a single `SELECT * FROM wp_agentready_md_cache`.
- v0.1 is **greenfield**: no existing users, no production data, no rollback concerns beyond uninstall. The full SDLC migration ceremony (separate `/migration` ticket + `templates/agdr-migration.md` shape) is therefore proportionate to production migrations — not to a v0.1 greenfield table. Activation-time creation via `dbDelta()` is folded into `#5`.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Custom table `wp_agentready_md_cache`** | Clean separation. Indexed exactly for our access pattern (`post_id`, `content_hash`). No core-table bloat. Inspectable, prunable, schema is ours. Single-row read on hot path. | Requires `dbDelta()` on activation. Adds a wp.org-review surface for the schema. Multisite needs per-site table creation. Uninstall must drop. |
| Post-meta (`_agentready_md_v1`) | Zero schema. Cascades on post delete automatically. Object-cache integrated via WP metadata API. Indexed by `meta_key`. | Bloats `wp_postmeta` (a hot core table). Mixes plugin state with core data. Limited to per-post granularity — can't easily key by `(post_id, content_hash)` for hash-validated reads without a second meta lookup. |
| Transients (`agentready_md_$post_id`) | Idiomatic WordPress. Uses object cache if present. Trivial invalidation. | TTL-based eviction loses cache on time, not on content change. On sites without persistent object cache, every entry rides `wp_options` (a hot autoload-adjacent table). Unsuitable for a cache that should mirror post lifecycle, not clock. |

## Decision

Chosen: **Custom table `{$wpdb->prefix}agentready_md_cache`**, because:

1. The access pattern `(post_id, content_hash) → markdown` doesn't map naturally to post-meta. The hash-validated read pattern (compare incoming `content_hash` against stored, regenerate if different) wants two columns we can index together, not two meta-key lookups.
2. Cache state shouldn't live in core tables. Sites with our plugin installed will accumulate ~one row per public post; isolating that in our own table makes it deletable, auditable, and clearly ours.
3. wp.org allows custom tables when justified — the convention is `dbDelta()` on activation, drop on uninstall. We follow it.
4. v0.1 is greenfield, so the SDLC migration sub-workflow ceremony (separate ticket + dedicated migration AgDR with rollback runbook) is not load-bearing. This AgDR records the rollback story inline.

## Consequences

### Schema (initial)

```sql
CREATE TABLE {$wpdb->prefix}agentready_md_cache (
  post_id        BIGINT(20) UNSIGNED NOT NULL,
  content_hash   CHAR(40)             NOT NULL,  -- sha1 of HTML input to the walker
  markdown       LONGTEXT             NOT NULL,
  generated_at   DATETIME             NOT NULL,
  walker_version VARCHAR(20)          NOT NULL,  -- bump to invalidate after walker changes
  PRIMARY KEY  (post_id),
  KEY content_hash (content_hash),
  KEY walker_version (walker_version)
);
```

- `PRIMARY KEY (post_id)` — one row per post; updates overwrite. (We do not retain history of stale conversions.)
- `KEY content_hash` — supports the read pattern: select where `post_id = X AND content_hash = Y` → cache hit; if no match → regenerate.
- `KEY walker_version` — bumping the walker version (e.g. when AgDR-0010's walker fixes a bug) lets us invalidate the entire cache via a single `DELETE FROM ... WHERE walker_version != $current`.

### Activation / uninstall

- `register_activation_hook(...)` calls a `Schema::create()` method that runs `dbDelta()` against the SQL above. `dbDelta()` is idempotent and handles upgrades.
- `register_uninstall_hook(...)` calls `Schema::drop()` which runs `DROP TABLE IF EXISTS`. On multisite, both routines iterate `get_sites()` for network-active installs.
- Schema version stored as an option (`agentready_schema_version`) for future upgrades.

### Invalidation

- `save_post` / `wp_trash_post` / `before_delete_post` / `wp_after_insert_post` hooks delete the row for the affected `post_id`.
- Walker-version bump invalidates the whole cache in one `DELETE` (see schema note).
- No TTL — the cache is content-hash-validated, not time-based.

### Rollback story (greenfield-appropriate)

- **If the table creation fails on activation**: `dbDelta()` returns errors; we surface them via `WP_Error` to the admin and abort activation. No partial state.
- **If we need to roll back the entire feature**: deactivate the plugin → `Schema::drop()` runs via uninstall (only on user-confirmed uninstall, not deactivate, per WordPress convention). To force-clear without uninstall, the user runs `wp agentready cache clear` (WP-CLI command shipped alongside the feature).
- **If a future schema migration breaks**: future migrations are gated by the SDLC migration sub-workflow (`/migration` ticket + migration AgDR with full rollback runbook). This AgDR's greenfield exemption applies **only** to the initial creation, not to subsequent column adds / drops.

### Observability

- Activation hook logs to `wp_options` (`agentready_schema_version_log`) the version transitioned to and timestamp.
- WP-CLI command `wp agentready cache stats` exposes row count, total markdown bytes stored, hit rate (counter incremented on serve), and last-prune timestamp.

### wp.org review surface

- Custom tables are an accepted pattern; the wp.org review bot flags them but does not reject. We document the table's purpose in `readme.txt` under "Privacy and Storage" so reviewers see why it exists.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#5`
- Related AgDRs: AgDR-0010 (walker — produces what we cache)
- SDLC-migration-workflow status: **inlined** into `#5` for v0.1 greenfield. Future column changes will follow the full `/migration` flow per `.claude/rules/workflow-gates.md` Gate 3a.
- Files touched (planned): `includes/markdown-views/Schema.php`, activation/uninstall registration in `agentready.php`, WP-CLI command at `includes/cli/CacheCommand.php`.
