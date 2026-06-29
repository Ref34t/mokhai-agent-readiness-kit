# AgDR-0028 — Prompt scaffold, token budget, and truncation policy for /llms.txt entry descriptions

> In the context of #8 (`Description_Orchestrator` calls `Client_Wrapper::generate` for each entry per AgDR-0027) and facing the question of what prompt, options, and post-processing rules govern the LLM call, I decided to use a tight directive system prompt ("write one factual sentence, max 160 characters, no preamble"), a user-prompt body containing only `title`, `URL`, and the first ~500 chars of `wp_strip_all_tags( post_content )`, `max_tokens = 80` and `temperature = 0.2` on the WP AI Client request, and a deterministic post-processing pass (strip newlines → collapse whitespace → drop leading "Description:"-style preambles → truncate to 157 chars + "…" if needed → reject empty strings as `failed`), to achieve concise factual descriptions cheaply on operator-configured cheap-tier models, accepting that the prompt is provider-agnostic by design (no Anthropic vs OpenAI tuning) and may produce slightly different output across providers — production telemetry in v0.1.x decides whether per-provider divergence is worth the complexity.

## Context

`Client_Wrapper::generate( $prompt, $options )` accepts three options today: `temperature`, `max_tokens`, `system` (AgDR-0019). Model and provider selection live in the operator's WP AI Client / Connector configuration — the wrapper is intentionally model-agnostic. The "cheap-tier model" language in the ticket AC is operator-facing (the operator points WP AI Client at gpt-4o-mini, claude-haiku, or equivalent) and not a wrapper concept.

`Cleanup_Orchestrator` (AgDR-0017/0018) uses a single inline prompt scaffold (`CLEANUP_PROMPT`) and post-processes through `Cleanup_Guard` — a sentence-allowlist filter that rejects hallucinated entities. The cleanup workload is long-form (potentially several paragraphs of cleaned markdown per post) so hallucination risk is real and the guard pays for itself.

Entry descriptions are a different shape: one sentence, hard cap 160 characters (per `Entry_Source::normalise_description`), surfaced as the human-readable hint in a `- [title](url): description` line. Hallucination risk is structurally bounded:

- The cap of 160 chars limits how much the LLM can invent.
- The "describe what this page is about" task is constrained by the title + URL + excerpt the prompt provides.
- The cost of a slightly-off description is "agents read a weak sentence"; the cost of a hallucinated cleanup pass is "agents read invented facts about the site".

A sentence-allowlist guard is overkill here. The right post-processing is structural: trim, collapse, truncate, reject empty.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Tight directive system prompt + minimal user body (title + URL + 500-char excerpt) + structural post-processing (trim/collapse/truncate/reject-empty)** | Concise, deterministic post-processing. Provider-agnostic prompt. Cheap on tokens (~150 input + 80 output ≈ negligible). Matches the AC's "one-line summary" framing. | No per-provider tuning. If OpenAI's instruction-following at cheap tiers diverges from Anthropic's at cheap tiers, descriptions vary across operators. Acceptable — see AgDR-0019's same trade-off for error classification. |
| B — Few-shot prompt (3–5 worked examples in the system prompt) | Better instruction adherence on weak models. | Heavy on input tokens (every call carries the examples). For 200-post backfills that compounds. The structural cap + reject-empty already disciplines output; few-shot isn't earning its cost. |
| C — JSON-mode prompt asking for `{"description": "…"}` | Easier to parse, less likely to include preamble. | Cheap-tier models' JSON-mode discipline is uneven; OpenAI's `response_format=json_object` requires gpt-4o+ for guarantees, and the WP AI Client `using_*` builders don't yet expose a response-format option. Adds a parse step that must defend against malformed JSON. Plain-text + post-processing is simpler. |
| D — Inline-style "Title: X\\nURL: Y\\nExcerpt: Z\\nDescription:" with no system prompt | Works in completion-only providers. | Most modern providers expect chat-style messages; the WP AI Client surface is chat-first (`using_system_instruction()`). Skipping the system prompt loses the format constraint enforcement. |

## Decision

Chosen: **Option A — tight system prompt + minimal user body + structural post-processing.**

Reasons:

1. The structural cap of 160 chars and the reject-empty rule do most of the work that a guard layer would do, at zero cost.
2. Few-shot examples are tempting but the per-call cost matters at backfill scale. For a 1000-post site (`PER_CPT_CAP`), every additional 100 input tokens × N CPTs adds up. Tight system prompt keeps each call to ~200 total tokens.
3. JSON-mode is the future-proof option but the WP AI Client surface doesn't expose response-format toggles today, and the cheap-tier JSON discipline isn't reliable enough to drop the parse-defence. Plain-text out, deterministic post-process in.

### System prompt (v0.1 frozen)

```
You write one-sentence descriptions for an /llms.txt index that AI agents
scan to decide which pages to read.

Rules:
- Output ONE factual sentence.
- Maximum 160 characters.
- Use only information present in the input.
- No marketing language. No first-person. No hedging ("might be", "seems
  to"). No emoji. No preamble (do not start with "This page", "Here is",
  "Description:", etc.).
- If the input is empty or has no extractable topic, output the word
  EMPTY.

Output the sentence itself with no quotation marks, no Markdown, no
labels.
```

### User prompt body (v0.1 frozen)

```
Title: {title}
URL: {url}
Excerpt (may be truncated):
{first 500 chars of wp_strip_all_tags(post_content)}
```

