# AgDR-0016 — Page-builder detection via per-post meta-key signature (with content-fingerprint fallback)

> In the context of needing to decide *per post* whether the Markdown Views LLM cleanup pass should auto-trigger (`Ref34t/agentready#6` AC 1: *"LLM cleanup auto-triggers when a page-builder is detected (Elementor, Divi, WPBakery, Avada, Beaver Builder)"*), facing the choice between an active-plugin probe, a per-post `post_meta` signature, a content fingerprint, or some combination, I decided to ship a per-post `post_meta`-signature detector backed by a content-fingerprint fallback, to achieve accurate per-post classification regardless of plugin-activation state, accepting that an unrecognised page-builder ships through the deterministic floor and only triggers cleanup via the quality-score gate (AgDR-0017).

## Context

- A site may have Elementor installed but only some posts rendered with it — classic-editor content on the same site should not be sent to the LLM. Plugin-activation state is therefore a wrong signal at the per-post granularity AC 1 demands.
- Each major builder writes a stable, well-known `post_meta` key on every post it renders. These keys persist whether the plugin is currently active or not, so deactivated-builder content (a common state during migrations) still classifies correctly.
- Detection must run **synchronously and cheaply** in `Service::get_markdown_for_post()` on cache miss — adding a single indexed `post_meta` lookup is acceptable; loading the whole post-meta blob and regexing is not.
- The five builders named in the AC have known signatures. A sixth builder we don't recognise should still route through cleanup *if* the quality score is poor — that's AgDR-0017's job, not this one. This AgDR's job is fast-path detection of *known* builders.
- Content-fingerprint detection (regex on `post_content` for builder shortcodes / CSS class prefixes) is a useful fallback for the rare case where a builder writes content but no canonical meta signal (e.g. WPBakery in some legacy versions stores its state inline in `post_content` rather than meta). Keep it as a cheap secondary check, not the primary mechanism.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Per-post meta-key signature (+ content fingerprint fallback)** | Accurate at per-post granularity. Survives plugin deactivation. Indexed `get_post_meta` is cheap (object-cache hit on second read). Each builder declares one canonical key. | Misses builders we haven't catalogued. New builders need a code change to extend the signature map. |
| B — Active-plugin probe (`is_plugin_active( 'elementor/elementor.php' )`) | One-liner; no per-post work. | Wrong granularity — flags classic-editor posts on a site that *also* has an Elementor page. False positives. Doesn't survive deactivation. |
| C — Content fingerprint only (regex on `post_content`) | No meta lookup; works on imported/migrated content with no builder meta. | Regex over `post_content` is more expensive than a single meta read on hot path. Pattern drift as builders evolve their markup. False positives on legitimate shortcode content. |
| D — Filter-based extension hook (`apply_filters( 'agentready_should_clean_post', $bool, $post )`) | Trivial third-party override. | Doesn't decide anything itself — needs a primary mechanism first. We can layer a filter *on top of* option A later if extension demand surfaces; doesn't need to be in the v0.1 surface. |

## Decision

Chosen: **Option A — per-post meta-key signature, with a content-fingerprint fallback for the WPBakery-legacy edge case**, because:

1. The AC is per-post by design ("when a page-builder is detected" + the implicit "on this post"). Option B's per-site granularity is structurally wrong; would produce a confusing UX where classic-editor posts on builder-active sites needlessly burn LLM budget.
2. Each builder named in the AC has a single canonical meta key. Detection is a constant-time check, falls through to "unknown" in ~1 `get_post_meta()` call. The object cache covers the second read on the same request.
3. Fallback content fingerprint stays narrow — checks `post_content` for a small set of unambiguous markers (`[vc_row`, `[av_section`, `[fusion_builder_container`). The check is regex against the *opening tag of an unambiguously-builder shortcode*, not free-form scanning.
4. The "what about builders we don't know about" case is intentionally not solved here. Unknown builders that *also* produce messy output will still trigger cleanup via AgDR-0017's quality-score gate — defence in depth, not a single point of failure.

### Detection map (initial)

