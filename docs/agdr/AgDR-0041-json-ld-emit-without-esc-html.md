# AgDR-0041 — Emit JSON-LD without `esc_html()`; use `JSON_HEX_TAG` for script-tag-breakout safety

> In the context of `Ref34t/agentready#118` — *"the native gap-fill JSON-LD emitter wraps `wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )` in `esc_html()` before printing it inside `<script type=\"application/ld+json\">`, which HTML-entity-encodes every quote (`"` → `&quot;`), ampersand (`&` → `&amp;`), and other reserved char inside the JSON body — invalid JSON for every standards-compliant validator (Google Rich Results Test, schema.org validator, structured-data-testing-tool)"* — surfaced on `localhost:8890` during v0.1.1 post-release verification on a `refmo`-themed site emitting baseline JSON-LD across `post`, `page`, and `lesson` CPTs, facing the choice between (a) dropping `esc_html()` and emitting `wp_json_encode()` output raw (clean but loses the breakout-prevention safety net the comment was reaching for), (b) keeping `esc_html()` and accepting the broken output (status quo), (c) replacing `esc_html()` with a targeted `str_replace( '</', '<\/', $json )` (manual escape of just the closing-tag sequence), or (d) **adding `JSON_HEX_TAG` to the `wp_json_encode()` flags so `<` and `>` are emitted as the JSON unicode escapes `\u003C` / `\u003E` at encode time, then emitting the result raw** — I decided **option (d)** — to achieve a valid JSON body with the same script-tag-breakout safety the `esc_html()` call was meant to provide, while keeping the encoding strategy inside `wp_json_encode()` (one source of truth, one set of flags) instead of layering a post-hoc string replacement that future authors could drop, accepting that the JSON body will contain `\u003C` / `\u003E` escapes inside string values (which every JSON parser decodes back to `<` / `>` transparently — schema validators, `json_decode()`, and downstream consumers all see the original characters).

## Context

The original print routine — added when the emitter first landed (PR #75, AgDR-0033) — looked like this:

```php
// includes/Seo/Schema_Emitter.php (pre-#118)
private static function print_jsonld( array $nodes ): void {
    $payload = count( $nodes ) === 1 ? $nodes[0] : $nodes;
    $json    = \wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );

    if ( ! \is_string( $json ) || '' === $json ) {
        return;
    }

    echo "\n<script type=\"application/ld+json\" data-emitted-by=\"agentready\">\n";
    echo \esc_html( $json );                     // ← the bug
    echo "\n</script>\n";
}
```

The docblock above the function articulated the intent clearly:

> JSON_UNESCAPED_SLASHES keeps URLs readable; the JSON body is escaped via `esc_html()` so any operator-injected node content can't break out of the script tag.

`esc_html()` does prevent script-tag breakout — but as a side effect it entity-encodes the JSON's own structural quotes. Every `"` in the JSON body becomes `&quot;`; every `&` becomes `&amp;`; every `<` becomes `&lt;`. The resulting payload looks like this:

```html
<script type="application/ld+json" data-emitted-by="agentready">
[{&quot;@context&quot;:&quot;https://schema.org&quot;,&quot;@type&quot;:&quot;WebSite&quot;,...
</script>
```

That is not valid JSON. The HTML5 spec for `<script type="application/ld+json">` (and the JSON-LD 1.1 spec, §6.1) requires the body to be raw JSON — the script-tag content model for `<script>` already disables HTML parsing inside the body, so entity references are NOT decoded by the parser. Google Rich Results Test, schema.org validator, and `JSON.parse()` in any browser all fail to parse the entity-encoded payload.

Net effect: `schemaCoordination.filled` in Context Score reports `["WebSite", "Organization", "WebPage", "Article"]` (or similar) and Score's narrative claims *"Native JSON-LD is enabled for WebSite, Organization, and per-content schema"* — accurate at the PHP node-array layer, completely false at the wire layer. Every site that activated AI Readiness Kit between PR #75 (v0.1.0) and this fix shipped broken structured data.

### How it survived two releases (v0.1.0 → v0.1.1) of CI

The Unit and Integration test suites both contain a `extract_json()` helper that runs `html_entity_decode( $body, ENT_QUOTES | ENT_HTML5 )` BEFORE `json_decode()`:

```php
// tests/Unit/Seo/Schema_Emitter_Test.php (pre-#118)
private function extract_json( string $output ): ?array {
    if ( ! preg_match( '#<script type="application/ld\+json" ...>\s*(.+?)\s*</script>#s', $output, $m ) ) {
        return null;
    }
    // esc_html() escapes &, <, >, ", ' — but the JSON we produce uses
    // JSON_UNESCAPED_SLASHES + nothing requires those entities, so the
    // only entity html_entity_decode reverses here is &quot; → ".
    $body    = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
    $decoded = json_decode( $body, true );
    return is_array( $decoded ) ? $decoded : null;
}
```

The comment misdiagnoses the situation — `&quot;` IS one of the entities `esc_html()` produces, and the JSON does contain quotes (every key, every string value). The helper was reverse-engineered to match the buggy emitter, so every existing test silently undoes the encoding before asserting. CI never saw the bug because no test ever asked *"is the raw body between the script tags parseable JSON?"*

This is also why the bug was discovered via live-site view-source on `localhost:8890`, not via unit-test regression. Live demo catches what mock-shaped tests miss (see also the broader pattern in user memory: *"Live wp-env demo catches real bugs"*).

## Options Considered

