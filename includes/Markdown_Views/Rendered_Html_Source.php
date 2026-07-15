<?php
/**
 * Rendered-HTML source adapter for Markdown Views — the source of last resort.
 *
 * Bundled adapter (AgDR-0069) that hooks the `mokhai_markdown_source_html`
 * filter at a LATE priority (20, after the ACF adapter at 10) so it runs only
 * when every earlier source left the body empty. It loopback-fetches the post's
 * own rendered front-end HTML, isolates the main-content region with a
 * self-contained DOMDocument heuristic (no runtime dependency), and hands that
 * HTML to the Walker — the same seam the WooCommerce (AgDR-0061) and ACF
 * (AgDR-0068) adapters use.
 *
 * This recovers content that lives only in raw postmeta + the front-end render
 * (ACF-in-templates, Sage/Acorn, any theme-template builder) and is reachable
 * via neither `the_content` NOR the ACF API — the exact #297 failure the ACF
 * adapter could not solve. It is theme- and builder-agnostic because it reads
 * what the page actually renders, which is what an AI agent fetching the page
 * would see.
 *
 * Cost & safety (AgDR-0069):
 *  - Fires only on a cache-MISS render of an otherwise-empty twin, so cache hits
 *    pay nothing and a genuinely-empty page fetches once per content-hash.
 *  - A DB-backed post-scoped transient lock ({@see LOCK_PREFIX}) is the
 *    load-bearing recursion guard — it crosses the PHP-FPM process boundary the
 *    loopback creates, which a user-agent check cannot. An in-request marker
 *    ({@see MARKER}) is the secondary guard.
 *  - Only HTTP 200 with a non-empty body is extracted; a 401 auth wall or 500
 *    error page is refused (never cached as the twin).
 *  - Same-host only; `reject_unsafe_urls` on the request.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

use Mokhai\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

// PHP's DOM API exposes camelCase property names ($node->childNodes,
// $node->parentNode, $node->textContent, $node->ownerDocument). The WPCS
// snake_case sniff flags every access; they are native DOM properties we cannot
// rename. Disabled file-wide, matching Walker.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Sources the rendered front-end HTML into the Markdown body when every other
 * source produced nothing.
 */
final class Rendered_Html_Source {

	/**
	 * Filter priority. Runs after WooCommerce / ACF (both 10) so the loopback
	 * is genuinely last-resort and never duplicates content those cheaper,
	 * no-HTTP adapters already produced.
	 *
	 * @var int
	 */
	private const FILTER_PRIORITY = 20;

	/**
	 * Query-arg marker added to the loopback URL so the request handling the
	 * loopback can recognise "this is Mokhai fetching its own page" and no-op
	 * the adapter — the secondary recursion guard behind the transient lock.
	 *
	 * @var string
	 */
	public const MARKER = 'mokhai_render';

	/**
	 * Self-identifying user-agent for the loopback request — for server logs /
	 * request attribution only. It is deliberately NOT read as a recursion
	 * guard: a WAF can strip it, and server-side UA logic is unreliable behind a
	 * page cache. The query marker + the DB lock are the real guards.
	 *
	 * @var string
	 */
	private const USER_AGENT = 'mokhai-render-fetch/1.0';

	/**
	 * Transient key prefix for the per-post render lock. Set before the fetch,
	 * deleted on completion (success or failure); the TTL is only a crash-safety
	 * net so a fatal mid-fetch can't wedge a post's twin for longer than this.
	 *
	 * @var string
	 */
	private const LOCK_PREFIX = 'mokhai_render_lock_';

	/**
	 * Render-lock TTL in seconds. Comfortably longer than the request timeout
	 * so the lock never expires mid-fetch, short enough that a crash self-heals
	 * quickly.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 30;

	/**
	 * Loopback request timeout in seconds.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT = 5;

	/**
	 * Response-size ceiling in bytes. Bounds memory on a pathological page;
	 * 2 MB covers any realistic rendered document.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 2_097_152;

	/**
	 * Chrome element tags stripped before region selection — these never carry
	 * the primary content.
	 *
	 * @var array<int, string>
	 */
	private const CHROME_TAGS = array( 'nav', 'header', 'footer', 'aside', 'form', 'script', 'style', 'noscript', 'template' );

