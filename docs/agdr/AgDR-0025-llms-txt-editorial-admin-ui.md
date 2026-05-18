# AgDR-0025 — LLMs Index editorial admin UI: React panel + Settings API storage + Section pick-list

> In the context of `Ref34t/agentready#7` Phase C — AC #2 (composition includes admin-curated editorial entries), facing the choice of UI tech (Settings API form vs React panel), location (own submenu vs section of existing Tools → Context page), storage shape (separate option vs nested under Context Profile), and validation strictness (free-text section vs pick-list, URL allowlist, length caps), I decided to ship a React panel mounted as a second root on the existing Tools → Context page, posting back through the standard `register_setting` / `options.php` Settings API flow (same pattern as the Context Profile editor in AgDR-0002), backed by a separate option `agentready_llms_txt_editorial` with a constrained section pick-list (`Featured`, `Resources`, `Custom`) and free-form URLs (internal OR external), to achieve a consistent React-driven admin experience that matches the Context Profile aesthetic without inventing a second storage shape or admin destination, accepting that the React build cost is non-zero and that the section pick-list will need a follow-up if adopters want more categories.

## Context

Phase A's `Composer` and `Service` (AgDR-0021/0022/0023) already read from the option `agentready_llms_txt_editorial` and fire `agentready_llms_txt_editorial_saved` on save. The contract is set — Phase C just adds the writer. The writer is the most user-facing piece of the LLMs Index feature; choices here are about admin UX, not the runtime path.

Existing admin patterns to mirror:

- **Context Profile** (`#4` / AgDR-0002 / AgDR-0008) — Settings API option, React UI under `src/admin/context-profile/`, mounted at `tools_page_agentready-context`, submitted via `options.php`.
- **Markdown Views** (`#5` / `#6`) — pure server-rendered admin (no React), exposes its surface as Tools → Context settings via the Context Profile schema.

The editorial entries are conceptually a **child of the Context Profile** — they configure what AgentReady exposes to agents, same as `exposed_cpts` and `exposed_statuses`. Putting them on a separate admin destination would be a navigation surprise for admins who already think of "Tools → Context" as the agentready config hub.

The conflict notice (`#7` Phase B / AgDR-0024) also renders on the Tools → Context page. Co-locating the editorial editor on the same page means the conflict-resolution path is one scroll away from the "I just curated entries, why aren't they showing up?" question.

## Options Considered

