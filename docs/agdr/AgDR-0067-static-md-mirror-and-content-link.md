# Static .md mirror in uploads + always-on in-content discovery link

> In the context of serving markdown to AI agents on hard-cached and statically-exported sites, facing the facts that AI fetchers strip head tags / HTTP headers and that full-page caches can serve HTML without ever invoking PHP, I decided to write publish-time static `.md` files into the uploads directory (auto-enabled only when a hard cache is detected, with a manual override) and to inject an always-on, stylesheet-hidden in-content discovery link whose anchor text carries the literal `.md` URL, to achieve agent discovery and delivery that survive both content extraction and PHP-less serving, accepting a second (non-pretty) URL for the same document and the plugin's first filesystem-write code path.

## Context

- Empirical tests (2026-07-12, real fetchers against a staging site) showed Claude WebFetch and ChatGPT browsing both strip `<head>` `link rel=alternate` tags, `meta robots`, and HTTP `Link` headers — only converted body text reaches the model. ChatGPT additionally strips `href` values but keeps anchor text.
- Hard full-page caches (plugin or host-level) can serve stored HTML without PHP; purges never invalidate `.md` URLs; fully static exports have no PHP at all.
- Host-level caches matter as much as plugin caches: Kinsta, WP Engine, Cloudflare APO et al. cache at the server/CDN layer, invisible to plugin-constant sniffing.
- Nginx dominates managed WP hosting and cannot be configured by a plugin (no `.htaccess`), so "serve the pretty `/path.md` URL statically" is not deliverable cross-host.
- `wp-content/uploads/` is served statically by every host with zero configuration.
- Mokhai already has a posture-detection precedent (`includes/Seo/` detects the active SEO plugin by class/constant).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Path-mirror cache dir + `.htaccess` rules (WP Super Cache pattern) | Pretty `/path.md` served with zero PHP | Apache-only; silently fails on nginx hosts (Kinsta, WP Engine); rewrite-rule maintenance |
| Static files in uploads, always-on for every install | Universal static serving; simplest logic | File writes + disk usage on sites that don't need it; support surface for read-only filesystems |
| **Static files in uploads, auto-enabled on cache detection (chosen)** | Universal static serving where needed; zero footprint elsewhere; manual override for edge cases | Detector complexity (plugin + host-level probe); link target must follow mirror state |
| Embed markdown inside the HTML page (hidden blob / script tag) | Single artifact, fully cache-proof | Script tags stripped by extractors; hidden blobs double page weight and are a spam signal — rejected |

## Decision

Chosen: **uploads-dir static mirror, cache-posture-gated, plus an always-on in-content link**, because:

1. **Hidden link always-on** — head/header stripping happens on every site regardless of caching, so the in-content link is the only universally surviving discovery signal. Cost is one invisible anchor.
2. **Static mirror conditional** — file generation runs only when `static_md_mode` resolves on: `auto` (default, on when the cache-posture detector finds a hard cache), `on`, or `off`. Detection covers **both** plugin caches (constant/class sniffing: WP Rocket, W3TC, WP Super Cache, LiteSpeed, Cache Enabler, …) **and host/CDN-level caches** via a loopback self-probe inspecting response headers (`x-cache`, `x-kinsta-cache`, `cf-cache-status`, `x-litespeed-cache`, `x-varnish`, `age`).
3. **Link target follows mirror state** — anchor text carries the literal URL of whichever surface is live: the uploads file when the mirror is active (worst-case-proof), otherwise the canonical `/path.md` route.
4. **Canonical URL unchanged** — llms.txt, head links, and HTTP headers keep advertising `/path.md`; the uploads URL is a delivery detail, not a second canonical.
5. **Link hiding via stylesheet class only** (offscreen/sr-only pattern) — never inline `style` or `hidden` attributes, which smarter extraction pipelines check; injected inside the content container, which main-content extractors keep.

## Consequences

- First filesystem-write code in the plugin: needs uploads-writability checks, graceful degrade to request-time rendering, and lifecycle handling (regenerate on save, delete on trash/unpublish/exposure-revoke, move on slug change).
- The `the_content` injection must be guarded (`in_the_loop() && is_main_query() && is_singular()`) so the link cannot leak into the plugin's own markdown/llms.txt rendering, which also runs `the_content`.
- Two URLs can serve the same document when the mirror is active; agents don't care, but docs should state the canonical is `/path.md`.
- The loopback probe adds a low-frequency HTTP self-request (cached posture, recheck on schedule/save), mirroring the SEO-posture pattern.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#283
- Empirical validation notes in #283 body (2026-07-12 staging fetcher tests)
