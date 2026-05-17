<?php
/**
 * Per-post page-builder detection per AgDR-0016.
 *
 * Classifies a post by inspecting a small, fixed set of well-known
 * `post_meta` keys written by major WordPress page-builders. Falls back
 * to a narrow content-fingerprint regex for the legacy-WPBakery /
 * Avada-export / Elementor-via-classic edge cases where the canonical
 * meta key is absent but the content is still builder-rendered.
 *
 * Pure classification: this class never decides whether to LLM-clean a
 * post — that's the orchestrator's job, combining detection +
 * quality-score (AgDR-0017) + module enablement.
 *
 * Single integration call: `detect( WP_Post )` returns either the
 * builder slug (one of `elementor`, `divi`, `wpbakery`, `avada`,
 * `beaver_builder`) or `null`. No side effects, no caching at this
 * layer — the meta read is cheap on object-cache-equipped sites.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Stateless static classifier for the cleanup-trigger fast path.
 *
 * Adding a sixth builder is a one-line append to `META_KEYS` plus an
 * optional fingerprint entry — no migration, no new AgDR. Gutenberg
 * block-heavy posts are deliberately not classified here and fall
 * through to the quality-score gate (see AgDR-0016 "does NOT decide").
 */
final class Page_Builder_Detector {

	/**
	 * Canonical per-builder `post_meta` keys. First non-empty match wins
	 * in `detect()`. Order is by prevalence (Elementor first) so the hot
	 * path resolves in one meta read on typical sites.
	 *
	 * @var array<string, string>
	 */
	private const META_KEYS = array(
		'elementor'      => '_elementor_data',
		'divi'           => '_et_pb_use_builder',
		'wpbakery'       => '_wpb_vc_js_status',
		'avada'          => 'fusion_builder_status',
		'beaver_builder' => '_fl_builder_enabled',
	);

	/**
	 * Content-fingerprint fallbacks. Intentionally narrow: each regex
	 * matches an opening-tag form of an unambiguously-builder construct.
	 * This keeps the false-positive rate near zero on classic-editor
	 * posts that legitimately mention `[vc_row]` inside a code block.
	 *
	 * @var array<string, string>
	 */
	private const CONTENT_FINGERPRINTS = array(
		'wpbakery'  => '/\[vc_(row|column|column_text|btn)\b/i',
		'avada'     => '/\[(fusion_builder_container|av_section|av_textblock)\b/i',
		'elementor' => '/<div [^>]*class="[^"]*\belementor\b/i',
	);

	/**
	 * Return the builder slug for the post, or null if none detected.
	 *
	 * Meta-key lookup is the primary mechanism; content-fingerprint
	 * fallback runs only when every meta key misses. The fallback
	 * inspects `$post->post_content` (the raw stored content) — running
	 * `the_content` filters here is intentionally avoided so the
	 * fingerprint sees the same bytes a `wp_insert_post` write would
	 * have stored.
	 */
	public static function detect( \WP_Post $post ): ?string {
		foreach ( self::META_KEYS as $slug => $meta_key ) {
			$value = \get_post_meta( (int) $post->ID, $meta_key, true );

			if ( ! self::is_empty_meta( $value ) ) {
				return $slug;
			}
		}

		$content = (string) $post->post_content;

		if ( '' === $content ) {
			return null;
		}

		foreach ( self::CONTENT_FINGERPRINTS as $slug => $pattern ) {
			if ( 1 === \preg_match( $pattern, $content ) ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Convenience wrapper for callers that don't need the slug.
	 */
	public static function is_page_builder_post( \WP_Post $post ): bool {
		return null !== self::detect( $post );
	}

	/**
	 * `get_post_meta` returns mixed: '', '0', false, [], or the actual
	 * value. The page-builder keys all store a non-empty truthy value
	 * when the builder is in use, so we treat '0', '' and false alike
	 * as "absent". An array with at least one element is "present"
	 * (Elementor's `_elementor_data` stores a JSON-encoded array).
	 *
	 * @param mixed $value Raw `get_post_meta` return value.
	 */
	private static function is_empty_meta( $value ): bool {
		if ( false === $value || null === $value ) {
			return true;
		}

		if ( \is_string( $value ) ) {
			return '' === $value || '0' === $value;
		}

		if ( \is_array( $value ) ) {
			return 0 === \count( $value );
		}

		return false;
	}
}
