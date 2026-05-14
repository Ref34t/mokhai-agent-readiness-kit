# AgDR-0012 — Markdown Views exposure rules strictly inherit from Context Profile

> In the context of resolving which URLs are served vs 404'd for the Markdown Views feature (`Ref34t/agentready#5`), facing the choice between strict inheritance from the Context Profile module, an MD-specific override meta layer, or a developer-only filter hook, I decided that Markdown Views will call the Context Profile's exposure verdict 1:1 with no MD-specific overrides, to achieve the PRD's "single source of truth" architectural invariant and a single audit surface for exposure decisions, accepting that we lose the ability to hide-only-from-MD-but-not-elsewhere as a per-post toggle (and that any future need for that becomes a Context Profile feature, not an MD-views feature).

## Context

- The PRD's central architectural insight: **one Context Profile** drives every output module (robots.txt, /llms.txt, /llms-full.txt, per-URL .md, JSON-LD, well-known policy, audit, analytics). Each module is independently toggleable, but the exposure verdict for a given post is shared.
- `#4` (Context Profile admin screen) already shipped. It owns the `is_url_exposable($post, $context = 'default')` API (or equivalent — exact name TBD when integrating). Markdown Views is the first consumer of that API in production.
- AC for `#5` enumerates the exposure cases that must 404: private, password-protected, noindex, draft, pending, excluded-CPT. All of these are Context Profile concerns already.
- An override-layer option (per-post `_agentready_md_visible` meta) would let users hide a post from MD specifically while keeping it visible in llms.txt, etc. Real but uncommon use case — fragmentation cost > value for v0.1.
- A developer filter hook (`apply_filters('agentready_md_views_visible', $visible, $post)`) is the lightest-weight extension point, but is end-user-invisible and only useful for site-specific custom code.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Strict inherit from Context Profile** | One source of truth. Debuggable from one admin screen. Honours PRD's architectural invariant. Zero new admin surface. | No per-post hide-only-from-MD toggle. If a user wants that, they must hide everywhere (or escalate as a future feature). |
| Inherit + per-post MD-specific override meta | Flexible. Lets users carve out edge cases. | Two surfaces for "why is this URL 404?" — Context Profile **and** per-post meta. Fragments the model the PRD explicitly tried to unify. Adds an admin UI element to the post editor. |
| Strict inherit + developer-only filter hook | Lightest extension surface. Site-specific dev-mode overrides without admin UI. | End-users see no flexibility. Filter hooks are invisible to non-developers. Adds an API surface we must keep stable forever. |

## Decision

Chosen: **Strict inherit from Context Profile**, because:

1. The PRD's "single source of truth" claim is load-bearing for the entire product positioning ("one coherent layer"). Fragmenting exposure across modules in the first consumer would set a precedent that erodes the invariant.
2. The override-meta scenario ("hide from MD but keep in llms.txt") is real but rare. We don't have a single user request for it yet (the project hasn't shipped). Speculating that need before shipping risks building the wrong thing.
3. A future override layer can always be added later — going from strict to flexible is a forward migration that doesn't break anything. Going from flexible to strict would deprecate a user-facing toggle.
4. Debugging "why is this URL 404?" is a real support burden. Keeping the answer at "check Context Profile" instead of "check Context Profile AND per-post meta AND filter hooks" is a significant UX win.

The developer-filter-hook option remains available as a v0.1.1+ addition if a specific extensibility need emerges. We are NOT pre-emptively shipping that hook in v0.1 — adding a filter we then have to maintain forever has a real cost.

## Consequences

- Markdown Views' route handler resolves a request like:
  1. Parse URL → resolve to `$post_id` (and `404` if no post matches).
  2. Call `ContextProfile::is_url_exposable($post)` (or equivalent — the API name and signature are owned by Context Profile, set by `#4` and AgDR-0002).
  3. If `false` → return `404` with no body. **No partial content leak under any code path.**
  4. If `true` → proceed to cache lookup → walker → response.
- The MD-views module **does not** read post status, password-protected flag, robots-noindex meta, draft state, or excluded-CPT lists directly. All such checks are encapsulated behind `is_url_exposable`. If Context Profile's logic changes, MD views inherits the change for free.
- If `#4` later expands the exposure API to take a `$context` argument (e.g. `is_url_exposable($post, 'md')` so different modules can have different policies), MD views adopts that signature and passes `'md'`. The strict-inherit decision remains intact — MD views still doesn't store its own rules; the context-keyed policy lives in Context Profile.
- Documentation in `readme.txt` / the admin help text for Markdown Views explicitly states: *"Markdown Views inherits exposure rules from the Context Profile. To change which URLs are exposed, edit the Context Profile, not this module."*
- Future scope:
  - If we add per-post override later (e.g. v0.2), it goes into Context Profile's UI as a per-module column, not into MD views' code. AgDR-0012 is amended (not replaced) to record the change.
  - The developer filter hook (`agentready_md_views_visible`) is **out of scope for v0.1** but is the right place to add extensibility when we have evidence of need.

## Artifacts

- Ticket: `Ref34t/agentready#5`
- Related AgDRs: AgDR-0002 (Context Profile storage), AgDR-0011 (cache table — only fills for exposable posts)
- Related tickets: `Ref34t/agentready#4` (Context Profile admin screen, ships the `is_url_exposable` API)
