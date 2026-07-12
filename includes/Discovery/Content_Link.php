<?php
/**
 * In-content markdown discovery link.
 *
 * The third — and only stripping-proof — discovery channel (#283 /
 * AgDR-0067). Empirical tests (2026-07-12, real fetchers) showed that both
 * Claude's and ChatGPT's URL fetchers strip everything
 * `Alternate_Advertiser` emits: `<head>` alternate links, meta tags, and
 * HTTP `Link` headers. Only the converted body text reaches the model. A
 * link INSIDE the content survives both — so this module appends one to
 * `the_content` on exposable singular views.
 *
 * Three empirically-driven design constraints:
 *
 *   1. Hidden via a STYLESHEET CLASS using the offscreen pattern — never an
 *      inline `style` attribute or the `hidden` attribute, which
 *      Readability-class extraction pipelines check and drop. Extractors
 *      don't download CSS, so a class-hidden anchor is indistinguishable
 *      from a visible link to them while browsers never paint it.
 *   2. The anchor TEXT carries the literal URL — ChatGPT's page viewer
 *      exposes anchor text but strips `href` values, so a bare label would
 *      leave the agent unable to quote or construct the address.
 *   3. Injected inside the content container — main-content extractors keep
 *      the article body and discard header / footer chrome.
 *
 * The link target follows the delivery surface: the static mirror file when
 * `Static_Mirror` is active (worst-case-proof: served without PHP), else the
 * canonical `/path.md` route. Baked into the page HTML, the link survives
 * inside cached copies — discovery keeps working even when PHP never runs.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Discovery;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Static_Mirror;
use Mokhai\Markdown_Views\Url_Mapper;

\defined( 'ABSPATH' ) || exit;

/**
 * Appends the hidden in-content discovery anchor.
 */
final class Content_Link {

	/**
	 * CSS class carrying the offscreen rule. Public so themes can restyle
	 * (or reveal) the link deliberately.
	 *
	 * @var string
	 */
	public const CSS_CLASS = 'mokhai-md-link';

	/**
	 * Wire the content filter + the one-rule stylesheet. Called once from
	 * `Main::register_hooks()`.
	 *
	 * `the_content` at 99: after core formatters (wpautop at 10, shortcodes
	 * at 11) so the anchor lands at the very end of the finished body.
	 * `wp_head` at 5: before typical theme styles, so the rule exists by the
	 * time the anchor first paints — no flash of a visible link.
	 */
	public static function register_hooks(): void {
		// Core filter consumed, not defined — same annotation rationale as
		// Markdown_Views\Service::render_source_html().
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\add_filter( 'the_content', array( self::class, 'append_link' ), 99, 1 );
		\add_action( 'wp_head', array( self::class, 'print_style' ), 5, 0 );
	}

	/**
	 * `the_content` callback: append the hidden anchor on the main rendered
	 * body of an exposable singular view.
	 *
	 * The `in_the_loop() && is_main_query()` guard is load-bearing twice
	 * over: it scopes injection to the page's own content (not widgets or
	 * secondary loops), and it keeps the anchor OUT of the plugin's own
	 * markdown pipeline — `Markdown_Views\Service::render_source_html()` and
	 * `LlmsTxt\Service` both apply `the_content` outside any loop, and a
	 * discovery link recursively embedded in the `.md` body it points to
	 * would be garbage in every consumer.
	 *
	 * @param string $content Rendered post content.
	 *
	 * @return string
	 */
	public static function append_link( $content ): string {
		$content = (string) $content;

		if ( ! self::should_inject() ) {
			return $content;
		}

		$url = self::target_url();
		if ( '' === $url ) {
			return $content;
		}

		return $content . "\n" . self::build_anchor( $url );
	}

	/**
	 * `wp_head` callback: print the offscreen rule.
	 *
	 * A `<style>` ELEMENT in the head — not an inline `style` ATTRIBUTE on
	 * the anchor. Extractors that sniff inline styles never see an element
	 * stylesheet; browsers apply it identically. Printed only where the
	 * anchor can appear (enabled + singular) so every other page stays
	 * byte-identical.
	 */
	public static function print_style(): void {
		if ( ! self::enabled() || ! \function_exists( 'is_singular' ) || ! \is_singular() ) {
			return;
		}

		echo '<style id="' . \esc_attr( self::CSS_CLASS ) . '-css">.'
			. \esc_attr( self::CSS_CLASS )
			. '{position:absolute!important;left:-9999px!important;top:auto!important;'
			. 'width:1px!important;height:1px!important;overflow:hidden!important}</style>' . "\n";
	}

	/* ---------------------------------------------------------------------
	 * Pure builders (unit-tested directly)
	 * ------------------------------------------------------------------- */

	/**
	 * Build the hidden anchor markup.
	 *
	 * `aria-hidden` + negative tabindex: the link is machine-facing — a
	 * screen-reader announcement or a keyboard focus stop on an invisible
	 * element would each be an accessibility regression, and assistive tech
	 * is not the audience (agents read the extracted text, where ARIA does
	 * not apply).
	 *
	 * @param string $url Target `.md` URL.
	 *
	 * @return string
	 */
	public static function build_anchor( string $url ): string {
		return '<a class="' . \esc_attr( self::CSS_CLASS ) . '" href="' . \esc_url( $url ) . '"'
			. ' aria-hidden="true" tabindex="-1">'
			. \sprintf(
				/* translators: %s: URL of the markdown version of the page. */
				\esc_html__( 'Markdown version of this page: %s', 'mokhai-agent-readiness-kit' ),
				\esc_html( $url )
			)
			. '</a>';
	}

	/* ---------------------------------------------------------------------
	 * Internal resolvers (touch globals / options)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the in-content link is enabled in the Context Profile.
	 * Default-true convention (same as `advertise_alternates_enabled`).
	 */
	private static function enabled(): bool {
		$profile = Context_Profile_Settings::get_profile();

		return ! \array_key_exists( 'content_link_enabled', $profile )
			|| ! empty( $profile['content_link_enabled'] );
	}

	/**
	 * All request-context gates for injection.
	 */
	private static function should_inject(): bool {
		if ( ! \function_exists( 'is_singular' ) || ! \is_singular() ) {
			return false;
		}

		if ( ! \in_the_loop() || ! \is_main_query() ) {
			return false;
		}

		if ( \is_feed() || ( \defined( 'REST_REQUEST' ) && \REST_REQUEST ) ) {
			return false;
		}

		if ( ! self::enabled() ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the link target for the current post, or `''` when nothing
	 * should be linked (module off / not exposable — the same predicate the
	 * route serves on, so the link can never point at a 404).
	 */
	private static function target_url(): string {
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return '';
		}

		$post = \get_post();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return '';
		}

		// Worst-case-proof surface first: the static mirror file needs no PHP.
		$static_url = Static_Mirror::file_url_for_post( $post );
		if ( \is_string( $static_url ) && '' !== $static_url ) {
			return $static_url;
		}

		$permalink = \get_permalink( $post );
		if ( ! \is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		return Url_Mapper::to_md_url( $permalink );
	}
}
