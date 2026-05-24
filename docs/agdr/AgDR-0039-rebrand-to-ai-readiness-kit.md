# AgDR-0039 — Rebrand to "AI Readiness Kit" / slug `ai-readiness-kit`

> In the context of `Ref34t/agentready#101` — *"wp.org reviewer assigned the username-prefixed fallback slug `mokhaled-ai-readiness-toolkit` (May 21, 2026) and the original review email is no longer accessible to us"* — facing the choice between (a) accepting the assigned username-prefixed slug as-is (`mokhaled-ai-readiness-toolkit`), (b) replying to ask for the originally-submitted slug `agent-ready` back (the AgDR-0036 outcome), or (c) proposing a new descriptive slug `ai-readiness-kit` together with a full product-display rebrand from "Agent Ready" → "AI Readiness Kit", I decided to **rebrand the product end-to-end to "AI Readiness Kit" and propose `ai-readiness-kit` as the new slug in the resubmission reply** — keeping the wp.org-visible surface (display name, slug, textdomain, REST namespace, WP-CLI command base, ZIP top-level folder) coherent under a single descriptive name — to achieve a clean resubmission with a slug that wp.org's policy patterns are likely to grant (descriptive, not marketing-shaped, no username prefix needed), accepting the cost of a second large mechanical rename within ~12 days of AgDR-0036 and the supersession of AgDR-0036's specific slug-value choice (`agent-ready`).

## Context

May 20, 2026: `Ref34t/agentready` v0.1.0 was submitted to wp.org per the AgDR-0036 plan — Plugin Name "Agent Ready", slug `agent-ready`, textdomain `agent-ready`.

May 21, 2026: wp.org review team replied with an automated PCP report listing ~150 textdomain-mismatch errors plus an `outdated_tested_upto_header` error. Crucially, they ALSO reassigned the slug to `mokhaled-ai-readiness-toolkit` — a **username-prefixed fallback**. The PCP errors all read:

```
WordPress.WP.I18n.TextDomainMismatch
Expected 'mokhaled-ai-readiness-toolkit' but got 'agent-ready'.
```

The original reply email was subsequently lost (account access change), so we don't have the human-readable rationale the reviewer gave. We do have the assigned slug and the PCP delta, which together carry the policy signal.

Username-prefixed slugs are wp.org's standard fallback when:

1. A submitted slug is too marketing-shaped (claims a generic category — "Agent", "Ready", "AI", etc.)
2. A submitted slug is too close to a trademarked or already-claimed name
3. A reviewer judges the name as not-descriptive-enough of function for a slug

"Agent Ready" pattern-matches case (1) — the name is metaphorical (treating "Agent" as the audience), not descriptive of the function. The slug they assigned, `mokhaled-ai-readiness-toolkit`, is informative:

- They kept "AI Readiness" — descriptive of what the plugin does
- They dropped "Toolkit" → "Kit" wouldn't have fit their fallback shape; "Toolkit" was their wording
- They prefixed `mokhaled-` — denying any claim to a generic `ai-readiness` slug for me

