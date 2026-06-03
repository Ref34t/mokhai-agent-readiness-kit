<?php
/**
 * Shared orphaned-shortcode stripper.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Support;

\defined( 'ABSPATH' ) || exit;

/**
 * Removes orphaned / unregistered shortcodes from a string.
 *
 * A shortcode whose owning plugin is inactive (or that is otherwise
 * unregistered) is never expanded by `do_shortcode()` and survives as a
 * literal `[tag …]` token. `strip_shortcodes()` only handles REGISTERED tags,
 * so a regex sweep is the only deterministic way to remove the residue.
 *
 * Used by both agent-facing content paths so they share one implementation:
 *   - `Markdown_Views\Walker::preprocess()` — the deterministic .md view (#145)
 *   - `LlmsTxt\Description_Orchestrator::build_user_prompt()` — the /llms.txt
 *     entry-description LLM excerpt (#147)
 *
 * Conservative by construction — only tokens that are unambiguously shortcodes
 * are removed; bare bracketed prose (`[1]`, `[citation needed]`) is preserved.
 */
final class Shortcode_Stripper {

	/**
	 * Strip orphaned shortcodes from `$text`, returning the cleaned string.
	 *
	 * Two passes, in order:
	 *   1. Paired `[tag …]…[/tag]` — the wrappers are dropped but the inner
	 *      content is KEPT (builder containers like
	 *      `[vc_column_text]real copy[/vc_column_text]` carry the actual
	 *      content). Looped so nested containers unwrap fully.
	 *   2. Attribute-bearing or self-closing standalones — `[tag x="y"]` and
	 *      `[tag /]` — are removed entirely.
	 *
	 * Bare bracketed prose has no `=` attribute, no trailing `/`, and no
	 * matching close tag, so it matches neither pass and survives.
	 *
	 * Callers that run this on HTML should call it BEFORE converting to
	 * Markdown — at the HTML stage `[text](url)` link syntax does not exist
	 * yet and so cannot be mistaken for a shortcode.
	 *
	 * @param string $text Source text (HTML or plain) possibly containing
	 *                     orphaned shortcode tokens.
	 * @return string The text with orphaned shortcodes removed.
	 */
	public static function strip_orphaned( string $text ): string {
		// 1. Paired tags — keep inner content, drop the wrappers. Looped so
		//    nested builder containers (vc_row > vc_column > …) fully unwrap.
		$paired = '/\[([a-z][a-z0-9_-]*)(?:\s[^\]]*)?\](.*?)\[\/\1\]/su';
		do {
			$result = \preg_replace_callback(
				$paired,
				static function ( array $matches ): string {
					return $matches[2];
				},
				$text,
				-1,
				$unwrapped
			);
			// A PCRE error returns null — leave the content untouched rather
			// than silently wiping it to an empty string.
			if ( null === $result ) {
				break;
			}
			$text = $result;
		} while ( $unwrapped > 0 );

		// 2. Self-closing `[tag /]` then attribute-bearing `[tag x="y"]`.
		$text = (string) \preg_replace( '/\[[a-z][a-z0-9_-]*(?:\s[^\]]*?)?\/\]/u', '', $text );
		$text = (string) \preg_replace( '/\[[a-z][a-z0-9_-]*\s[^\]]*?=[^\]]*?\]/u', '', $text );

		return $text;
	}
}
