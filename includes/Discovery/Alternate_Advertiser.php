<?php
/**
 * Advertise the plugin's agent surfaces through standard discovery channels.
 *
 * Mokhai generates `/llms.txt` and per-page `.md` twins, but unless they're
 * announced only an agent that already knows the `llms.txt` convention (or
 * guesses that appending `.md` works) finds them (#178). This module announces
 * them through three standard channels so any agent that merely reads response
 * metadata discovers the agent-readable content:
 *
 *   1. `wp_head`      — `<link rel="alternate">` tags (`.md` on exposable
 *                       singular views; `/llms.txt` on the front page).
 *   2. `send_headers` — an HTTP `Link: …; rel="alternate"; type="text/markdown"`
 *                       header on exposable singular views.
 *   3. `robots_txt`   — an absolute reference to `/llms.txt`.
 *
 * Everything is gated on the `advertise_alternates_enabled` Context Profile flag
 * and the existing exposure model, so noindex / excluded content is never
 * advertised. A `.md` known to be an empty twin is also suppressed (#296 /
 * AgDR-0070), so the advertiser never announces a twin it knows to be empty; a
 * not-yet-rendered genuinely-empty page may 404 once on first fetch, then
 * self-suppresses once its empty row is cached. No AI, no external calls.
 *
 * Behaviour-vs-side-effects split (mirrors `LlmsTxt\Router` / `Markdown_Views\
 * Handler`): the `build_*` / `augment_robots_txt` methods are pure string
 * assembly, unit-testable directly; the hook callbacks resolve globals and
 * echo / send headers.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Discovery;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Service;
use Mokhai\Markdown_Views\Url_Mapper;

\defined( 'ABSPATH' ) || exit;

/**
 * Emits markdown / llms.txt alternate-discovery hints.
 */
final class Alternate_Advertiser {

	/**
	 * Wire the three discovery hooks. Called once from `Main::register_hooks()`.
	 *
	 * `wp_head` at priority 10 mirrors `Seo\Schema_Emitter`. `send_headers`
	 * carries the `WP` instance (unused — we resolve via the query conditionals).
	 */
	public static function register_hooks(): void {
		\add_action( 'wp_head', array( self::class, 'render_head_links' ), 10, 0 );
		\add_action( 'send_headers', array( self::class, 'send_link_header' ), 10, 0 );
		// The robots_txt restricted-hook sniff targets WordPress VIP, where the
		// platform owns robots.txt. This is a distributed plugin whose whole job
		// is agent discovery — augmenting the virtual robots.txt is intentional.
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt
		\add_filter( 'robots_txt', array( self::class, 'filter_robots_txt' ), 10, 2 );
	}

	/**
	 * `wp_head` callback: emit alternate `<link>` tags.
	 *
	 * - exposable singular view → the page's `.md` twin
	 * - front page              → the site `/llms.txt`
	 */
	public static function render_head_links(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$md_url = self::current_md_url();
		if ( '' !== $md_url ) {
			// esc_url handles the HTML-attribute context; phpcs can't see that
			// the builder escapes, so the echo is annotated.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::build_md_link_tag( $md_url );
		}

		if ( \function_exists( 'is_front_page' ) && \is_front_page() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::build_llms_link_tag( self::llms_txt_url() );
		}
	}

	/**
	 * `send_headers` callback: emit the `Link` header on an exposable singular
	 * view. Appends (`false` third arg) so other plugins' `Link` headers are
	 * preserved. Skipped once output has started — a header can't be sent then.
	 */
	public static function send_link_header(): void {
		if ( ! self::enabled() || \headers_sent() ) {
			return;
		}

		$md_url = self::current_md_url();
		if ( '' === $md_url ) {
			return;
		}

		\header( 'Link: ' . self::build_md_link_header( $md_url ), false );
	}

	/**
	 * `robots_txt` filter: append an absolute reference to `/llms.txt`.
	 *
	 * Honours `$public` — when the site is set to discourage search engines WP
	 * emits `Disallow: /`, and advertising the index then would contradict the
	 * operator's intent, so we leave the output untouched.
	 *
	 * Known limitation: the `robots_txt` filter only runs for WordPress's
	 * *virtual* robots.txt. A site that ships a static `robots.txt` file bypasses
	 * this filter entirely (documented in AgDR-0053).
	 *
	 * @param string $output    The robots.txt content WP has assembled so far.
	 * @param bool   $is_public Whether the site is publicly indexable.
	 *
	 * @return string The (possibly augmented) robots.txt content.
	 */
	public static function filter_robots_txt( $output, $is_public ): string {
		$output = (string) $output;

		if ( ! self::enabled() || ! $is_public ) {
			return $output;
		}

		return self::augment_robots_txt( $output, self::llms_txt_url() );
	}

