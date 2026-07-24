# Context Score — Reference

The Context Score turns "is my site agent-ready?" into a number you can improve. It is a 0–100 audit computed from seven weighted sub-scores. Every sub-score reports its raw signals and a list of plain-language reasons, so the score is never a black box: you can always see which signal cost you points and what to do about it.

The score is deterministic and runs fully locally. No AI provider is needed to compute it. (The optional narrative on top of it can use one — see [Narrative](#the-narrative), below.)

## Where to find it

- **Admin page** — Tools → Context, the Context Score panel: overall score, per-sub-score breakdown, and reasons.
- **Site Health** — a Context Score section under Tools → Site Health, so agent-readiness shows up where site owners already look.
- **WP-CLI** — `wp mokhai context-score recompute` recomputes on demand.

## How the overall score is computed

Each sub-score produces a value from 0–100. The overall score is the weighted average:

```
overall = floor( Σ (sub_score_value × weight) / 100 )
```

The weights sum to exactly 100 — asserted in self-tests, so a contributor who adjusts one weight without re-totalling gets a deterministic failure instead of a silently skewed score.

| Sub-score | Weight | One-line question it answers |
| --- | --- | --- |
| Markdown conversion quality | 25 | Is the Markdown agents actually receive clean? |
| Content readability | 15 | Do exposed entries carry curated descriptions? |
| Exposure safety | 15 | Could an agent see content you meant to hide? |
| Integration health | 15 | Is the configuration consistent, or silently degraded? |
| Discoverability | 10 | Can an agent find `/llms.txt` and something in it? |
| Schema coverage | 10 | Is structured data published alongside the content? |
| Multi-channel discovery | 10 | Are you discoverable beyond `/llms.txt`? |

Markdown conversion quality carries the largest weight on purpose: the Markdown twin is the surface agents actually consume. A perfect index pointing at broken Markdown is a well-organised failure.

## The seven sub-scores

### Markdown conversion quality (weight 25)

How clean is the deterministic walker output for the cached pages?

- Mean Markdown `quality_score` across cached rows — up to **60 points**, scaling linearly.
- Percentage of rows at or above the quality threshold (70) — up to **40 points**, scaling linearly.

Two deductions protect against pages that are technically cached but useless to an agent, each applied proportionally to how much of the sample is affected:

- **Empty or near-empty bodies** — up to **−40**. A site whose Markdown twins are 0-byte bodies loses the full 40.
- **Noise-dominated bodies** (base64 page-builder blobs, leaked script) — up to **−30**.

A site with zero cached Markdown rows scores **0** here — there is nothing to evaluate yet. The fix is cheap: visit a few `.md` URLs so the cache populates, then recompute.

### Content readability (weight 15)

One signal: description coverage. Of the entries exposed in `/llms.txt`, how many carry a curated description — a post excerpt, an editorial description, or a cached LLM-generated one? The coverage percentage *is* the sub-score value. A site with zero exposed entries scores 0: nothing to read.

To raise it: write excerpts for your important pages, or enable the LLM description pass and review its drafts. See [docs/llms-txt.md](llms-txt.md).

### Exposure safety (weight 15)

Does the site avoid leaking unpublished or sensitive content to agents?

- Exposed statuses limited to `publish` — the safe baseline, **60 points**.
- At least one CPT exposed — **40 points** (a site exposing nothing is "safe" but not useful, so safety credit requires actually participating).

Penalties apply per non-publish status you expose: a flat **−15** per risky status, capped at the 60-point baseline. Exposing four or more non-publish statuses zeroes out the safety credit entirely.

### Integration health (weight 15)

Is the configuration internally consistent?

- **LLM toggle consistency** — **60 points**. Both the LLM features and the AI Client configured, or both off: full credit. LLM features toggled on with no configured client: zero. That is the silent-degrade state this sub-score exists to catch — everything looks enabled, nothing actually works.
- **No `/llms.txt` conflicts** of any kind — **40 points**.

Opting out of the LLM stack entirely (toggles off, no client) is **not** penalised. That is a valid steady-state configuration. The only penalty target is the inconsistent state.

### Discoverability (weight 10)

Can an agent find the front door?

- `/llms.txt` cache populated — **50 points**
- At least one CPT exposed — **25 points**
- Non-zero entry count in `/llms.txt` — **15 points**
- No rewrite-shadowing conflict — **10 points**

### Schema coverage (weight 10)

Is structured data published alongside the content?

- An SEO plugin that emits JSON-LD is detected (Yoast, Rank Math, AIOSEO) — **100**.
- Mokhai's native gap-fill emitter is enabled in the Context Profile (WebSite + Organization + per-content JSON-LD on `wp_head`) — **100**.
- Neither — **60**, with a reason pointing at the one-click fix: enable Schema emission in the Context Profile.

The floor is 60, not 0: missing schema makes a site poorer for agents, not broken.

### Multi-channel discovery (weight 10)

`/llms.txt` is the de-facto front door, but it is not the only discovery surface agents check. Four plugin-served channels score, **25 points each**:

- `/llms.txt` cache populated (re-credits the discoverability signal — intentional)
- `ai.txt` at the WordPress install root
- `/.well-known/ai-layer` (a file, or a registered sibling plugin providing it)
- `/.well-known/llms-policy.json`

A fifth surface — an OpenAPI/Swagger spec at the install root (`openapi.json`, `openapi.yaml`, or `swagger.json`) — is detected and credited in the narrative as a **bonus** channel for API-exposing sites, but it does **not** change the score (AgDR-0058 / #212). The plugin can't generate an OpenAPI spec for a REST surface it doesn't own, so gating the score on it would be a dead-end gauge.

When a sibling plugin provides a surface, the reason names the plugin and points at its admin page, so the fix is one click away rather than a scavenger hunt.

## The narrative

On top of the numeric breakdown, the score panel can show a short narrative explaining the score and the highest-leverage fixes.

- With an AI provider configured (via the optional WP AI Client), the narrative is LLM-written.
- Without one, a **rule-based narrative** renders from the same reasons — the feature does not silently disappear.
- A narrative guard constrains the LLM output to the numbers that actually exist in the breakdown (sub-score values and weights). The narrative may explain your score; it may not invent one.

## Reading the score honestly

The Context Score measures readiness, not popularity. A 90 does not mean agents visit your site; it means that when one does, it will find a discovery index, clean Markdown, structured data, and no leaked drafts. Treat it like a lighthouse score for the agent web: a number to improve deliberately, one named signal at a time.
