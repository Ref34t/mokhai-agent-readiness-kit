# Mokhai Publicity Plan — v0.5.0 launch

Go-to-market plan for gaining visibility for Mokhai — Agent Readiness Kit after the v0.5.0 public launch (2026-06-30). This is a living document: check items off as they ship, and revise phases as channel results come in.

Tracking issue: [#279](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/279)

---

## Positioning

One sentence, reused verbatim across every channel:

> **AI agents like ChatGPT and Claude are already visiting your WordPress site — Mokhai makes sure they can actually read it.**

Why this framing:

- **Fear + fix** outperforms feature-listing ("llms.txt generator") — the visitor is already on your site and sees nav-soup.
- **The Context Score is the shareable moment.** A grade is screenshotable, arguable, and makes people want to check their own site. Lead with it in every screenshot, demo, and post.

## Target audiences

| Audience | What they care about | Where they are |
|----------|---------------------|----------------|
| WP site owners | "Is my site invisible to AI?" | r/Wordpress, Facebook groups, YouTube |
| WP developers / agencies | A credible tool to offer clients | r/ProWordPress, Post Status, WP newsletters, WordCamps |
| SEO / GEO practitioners | The next optimization surface | r/SEO, GEO newsletters, X |
| AI-agent builders | Sites that parse cleanly | Hacker News, llms.txt directories |

---

## Phase 1 — Week 1: free, high-intent channels

- [ ] **wp.org listing polish** — the storefront with built-in distribution:
  - Screenshots leading with the Context Score and a before/after of what an agent sees
  - FAQ entry: "What is llms.txt and why should I care?"
  - Ship the Arabic translation (#276) — translations boost wp.org discoverability
- [ ] **Show HN post** — "Show HN: Mokhai – see what AI agents see when they visit your WordPress site". Honest-builder angle: *"agent traffic to WP sites renders as nav-soup, so I built this."* One shot; draft and review the post text before submitting.
- [ ] **Reddit** — story-first, not ad-first:
  - r/Wordpress + r/ProWordPress: "I built a free plugin — here's what I learned about how AI agents parse WP sites"
  - r/SEO: the GEO/AEO angle
- [ ] **X / LinkedIn build-in-public thread** — the v0.5.0 story writes itself: *"I renamed my plugin 3 days before launch and dropped all back-compat — here's why."*

## Phase 2 — Weeks 2–3: WordPress community circuit

- [ ] **Newsletter / blog pitches** — The Repository, Post Status, The WP Minute, WP Tavern. Pitch: "first agent-readiness plugin on wp.org". Five-sentence email each.
- [ ] **Podcast outreach** — WP Builds, Do the Woo, The WP Minute podcast. AI-and-WordPress is the topic every host wants; a solo founder with a live plugin is an easy booking.
- [ ] **Demo video (60–90s)** — install → run the score → fix → re-score. Reused on wp.org, X, YouTube, and in every pitch above.
- [ ] **llms.txt directories** — submit to llmstxt.site and similar directories / awesome-lists. Small but exactly-targeted traffic, plus backlinks.

## Phase 3 — Week 4+: compounding plays

- [ ] **Free web checker — "Is your site agent-ready?"** Paste any URL, get a lightweight Context Score, see "install Mokhai to fix it." Turns curiosity into installs and gives every social post a link that *does something*. Highest-leverage build item in this plan.
- [ ] **Original data study** — the agent-analytics cluster (#161–#165) is secretly a PR engine. Once agent visits are captured: *"We measured which AI agents visit N WordPress sites — here's what they saw."* Original data gets picked up by HN, SEO newsletters, and WP press. This is the strongest argument for prioritizing that cluster next.
- [ ] **Talk circuit** — "Making WordPress readable by AI agents" as a lightning talk: local meetup first, WordCamp application after.

## Phase 4 — ongoing rhythm

- [ ] Weekly build-in-public post (X/LinkedIn) tied to whatever shipped
- [ ] Respond to every wp.org support thread fast — response time is a public trust signal on the plugin page
- [ ] Re-pitch newsletters/podcasts on each meaningful release (the data study and the checker are both re-pitch moments)

---

## Metrics

| Metric | Source | Cadence |
|--------|--------|---------|
| Active installs + downloads | wp.org plugin page / stats API | Weekly |
| GitHub stars | repo | Weekly |
| Support threads (volume = engagement) | wp.org support forum | Weekly |
| Checker runs → install conversion | checker analytics (once built) | Weekly |

**30-day target:** 100 active installs. Modest on purpose — it exists so each channel can be judged as worked / didn't-work, not as a vanity goal.

## Dependencies on existing backlog

This plan references but does not duplicate:

- [#276](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/276) — Arabic translation on translate.wordpress.org (Phase 1)
- [#161](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/161)–[#165](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/165) — agent analytics cluster (Phase 3 data study)
