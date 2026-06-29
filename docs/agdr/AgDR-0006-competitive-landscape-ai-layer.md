---
id: AgDR-0006
timestamp: 2026-05-13T15:00:00Z
agent: claude-opus-4-7
model: claude-opus-4-7
session: ticket-20-competitive-analysis
trigger: ad-hoc competitive review of https://ai-layer.org/plugin/index.html prompted by CEO
status: executed
---

# AgDR-0006 — Competitive landscape: AI Layer

> In the context of building Mokhai on a 2026-07-08 launch target, facing a parallel-shipping competitor in the same "WP plugin for AI agents" niche, I decided to keep our PRD's positioning intact ("agent readiness for WordPress" — audit-driven, content-as-is, safe-by-default) AND promote three v0.1.1 follow-up moves (MCP/Abilities API, multi-channel discovery detection, co-existence-not-competition framing in the launch post) to close visible gaps without restructuring the v0.1 plan, accepting that AI Layer's beta will likely accumulate some mindshare before our wp.org launch.

## Context

On 2026-05-13 the CEO surfaced [AI Layer](https://ai-layer.org/plugin/index.html) for a comparative review. AI Layer is a parallel-shipping plugin in the same niche ("make WordPress sites work with AI agents") — beta-released now via GitHub, not yet on wp.org. The PRD's competitive analysis hadn't named them.

The decision moment is: does this change v0.1 scope, kill the project, reframe positioning, or just inform v0.1.1+?

The answer matters because:
- Their MCP / Abilities API integration (33 abilities) is in production while we slated FR-15 as v0.2+. If MCP is now table-stakes, our v0.1 looks behind.
- Their discovery surface count is 10 (ai.txt, manifest, OpenAPI, robots.txt, headers, JSON-LD, HTML pages, Markdown, sitemap, /.well-known/ai-layer) vs our v0.1 plan of 1 (`/llms.txt`).
- Their thesis ("structured data layer") is sufficiently different from ours ("surface existing content as agent-readable + audit readiness") that we're not directly substitutable — but the overlap on `/llms.txt`, schema-coordination-with-Yoast, and analytics is real.

## Side-by-side feature snapshot (2026-05-13)

| Capability | Mokhai (v0.1 plan) | AI Layer (beta) |
|------------|:----------------------:|:---------------:|
| `/llms.txt` generator | ✓ (FR-4) | ✓ (one of 10 channels) |
| Per-URL Markdown view (`?format=md`) with content-negotiation | ✓ (FR-2) | ⚠ "Markdown" channel listed but entity-output, not full-page HTML→MD |
| LLM cleanup for page-builder pages | ✓ (FR-3) | ✗ |
| Context Score audit (0–100) | ✓ (FR-6) | ✗ analytics only |
| Defer JSON-LD to Yoast / Rank Math / AIOSEO | ✓ (FR-8) | ✓ conflict-detection |
| Safe-by-default exposure (opt-in CPTs) | ✓ (FR-9) | ✗ Setup Wizard auto-populates |
| MCP / WordPress Abilities API | v0.2+ (FR-15) | ✓ **33 abilities shipping now** |
| Typed entity model (Services / Locations / FAQs / Actions / Proof / Business Profile) | ✗ deliberate (content-agnostic) | ✓ core thesis |
| Built-in Q&A answer engine (server-side, no LLM at runtime) | ✗ | ✓ |
| WooCommerce live-read proxy | ✗ | ✓ |
| 10-channel discovery (manifest, OpenAPI, robots.txt, headers, JSON-LD, ai.txt, /.well-known/, …) | partial v0.2+ (FR-13, FR-14) | ✓ already shipping |
| `ai.txt` support | ✗ | ✓ beta |
| Setup Wizard | ✗ deliberate (safe-by-default) | ✓ |
| Analytics dashboard | v0.1.1 (FR-11 Agent Activity) | ✓ |
| WordPress version floor | 7.0+ hard (needs WP AI Client) | 6.0+ soft, 6.9+ for full Abilities |
| PHP floor | 7.4+ | 8.1+ |
| LLM strategy | WP AI Client (core abstraction, 7.0+) | Bring-your-own-key OR server-side no-LLM answer engine |
| Distribution | OSS GPLv2+, wp.org target 2026-07-03 | OSS, GitHub only, beta now |
| Pricing | Free | Free |

## Where we're ahead

1. **Markdown-per-URL with page-builder cleanup** (FR-2 + FR-3). Agency leads' real demo moment is the messy Elementor / Divi / WPBakery site. AI Layer does not address this. Strongest moat.
2. **Context Score audit** (FR-6). "Your site is 32/100 agent-ready, here's what to fix" is the one-screen demo for the agency-lead persona. AI Layer ships analytics ("top questions, unanswered queries") but no readiness score.
3. **Safe-by-default exposure** (FR-9). For agency contexts where data-leak posture and compliance matter, the opt-in model is the trust signal AI Layer doesn't replicate.
4. **wp.org distribution path**. Once we ship to wp.org's directory, we get directory-search discoverability they don't have on GitHub-only.

## Where AI Layer is ahead

1. **Shipping in beta TODAY.** ~2 months of lead time on building mindshare before our 2026-07-08 launch.
2. **MCP / Abilities API integration.** 33 abilities exposed. We slated FR-15 as v0.2+. Not having this at v0.1 makes us look 6 months behind on agent-integration table-stakes.
3. **Discovery breadth.** 10 channels vs our 1. The `ai.txt`, `/.well-known/`, OpenAPI surfaces matter for serious agent operators.
4. **Built-in answer engine.** Clever, demoable USP. We have no analogue planned through v0.2+.
5. **Lower WP floor.** 6.0+ runs on more existing sites today than our 7.0+ (which depends on WP core that hasn't shipped GA yet).

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — Keep v0.1 plan, add 3 v0.1.1 moves** (chosen) | v0.1 launch target preserved; closes visible gaps in the fast-follow; doesn't reactively rewrite the PRD | Accepts ~6 weeks of "we look behind on MCP" between launch and v0.1.1 |
| B — Pull MCP/Abilities into v0.1, delay launch | Match AI Layer's MCP table-stakes at launch | Launch slips beyond 2026-07-08; PRD timeline trades a hard deadline for parity; medium-effort ticket added mid-build |
| C — Reposition Mokhai as "audit + Markdown only", deferring all discovery to AI Layer | Sharpest narrowing; tight differentiation | Cedes the `/llms.txt` + analytics ground; weakens the agency-deck demo; not what the PRD said |
| D — Kill / pause, validate harder | Avoids running into a competitor | We have a PRD-approved plan, capital allocated, and 3 build tickets shipped; killing now is costlier than continuing |
| E — Add a typed-entity layer to compete with their core thesis | Direct feature parity | Massive scope addition; not the Mokhai thesis; deeply changes the PRD |

## Decision

Chosen: **Option A.** Keep the v0.1 plan as-is. File three v0.1.1 follow-up tickets that close the most visible gaps:

1. **`Ref34t/mokhai-agent-readiness-kit#21`** — Promote MCP / WordPress Abilities API integration (FR-15) from v0.2+ to v0.1.1. 33-abilities-ish surface covering `audit.run`, `profile.read`, `profile.set_exposure`, `llms_txt.regenerate`, `md_view.preview`. Capability checks mirror the admin UI.

2. **`Ref34t/mokhai-agent-readiness-kit#22`** — Context Score sub-score: "Multi-channel discovery". Audit detects presence of `ai.txt`, `/.well-known/ai-layer`, `/.well-known/llms-policy.json`, OpenAPI specs in standard paths. If AI Layer is detected, the audit panel surfaces a "coordinating with AI Layer" note rather than penalising. Mirrors the FR-8 defer-to-Yoast playbook.

3. **Launch-post framing** (no code ticket — marketing prep) — reframe the "WordPress for Machines" analysis post + the v0.1 launch announcement to position Mokhai as **complementary** to AI Layer: *"AI Layer answers questions about your business. Mokhai audits and shapes your existing content for agents. They co-exist on the same site."* Avoids head-to-head framing in coverage, leverages their mindshare as a halo effect for the category.

### What this AgDR explicitly does NOT change

- **PRD positioning** — "Agent Readiness for WordPress, for agency leads managing 10–100 client sites" stays intact.
- **v0.1 scope** — the 9 Must-have FRs ship as planned.
- **Launch target** — 2026-07-08.
- **Kill criteria** — the 90-day metric thresholds (200 installs, 1 case study, 4.5 rating, 3 inbound inquiries) stay. Worth noting that AI Layer's parallel beta increases the bar for "did we matter" — if they accumulate hundreds of installs before our launch, hitting 200 by day 90 is harder. Re-evaluate at the 30-day post-launch mark.
- **Hard floor** — WP 7.0 stays. The WP AI Client dependency is what makes our LLM features tractable; lowering the floor to match AI Layer's 6.0 would mean either dropping LLM features or implementing our own provider routing, which the PRD explicitly rejects (FR-3, FR-5, FR-7 all depend on the WP AI Client abstraction).

## Consequences

- Two new tickets in the v0.1.1 queue with explicit AI-Layer-driven AC language. Future contributors reading those tickets see WHY the work matters (competitive parity, not opinion).
- The launch-post draft (target 2026-05-19) and the v0.1 launch announcement (target 2026-07-08) need explicit AI-Layer-coexistence language. **Owner:** Mohamed Khaled (Head of Product, this is a marketing call).
- We will track AI Layer's progress between now and launch. If they ship features that materially erode our differentiators (per-URL Markdown, Context Score audit), revisit this AgDR.
- If AI Layer beats us to wp.org submission and wins the "first agent-ready WP plugin on wp.org" narrative, the launch-post framing becomes load-bearing — we lead with the "audits your existing site" angle, not the "first" angle.

## Artifacts

- Source profile fetched 2026-05-13: https://ai-layer.org/plugin/index.html
- Their GitHub: https://github.com/james-s-k/ai-layer
- Ticket: https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/20
- v0.1.1 follow-up #21 (MCP): https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/21
- v0.1.1 follow-up #22 (multi-channel discovery): https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/22
- PRD: `projects/agentready/PRD.md` in the portfolio repo (will be updated to reference this AgDR)
- PR: (linked here on creation)
