<?php
/**
 * Unit tests for the WooCommerce Markdown-source adapter (#252 / AgDR-0061).
 *
 * The adapter prepends a product's short description to the Markdown source so
 * products whose `post_content` is empty no longer render a 0-byte `.md` body.
 * These tests exercise the filter callback directly:
 *
 * 1. No-op when the post is not a `product`.
 * 2. No-op when WooCommerce is inactive / the product has no short description.
 * 3. Happy path — short description prepended ahead of the long-form HTML.
 *
 * A minimal global `wc_get_product()` + fake product stand in for WooCommerce;
 * the `woocommerce_short_description` filter is a passthrough (no callback
 * registered) so the assertion is deterministic.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace {
	// Minimal WooCommerce product double — only the method the adapter calls.
	if ( ! class_exists( 'Mokhai_Test_WC_Product' ) ) {
		class Mokhai_Test_WC_Product {
			/** @var string */
			private string $short;

			public function __construct( string $short ) {
				$this->short = $short;
			}

			public function get_short_description(): string {
				return $this->short;
			}
		}
	}

	// Global WooCommerce factory the adapter probes via function_exists().
	// Returns whatever the active test placed in the global, or false.
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) {
			return $GLOBALS['wpctx_test_wc_product'] ?? false;
		}
	}
}

namespace Mokhai\Tests\Unit\Markdown_Views {

	use PHPUnit\Framework\TestCase;
	use Mokhai\Markdown_Views\Woocommerce_Source;
	use WP_Post;

	final class Woocommerce_Source_Test extends TestCase {

		protected function tearDown(): void {
			unset( $GLOBALS['wpctx_test_wc_product'] );
			parent::tearDown();
		}

		private function make_post( string $type ): WP_Post {
			$post            = new WP_Post();
			$post->ID        = 42;
			$post->post_type = $type;
			return $post;
		}

		public function test_non_product_is_unchanged(): void {
			$GLOBALS['wpctx_test_wc_product'] = new \Mokhai_Test_WC_Product( 'Short desc' );
			$html                             = '<p>Long form.</p>';

			$out = Woocommerce_Source::prepend_short_description( $html, $this->make_post( 'page' ) );

			self::assertSame( $html, $out );
		}

		public function test_product_without_short_description_is_unchanged(): void {
			$GLOBALS['wpctx_test_wc_product'] = new \Mokhai_Test_WC_Product( '   ' );
			$html                             = '<p>Long form.</p>';

			$out = Woocommerce_Source::prepend_short_description( $html, $this->make_post( 'product' ) );

			self::assertSame( $html, $out );
		}

		public function test_missing_product_is_unchanged(): void {
			// No product set → wc_get_product() returns false.
			$html = '<p>Long form.</p>';

			$out = Woocommerce_Source::prepend_short_description( $html, $this->make_post( 'product' ) );

			self::assertSame( $html, $out );
		}

		public function test_short_description_prepended_for_product(): void {
			$GLOBALS['wpctx_test_wc_product'] = new \Mokhai_Test_WC_Product( '<p>Hydrating stick, SPF 30.</p>' );
			$html                             = '<p>Long-form product story.</p>';

			$out = Woocommerce_Source::prepend_short_description( $html, $this->make_post( 'product' ) );

			self::assertStringContainsString( 'Hydrating stick, SPF 30.', $out );
			self::assertStringContainsString( 'Long-form product story.', $out );
			// Short description leads the body.
			self::assertLessThan(
				strpos( $out, 'Long-form product story.' ),
				strpos( $out, 'Hydrating stick, SPF 30.' )
			);
		}

		public function test_product_with_empty_long_form_still_yields_short(): void {
			$GLOBALS['wpctx_test_wc_product'] = new \Mokhai_Test_WC_Product( '<p>Only the short description.</p>' );

			$out = Woocommerce_Source::prepend_short_description( '', $this->make_post( 'product' ) );

			self::assertStringContainsString( 'Only the short description.', $out );
		}
	}
}