| Dimension | Option A (chosen) | Option B (rejected) | Option C (rejected) |
|-----------|-------------------|---------------------|---------------------|
| **UI tech** | React panel reusing the existing `@wordpress/scripts` build | Settings API HTML form (no JS), repeater rows via vanilla-JS sprinkle | Server-rendered HTML with no client-side at all |
| **Location** | Second React root on the existing `tools_page_agentready-context` page | Separate `tools_page_agentready-llms-txt-editorial` submenu | Tabbed nav inside the Context page |
| **Storage** | Separate option `agentready_llms_txt_editorial` (already reserved by Phase A) | Nested under `agentready_context_profile.llms_txt_editorial[]` | Custom table |
| **Section field** | Pick-list of 3 (`Featured`, `Resources`, `Custom` with free-text fallback) | Free-text only | Hard-coded single section |
| **URL validation** | `esc_url_raw` + scheme allowlist (`http`, `https`, `mailto`) — internal OR external | `home_url()`-relative only | No validation |
| **Length caps** | None (editorial entries are admin intent — admin's choice) | 160-char description cap | 50-entry total cap |

### Why option A on each axis

**UI tech — React.** The repeater UX (add row, remove row, drag to reorder, inline edit) is significantly better in React than in a Settings API form with vanilla-JS row controls. The build cost is a one-time setup; once the React panel exists it costs nothing per release. Hybrid (Settings API + JS sprinkle) was a serious contender for v0.1 simplicity but the inconsistency with the Context Profile (which IS React) outweighed it.

**Location — same Tools → Context page.** Co-located with the Context Profile editor; one admin destination for all agentready config. Conflict notice renders on this page already. The alternative (separate submenu) adds a click; admins who curate entries also need to think about which CPTs are exposed — putting them on the same page reinforces the mental model.

**Storage — separate option.** Phase A's `Service::resolve_editorial()` already reads `agentready_llms_txt_editorial` directly. Nesting under Context Profile would require either changing the Phase A read path (churn) or duplicating the data (worse). Custom table is overkill for what is at most a few dozen entries.

**Section — pick-list with `Custom` escape.** The notice in Phase A's Composer groups entries by section header (`## Featured`, `## Resources`, etc.). Free-text invites typo proliferation (`## Featured` vs `## featured` vs `## FEATURED`) which breaks rendering. The pick-list keeps the common case clean; `Custom` lets admins type their own label when they need to.

**URL validation — internal OR external.** llms.txt v1 doesn't constrain URL host. Editorial entries often point at related-but-external resources (e.g. a blog hosted on a separate domain, a partner site, a GitHub repo). Restricting to `home_url()` would prevent legitimate uses. `esc_url_raw` + scheme allowlist gives enough defence against `javascript:` URLs while staying permissive.

**Length caps — none.** Auto-listed entries are capped at 160 chars by `Entry_Source::normalise_description` because they're machine-derived; editorial entries are admin intent and we honour what the admin types. The composer's `escape_inline` collapses newlines so there's no formatting escape hatch.

## Decision

Chosen: **React panel on Tools → Context, Settings API option, section pick-list with Custom fallback.**

### Storage shape

```php
// Option: agentready_llms_txt_editorial
[
    'schema_version' => 1,
    'entries' => [
        [
            'title'       => 'Pinned launch post',
            'url'         => 'https://example.com/launch',
            'description' => 'Curated landing page',
            'section'     => 'Featured',
        ],
        [
            'title'       => 'Partner integration',
            'url'         => 'https://partner.example.com/agentready',
            'description' => '',
            'section'     => 'Resources',
        ],
    ],
]
```

The Phase A `Service::resolve_editorial()` reader expects a flat array (legacy shape from when this was being written speculatively). Phase C adds a `schema_version` wrapper. The reader is updated to peel either shape:

- Versioned `{schema_version: 1, entries: [...]}` → read `entries`
- Bare `[{...}, {...}]` → treat as `entries` directly (no schema_version)

The bare-shape branch is the v0.1 forward-compat path for any test fixture or manual `wp option update` that wrote the legacy shape before Phase C shipped.

### Section pick-list

```php
public const SECTIONS = ['Featured', 'Resources', 'Custom'];
```

When `section === 'Custom'`, a sibling `section_label` field on the entry provides the actual rendered label:

```php
[
    'title'         => 'Internal docs',
    'url'           => 'https://example.com/docs',
    'section'       => 'Custom',
    'section_label' => 'For Partners',
]
```

The Composer renders `## For Partners` for that entry. When `section` is one of the three pick-list values, the value itself is the rendered label.

### Sanitization (server-side, source of truth)

`Editorial_Settings::sanitize()` is the single callback registered with `register_setting`:

1. Coerce input to an array; non-array → empty array.
2. Reset to `schema_version = 1`, ignore caller-supplied version.
3. For each entry:
   - Drop if `title` or `url` is empty.
   - `title` and `description` → `sanitize_text_field`.
   - `url` → `esc_url_raw` with `[http, https, mailto]` scheme allowlist (passed via filter), drop entry if result is empty.
   - `section` → one of `['Featured', 'Resources', 'Custom']` or default to `Featured`.
   - `section_label` → only kept when `section === 'Custom'`; `sanitize_text_field`.
4. Re-index entries (drop string keys, preserve order).
5. No total-count cap.

### REST surface

Phase C does NOT add a custom REST endpoint. The React UI submits via `options.php` (standard Settings API flow), same as the Context Profile editor. The bootstrap payload is injected via `wp_add_inline_script` containing:
- The current entries
- The pick-list values
- The settings-API nonce
- The options.php URL

The React UI maintains a local state copy, builds a hidden `<form>` on save, fills the nonce + value fields, and submits it. Same pattern AgDR-0008 / Context Profile uses.

### Hook firing

`Editorial_Settings::on_save()` is wired to `update_option_agentready_llms_txt_editorial` (and `add_option_<key>`) and fires `do_action( 'agentready_llms_txt_editorial_saved' )` — the action Phase A's `Service::register_hooks()` already subscribes to. So save → regen scheduled within the 5s debounce window → next public hit serves the updated body.

## Consequences

### Build path

`webpack.config.js` (via `@wordpress/scripts`) already discovers entry points in `src/admin/*/index.js`. Adding `src/admin/llms-txt-editorial/index.js` will auto-produce `build/admin/llms-txt-editorial.js` + `build/admin/llms-txt-editorial.asset.php`. No webpack config changes.

### Enqueue

`Context_Profile_Page::enqueue_assets()` extends to also enqueue the editorial bundle (gated by the same screen-suffix check). Two scripts on the page, two inline-bootstrap payloads, one CSS file.

### Render

`Context_Profile_Page::render()` extends to mount a second `<div id="agentready-llms-txt-editorial-root">` below the existing Context Profile root. The React panel uses `@wordpress/components` `Panel`, `PanelBody`, `Button`, `TextControl`, `SelectControl` — same component vocabulary as the Context Profile editor.

### Conflict-notice interaction

The conflict notice (Phase B) renders at `admin_notices` priority, above the page content. Editorial editor renders inside the page content, below the Context Profile editor. No collision; the notice sits above both editors.

### What this does NOT do

- We do NOT add a REST endpoint for editorial CRUD. Options.php round-trip is the form mechanism.
- We do NOT introduce per-entry post-meta. Storage is one option for all entries.
- We do NOT add drag-to-reorder in v0.1. Up/down buttons only. Drag would require a sortable library; up/down covers the common case.
- We do NOT pre-fill entries on activation. A fresh install has zero editorial entries (FR-9 empty-default holds).
- We do NOT import competitor plugin entries (per AgDR-0024 § "Migration — explicitly deferred").

## Artifacts

- Ticket: `Ref34t/agentready#7` (Phase C sub-scope)
- Related AgDRs: AgDR-0002 (Context Profile storage shape — mirrors structure), AgDR-0008 (wp-scripts for React build), AgDR-0021 (serving — reads via `Service::resolve_editorial`), AgDR-0023 (regen debounce — `agentready_llms_txt_editorial_saved` triggers it), AgDR-0024 (conflict notice — co-located on same page)
- Implementation files (planned):
  - `includes/LlmsTxt/Editorial_Settings.php` (option + sanitize + action firing)
  - `includes/Admin/Context_Profile_Page.php` (extended for the second React mount + enqueue)
  - `src/admin/llms-txt-editorial/index.js` (React panel)
  - `uninstall.php` (option already listed from Phase A)
