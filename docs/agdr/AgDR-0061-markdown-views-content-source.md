# AgDR-0061 — Markdown Views content source for non-`post_content` post types

> In the context of **Markdown Views rendering empty `.md` bodies for WooCommerce products whose copy lives in the short description (#252)**, facing **a renderer hardwired to `apply_filters('the_content', $post->post_content)`**, we decided **to introduce a generic `agentready_markdown_source_html` filter (default = the current behaviour) plus a bundled WooCommerce adapter**, to achieve **non-empty Markdown for product-shaped content without coupling the core renderer to WooCommerce**, accepting **a small amount of added indirection (one filter + one adapter class).**

## Context

`Markdown_Views/Service.php` builds the HTML it converts to Markdown from a single source — `apply_filters('the_content', $post->post_content)` — in **two** places: `convert_post()` (the live `.md` path) and `regenerate_conversion_for()` (the admin preview-ability path).

For WooCommerce products, the canonical descriptive copy frequently lives in the **short description** (`post_excerpt`, rendered via the `woocommerce_short_description` filter), not `post_content`. On a real store the product's `post_content` was 29 chars while its short description was 872 — so the `.md` view served a `text/markdown` 200 with a **0-byte body** (#252). These empty URLs are still advertised in `/llms.txt`, so an agent following the index hits blank pages.

Two cross-cutting constraints shape any fix:

1. **Cache coherence.** `content_hash()` is `sha1(post_content . post_modified_gmt . post_title)`. Any new source field pulled into the body MUST also enter the hash, or an edit that touches only the new field won't invalidate the stale (empty) cache.
2. **Two render sites must not drift.** `convert_post()` and `regenerate_conversion_for()` construct the source HTML independently today; the fix must converge them so the live view and the admin preview can never disagree.

Scope boundary: this decision addresses content that exists in `post_content` **or** `post_excerpt`. Products whose description lives **only** in a page builder or ACF/meta (both fields empty) remain empty after this change — that is the builder-decoding problem tracked in #253, explicitly out of scope here.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Hardcode a WooCommerce branch in `Service.php`** (`if post_type === 'product'`, prepend `woocommerce_short_description` + long description) | Smallest diff; no new extension surface | Couples the core renderer to WooCommerce; doesn't generalise to other CPTs, ACF, or builder content; every future "this CPT stores copy elsewhere" case reopens core |
| **B — Generic `agentready_markdown_source_html` filter (default = `the_content(post_content)`) + bundled WooCommerce adapter** that hooks it to prepend the short description | Core stays generic, deterministic, offline-by-default; matches the plugin's existing defer-to-integrations posture (SEO coordination, module toggles); ACF/builder/3rd-party authors can extend without patching core | More moving parts (one filter + one adapter class); contributors must know the source is filterable |
| **C — Always append `post_excerpt` for every post type, no filter** | Trivial; fixes the common case with no new surface | Bleeds excerpts into non-product content where that may be unwanted; still doesn't reach builder/ACF content; no extension path for other CPTs |

## Decision

Chosen: **Option B**, because it fixes #252 for the common (short-description) case while keeping the core renderer post-type-agnostic and offline-deterministic. WooCommerce becomes an **adapter** that opts into a published extension point, not a special case baked into the converter — consistent with how the plugin already defers to SEO plugins and gates every module behind the Context Profile.

### Cross-cutting requirements (apply regardless of option)

- **Extract `render_source_html(\WP_Post $post): string`** as the single source-building helper; call it from both `convert_post()` and `regenerate_conversion_for()`. The `agentready_markdown_source_html` filter is applied inside this helper.
- **Extend `content_hash()`** to incorporate every field the source can draw from (add `post_excerpt`; the WooCommerce adapter, if it reads product meta, must contribute to the cache key via a filter on the hash input so an excerpt-only or meta-only edit still invalidates).
- **Bundled WooCommerce adapter** loads only when WooCommerce is active; it prepends the rendered short description (`apply_filters('woocommerce_short_description', $product->get_short_description())`) ahead of the long description.

### Acceptance scope (feeds the #252 AC tightening)

"`.md` body is non-empty when content exists in `post_content` **or** `post_excerpt`" — **not** "products are never empty". Builder/ACF-only products are #253.

## Consequences

- New public extension point `agentready_markdown_source_html` (post-aware) becomes part of the plugin's contract — documentable, and reusable for ACF/builder adapters later.
- The cache key changes shape; a one-time cache invalidation on upgrade is expected (every `.md` re-renders once). Acceptable — the cache is regenerable.
- The Markdown-conversion-quality sub-score (#255) should observe the improvement automatically once it samples rendered bodies, giving the two tickets a shared verification path.
- Slight increase in surface area for contributors; mitigated by the helper + a short doc note.

## Artifacts

- Bug: Ref34t/mokhai-agent-readiness-kit#252 (empty product Markdown)
- Related: #253 (builder/slider noise — the builder/ACF-only case this AgDR scopes out), #255 (score should detect empty output), #256 (messy-site CI fixture that will guard this)
- Source under change: `includes/Markdown_Views/Service.php` (`convert_post`, `regenerate_conversion_for`, `content_hash`)
