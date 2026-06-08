# Advertise agent-surface alternates through standard discovery channels

> In the context of agentready generating `/llms.txt` and per-page `.md` twins
> that nothing announces, facing the fact that only convention-aware agents find
> them, I decided to advertise them via a single cross-cutting module hooking
> `wp_head`, `send_headers`, and `robots_txt` (with two extract-to-single-source
> refactors), to achieve discovery by any agent that reads standard response
> metadata, accepting that the `robots_txt` reference is a comment (not a bespoke
> directive) and only covers WP's virtual robots.txt.

## Context

agentready correctly produces `/llms.txt` (200 `text/plain`) and per-page `.md`
companions (200 `text/markdown`), but on a managed-project staging site **nothing
advertised them**: no `Link` header, no `<head>` `<link rel="alternate">`, no
robots.txt reference. The artifacts win only with agents that already know the
`llms.txt` convention or guess that appending `.md` works — not with any agent
that merely reads response metadata (#178). P1, no AI, no external calls.

The advertising must never point at content that doesn't resolve (no 404 /
soft-404), so it has to honour the *exact same* exposure model the `.md` route
uses. Two pieces of that model were private to single callers.

## Options Considered

| Decision | Options | Chosen |
|----------|---------|--------|
| Where the logic lives | (a) scatter across `Markdown_Views` + `LlmsTxt`; (b) one new cross-cutting module | **(b)** new `WPContext\Discovery\Alternate_Advertiser` — the concern (announcing surfaces) spans both the `.md` and `/llms.txt` features, so one module hooking the three discovery points is cohesive and testable |
| `.md` URL building | (a) duplicate `Entry_Source`'s private `to_md_url`; (b) promote to a public shared mapper | **(b)** new `Markdown_Views\Url_Mapper::to_md_url()`; `Entry_Source` delegates — one definition of the rewrite contract so advertising can't drift from `/llms.txt` links |
| Exposability check | (a) re-implement the allowlist compare; (b) promote `Schema_Emitter`'s private `post_is_exposed` | **(b)** `Context_Profile_Settings::is_post_exposed()`; `Schema_Emitter` delegates — single source of truth shared with the advertiser |
| robots.txt form | (a) bespoke directive (e.g. `Llms-Txt:`); (b) a comment carrying the absolute URL | **(b)** a comment — robots.txt has no standard `llms.txt` field, and an unknown directive can trip strict parsers; the absolute URL in a comment satisfies discovery without that risk |
| Settings surface | (a) reuse `markdown_views_enabled`; (b) dedicated toggle + admin checkbox; (c) dedicated toggle, no UI | **(c)** `advertise_alternates_enabled` (default true), plumbed through defaults/migrate/sanitize and filterable, but **no admin checkbox** — honours the issue's "No UI changes" note and avoids the design-review gate; a checkbox is a clean fast-follow |

## Decision

A `WPContext\Discovery\Alternate_Advertiser` module wires three hooks, all gated
on `advertise_alternates_enabled` + the exposure model:

- `wp_head` → `<link rel="alternate" type="text/markdown">` on exposable singular
  views (only when Markdown Views is enabled, so the `.md` resolves) and
  `<link rel="alternate" type="text/plain" href="…/llms.txt">` on the front page.
- `send_headers` → `Link: <{md_url}>; rel="alternate"; type="text/markdown"`
  (appended, not clobbering other `Link` headers; skipped once `headers_sent()`).
- `robots_txt` → an absolute `/llms.txt` reference, only when `$is_public`.

Pure `build_*` / `augment_robots_txt` methods (escaping via `esc_url` for the
HTML-attribute context, `esc_url_raw` for the header / robots contexts) are split
from the hook callbacks, mirroring `LlmsTxt\Router` / `Markdown_Views\Handler`.

## Consequences

- Any agent reading standard response metadata now discovers the agent surfaces;
  convention knowledge is no longer required.
- Advertising shares one exposability predicate and one `.md` URL mapper with the
  routes that serve the content, so an advertised alternate always resolves.
- **Limitation:** the `robots_txt` filter only runs for WordPress's *virtual*
  robots.txt — a site shipping a static `robots.txt` file bypasses it. The
  `<head>` link + `Link` header are unaffected. Documented in code + here.
- Default-on means existing installs start advertising on upgrade (intended —
  discovery is the point); operators opt out via the toggle.
- A `WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt` sniff warning is
  suppressed with justification (the rule targets VIP, where the platform owns
  robots.txt; this is a distributed plugin whose purpose is agent discovery).

## Artifacts

- Issue: Ref34t/agentready#178
- `includes/Discovery/Alternate_Advertiser.php`, `includes/Markdown_Views/Url_Mapper.php`
- `includes/LlmsTxt/Entry_Source.php`, `includes/Seo/Schema_Emitter.php`,
  `includes/Admin/Context_Profile_Settings.php`, `includes/Main.php`
- Tests: `tests/Unit/Markdown_Views/Url_Mapper_Test.php`,
  `tests/Unit/Discovery/Alternate_Advertiser_Test.php`,
  `tests/Integration/Discovery/Alternate_Advertiser_Test.php`
