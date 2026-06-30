<?php
/**
 * "Messy real-world site" fixture.
 *
 * Seeds the content *shapes* that broke the renderer in live testing of v0.3.1
 * — WooCommerce-style excerpt-only products (#252), page-builder base64 blobs +
 * Revolution-Slider init JS (#253), and a static front page (#254) — without
 * installing the heavy third-party plugins themselves. The fixture is synthetic
 * content only (AC: no client / site-specific data); it reproduces the *input
 * the renderer must survive*, which is what the regression guards assert against.
 *
 * Each seed method is small and named after the bug it reproduces, so adding a
 * new messy case is "write one more seed_* method + one more test" (see
 * tests/Integration/Messy_Site/README.md).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Messy_Site;

use Mokhai\Admin\Context_Profile_Settings;

/**
 * Static seed helpers for the messy-site integration tests.
 */
final class Messy_Site_Fixture {

	/**
	 * Synthetic product post type. A stand-in for WooCommerce's `product` —
	 * same post-type name, so the renderer's product-shaped code paths apply,
	 * but with no WooCommerce dependency.
	 */
	public const PRODUCT_CPT = 'product';

	/**
	 * Descriptive copy seeded into the product's `post_excerpt` (a product's
	 * short description lives here, not in `post_content`). Contains a
	 * distinctive phrase the tests assert on.
	 */
	public const PRODUCT_EXCERPT = 'Hand-poured soy candle with a 40-hour burn time and a bergamot-and-cedar scent.';

	/**
	 * Human prose seeded into the builder page — must survive conversion
	 * (proves the noise strip did not over-strip real content).
	 */
	public const PROSE_MARKER = 'Quality products since 2010';

	/**
	 * Revolution-Slider init-function name. Must NOT appear in rendered MD.
	 */
	public const REV_INIT = 'setREVStartSize';

	/**
	 * Register the synthetic `product` post type if WooCommerce (or a prior
	 * test) hasn't already. Public + excerpt support so it is exposable and
	 * carries a short description.
	 */
	public static function register_product_cpt(): void {
		if ( \post_type_exists( self::PRODUCT_CPT ) ) {
			return;
		}

		\register_post_type(
			self::PRODUCT_CPT,
			array(
				'label'    => 'Products',
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			)
		);
	}

	/**
	 * #252 — a product whose descriptive copy is in `post_excerpt` and whose
	 * `post_content` is empty. `the_content` emits nothing, so without a source
	 * adapter feeding the excerpt the Markdown View renders a 0-byte body.
	 *
	 * @return int Product post ID.
	 */
	public static function seed_excerpt_only_product(): int {
		return (int) \wp_insert_post(
			array(
				'post_type'    => self::PRODUCT_CPT,
				'post_status'  => 'publish',
				'post_title'   => 'Bergamot Candle',
				'post_content' => '',
				'post_excerpt' => self::PRODUCT_EXCERPT,
			)
		);
	}

	/**
	 * #253 — a page built by a base64/URL-encoding page builder (Uncode/WPBakery
	 * family) carrying a Revolution-Slider init `<script>`. The renderer must
	 * drop the script subtree and strip the long base64 run, keeping the prose.
	 *
	 * @return int Page post ID.
	 */
	public static function seed_builder_noise_page(): int {
		// A contiguous base64-charset run well above Walker::BUILDER_BLOB_MIN_LEN
		// (60) — the shape of an encoded page-builder payload.
		$base64_blob = \str_repeat( 'QkFTRTY0', 20 ); // 160 chars, no whitespace.

		$content = '<div class="vc_row" data-builder="uncode">' . "\n"
			. '<p>' . self::PROSE_MARKER . '. We ship worldwide.</p>' . "\n"
			. '<div class="rev_slider_wrapper">' . "\n"
			. '<script type="text/javascript">' . self::REV_INIT . "({c:'rev_slider_1',rl:[1240,1024],el:[768],gw:[1240]});</script>\n"
			. '</div>' . "\n"
			. '<p>' . $base64_blob . '</p>' . "\n"
			. '</div>';

		// Page builders save raw markup — including `<script>` — because the
		// saving admin has `unfiltered_html`. The test runs as an anon user, so
		// KSES would otherwise strip the `<script>` tags on insert and leave the
		// init JS as a bare text node, changing the shape under test. Drop the
		// content filters around the insert so the markup is stored verbatim,
		// exactly as a builder would store it.
		\kses_remove_filters();
		$id = (int) \wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Builder Page',
				'post_content' => $content,
			)
		);
		\kses_init_filters();

		return $id;
	}

	/**
	 * #254 — set a static page as the site front page (Settings → Reading).
	 * The front-page entry's `.md` URL must stay a valid URL, not `host.md`.
	 *
	 * @return int Front-page post ID.
	 */
	public static function set_static_front_page(): int {
		$front = (int) \wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Home',
				'post_content' => '<p>Welcome to our shop.</p>',
			)
		);

		\update_option( 'show_on_front', 'page' );
		\update_option( 'page_on_front', $front );

		return $front;
	}

	/**
	 * Expose the realistic commercial-site surface: posts, pages, and products,
	 * published only. Mirrors what a site owner would tick for an AI-agent
	 * audience.
	 */
	public static function expose_default(): void {
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post', 'page', self::PRODUCT_CPT ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);
	}
}
