# AgDR-0019 — Message-substring classification of WP AI Client `WP_Error` returns

> In the context of wiring `Client_Wrapper::call_provider` to the real WP AI Client (the deferred half of AgDR-0003), facing the fact that `wp_ai_client_prompt()->generate_text()` returns `WP_Error` with a coarse code (`prompt_builder_error` / `prompt_prevented`) and the wrapped underlying-provider message as a free-form string, I decided to classify the outcome by case-insensitive substring matching against a small set of rate-limit markers (`rate limit`, `rate-limit`, `rate_limit`, `429`, `quota`, `too many requests`, `exceeded`), routing matches to `Rate_Limit_Error` and everything else to `Network_Error`, to achieve preservation of the wrapper's existing retry / deferred-retry contract without depending on private WP AI Client internals, accepting that the heuristic is fragile against provider-specific message wording changes — production telemetry in v0.1.x drives whether finer classification (auth, content filter, model-unavailable) needs distinct exception types.

## Context

`Client_Wrapper` (AgDR-0003) is built around two typed exceptions and a generic catch-all:

```
catch ( Rate_Limit_Error $e )  → queue deferred retry, no immediate retry
catch ( Network_Error $e )     → immediate in-request retry (one), then deferred
catch ( \Throwable $e )        → break out as 'unknown', deferred retry
```

The WP AI Client's `wp_ai_client_prompt()->generate_text()` is `string|WP_Error`. Errors do not surface as typed exceptions at the public surface — they collapse to two `WP_Error` codes:

| WP_Error code | Source |
|---|---|
| `prompt_builder_error` | The underlying PHP AI Client threw an exception during prompt construction or send. Message is `$e->getMessage()` from the wrapped exception. |
| `prompt_prevented` | A `Prompt_Prevented_Exception` from filter / policy / capability gate. |

The PHP AI Client below WP AI Client does throw typed exceptions (rate-limit, auth, model-unavailable, etc.), but those are caught and stringified before reaching our caller. To recover useful classification we must inspect the free-form message string.

This is the same trade-off the WP HTTP API has lived with for years: provider details get squashed into a `WP_Error` and callers grep the message.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Message-substring classification (rate-limit markers → `Rate_Limit_Error`, all else → `Network_Error`)** | Deterministic, no provider-specific code paths, zero dependencies on PHP AI Client internals, easy to extend the marker list. | Fragile against message wording changes by upstream providers. False negatives (a rate-limit phrased differently) end up on the `Network_Error` path, which retries in-request once — slightly more provider load. False positives are essentially impossible. |
| B — Inspect the wrapped exception via reflection / `previous` chain on the `WP_Error` `data` field | Most accurate; recovers the original typed PHP AI Client exception. | Couples to internal WP AI Client structure that is not part of the public API contract; brittle across versions. UPGRADE.md explicitly warns against depending on `WordPress\AI_Client\AI_Client` internals. |
| C — Always treat as `Network_Error` (give up on classification) | Simplest. | Every rate-limit triggers an in-request retry — wastes a second provider call against an already-rate-limited account, accelerating the next hit. Worse provider citizenship. |
| D — Skip WP AI Client's `_With_WP_Error` builder; use the exception-throwing builder directly | Get the typed exceptions cleanly, no string-grep needed. | UPGRADE.md says only `wp_ai_client_prompt()` (the WP_Error variant) survives into WP core 7.0. The exception-throwing builder is deprecated and not in core. |

## Decision

Chosen: **Option A — message-substring classification.** Reasons:

1. The exception-throwing builder is explicitly deprecated by upstream (UPGRADE.md). Building against it would force a hard pivot when WP 7.0 final ships.
2. The classification is mechanically simple and the marker set is small enough to maintain. Adding `quota` or `429` if a new provider phrases its rate-limit differently is a one-line change.
3. False-positive risk is minimal — these markers are unambiguous in the rate-limit class. The wider risk is false negatives, and the cost there is "one extra retry" not "wrong behaviour".
4. Production telemetry in v0.1.x is the right place to learn whether finer-grained classes (auth, content filter, model-not-available) need their own exception types. We don't have that data yet; speculative class proliferation is premature.

### Marker list (frozen for v0.1)

```php
private const RATE_LIMIT_MARKERS = array(
    'rate limit',
    'rate-limit',
    'rate_limit',
    '429',
    'quota',
    'too many requests',
    'exceeded',
);
```

Comparison is over `strtolower()` of the WP_Error message; any markers match on substring.

### `has_ai_client()` detector also corrected

AgDR-0003 stipulated detection via `function_exists( 'wp_ai_client' ) || class_exists( '\WP_AI_Client' )` — names from the pre-release WP AI Client surface that did not survive into the shipped API. The actual entry point on WP 7.0+ (and via the `wordpress/wp-ai-client` backport on 6.x) is `wp_ai_client_prompt()`. The detector is updated to:

```php
return \function_exists( 'wp_ai_client_prompt' );
```

This is the side that previously made the whole cleanup feature non-functional in production: `has_ai_client()` returned false on every released WP version, so `Cleanup_Orchestrator::should_clean()` never returned true. Fixing the detector + wiring the real provider is what makes Phase A's engine actually run in v0.1.

### What this AgDR explicitly does NOT decide

- **Per-provider tuning** (Anthropic-specific vs OpenAI-specific message handling). The marker list is provider-agnostic by design; per-provider divergence ships only if telemetry shows it's needed.
- **Model selection / preference order**. The wrapper takes options through unchanged — model selection lives at the call site (e.g. `Cleanup_Orchestrator` passing `tier => quality`).
- **Cost / token accounting**. AgDR-0003 deferred this; still deferred.
- **Auth-error specific handling**. An auth error currently lands on `Network_Error` and gets retried, which is the wrong shape (retries will fail identically until creds are fixed). Acceptable for v0.1 because the retry cap stops infinite loops; v0.1.x can introduce `Auth_Error` if telemetry shows enough of these.

## Consequences

- New file: `includes/Ai/Wp_Ai_Client_Provider.php` — concrete `Provider` implementation.
- `Client_Wrapper::call_provider()` no longer throws unconditionally; instantiates `Wp_Ai_Client_Provider` when no test provider is injected.
- `Client_Wrapper::has_ai_client()` now correctly detects `wp_ai_client_prompt`. The Cleanup_Orchestrator's `should_clean()` gate flips from "always false on real WP" to "true when the function is callable AND cleanup is configured" — making Phase A's full pipeline reach the LLM.
- New `tests/Unit/wp-stubs.php` entries for `wp_ai_client_prompt`, `is_wp_error`, `WP_Error`, and `esc_html` so the provider's unit tests can run without a real WP bootstrap.
- One existing test removed: `Client_Wrapper_Test::test_unconfigured_returns_fallback_without_calling_provider`. With the new stub in place, `has_ai_client()` returns true in unit tests so the unconfigured short-circuit is no longer reachable from unit-level testing. The branch itself is a single `if`; integration tests on an unconfigured wp-env install cover the path.
- AgDR-0003's "deferred until #6" note is now closed — that AgDR remains the source of truth for the wrapper's retry contract.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#6`
- Related AgDRs: AgDR-0003 (wrapper design), AgDR-0018 (no-hallucination guard — consumes provider output)
- Files: `includes/Ai/Wp_Ai_Client_Provider.php`, `includes/Ai/Client_Wrapper.php`, `tests/Unit/Ai/Wp_Ai_Client_Provider_Test.php`, `tests/Unit/wp-stubs.php`
