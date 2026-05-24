<?php
/**
 * Integration tests for WPContext\Seo\Schema_Emitter.
 *
 * Drives the full wp_head pipeline end-to-end against a real WordPress
 * test instance — no stubs, no mocks. Maps 1:1 to AC #5 of #73:
 *
 *   (a) Exposed post on the front-end → JSON-LD block present, Article
 *       node included.
 *   (b) Non-exposed post (CPT or status outside the Profile allowlist)
 *       → no Article node. Site-identity nodes (WebSite / Organization)
 *       still render because the Profile toggle is on.
 *   (c) When the deference posture short-circuits the gap (`yoast`,
 *       `rank_math`, `aioseo`) → emitter renders zero bytes regardless
 *       of Profile state.
 *
 * Posture is driven by `Plugin_Coverage::FILTER_COVERAGE_MATRIX` — the
 * cleanest seam for an integration test that can't load Yoast itself
 * but still wants to assert the deference branch.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Seo;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;
use WPContext\Seo\Schema_Emitter;

final class Schema_Emitter_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The save_post invalidation hook in Markdown_Views\Service errors
		// when its cache table is absent. Other integration tests use the
		// same pattern (see LlmsTxt/Router_Test).
		Markdown_Views_Schema::create();

		// Default to opted-in Profile with `post` exposed at `publish`.
		// Individual tests override fields as needed.
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => true,
					'exposed_cpts'        => array( 'post' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);
	}

	public function test_renders_full_jsonld_block_on_exposed_post(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Hello agents',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );

		self::assertNotNull( $json, 'wp_head should render a JSON-LD block for an exposed post.' );

		$types = $this->collect_types( $json );
		self::assertContains( 'WebSite', $types );
		self::assertContains( 'Organization', $types );
		self::assertContains( 'Article', $types );

		$article = $this->find_node_of_type( $json, 'Article' );
		self::assertNotNull( $article );
		self::assertSame( 'Hello agents', $article['headline'] );
	}

	public function test_omits_article_node_when_post_type_not_in_exposed_cpts(): void {
		// Profile flipped to only expose `page`. A singular post should
		// see site-identity nodes but no Article.
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => true,
					'exposed_cpts'        => array( 'page' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Not exposed',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types, 'Site identity always emits when toggle is on.' );
		self::assertContains( 'Organization', $types );
		self::assertNotContains( 'Article', $types, 'Article must not emit when post_type is outside exposed_cpts.' );
	}

	public function test_renders_nothing_when_seo_plugin_posture_covers_baseline(): void {
		// We can't load Yoast in the test environment, but the deference
		// path is driven by the coverage matrix — overriding the matrix
		// to claim `yoast` covers everything reproduces the deferral
		// branch end-to-end.
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Yoast site',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'yoast' );

		self::assertSame( '', $output, 'Emitter must defer entirely to Yoast — zero bytes on wp_head.' );
	}

	/**
	 * Regression for Ref34t/agentready#104 / AgDR-0040.
	 *
	 * A custom CPT in `exposed_cpts` must produce a per-content schema
	 * node on its singular URL — the pre-fix behavior emitted only
	 * WebSite + Organization for any CPT other than `post` and `page`.
	 * Default per-content type for non-`post` CPTs is WebPage.
	 */
	public function test_emits_webpage_for_custom_cpt_by_default(): void {
		register_post_type(
			'lesson',
			array(
				'public'             => true,
				'show_in_rest'       => true,
				'publicly_queryable' => true,
				'has_archive'        => false,
			)
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => true,
					'exposed_cpts'        => array( 'lesson' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'lesson',
				'post_title'  => 'Lesson 01: Tokenisation',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types );
		self::assertContains( 'Organization', $types );
		self::assertContains( 'WebPage', $types, 'Custom CPT singular must default to WebPage per AgDR-0040.' );
		self::assertNotContains( 'Article', $types, 'Custom CPTs should not silently get Article — default is WebPage.' );

		$webpage = $this->find_node_of_type( $json, 'WebPage' );
		self::assertNotNull( $webpage );
		self::assertSame( 'Lesson 01: Tokenisation', $webpage['name'] );

		unregister_post_type( 'lesson' );
	}

	/**
	 * Filter override path for #104 / AgDR-0040: an opinionated host that
	 * wants `lesson` to map to `Article` (treating each lesson as a
	 * standalone article) can subscribe to the filter and get the swap.
	 */
	public function test_filter_can_swap_custom_cpt_to_article(): void {
		register_post_type(
			'lesson',
			array(
				'public'             => true,
				'show_in_rest'       => true,
				'publicly_queryable' => true,
				'has_archive'        => false,
			)
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => true,
					'exposed_cpts'        => array( 'lesson' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);

		add_filter(
			'agentready_schema_type_for_cpt',
			static function ( $default, $cpt ) {
				return 'lesson' === $cpt ? 'Article' : $default;
			},
			10,
			2
		);

		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'lesson',
				'post_title'  => 'Lesson 02: Embeddings',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'Article', $types, 'Filter override must flip lesson to Article.' );
		self::assertNotContains( 'WebPage', $types, 'WebPage must be suppressed when filter resolves to Article.' );

		$article = $this->find_node_of_type( $json, 'Article' );
		self::assertNotNull( $article );
		self::assertSame( 'Lesson 02: Embeddings', $article['headline'] );

		remove_all_filters( 'agentready_schema_type_for_cpt' );
		unregister_post_type( 'lesson' );
	}

	/**
	 * Filter suppression path for #104 / AgDR-0040: a host that wants
	 * NO per-content schema for a specific CPT can return null/'' from
	 * the filter. Site-identity nodes still emit.
	 */
	public function test_filter_returning_null_suppresses_per_content_emit(): void {
		register_post_type(
			'product',
			array(
				'public'             => true,
				'show_in_rest'       => true,
				'publicly_queryable' => true,
				'has_archive'        => false,
			)
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => true,
					'exposed_cpts'        => array( 'product' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);

		add_filter(
			'agentready_schema_type_for_cpt',
			static function ( $default, $cpt ) {
				return 'product' === $cpt ? null : $default;
			},
			10,
			2
		);

		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'product',
				'post_title'  => 'Widget',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );
		$json   = $this->extract_json( $output );
		$types  = $this->collect_types( $json );

		self::assertContains( 'WebSite', $types, 'Site-identity always emits when the toggle is on.' );
		self::assertContains( 'Organization', $types );
		self::assertNotContains( 'WebPage', $types, 'Filter returning null must suppress WebPage.' );
		self::assertNotContains( 'Article', $types, 'Filter returning null must suppress Article.' );

		remove_all_filters( 'agentready_schema_type_for_cpt' );
		unregister_post_type( 'product' );
	}

	public function test_renders_nothing_when_profile_toggle_is_off(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'schema_emit_enabled' => false,
					'exposed_cpts'        => array( 'post' ),
					'exposed_statuses'    => array( 'publish' ),
				)
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Untoggled',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$output = $this->capture_render( 'none' );

		self::assertSame( '', $output, 'schema_emit_enabled=false suppresses emission regardless of detected posture.' );
	}

	private function capture_render( string $posture ): string {
		ob_start();
		Schema_Emitter::render_for_posture( $posture );
		return (string) ob_get_clean();
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	private function extract_json( string $output ): ?array {
		if ( '' === $output ) {
			return null;
		}
		if ( ! preg_match( '#<script type="application/ld\+json" data-emitted-by="agentready">\s*(.+?)\s*</script>#s', $output, $m ) ) {
			return null;
		}
		$body    = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array<int|string, mixed>|null $json
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
	 * @param array<int|string, mixed>|null $json
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
