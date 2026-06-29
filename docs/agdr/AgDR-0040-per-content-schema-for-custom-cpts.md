# AgDR-0040 — Per-content schema emission for custom CPTs

> In the context of `Ref34t/mokhai-agent-readiness-kit#104` — *"the native gap-fill JSON-LD emitter produces only `WebSite` + `Organization` on singular requests for any CPT other than the built-in `post` (gets Article) and `page` (gets WebPage), so custom CPTs like `lesson` lose per-content schema entirely"* — surfaced during PR #102 post-merge smoke testing where a `/lessons/<slug>/` URL showed 2 JSON-LD blocks while `/hello-world/` (post) and `/about/` (page) showed 3, facing the choice between (a) defaulting every non-page singular to `Article` (broader Article coverage), (b) defaulting `post` to `Article` and everything else to `WebPage` (safer generic), (c) adding per-CPT `@type` mapping UI in the Context Profile (most flexible but biggest scope), or (d) leaving custom CPTs un-emitted by design and updating Context Score's `schemaCoordination.filled` reporting to be honest about the gap, I decided to **ship option (b) — smart defaults (`post` → `Article`, everything else → `WebPage`) — paired with an `agentready_schema_type_for_cpt` filter for plugin/theme authors to specialize** — to achieve honest per-content schema on every exposed singular without inventing a new admin surface, while leaving full custom-`@type` support (filter returning `Recipe`, `Course`, `Product`, etc., with dedicated node builders) as a v0.1.2 enhancement, accepting that v0.1.1's filter-honored return values are limited to `Article` and `WebPage` until those builders land.

## Context

The original native emitter (AgDR-0033, AgDR-0034) defined a fixed baseline of four schema.org `@type`s — `WebSite`, `Organization`, `WebPage`, `Article` — and gated each builder on a hardcoded condition:

```php
// includes/Seo/Schema_Emitter.php (pre-#104)
private static function build_webpage_node(): ?array {
    $is_page = \is_singular( 'page' );
    if ( ! $is_front_page && ! $is_page ) {
        return null;
    }
    // ...
}

private static function build_article_node(): ?array {
    if ( ! \is_singular( 'post' ) ) {
        return null;
    }
    // ...
}
```

Both builders silently returned `null` on any custom CPT. Net effect on `/lessons/<slug>/`:

| Schema node | Built-in `post` | Built-in `page` | Custom CPT (`lesson`) |
|---|---|---|---|
| WebSite | ✓ | ✓ | ✓ |
| Organization | ✓ | ✓ | ✓ |
| WebPage | — | ✓ | — |
| Article | ✓ | — | — |
| **Total emitted** | **3** | **3** | **2** |

The `schemaCoordination.filled` field that drives Context Score's `schema_coverage` sub-score (computed by `Plugin_Coverage::compute_gap()`) reports `["WebSite", "Organization", "WebPage", "Article"]` regardless of request type — overstating coverage for any site whose primary content lives in custom CPTs.

