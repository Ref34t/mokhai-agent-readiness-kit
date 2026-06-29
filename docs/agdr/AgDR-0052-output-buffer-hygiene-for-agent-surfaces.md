# Output-buffer hygiene for agent-facing surfaces

> In the context of serving `/llms.txt`, the `.md` views, and the plugin's REST
> endpoints, facing upstream BOM / whitespace pollution that another plugin or
> the theme prepends to every PHP response, I decided to discard the pending
> output buffer (and strip a leading BOM from our own body) immediately before
> each emit point, wiring REST via a single namespace-scoped
> `rest_pre_serve_request` filter, to achieve clean agent output and parseable
> REST JSON, accepting that pollution already flushed before headers are sent is
> unrecoverable and out of scope.

## Context

On a page-builder WordPress site (evaluated on staging, #175), a theme file
shipped with a UTF-8 BOM (`EF BB BF`) plus stray newlines from host mu-plugins.
That output leaked into the PHP output buffer ahead of *every* response and got
prepended to the plugin's own surfaces:

- a leading `\n\n` appeared before `/llms.txt`'s first `# H1` line, and
- REST JSON became unparseable in the admin (`JSON.parse` → "The response is not
  a valid JSON response", with a correct body visible in the Network tab) —
  breaking the Context Profile save.

The site's own `/wp-json/` was equally affected, so this is an upstream/site-stack
problem the plugin cannot fix at the source. But the plugin *can* refuse to emit
polluted output from its own surfaces. Related framework finding:
me2resh/apexyard#569.

The three emit surfaces:

1. `/llms.txt` — `LlmsTxt\Router::dispatch()` (raw `echo`, then `exit`)
2. `.md` views — `Markdown_Views\Handler::dispatch()` (raw `echo`, then `exit`)
3. REST JSON — 7+ controllers under namespace `ai-readiness-kit/v1` that return
   `WP_REST_Response` objects (serialized + echoed centrally by
   `WP_REST_Server::serve_request()`, which does no ob-clean of its own)

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Do nothing** (treat as a site config bug) | Zero code | Leaves the plugin's flagship `/llms.txt` + admin save broken on any polluted site; the failure is invisible to the operator |
| **Edit each REST controller** to ob-clean before returning | Explicit per-endpoint | 7+ files churned; easy to miss a future controller; controllers return objects, they don't emit — the clean would sit in the wrong layer |
| **Single namespace-scoped `rest_pre_serve_request` filter** (chosen for REST) | One seam covers every current + future plugin controller; WP-idiomatic; never touches other plugins' routes | One indirection to understand |
| **Buffer-clean + leading-BOM strip at each emit point** (chosen for `/llms.txt` + `.md`) | Deterministic; mirrors WP core's `wp_send_json` discipline; no behaviour change on clean sites | Cannot recover pollution already flushed before headers are sent |

## Decision

Chosen: a shared `WPContext\Support\Output_Buffer` helper with three responsibilities:

- `discard_pending()` — discard pending output buffers (skipped once
  `headers_sent()`, the unrecoverable case; the loop ends a buffer per iteration
  and stops if one can't be removed, so it can't spin).
- `strip_leading_bom()` — strip a leading BOM + whitespace from the plugin's own
  composed body (belt-and-suspenders for source content that itself carries a BOM).
- `clean_before_rest_serve()` + `is_plugin_rest_route()` — the
  `rest_pre_serve_request` filter, scoped to `/ai-readiness-kit/` routes only.

`/llms.txt` and `.md` self-harden inside their existing `dispatch()` (discard
before `status_header()` so headers still send; strip on `echo`). REST hardens via
the single filter, registered from `Main::register_hooks()`.

A single `rest_pre_serve_request` filter is chosen over per-controller edits
because WP serializes and echoes `WP_REST_Response` objects in one central place;
that central echo — not the controllers — is where upstream pollution must be
discarded, and one namespace-scoped hook is self-evidently complete where seven
controller edits would be perpetually one-new-controller behind.

## Consequences

- Deterministic; zero behaviour change on already-clean sites (a clean buffer
  discards to nothing; a BOM-free body strips to itself).
- Covers every present and future REST controller under the plugin namespace with
  no per-controller maintenance.
- Pollution flushed to the client *before* headers are sent remains unrecoverable
  — explicitly out of scope (the upstream theme/server fix).
- `is_plugin_rest_route()` and `strip_leading_bom()` are pure and unit-tested;
  `discard_pending()` is observed in a clean separate process (its effect is
  masked once the test runner has flushed its own output, which is itself the
  graceful-degrade path).

## Artifacts

- Issue: Ref34t/mokhai-agent-readiness-kit#175
- `includes/Support/Output_Buffer.php`
- `includes/LlmsTxt/Router.php`, `includes/Markdown_Views/Handler.php`,
  `includes/Main.php`
- `tests/Unit/Support/Output_Buffer_Test.php`,
  `tests/Integration/Support/Output_Buffer_Test.php`