`wp_strip_all_tags` mirrors the same call `Entry_Source::normalise_description` uses for the excerpt path — same input shape, same expected character set.

### Options passed to `Client_Wrapper::generate`

```php
array(
    'system'     => self::DESCRIPTION_SYSTEM_PROMPT,
    'max_tokens' => 200,
)
```

- `temperature` is **deliberately omitted**. Verified live against the WP AI Client OpenAI Connector on 2026-05-19 that reasoning-class models (o1 / o3 family, gpt-5 reasoning variants) return `400 Unsupported parameter: 'temperature' is not supported with this model.` Passing the option would route through AgDR-0026's `Permanent_Error` and fail every description job on those configurations — exactly the operator surface we want to keep model-agnostic. The system prompt's directive shape carries the consistency story without sampling-temperature pinning; cheap chat models at default temperature still produce stable one-sentence output at this prompt size.
- `max_tokens = 200` — comfortably above the 160-character target (~40 tokens of visible output) with headroom for reasoning-class models that consume part of the budget on internal reasoning tokens before emitting visible text. Verified in live probe on 2026-05-19: `max_tokens=80` non-deterministically truncated reasoning-model output mid-word (28% of runs); `max_tokens=200` produced complete sentences in every run. Non-reasoning chat models stop on the first period regardless — the higher cap costs nothing extra on them. The orchestrator's `normalise_output` still truncates anything above 160 chars to `157 + …` so the cap protects downstream consumers either way.
- No `model` / `tier` option — see AgDR-0027 § "Model tier".

> **Decision history** — this AgDR was drafted with `temperature = 0.2` and `max_tokens = 80`. Both were revised at draft time after live probing against the wp-env OpenAI Connector: temperature dropped to avoid 4xx on reasoning models; max_tokens raised to 200 to avoid non-deterministic truncation on the same. The revisions are inline rather than a follow-up AgDR because the prompt itself hadn't merged yet.

### Post-processing pipeline (Phase A frozen)

Applied in order to the raw LLM output:

```
1. Trim whitespace.
2. If output starts with a recognised preamble token ("Description:",
   "Summary:", "This page", "Here is"), strip the preamble.
3. wp_strip_all_tags() — defence against models that wrap output in
   <p> or quote tags.
4. Collapse internal whitespace (\s+ → " ").
5. If length > 160, truncate at 157 + "…".
6. If output is empty OR equals "EMPTY" (the sentinel for "no extractable
   topic"), return null and the orchestrator marks the post as `failed`.
   The Description_Filter sees no `_auto` and falls back to excerpt.
7. Otherwise return the cleaned string.
```

Step 6's `EMPTY` sentinel is the explicit "the model couldn't write a description from this input" path. Without it, models faced with a near-empty excerpt sometimes produce a generic sentence ("This page is part of the website.") which is worse than the excerpt-fallback path.

### Failure → fallback mapping

| LLM outcome | Post-processed value | Status meta | `_auto` slot | Filter behaviour |
|---|---|---|---|---|
| Success, valid sentence | Trimmed sentence ≤ 160 chars | `done` | sentence | Returns sentence |
| Success, output empty after trim | n/a | `failed` | not written | Falls through to excerpt |
| Success, output equals `EMPTY` | n/a | `failed` | not written | Falls through to excerpt |
| `Permanent_Error` (4xx, per AgDR-0026) | n/a | `failed` | not written | Falls through to excerpt |
| `Rate_Limit_Error` / network with `needs_retry=true` | n/a | `needs-retry` | not written | Falls through to excerpt until deferred-retry succeeds |
| `WP AI Client unavailable` | n/a | `failed` | not written | Falls through to excerpt; admin notice (Phase B) prompts the operator to configure WP AI Client |

### What this AgDR explicitly does NOT decide

- **Provider-specific tuning** — see AgDR-0019. Same trade-off applies.
- **Few-shot / fine-tuning of the system prompt** — v0.1.x concern based on production telemetry.
- **Per-CPT prompt customisation** — a future "blog posts vs documentation pages" distinction lives in v0.1.x if data shows the generic prompt produces noticeably different quality across CPTs.
- **Caching the prompt itself** — the system prompt + user prompt are recomposed per call. Providers' own prompt caching (Anthropic prompt caching, OpenAI prefix caching) kicks in transparently when the system prompt is stable, which it is here.

## Consequences

- Adds two constants to `Description_Orchestrator`: `DESCRIPTION_SYSTEM_PROMPT` and `DESCRIPTION_USER_TEMPLATE`. Both are private constants — not part of the public API; v0.1.x can re-tune freely.
- `Description_Orchestrator::normalise_output()` is the single post-processing surface — orchestrator and tests both call it directly. Keeps post-processing deterministic and unit-testable without an LLM call.
- One extra round-trip on the cheap-tier model per eligible post. Cost ceiling: 1000 posts × 250 total tokens × ($0.15 / 1M tokens on gpt-4o-mini) ≈ $0.04 for a full-site backfill. Operator-facing rounding error.
- `EMPTY` sentinel is documented in the system prompt — operators reading their LLM provider's request logs see the model occasionally returning `EMPTY`, which is expected behaviour, not a bug.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#8`
- Pairs with: [AgDR-0027](./AgDR-0027-llm-entry-descriptions-orchestrator.md) (architecture); references AgDR-0019 (error classification) + AgDR-0026 (Permanent_Error) for the failure pipeline.
- Files: `includes/LlmsTxt/Description_Orchestrator.php`, `tests/Unit/LlmsTxt/Description_Orchestrator_Test.php`
