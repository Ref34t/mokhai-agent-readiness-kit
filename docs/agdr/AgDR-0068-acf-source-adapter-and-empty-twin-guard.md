# AgDR-0068 — ACF source adapter (via the source-html filter) + empty-twin guard

> In the context of **Markdown twins rendering empty (0-byte, `200 OK`) bodies on ACF/template-rendered pages while `llms.txt` still links them (#292)**, facing **a renderer whose source is `apply_filters('the_content', $post->post_content)` — which is empty when a theme renders content from ACF fields in templates rather than from `post_content`**, we decided **to bundle an ACF source adapter that feeds `get_field_objects()` output into the existing `mokhai_markdown_source_html` filter (the AgDR-0061 WooCommerce pattern), plus a content-delivery-agnostic guard that keeps genuinely-empty conversions out of the served surface**, to achieve **non-empty Markdown on the agency-built site class the plugin targets, without coupling the core renderer to ACF**, accepting **that ACF field order ≠ visual order, and that unrecognised template-rendered builders remain empty until a later rendered-HTML fallback lands.**

## Context

- #292 is the same bug class as #252 (the WooCommerce 0-byte-body bug already solved by AgDR-0061), now for ACF Pro / template-rendered pages. On a real client staging site, 19 of 20 published pages served empty twins.
- `Markdown_Views/Service::render_source_html()` builds its HTML from `apply_filters('the_content', $post->post_content)` and exposes `mokhai_markdown_source_html` as the extension seam. Page builders that render *through* `the_content` (Elementor, Divi, Beaver Builder, WPBakery) are already captured — the empty-twin bug is specific to content rendered **in theme templates** (raw ACF fields), which `the_content` never emits.
- AgDR-0061 established the pattern for exactly this shape: a post-type-agnostic core renderer plus a bundled, opt-in source adapter (`Woocommerce_Source`) that no-ops unless its condition holds. An ACF adapter is the consistent, precedent-following extension of that seam.
- Two failure surfaces, not one: (a) the `.md` route serves `200`/empty because `Handler::build_response()` only 404s on a `WP_Error`, and an empty-but-successful conversion is a plain empty string; (b) `llms.txt` auto-lists the page regardless. The adapter fixes the common case; the guard must still cover pages the adapter can't source (no recognised fields).
- ACF field order in `get_field_objects()` reflects field-group registration order, not the template's visual layout — so sourced content may not match reading order. Acceptable for an agent-readable text twin; noted as a known limitation.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — ACF adapter via `mokhai_markdown_source_html` + empty-twin guard** | Follows AgDR-0061 precedent exactly (one bundled adapter, core stays agnostic). Delivers real content on ACF sites. Guard closes the served-empty surface for anything the adapter can't source. | ACF-only (misses non-ACF template builders). Field order ≠ visual order. Two moving parts (adapter + guard). |
| B — Guard/exclude only (#292 recommendation) | Smallest honest fix; 404 + llms.txt exclusion + readiness advisory. | Delivers **no** content on the target site class — defeats the core promise; punts the real fix to a separate Feature. |
| C — Rendered-HTML loopback fallback | Builder-agnostic (ACF and any template builder). | Chrome-stripping heuristics are the hard part; needs the #256 messy fixture; higher risk/cost. Deferred, not rejected. |

## Decision

Chosen: **Option A**, because it reuses the proven AgDR-0061 seam to deliver content on the exact site class #292 targets, while the guard removes the "empty twin is worse than no twin" hazard for any page the adapter can't source. Option C stays on the roadmap as the builder-agnostic successor once the #256 fixture exists.

### Shape

- **`Markdown_Views/Acf_Source`** — mirrors `Woocommerce_Source`: registered unconditionally from `Main::register_hooks()`, hooks `mokhai_markdown_source_html`, no-ops unless ACF is active (`function_exists('get_field_objects')`) and the sourced HTML is empty/near-empty.
  - **Field API — `get_field_objects()`, not `get_fields()`** (F2). `get_fields()` returns `name => value` with no type metadata, so it cannot support type-aware extraction and would dump booleans, image/relationship IDs, hex colors, and select keys as body text. Extraction reads `get_field_objects()` (definition + type + value) and emits only human-readable text types; non-text types are excluded.
  - **Recursive layout coverage — Flexible Content + Group + Clone + Repeater in first-pass scope** (F1). Agency ACF Pro builds (the #292 target class) assemble pages from Flexible Content and nested Group/Clone, *not* top-level scalar fields. A top-level-only pass would leave the flagship "19 of 20 pages" case contentless. The adapter walks these container types recursively; sub-field text is rendered in field-registration order. ACF *Blocks* (Gutenberg) are out of scope — they already flow through `the_content`.
  - **Cache-hash invariant** (F3). The field payload is folded into the content hash via `mokhai_markdown_content_hash` so field edits invalidate the render. The hash input and the adapter's render input **must draw from the identical `get_field_objects()` result** (no drift — same hazard AgDR-0061 called out). This reintroduces an ACF read on every `.md` request (including cache hits), weakening AgDR-0061's "hash is cheap, no render required" invariant; accepted as bounded by the persistent object cache on the target hosts, and noted rather than hidden.
- **Empty-twin guard** (F4) — a conversion still empty (after `trim`) once all source adapters have run is excluded from the served surface via a **single empty-detection point** in `Service`, which both the route and the readiness advisory consume so neither masks the other:
  - The `.md` route 404s by having `Service` return a `WP_Error` on empty, riding the existing `is_wp_error` → `build_404_response()` branch in `Handler`. This preserves AgDR-0015's uniform-404 contract (an exposable-but-empty page becomes indistinguishable from a non-exposable one — no new "why" leak).
  - Because `get_markdown_for_post()` is shared by the public route, REST, CLI, and the admin preview/readiness path (AgDR-0014), the empty signal is surfaced as a distinct queryable state — the readiness advisory **counts** empty pages rather than being blinded by the `WP_Error`, and the auto-listed `llms.txt` entry source drops them.

## Consequences

- ACF-built pages get non-empty twins; the served surface no longer advertises empty documents.
- New bundled adapter to maintain alongside `Woocommerce_Source`; the two share the same seam and lifecycle.
- Non-ACF template-rendered builders remain empty (now correctly excluded, not falsely advertised) until Option C lands.
- Per-request ACF reads on the hot path (F3) — a real cost on cache hits, mitigated by the object cache; if it bites, revisit with a serialized-meta hash proxy.
- #292 title narrowed — the bug is "ACF-in-templates + template-rendering content", not all page builders; the ones that render through `the_content` were never affected.
- Field order = ACF registration order, not visual layout order (accepted limitation; non-empty out-of-order text beats a 0-byte body; Option C fixes visual order later).

## Artifacts

- Issue: `Ref34t/mokhai-agent-readiness-kit#292`
- Precedent: [AgDR-0061](AgDR-0061-markdown-views-content-source.md) (WooCommerce source adapter), [AgDR-0016](AgDR-0016-page-builder-detection-via-post-meta-signature.md) (builder detection), [AgDR-0063](AgDR-0063-strip-builder-blobs-and-scripts.md) (blob stripping)
- Existing seam: `includes/Markdown_Views/Service.php:146`, `includes/Markdown_Views/Woocommerce_Source.php`
- Companion evidence: spike #291; related fixture #256
