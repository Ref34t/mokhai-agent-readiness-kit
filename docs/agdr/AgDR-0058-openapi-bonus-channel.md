# AgDR-0058 — OpenAPI as a bonus channel in multi-channel discovery scoring

> In the context of `Ref34t/mokhai-agent-readiness-kit#212` — *"Make the OpenAPI discovery-channel advice actionable"* — facing the choice between (a) the plugin serving a minimal OpenAPI spec for the site's REST surface as a new opt-in discovery channel so the existing 5-channel scoring becomes fully achievable, vs (b) re-modelling the `multi_channel_discovery` sub-score so the four plugin-served channels (`/llms.txt`, `ai.txt`, `/.well-known/ai-layer`, `/.well-known/llms-policy.json`) score 100 on their own and OpenAPI becomes an acknowledged bonus, I decided to ship **(b)** — to achieve a sub-score a plugin-only site can actually reach 100 on, without the plugin taking on responsibility for generating and maintaining an OpenAPI document for a REST surface it does not own, accepting that OpenAPI presence no longer raises the numeric score (it is credited in the narrative only).

## Context

- Live UX test (2026-06-11): a site running all four plugin-served discovery channels scored **80/100** on multi-channel discovery, with the fix line "Add the missing OpenAPI spec discovery channel" — advice the plugin gives but cannot help the user execute. The Engine checked for `openapi.json` / `swagger.json` at `ABSPATH`, which the plugin never creates.
- The old model: 5 channels × 20 points each. OpenAPI was the only channel the plugin couldn't serve, so it permanently capped a plugin-only install at 80.
- A score the product can't help you reach is a dead-end gauge — it reads as a defect, not guidance.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Serve a minimal OpenAPI spec** | Keeps the 5-channel model; makes all 5 achievable | The plugin would own an OpenAPI document describing a REST surface it doesn't define; opt-in toggle, endpoint, spec-generation, and maintenance burden; high surface for a P3 polish ticket; an auto-generated spec for an arbitrary site's REST API is low-value and easily wrong |
| **B — OpenAPI as a bonus channel (chosen)** | Plugin-only site reaches 100 on the four channels it actually serves; OpenAPI still detected + credited in the narrative for API-exposing sites; small, contained change | OpenAPI presence no longer increases the numeric score; the "5 channels" framing in older changelog copy becomes historical |

## Decision

The four plugin-served channels each contribute 25 points (4 → 100). OpenAPI is detected and surfaced as a bonus in the narrative ("OpenAPI spec detected — bonus discovery channel for API-exposing sites") but does not change the score. The fix line for a sub-100 score names the missing plugin-served channels and clarifies OpenAPI is optional and only relevant to sites exposing an API.

The sub-score's **weight** in the composite (10) is unchanged — only its internal 0–100 computation changes. AgDR-0030 (engine) and the #22 multi-channel addition remain the governing prior art; this revises the channel-weighting introduced there.
