# AgDR-0024 — LLMs Index conflict detection: 3-surface scan + dismissible admin notice (no auto-resolution)

> In the context of `Ref34t/agentready#7` Phase B — AC #4: detect any already-installed `/llms.txt`-serving plugin on activation and at runtime, surface an admin notice with a resolution path, and never silently overwrite the existing file, facing the choice between (a) scanning a hand-maintained registry of plugin slugs + filesystem + rewrite rules and surfacing a notice, (b) silently deactivating any competing plugin we detect, (c) trying to read the other plugin's stored entries and auto-migrate them into our editorial option, or (d) only detecting the rare runtime collision (our rewrite is shadowed) and ignoring storage / filesystem signals, I decided to ship a three-surface detector (plugin-slug registry + filesystem at `ABSPATH . 'llms.txt'` + rewrite-rule scan in `$wp_rewrite->extra_rules_top`), pre-loaded with five known wp.org plugins, surfaced as a dismissible admin notice on the Plugins screen and the Tools → Context page, with a **read-only resolution path** (the notice tells admins what to do; we do NOT auto-deactivate competitors, NOT auto-delete static files, NOT auto-import entries), to achieve a transparent and conservative conflict story that matches WordPress conventions for cohabiting plugins, accepting that the slug registry needs maintenance as the ecosystem grows and that the "one-click migration" framing in AC #4 is deferred to a follow-up ticket per-plugin (each competing plugin stores entries differently — five different importers is real work and is not load-bearing for v0.1 launch).

## Context

llmstxt.org is six months old. The wp.org plugin directory already lists five plugins that publish `/llms.txt`, with the largest (Ryan Howard's "Website LLMs.txt") at 30,000+ active installs. By the time agentready v0.1 ships, every adopter has non-trivial odds of having one of these already installed — either because they tried an early option and moved on, or because they're switching to us deliberately.

The failure modes if we ignore the cohabitation problem:

1. **Two plugins both writing the file at `ABSPATH/llms.txt`** — the more-recently-activated plugin's regen wins; whichever one ran last is what agents see. The admin has no idea why their changes "don't stick". Symptom looks like "agentready is broken" but the root cause is a competing static-file writer they forgot they installed.
2. **Two plugins both registering `^llms\.txt/?$` at `'top'` precedence** — WordPress's `add_rewrite_rule` is a flat associative array keyed by the regex; the last `add_rewrite_rule` call wins. Whichever plugin registered later (init priority + plugin load order) gets the request. Same symptom — admin's intent and reality diverge.
3. **Both** — competing plugin writes a static file AND we register a rewrite. The web server serves the static file before WP boots; our rewrite never fires. Worst possible failure: agentready looks fine in the admin (cache populated, status command happy) but the public route returns the OTHER plugin's content.

The fix isn't "fight them at runtime" — that would be a last-registered-wins race which by definition we cannot reliably win. The fix is "tell the admin what's happening and let them choose".

AC #4 frames a "one-click config migration" — a button that copies the competing plugin's entries into our editorial option then deactivates the competitor. This is appealing for the most common scenario (admin switching from competitor X to us). But each of the five surveyed plugins stores entries differently:

| Plugin | Storage |
|--------|---------|
| Website LLMs.txt | Custom DB table + WP options + post-meta + filesystem |
| LLMs.txt Generator (visibility.so) | WP options |
| LLMs.txt Generator (Pedro Ladeira) | WP options |
| Markdown Mirror | Stateless — no storage to migrate |
| JumpsuitAI | WP options + post-meta (per-post hide flag) |

A real auto-migration is N importers, each one a small but distinct integration. That's a Phase D ticket, not a sub-piece of Phase B's "detect conflicts and surface them". The notice text in Phase B tells admins **what to do** ("deactivate plugin X, then verify `/llms.txt` shows our content"); the actual *do* stays manual until a future ticket adds the importer for the specific plugin the adopter is on.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — 3-surface detector + admin notice, manual resolution** | Conservative: never silently overrides admin intent. Three independent surfaces catch every documented competing-plugin shape. Notice surfaces the conflict + actionable next step. Registry is small (5 entries) and easy to extend via filter. No write actions taken against other plugins. Matches WP convention for "this plugin is also installed". | Five hand-maintained slugs go stale as the ecosystem grows — requires periodic registry refresh (mitigated by extending via filter so adopters can patch in their own slugs without waiting for us). |
| B — Detect + silently deactivate competing plugin | Aggressively resolves the conflict; admin doesn't have to think. | Catastrophic UX for admins who installed two plugins on purpose (one for `/llms.txt`, another for `/llms-full.txt`, etc.). Violates "never silently overwrites" intent of AC #4. wp.org review would flag this as anti-cooperative behaviour. |
| C — Detect + auto-import entries + auto-deactivate | The "one-click migration" framing from AC #4 taken to its full extent. | Five plugins × five storage shapes = five importers. Each one needs schema reverse-engineering, edge-case handling, version-specific drift. Two of the five plugins store on post-meta + custom tables — non-trivial reads. Massive scope creep for Phase B. Better as a follow-up ticket once we know which competing plugin adopters actually migrate FROM. |
| D — Runtime-only collision detection (only flag when our rewrite is shadowed) | Smallest surface; we don't need a slug registry at all. | Misses the most common case (competing plugin writes static file → our rewrite never fires; we don't even know we lost). The whole admin UX is "agentready returns nothing, why?" with no path to discovery. |

