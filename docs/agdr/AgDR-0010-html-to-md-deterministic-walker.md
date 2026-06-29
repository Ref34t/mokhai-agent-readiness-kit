# AgDR-0010 — HTML→Markdown deterministic walker (in-house DOMDocument)

> In the context of building the deterministic Markdown Views pass for v0.1 (`Ref34t/mokhai-agent-readiness-kit#5`), facing the choice between an in-house DOMDocument walker, a Composer-distributed library (league/html-to-markdown), or a hybrid lean on commonmark types, I decided to build the converter in-house on top of PHP's DOMDocument with a custom node mapper, to achieve zero-Composer-dependency v0.1 plus full control over WP-specific edge cases (Gutenberg block residue, shortcode artefacts, gallery markup, oembed wrappers), accepting that we own ~1.5–2k LOC of conversion code and the bugs that come with it instead of inheriting a mature upstream.

## Context

- `#5` requires a **deterministic** HTML→MD pass — same input must always produce same output, which is the AC's contract and the precondition for safe caching.
- This is the **floor** layer. The PRD splits it from the LLM cleanup pass (`#6`) deliberately: deterministic must work without an API key, without an internet round-trip, and without per-request cost. Cost / latency / privacy / wp.org-compliance reasons are documented in the `#5` planning discussion.
- AgDR-0003 establishes WP AI Client as **graceful-degrade and optional**. Marking `#5` as LLM-dependent would contradict that and make Markdown Views unusable on sites without a configured API key.
- wp.org distributes the plugin via SVN. Composer dependencies must be committed as a vendor tree. We already accept that for `wp-scripts` (build output, AgDR-0008), but every additional vendored dep is wp.org-review surface area and a long-tail maintenance burden.
- WordPress content is rarely pure HTML. Real-world fixtures include: classic-editor shortcodes, Gutenberg block delimiters left in via `the_content` filters, oembed iframe wrappers, gallery shortcodes, contact-form-7 markup, page-builder div soup. A general HTML→MD library handles standard semantic HTML well but doesn't know what to do with WP-specific markup.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **In-house DOMDocument walker + custom node mapper** | Zero Composer deps. Full control over WP-specific markup (shortcode residue, gallery, oembed). One artefact to ship to wp.org. Test fixtures live next to the converter — direct ownership of edge-case handling. | ~1.5–2k LOC of conversion logic to write + maintain. We own the bugs, including ones a mature upstream already solved. Slower to v0.1 first cut. |
| `league/html-to-markdown` + custom converters | Mature, MIT, ~6k GitHub stars, used by Drupal core. Saves the bulk of generic-HTML conversion work. Extensible via custom `Converter` classes. | Adds a Composer dep + ~150KB vendor tree to ship via SVN. Generic mapper doesn't know WP markup — we'd still write custom converters for galleries/shortcodes/oembed. Upstream maintenance / security cadence outside our control. |
| Hybrid: `thephpleague/commonmark` types for output + custom DOM walker | Stable CommonMark output structures. Some library leverage without inheriting a generic HTML mapper. | Still adds a Composer dep, with less of the leverage that league/html-to-markdown provides. Compromise that doesn't fully buy either benefit. |
| LLM-first via WP AI Client (rejected without recording as an option here — see Context, point 2 + 3) | — | Non-deterministic. Per-request cost. Latency. Requires API key. Privacy / wp.org concerns. Conflicts with AgDR-0003's graceful-degrade contract. |

## Decision

Chosen: **In-house DOMDocument walker + custom node mapper**, because:

1. The deterministic-floor role makes the "no external dep / no API key / no internet" property load-bearing. An in-house walker keeps that property explicit in code instead of relying on a library's behaviour under degraded conditions.
2. WP-specific markup is the hard part of this converter, and no upstream library handles it. We'd write the same custom converters either way — going library-free means we don't pay the vendor-tree cost on top.
3. wp.org submission surface stays smaller. Reviewers see one converter class, one fixture corpus, one set of tests.
4. The work is bounded: ~1.5–2k LOC, well-shaped (DOM walk + mapper + 8–12 element handlers + N WP-specific converters), and high test coverage is achievable since the API is pure (HTML in, MD out).

The LLM-first option was raised mid-discussion and explicitly rejected for the deterministic floor. It belongs to `#6` (LLM cleanup pass), not `#5`.

## Consequences

- We commit to writing and maintaining a `DomToMarkdown` (or similar) converter class under `includes/markdown-views/` with its own fixture corpus under `tests/fixtures/html-to-md/`.
- The converter must be hermetic: no network calls, no `wp_remote_*`, no filesystem reads beyond fixtures during tests. Determinism is verified by golden-file tests (input HTML + expected MD output per fixture).
- Custom converters needed for WP-specific markup at minimum:
  - Gutenberg block residue (`<!-- wp:* -->` HTML comments stripped or mapped)
  - `[gallery]` / `[caption]` / common-shortcode markup
  - oembed wrappers (`<figure class="wp-block-embed">`)
  - `<figure>` + `<figcaption>` → MD equivalent
  - Inline styles → stripped
  - WordPress-emitted `<p>` wrapping of standalone images
- The converter must be safe to call on every URL on every site. Bounded memory, bounded time, no recursion explosion on pathological input — input-size and depth limits enforced.
- Future-proofing: if `#6`'s LLM cleanup pass ever becomes good enough for some node types that the deterministic pass can outsource them, the converter must expose hook points (filters) to swap or augment specific element handlers. Don't bake assumptions that the deterministic pass is the only converter.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#5`
- Related AgDRs: AgDR-0002 (Context Profile storage), AgDR-0003 (AI Client wrapper — kept for `#6`)
- Related ticket: `Ref34t/mokhai-agent-readiness-kit#6` (LLM cleanup pass — depends on `#5`'s output)
