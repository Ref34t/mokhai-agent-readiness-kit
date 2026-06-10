<?php
/**
 * llms.txt body composer (pure function).
 *
 * Per AgDR-0021/0022, `LlmsTxt\Service` resolves the inputs (site identity,
 * editorial entries, auto-listed entries) and hands a structured array to
 * `Composer::compose()`, which returns the body string. Composer never reads
 * WP state — keeping the formatting logic side-effect-free makes it trivially
 * unit-testable without booting WordPress.
 *
 * Output shape follows llms.txt v1 (https://llmstxt.org):
 *
 *     # {site name}
 *     > {tagline}
 *
 *     ## {section label}
 *
 *     - [{title}]({url}): {description}
 *     - [{title}]({url})
 *
 * Empty default (FR-9 / AC #5): when neither editorial nor auto-listed
 * sections contain entries, `compose()` returns the empty string — the
 * handler (AgDR-0021) serves that as `200 text/plain` with no body. A fresh
 * install with empty `exposed_cpts` thus exposes nothing, not even site
 * identity.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure composer for the llms.txt body.
 *
 * Input shape (passed to `compose()`):
 *
 *     array{
 *       identity: array{site_name: string, tagline: string},
 *       editorial: array<int, array{title: string, url: string, description?: string, section?: string}>,
 *       sections: array<int, array{
 *         label: string,
 *         entries: array<int, array{title: string, url: string, description?: string}>
 *       }>
 *     }
 *
 * Empty `editorial` AND empty `sections` (or sections all with empty entries)
 * → empty body. Otherwise the identity block precedes the section list.
 */
final class Composer {

	/**
	 * Default section label for editorial entries that don't carry an
	 * explicit `section` key.
	 *
	 * @var string
	 */
	public const DEFAULT_EDITORIAL_SECTION = 'Editorial';

	/**
	 * Compose the llms.txt body from an inputs array.
	 *
	 * @param array<string, mixed> $inputs Structured input. See class docblock for shape.
	 *
	 * @return string The composed body, or '' when there's nothing to expose.
	 */
	public static function compose( array $inputs ): string {
		$editorial_groups = self::group_editorial( $inputs['editorial'] ?? array() );
		$auto_sections    = self::normalize_sections( $inputs['sections'] ?? array() );

		$all_sections = self::merge_sections( $editorial_groups, $auto_sections );

		if ( array() === $all_sections ) {
			return '';
		}

		$identity = self::normalize_identity( $inputs['identity'] ?? array() );

		$out = '# ' . self::escape_inline( $identity['site_name'] ) . "\n";

		if ( '' !== $identity['tagline'] ) {
			$out .= '> ' . self::escape_inline( $identity['tagline'] ) . "\n";
		}

		foreach ( $all_sections as $section ) {
			$out .= "\n## " . self::escape_inline( $section['label'] ) . "\n\n";

			foreach ( $section['entries'] as $entry ) {
				$out .= self::format_entry( $entry ) . "\n";
			}
		}

		return $out;
	}

	/**
	 * Group editorial entries by their `section` key (default DEFAULT_EDITORIAL_SECTION).
	 *
	 * Within each section, entries preserve the input order — admins arrange
	 * editorial entries deliberately and we don't re-sort.
	 *
	 * Public so `Full_Composer` (#179) groups the same editorial input with
	 * identical semantics — the two documents must never disagree on how an
	 * editorial entry is bucketed.
	 *
	 * @param array<int, array<string, mixed>> $editorial Raw editorial list.
	 *
	 * @return array<int, array{label: string, entries: array<int, array<string, mixed>>}>
	 */
	public static function group_editorial( array $editorial ): array {
		$buckets = array();
		$order   = array();

		foreach ( $editorial as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$title = isset( $entry['title'] ) ? (string) $entry['title'] : '';
			$url   = isset( $entry['url'] ) ? (string) $entry['url'] : '';
			if ( '' === $title || '' === $url ) {
				continue;
			}

			$section = isset( $entry['section'] ) && '' !== (string) $entry['section']
				? (string) $entry['section']
				: self::DEFAULT_EDITORIAL_SECTION;

			if ( ! isset( $buckets[ $section ] ) ) {
				$buckets[ $section ] = array();
				$order[]             = $section;
			}

			$normalized = array(
				'title' => $title,
				'url'   => $url,
			);
			if ( isset( $entry['description'] ) && '' !== (string) $entry['description'] ) {
				$normalized['description'] = (string) $entry['description'];
			}

			$buckets[ $section ][] = $normalized;
		}

		$out = array();
		foreach ( $order as $label ) {
			$out[] = array(
				'label'   => $label,
				'entries' => $buckets[ $label ],
			);
		}

		return $out;
	}

	/**
	 * Normalize auto-listed sections from `Entry_Source`, stripping malformed
	 * or empty entries. Sections that end up with zero entries are removed.
	 *
	 * @param array<int, mixed> $sections Raw sections list.
	 *
	 * @return array<int, array{label: string, entries: array<int, array<string, mixed>>}>
	 */
	private static function normalize_sections( array $sections ): array {
		$out = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$label = isset( $section['label'] ) ? (string) $section['label'] : '';
			if ( '' === $label ) {
				continue;
			}

			$entries = array();
			$raw     = isset( $section['entries'] ) && is_array( $section['entries'] )
				? $section['entries']
				: array();

			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$title = isset( $entry['title'] ) ? (string) $entry['title'] : '';
				$url   = isset( $entry['url'] ) ? (string) $entry['url'] : '';
				if ( '' === $title || '' === $url ) {
					continue;
				}
				$normalized = array(
					'title' => $title,
					'url'   => $url,
				);
				if ( isset( $entry['description'] ) && '' !== (string) $entry['description'] ) {
					$normalized['description'] = (string) $entry['description'];
				}
				$entries[] = $normalized;
			}