## Decision

Chosen: **Option A — 3-surface detector + dismissible admin notice + manual resolution.**

### The three detection surfaces

```php
// includes/LlmsTxt/Conflict_Detector.php — pseudocode shape

public static function detect(): array {
    $conflicts = [];

    // Surface 1: known plugin-slug registry.
    foreach ( self::known_plugin_slugs() as $slug => $meta ) {
        if ( \is_plugin_active( $slug ) ) {
            $conflicts[] = [
                'kind' => 'plugin',
                'slug' => $slug,
                'name' => $meta['name'],
                'url'  => $meta['wporg_url'],
                'shape' => $meta['shape'], // 'static-file' | 'rewrite' | 'hybrid'
            ];
        }
    }

    // Surface 2: filesystem — competing static-file writer.
    if ( file_exists( ABSPATH . 'llms.txt' ) ) {
        $conflicts[] = [
            'kind' => 'filesystem',
            'path' => ABSPATH . 'llms.txt',
        ];
    }

    // Surface 3: rewrite-rule scan — our rule shadowed by another.
    // Our own rule is at extra_rules_top[ '^llms\.txt/?$' ] with value
    // 'index.php?agentready_llms_txt=1'. Any OTHER value at that key is
    // a shadow.
    global $wp_rewrite;
    if ( isset( $wp_rewrite->extra_rules_top[ '^llms\.txt/?$' ] ) ) {
        $value = (string) $wp_rewrite->extra_rules_top[ '^llms\.txt/?$' ];
        if ( false === strpos( $value, 'agentready_llms_txt' ) ) {
            $conflicts[] = [
                'kind'  => 'rewrite',
                'rule'  => $value,
            ];
        }
    }

    return $conflicts;
}
```

### The plugin-slug registry (Phase B initial seed)

| Slug | wp.org dir | Name | Shape |
|------|-----------|------|-------|
| `website-llms-txt/website-llms-txt.php` | website-llms-txt | Website LLMs.txt | hybrid (file + rewrite + DB table) |
| `llms-full-txt-generator/llms-txt-generator.php` ⚠️ | llms-full-txt-generator | LLMs.txt and LLMs-Full.txt Generator | static-file |
| `llms-txt-generator/llms-txt-generator.php` | llms-txt-generator | LLMs.txt Generator (Pedro Ladeira) | rewrite |
| `markdown-mirror/markdown-mirror.php` | markdown-mirror | Markdown Mirror | rewrite |
| `jumpsuitai-llms-txt/jumpsuitai-llms-txt.php` | jumpsuitai-llms-txt | JumpsuitAI llms.txt | rewrite |

The `llms-full-txt-generator` entry is the gotcha — directory slug `llms-full-txt-generator` but entry file `llms-txt-generator.php`. Hard-coded literally so we don't silently miss it by guessing.

