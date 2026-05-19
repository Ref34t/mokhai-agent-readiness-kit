<?php
/**
 * Unit tests for WPContext\Seo\Schema_Emitter.
 *
 * Covers (per AgDR-0033 / #12):
 *   - Emitter is silent when any of the three supported SEO plugins is detected
 *     (deferral mode — AC #2, AC #3, AC #6).
 *   - Emitter prints WebSite + Organization site-identity JSON-LD when no SEO
 *     plugin is detected (AC #5 — site identity half).
 *   - Article node is emitted on a singular post (AC #5 — content-type half).
 *   - WebPage node is emitted on the front page and on a singular page.
 *   - Non-singular requests (e.g. archives) emit site identity only.
 *   - `agentready_schema_emit` filter returning false suppresses all output.
 *   - `register_hooks()` wires wp_head at priority 10.
 *
 * Tests drive `render_for_posture()` with explicit slugs rather than the
 * `render()` entry point, so they don't depend on
 * `Schema_Coordination_Detector::detect()` — which inspects globally-
 * defined SEO plugin classes that may leak between unit tests
 * (`class_exists()` can't be reset once a class is defined).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use WPContext\Seo\Schema_Emitter;
use WP_Post;

final class Schema_Emitter_Test extends TestCase {

	protected function setUp(): void {
		// Reset every test-global the emitter / detector look at so a
		// prior test's posture / query context can't leak forward.
		$GLOBALS['wpctx_test_active_plugins'] = array();
		$GLOBALS['wpctx_test_filters']        = array();
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
		self::assertStringContainsString( '<script type="application/ld+json" data-emitted-by="agentready">', $output );

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

		self::assertSame( '', $output, 'agentready_schema_emit=false should suppress emission.' );
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
		if ( ! preg_match( '#<script type="application/ld\+json" data-emitted-by="agentready">\s*(.+?)\s*</script>#s', $output, $m ) ) {
			return null;
		}
		// esc_html() escapes &, <, >, ", ' — but the JSON we produce uses
		// JSON_UNESCAPED_SLASHES + nothing requires those entities, so the
		// only entity html_entity_decode reverses here is &quot; → ".
		$body    = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		$decoded = json_decode( $body, true );
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