```php
const PAGE_BUILDER_META_KEYS = [
    'elementor'      => '_elementor_data',
    'divi'           => '_et_pb_use_builder',
    'wpbakery'       => '_wpb_vc_js_status',     // also: '_wpb_shortcodes_custom_css'
    'avada'          => 'fusion_builder_status', // Fusion / Avada
    'beaver_builder' => '_fl_builder_enabled',
];

// Content-fingerprint fallback (in order; first match wins):
const CONTENT_FINGERPRINTS = [
    'wpbakery'       => '/\[vc_(row|column|column_text|btn)/i',
    'avada'          => '/\[(fusion_builder_container|av_section|av_textblock)/i',
    'elementor'      => '/<div [^>]*class="[^"]*\belementor\b/i',
];
```

The fingerprint list is intentionally short — only the three builders where meta-key detection is known to miss in real-world content (older WPBakery, edge-case Avada exports, Elementor-via-classic-editor hybrid posts).

### API shape

```php
namespace WPContext\Markdown_Views;

final class Page_Builder_Detector {
    /**
     * @return string|null Builder slug ('elementor', 'divi', ...) or null if none detected.
     */
    public static function detect( \WP_Post $post ): ?string;

    /** Convenience for callers that don't care which builder. */
    public static function is_page_builder_post( \WP_Post $post ): bool;
}
```

`detect()` returns the builder slug (or `null`) so downstream code can record *which* builder triggered cleanup — useful for AgDR-0017's quality-score telemetry and for future per-builder prompt tuning.

### What this AgDR explicitly does NOT decide

- **The cleanup trigger itself** — this AgDR only classifies. The "should I LLM-clean this post?" call lives in the Service, combining detection + quality score + module enablement.
- **Per-builder prompts** — initial v0.1 uses one cleanup prompt across all detected builders (defined in AgDR-0018). Per-builder prompt variants are a v0.1.1 candidate if quality data justifies them.
- **Extension hook for unknown builders** — explicitly deferred. The quality-score gate handles unknowns adequately for v0.1.
- **Gutenberg block-heavy posts as a detected class** — deliberately deferred. Gutenberg is WP core, not a page builder in the AC's sense. Block-heavy posts (deep nesting, third-party block-library CSS, image-only blocks) are expected to fall through to AgDR-0017's quality-score gate and trigger cleanup on signal, not classification. Three follow-up paths if data disagrees with that expectation: **(1) implicit forever** — quality score handles them; no Gutenberg-specific code ever needed. Most likely outcome. **(2) v0.1.1 ticket** — if production data shows block-heavy posts scoring 70–80 (above threshold, still messy), add Gutenberg as a one-line append to `PAGE_BUILDER_META_KEYS` (e.g. a `gutenberg_block_count >= N` derived signal). No migration. No new AgDR. **(3) v0.2 per-library detection** — if third-party block libraries (Kadence Blocks, GenerateBlocks, Spectra, Stackable) need per-library prompt tuning, that's a separate detection surface and gets its own AgDR.

## Consequences

- New file: `includes/Markdown_Views/Page_Builder_Detector.php` — pure static class, no state, easily unit-testable.
- `Service::get_markdown_for_post()` gains a single call into the detector after the cache-miss branch; the result feeds the "should clean?" decision (combined with AgDR-0017's quality score).
- Object cache picks up the meta read on the second invocation in the same request — no measurable hot-path cost.
- Tests: one fixture per builder (valid meta key set, invalid meta key absent), plus one fingerprint-fallback fixture per content-fingerprint pattern. Adversarial fixture: a classic-editor post containing `[vc_row]` *inside a code block* should NOT match — fingerprint regex is intentionally narrow to opening-tag form, not full string contains.
- No DB schema change.
- Adding a sixth builder later is a one-line append to the meta-key map plus an optional fingerprint entry — no migration, no AgDR.

## Artifacts

- Ticket: `Ref34t/agentready#6`
- Related AgDRs: AgDR-0017 (quality-score threshold), AgDR-0018 (no-hallucination guard), AgDR-0010 (deterministic walker)
- Files (planned): `includes/Markdown_Views/Page_Builder_Detector.php`, tests under `tests/unit/Markdown_Views/`