Exposed via filter for adopter / future-self extension:

```php
$slugs = apply_filters(
    'agentready_llms_txt_known_plugin_slugs',
    self::DEFAULT_KNOWN_PLUGIN_SLUGS
);
```

### Where the detection runs

| Trigger | Where the result surfaces |
|---------|---------------------------|
| Plugin activation hook | One-time admin notice (transient, shown on next admin pageload) |
| `admin_init` on every pageload | Persistent notice on Plugins screen + Tools → Context page until dismissed or conflict resolves |
| `wp agentready llms-txt status` (Phase A WP-CLI command) | Adds a `conflicts_detected` field to the output |

Detection is cheap: three calls (`is_plugin_active`, `file_exists`, one array access on `$wp_rewrite->extra_rules_top`). Caching the result is overkill — runs only when we're already inside an admin page or a CLI invocation, never on the public route.

### Notice shape

```
⚠️  AgentReady — /llms.txt conflict detected

The "Website LLMs.txt" plugin (by Ryan Howard) is also active and serves
its own /llms.txt. AgentReady's /llms.txt will not be visible to agents
until you deactivate the other plugin OR explicitly switch one of them off.

To switch to AgentReady's /llms.txt:
  1. Confirm you want to use AgentReady's index.
  2. Deactivate "Website LLMs.txt" from Plugins → Installed Plugins.
  3. Reload this page to verify the conflict is resolved.

If you intentionally run both — e.g. one for /llms.txt, the other for
something else — dismiss this notice. We will not show it again for
this combination of detected conflicts.

[ Open Plugins screen ]   [ Dismiss for this conflict ]
```

When the conflict is the filesystem variant (`ABSPATH/llms.txt` exists but no plugin is recognised — possibly hand-rolled or left by a removed plugin), the body changes to:

```
A static /llms.txt file exists at the WordPress root. AgentReady's
/llms.txt route is being shadowed because web servers serve static
files before WordPress loads.

To switch to AgentReady's /llms.txt:
  1. Back up the existing /llms.txt content if you want to keep any of it.
  2. Delete /llms.txt from your WordPress root via FTP/SFTP/file manager.
  3. Reload this page to verify the conflict is resolved.
```

### Dismissal model

- Per-user (current admin). Stored in user-meta under `agentready_llms_txt_dismissed_conflicts`.
- Keyed by a SHA-1 hash of the conflict signature — the sorted JSON of detected conflicts. If the conflict shape changes (admin deactivates one plugin but a new one activates), dismissal does NOT carry over — a new fingerprint triggers a fresh notice.
- Filterable via `agentready_llms_txt_dismiss_conflict_fingerprint` so adopters can scope-shift the dismissal if they need to (e.g. dismissal-by-fingerprint to dismissal-by-site for multisite).

Why not a `wp_options` site-wide dismissal: avoids "one admin dismisses, every admin loses visibility on a real config issue". User-meta is the right granularity for "I personally don't want to see this notice again".

### Migration — explicitly deferred

This AgDR does NOT commit to an importer for any of the five known plugins. AC #4 frames the resolution as "one-click config migration"; Phase B ships **detection + actionable notice text + manual resolution steps**. The importer per-plugin is filed as a follow-up.

The rationale:

1. Each competing plugin stores entries differently — five plugins × five storage shapes. The largest competitor (Ryan Howard, 30k installs) uses a custom DB table + WP options + post-meta + filesystem; the auto-import path against just that plugin is a half-day of work and a schema-reverse-engineering exercise that risks breaking with the next competitor release.
2. Production data (post-v0.1 launch) will tell us which competing plugin adopters actually migrate FROM. Writing five importers up-front for plugins nobody migrates from is wasted scope. Writing the importer for the one or two plugins that show up in support requests is targeted.
3. The notice's manual-resolution steps cost the admin one click (deactivate competitor) plus optionally re-entering editorial entries (if any were manually set in the competitor). For the dominant case (admin tried a competitor, didn't customise it, wants to switch to us) that's a 10-second flow — auto-migration would optimise a non-bottleneck.

