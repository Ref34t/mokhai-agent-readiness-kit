# Expose abilities to MCP via `meta.mcp.public`, not a custom server

> In the context of #131 (PR B of #21 — making the plugin's abilities consumable by MCP agent stacks), facing the choice of how to integrate the optional `WordPress/mcp-adapter`, I decided to flag each ability with `meta.mcp.public = true` so the adapter's default MCP server exposes them, rather than building a custom `create_server()` server or a `class_exists`-gated glue class, to achieve a minimal, additive, inert-without-adapter integration, accepting that abilities surface via the default server's discover/execute meta-tools instead of as individually-named `tools/list` entries.

## Context

PR A (#130) registered five abilities under `ai-readiness-kit/*` on the core Abilities API, reachable via `wp-abilities/v1` REST. #131 adds MCP exposure through the optional `WordPress/mcp-adapter` (`composer require wordpress/mcp-adapter`, class `WP\MCP\Core\McpAdapter`, default server at `/wp-json/mcp/mcp-adapter-default-server`).

The adapter offers two exposure mechanisms:

1. **`meta.mcp.public = true`** on the ability registration → the ability is discoverable + executable on the adapter's **default** server, via its `discover-abilities` / `get-ability-info` / `execute-ability` tools.
2. **`$adapter->create_server( id, namespace, route, name, desc, version, [Transport…], ErrorHandler, ObservabilityHandler, [ability ids], [], [] )`** on the `mcp_adapter_init` hook → a **dedicated** server exposing each ability as an individually-named `tools/list` entry.

AgDR-0044 § "PR B follow-up" had sketched a conditional `Abilities\Mcp_Integration` class gated on `class_exists`. Verifying the adapter's actual API showed that's unnecessary.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **`meta.mcp.public` (chosen)** | One additive metadata key per ability; inert when the adapter is absent (no gate needed); zero new classes; the adapter's documented default path | Abilities surface via the default server's discover/execute meta-tools, not as named `tools/list` entries |
| Custom `create_server()` server | Each ability appears as a first-class named MCP tool | Needs the adapter's full transport / error-handler / observability-handler wiring (FQCNs + required args) — more code, more coupling to the adapter's surface, only runs under `mcp_adapter_init` |
| Conditional `Mcp_Integration` class (AgDR-0044 sketch) | Explicit gating | Redundant — `meta.mcp.public` is already inert without the adapter; the class would just re-implement what the meta flag does for free |

## Decision

Chosen: **`meta.mcp.public = true` on all five abilities**, because it's the smallest change that achieves MCP exposure, carries no runtime cost or risk when the adapter isn't installed, and avoids coupling to `create_server()`'s multi-argument transport/observability signature. This **supersedes** AgDR-0044's PR-B-glue note (no `Mcp_Integration` class is built).

## Consequences

- Single edit to `includes/Abilities/Registrar.php` (add `'mcp' => array( 'public' => true )` to each ability's `meta`). No new runtime class.
- When the `mcp-adapter` is active, all five abilities are discoverable + executable on `/wp-json/mcp/mcp-adapter-default-server`; when it's absent, the meta is inert.
- Verified by `tests/Integration/Abilities/Mcp_Exposure_Test.php` (contract: every ability has `meta.mcp.public === true`) plus a live wp-env smoke against the installed adapter.
- **Possible future enhancement** (file separately if wanted): a dedicated `create_server()` server exposing each ability as a named `tools/list` tool, for agent stacks that prefer per-tool discovery over the discover/execute pattern.

## Artifacts

- Ticket: Ref34t/agentready#131 (PR B of #21)
- Builds on #130 (PR A — abilities core, AgDR-0044)
