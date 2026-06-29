# AgDR-0014 — Admin preview via Gutenberg sidebar plugin + WP-CLI command

> In the context of letting site admins see the deterministic MD output of any post without opening the public URL (`Ref34t/mokhai-agent-readiness-kit#5` AC: *"Admin can preview the `.md` of any post from the post-editor sidebar"*), facing the choice between Gutenberg-only, Gutenberg+CLI, or Gutenberg+CLI+Tools-page surfaces, I decided to ship a Gutenberg `PluginDocumentSettingPanel` plus a `wp agentready md preview <post-id>` WP-CLI command, to achieve in-context preview for the dominant editor surface plus headless/Classic/scripting coverage, accepting that Classic-Editor-only sites get no in-admin React panel for this in v0.1 (they fall back to the CLI or to hitting `?format=md` directly).

## Context

- AC explicitly names the **post-editor sidebar** as the preview location. WordPress's modern sidebar API (`PluginDocumentSettingPanel`, `PluginSidebar`) is Gutenberg-only.
- AgDR-0008 already commits the project to wp-scripts as the React build toolchain. A Gutenberg panel is the canonical use case for that toolchain.
- Classic Editor (the plugin) still has ~9M installs but is on a slow decline. Sites that intentionally stay on Classic typically have scripted / power-user workflows where a WP-CLI command is more useful than a meta-box anyway.
- Headless-WordPress shops (decoupled front-ends consuming the REST API) don't have wp-admin as a meaningful preview surface — they want CLI / scripting access.
- The deterministic walker is a pure function: HTML in, MD out. Every preview surface (sidebar, CLI, public route) calls the same handler. We pay the engineering cost once.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Gutenberg sidebar + WP-CLI** | Covers ~90% of editing scenarios (Gutenberg) plus 100% of scripted / headless / Classic. Two surfaces, one shared backend. CLI is cheap to add (~50 LOC). | No in-admin preview for Classic-editor-only sites. |
| Gutenberg sidebar only | Strictly faithful to the AC. Minimum surface. Less code to QA. | Classic-editor + headless sites have zero in-admin preview path. CLI is missing from a product that already has CLI ambition (cf. `#10` Context Score CLI). |
| Gutenberg sidebar + WP-CLI + Tools page free-form preview | A third surface for admins debugging "why is this URL's MD weird?" without opening each post. | Third UI to design / test / translate. Significant overlap with just hitting the public URL. Net: low marginal value vs the cost. |

## Decision

Chosen: **Gutenberg sidebar + WP-CLI command**, because:

1. The AC's "post-editor sidebar" language commits us to Gutenberg's sidebar UI; that surface is built.
2. WP-CLI is a near-zero-marginal-cost addition that closes the Classic / headless / scripting gap. Other tickets in the v0.1 milestone (`#10` Context Score CLI) already commit to CLI as a first-class surface — adding `wp agentready md preview` is on-brand and on-trend with the rest of the plugin.
3. A Tools-page free-form preview is the next natural addition but is not load-bearing for v0.1. We hold it as a v0.1.1 candidate if user feedback requests it.

## Consequences

### Gutenberg sidebar panel

- File: `assets/admin/markdown-views-sidebar/` (React + wp-scripts, per AgDR-0008).
- Component: `PluginDocumentSettingPanel` named `agentready-md-preview`, registered via `registerPlugin` in the `wp.plugins` namespace. Visible on all post types where the current Context Profile says MD views are enabled.
- Content of the panel:
  - **Preview button** ("Preview Markdown view") → calls a custom REST endpoint `agentready/v1/markdown-views/preview?post=<id>`.
  - **Read-only `<pre>` block** with the returned MD, line-numbered, with a "Copy to clipboard" button.
  - **Visibility verdict line**: if Context Profile says the post is hidden, the panel shows the reason ("Hidden: post status is `draft`") instead of the MD body, so the editor knows why agents would 404 the URL.
  - **Live cache state**: cached / regenerated, content hash, generated-at timestamp. Useful for confirming the cache invalidation is firing on edits.
- The panel does not call the public route; it calls a REST endpoint protected by `current_user_can('edit_post', $post_id)`. Same walker, same cache reads, same exposure check, but the auth boundary is the admin's edit-post capability, not the public reader's.

### WP-CLI command

- Registered in `includes/cli/MarkdownViewsCommand.php` when `WP_CLI` is defined.
- Synopsis: `wp agentready md preview <post-id-or-url> [--format=raw|wrapped] [--show-meta]`.
  - `<post-id-or-url>` accepts either a numeric post ID or a permalink; the command resolves either to a post.
  - `--format=raw` (default) prints just the MD body. `--format=wrapped` prefixes a YAML-like header with title / canonical-url / generated-at — useful for piping into LLM tooling.
  - `--show-meta` adds the cache-state diagnostic line (cached vs regenerated, content hash) to stderr so stdout stays pure MD.
- The command honours exposure rules (will 404-equivalent fail with a clear error message if the post is hidden — for example "Cannot preview: post #42 is password-protected. Use `--bypass-exposure` to preview anyway." with a require-admin-cap check on that flag).
- An admin-cap check (`current_user_can('manage_options')` or `'edit_others_posts'`) gates the CLI command; CLI generally runs as the system user, but we still verify when called from the API.

### Shared backend

- Public route handler, sidebar REST endpoint, and CLI command all delegate to `MarkdownViewsService::get_markdown_for_post( $post )`.
- That service is the single integration point with the walker (AgDR-0010), the cache (AgDR-0011), and the exposure check (AgDR-0012).
- Any change to walker, cache, or exposure logic propagates to all three surfaces automatically.

### What this does NOT do

- **No Tools-page free-form preview** in v0.1. Deferred to v0.1.1 if requested.
- **No Classic Editor meta-box** in v0.1. Classic users can run the CLI, hit the public URL, or open the post in Gutenberg temporarily. If demand surfaces, a v0.1.1 ticket adds a meta-box that calls the same REST endpoint.
- **No bulk preview from the post list** (e.g. a row-action "Preview MD" link). Deferred; the CLI handles bulk workflows scriptably.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#5`
- Related AgDRs: AgDR-0008 (wp-scripts toolchain), AgDR-0010 (walker), AgDR-0011 (cache), AgDR-0012 (exposure)
- Files (planned): `assets/admin/markdown-views-sidebar/`, `includes/markdown-views/RestController.php`, `includes/cli/MarkdownViewsCommand.php`, `includes/markdown-views/Service.php`