Phase B's notice text DOES surface the data-loss caveat ("If you've manually curated entries in [competitor], they will not transfer automatically — back up first"). Honest framing.

## Consequences

### Hook surface

- `admin_init` — runs detection, populates a transient with the conflict list (5-minute TTL — short enough that the notice clears within a minute of the admin deactivating the competitor, long enough that detection doesn't re-run on every admin pageload).
- `admin_notices` — renders the notice from the transient.
- `wp_ajax_agentready_llms_txt_dismiss_conflict` — REST-or-AJAX endpoint that writes user-meta on dismiss.
- `plugins_loaded` hook chain — detection is NOT wired here because `is_plugin_active()` requires `wp-admin/includes/plugin.php` which is only loaded after `admin_init`.

### Notice does NOT appear on the public site

The notice is admin-only. The public `/llms.txt` route either returns our content (conflict resolved or no conflict) or the competitor's (we lost the race). We do not annotate the public output with a comment about the conflict — agent fetchers wouldn't act on it and it would leak admin state to the public web.

### When BOTH our content and a competitor's content can be returned

Three sub-cases:

1. **Competitor wrote static file, we registered rewrite**: web server returns the static file. Our rewrite never fires. Our admin shows the conflict notice (filesystem variant).
2. **Both registered rewrites, competitor's at `'top'` precedence too**: whichever was registered later wins. If we lose, the admin sees the rewrite-conflict variant.
3. **Both wrote static files**: this shouldn't happen because we never write static files (AgDR-0021). But if a sysadmin manually placed a `/llms.txt` AND a competing plugin wrote one too, our notice catches both signals: filesystem variant + plugin variant.

### The filter for the slug registry

```php
$slugs = apply_filters(
    'agentready_llms_txt_known_plugin_slugs',
    array(
        'website-llms-txt/website-llms-txt.php'         => [...],
        // ...
    )
);
```

Reasons this exists:

- Adopters know about plugins we don't (private themes that ship a `/llms.txt` shim, custom plugins, future wp.org listings between our releases).
- Multisite networks where a particular subsite has an unusual competing plugin can scope an override.
- Phase D auto-importer tickets can register themselves into this map with an extra `'importer' => 'callable'` field; the notice's resolution UI can then offer a button.

The filter is documented in the readme.txt's "Filters" section so adopters can find it without source-diving.

### Performance

- `is_plugin_active()` against 5 slugs: ~5 array lookups on the `active_plugins` option (already autoloaded).
- `file_exists(ABSPATH . 'llms.txt')`: one `stat()` call. <1ms.
- `$wp_rewrite->extra_rules_top[ '^llms\.txt/?$' ]`: one array access.

Total: ~1ms on every admin pageload after `admin_init`. Negligible. Caching the detection in a transient is more for predictability than performance.

### Translation

All notice strings via `__()` / `_e()` with the `'agentready'` text domain. Five plugin names are NOT translated (proper nouns); the surrounding sentence template IS.

### What this does NOT do

- We do not attempt to deactivate any plugin (AC #4 explicit constraint).
- We do not delete any file on the filesystem.
- We do not read or modify any data owned by competing plugins (Phase B). Phase D may introduce importers but is out of scope here.
- We do not show the notice to non-admins. The notice is gated on `manage_options` capability.
- We do not annotate the public `/llms.txt` body with conflict information.
- We do not re-run the detection on every admin pageload; the 5-minute transient is the cache.

## Artifacts

- Ticket: `Ref34t/agentready#7` (Phase B sub-scope)
- Related AgDRs: AgDR-0021 (serving mechanism — the route we're protecting), AgDR-0022 (cache strategy — orthogonal), AgDR-0023 (regen debounce — orthogonal), AgDR-0002 (Context Profile — not touched by Phase B)
- Implementation files (planned): `includes/LlmsTxt/Conflict_Detector.php`, `includes/LlmsTxt/Conflict_Notice.php` (admin notice), `includes/Cli/Llms_Txt_Command.php` (status output gains conflicts field)
- Follow-up tickets (Phase D — deferred): one importer per known plugin slug, only filed when production traffic shows a real migration demand from that specific plugin
