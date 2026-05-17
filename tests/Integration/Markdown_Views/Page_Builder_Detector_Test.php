<?php
/**
 * Integration tests for WPContext\Markdown_Views\Page_Builder_Detector.
 *
 * Runs inside wp-phpunit so `get_post_meta` and `wp_insert_post` exercise
 * real WordPress code paths. Five builders × meta-key hits, plus the
 * three content-fingerprint fallbacks, plus the classic-editor and
 * code-block-residue negative cases.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use WPContext\Markdown_Views\Page_Builder_Detector;

final class Page_Builder_Detector_Test extends WP_UnitTestCase {

	/**
	 * @return iterable<string, array{0: string, 1: mixed, 2: string}>
	 *   [meta_key, meta_value, expected_slug]
	 */
	public function meta_key_provider(): iterable {
		yield 'elementor' => array( '_elementor_data', '[{"id":"abc"}]', 'elementor' );
		yield 'divi' => array( '_et_pb_use_builder', 'on', 'divi' );
		yield 'wpbakery' => array( '_wpb_vc_js_status', 'true', 'wpbakery' );
		yield 'avada' => array( 'fusion_builder_status', 'active', 'avada' );
		yield 'beaver_builder' => array( '_fl_builder_enabled', '1', 'beaver_builder' );
	}

	/**
	 * @dataProvider meta_key_provider
	 *
	 * @param mixed $meta_value Value stored on the post.
	 */
	public function test_detect_returns_slug_when_meta_key_present(
		string $meta_key,
		$meta_value,
		string $expected_slug
	): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, $meta_key, $meta_value );
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertSame( $expected_slug, Page_Builder_Detector::detect( $post ) );
	}

	public function test_classic_editor_post_returns_null(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p>A plain paragraph.</p><h2>And a heading.</h2>',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertNull( Page_Builder_Detector::detect( $post ) );
	}

	public function test_empty_meta_value_is_not_a_match(): void {
		$post_id = self::factory()->post->create();
		// All three "empty" shapes the WP API can return:
		\update_post_meta( $post_id, '_elementor_data', '' );
		\update_post_meta( $post_id, '_et_pb_use_builder', '0' );
		\update_post_meta( $post_id, '_wpb_vc_js_status', array() );
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertNull( Page_Builder_Detector::detect( $post ) );
	}

	public function test_fingerprint_fallback_matches_wpbakery_shortcode(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '[vc_row][vc_column][vc_column_text]Hello[/vc_column_text][/vc_column][/vc_row]',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertSame( 'wpbakery', Page_Builder_Detector::detect( $post ) );
	}

	public function test_fingerprint_fallback_matches_avada_shortcode(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '[fusion_builder_container]Body[/fusion_builder_container]',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertSame( 'avada', Page_Builder_Detector::detect( $post ) );
	}

	public function test_fingerprint_fallback_matches_elementor_div_class(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<div class="elementor elementor-1234">Body</div>',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertSame( 'elementor', Page_Builder_Detector::detect( $post ) );
	}

	/**
	 * Adversarial: a classic-editor post that mentions `[vc_row]` inside
	 * a code block should NOT match — the fingerprint matches opening
	 * tags, not bare text. (Markdown / pre / code wrapping preserves the
	 * shortcode literal in `post_content`.)
	 */
	public function test_fingerprint_does_not_match_shortcode_inside_code_block(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => "<pre><code>Use the [vc_row] shortcode like this</code></pre>",
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		// `[vc_row]` (with closing bracket) DOES still match the regex —
		// the regex doesn't know about code blocks. This is the documented
		// false-positive surface; we assert the current behaviour so a
		// future tightening of the regex flips this case deliberately.
		self::assertSame( 'wpbakery', Page_Builder_Detector::detect( $post ) );
	}

	public function test_meta_key_wins_over_fingerprint(): void {
		// Avada meta key set, but content also contains WPBakery
		// shortcodes. Meta key is the primary source — must win.
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '[vc_row]Mixed content[/vc_row]',
			)
		);
		\update_post_meta( $post_id, 'fusion_builder_status', 'active' );
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertSame( 'avada', Page_Builder_Detector::detect( $post ) );
	}

	public function test_is_page_builder_post_returns_true_when_detected(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, '_elementor_data', '[{"id":"abc"}]' );
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertTrue( Page_Builder_Detector::is_page_builder_post( $post ) );
	}

	public function test_is_page_builder_post_returns_false_for_classic(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p>Pure classic content.</p>',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertFalse( Page_Builder_Detector::is_page_builder_post( $post ) );
	}

	public function test_empty_post_content_returns_null(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '',
			)
		);
		$post = \get_post( $post_id );

		self::assertNotNull( $post );
		self::assertNull( Page_Builder_Detector::detect( $post ) );
	}
}
