<?php
/**
 * Canonical permalink → Markdown-View URL mapping.
 *
 * Single source of truth for the `{permalink} → {permalink}.md` (or
 * `?format=md`) transform that the rest of the plugin relies on. Both
 * `/llms.txt` entry generation (`LlmsTxt\Entry_Source`) and agent-surface
 * advertising (`Discovery\Alternate_Advertiser`, #178) must produce the SAME
 * `.md` URL the rewrite contract in `Markdown_Views\Router` actually resolves —
 * so the logic lives in one place rather than being duplicated per caller.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure URL transform — no globals, no options reads. Safe to unit-test directly.
 */
final class Url_Mapper {

	/**
	 * Transform a canonical permalink to its Markdown View URL form.
	 *
	 * Two URL shapes, matching the rewrite contract in `Markdown_Views\Router`:
	 *
	 *   - **Pretty permalinks** (`/lessons/foo/`): strip the trailing slash,
	 *     append `.md`. Result `/lessons/foo.md`.
	 *   - **Plain permalinks** (`/?p=42`): append `&format=md` to the existing
	 *     query string. Result `/?p=42&format=md`.
	 *
	 * Both shapes resolve to the same `Handler::dispatch()` code path once
	 * WordPress parses the request — the rewrite handles the path form, the
	 * query var handles the query form.
	 *
	 * **Edge case — a pretty permalink carrying a query string.** The branch is
	 * chosen by *presence of a query string*, not by the site's permalink mode.
	 * So a pretty URL that already carries a query string (e.g.
	 * `/lessons/foo/?ver=2`) takes the query branch and becomes
	 * `/lessons/foo/?ver=2&format=md` — it does NOT get the `.md` suffix. That's
	 * intentional: `?format=md` content-negotiation reaches the same Markdown
	 * response regardless of permalink mode, whereas appending `.md` to a path
	 * that still has a query string would produce a malformed URL.
	 *
	 * **Edge case — the root / front-page URL** (`https://host/`, no path
	 * segment). Appending `.md` would glue it onto the host (`https://host.md`),
	 * an invalid URL. This case takes the query form (`https://host/?format=md`)
	 * instead — the Router resolves it identically and the URL is always valid.
	 *
	 * Idempotent: a URL already in `.md` or `format=md` shape is returned
	 * unchanged.
	 *
	 * @param string $url Canonical permalink.
	 *
	 * @return string The Markdown View URL form.
	 */
	public static function to_md_url( string $url ): string {
		$parsed = \wp_parse_url( $url );
		$query  = isset( $parsed['query'] ) ? (string) $parsed['query'] : '';

		if ( '' !== $query ) {
			// Plain-permalink mode (or any URL carrying a query string).
			if ( false !== \stripos( $query, 'format=md' ) ) {
				return $url;
			}
			return $url . '&format=md';
		}

		// Pretty-permalink mode (or any URL with no query).
		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		if ( '' !== $path && \substr( $path, -3 ) === '.md' ) {
			return $url;
		}

		// Root / front-page URL (no path segment, e.g. `https://host/`). There
		// is nothing to suffix `.md` onto — appending it would glue `.md` to the
		// host (`https://host.md`, an invalid URL pointing at the `.md` TLD).
		// Fall back to the query form, which `Markdown_Views\Router` resolves
		// identically and is always a valid URL. (#241)
		if ( '' === \rtrim( $path, '/' ) ) {
			return \rtrim( $url, '/' ) . '/?format=md';
		}

		return \rtrim( $url, '/' ) . '.md';
	}
}
