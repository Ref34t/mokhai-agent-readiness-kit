# AgDR-0026 — `Permanent_Error` exception for non-retryable 4xx WP_Error returns

> In the context of preparing #8 (LLM-powered /llms.txt entry descriptions — first feature that fans out one prompt per entry on regen) and finding that `Wp_Ai_Client_Provider` classifies every non-rate-limit `WP_Error` as `Network_Error` (which triggers an immediate in-request retry plus a deferred-retry cron event), and observing that 4xx parameter-validation failures (e.g. OpenAI returning `Bad Request (400) - Invalid 'max_output_tokens'…`) re-send the same payload identically and fail identically — burning cron cycles, provider quota, and hiding the underlying config bug behind apparent intermittency — I decided to introduce a third typed exception `Permanent_Error` and route 4xx HTTP-status markers (plus a small phrase set) to it, with `Client_Wrapper::generate` returning a `Result(needs_retry=false, error_code='permanent')` for that path (no immediate retry, no cron queue), to achieve correct treatment of validation / auth / not-found failures as terminal while preserving the existing rate-limit and network paths, accepting that the heuristic remains message-substring based (same trade-off as AgDR-0019) and that bare `'invalid'` is excluded from markers to avoid false positives against 5xx error strings that happen to contain the word.

## Context

AgDR-0019 froze a two-class scheme for `WP_Error` returns from `wp_ai_client_prompt()`:

| WP_Error message contains… | Classified as | Wrapper behaviour |
|---|---|---|
| `rate limit` / `429` / `quota` / `too many requests` / `exceeded` | `Rate_Limit_Error` | No in-request retry, queue deferred retry, `needs_retry=true`, `error_code='rate_limit'` |
| anything else | `Network_Error` | Immediate in-request retry once, then queue deferred retry, `needs_retry=true`, `error_code='network'` |

