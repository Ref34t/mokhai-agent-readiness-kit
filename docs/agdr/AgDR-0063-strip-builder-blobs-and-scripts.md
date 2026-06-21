# Strip page-builder encoded blobs and script/style subtrees in the Markdown walker

> In the context of the HTML→Markdown walker serving agent-facing `.md` views, facing two distinct noise leaks from page-builder themes (Uncode/WPBakery base64/URL-encoded layout blobs surfacing as literal text, and Revolution Slider inline init JS captured as body text), I decided to (a) drop `<script>`/`<style>`/`<noscript>`/`<template>` subtrees in the dispatch switch and (b) strip long base64-charset runs at the **text-node** level inside `render_text()`, to achieve clean Markdown that contains only human-readable prose, accepting a conservative over-strip risk on genuinely long unbroken base64-like prose tokens.

## Context

Issue #253 reports two leaks in the `.md` output for pages built with the Uncode/WPBakery family plus Revolution Slider:

1. **Encoded builder blobs** — Uncode stores layout as URL-encoded-then-base64 shortcode payloads. When the owning shortcode isn't expanded, the encoded payload (e.g. `JTNDZGl2JTIw…`, which is base64 of `%3Cdiv%20`) survives as a long literal text token in the rendered HTML and lands verbatim in the Markdown.
2. **Slider init JS** — Revolution Slider emits inline `<script>setREVStartSize({…})</script>`. The walker's `dispatch()` has no `script`/`style` case, so these fall through to `default` → `render_children()` → the script's text child is emitted by `render_text()` as body text.

The walker is a pure, deterministic function (AgDR-0010) feeding a cache keyed on `WALKER_VERSION` + content hash. Any output change for the same input must bump `WALKER_VERSION` so cached rows regenerate.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A. String-regex strip of base64 runs in `preprocess()`** (pre-DOM) | Single chokepoint; mirrors existing shortcode strip | Runs on the raw HTML string — would match and corrupt legitimate `src="data:image/...;base64,iVBOR…"` image URIs, destroying valid images |
| **B. Token-level strip in `render_text()` + `script`/`style` drop in `dispatch()`** | Operates only on text nodes, so attribute values (data-URI `src`) are never touched; script/style drop is unambiguously correct | Two touch-points instead of one; needs a length threshold calibrated to avoid over-strip |
| **C. Decode the base64 and re-emit the inner HTML** | Could recover real content from inside the blob | Uncode payloads are layout/shortcode scaffolding, not prose — decoding yields more builder soup, not clean text; high complexity, fragile to builder-specific encodings |

## Decision

Chosen: **Option B**, because it fixes both leaks with the smallest blast radius.

- `dispatch()` gains `case 'script': case 'style': case 'noscript': case 'template': return '';` — these subtrees are dropped entirely (their text children never reach `render_text()`). This is the correct, unsurprising behaviour for an HTML→Markdown converter and also closes a minor XSS-noise vector.
- `render_text()` strips runs of **≥ 60 contiguous base64-charset characters** (`[A-Za-z0-9+/]{60,}={0,2}`) from each text node's value before whitespace collapsing. The 60-char floor is well above any natural-language word and above realistic unbroken slugs/IDs, while every observed Uncode payload is hundreds of chars. Surrounding prose in the same text node is preserved (the strip is a `preg_replace`, not a node drop).
- Operating at the text-node level means `data:` URI image sources (which live in `src` attributes, consumed by `render_image()`) are never seen by the strip and stay intact.

`WALKER_VERSION` bumps `4` → `5` so affected cached rows regenerate.

## Consequences

- Pages with Uncode/WPBakery encoded blobs and Revolution Slider now produce prose-only Markdown.
- Genuinely long (≥ 60 char) unbroken base64-like prose tokens — a pasted JWT, a raw hash, a non-data-URI base64 string in body copy — would be stripped. Judged acceptable: such tokens are noise to an LLM ingesting the `.md`, and the threshold makes false positives rare. Documented here so a future maintainer who sees a missing token knows where to look.
- Data-URI images remain intact (attribute-level, not text-level).
- One cache-wide regeneration on deploy (the `WALKER_VERSION` bump), consistent with prior walker output changes (#145).

## Artifacts

- Issue #253
- PR (this branch): `fix/GH-253-strip-builder-blobs-and-scripts`
- Code: `includes/Markdown_Views/Walker.php` (`dispatch`, `render_text`, `WALKER_VERSION`)
- Tests: `tests/Unit/Markdown_Views/Walker_Test.php`
