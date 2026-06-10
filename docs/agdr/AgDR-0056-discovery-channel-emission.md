# Discovery-channel emission — ai.txt + /.well-known/ served virtually, uncached, default-on with deny-training policy default

> In the context of #172 (auto-emit the sibling AI-discovery channels so `multi_channel_discovery` climbs without hand-created files), facing the choice of how to materialise `ai.txt`, `/.well-known/llms-policy.json`, and `/.well-known/ai-layer`, I decided to serve all three **virtually via rewrite rules** (mirroring `LlmsTxt\Router`) with **no payload cache**, gated by a single default-on `discovery_channels_enabled` profile toggle, with the llms-policy access stance defaulting to `allow_inference: true` / `allow_training: false`, to achieve zero-config multi-channel discovery that defers to operator-owned static files by construction, accepting that every request recomposes a (cheap, O(1)) payload and that policy defaults embed an opinion.

## Context

- `/llms.txt` proves the serving pattern: rewrite registered on `init`, dispatch on `template_redirect`, activation/deactivation rewrite-flush lifecycle (`LlmsTxt\Router`, AgDR-0021).
- `Signal_Collector::multi_channel_signals()` currently only detects the sibling channels via `file_exists` probes; a fresh install caps at 1/5 (AgDR-0043). A live site evaluation showed hand-creating the three files moves multi-channel 1/5 → 4/5 and the overall score 66 → 72.
- Channel payloads are site **metadata** (identity, pointers, policy stance) — unlike `/llms.txt` they contain no per-post content, so the FR-9 "expose nothing by default" content contract is not implicated.
- The ticket excludes OpenAPI (needs a real API) and policy *enforcement* (declaration only).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A. Virtual serving via rewrites (chosen)** | No filesystem writes (no perms issues, nothing to clean up on uninstall); defer-to-static is automatic — a web server serves a real `ai.txt` / `.well-known/*` file before the request ever reaches WordPress; identical lifecycle to `/llms.txt` (flush on activate/deactivate satisfies the "disabling removes the channels" AC) | Extension-less `/.well-known/ai-layer` and `.json` paths depend on rewrites reaching WP (true wherever `/llms.txt` already works) |
| B. Write real files to the web root | Survives plugin deactivation; zero runtime cost | Web-root write permissions are not guaranteed; overwrite risk against operator files (explicitly forbidden by AC); uninstall/deactivation must delete files (new cleanup surface — see #189); drifts stale when the profile changes |
| C. Mirror the full `LlmsTxt\Service` shape (cached option + debounced cron regen) | Maximum pattern symmetry | Nothing to cache: payloads are O(1) compositions from `get_option`/`home_url` — no post queries, no LLM, no expensive walk. A cache adds invalidation surface (the #190 bug class) with zero win |

## Decision

Chosen: **Option A, without the Service/cache layer**, because the defer-to-static AC falls out of the architecture for free and the payloads are too cheap to cache. Concrete shape:

- `Discovery\Channel_Router` — three rewrite rules (`^ai\.txt/?$`, `^\.well-known/llms-policy\.json$`, `^\.well-known/ai-layer/?$`, all `'top'`), one query var per channel, `template_redirect` dispatch at priority 0, `Output_Buffer` hardening (#175), explicit `Content-Type` headers (`text/plain` for ai.txt; `application/json` for both `.well-known` routes — the extension-less `ai-layer` path MUST NOT inherit `text/html`).
- `Discovery\Channel_Content` — pure builders returning strings/arrays; composed per request from `blogname` / `home_url()` / the Context Profile. Deterministic, no AI, no external HTTP (parity with `/llms.txt`).
- **Defer-to-static**: if the corresponding real file exists at the web root, `maybe_serve` returns without dispatching. In the normal case the web server has already served the file and WP never sees the request; the guard covers misrouted setups. The plugin never writes files, so "no overwrite" holds by construction.
- **Soft-disable**: toggle off → the route dispatches a 404 (`X-Robots-Tag: noindex`), mirroring the AgDR-0015 Markdown Views convention — never a fall-through to the homepage with a stray query var.
- **Toggle**: single `discovery_channels_enabled` profile key (not per-channel — three booleans for three metadata routes is config surface without a use case; a per-channel split can be added additively if one materialises). Default **true** per the `markdown_views_enabled` / `advertise_alternates_enabled` "default true, explicit false to disable" convention — discovery is the plugin's point, and no content is exposed.
- **Policy stance** (`llms-policy.json`): profile keys `policy_allow_inference` (default **true**) and `policy_allow_training` (default **false**). Deny-training is the conservative default for the consequential dimension — an operator must opt IN to declaring their content available for model training, while inference/answering (the reason a site installs an AI-readiness plugin) defaults on. Both surfaced in the Context Profile so regulated operators control the declaration; profile `schema_version` 2 → 3 (additive — `migrate()`'s defaults-merge suffices).
- **Score credit**: `multi_channel_signals()` ORs each channel's `file_exists` probe with "this plugin is serving it" (module enabled), the same shape AgDR-0043 already uses to credit a sibling provider's dynamic `ai-layer`. A *served* channel counts; detection of operator-static files is preserved.

## Consequences

- Fresh install scores 4/5 on `multi_channel_discovery` out of the box (llms.txt + ai.txt + both `.well-known` routes; OpenAPI remains operator-supplied).
- Every channel request recomposes its payload — acceptable: no DB queries beyond already-cached options, no post iteration.
- Deactivating the plugin removes the channels via the rewrite flush; nothing persists on disk.
- The policy defaults are an opinion shipped as data, not code — operators change them in the profile without a release.
- UI checkbox/panel for the new keys ships as a follow-up phase (same Phase A engine / Phase B UI split as #8 and #45); the `array_key_exists` sanitize convention keeps form-less saves at defaults until then.

## Artifacts

- Ticket: Ref34t/agentready#172 · Implementation PR: (this PR)
- Prior art: AgDR-0021 (llms.txt route), AgDR-0043 (multi-channel sub-score), AgDR-0015 (soft-disable 404), AgDR-0052 (output-buffer hygiene), AgDR-0053 (alternate advertising)