| Option | Pros | Cons |
|---|---|---|
| **(a) Drop `esc_html()`; emit `wp_json_encode()` output raw** | Cleanest; matches what every other JSON-LD emitter in the WP plugin ecosystem does (Yoast, Rank Math, AIOSEO, Schema Pro all emit raw). | Reopens the script-tag-breakout vector — if any node string ever contains the literal sequence `</script>`, the script tag closes early and the rest of the body bleeds into the HTML page. Unlikely in practice (site title / org name almost never contain HTML), but `wp_json_encode()` alone offers no defense. |
| **(b) Keep `esc_html()`; accept the broken output** | No code change. | Ships broken structured data forever; Context Score `schema_coverage` claim is fraudulent. Not a real option, listed for completeness. |
| **(c) Replace `esc_html()` with `str_replace( '</', '<\/', $json )`** | Targeted — only escapes the closing-tag sequence, leaves all other JSON characters as-is. Valid JSON output. | Adds a string post-process layer outside `wp_json_encode()` — a future author could drop the `str_replace` without realizing it was load-bearing for security. Two sources of truth for escaping (`wp_json_encode` flags + post-hoc replacement). |
| **(d) Add `JSON_HEX_TAG` to `wp_json_encode()` flags; emit raw** ✅ | Single source of truth (all escaping decisions live inside `wp_json_encode()` flags). `JSON_HEX_TAG` was added to PHP 5.3 specifically for this case (escape `<` and `>` inside JSON string values as JSON unicode escapes `\u003C` / `\u003E`). Output is valid JSON — `json_decode()` and every browser\'s `JSON.parse()` decode `\u003C` / `\u003E` back to `<` / `>` transparently. Schema validators see the literal `<` / `>` after parsing. | Output has `\u003C` / `\u003E` escapes inside string values (e.g. an Article `headline` of `"Why X < Y"` becomes `"Why X \u003C Y"` on the wire). Cosmetic for humans view-sourcing; functionally invisible after JSON parse. |

## Decision

Ship option **(d)**: add `JSON_HEX_TAG` to the `wp_json_encode()` flags and drop `esc_html()`.

Also add `JSON_UNESCAPED_UNICODE` so non-ASCII characters in site titles / org names (CJK, emoji, accented Latin) are emitted as literal UTF-8 rather than `\uXXXX` escapes — purely a readability win, no functional effect.

Final flags: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG`.

### Why (d) over (a)

Option (a) is fine in practice — the failure mode (literal `</script>` in a node string value) requires a site operator to put HTML markup in their site name or organization name, which is rare and self-inflicted. But "rare and self-inflicted" is exactly the shape of bug that lands six months from now in a production site and causes an obscure XSS-adjacent rendering glitch. `JSON_HEX_TAG` is free defense — the cost is `<` in some string values, never the difference between a working site and a broken one.

### Why (d) over (c)

`str_replace( '</', '<\/', $json )` works but moves the escape decision OUT of `wp_json_encode()` into a layer that's easy to drop. A future contributor who reads *"why is there a `str_replace` after `wp_json_encode`?"* may not connect it to script-tag-breakout safety. `JSON_HEX_TAG` is a named flag — `git blame` plus this AgDR explains exactly what's load-bearing.

### Test suite cleanup

The helper-level `html_entity_decode()` call in both `Unit/Seo/Schema_Emitter_Test.php` and `Integration/Seo/Schema_Emitter_Test.php` is removed in the same PR. With the emitter fixed, the helper now does the obvious thing — `json_decode( $body )` directly — and adds an explicit regression test that asserts:

1. The raw body between the script tags parses as JSON with no pre-processing.
2. The raw body contains no `&quot;`, `&amp;`, `&lt;`, `&gt;`, `&#039;` entities.
3. A node containing the literal sequence `</script>` in a string value is emitted with the `<` escape (script-tag-breakout protection).

Test #1 is the load-bearing one. The other two protect against regression to options (a) or (c) without a deliberate AgDR.

## Consequences

- **Validators pass.** Google Rich Results Test, schema.org validator, and any browser-side `JSON.parse(document.querySelector('script[type="application/ld+json"]').textContent)` now see valid JSON.
- **Context Score's claim becomes true.** The narrative *"Native JSON-LD is enabled for WebSite, Organization, and per-content schema"* matches what crawlers actually parse.
- **Backward-compatible at the consumer layer.** Any downstream code that already used `json_decode()` on the script body was already broken (returning `null`) — fixing the emitter makes those decodes succeed for the first time. No code path can regress from "working with entity-encoded body" because no such code path can exist (entity-encoded JSON doesn't parse anywhere).
- **One pattern to remember.** Future emitters that print JSON inside `<script>` tags use the same three-flag set (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG`) and emit raw. Documented in the docblock of `print_jsonld()` so it travels with the code.
- **AgDR-0033 is amended, not superseded.** AgDR-0033 defined the gap-fill emitter's scope and posture model — both intact. This AgDR replaces the escape strategy described in AgDR-0033's *"Output shape"* section.

## Artifacts

- Ref34t/agentready#118 — the bug report (filed 2026-05-24 during v0.1.1 post-release verification).
- PR #_TBD_ — `fix(#118): emit JSON-LD without esc_html, escape angle brackets via JSON_HEX_TAG`.
- `includes/Seo/Schema_Emitter.php::print_jsonld()` — the fixed printer.
- `tests/Unit/Seo/Schema_Emitter_Test.php` and `tests/Integration/Seo/Schema_Emitter_Test.php` — helper cleanup + new regression tests.
