# Match the connector admin UX on stable WordPress — REST-backed Card UI, not `@wordpress/boot`

> In the context of #142 (make Tools → Context feel like the WP AI Client / Connector settings screen, after the #70 re-skin landed but still felt off), facing the fact that the connector's no-reload SPA feel comes from `@wordpress/boot` — a framework that ships only in WordPress 7.1-alpha (trunk) / the WordPress/ai plugin — I decided to reproduce the connector's *visual language and no-reload feel* with stable primitives (`@wordpress/components` Card + `TabPanel` + `@wordpress/api-fetch`) and new REST write routes, rather than adopt `@wordpress/boot`, to achieve the connector experience on the WordPress versions our users actually run, accepting that we do not get the connector's full-page admin takeover and must build two REST write controllers we didn't previously need.

## Context

#70 re-skinned the three Tools → Context React mounts against shared `@wordpress/components` primitives (shared `admin-ui.css` + a reusable `<Pill>`). It looked better but still didn't *feel* like the connector screen, for two reasons the CSS pass could not touch:

1. **It reloads on save.** The Context Profile + editorial editors submit via an `options.php` HTML form POST → full page reload. The connector never reloads.
2. **It's a panel, not an app.** Three loose mounts inside the normal wp-admin chrome vs. the connector's single cohesive surface.

Investigating the reference plugin (`WordPress/ai` v1.0.1, the official WordPress AI team plugin) revealed *why* it feels different — and the load-bearing constraint:

| Finding | Evidence |
|---|---|
| The connector "Settings → AI" page is a **full-page React SPA** | `build/pages/ai/page.php` emits its own `<!DOCTYPE html>…<body class="ai"><div id="ai-app">`, dequeues all other admin scripts/styles, and boots via `import("@wordpress/boot").then(mod => mod.init({...routes, menuItems}))` |
| The no-reload feel is **client-side routing + REST preloading**, not CSS | `page.php` registers routes/menu-items and installs `createPreloadingMiddleware` over `wp.apiFetch` |
| **`@wordpress/boot` is WordPress 7.1-alpha only** | Present at `wp-includes/js/dist/script-modules/boot/` in this wp-env, which runs `WordPress#master` = `7.1-alpha-62359`. The AI plugin's `build/modules/boot/index.min.asset.php` loads core's copy first and bundles a fallback otherwise. It is in **no stable WordPress release.** |

agentready is mid-wordpress.org review and must run on the stable WordPress its users have today, and a core principle (AgDR-0002 era) is that the Context page configures **non-AI** behaviour (CPT exposure, post statuses, schema) and therefore must render fully **without the AI plugin active**. Both constraints rule out a hard dependency on `@wordpress/boot`.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **REST-backed Card UI + in-page `TabPanel`, stable primitives** (chosen) | Delivers the connector's look (Card/header/settings-grid) **and** no-reload feel (REST `apiFetch` saves + in-place tab switching) on stable WordPress; zero new runtime dependency; preserves graceful-degrade without the AI plugin; the descriptions table already proves the REST-save pattern in-repo | Not a byte-for-byte clone — no full-page takeover; requires two **new REST write controllers** (profile + editorial) that didn't exist; a page-shell rebuild |
| **Full `@wordpress/boot` SPA** (rejected) | Truest match — identical framework, identical routing | Runs **only** on WP 7.1-alpha or where the AI plugin provides boot → breaks for ~all current users; hard-couples the entire admin UI to the AI plugin, violating the "works without the AI plugin" rule; bleeding-edge, unstable API; an AgDR-worthy dependency the product can't take pre-flip |
| **Stay on #70** (rejected) | No further work | Saves still reload; not the connector feel the request is about |
| **Vendor/bundle `@wordpress/boot` ourselves** (rejected) | Decouples from the AI plugin | Ships an experimental, unversioned core module inside our plugin; large bundle; we'd own tracking a moving trunk API — disproportionate risk for a settings page |

## Decision

Chosen: **REST-backed Card UI + in-page `TabPanel` on stable `@wordpress/components`**, because it reproduces what the request actually asks for — the connector's visual system and its no-page-reload interaction — on the WordPress our users run, without coupling the admin UI to an alpha-only framework or the AI plugin. The full-page-takeover aspect of boot is the one part we omit, and it is also the part that is actively harmful here.

Scope guardrails for #142:
- New REST write routes for profile + editorial, capability-gated (`manage_options`) + nonce-verified, **reusing the existing `Context_Profile_Settings` / `Editorial_Settings` sanitisers as the single write path** — no new validation surface, storage shape unchanged (AgDR-0002 holds).
- In-page `TabPanel` for section navigation — no client-side router library.
- The Markdown Views Gutenberg sidebar is out of scope (separate surface).

## Consequences

- The page stops POSTing to `options.php`; the `<noscript>` form-fallback path is retired in favour of the JS-required SPA-feel shell (the page already required JS for the editors).
- Two new REST controllers join `Descriptions_Rest_Controller` under the `ai-readiness-kit/v1` namespace; they need >80% coverage to pass the merge gate.
- The bootstrap payloads stay server-rendered for first paint, but saves round-trip through REST — the descriptions table's pattern generalises to the whole page.
- Revisit `@wordpress/boot` if/when it lands in a **stable** WordPress release and a full-page surface is justified; this decision is explicitly scoped to "not yet, and not for a settings page."
- Builds on #70's shared `admin-ui.css` + `<Pill>` rather than discarding them.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#142
- Supersedes the architecture left after #70 (PR #141)
- Reference: `WordPress/ai` v1.0.1 — `build/pages/ai/page.php`, `build/modules/boot/index.min.asset.php`
- Related: [[AgDR-0002-context-profile-storage-shape]], [[AgDR-0008-wp-scripts-for-react-build]], [[AgDR-0025-llms-txt-editorial-admin-ui]], [[AgDR-0029-llm-entry-descriptions-admin-ui]], [[AgDR-0031-context-score-admin-ui]]