	/**
	 * ARIA landmark roles whose subtree is chrome, not content.
	 *
	 * @var array<int, string>
	 */
	private const CHROME_ROLES = array( 'navigation', 'banner', 'contentinfo', 'search', 'complementary' );

	/**
	 * Ordered class/id content-container hints, tried after `<main>` / `<article>`
	 * / `[role=main]` and before the density fallback.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_HINTS = array( 'entry-content', 'site-content', 'content-area', 'post-content', 'page-content' );

	/**
	 * Minimum text length (chars) a density-fallback winner must carry to be
	 * trusted over the chrome-stripped whole-body fallback.
	 *
	 * @var int
	 */
	private const DENSITY_MIN_CHARS = 200;

	/**
	 * Wire the source filter. Called from `Main::register_hooks()`.
	 *
	 * Registers unconditionally; the callback no-ops unless the body is still
	 * empty and the fetch succeeds, so there is no ordering dependency on any
	 * theme/plugin having loaded by registration time.
	 */
	public static function register(): void {
		\add_filter( 'mokhai_markdown_source_html', array( self::class, 'append_rendered_html' ), self::FILTER_PRIORITY, 2 );
	}

	/**
	 * Append the rendered front-end HTML when every earlier source came back
	 * empty.
	 *
	 * No-ops (returns `$html` unchanged) when: an earlier source already
	 * produced visible text; the feature is disabled; this request is itself a
	 * Mokhai loopback; a render for this post is already in flight; the permalink
	 * is cross-host; the fetch fails or is non-200; or extraction yields nothing.
	 *
	 * @param string   $html The HTML sourced so far (`the_content` + earlier adapters).
	 * @param \WP_Post $post The post being rendered.
	 *
	 * @return string HTML with the rendered body substituted when applicable.
	 */
	public static function append_rendered_html( string $html, \WP_Post $post ): string {
		// Last resort only: bail the moment any earlier source produced text.
		if ( '' !== \trim( \wp_strip_all_tags( $html ) ) ) {
			return $html;
		}

		if ( ! self::is_enabled( $post ) ) {
			return $html;
		}

		// Secondary guard: never fetch while handling our own loopback request.
		if ( self::is_self_fetch() ) {
			return $html;
		}

		$post_id = (int) $post->ID;

		// Load-bearing cross-process guard: a render for this post is already in
		// flight (this worker, or the loopback worker, or a concurrent agent).
		if ( self::is_locked( $post_id ) ) {
			return $html;
		}

		$permalink = (string) \get_permalink( $post );
		if ( '' === $permalink || ! self::is_same_host( $permalink ) ) {
			return $html;
		}

		self::lock( $post_id );

		try {
			$rendered = self::fetch_rendered_html( $permalink );
			if ( null === $rendered ) {
				return $html;
			}

			$extracted = self::extract_main_html( $rendered );
			if ( '' === \trim( \wp_strip_all_tags( $extracted ) ) ) {
				return $html;
			}

			// `$html` is empty here (guarded at the top), so the extracted body
			// IS the source. Trim to avoid a leading blank line.
			return \trim( $extracted );
		} finally {
			self::unlock( $post_id );
		}
	}

