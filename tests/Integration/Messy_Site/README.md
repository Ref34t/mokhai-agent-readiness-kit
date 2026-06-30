# Messy real-world site fixture

Regression guards for the renderer against **messy, commercial-site content** —
the class of input that clean Gutenberg/classic test posts never exercised, and
that let three bugs ship in v0.3.1:

| Bug | Shape | Guard |
|-----|-------|-------|
| [#252](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/252) | WooCommerce-style product: copy in `post_excerpt`, empty `post_content` | `test_excerpt_only_product_renders_nonempty_md_via_source_seam` |
| [#253](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/253) | Page-builder base64 blobs + Revolution-Slider init `<script>` | `test_builder_noise_is_stripped_from_md` |
| [#254](https://github.com/Ref34t/mokhai-agent-readiness-kit/issues/254) | Static front page → `/llms.txt` URL | `test_static_front_page_llms_txt_url_is_valid` (skipped, see below) |

## Synthetic content only — no third-party plugins

The fixture seeds the **content shapes** that broke the renderer, not the real
WooCommerce / Uncode / Revolution Slider plugins. That keeps CI deterministic
and fast, and satisfies the "no client / site-specific data" requirement.

One consequence: the bundled `Woocommerce_Source` adapter (the #252 fix) reads
the short description via `wc_get_product()` and **no-ops without WooCommerce**,
so it cannot fire in CI. The #252 guard therefore targets what the fix actually
depends on — the `mokhai_markdown_source_html` seam in
`Service::render_source_html()` — by registering a stand-in source adapter that
supplies the excerpt through that same filter. If the seam is ever removed, the
test fails. The real WooCommerce adapter is verified manually against a live
store.

## The #254 test is skipped (on purpose)

`#254` is still open. A failing assertion can't merge (red-CI block), and fixing
the bug is out of scope for this fixture task. The test seeds the static-front-
page scenario but calls `markTestSkipped`. **When #254 is fixed, delete the
`markTestSkipped` line** and it becomes a blocking guard.

## Run locally

The suite runs inside the same wp-env instance the CI integration matrix uses:

```bash
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/mokhai-agent-readiness-kit \
  bash -c "vendor/bin/phpunit --testsuite integration --filter Messy_Site"
npx wp-env stop
```

Drop `--filter Messy_Site` to run the whole integration suite. No separate CI
job is needed — anything under `tests/Integration/*` is collected by the
`integration` testsuite (`phpunit.xml.dist`) and runs in the existing
`phpunit-integration` matrix in `.github/workflows/ci.yml`.

## Add a new messy case

1. Add a `seed_<shape>()` method to `Messy_Site_Fixture` that `wp_insert_post`s
   the problematic content shape (synthetic only). Expose a distinctive marker
   constant the test can assert on.
2. Add a `test_<shape>_…` method to `Messy_Site_Test` that renders the seeded
   content through `Service::get_markdown_for_post()` (or composes `/llms.txt`
   via `LlmsTxt\Service`) and asserts the invariant.
3. If the case reproduces a still-open bug, `markTestSkipped` with the issue
   number and a "remove this when #N is fixed" note, mirroring the #254 test.
