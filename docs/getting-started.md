# Getting Started

AI Readiness Kit turns your WordPress site into a first-class citizen of the
AI-agent web — readable, discoverable, governable, and measurable for the AI
assistants and crawlers that increasingly read sites on a user's behalf. This
guide takes you from a fresh install to a verified, agent-ready site in about
ten minutes.

Everything runs on your own server. The plugin makes no external HTTP calls; the
optional AI-powered niceties only run if you explicitly wire up an AI provider
(see [Optional: turn on the AI-powered extras](#optional-turn-on-the-ai-powered-extras)).

---

## Requirements

- WordPress **6.9+**
- PHP **7.4+**
- Pretty permalinks recommended (the `.md` URL form needs them; a query-string
  fallback works without them)

## 1. Install and activate

Install like any other plugin:

- **From the WordPress.org directory** — *Plugins → Add New*, search for
  "AI Readiness Kit", **Install**, then **Activate**.
- **From a ZIP** — *Plugins → Add New → Upload Plugin*, choose the ZIP,
  **Install**, then **Activate**.

On activation nothing is exposed to agents yet — you opt surfaces in from the
Context Profile in the next step.

## 2. Configure the Context Profile (the one place that matters)

Open **Tools → Context**. The Context Profile is the single source of truth for
every agent-facing surface — *what* is exposed, *how* it's served, and *what*
gets scored. The page is a tabbed app:

| Tab | What it's for |
|-----|---------------|
| **Profile** | The core settings: which content is exposed, and which modules are on. |
| **Editorial** | Hand-curated entries you want listed in `/llms.txt` (beyond your auto-listed posts/pages). |
| **Descriptions** | Manage the (optional) AI-drafted one-line descriptions for `/llms.txt` entries. |

On the **Profile** tab, set:

1. **Exposed post types** — which CPTs agents may read (e.g. `post`, `page`).
   Anything not listed returns a clean 404 on every agent surface.
2. **Exposed statuses** — defaults to `publish` only. Drafts and private content
   stay invisible unless you add their status here.
3. **Module toggles** — each module is independently switchable:
   - **Markdown Views** — serve clean Markdown of your pages.
   - **LLMs Index** — publish `/llms.txt`.
   - **Schema Coordination** — emit JSON-LD when your SEO plugin isn't already.
   - **Context Score** — run the readiness audit.

Save. Exposure is strict and uniform: a URL is served to an agent only when its
CPT **and** status are both on the allowlists, it isn't password-protected, and
the relevant module is on. Every denial returns an identical `404` — never a
partial content leak.

## 3. Verify the agent-facing surfaces

With the profile saved, confirm each surface works. Replace `example.com` and the
slugs with your own.

**Markdown view** of any exposed page — three equivalent forms, pick whichever
your client uses:

```
https://example.com/about-us.md                 # path form (needs pretty permalinks)
https://example.com/about-us/?format=md          # query form (works on any permalinks)
curl -H "Accept: text/markdown" https://example.com/about-us/   # content negotiation
```

All three return the same body with `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex` (so the raw view isn't indexed as a duplicate), and a
no-store cache header. A non-exposed URL returns `404` with no body.

**The discovery index:**

```
https://example.com/llms.txt
```

This lists your exposed content (plus any Editorial entries you added) so an agent
knows what's worth reading and where.

**Structured data** — view a page's source and look for a
`<script type="application/ld+json">` block. If you already run Yoast / Rank Math /
AIOSEO, AI Readiness Kit detects their JSON-LD and steps aside; if nothing covers
it, the plugin emits a native WebSite + Organization + per-content set.

**See a page as an agent sees it** — while editing any post, open the
**AI Readiness** panel in the editor sidebar (and the **AI Assistant Preview**
admin pane) to view raw HTML, the Markdown view, and the live `/llms.txt` line
side by side, with an on-demand "sample AI summary".

## 4. Check your Context Score

Open **Tools → Context Score** for a 0–100 readiness audit across seven weighted
sub-scores:

1. Discoverability
2. Content readability
3. Schema coverage
4. Exposure safety
5. Integration health
6. Markdown conversion quality
7. Multi-channel discovery (extra surfaces like `ai.txt`, `/.well-known/`, OpenAPI)

The score also appears in **Tools → Site Health**. Use it as your to-do list —
the lowest sub-scores are your highest-leverage fixes.

## Optional: turn on the AI-powered extras

Three features can use an LLM to add polish. They are **off unless** you've
configured an AI provider through the **WP AI Client** (an optional dependency);
without it, every core surface still works fully via deterministic, local logic:

| Feature | What the AI adds | Local fallback |
|---------|------------------|----------------|
| `/llms.txt` entry descriptions | Drafts a one-line description per entry from the post content | Entries list without descriptions |
| Context Score narrative | A written explanation of your score + top fixes | Rule-based narrative |
| AI Assistant Preview summary | An on-demand "what an agent would say about this page" | (Preview still shows the raw/Markdown/llms.txt views) |

No content leaves your server except the specific text you send to the AI provider
you configured, only for the modules you opted into.

## Optional: agent abilities over MCP

If you run MCP clients, core operations are exposed through the WordPress
Abilities API and surfaced via the WordPress MCP adapter: run an audit, read the
profile, toggle exposure, regenerate `/llms.txt`, and preview Markdown. Every
ability is gated on the `manage_options` capability.

## Optional: WP-CLI quick reference

Every surface has a command, useful for scripting, CI, or a no-JavaScript admin
path:

```bash
# Readiness score
wp ai-readiness-kit context-score audit        # show the current score breakdown
wp ai-readiness-kit context-score recompute     # recalculate now
wp ai-readiness-kit context-score reset          # clear the cached score

# /llms.txt index
wp ai-readiness-kit llms-txt status              # generation state + entry count
wp ai-readiness-kit llms-txt regen               # rebuild the index
wp ai-readiness-kit llms-txt preview             # print what /llms.txt would emit

# AI-drafted entry descriptions (needs an AI provider)
wp ai-readiness-kit llms-txt descriptions status
wp ai-readiness-kit llms-txt descriptions backfill
wp ai-readiness-kit llms-txt descriptions regen

# Markdown view
wp ai-readiness-kit md preview <post-id-or-url>  # render + show the visibility verdict
```

## Troubleshooting: "my page returns 404 to agents"

The 404 is intentional and uniform — it never leaks partial content. A page is
denied when **any** of these is true:

- Its post type isn't in **Exposed post types**.
- Its status isn't in **Exposed statuses** (defaults to `publish` only).
- It's password-protected.
- The relevant module (Markdown Views / LLMs Index) is toggled **off**.
- A theme or companion plugin marked it no-index via the
  `agentready_post_is_noindexed` filter.

To find the exact reason for a specific page, run
`wp ai-readiness-kit md preview <post-id-or-url>` or open the **AI Readiness**
panel in that post's editor sidebar — both surface the precise denial cause.

---

*AI Readiness Kit is free and open source (GPL-2.0-or-later), with no paid tier
and no hosted backend.*
