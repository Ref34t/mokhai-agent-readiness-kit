# AgDR-0038 — CLI YAML emission via wp_json_encode (no symfony/yaml runtime dep)

> In the context of `wp agent-ready md preview … --format=wrapped` producing a YAML front-matter header whose naïve string-replace escaping mishandled backslashes, literal newlines, control characters, and unbalanced double quotes, I decided to route string scalars through `wp_json_encode()` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` rather than add `symfony/yaml` as a runtime dependency, to achieve correct YAML 1.2 escaping while keeping the plugin's runtime-dependency footprint at zero, accepting the extra knowledge required to reason about "JSON string scalars are a strict subset of YAML 1.2 double-quoted scalars."

## Context

GH#42 flagged that `Markdown_Views_Command::wrap_with_header()` used `str_replace('"', '\"', ...)` to escape post titles for inclusion in the YAML front matter block. That approach:

- Misses backslashes — `'C:\Users\admin'` survives unescaped, and YAML parsers interpret `\U` as a Unicode escape, mangling the title.
- Misses literal newlines — embedded `\n` in `post_title` (rare but possible via `WP-CLI` direct update) splits the YAML scalar mid-string, breaking the front matter.
- Misses control characters (tab, `\r`, etc.) — same shape as the newline case.
- Misses unbalanced double quotes — a stray `"` inside the title closes the YAML scalar early.

The ticket's fix sketch suggested using `Symfony\Component\Yaml\Yaml::dump()`. The plugin's `composer.json` `require` section is currently `{"php": ">=7.4"}` — **zero runtime dependencies**. Adding `symfony/yaml` (~150 KB unpacked, two PHP files in its core path) is a significant change to that posture for a single feature affecting a CLI command that ships behind an opt-in `--format=wrapped` flag.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A — `symfony/yaml` runtime dep** (ticket's sketched fix) | Industry-standard, well-tested across every YAML edge case; ~5 lines of code change | First runtime dependency the plugin ever takes; ~150 KB added to the wp.org ZIP; expands attack surface; adds a vendored library to the wp.org submission Plugin Check has to grok |
| **B — `wp_json_encode()` for string scalars** (chosen) | JSON string scalars are a strict subset of YAML 1.2 double-quoted scalars; `wp_json_encode` is already used 8+ times in the codebase; zero new deps; ~4 lines of code change | Reader needs to understand the JSON⊂YAML claim; YAML's `\/` solidus-escape is *optional* — `JSON_UNESCAPED_SLASHES` is required to avoid emitting `\/` which YAML doesn't recognize |
| **C — Hand-rolled YAML escape helper** (~20 lines mapping backslash, double quote, control chars, unicode to YAML escapes) | Zero deps; explicit about what's covered | Maintain our own escape logic; only as correct as the test coverage; reinvents YAML 1.2 § 5.7 |
| **D — `yaml_emit()` PHP extension** | Standard YAML emitter built into PHP | The `pecl yaml` extension is NOT in WordPress core's minimum requirements and is unavailable on most shared WP hosts. Hard no for a wp.org-distributed plugin. |

## Decision

Chosen: **Option B — `wp_json_encode()` for string scalars**, because the plugin's zero-runtime-deps posture is load-bearing for the wp.org distribution and the JSON⊂YAML invariant is a known property of YAML 1.2 (§ 5.7 "Escaped Characters" and § 7.3.2 "Double-Quoted Style") — JSON emits exactly the subset of escapes that YAML 1.2 double-quoted scalars accept, with one caveat (the optional `\/` solidus-escape) addressed by `JSON_UNESCAPED_SLASHES`.

Required flags:

- `JSON_UNESCAPED_SLASHES` — prevents `wp_json_encode` from emitting `\/` for forward slashes (e.g. URL paths). YAML 1.2 does NOT recognize `\/` as a valid escape; a parser would either reject the scalar or pass the `\/` through literally.
- `JSON_UNESCAPED_UNICODE` — keeps non-ASCII readable in the output (`"Café"` rather than `"Café"`). YAML 1.2 *does* accept `\uXXXX`, so this is a readability choice rather than a correctness one.

The refactor extracts a testable `Markdown_Views_Command::format_yaml_header(int $id, string $title, string $canonical_url, string $generated_at): string` helper from the existing `wrap_with_header()`, so the YAML emission shape can be exercised in pure unit tests with no `WP_Post` / `get_permalink()` / `current_time()` dependency.

## Consequences

- The four-field YAML header (`id`, `title`, `canonical_url`, `generated_at`) now correctly escapes every PHP-string input.
- 10 new unit tests in `tests/Unit/Cli/Markdown_Views_Command_Test.php` covering the regression class (backslash, embedded double quote, literal newline, unbalanced quote, tab) plus invariants (unicode preserved, slashes unescaped, integer id unquoted, full-header bracketing).
- `wp_json_encode` is already loaded in the unit-test stub at `tests/Unit/wp-stubs.php:420`; no new test infra needed.
- Plugin's runtime `composer.json require` section stays at `{"php": ">=7.4"}` — zero dependencies. Distribution ZIP unchanged in size.
- Anyone reading `format_yaml_header()` needs to understand the JSON⊂YAML claim. The function docblock cites this AgDR explicitly so the choice is discoverable from the call site.

If a future feature needs to emit *block-style* YAML, *flow collections* with nested structures, or anchors / aliases / merge keys, this approach is insufficient and Option A (Symfony YAML) should be reconsidered at that point. The current four-field flat header is the only YAML emission in the plugin.

## Artifacts

- PR #93 (the implementation + tests)
- Closes #42
- Related: AgDR-0004 (PHPCS / Plugin Check / PHPUnit shape), AgDR-0035 (build-zip-verify), AgDR-0037 (CI check-build job)
