# AI Readiness Kit — WordPress Abilities

AI Readiness Kit registers five [WordPress Abilities](https://developer.wordpress.org/apis/abilities-api/) (core Abilities API, WordPress 6.9+) so AI agent stacks can call into the plugin's audit, profile, exposure, `/llms.txt`, and Markdown-view surfaces directly instead of scraping admin screens.

All abilities live under the `ai-readiness-kit` category and are exposed via core's REST surface at `GET /wp-json/wp-abilities/v1/abilities` (each with `meta.show_in_rest = true`). See AgDR-0044 for the design rationale.

## Capability

**Every** ability requires the `manage_options` capability — the same gate as the plugin's admin screens. An unauthorised caller receives `WP_Error( 'ability_invalid_permissions' )`. There are no lower-privilege read abilities in v0.1.1.

## Naming

Ability IDs follow core's required pattern `^[a-z0-9-]+/[a-z0-9-]+$` (one slash, lowercase + hyphens — **no dots or underscores**). The namespace segment is the wp.org slug `ai-readiness-kit`, consistent with the REST namespace (`ai-readiness-kit/v1`) and WP-CLI base.

## Abilities

### `ai-readiness-kit/audit-run`

Recompute the Context Score synchronously and return the full breakdown.

- **readonly:** no (writes the score cache)
- **input:** _none_ — `{}`
- **output:** `{ overall: int(0–100), sub_scores: object, narrative: object|null, schema_version: int, computed_at: string }`

### `ai-readiness-kit/profile-read`

Return the current Context Profile (exposure config + module flags).

- **readonly:** yes
- **input:** _none_ — `{}`
- **output:** the profile object — `{ exposed_cpts: string[], exposed_statuses: string[], llm_cleanup_enabled: bool, llm_descriptions_enabled: bool, schema_emit_enabled: bool, markdown_views_enabled: bool, markdown_views_cleanup_threshold: int, markdown_views_cleanup_max_per_run: int, schema_version: int }`

### `ai-readiness-kit/profile-set-exposure`

Update which custom post types and/or post statuses are exposed to agents. Writes **only** the two exposure keys; all other profile settings are preserved. Invalid CPTs (non-public) and statuses (outside the allowed set) are silently dropped by the whitelist. Saving fires the standard cascade (Context Score recompute + `/llms.txt` regen).

- **readonly:** no
- **input:** `{ exposed_cpts?: string[], exposed_statuses?: string[] }` — at least one of the two is required. `exposed_statuses` values must be one of `publish | private | password | draft | pending`.
- **output:** the saved profile (post-whitelist) — same shape as `profile-read`.

### `ai-readiness-kit/llms-txt-regenerate`

Recompose and cache the `/llms.txt` document, returning the new body.

- **readonly:** no (writes the `/llms.txt` cache)
- **input:** _none_ — `{}`
- **output:** `{ content: string, bytes: int }`

### `ai-readiness-kit/md-view-preview`

Return the deterministic Markdown view of a post, plus any cached LLM-cleaned output. **Non-blocking:** the deterministic markdown is always computed synchronously; the cleaned output is whatever the asynchronous cleanup pipeline has already produced (or `null`) — this ability never triggers a blocking LLM call.

- **readonly:** yes
- **input:** exactly one of `{ url: string }` or `{ post_id: int }`
- **output:** `{ post_id: int, exposable: bool, reason: string|null, deterministic_markdown: string, quality_score: int|null, signals: object|null, cleaned_markdown: string|null, cleaned_status: string }`
  - When the post is not exposable, `exposable` is `false` and `reason` is one of `cpt | status | password | noindex`; `deterministic_markdown` is empty. A url/post_id that resolves to no post returns `WP_Error( 'ai_readiness_kit_post_not_found' )` (404).
  - `cleaned_status` is `none` when no cleanup exists, else the orchestrator's status (`pending | done | approved | rejected | failed | needs-retry`).

## Example (WP-CLI)

```bash
# List the registered abilities
wp eval 'foreach ( wp_get_abilities() as $id => $a ) { if ( str_starts_with( $id, "ai-readiness-kit/" ) ) { echo $id . PHP_EOL; } }'

# Execute one (as an admin user)
wp eval '$r = wp_get_ability( "ai-readiness-kit/audit-run" )->execute( array() ); echo $r["overall"];' --user=admin
```

## MCP exposure

All five abilities carry `meta.mcp.public = true`, so the optional [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) exposes them on its **default MCP server** with no further configuration. When the adapter is not installed, the flag is inert — the abilities remain reachable via the core `wp-abilities/v1` REST surface. See AgDR-0045 for the design rationale.

### Enabling MCP

```bash
composer require wordpress/mcp-adapter
```

Once the adapter is active, the abilities are reachable on the default server at:

```
/wp-json/mcp/mcp-adapter-default-server
```

Per the adapter's design, `meta.mcp.public` abilities are accessed through the default server's meta-tools — `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` — rather than appearing as individually-named entries in `tools/list`.

### Verifying

```bash
# List MCP servers
wp mcp-adapter list

# Discover the exposed ai-readiness-kit/* abilities
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' \
  | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

### Named tools (future)

Exposing each ability as an individually-named `tools/list` tool (via a dedicated `create_server()` MCP server) is a possible future enhancement, deferred per AgDR-0045 — the default-server discover/execute pattern covers the v0.1.1 need.