	/**
	 * Whether the loopback fallback is enabled for this post.
	 *
	 * Gated by the `mokhai_markdown_loopback_enabled` filter (default true) and
	 * an optional Context Profile `rendered_html_fallback` = `off` kill switch.
	 *
	 * @param \WP_Post $post The post being rendered.
	 */
	private static function is_enabled( \WP_Post $post ): bool {
		$profile = Context_Profile_Settings::get_profile();
		if ( isset( $profile['rendered_html_fallback'] ) && 'off' === $profile['rendered_html_fallback'] ) {
			return false;
		}

		/**
		 * Filter whether the rendered-HTML loopback fallback runs.
		 *
		 * @param bool     $enabled Default true.
		 * @param \WP_Post $post    The post being rendered.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- constant IS mokhai-prefixed.
		return (bool) \apply_filters( 'mokhai_markdown_loopback_enabled', true, $post );
	}

	/**
	 * Is the CURRENT request a Mokhai loopback self-fetch? Detected by the
	 * `mokhai_render` query marker the outbound loopback URL carries — this
	 * rides in the URL, so it is reliable regardless of UA stripping or page
	 * caching (a marked URL is a distinct cache key).
	 */
	private static function is_self_fetch(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check on a self-generated URL marker; no state change.
		return isset( $_GET[ self::MARKER ] );
	}

	/**
	 * Loopback-fetch the rendered HTML for a permalink.
	 *
	 * Returns the response body only on HTTP 200 with a non-empty body; any
	 * other status (401 auth wall, 500 error page, redirect chain to a login)
	 * or a transport error returns null so the caller falls through to the empty
	 * guard rather than caching an error page as the twin (AgDR-0069 B2).
	 *
	 * @param string $permalink Same-host permalink to fetch.
	 *
	 * @return string|null Rendered HTML body, or null on any non-200 / error.
	 */
	private static function fetch_rendered_html( string $permalink ): ?string {
		$url = \add_query_arg( self::MARKER, '1', $permalink );

		$response = \wp_remote_get(
			$url,
			array(
				'timeout'             => self::REQUEST_TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'reject_unsafe_urls'  => true,
				'user-agent'          => self::USER_AGENT,
				'headers'             => array( 'X-Mokhai-Render' => '1' ),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = (string) \wp_remote_retrieve_body( $response );
		return '' === \trim( $body ) ? null : $body;
	}

	/**
	 * Isolate the main-content region of a rendered HTML document and return its
	 * inner HTML for the Walker to sanitise + convert.
	 *
	 * Pure over its input (no WordPress calls) so it is unit-testable without a
	 * live site. Strategy (AgDR-0069):
	 *   1. Strip chrome subtrees (nav/header/footer/aside/form/script/style/…
	 *      + ARIA landmark roles).
	 *   2. Semantic-first region pick: <main>, <article>, [role=main], then the
	 *      ordered class/id content hints.
	 *   3. Density fallback: the block container carrying the most non-link text,
	 *      if it clears {@see DENSITY_MIN_CHARS}; else the whole chrome-stripped
	 *      body (conservative — may include a little extra, never drops content).
	 *
	 * Reuses the Walker's DOMDocument hardening (UTF-8 PI, internal-error
	 * suppression, no-implied-tags) so malformed HTML5 doesn't warn or mis-parse.
	 *
	 * @param string $html Rendered HTML document.
	 *
	 * @return string Inner HTML of the chosen region, or '' when nothing usable.
	 */
	public static function extract_main_html( string $html ): string {
		if ( '' === \trim( $html ) ) {
			return '';
		}

		$prev = \libxml_use_internal_errors( true );
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );

		// XML processing instruction is the most reliable cross-version UTF-8
		// hint; the <wrapper> gives a single deterministic root to walk.
		$wrapped = '<?xml encoding="utf-8" ?><wrapper>' . $html . '</wrapper>';
		$loaded  = $dom->loadHTML(
			$wrapped,
			\LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
		);

		\libxml_clear_errors();
		\libxml_use_internal_errors( $prev );

		if ( false === $loaded ) {
			return '';
		}

		$xpath = new \DOMXPath( $dom );

		self::strip_chrome( $xpath );

		$region = self::select_region( $xpath );
		if ( ! $region instanceof \DOMNode ) {
			return '';
		}

		return self::inner_html( $region );
	}

	/**
	 * Remove chrome subtrees (by tag and by ARIA landmark role) in place.
	 *
	 * @param \DOMXPath $xpath XPath over the loaded document.
	 */
	private static function strip_chrome( \DOMXPath $xpath ): void {
		$queries = array();

		foreach ( self::CHROME_TAGS as $tag ) {
			$queries[] = '//' . $tag;
		}
		foreach ( self::CHROME_ROLES as $role ) {
			$queries[] = "//*[@role='" . $role . "']";
		}

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}
			// Snapshot into an array first: removing a node while iterating a
			// live DOMNodeList skips siblings.
			$to_remove = array();
			foreach ( $nodes as $node ) {
				$to_remove[] = $node;
			}
			foreach ( $to_remove as $node ) {
				if ( $node->parentNode instanceof \DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Pick the content region: semantic tags, then class/id hints, then a
	 * density fallback, then the whole chrome-stripped body.
	 *
	 * @param \DOMXPath $xpath XPath over the chrome-stripped document.
	 *
	 * @return \DOMNode|null
	 */
	private static function select_region( \DOMXPath $xpath ): ?\DOMNode {
		foreach ( array( '//main', '//article', "//*[@role='main']" ) as $query ) {
			$node = self::first_node( $xpath, $query );
			if ( null !== $node ) {
				return $node;
			}
		}

		foreach ( self::CONTENT_HINTS as $hint ) {
			$node = self::first_node(
				$xpath,
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $hint . " ') or @id='" . $hint . "']"
			);
			if ( null !== $node ) {
				return $node;
			}
		}

		$dense = self::densest_container( $xpath );
		if ( null !== $dense ) {
			return $dense;
		}

		// Whole chrome-stripped body — the synthetic <wrapper> root.
		return self::first_node( $xpath, '//wrapper' );
	}

	/**
	 * The block container carrying the most non-link text, if it clears the
	 * minimum-chars floor. Text length is link-density-adjusted so a nav-like
	 * link farm that slipped the chrome strip can't win.
	 *
	 * @param \DOMXPath $xpath XPath over the chrome-stripped document.
	 *
	 * @return \DOMNode|null
	 */
	private static function densest_container( \DOMXPath $xpath ): ?\DOMNode {
		$nodes = $xpath->query( '//div | //section | //article | //main' );
		if ( false === $nodes ) {
			return null;
		}

		$best       = null;
		$best_score = 0;

		foreach ( $nodes as $node ) {
			$text = \trim( (string) $node->textContent );
			$len  = \strlen( $text );
			if ( $len < self::DENSITY_MIN_CHARS ) {
				continue;
			}

			$link_len = 0;
			$links    = $xpath->query( './/a', $node );
			if ( false !== $links ) {
				foreach ( $links as $link ) {
					$link_len += \strlen( \trim( (string) $link->textContent ) );
				}
			}

			$score = $len - $link_len;
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $node;
			}
		}

		return $best;
	}

	/**
	 * First node matching a query, or null.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param string    $query XPath expression.
	 *
	 * @return \DOMNode|null
	 */
	private static function first_node( \DOMXPath $xpath, string $query ): ?\DOMNode {
		$nodes = $xpath->query( $query );
		if ( false === $nodes || 0 === $nodes->length ) {
			return null;
		}
		$node = $nodes->item( 0 );
		return $node instanceof \DOMNode ? $node : null;
	}

	/**
	 * Serialise a node's children to an HTML string.
	 *
	 * @param \DOMNode $node Node whose inner HTML is wanted.
	 *
	 * @return string
	 */
	private static function inner_html( \DOMNode $node ): string {
		$html = '';
		$dom  = $node->ownerDocument;
		if ( ! $dom instanceof \DOMDocument ) {
			return '';
		}

		foreach ( $node->childNodes as $child ) {
			$fragment = $dom->saveHTML( $child );
			if ( \is_string( $fragment ) ) {
				$html .= $fragment;
			}
		}

		return $html;
	}

	/**
	 * Whether a URL's host matches the site's own host. Blocks any cross-host
	 * fetch (the loopback must only ever hit this site).
	 *
	 * @param string $url URL to test.
	 */
	private static function is_same_host( string $url ): bool {
		$target = (string) \wp_parse_url( $url, \PHP_URL_HOST );
		$home   = (string) \wp_parse_url( (string) \home_url( '/' ), \PHP_URL_HOST );

		return '' !== $target && '' !== $home && \strtolower( $target ) === \strtolower( $home );
	}

	/**
	 * Is a render for this post already in flight?
	 *
	 * @param int $post_id Post identifier.
	 */
	private static function is_locked( int $post_id ): bool {
		return false !== \get_transient( self::LOCK_PREFIX . $post_id );
	}

	/**
	 * Take the per-post render lock before fetching.
	 *
	 * @param int $post_id Post identifier.
	 */
	private static function lock( int $post_id ): void {
		\set_transient( self::LOCK_PREFIX . $post_id, 1, self::LOCK_TTL );
	}

	/**
	 * Release the per-post render lock on completion (success or failure) so a
	 * legitimate re-render is not starved for the TTL window (AgDR-0069).
	 *
	 * @param int $post_id Post identifier.
	 */
	private static function unlock( int $post_id ): void {
		\delete_transient( self::LOCK_PREFIX . $post_id );
	}
}
