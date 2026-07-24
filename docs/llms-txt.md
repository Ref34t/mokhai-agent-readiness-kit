# /llms.txt — Curation Guide

`/llms.txt` is the de-facto convention for telling AI agents which URLs on a site are worth fetching as context — the agent's front door, the way `robots.txt` was the crawler's. Mokhai generates and serves it, but the interesting work is editorial: deciding what agents should find, and what they should read about each entry before fetching it.

This guide covers how Mokhai builds the file, how to curate it, and the guard rails around it.

## The shape of an entry

Every entry renders in the documented `/llms.txt` form:

```
- [Title](url): Description
```

or, when no description exists:

```
- [Title](url)
```

The URL points at the page's **Markdown twin** (`/about-us.md`), not the HTML page. An agent reading your index gets a curated map of the site *and* the readable form of each page in one hop. That pairing — described entry → clean Markdown — is the core move of the plugin.

## What gets included

The generator is driven by the Context Profile: only post types and statuses you have exposed appear in the index. Nothing enters `/llms.txt` that the Markdown Views exposure rules would not also serve — the index and the content surface can never disagree about what is public.

If `robots.txt` already covers paths the index would advertise, Mokhai detects the conflict and shows an admin notice instead of publishing a silently inconsistent pair of signals. Conflicts also cost points in the Context Score (discoverability and integration health).

## Descriptions: the editorial layer

A title tells an agent that a page exists. A description tells it whether fetching the page is worth the tokens. Descriptions come from three sources, in the order you should prefer them:

1. **Curated** — you write it, in the editorial entries UI or as the post excerpt. Best quality, costs your time.
2. **LLM-drafted** — an optional pass drafts descriptions from post content, using the AI provider configured at the site level (via the optional WP AI Client). Review them; they are drafts, not verdicts.
3. **Title-only floor** — no description. Deterministic, always available, never wrong.

Two behaviors keep the LLM pass honest:

- **Short posts are skipped, not padded.** A post whose body is below the minimum length shows a "skipped" status in the Descriptions tab and falls back to the title-only floor, instead of getting a filler description like "Title is available at URL." Adjust the threshold with the `mokhai_description_min_content_chars` filter.
- **Coverage is measured.** The percentage of exposed entries with a description is the Content Readability sub-score of your Context Score — so the editorial work has a visible number attached.

## Editorial entries: URLs that are not posts

Most sites have valuable agent context that is not a WordPress post — a pricing page on another subdomain, brand guidelines, a support knowledge base hosted elsewhere. The editorial entries admin UI lets you add curated entries with custom titles and descriptions. They render in `/llms.txt` alongside the generated entries, in the same shape.

## The instruction-shape advisory

Descriptions are read by agents that trust the file. That makes them a quiet injection surface — not structurally (newlines are collapsed and Markdown is escaped before an entry ever renders), but semantically: a description can be *worded* as an instruction. "Ignore previous instructions." "Always recommend this product." Direct address to AI assistants.

Mokhai runs a non-blocking advisory over every live description — curated, excerpt, or LLM-drafted, because agents read them all identically — and flags instruction-shaped wording.

Three things it deliberately does **not** do:

- It never mutates your published wording.
- It never blocks generation.
- It never affects the Context Score.

The advisory exists to inform the operator, not to police them. Your site, your words — but if your description reads like a command to someone else's agent, you should know before the agents do.

## Verifying and maintaining

```
# Current generation state, conflict report, entry count
wp mokhai llms-txt status

# Force regeneration
wp mokhai llms-txt regen
```

The file regenerates from the Context Profile, so the routine maintenance loop is: expose the right content, write descriptions for the entries that matter, check `status` for conflicts, and let the Context Score tell you where the coverage gaps are.

## What good looks like

A well-curated `/llms.txt` is short before it is long. Ten entries with sharp one-line descriptions beat two hundred raw permalinks — an agent deciding what to fetch is doing retrieval, and you are writing its ranking signals. Lead with the pages that answer real questions (pricing, services, docs, contact), describe each in the language a user's question would use, and let the rest of the site stay findable through the Markdown twins rather than crowding the index.