The signal: a **descriptive** name without the username prefix would likely be granted.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Accept `mokhaled-ai-readiness-toolkit` as-is, keep display name "Agent Ready"** | Zero work beyond fixing the textdomain mismatch + tested-up-to. Ships fastest. | Permanent ugly URL on wp.org (`/plugins/mokhaled-ai-readiness-toolkit/`). Brand/URL dissonance: display says "Agent Ready", URL says "mokhaled-ai-readiness-toolkit". Username prefix signals "this plugin couldn't get a real slug" to anyone reading the URL. |
| **B — Reply asking for `agent-ready` back (the AgDR-0036 outcome)** | Preserves "Agent Ready" brand and the AgDR-0036 work. | The reviewer already rejected this slug shape. Re-asking without addressing the underlying concern (marketing-shaped name) is likely to either fail or trigger weeks of back-and-forth. We have no leverage on a re-ask. Email thread is lost. |
| **C — Rebrand to "AI Readiness Kit" / `ai-readiness-kit` (chosen)** | The name aligns with wp.org's policy hint (descriptive, no claim on a generic noun). Three words instead of four (vs their suggestion). No username prefix likely needed because the name itself disambiguates. Coherent: display = slug = textdomain = REST = CLI. Pre-launch — brand equity in "Agent Ready" is near zero (no users yet). Future feature work touches the new name naturally. | Substantial mechanical diff: 149 PHP gettext + 201 JS gettext + 4 REST + 4 WP-CLI + entry file rename + ZIP top-level + ci.yml + .wp-env.json + phpcs textdomain rule + display-name swap. Second large rename in ~12 days (AgDR-0036 was 2026-05-12). Supersedes AgDR-0036's specific value choice. Drops the "Agent Ready" wedge framing (AI-agent-readiness-specifically → broader AI-readiness — but the plugin's actual modules already serve the broader scope, so this is arguably a more accurate name). |
| D — Hybrid: accept the assigned slug but rebrand the display name to "AI Readiness Kit" | Less mechanical work than C (no slug rename), still a coherent display brand. | Display ≠ URL dissonance preserved (display "AI Readiness Kit", URL `/plugins/mokhaled-ai-readiness-toolkit/`). The username-prefix shame stays forever. |

## Decision

Chosen: **C — full rebrand to "AI Readiness Kit" with slug `ai-readiness-kit`**.

Reasoning:

1. **wp.org's policy signal is the constraint.** The reviewer's assigned-slug shape (kept "AI Readiness", dropped marketing words, added username prefix) reads as a soft suggestion: *"AI Readiness is fine; the prefix would go away if the name were a touch more descriptive."* The proposed `ai-readiness-kit` removes the username-prefix shame without claiming a generic slug — "Kit" is the disambiguator.
2. **Pre-launch is the cheapest moment to rebrand.** Brand equity in "Agent Ready" is near zero — no users yet, no inbound links, no press, no community recognition. The rename cost is mechanical, not market damage. Doing this post-launch would be 10× more expensive.
3. **The original "Agent Ready" framing has drifted.** That name presupposed AI-agents-specifically as the audience. The plugin actually ships four modules (Markdown Views, /llms.txt, Context Score, Schema Coordination) that serve "AI Readiness" generally — any LLM consumer, not just agents. "AI Readiness Kit" describes the actual scope better than the metaphor.
4. **The mechanical pattern is well-rehearsed.** AgDR-0036 established the rename pattern (separation between wp.org-visible slug and PHP-storage `agentready_*` identifiers). This rebrand follows the same pattern exactly, only the target value changes. The same stay-unchanged list applies.

## Consequences

**Supersession of AgDR-0036.** AgDR-0036's *value choice* (`agent-ready`) is superseded. AgDR-0036's *pattern* (display→slug→textdomain→REST→CLI all aligned, internal `agentready_*` identifiers stay) is preserved and re-applied to the new target.

**Surface changes (visible to wp.org and end users):**

- wp.org URL: `https://wordpress.org/plugins/ai-readiness-kit/` (previously planned: `/plugins/agent-ready/`)
- WP-CLI: `wp ai-readiness-kit md preview ...` (previously `wp agent-ready ...`)
- REST namespace: `/wp-json/ai-readiness-kit/v1/...` (previously `/wp-json/agent-ready/v1/...`)
- Plugin folder in ZIP: `ai-readiness-kit/` (previously planned: `agent-ready/`)
- Display name in WP admin: "AI Readiness Kit" (previously "Agent Ready")

No adopters yet, so the WP-CLI and REST changes are non-breaking in practice.

**Resubmission strategy.** Reply to plugins@wordpress.org asking for the slug `ai-readiness-kit`, attach the rebuilt ZIP, explicitly note that the textdomain mismatch + tested-up-to errors are addressed. The reply explains the descriptive-name reasoning so the reviewer doesn't have to re-litigate why "Agent Ready" wasn't granted.

**Risk: reviewer rejects `ai-readiness-kit` too.** Low but non-zero. Fallback in that case: accept whatever they assign (option A shape) and proceed. The mechanical work in this PR is reusable — a third rename would just change the target value again, with the same pattern. Less likely because `ai-readiness-kit` directly answers the policy concern that triggered the original rejection.

**Two renames in ~12 days is genuinely uncomfortable.** Future-me reading this AgDR should understand: the first rename (AgDR-0036) was right for the information available at that time (wp.org auto-slug-derivation rule). The reviewer's assignment surfaced a different constraint (marketing-shaped name policy) that wasn't observable until submission. The second rename addresses that newly-surfaced constraint. Not a quality-of-decisions problem — a "wp.org gates aren't visible until you knock" problem.

## Artifacts

- Ticket: [`Ref34t/agentready#101`](https://github.com/Ref34t/agentready/issues/101)
- Rebrand PR: (this PR, opened after this AgDR commits)
- Supersedes: [AgDR-0036](AgDR-0036-slug-rename-agent-ready.md) (value choice only; pattern preserved)
- Builds on: [AgDR-0009](AgDR-0009-translations-via-wporg-auto-load.md) (translations auto-loaded since WP 4.6 — only the textdomain VALUE changes)
- Referenced from: `readme.txt` (Tested up to bump), `phpcs.xml.dist` (textdomain rule), `.wp-env.json` (plugin path), `package.json` ("name"), `.github/workflows/ci.yml` (path patterns)
