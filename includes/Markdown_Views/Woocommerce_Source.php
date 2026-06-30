<?php
/**
 * WooCommerce source adapter for Markdown Views.
 *
 * Bundled adapter (AgDR-0061) that hooks the `agentready_markdown_source_html`
 * filter so WooCommerce products render their short description in the
 * Markdown View. A product's descriptive copy is frequently the short
 * description (`post_excerpt`, rendered via `woocommerce_short_description`),
 * which `the_content` never emits — so without this, products whose
 * `post_content` is empty rendered a 0-byte `.md` body (#252).
 *
 * The core renderer ({@see Service}) stays post-type-agnostic and offline;
 * WooCommerce support is this opt-in adapter, active only when WooCommerce is.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Prepends a WooCommerce product's short description to the Markdown source.
 */
final class Woocommerce_Source {

	/**
	 * Wire the source filter. Called from `Main::register_hooks()`.
	 *
	 * Registers unconditionally; the callback itself no-ops when WooCommerce
	 * is inactive or the post is not a product, so there is no ordering
	 * dependency on WooCommerce having loaded by registration time.
	 */
	public static function register(): void {
		\add_filter( 'agentready_markdown_source_html', array( self::class, 'prepend_short_description' ), 10, 2 );
	}

	/**
	 * Prepend the product short description to the sourced HTML.
	 *
	 * No-ops (returns `$html` unchanged) unless WooCommerce is active, the post
	 * is a `product`, and the product has a non-empty short description. The
	 * short description is rendered through WooCommerce's own
	 * `woocommerce_short_description` filter so it matches the storefront.
	 *
	 * @param string   $html The `the_content`-rendered HTML.
	 * @param \WP_Post $post The post being rendered.
	 *
	 * @return string HTML with the short description prepended when applicable.
	 */
	public static function prepend_short_description( string $html, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type || ! \function_exists( 'wc_get_product' ) ) {
			return $html;
		}

		$product = \wc_get_product( $post->ID );

		if ( ! $product || ! \method_exists( $product, 'get_short_description' ) ) {
			return $html;
		}

		$short = (string) $product->get_short_description();

		if ( '' === \trim( $short ) ) {
			return $html;
		}

		// `woocommerce_short_description` is WooCommerce's own filter (wptexturize
		// / wpautop / shortcodes) — the canonical way to render the short
		// description, matching the storefront output.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$short_html = (string) \apply_filters( 'woocommerce_short_description', $short );

		return $short_html . "\n" . $html;
	}
}
