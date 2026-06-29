# Expose plugin operations via the WordPress Abilities API

> In the context of #21 (agent-integration table-stakes surfaced by AgDR-0006), facing the need to let external agent stacks call into the plugin without scraping admin screens, I decided to register five operations as **core WordPress Abilities** under the `ai-readiness-kit/*` namespace (deferring MCP-adapter glue to a follow-up PR), to achieve a standards-based, REST-exposed agent surface, accepting that the richest "agent-actionable" payoff (MCP tools) lands one PR later.

## Context

The WordPress Abilities API shipped in core 6.9 (`wp_register_ability` / `wp_register_ability_category`, REST surface at `wp-abilities/v1`). Our plugin floor is 6.9, so the API is always present. AgDR-0006 (competitive analysis vs AI Layer, who shipped 33 abilities + MCP in their beta) flagged this as table-stakes and promoted FR-15 from v0.2+ to a v0.1.1 fast-follow (#21).

Five operations map onto existing service entrypoints:

| Ability | Entrypoint | Mutates |
|---|---|---|
| `ai-readiness-kit/audit-run` | `Context_Score\Service::recompute_now()` | cache |
| `ai-readiness-kit/profile-read` | `Admin\Context_Profile_Settings::get_profile()` | no |
| `ai-readiness-kit/profile-set-exposure` | new `Context_Profile_Settings::set_exposure()` | option |
| `ai-readiness-kit/llms-txt-regenerate` | `LlmsTxt\Service::regen_sync()` | cache |
| `ai-readiness-kit/md-view-preview` | `Markdown_Views\Service::regenerate_conversion_for()` + `Cleanup_Orchestrator` cached output | no |

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Core Abilities API** (chosen) | Standards-based; auto-exposed via `wp-abilities/v1`; the surface the MCP adapter consumes; future-proof | Newer API; output-schema validation can reject loose returns |
| Custom REST routes only | Full control; we already have `ai-readiness-kit/v1` controllers | Re-implements what core + MCP adapter give for free; not discoverable by agent tooling expecting Abilities |
| Wait for MCP adapter to stabilise | Single integrated delivery | Blocks a self-contained, immediately-valuable surface on an external plugin's API churn |

### Namespace: `ai-readiness-kit/*` vs `agentready/*`

The ticket said `agentready/*`, but that predates the Mokhai rebrand (AgDR-0039). Ability IDs are an agent-facing, REST-exposed, **stable** contract (renaming = breaking), directly analogous to the REST namespace (`ai-readiness-kit/v1`) and the WP-CLI base (`wp ai-readiness-kit`) — both of which use the wp.org slug per the slug-internal split (AgDR-0036/0039). Chose `ai-readiness-kit/*` for consistency with every other agent-facing surface. Internal identifiers (option keys, hooks) stay `agentready_*` as before.

**Action-name separator forced to hyphens.** Core validates ability IDs against `/^[a-z0-9-]+\/[a-z0-9-]+$/` (`WP_Abilities_Registry::register`) — exactly one slash, lowercase alphanumeric + hyphens only. Dots and underscores are rejected (the registry silently returns null). So the ticket's dotted action names (`audit.run`, `profile.set_exposure`) became `audit-run`, `profile-set-exposure`, `llms-txt-regenerate`, `md-view-preview`. This is a core constraint, not a style choice. Grouping is preserved visually via hyphen prefixes (`profile-read` / `profile-set-exposure`).

### Preview semantics: non-blocking

`md-view.preview` must return "LLM-cleaned" markdown, but cleaning is asynchronous (`Cleanup_Orchestrator` runs on cron, stores output in post-meta, gated behind admin approval). Forcing a synchronous LLM call inside an agent-invoked ability would block the agent request on a network call (seconds; can time out) and contradict the plugin's async architecture. Chose: always return deterministic markdown synchronously; surface the cached cleaned output + a `cleaned_status` field read-only; never block on the LLM. A future mutating `md-view.clean` ability can force a clean if genuinely wanted.

### Ticket entrypoint correction

The ticket named `Handler::build_response()` (returns an HTTP envelope `{status,headers,body}`) and implied `regenerate_conversion_for()` is the LLM-cleaned path. Verified: `regenerate_conversion_for()` is the **deterministic walker** (`the_content` → `Walker::convert`), and cleaned output lives in `Cleanup_Orchestrator` post-meta. The implementation uses `regenerate_conversion_for()` for deterministic markdown and `Cleanup_Orchestrator::get_state()` / `get_status()` for the cleaned read.

## Decision

Chosen: **core Abilities API, `ai-readiness-kit/*` namespace, non-blocking preview, MCP-adapter glue split to PR B**, because it delivers a standards-based agent surface immediately (usable via `wp-abilities/v1` the moment it merges) without coupling the well-understood core to an external plugin's unverified API.

Capability: all five abilities gate on `manage_options` via a shared `Abilities\Permissions::require_manage_options()` returning `WP_Error` (never `wp_die` — these run in REST/MCP request context). `profile.set_exposure` adds a public `set_exposure()` setter on `Context_Profile_Settings` (the "missing setter") that merges only `exposed_cpts`/`exposed_statuses`, re-runs the existing whitelist sanitiser, and fires the `agentready_context_profile_saved` cascade.

Output schemas are deliberately permissive (loose types, `additionalProperties` allowed) because `WP_Ability::execute()` validates the return against `output_schema` and the service payloads are richly nested.

## Consequences

- New `includes/Abilities/` subsystem (Registrar + Permissions + 4 ability classes), one-line wired from `Main::register_hooks()`.
- New public `Context_Profile_Settings::set_exposure()` — the canonical programmatic exposure write, reused by any future non-form caller.
- Abilities appear at `wp-json/wp-abilities/v1/abilities`; agents can discover + invoke them with their own credentials.
- **PR B follow-up** (sub-task of #21): conditional `Abilities\Mcp_Integration` gated on the mcp-adapter plugin's presence, its dev-dependency, a smoke test, and the MCP section of `docs/abilities.md`.
- Risk: `recompute_now()` and `md-view.preview` may touch the LLM via the narrative generator — bounded per AgDR-0032 (always returns a usable shape); preview never blocks on it.

## Artifacts

- Ticket: Ref34t/mokhai-agent-readiness-kit#21
- Branch: `feature/GH-21-mcp-abilities-api`
- Supersedes the `agentready/*` namespace wording in #21 (predates AgDR-0039).
