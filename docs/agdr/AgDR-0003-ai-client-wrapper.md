---
id: AgDR-0003
timestamp: 2026-05-13T00:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-2-wp-version-floor
trigger: user-prompt (chose "Run /decide for the wrapper" during /start-ticket 2)
status: executed
---

# AgDR-0003 — WP AI Client wrapper: static class + Result value object + deferred retry

> In the context of needing a single shared API for #6 (Markdown LLM cleanup), #8 (/llms.txt entry descriptions), and #11 (Context Score narrative) to call the WP AI Client, facing the requirement that every LLM-touching feature must degrade silently when the AI Client is unconfigured and retry with backoff per the PRD edge-cases table, I decided to expose the wrapper as a static class `WPContext\Ai\Client_Wrapper` returning a small `Result` value object, with rate-limit / provider failures queueing a deferred retry via `wp_schedule_single_event` rather than blocking the current request, to achieve a clean call site for downstream modules and a deterministic-first UX, accepting that static methods are slightly harder to mock until the PHPUnit harness from #3 lands.

## Context

Three v0.1 Must-have modules will call the WP AI Client:

- **#6 — Markdown Views LLM cleanup pass** — runs at save_post; output cached as post-meta
- **#8 — /llms.txt entry descriptions** — runs at save_post on the cheap-tier model; cached as post-meta
- **#11 — Context Score narrative** — runs on demand from the audit screen / CLI

All three need identical behaviour around three failure modes:

1. **AI Client unconfigured** — return the deterministic fallback the module already computed, with no warning or PHP error.
2. **Provider rate-limited** — return the deterministic fallback, mark the post / score as `needs-retry`, queue a deferred retry so the LLM result is eventually filled in. Per PRD edge-cases table.
3. **Provider network error** — retry once immediately (within the same request); on second failure treat as rate-limited (queue + fallback + needs-retry).

If each module reinvents this logic, three slightly-different retry policies ship and the PRD invariant ("no PHP errors, no broken admin screens, graceful degrade") cracks. A shared wrapper is the only sane shape.

The wrapper is also the natural home for the `wp_context_has_ai_client(): bool` helper that the ticket-#2 admin notice depends on — keeping the AI-client-related code in one namespace.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Static class `Client_Wrapper::generate()`** | Simple call site (`Client_Wrapper::generate( $prompt )`); no DI ceremony; no per-call state. | Static methods are harder to mock until a real test harness exists; refactor cost if state ever needed. |
| B — Instance class via `Main::get_instance()->ai()` | Easy to mock by swapping the instance; future-proof for cached state (rate-limit counter, last-error timestamp). | More ceremony per call; modules need to thread the instance through; over-engineered given the zero per-call state. |
| C — Functional helper `wp_context_ai_generate()` | Matches `wp_remote_get()` idiom; brief. | Function-level mocking needs Brain Monkey or runkit; can't return a typed `Result` cleanly without a class anyway; ends up needing the class internally. |
| D — Filter-based service `apply_filters( 'wpctx_ai_generate', ... )` | Site / plugin override is trivial; very WP-idiomatic. | Overkill for the three callers we have; no type safety on the filter contract; debugging is harder. Defer this hook to a future ticket if external override is ever requested. |

## Decision

Chosen: **Option A — Static class `WPContext\Ai\Client_Wrapper` returning a `WPContext\Ai\Result` value object**, because:

- The wrapper has zero per-call state — every retry+backoff decision is derivable from the immediate provider response, so the OOP overhead of an instance is unjustified.
- Three call sites need an identical contract; static keeps that contract single-file.
- The `Result` value object solves the "did we get LLM or fallback?" question without callers having to interpret return-string magic, and lets callers branch on `$result->needs_retry` to mark posts.
- Testability is a real concern, mitigated two ways: (a) a `$provider` injection point on `generate( $prompt, $options, ?Provider $provider = null )` so #3's PHPUnit harness can pass a fake; (b) the `Result` class itself is trivially constructable in tests.
- Rate-limit retry is deferred via `wp_schedule_single_event( time() + 300, 'wpctx_ai_retry', [ $context ] )` rather than blocking the current request with `usleep()`. Blocking save_post for 5 minutes to wait out a rate-limit window would be a worse failure mode than serving the deterministic fallback. The cron event re-runs `generate()` for the same context and overwrites the cached post-meta on success.

### API shape (frozen for v0.1)

```php
namespace WPContext\Ai;

final class Client_Wrapper {
    public static function generate(
        string $prompt,
        array $options = [],
        ?Provider $provider = null
    ): Result;

    public static function has_ai_client(): bool;
}

final class Result {
    public readonly bool $from_llm;
    public readonly bool $needs_retry;
    public readonly ?string $content;
    public readonly ?string $error_code;  // 'unconfigured' | 'rate_limit' | 'network' | null
}
```

Note: `readonly` is PHP 8.1+; v0.1 floor is 7.4 (see ticket #1). The `Result` class will use private properties + getters, not `readonly`. The pseudo-code above is illustrative of intent.

### Globally-namespaced helper

`wp_context_has_ai_client(): bool` is exposed as a thin global-namespace shim that just calls `\WPContext\Ai\Client_Wrapper::has_ai_client()`. Matches ticket #2 AC text and gives non-namespaced template code (themes, mu-plugins) a clean entry point. The `generate()` path is namespaced only — themes don't call LLM providers directly.

### What this AgDR explicitly does NOT decide

- **The deterministic fallback shape per module** — each consuming module owns its own fallback (#6 ships HTML→MD pass output; #8 ships title+excerpt; #11 ships rule-based score narrative). The wrapper just transports the fallback through the `Result` object.
- **Provider selection** — WP AI Client owns provider routing. The wrapper does not pick OpenAI vs Anthropic vs Gemini.
- **Caching** — caching happens at the call site (post-meta in #6 / #8, transient in #11). The wrapper is stateless.
- **Cost / token accounting** — not in scope for v0.1.

## Consequences

- `includes/Ai/Client_Wrapper.php` and `includes/Ai/Result.php` ship in ticket #2's PR. Wrapper has full retry+backoff + deferred-retry logic implemented; `Provider` interface is a stub until #3's test harness needs the injection point.
- `wp_context_has_ai_client()` lands as a global helper in `includes/Ai/helpers.php`, autoloaded via Composer's `files:` field.
- #6, #8, #11 read this AgDR before they call the wrapper; their PR descriptions cite the contract.
- `wpctx_ai_retry` cron action handler lives in `Client_Wrapper::register_hooks()` (called from `Main::get_instance()`). On v0.1 launch, no callers exist yet — the cron handler is a no-op stub that #6/#8/#11 will plug into.
- AC #4 of ticket #2 (provider failure path) is partially verified: the wrapper's retry logic is unit-testable with a mock `Provider`, but end-to-end verification (real provider rate-limit → cron retry → cache update) requires a real caller, which lands in #6. QA on ticket #2 documents this caveat the same way ticket #1 deferred ACs 1 & 3 to ticket #3.

## Artifacts

- Ticket: https://github.com/Ref34t/wp-context/issues/2
- AgDR: this file
- PR: (linked here on creation)
