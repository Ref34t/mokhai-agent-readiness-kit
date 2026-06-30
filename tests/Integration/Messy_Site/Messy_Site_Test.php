<?php
/**
 * Messy-real-world-site regression guards.
 *
 * v0.3.1 shipped three renderer bugs (#252 empty Woo products, #253 base64
 * builder blobs + slider JS leaking into Markdown, #254 malformed front-page
 * `.md` URL) because CI only ever rendered clean Gutenberg/classic content.
 * This suite renders the messy shapes those bugs came from and fails the build
 * if that class of regression returns.
 *
 * Runs inside the existing wp-env phpunit-integration matrix (no extra CI
 * wiring — anything under tests/Integration/* is collected by the `integration`
 * testsuite). Synthetic content only; the heavy third-party plugins are NOT
 * installed — see Messy_Site_Fixture and the README for why the #252 guard
 * targets the source-html *seam* rather than WooCommerce itself.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Messy_Site;

use WP_UnitTestCase;
use WP_Post;
use WP_Error;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Service;
use Mokhai\LlmsTxt\Service as LlmsTxt_Service;

final class Messy_Site_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();
		Messy_Site_Fixture::register_product_cpt();
		Messy_Site_Fixture::expose_default();
	}

	protected function tearDown(): void {
		// A test that registers a source adapter must not leak it to the next.
		remove_all_filters( 'mokhai_markdown_source_html' );

		// Unregister the synthetic product CPT so it can't leak into other test
		// classes. Guarded on WooCommerce being absent — never unregister a real
		// WooCommerce-owned type.
		if ( ! \function_exists( 'wc_get_product' ) && \post_type_exists( Messy_Site_Fixture::PRODUCT_CPT ) ) {
			\unregister_post_type( Messy_Site_Fixture::PRODUCT_CPT );
		}

		Schema::drop();
		parent::tearDown();
	}

	/**
	 * #252 — a product whose copy lives only in `post_excerpt` must be able to
	 * render a non-empty Markdown body.
	 *
	 * We do not load WooCommerce in CI (synthetic-only fixture), so the bundled
	 * `Woocommerce_Source` adapter — which reads the short description via
	 * `wc_get_product()` and no-ops without WooCommerce — cannot fire here. What
	 * the fix actually *relies on*, and what this test guards, is the
	 * `mokhai_markdown_source_html` seam in `Service::render_source_html()`: a
	 * source adapter can give an empty-`post_content` post a body. We register a
	 * stand-in adapter that supplies the excerpt — the same contract
	 * `Woocommerce_Source` uses — and assert the seam produces non-empty MD.
	 */
	public function test_excerpt_only_product_renders_nonempty_md_via_source_seam(): void {
		$product = \get_post( Messy_Site_Fixture::seed_excerpt_only_product() );
		self::assertInstanceOf( WP_Post::class, $product );

		// Root cause: with no source adapter, the excerpt copy never reaches the
		// MD body (the empty-render that shipped as #252).
		$bare = Service::get_markdown_for_post( $product );
		self::assertNotInstanceOf( WP_Error::class, $bare );
		self::assertStringNotContainsString(
			'40-hour burn time',
			(string) $bare,
			'Without a source adapter the excerpt copy must not appear — this is the #252 root cause.'
		);

		// Stand-in for any source adapter (e.g. the bundled WooCommerce one):
		// inject the excerpt when post_content rendered empty.
		\add_filter(
			'mokhai_markdown_source_html',
			static function ( string $html, WP_Post $post ): string {
				if ( Messy_Site_Fixture::PRODUCT_CPT === $post->post_type && '' === \trim( $html ) ) {
					return '<p>' . \esc_html( $post->post_excerpt ) . '</p>';
				}
				return $html;
			},
			10,
			2
		);

		// The content hash is unchanged (the filter mutates HTML, not stored
		// fields), so drop the cached empty row to force a fresh walk.
		Service::invalidate( $product->ID );

		$md = Service::get_markdown_for_post( $product );
		self::assertNotInstanceOf( WP_Error::class, $md );
		self::assertNotSame(
			'',
			\trim( (string) $md ),
			'A source adapter supplying the excerpt must yield a non-empty MD body (#252 fix seam).'
		);
		self::assertStringContainsString( '40-hour burn time', (string) $md );
	}

	/**
	 * #253 — page-builder base64 blobs and Revolution-Slider init JS must not
	 * leak into the Markdown body, and real prose must survive.
	 */
	public function test_builder_noise_is_stripped_from_md(): void {
		$page = \get_post( Messy_Site_Fixture::seed_builder_noise_page() );
		self::assertInstanceOf( WP_Post::class, $page );

		$result = Service::get_markdown_for_post( $page );
		self::assertNotInstanceOf( WP_Error::class, $result );
		$md = (string) $result;

		self::assertStringNotContainsString(
			Messy_Site_Fixture::REV_INIT,
			$md,
			'Revolution-Slider init JS leaked into the Markdown body (#253).'
		);
		self::assertStringNotContainsString(
			'<script',
			$md,
			'A <script> subtree leaked into the Markdown body (#253).'
		);
		self::assertDoesNotMatchRegularExpression(
			'#[A-Za-z0-9+/]{60,}#',
			$md,
			'A long base64-encoded run survived in the Markdown body (#253).'
		);
		self::assertStringContainsString(
			Messy_Site_Fixture::PROSE_MARKER,
			$md,
			'Real prose was stripped — the noise filter over-stripped (#253).'
		);
	}

	/**
	 * #254 — a static front page's entry in `/llms.txt` must be a valid URL,
	 * never `https://host.md` (`.md` glued straight onto the host because the
	 * front-page permalink is the bare site root).
	 *
	 * Fixed by the root-URL guard in `Url_Mapper::to_md_url()` (#241): a permalink
	 * with no path segment takes the `?format=md` query form instead of the `.md`
	 * suffix. This is the regression guard verifying that holds for the
	 * static-front-page case end to end, through `/llms.txt` composition.
	 */
	public function test_static_front_page_llms_txt_url_is_valid(): void {
		Messy_Site_Fixture::set_static_front_page();

		$body = LlmsTxt_Service::compose_now();

		\preg_match_all( '#https?://\S+#', $body, $matches );
		self::assertNotEmpty( $matches[0], '/llms.txt should advertise at least one URL.' );

		// The bug shape: a URL whose host ends in `.md` (e.g. `https://host.md`).
		foreach ( $matches[0] as $url ) {
			$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
			self::assertNotSame(
				'.md',
				\substr( $host, -3 ),
				"Malformed `.md`-TLD URL in /llms.txt: {$url} (#254)."
			);
		}

		// Non-vacuous: the front page must actually be advertised, and in the
		// valid query form the root-URL guard produces — not absent, not broken.
		self::assertStringContainsString(
			'/?format=md',
			$body,
			'The static front page should be advertised in the valid `?format=md` form (#254).'
		);
	}
}
