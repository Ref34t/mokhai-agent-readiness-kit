<?php
/**
 * llms-full.txt body composer (pure function).
 *
 * Sibling of `Composer` (AgDR-0021/0022) for the consolidated full-content
 * variant defined by the llms.txt convention (#179 / AgDR-0057): where
 * `/llms.txt` is a link *index*, `/llms-full.txt` inlines the full Markdown
 * body of every indexed document so an agent ingests the whole site in one
 * fetch instead of following the per-page `.md` links.
 *
 * Like `Composer`, this class never reads WP state — `LlmsTxt\Service`
 * resolves the inputs (site identity, editorial entries, full documents) and
 * hands a structured array to `compose()`. Editorial entries are rendered as
 * link lines only (they are operator-curated arbitrary URLs — possibly
 * external — so there is no local body to inline); auto-listed documents are
 * rendered with their full Markdown body.
 *
 * Output shape:
 *
 *     # {site name}
 *     > {tagline}
 *
 *     ## {editorial section label}
 *
 *     - [{title}]({url}): {description}
 *
 *     ## {post-type section label}
 *
 *     ### {title}
 *
 *     URL: {url}
 *
 *     {full markdown body}
 *
 *     ---
 *
 *     ### {next title}
 *     ...
 *
 * Empty state mirrors `Composer` (#244): when nothing is exposed, `compose()`
 * emits the site identity header alone; only a nameless site returns ''.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure composer for the llms-full.txt body.
 *
 * Input shape (passed to `compose()`):
 *
 *     array{
 *       identity: array{site_name: string, tagline: string},
 *       editorial: array<int, array{title: string, url: string, description?: string, section?: string}>,
 *       documents: array<int, array{
 *         label: string,
 *         entries: array<int, array{title: string, url: string, markdown?: string}>
 *       }>
 *     }
 */
final class Full_Composer {

	/**
	 * Separator emitted between two documents inside one section. Section
	 * headings (`##`) already delimit sections, so the horizontal rule only
	 * separates consecutive documents — the "clear per-document separators"
	 * the ticket calls for.
	 *
	 * @var string
	 */
	public const DOCUMENT_SEPARATOR = '---';

	/**
	 * Compose the llms-full.txt body from an inputs array.
	 *
	 * @param array<string, mixed> $inputs Structured input. See class docblock for shape.
	 *
	 * @return string The composed body, or '' when there's nothing to expose.
	 */
	public static function compose( array $inputs ): string {
		$editorial_groups = Composer::group_editorial(
			isset( $inputs['editorial'] ) && \is_array( $inputs['editorial'] )
				? $inputs['editorial']
				: array()
		);
		$documents        = self::normalize_documents(
			isset( $inputs['documents'] ) && \is_array( $inputs['documents'] )
				? $inputs['documents']
				: array()
		);

		$identity = isset( $inputs['identity'] ) && \is_array( $inputs['identity'] )
			? $inputs['identity']
			: array();

		$site_name = isset( $identity['site_name'] ) ? (string) $identity['site_name'] : '';
		$tagline   = isset( $identity['tagline'] ) ? (string) $identity['tagline'] : '';

		// Mirror Composer (#244): emit the identity header even when nothing is
		// exposed, so `/llms-full.txt` identifies the site instead of serving a
		// blank body. Only a site with no identity AND no content is truly empty.
		if ( array() === $editorial_groups && array() === $documents && '' === $site_name ) {
			return '';
		}

		$out = '# ' . Composer::escape_inline( $site_name ) . "\n";

		if ( '' !== $tagline ) {
			$out .= '> ' . Composer::escape_inline( $tagline ) . "\n";
		}

		foreach ( $editorial_groups as $section ) {
			$out .= "\n## " . Composer::escape_inline( $section['label'] ) . "\n\n";

			foreach ( $section['entries'] as $entry ) {
				$out .= Composer::format_entry( $entry ) . "\n";
			}
		}

		foreach ( $documents as $section ) {
			$out .= "\n## " . Composer::escape_inline( $section['label'] ) . "\n";

			foreach ( $section['entries'] as $i => $entry ) {
				if ( $i > 0 ) {
					$out .= "\n" . self::DOCUMENT_SEPARATOR . "\n";
				}

				$out .= self::format_document( $entry );
			}
		}

		return $out;
	}

	/**
	 * Render one document block: heading, URL line, full Markdown body.
	 *
	 * The body is emitted verbatim (trimmed) — it is already Markdown from
	 * the Walker pipeline, so re-escaping would corrupt it. An empty body
	 * still renders the heading + URL so the parity contract holds: every
	 * entry `/llms.txt` lists appears in `/llms-full.txt`.
	 *
	 * @param array{title: string, url: string, markdown?: string} $entry Document entry.
	 */
	private static function format_document( array $entry ): string {
		$out  = "\n### " . Composer::escape_inline( $entry['title'] ) . "\n\n";
		$out .= 'URL: ' . Composer::escape_link_url( $entry['url'] ) . "\n";

		$body = isset( $entry['markdown'] ) ? trim( (string) $entry['markdown'] ) : '';
		if ( '' !== $body ) {
			$out .= "\n" . $body . "\n";
		}

		return $out;
	}

	/**
	 * Normalize document sections, stripping malformed or empty entries.
	 * Sections that end up with zero entries are removed — mirrors
	 * `Composer::normalize_sections()`.
	 *
	 * @param array<int, mixed> $documents Raw documents list.
	 *
	 * @return array<int, array{label: string, entries: array<int, array{title: string, url: string, markdown?: string}>}>
	 */
	private static function normalize_documents( array $documents ): array {
		$out = array();

		foreach ( $documents as $section ) {
			if ( ! \is_array( $section ) ) {
				continue;
			}

			$label = isset( $section['label'] ) ? (string) $section['label'] : '';
			if ( '' === $label ) {
				continue;
			}

			$entries = array();
			$raw     = isset( $section['entries'] ) && \is_array( $section['entries'] )
				? $section['entries']
				: array();

			foreach ( $raw as $entry ) {
				if ( ! \is_array( $entry ) ) {
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
				if ( isset( $entry['markdown'] ) && '' !== (string) $entry['markdown'] ) {
					$normalized['markdown'] = (string) $entry['markdown'];
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
}