			if ( array() === $entries ) {
				continue;
			}

			$out[] = array(
				'label'   => $label,
				'entries' => $entries,
			);
		}

		return $out;
	}

	/**
	 * Merge editorial-grouped sections with auto-listed sections.
	 *
	 * Editorial sections appear first (admin intent > automation), in their
	 * input order. Auto-listed sections follow. If an editorial section and
	 * an auto-listed section share a label, the auto entries are appended to
	 * the editorial entries under one heading.
	 *
	 * @param array<int, array{label: string, entries: array<int, array<string, mixed>>}> $editorial Grouped editorial sections.
	 * @param array<int, array{label: string, entries: array<int, array<string, mixed>>}> $auto       Normalized auto-listed sections.
	 *
	 * @return array<int, array{label: string, entries: array<int, array<string, mixed>>}>
	 */
	private static function merge_sections( array $editorial, array $auto ): array {
		$out          = array();
		$label_to_idx = array();

		foreach ( $editorial as $section ) {
			$label_to_idx[ $section['label'] ] = count( $out );
			$out[]                             = $section;
		}

		foreach ( $auto as $section ) {
			$label = $section['label'];
			if ( isset( $label_to_idx[ $label ] ) ) {
				$idx                    = $label_to_idx[ $label ];
				$out[ $idx ]['entries'] = array_merge(
					$out[ $idx ]['entries'],
					$section['entries']
				);
				continue;
			}
			$label_to_idx[ $label ] = count( $out );
			$out[]                  = $section;
		}

		return $out;
	}

	/**
	 * Normalize the identity block, defaulting to empty strings on missing keys.
	 *
	 * @param array<string, mixed> $identity Raw identity input.
	 *
	 * @return array{site_name: string, tagline: string}
	 */
	private static function normalize_identity( array $identity ): array {
		return array(
			'site_name' => isset( $identity['site_name'] ) ? (string) $identity['site_name'] : '',
			'tagline'   => isset( $identity['tagline'] ) ? (string) $identity['tagline'] : '',
		);
	}

	/**
	 * Format one entry line:
	 *   `- [Title](url): Description`
	 *   `- [Title](url)`
	 *
	 * Description is rendered when present and non-empty.
	 *
	 * Public so `Full_Composer` (#179) renders editorial link lines in the
	 * exact shape `/llms.txt` uses.
	 *
	 * @param array<string, mixed> $entry Entry shape with title/url/description.
	 */
	public static function format_entry( array $entry ): string {
		$title = self::escape_link_text( (string) $entry['title'] );
		$url   = self::escape_link_url( (string) $entry['url'] );

		$line = '- [' . $title . '](' . $url . ')';

		if ( isset( $entry['description'] ) && '' !== (string) $entry['description'] ) {
			$line .= ': ' . self::escape_inline( (string) $entry['description'] );
		}

		return $line;
	}

	/**
	 * Minimal Markdown escaping for inline strings (site name, tagline,
	 * descriptions, section labels). Collapses newlines and escapes the
	 * three characters that have inline syntactic meaning here: backslash,
	 * `*`, `_`. Backticks are NOT escaped because llms.txt readers are
	 * permissive about code spans inside descriptions.
	 *
	 * First step decodes HTML entities — WordPress's `wptexturize` filter
	 * (and the block editor) introduce `&#8217;`, `&amp;`, `&quot;`, etc.
	 * into titles and excerpts. `/llms.txt` is served as `text/plain`,
	 * so entity codes render literally to the consumer. Decoding at the
	 * bottom of the composition pipeline catches every entity introduced
	 * anywhere upstream without per-source patching. See #106.
	 *
	 * Public so `Full_Composer` (#179) escapes headings / labels with the
	 * same rules — one escaping implementation for both documents.
	 */
	public static function escape_inline( string $text ): string {
		$text = \html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', $text );
		$text = trim( $text );
		return strtr(
			$text,
			array(
				'\\' => '\\\\',
				'*'  => '\\*',
				'_'  => '\\_',
			)
		);
	}

	/**
	 * Escape link text — same as inline plus `[` and `]` so we don't break
	 * the surrounding `[...](...)`.
	 */
	private static function escape_link_text( string $text ): string {
		$text = self::escape_inline( $text );
		return strtr(
			$text,
			array(
				'[' => '\\[',
				']' => '\\]',
			)
		);
	}

	/**
	 * Escape link URL — collapse whitespace and escape `(` / `)` to keep the
	 * surrounding `(...)` boundary intact. WP-produced permalinks won't
	 * normally contain literal parens, but custom permalink structures and
	 * archive URLs occasionally do.
	 *
	 * Public so `Full_Composer` (#179) sanitises document URLs identically.
	 */
	public static function escape_link_url( string $url ): string {
		$url = preg_replace( '/\s+/', '', $url );
		if ( ! is_string( $url ) ) {
			return '';
		}
		return strtr(
			$url,
			array(
				'(' => '%28',
				')' => '%29',
			)
		);
	}
}