The bug was filed as Ref34t/mokhai-agent-readiness-kit#104 (P1, bug) during PR #102's post-merge smoke test on `localhost:8890`, with the user's `lesson` CPT as the concrete repro. Filing surfaced the design question this AgDR captures.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Article fallback for everything non-page**: `page` → `WebPage`, `post` + every other CPT → `Article`. | Simplest two-branch implementation. Article is a content-rich schema, so AI agents extract more from it than from WebPage. | Semantically wrong for non-blog-shaped CPTs (e.g., `product` is not an Article; `recipe` is not an Article). A wp.org Plugin Check reviewer might call this out as misleading schema. |
| **B — Smart defaults + filter (chosen)**: `post` → `Article`, every other CPT → `WebPage`. Add `agentready_schema_type_for_cpt` filter for plugin/theme authors to specialize. | Sane out-of-the-box for all CPTs (WebPage is semantically valid for any singular URL — it's the schema.org generic). Filter unlocks specialization without UI work. No new admin surface to design / test / document. Matches the existing extension-point shape (`agentready_schema_emit`, `agentready_schema_nodes` filters already exist). | v0.1.1 only honors `Article` / `WebPage` from the filter — full custom `@type` support (e.g., filter returning `Course`) requires dedicated node builders + tests; deferred to v0.1.2. Users who want `Recipe` on a `recipe` CPT have to wait. |
| C — Per-CPT mapping UI in Context Profile: dropdown next to each CPT checkbox `[Article \| WebPage \| None]`, persisted in the profile option. | Most discoverable for non-technical users. Centralized control. | Substantial UI + persistence + migration + admin-page-test scope (3–4× the diff of option B). Premature without filter-extension users to learn from. Hard to evolve into "any schema.org @type" later without a UI redesign. |
| D — Defer un-emitted custom CPTs by design + update `schemaCoordination.filled` to be honest: ship the bug as "not a bug, just incomplete reporting" and let `schema_coverage` drop below 100 on sites with un-gap-filled custom CPTs. | Zero new code in Schema_Emitter. Honest scoring. | Defeats the gap-fill emitter's purpose (which is to fill the schema gap when no SEO plugin is active). Sites with custom CPTs get neither schema NOR a usable score, which is worse than the status quo. |

## Decision

Chosen: **B — smart defaults (`post` → `Article`, everything else → `WebPage`) paired with an `agentready_schema_type_for_cpt` filter.**

Implementation shape:

```php
private static function schema_type_for_cpt( int $post_id, string $cpt ): ?string {
    $default = 'post' === $cpt ? 'Article' : 'WebPage';
    /**
     * Filter the schema.org @type emitted for a given CPT.
     * Return 'Article' or 'WebPage' to swap; return null/'' to suppress.
     * v0.1.1 honors only 'Article' and 'WebPage'; other strings are
     * treated as suppress until full custom-@type builders land in v0.1.2.
     */
    $type = \apply_filters( 'agentready_schema_type_for_cpt', $default, $cpt, $post_id );
    return ( \is_string( $type ) && '' !== $type ) ? $type : null;
}
```

Both `build_article_node()` and `build_webpage_node()` consult the resolver and return `null` when the resolved type doesn't match their own `@type`. The `Plugin_Coverage` gap baseline and the dispatch loop in `build_nodes()` stay unchanged — the per-builder gates do the work.

Reasoning:

1. **Honest defaults for every singular.** A custom CPT URL now always emits one per-content block. The user-flagged regression (3 blocks on `/hello-world/` vs 2 blocks on `/lessons/<slug>/`) is closed at the surface level.
2. **WebPage is the safe semantic generic.** Schema.org explicitly defines `WebPage` as the parent type of `Article`, `AboutPage`, `ContactPage`, etc. For any singular content URL of unknown semantic shape, `WebPage` is correct (if minimal). `Article` is semantically wrong for non-blog content (products, recipes, courses) — using it as the universal default would invite Plugin Check pushback.
3. **Filter is the right extension shape.** Filter-based extension already matches the plugin's existing API surface (`agentready_schema_emit`, `agentready_schema_nodes`). Plugin/theme authors specializing schema is exactly the kind of advanced customization that belongs in a filter, not the admin UI.
4. **UI deferral is cheap to reverse.** A v0.1.2 admin UI can be added later that writes to the same filter (via internal `add_filter`) without changing the public API. Shipping the filter first lets us learn whether users actually want the UI.
5. **`Recipe` / `Course` / `Product` / etc. coming in v0.1.2.** The filter's contract acknowledges this — strings other than `Article`/`WebPage` are treated as suppress for v0.1.1 (with a documented note). v0.1.2 will land dedicated builders for the common types and the filter will start honoring them. Filter subscribers won't need to change.

Anti-scope (explicitly NOT in v0.1.1):

- Per-CPT admin UI mapping (option C). v0.1.2 candidate, low priority until filter use proves demand.
- Full schema.org `@type` support beyond `Article` and `WebPage`. v0.1.2.
- Adjusting `Plugin_Coverage::compute_gap()` or `schemaCoordination.filled` reporting. The current `filled` claim already lists `WebPage` and `Article` — with this fix, that claim becomes more honest by construction (the emitter actually delivers per-content schema on every exposed singular). No reporting change needed.
- `Article` vs `WebPage` defaulting for THIS adopter's `lesson` CPT specifically. Defaults are `WebPage` (safer); if `lesson` semantically maps to `Course` or `LearningResource`, that's a v0.1.2 conversation.

## Consequences

**Surface changes (visible to consumers — agents, Plugin Check, debug tooling):**

- `/lessons/<slug>/` and every other singular custom-CPT URL now emits a `WebPage` node (by default).
- `/hello-world/` (built-in `post`) continues to emit `Article` — no regression on the working path.
- `/about/` (built-in `page`) continues to emit `WebPage` — no regression.
- Front page (latest-posts mode, no queried WP_Post) continues to emit `WebPage` — front-page handling is now independent of the singular CPT resolver (a separate code path in `build_webpage_node()`).

**Filter surface (new):**

- `agentready_schema_type_for_cpt( string $default, string $cpt, int $post_id ): ?string` — joins the existing `agentready_schema_emit` and `agentready_schema_nodes` filters as the schema extension surface.
- Documented limitation: v0.1.1 honors only `Article` and `WebPage` return values; others are treated as suppress.

**Internal cleanup:**

- The private `current_post_is_exposed()` helper is removed — its only caller (`build_webpage_node()`'s exposure check) now inlines the equivalent logic so the per-builder gate is single-pass. Removing dead code is included in the same PR so the diff stays tight.

**Test coverage:**

- `tests/Integration/Seo/Schema_Emitter_Test.php` gets new methods covering: WebPage emit on custom CPT (default), Article emit on built-in `post` (default), filter returning `'Article'` flipping a custom-CPT page to Article, filter returning `null` suppressing per-content emit entirely.
- Existing tests (`post` → Article, `page` → WebPage, front-page → WebPage) keep passing.

**v0.1.2 roadmap implication:**

- File a v0.1.2 ticket: "Dedicated node builders for `Recipe`, `Course`, `Product`, `Event`, and `Person`" — the highest-frequency schema.org types that AI agents extract specific signal from. The filter contract will start honoring those return values then.
- Optional companion v0.1.2 ticket: "Per-CPT schema-type dropdown in Context Profile" — only if user feedback shows the filter is too high-friction.

## Artifacts

- Ticket: [`Ref34t/mokhai-agent-readiness-kit#104`](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/104)
- Fix PR: (this AgDR is part of fix PR for #104)
- Builds on: [AgDR-0033](AgDR-0033-seo-defer-gap-fill-emitter.md) (gap-fill emitter rationale + SEO plugin deference), [AgDR-0034](AgDR-0034-native-schema-emit-toggle-and-engine-credit.md) (emit toggle + engine credit)
- Related (surfaced during the same smoke test): [#103](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/103) (profile-save → llms.txt regen), [#105](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/105) (llms.txt → .md links), [#106](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/106) (llms.txt entity decoding) — all bundled in v0.1.1.