	/* ---------------------------------------------------------------------
	 * Pure builders (unit-tested directly)
	 * ------------------------------------------------------------------- */

	/**
	 * Build the `<head>` markdown-alternate link tag (trailing newline).
	 *
	 * @param string $md_url The page's `.md` URL.
	 *
	 * @return string
	 */
	public static function build_md_link_tag( string $md_url ): string {
		return '<link rel="alternate" type="text/markdown" href="' . \esc_url( $md_url ) . '" />' . "\n";
	}

	/**
	 * Build the `<head>` llms.txt-alternate link tag (trailing newline).
	 *
	 * @param string $llms_url The absolute `/llms.txt` URL.
	 *
	 * @return string
	 */
	public static function build_llms_link_tag( string $llms_url ): string {
		return '<link rel="alternate" type="text/plain" href="' . \esc_url( $llms_url ) . '" />' . "\n";
	}

	/**
	 * Build the value of the `Link` HTTP header (the `Link:` prefix is added by
	 * the caller). `esc_url_raw` — a header is not an HTML-attribute context.
	 *
	 * @param string $md_url The page's `.md` URL.
	 *
	 * @return string
	 */
	public static function build_md_link_header( string $md_url ): string {
		return '<' . \esc_url_raw( $md_url ) . '>; rel="alternate"; type="text/markdown"';
	}

	/**
	 * Append the `/llms.txt` reference to robots.txt as a comment line.
	 *
	 * A comment (not a bespoke directive) is used deliberately: robots.txt has
	 * no standard `llms.txt` field, and an unknown directive can trip strict
	 * parsers. The absolute URL in the comment satisfies discovery without that
	 * risk (AgDR-0053).
	 *
	 * @param string $output   Existing robots.txt content.
	 * @param string $llms_url Absolute `/llms.txt` URL.
	 *
	 * @return string
	 */
	public static function augment_robots_txt( string $output, string $llms_url ): string {
		$line = '# AI-readable content index (mokhai): ' . \esc_url_raw( $llms_url );

		return \rtrim( $output, "\n" ) . "\n" . $line . "\n";
	}

	/* ---------------------------------------------------------------------
	 * Internal resolvers (touch globals / options)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether agent-surface advertising is enabled in the Context Profile.
	 */
	private static function enabled(): bool {
		$profile = Context_Profile_Settings::get_profile();
		return ! empty( $profile['advertise_alternates_enabled'] );
	}

	/**
	 * The `.md` URL for the current request, or `''` when nothing should be
	 * advertised: not a singular view, the Markdown Views module is off, or the
	 * post isn't exposable.
	 *
	 * Gates on EXACTLY what `Markdown_Views\Service::get_markdown_for_post()`
	 * serves on — `is_module_enabled('markdown_views')` + `is_url_exposable()`
	 * (the latter denies cpt / status / password-protected / noindexed posts) —
	 * plus a render-free known-empty-twin check (#296 / AgDR-0070) so a page
	 * whose cached twin is empty (and therefore 404s per AgDR-0068) is not
	 * advertised. See AgDR-0053.
	 */
	private static function current_md_url(): string {
		if ( ! \function_exists( 'is_singular' ) || ! \is_singular() ) {
			return '';
		}

		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return '';
		}

		$post = \get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return '';
		}

		// Don't advertise a twin known to be empty — AgDR-0068's guard makes that
		// `.md` route 404 (#296 / AgDR-0070). This is a render-free cache read, so
		// it adds no render / loopback cost to the page view. A not-yet-rendered
		// genuinely-empty page may still 404 once on first fetch, then
		// self-suppresses once its empty row is cached.
		if ( Service::is_known_empty_twin( $post ) ) {
			return '';
		}

		$url = \get_permalink( $post );
		if ( ! \is_string( $url ) || '' === $url ) {
			return '';
		}

		return Url_Mapper::to_md_url( $url );
	}

	/**
	 * Absolute URL of the site `/llms.txt`.
	 */
	private static function llms_txt_url(): string {
		return \home_url( '/llms.txt' );
	}
}