The "anything else → retry" fallthrough was acceptable for v0.1 because the only LLM caller at the time (#6 cleanup) is a one-shot per post, and the v0.1.x window was named as the place to learn whether finer-grained classification (auth, content filter, model-not-available) was needed.

Production wp-env verification on 2026-05-18 (after #7 Phase C merged) exposed the failure shape directly. `Client_Wrapper::generate( "Reply with one word", [ "max_tokens" => 10 ] )` against a real OpenAI Connector with `max_tokens` below OpenAI's minimum of 16:

- HTTP 400 returned with structured message `"Invalid 'max_output_tokens': integer below minimum value. Expected a value >= 16, but got 10 instead."`
- WP AI Client wrapped it as `WP_Error('prompt_builder_error', 'Bad Request (400) - Invalid …')`
- `throw_for_wp_error()` walked `RATE_LIMIT_MARKERS`, didn't match, fell through to `Network_Error`
- `Client_Wrapper::generate` caught `Network_Error`, looped once (same payload, same 400), exhausted attempts, queued `wpctx_ai_retry` cron event
- Every cron tick fires the retry with the same payload, fails identically, queues again — until kill-switch limits intervene or the operator deletes the cron event

The bug had been latent since #6 merged (PR #49). It only became visible when an operator passed an option that violated a provider-side constraint. #8 is the first feature where this happens at scale: per-entry generation means N posts in one regenerate, and any per-account config error (bad API key → 401, invalid model name → 404) gets multiplied N-fold into the cron queue.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — New `Permanent_Error` exception + 4xx marker list, ordered after rate-limit + before network fallback** | Three-class scheme stays mechanically simple (same substring approach as AgDR-0019). Clean wrapper-side behaviour: `Permanent_Error` catches before the retry loop, returns immediately with `needs_retry=false`. New marker set is small and additive. No churn on rate-limit / network paths. | Still substring-based, inherits AgDR-0019's fragility against upstream wording changes. False-positive risk on markers that overlap legitimate 5xx messages — mitigated by excluding bare `'invalid'` and using HTTP status codes (`400`/`401`/`403`/`404`/`415`/`422`) as the dominant signal. |
| B — Inspect `WP_Error` `data` for the wrapped exception type (auth vs invalid-arg vs rate-limit) | Most accurate; recovers the original PHP AI Client exception class. | Same downside as Option B in AgDR-0019: couples to internal WP AI Client structure not in the public API. UPGRADE.md warns against depending on internals. |
| C — Bound the deferred-retry queue (cap N retries before giving up) | Limits damage from misclassification without changing the type system. | Treats the symptom, not the cause. The bug isn't "retry loops are unbounded" — it's "the wrapper believes a 400 is transient when it isn't". A retry cap would still burn the cap's worth of failed cron ticks and provider quota per failure event. |
| D — Match on `WP_Error::get_error_code()` (e.g. `prompt_prevented` → permanent) instead of message | More semantic than message-grep. | The two codes WP AI Client uses (`prompt_builder_error` / `prompt_prevented`) don't distinguish 4xx from 5xx — both ride on `prompt_builder_error`. Code-matching alone solves only the policy-rejection sub-case (which this ticket does not address). |

## Decision

Chosen: **Option A — introduce `Permanent_Error` + 4xx marker list, ordered after rate-limit check, before network fallback.**

Reasons:

1. Symmetric with the AgDR-0019 design: substring markers, small list, easy to extend. No new architectural concept — just a third bucket in an existing scheme.
2. HTTP status codes (`400`, `401`, `403`, `404`, `415`, `422`) appear verbatim in upstream provider error messages (verified by the wp-env trace: `"Bad Request (400) - …"`). Substring matching on them is reliable and unambiguous in practice — the false-positive surface is "a 5xx response that happens to contain the substring '404'", which is structurally rare.
3. Bare `'invalid'` is excluded from markers despite being suggested in the ticket. The OpenAI message that prompted this ticket also contains `400`, so the status-code marker catches it without us depending on the more ambiguous word. `invalid` can appear in 5xx error strings (e.g. internal "invalid state" messages from a provider's own error path) and we'd rather miss a marginal case than misroute a transient 5xx to permanent and silently drop the retry.
4. Wrapper change is one new catch clause that runs to completion immediately — no impact on the rate-limit / network-retry paths. Existing tests stay green.

### Marker list (v0.1.x frozen)

```php
private const PERMANENT_ERROR_MARKERS = array(
    '400',
    '401',
    '403',
    '404',
    '415',
    '422',
    'bad request',
    'unauthorized',
    'forbidden',
    'not found',
    'unprocessable',
);
```

Comparison is `strpos` over `strtolower()` of the WP_Error message — same shape as `RATE_LIMIT_MARKERS`.

### Order of checks in `throw_for_wp_error()`

```
1. Rate-limit markers  → Rate_Limit_Error   (preserves 429 → deferred retry — no regression)
2. Permanent markers   → Permanent_Error    (new: 4xx → no retry, no queue)
3. Fallback            → Network_Error      (5xx, network, unknown — retry path)
```

Rate-limit must come first because `429` is a 4xx status code; if the order flipped, `429` would match `'4'` (not in marker list) or no permanent marker (also fine), but the semantic intent — "rate-limits are retryable, all other 4xx are not" — reads cleanly with rate-limit-first.

### Wrapper contract for the new path

`Client_Wrapper::generate` adds a catch clause:

```php
} catch ( Permanent_Error $e ) {
    return new Result( false, false, null, 'permanent' );
}
```

`from_llm=false` (no content), `needs_retry=false` (caller MUST NOT mark needs-retry — the failure is terminal), `content=null`, `error_code='permanent'`. No `queue_deferred_retry()` call. The caller's deterministic fallback path runs (post-excerpt or title-based template per #8's AC), the failure is logged once, and the operator must fix the config before regeneration succeeds.

### What this AgDR explicitly does NOT decide

- **Auth-specific exception class.** `401`/`403` land on `Permanent_Error` together with parameter-validation 4xx. The wrapper makes the same decision (don't retry) for both, so a separate `Auth_Error` would only matter if a future caller wants to distinguish them in UI (e.g. "fix your API key" vs "fix your max_tokens"). v0.1.x can introduce that split if telemetry shows it's wanted.
- **`prompt_prevented` policy rejections.** Currently routed through `Network_Error` (retried). Logically permanent (a policy rejection won't change on retry) but out of scope here — separate ticket if it becomes a real cost.
- **404 model-not-found vs 404 endpoint-not-found.** Both are operator-fixable config errors; same wrapper behaviour applies. No need to split.
- **Operator-facing surfacing.** This AgDR fixes the classification + retry shape only. How the operator learns "you have a permanent error on entry X" lives in the calling feature (#8 admin UI, Context Profile screen, etc.).

## Consequences

- New file: `includes/Ai/Permanent_Error.php`.
- Modified: `includes/Ai/Wp_Ai_Client_Provider.php` — adds marker list + ordered classification in `throw_for_wp_error()`.
- Modified: `includes/Ai/Client_Wrapper.php` — new catch clause for `Permanent_Error`. The `Provider` interface PHPDoc expands its `@throws` list to include `Permanent_Error`.
- Modified: `includes/Ai/Provider.php` — `@throws Permanent_Error` added to interface contract.
- Modified: `tests/Unit/Ai/Wp_Ai_Client_Provider_Test.php` — new data-provider test pinning each 4xx marker as `Permanent_Error`, regression assertion that 5xx / network / unknown messages still route to `Network_Error`.
- Modified: `tests/Unit/Ai/Client_Wrapper_Test.php` — new test for the permanent path: provider throws `Permanent_Error` once → result is `from_llm=false`, `needs_retry=false`, `error_code='permanent'`, provider called exactly once, cron queue empty.
- Per-account `wpctx_ai_retry` cron events created by the bug before this PR remain queued — operators clear them via `wp cron event delete wpctx_ai_retry` after upgrading. (No automated cleanup in this PR; the deferred-retry handler is a no-op so the events fire harmlessly until they expire.)

## Artifacts

- Ticket: `Ref34t/agentready#66`
- Amends: `AgDR-0019` (classification heuristic) — does not deprecate, extends. Both AgDRs remain current.
- Related: `AgDR-0003` (wrapper contract), `AgDR-0018` (no-hallucination guard — consumes provider output, unchanged here)
- Files: `includes/Ai/Permanent_Error.php`, `includes/Ai/Wp_Ai_Client_Provider.php`, `includes/Ai/Client_Wrapper.php`, `includes/Ai/Provider.php`, `tests/Unit/Ai/Wp_Ai_Client_Provider_Test.php`, `tests/Unit/Ai/Client_Wrapper_Test.php`
