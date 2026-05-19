# AgDR-0029 — REST controller + admin UI shape for Phase B of #8 (LLM-powered entry descriptions)

> In the context of #8 Phase B (the admin surface on top of the Phase A engine shipped in commit `6dd0399`) and facing the question of how the admin sees, edits, and regenerates per-post descriptions, I decided to add a server-paginated REST controller (`Descriptions_Rest_Controller`) with five routes under `agentready/v1/llms-txt/descriptions/*`, a third React root on the existing Context Profile screen mounted at `#agentready-llms-txt-descriptions-root` (rendering a server-paginated table of exposed posts with per-row inline edit / regenerate buttons + a top-level "Regenerate stale" button), and surface stale-detection as an `is_stale` field on each row driven by `post_modified_gmt > _generated_for_modified_gmt`, all gated by `manage_options` (same gate as the rest of the Context Profile screen), to achieve the AC's "admin can inline-edit any generated description" + "bulk-regenerate button regenerates only stale descriptions" + "sticky behaviour" in a coherent table UI that operators already know from the editorial-entries section above it, accepting that the table can grow long on sites with hundreds of posts (mitigated by per-page = 20 default + CPT/status filters that push down to the SQL query).

## Context

Phase A (PR #68, merged 2026-05-19) shipped:
- `Description_Orchestrator` — cron-based per-post LLM pipeline; writes to `_auto` meta + bookkeeping (`_status`, `_generated_for_modified_gmt`, `_diagnostics`).
- `Description_Filter` — read-side `Entry_Source::DESCRIPTION_FILTER` subscriber. Resolution: `_manual` → `_auto` → excerpt fallback.
- `Llms_Txt_Descriptions_Command` (WP-CLI) — `status` / `backfill` / `regen <post>`.
- Two-meta-key sticky shape: `_manual` (admin override, sticky) and `_auto` (LLM-overwritable).
- `llm_descriptions_enabled` toggle in `Context_Profile_Settings::get_defaults()` — already surfaced in the React `index.js` from prior work (lines 355–372 of `src/admin/context-profile/index.js`).

What Phase A explicitly deferred to Phase B (per AgDR-0027 § "What this AgDR explicitly does NOT decide"):
1. **Admin inline-edit UI** for the `_manual` slot. WP-CLI exists; the admin's UI does not.
2. **Bulk-regenerate button** that schedules description jobs only for posts whose `_auto` is stale or missing. WP-CLI `backfill` is the bulk path; the admin's UI does not have a button.
3. **Stale-detection visibility** in the admin. Phase A stores the generation timestamp but no UI surfaces "this description is stale".

The Phase B AC items also include "[ ] Context Profile toggle 'Auto-generate entry descriptions' (default on)" — already satisfied; the toggle is wired in the existing React bundle. Phase B treats this as no-op.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — REST controller + server-paginated table + third React root on the Context Profile screen** | Reuses the Context Profile screen the admin is already on (matches AC "from the Context Profile screen"). Server pagination keeps the round-trip cheap on a 1000-post site. CPT/status filters push to SQL. Mirrors the editorial-entries pattern operators have already learned (#7 Phase C). | One more React bundle. One more REST controller. The Context Profile screen grows long — acceptable for a setup screen, painful if the admin opens it daily (which they don't). |
| B — Separate admin subpage `Tools → Context → Descriptions` | Cleaner UX; descriptions get their own screen. | Diverges from the AC text ("from the Context Profile screen"). Operators must discover the subpage. |
| C — Inline-edit on individual `post.php` (per-post sidebar panel, like Markdown Views sidebar) | Most natural editing surface — admin is already in the editor when they want to tweak the description. | Requires a Gutenberg panel + a separate UI build target. Bulk operations have no home. Doesn't match the AC's "Context Profile screen" wording. |
| D — Client-side filter on a single fetch capped at `PER_CPT_CAP` (1000) | Simpler React; no per-page round-trip. Filter UX is instant. | Loads up to 1000 rows of post-meta over a single fetch. On a small site (< 50 posts) it's trivial; on a cap-saturated site it's noticeable. The server-pagination shape is the same code complexity once you've factored CPT/status filters anyway. |

## Decision

Chosen: **Option A — REST controller + server-paginated table + third React root on the Context Profile screen.**

Reasons:

1. AC says "from the Context Profile screen." Doing anything else loses points for no UX gain on a setup-time-only surface.
2. Server pagination at 20-per-page keeps even a saturated 1000-post site responsive. The CPT/status filter pushes to the `WP_Query` predicate, not a client-side filter on a fully-fetched list.
3. The editorial-entries section already lives on this screen and uses its own React root; operators already model "Context Profile = where you configure /llms.txt". Adding a third root below is the path of least surprise.
4. Inline-edit + per-row regen + top-level bulk-regen all land in one table UI — no UI sprawl across multiple pages.

### REST routes (Phase B frozen)

All under namespace `agentready/v1`, gated by `manage_options` (same gate as the rest of the screen):

```
GET    /agentready/v1/llms-txt/descriptions?paged=N&per_page=20&cpt=post&status=(any|missing|cached|pending|failed|stale)
PATCH  /agentready/v1/llms-txt/descriptions/<post_id>             body: { manual: string }
DELETE /agentready/v1/llms-txt/descriptions/<post_id>/manual
POST   /agentready/v1/llms-txt/descriptions/<post_id>/regenerate
POST   /agentready/v1/llms-txt/descriptions/bulk-regenerate-stale body: { limit?: int }
```

#### Response shape — GET

```json
{
  "items": [
    {
      "post_id": 42,
      "title": "Introducing the export API",
      "url": "https://example.com/blog/introducing-export",
      "post_type": "post",
      "post_modified_gmt": "2026-05-19 08:00:00",
      "auto": "Documentation for the export API endpoints.",
      "manual": "",
      "resolved": "Documentation for the export API endpoints.",
      "source": "auto",
      "status": "done",
      "generated_for_modified_gmt": "2026-05-19 08:00:00",
      "is_stale": false,
      "diagnostics": { "attempted_at": "...", "output_chars": 56 }
    }
  ],
  "total": 142,
  "page": 1,
  "per_page": 20,
  "pages": 8
}
```

Field semantics:
- `source` ∈ `{manual, auto, excerpt, none}` — what the filter will actually return for this row.
- `status` mirrors `Description_Orchestrator::STATUS_*` (empty string when no LLM attempt yet).
- `is_stale` is true when `auto` is non-empty AND `post_modified_gmt > generated_for_modified_gmt`. False on a post that has never been generated (use `source === none` to detect that case).
- `diagnostics` is the JSON-decoded `_agentready_llms_description_diagnostics` blob or null.

#### Status filter values

| Value | SQL predicate |
|-------|---------------|
| `any` (default) | No additional predicate beyond exposed CPTs/statuses. |
| `missing` | No `_auto` post-meta. |
| `cached` | `_auto` non-empty AND not stale. |
| `pending` | `_status` = 'pending'. |
| `failed` | `_status` = 'failed'. |
| `needs-retry` | `_status` = 'needs-retry'. |
| `stale` | `_auto` non-empty AND `post_modified_gmt > _generated_for_modified_gmt`. |
| `manual` | `_manual` non-empty (sticky override). |

The status filter is implemented as a `meta_query` join, not a `WHERE meta_value` literal — `_generated_for_modified_gmt` comparison vs `post_modified_gmt` uses `meta_query` with `compare = '<'` per WP convention.

#### Bulk-regenerate-stale semantics

`POST /agentready/v1/llms-txt/descriptions/bulk-regenerate-stale` schedules description jobs for:
- Posts where `_auto` is non-empty AND `post_modified_gmt > _generated_for_modified_gmt` (stale)
- Posts where `_auto` is empty AND no `_manual` (missing)
- Up to the request's `limit` (default: `markdown_views_cleanup_max_per_run`, capped at 100 in the route handler to avoid request-time WP_Query overhead on a 10000-post site)

Returns `{ scheduled: N, skipped: M }`. Sticky-manual posts always end up in the `skipped` count — they don't qualify.

### Orchestrator additions

Phase B adds three public methods to `Description_Orchestrator`:

```php
public static function is_stale( \WP_Post $post ): bool;
public static function set_manual( int $post_id, string $description ): void;
public static function clear_manual( int $post_id ): void;
```

- `is_stale` — pure read; the source-of-truth predicate for the `is_stale` response field + the `stale` status filter.
- `set_manual` — sanitises (`sanitize_text_field` + truncate to 160 chars via the existing `normalise_output` pipeline) then writes to `_manual`. No status mutation — `_manual` is a separate slot.
- `clear_manual` — `delete_post_meta` on `_manual`. Existing `_auto` (if any) takes over.

### React component shape

New entry point: `src/admin/llms-txt-descriptions/index.js`, auto-discovered by `webpack.config.js`.

Component tree:

```
<DescriptionsTable>
  <FilterBar>  — CPT select, status select, search input, "Regenerate stale" button
  <Table>      — TableHead + paginated TableRows
    <DescriptionRow>     — title link, source pill, status pill, stale badge,
                           inline edit popover, regenerate button
  <Pagination> — prev / next / page number selector
```

Mirrors `src/admin/llms-txt-editorial/index.js`'s layout idioms — same `@wordpress/components` (`Button`, `TextControl`, `SelectControl`, `Notice`, `Modal`/`Popover`) so the visual language stays consistent.

### Bootstrap data extension

`Context_Profile_Page::editorial_bootstrap_data()` is the pattern. We add `descriptions_bootstrap_data()`:

```php
return array(
    'restNamespace' => Descriptions_Rest_Controller::NAMESPACE,
    'restNonce'     => wp_create_nonce( 'wp_rest' ),
    'restBase'      => '/llms-txt/descriptions',
    'exposedCpts'   => $profile['exposed_cpts'],
    'enabled'       => (bool) $profile['llm_descriptions_enabled'],
    'llmAvailable'  => Client_Wrapper::has_ai_client(),
);
```

### What this AgDR explicitly does NOT decide

- **A "Regenerate ALL" non-stale button.** The WP-CLI backfill exists for that; UI exposing "regenerate everything" is a footgun for sites with hundreds of posts. Stale-only is the right UI default.
- **Description history / audit log.** "When did this description last change?" lives in the existing diagnostic blob (`attempted_at`); a full history is out of scope.
- **Per-CPT prompt customisation surface.** AgDR-0028 declared this out of scope until telemetry justifies it.
- **Auto-regen on save_post even when sticky.** The Phase A `save_post` listener already short-circuits when `_manual` is set; this is preserved.

## Consequences

- New files: `includes/LlmsTxt/Descriptions_Rest_Controller.php`, `src/admin/llms-txt-descriptions/index.js`.
- Modified: `includes/LlmsTxt/Description_Orchestrator.php` (three new methods), `includes/Admin/Context_Profile_Page.php` (new section + enqueue method + bootstrap data), `includes/Main.php` (wire the REST controller).
- One new React bundle in `build/admin/llms-txt-descriptions.{js,asset.php}` after `npm run build`.
- The Context Profile screen renders three React roots; total bundle weight grows by ~15-20 KB gzipped.
- One additional `rest_api_init` action subscriber. Five new REST routes show up in `wp rest endpoints list`.

## Artifacts

- Ticket: `Ref34t/agentready#8` Phase B
- Pairs with: [AgDR-0027](./AgDR-0027-llm-entry-descriptions-orchestrator.md) (Phase A architecture), [AgDR-0028](./AgDR-0028-llm-entry-description-prompt.md) (prompt + budget)
- Files: `includes/LlmsTxt/Descriptions_Rest_Controller.php`, `includes/LlmsTxt/Description_Orchestrator.php` (extensions), `includes/Admin/Context_Profile_Page.php`, `includes/Main.php`, `src/admin/llms-txt-descriptions/index.js`, plus integration tests under `tests/Integration/LlmsTxt/Descriptions_Rest_Controller_Test.php`
