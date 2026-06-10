# /llms-full.txt — Consolidated Full-Content Document

> In the context of #179 (serve the `llms-full.txt` expanded variant of the llms.txt convention), facing the choice of how to source, cache, and invalidate a document that inlines the full Markdown body of every indexed page, I decided to regenerate it inside the existing `LlmsTxt\Service::regen_sync()` pipeline (one trigger set, two cached artifacts) with per-post Markdown sourced through the Markdown Views cache, to achieve trigger parity with `/llms.txt` by construction, accepting a second potentially-large non-autoloaded option row and a slower regen pass.

## Context

- `/llms.txt` (AgDR-0021–0023) is a cached link index: `Entry_Source::get_sections()` (exposure gates + exclusions + per-CPT cap) → pure `Composer` → option cache → debounced regen on post/profile/editorial/description/exclude-meta changes + daily backstop.
- `/llms-full.txt` must contain the full Markdown body of every page in `/llms.txt`, respect the same include/exclude rules, and regenerate on the same cadence/triggers (#179 ACs).
- Per-page Markdown already exists: `Markdown_Views\Service::get_markdown_for_post()` renders `the_content` through the Walker and caches per-post in the `agentready_md_cache` table with eager + content-hash invalidation.
- Unlike the #172 channels (O(1) metadata payloads, deliberately uncached), the full document aggregates per-post *content* — it needs the cache + invalidation machinery.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A. Second artifact inside the existing regen pipeline** — `regen_sync()` writes both the index cache and a new `agentready_llms_full_txt_cache` option; per-post Markdown via the Markdown Views cache (Walker fallback when that module is off) | Trigger parity is structural (one pipeline → can't drift); O(1) serve; conversions amortised by the md_cache table; reuses lock, debounce, daily backstop | Regen pass does more work; full body duplicated in a second option row (MBs on large sites) |
| B. Independent `Full_Service` with its own triggers + cache | Clean separation; index regen unaffected | Duplicates the entire trigger wiring (#103/#151/#190 classes would need re-fixing twice); cadence parity by convention, not construction |
| C. Compose per-request from the md_cache table, no document cache | No big option row; md_cache invalidation is already correct | First request after a bulk edit runs N synchronous Walker conversions (timeout risk at the 1000-post cap); request-time WP_Query on a public route |

## Decision

Chosen: **Option A**, because the "same cadence/triggers as llms.txt" AC is satisfied by having exactly one regen pipeline rather than two kept in sync, and the per-post cost is already amortised by the Markdown Views cache table.

Supporting decisions:

- **Routing**: second rewrite (`^llms-full\.txt/?$`) in `LlmsTxt\Router`, plus a `ROUTES_VERSION` / `maybe_flush()` upgrade path (mirrors `Discovery\Channel_Router`) so plugin *updates* gain the rule without reactivation.
- **Toggle**: profile key `llms_full_txt_enabled`, default ON, no UI checkbox (the issue's "No UI changes" note; same shape as `advertise_alternates_enabled`, #178). Off → explicit 404 (AgDR-0015 soft-disable) and the regen pass clears the full cache. Default ON is FR-9-safe: the document only inlines content the exposure gates already publish, and a fresh install (empty `exposed_cpts`) serves an empty 200.
- **Markdown source**: `Markdown_Views\Service::get_markdown_for_post()` when that module is enabled (cache-table hit, byte-identical to the served `.md` companion); direct `Walker::convert()` fallback when disabled — the consolidated file doesn't disappear because per-page `.md` routes are off, and the fallback never writes the md_cache table.
- **Editorial entries** render as link lines only (operator-curated, possibly external URLs — no local body to inline); auto-listed documents render `### title` + `URL:` line + full body, separated by `---` rules.
- **Parity**: `compose_full_now()` consumes the same `Entry_Source::get_sections()` output the index uses (entries now carry `post_id`), so include/exclude parity holds by construction.

## Consequences

- One regen pass now also runs N markdown lookups (cache-table hits in steady state; Walker conversions only for posts never served as `.md`).
- `agentready_llms_full_txt_cache` is a second non-autoloaded option that can reach MBs on large sites — acceptable for `LONGTEXT` storage and never read outside the route; revisit (streaming compose, Option C hybrid) if a real site hits `max_allowed_packet` pressure.
- `Composer::{group_editorial, format_entry, escape_inline, escape_link_url}` became public — `Full_Composer` shares one grouping/escaping implementation instead of forking it.
- Profile `CURRENT_SCHEMA_VERSION` 3 → 4 (additive key; defaults-merge migration suffices, same as #172/#180).
- New uninstall keys registered per the #189 contract: `FULL_CACHE_OPTION`, `Router::ROUTES_VERSION_OPTION`.

## Artifacts

- Ref34t/agentready#179 (ticket)
- `includes/LlmsTxt/Full_Composer.php`, `includes/LlmsTxt/Service.php`, `includes/LlmsTxt/Router.php`, `includes/LlmsTxt/Entry_Source.php`, `includes/Admin/Context_Profile_Settings.php`, `includes/Support/Uninstaller.php`
