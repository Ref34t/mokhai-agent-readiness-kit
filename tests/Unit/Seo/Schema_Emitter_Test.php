<?php
/**
 * Unit tests for Mokhai\Seo\Schema_Emitter.
 *
 * Covers (per AgDR-0033 / #12):
 *   - Emitter is silent when any of the three supported SEO plugins is detected
 *     (deferral mode — AC #2, AC #3, AC #6).
 *   - Emitter prints WebSite + Organization site-identity JSON-LD when no SEO
 *     plugin is detected (AC #5 — site identity half).
 *   - Article node is emitted on a singular post (AC #5 — content-type half).
 *   - WebPage node is emitted on the front page and on a singular page.
 *   - Non-singular requests (e.g. archives) emit site identity only.
 *   - `mokhai_schema_emit` filter returning false suppresses all output.
 *   - `register_hooks()` wires wp_head at priority 10.
 *
 * Tests drive `render_for_posture()` with explicit slugs rather than the
 * `render()` entry point, so they don't depend on
 * `Schema_Coordination_Detector::detect()` — which inspects globally-
 * defined SEO plugin classes that may leak between unit tests
 * (`class_exists()` can't be reset once a class is defined).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use Mokhai\Seo\Schema_Emitter;
use WP_Post;

final class Schema_Emitter_Test extends TestCase {

	protected function setUp(): void {
		// Reset every test-global the emitter / detector look at so a
		// prior test's posture / query context can't leak forward.
		$GLOBALS['wpctx_test_active_plugins'] = array();
		$GLOBALS['mokhai_test_filters']        = array();
		$GLOBALS['wpctx_test_added_actions']  = array();
		$GLOBALS['wpctx_test_query_context']  = array(
			'is_singular_type'  => '',
			'is_front_page'     => false,
			'queried_object_id' => 0,
		);
		$GLOBALS['wpctx_test_posts']    = array();
		$GLOBALS['wpctx_test_home_url'] = 'https://example.test';
		$GLOBALS['wpctx_test_bloginfo'] = array(
			'name'        => 'Test Site',
			'description' => 'Just another WordPress site',
			'language'    => 'en-US',
		);

		// Default to the opted-in Profile state with `post` + `page` exposed
		// + `publish` status — the unit tests assert the gating logic, not
		// the safe-by-default behaviour, which is covered by the explicit
		// "profile off" / "post not exposed" tests below.
		$GLOBALS['wpctx_test_options']['mokhai_context_profile'] = array(
			'schema_version'      => 1,
			'schema_emit_enabled' => true,
			'exposed_cpts'        => array( 'post', 'page' ),
			'exposed_statuses'    => array( 'publish' ),
		);
	}

	public function test_register_hooks_wires_wp_head_at_priority_ten(): void {
		Schema_Emitter::register_hooks();

		$entries = wpctx_test_get_added_actions_for( 'wp_head' );

		$matching = array_values(
			array_filter(
				$entries,
				static function ( array $entry ): bool {
					$cb = $entry['callback'];
					return is_array( $cb )
						&& isset( $cb[0], $cb[1] )
						&& Schema_Emitter::class === $cb[0]
						&& 'render' === $cb[1];
				}
			)
		);

		self::assertCount( 1, $matching, 'Schema_Emitter::render should be wired exactly once on wp_head.' );
		self::assertSame( 10, $matching[0]['priority'] );
	}

	public function test_render_emits_nothing_when_yoast_is_active(): void {
		$output = $this->capture_render( 'yoast' );

		self::assertSame( '', $output, 'Emitter must defer entirely to Yoast.' );
	}

	public function test_render_emits_nothing_when_rank_math_is_active(): void {
		$output = $this->capture_render( 'rank_math' );

		self::assertSame( '', $output );
	}

	public function test_render_emits_nothing_when_aioseo_is_active(): void {
		$output = $this->capture_render( 'aioseo' );

		self::assertSame( '', $output );
	}

	public function test_render_emits_site_identity_when_no_seo_plugin_active(): void {
		$output = $this->capture_render( 'none' );

		self::assertNotSame( '', $output );
		self::assertStringContainsString( '<script type="application/ld+json" data-emitted-by="mokhai">', $output );

		$json = $this->extract_json( $output );
		self::assertNotNull( $json );

		$types = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types );
		self::assertContains( 'Organization', $types );
		// No page / post context → no per-content node.
		self::assertNotContains( 'WebPage', $types );
		self::assertNotContains( 'Article', $types );
	}

	public function test_render_includes_webpage_node_on_front_page(): void {
		$GLOBALS['wpctx_test_query_context']['is_front_page'] = true;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types );
		self::assertContains( 'WebPage', $types );
	}

	public function test_render_includes_article_node_on_singular_post(): void {
		$post                = new WP_Post();
		$post->ID            = 42;
		$post->post_type     = 'post';
		$post->post_status   = 'publish';
		$post->post_title    = 'Hello World';
		$post->post_date_gmt = '2026-04-12T10:30:00+00:00';
		$post->post_modified_gmt = '2026-04-13T08:15:00+00:00';

		$GLOBALS['wpctx_test_posts'][42]                          = $post;
		$GLOBALS['wpctx_test_query_context']['is_singular_type']  = 'post';
		$GLOBALS['wpctx_test_query_context']['queried_object_id'] = 42;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'Article', $types );

		$article = $this->find_node_of_type( $json, 'Article' );
		self::assertNotNull( $article );
		self::assertSame( 'Hello World', $article['headline'] );
		self::assertSame( '2026-04-12T10:30:00+00:00', $article['datePublished'] );
		self::assertSame( '2026-04-13T08:15:00+00:00', $article['dateModified'] );
		self::assertStringContainsString( '#article', $article['@id'] );
	}

	public function test_render_includes_webpage_node_on_singular_page(): void {
		$post                = new WP_Post();
		$post->ID            = 7;
		$post->post_type     = 'page';
		$post->post_status   = 'publish';
		$post->post_title    = 'About Us';

		$GLOBALS['wpctx_test_posts'][7]                           = $post;
		$GLOBALS['wpctx_test_query_context']['is_singular_type']  = 'page';
		$GLOBALS['wpctx_test_query_context']['queried_object_id'] = 7;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebPage', $types );
		self::assertNotContains( 'Article', $types );
	}

	public function test_filter_returning_false_suppresses_all_output(): void {
		add_filter(
			Schema_Emitter::FILTER_EMIT_DECISION,
			static function () {
				return false;
			}
		);

		$output = $this->capture_render( 'none' );

		self::assertSame( '', $output, 'mokhai_schema_emit=false should suppress emission.' );
	}

	public function test_render_emits_nothing_when_profile_toggle_is_off(): void {
		$GLOBALS['wpctx_test_options']['mokhai_context_profile']['schema_emit_enabled'] = false;

		$output = $this->capture_render( 'none' );

		self::assertSame( '', $output, 'schema_emit_enabled=false must suppress emission regardless of posture.' );
	}

	public function test_render_omits_article_node_when_post_type_not_exposed(): void {
		// Profile only exposes 'page' — a singular post should not get its
		// Article node, but the site-identity nodes still render.
		$GLOBALS['wpctx_test_options']['mokhai_context_profile']['exposed_cpts'] = array( 'page' );

		$post                = new WP_Post();
		$post->ID            = 42;
		$post->post_type     = 'post';
		$post->post_status   = 'publish';
		$post->post_title    = 'Hidden';

		$GLOBALS['wpctx_test_posts'][42]                          = $post;
		$GLOBALS['wpctx_test_query_context']['is_singular_type']  = 'post';
		$GLOBALS['wpctx_test_query_context']['queried_object_id'] = 42;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types );
		self::assertContains( 'Organization', $types );
		self::assertNotContains( 'Article', $types, 'Article must not emit when post_type is outside exposed_cpts.' );
	}

	public function test_render_omits_article_node_when_post_status_not_exposed(): void {
		// Profile exposes 'publish' only — a draft singular post must not
		// emit its Article node.
		$post                = new WP_Post();
		$post->ID            = 99;
		$post->post_type     = 'post';
		$post->post_status   = 'draft';
		$post->post_title    = 'Unpublished';

		$GLOBALS['wpctx_test_posts'][99]                          = $post;
		$GLOBALS['wpctx_test_query_context']['is_singular_type']  = 'post';
		$GLOBALS['wpctx_test_query_context']['queried_object_id'] = 99;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertNotContains( 'Article', $types );
	}

	public function test_render_omits_webpage_node_when_page_status_not_exposed(): void {
		$post                = new WP_Post();
		$post->ID            = 17;
		$post->post_type     = 'page';
		$post->post_status   = 'private';
		$post->post_title    = 'Internal';

		$GLOBALS['wpctx_test_posts'][17]                          = $post;
		$GLOBALS['wpctx_test_query_context']['is_singular_type']  = 'page';
		$GLOBALS['wpctx_test_query_context']['queried_object_id'] = 17;

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertNotContains( 'WebPage', $types );
		// Site identity still emits — the page's exposure only gates the
		// per-content node.
		self::assertContains( 'WebSite', $types );
	}

	public function test_filter_can_enrich_the_node_list(): void {
		add_filter(
			Schema_Emitter::FILTER_NODES,
			static function ( array $nodes ): array {
				foreach ( $nodes as &$node ) {
					if ( isset( $node['@type'] ) && 'Organization' === $node['@type'] ) {
						$node['logo'] = 'https://example.test/logo.png';
					}
				}
				return $nodes;
			}
		);

		$output       = $this->capture_render( 'none' );
		$json         = $this->extract_json( $output );
		$organization = $this->find_node_of_type( $json, 'Organization' );

		self::assertNotNull( $organization );
		self::assertSame( 'https://example.test/logo.png', $organization['logo'] );
	}

	/**
	 * Ref34t/agentready#118 — the script body MUST be raw JSON.
	 *
	 * Pre-#118 the emitter wrapped `wp_json_encode()` output in `esc_html()`,
	 * which entity-encoded every structural `"` and `&` and made the body
	 * invalid for every standards-compliant JSON-LD consumer (Google Rich
	 * Results Test, schema.org validator, `JSON.parse()`). The unit tests
	 * masked this by running `html_entity_decode` before `json_decode` —
	 * see AgDR-0041.
	 */
	public function test_script_body_is_raw_json_with_no_html_entities(): void {
		$output = $this->capture_render( 'none' );

		self::assertNotSame( '', $output );

		$match = preg_match(
			'#<script type="application/ld\+json" data-emitted-by="mokhai">\s*(.+?)\s*</script>#s',
			$output,
			$m
		);
		self::assertSame( 1, $match, 'No JSON-LD script block found in output.' );

		$body = $m[1];

		self::assertStringNotContainsString( '&quot;', $body, 'Body contains &quot; entities — JSON is not raw.' );
		self::assertStringNotContainsString( '&amp;', $body, 'Body contains &amp; entities — JSON is not raw.' );
		self::assertStringNotContainsString( '&lt;', $body, 'Body contains &lt; entities — JSON is not raw.' );
		self::assertStringNotContainsString( '&gt;', $body, 'Body contains &gt; entities — JSON is not raw.' );
		self::assertStringNotContainsString( '&#039;', $body, 'Body contains &#039; entities — JSON is not raw.' );

		$decoded = json_decode( $body, true );
		self::assertIsArray( $decoded, 'Body must parse as JSON directly, with no html_entity_decode pre-pass.' );
	}

	/**
	 * Ref34t/agentready#118 — script-tag-breakout safety must NOT depend
	 * on `esc_html()`. Instead `JSON_HEX_TAG` escapes `<` and `>` inside
	 * string values as `<` / `>`, so a node value containing the
	 * literal sequence `</script>` cannot close the wrapping script tag
	 * early. See AgDR-0041.
	 *
	 * Assertion shape (intentional):
	 *   1. `substr_count(..., '</script>') === 1` — PROPERTY assertion.
	 *      The wrapping tag closes exactly once. This is the load-bearing
	 *      breakout-safety property and survives any future encoding
	 *      strategy that still produces a literal-`</script>`-free body.
	 *   2. `assertStringContainsString('<\/script>', ...)` — MECHANISM
	 *      assertion. Pins `JSON_HEX_TAG` as the escape strategy. A
	 *      future maintainer revisiting AgDR-0041's option (c) (manual
	 *      `str_replace('</','<\/')`) under a fresh AgDR should relax
	 *      this one assertion; assertions #1 and #3 keep the property
	 *      guarantee intact.
	 *   3. `assertSame($injected_value, $organization['name'])` —
	 *      PROPERTY assertion. Round-trip through `json_decode()` returns
	 *      the original literal — consumers don't have to know we
	 *      escaped anything.
	 */
	public function test_node_value_with_script_close_sequence_is_escaped(): void {
		add_filter(
			Schema_Emitter::FILTER_NODES,
			static function ( array $nodes ): array {
				foreach ( $nodes as &$node ) {
					if ( isset( $node['@type'] ) && 'Organization' === $node['@type'] ) {
						$node['name'] = 'Bad </script><script>alert(1)</script>';
					}
				}
				return $nodes;
			}
		);

		$output = $this->capture_render( 'none' );

		// The wrapping script tag must close exactly once — the literal
		// `</script>` inside the org name must NOT have closed it early.
		self::assertSame(
			1,
			substr_count( $output, '</script>' ),
			'Operator-injected </script> broke out of the wrapping script tag.'
		);

		// The encoded form is what should appear in the body — `JSON_HEX_TAG`
		// emits `<` and `>` as the JSON unicode escapes `<` / `>`.
		self::assertStringContainsString(
			'</script>',
			$output,
			'Expected JSON_HEX_TAG-escaped </script> sequence inside the JSON body.'
		);

		// And after JSON parse, the consumer sees the original literal.
		$json         = $this->extract_json( $output );
		$organization = $this->find_node_of_type( $json, 'Organization' );

		self::assertNotNull( $organization );
		self::assertSame(
			'Bad </script><script>alert(1)</script>',
			$organization['name'],
			'JSON decoding should reverse the JSON_HEX_TAG escape transparently.'
		);
	}

	/**
	 * Capture stdout from Schema_Emitter::render_for_posture(). Tests
	 * supply the posture slug explicitly to avoid coupling to global
	 * `class_exists()` state that leaks across unit tests.
	 */
	private function capture_render( string $posture_slug ): string {
		ob_start();
		Schema_Emitter::render_for_posture( $posture_slug );
		return (string) ob_get_clean();
	}

	/**
	 * Pull the JSON payload out of the `<script>` block and decode it. The
	 * emitter prints exactly one block; returns null when no JSON is found.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private function extract_json( string $output ): ?array {
		if ( '' === $output ) {
			return null;
		}
		if ( ! preg_match( '#<script type="application/ld\+json" data-emitted-by="mokhai">\s*(.+?)\s*</script>#s', $output, $m ) ) {
			return null;
		}
		// Body must be raw JSON (AgDR-0041) — `json_decode` directly,
		// no `html_entity_decode` pre-processing.
		$decoded = json_decode( $m[1], true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Return the `@type` values of every node in a payload. The emitter
	 * stores a single node as the top-level object and multiple as a flat
	 * array — `collect_types` handles both shapes.
	 *
	 * @param array<int|string, mixed>|null $json Decoded payload.
	 * @return array<int, string>
	 */
	private function collect_types( ?array $json ): array {
		if ( null === $json ) {
			return array();
		}
		if ( isset( $json['@type'] ) ) {
			return array( (string) $json['@type'] );
		}
		$types = array();
		foreach ( $json as $entry ) {
			if ( is_array( $entry ) && isset( $entry['@type'] ) ) {
				$types[] = (string) $entry['@type'];
			}
		}
		return $types;
	}

	/**
	 * Locate the first node of a given type in the payload. Same shape-
	 * agnostic handling as `collect_types`.
	 *
	 * @param array<int|string, mixed>|null $json Decoded payload.
	 * @return array<string, mixed>|null
	 */
	private function find_node_of_type( ?array $json, string $type ): ?array {
		if ( null === $json ) {
			return null;
		}
		if ( isset( $json['@type'] ) && (string) $json['@type'] === $type ) {
			return $json;
		}
		foreach ( $json as $entry ) {
			if ( is_array( $entry ) && isset( $entry['@type'] ) && (string) $entry['@type'] === $type ) {
				return $entry;
			}
		}
		return null;
	}
}
