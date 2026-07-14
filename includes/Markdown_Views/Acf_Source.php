<?php
/**
 * ACF (Advanced Custom Fields) source adapter for Markdown Views.
 *
 * Bundled adapter (AgDR-0068) that hooks the `mokhai_markdown_source_html`
 * filter so pages whose content lives in ACF fields — rendered by the theme in
 * templates, never through `the_content` — no longer serve a 0-byte `.md`
 * body (#292). This is the same bug class AgDR-0061 solved for WooCommerce
 * products, reusing the same seam.
 *
 * The adapter only contributes when `the_content` produced nothing meaningful,
 * so classic-editor / block-editor / builder content that already renders
 * through `the_content` is never duplicated. It reads field TYPE metadata via
 * `get_field_objects()` (not `get_fields()`, which returns values without
 * types) and emits only human-readable text field types, recursing through
 * Flexible Content, Group, Clone and Repeater containers in field-registration
 * order. Non-text types (booleans, image / relationship IDs, colours, select
 * keys) are excluded — dumping them as body text is noise worse than empty.
 *
 * ACF *Blocks* (Gutenberg) are out of scope: they already render through
 * `the_content` and so are captured by the base source.
 *
 * The core renderer ({@see Service}) stays field-agnostic and offline; ACF
 * support is this opt-in adapter, active only when ACF is.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Sources ACF field text into the Markdown body when `post_content` is empty.
 */
final class Acf_Source {

	/**
	 * ACF field types whose value is human-readable prose worth emitting.
	 * Everything else (image, file, gallery, true_false, select, checkbox,
	 * radio, number, range, date, colour, relationship, post_object, link,
	 * taxonomy, user, google_map, …) is excluded — its raw value is an ID,
	 * key, or code that reads as noise in an agent-facing text twin.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_FIELD_TYPES = array( 'text', 'textarea', 'wysiwyg', 'email', 'url' );

	/**
	 * Container field types whose sub-fields are walked recursively.
	 *
	 * @var array<int, string>
	 */
	private const CONTAINER_FIELD_TYPES = array( 'group', 'clone', 'repeater', 'flexible_content' );

	/**
	 * Wire the source + hash filters. Called from `Main::register_hooks()`.
	 *
	 * Registers unconditionally; both callbacks no-op when ACF is inactive, so
	 * there is no ordering dependency on ACF having loaded by registration time.
	 */
	public static function register(): void {
		\add_filter( 'mokhai_markdown_source_html', array( self::class, 'append_field_text' ), 10, 2 );
		\add_filter( 'mokhai_markdown_content_hash', array( self::class, 'extend_content_hash' ), 10, 2 );
	}

	/**
	 * Append ACF field text to the Markdown source when `the_content` is empty.
	 *
	 * No-ops (returns `$html` unchanged) unless ACF is active, `the_content`
	 * yielded no visible text, and the post has extractable text fields. The
	 * "only when empty" guard is what keeps this from duplicating content on
	 * pages that already render through `the_content`.
	 *
	 * @param string   $html The `the_content`-rendered HTML.
	 * @param \WP_Post $post The post being rendered.
	 *
	 * @return string HTML with ACF field text appended when applicable.
	 */
	public static function append_field_text( string $html, \WP_Post $post ): string {
		if ( ! \function_exists( 'get_field_objects' ) ) {
			return $html;
		}

		// Only source ACF when the canonical render produced nothing visible.
		// A theme that renders ACF via `the_content` (rare) or a page with real
		// `post_content` must not have its body duplicated.
		if ( '' !== \trim( \wp_strip_all_tags( $html ) ) ) {
			return $html;
		}

		$objects = self::field_objects( $post->ID );
		if ( array() === $objects ) {
			return $html;
		}

		$rendered = self::extract_text_html( $objects );
		if ( '' === \trim( \wp_strip_all_tags( $rendered ) ) ) {
			return $html;
		}

		// `$html` is empty here (guarded above), so this is effectively the body.
		return \trim( $rendered . "\n" . $html );
	}

	/**
	 * Fold the ACF field payload into the Markdown View cache key so an edit to
	 * any sourced field invalidates the cached render (AgDR-0068 F3).
	 *
	 * Draws from the IDENTICAL `field_objects()` result the renderer uses, so
	 * the hash input and the render input can never drift (the AgDR-0061
	 * "two sites must not drift" hazard). Runs on every `.md` request including
	 * cache hits — the acknowledged, object-cache-bounded cost of ACF support.
	 *
	 * Conservatively includes the field payload whenever ACF is active, even
	 * for posts whose `post_content` is non-empty (where the renderer won't use
	 * ACF): that only ever OVER-invalidates, never serves stale content.
	 *
	 * @param string   $base The default hash input.
	 * @param \WP_Post $post The post being rendered.
	 *
	 * @return string Hash input with the ACF field payload appended.
	 */
	public static function extend_content_hash( string $base, \WP_Post $post ): string {
		if ( ! \function_exists( 'get_field_objects' ) ) {
			return $base;
		}

		$objects = self::field_objects( $post->ID );
		if ( array() === $objects ) {
			return $base;
		}

		return $base . (string) \wp_json_encode( self::values_only( $objects ) );
	}

	/**
	 * Read `get_field_objects()` for a post, normalised to an array.
	 *
	 * `get_field_objects()` returns `false` when a post has no fields; this
	 * collapses that to an empty array so callers branch on `array() ===`.
	 *
	 * @param int $post_id Post identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function field_objects( int $post_id ): array {
		$objects = \get_field_objects( $post_id );
		return \is_array( $objects ) ? $objects : array();
	}

	/**
	 * Walk a list of ACF field objects and return the concatenated HTML of
	 * every human-readable text value, in field-registration order.
	 *
	 * Pure over its input — the recursion operates on the ACF field-object
	 * shape (`type` / `value` / `sub_fields` / `layouts`) so it is unit-testable
	 * without a live ACF install.
	 *
	 * @param array<string, array<string, mixed>> $objects Field objects (name => object).
	 *
	 * @return string
	 */
	public static function extract_text_html( array $objects ): string {
		$parts = array();

		foreach ( $objects as $field ) {
			if ( ! \is_array( $field ) || ! isset( $field['type'] ) ) {
				continue;
			}
			foreach ( self::collect_field( $field, $field['value'] ?? null ) as $fragment ) {
				$parts[] = $fragment;
			}
		}

		return \implode( "\n", $parts );
	}

	/**
	 * Collect HTML fragments for a single field definition + its value,
	 * recursing through container types.
	 *
	 * @param array<string, mixed> $field Field definition (carries `type`, and
	 *                                     `sub_fields` / `layouts` for containers).
	 * @param mixed                $value The field's value.
	 *
	 * @return array<int, string>
	 */
	private static function collect_field( array $field, $value ): array {
		$type = (string) ( $field['type'] ?? '' );

		if ( \in_array( $type, self::TEXT_FIELD_TYPES, true ) ) {
			return self::render_text_value( $type, $value );
		}

		if ( \in_array( $type, self::CONTAINER_FIELD_TYPES, true ) ) {
			return self::collect_container( $type, $field, $value );
		}

		return array();
	}

	/**
	 * Recurse into a container field (group / clone / repeater / flexible_content).
	 *
	 * @param string               $type  Container type.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Container value.
	 *
	 * @return array<int, string>
	 */
	private static function collect_container( string $type, array $field, $value ): array {
		if ( ! \is_array( $value ) ) {
			return array();
		}

		$parts = array();

		if ( 'group' === $type || 'clone' === $type ) {
			// One associative row keyed by sub-field name. This is the
			// group-display clone shape; a *seamless* clone hoists its
			// sub-fields to the parent level (their values then surface through
			// the top-level walk, or key-mismatch here yields nothing) — safe
			// either way, never a fatal.
			$parts = self::collect_row( $field['sub_fields'] ?? array(), $value );
		} elseif ( 'repeater' === $type ) {
			// A list of rows, each keyed by sub-field name.
			foreach ( $value as $row ) {
				if ( \is_array( $row ) ) {
					$parts = \array_merge( $parts, self::collect_row( $field['sub_fields'] ?? array(), $row ) );
				}
			}
		} elseif ( 'flexible_content' === $type ) {
			// A list of layout rows; each row names its layout via `acf_fc_layout`.
			$layouts = self::index_layouts( $field['layouts'] ?? array() );
			foreach ( $value as $row ) {
				if ( ! \is_array( $row ) ) {
					continue;
				}
				$layout_name = (string) ( $row['acf_fc_layout'] ?? '' );
				$sub_fields  = $layouts[ $layout_name ] ?? array();
				$parts       = \array_merge( $parts, self::collect_row( $sub_fields, $row ) );
			}
		}

		return $parts;
	}

	/**
	 * Collect fragments for one value-row against a list of sub-field
	 * definitions, in definition order.
	 *
	 * @param array<int, array<string, mixed>> $sub_fields Sub-field definitions.
	 * @param array<string, mixed>             $row        Value row keyed by name.
	 *
	 * @return array<int, string>
	 */
	private static function collect_row( array $sub_fields, array $row ): array {
		$parts = array();

		foreach ( $sub_fields as $sub ) {
			if ( ! \is_array( $sub ) || ! isset( $sub['name'] ) ) {
				continue;
			}
			$name      = (string) $sub['name'];
			$sub_value = $row[ $name ] ?? null;
			$parts     = \array_merge( $parts, self::collect_field( $sub, $sub_value ) );
		}

		return $parts;
	}

	/**
	 * Index flexible-content layout definitions by layout name → sub-fields.
	 *
	 * @param array<int, array<string, mixed>> $layouts Layout definitions.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function index_layouts( array $layouts ): array {
		$indexed = array();

		foreach ( $layouts as $layout ) {
			if ( \is_array( $layout ) && isset( $layout['name'] ) ) {
				$indexed[ (string) $layout['name'] ] = \is_array( $layout['sub_fields'] ?? null )
					? $layout['sub_fields']
					: array();
			}
		}

		return $indexed;
	}

	/**
	 * Render a single scalar text value to an HTML fragment.
	 *
	 * `wysiwyg` values are already HTML (kept as-is); `textarea` preserves line
	 * breaks; `text` / `email` / `url` are wrapped in a paragraph. Non-string
	 * or empty values yield nothing.
	 *
	 * @param string $type  Field type (already known to be a text type).
	 * @param mixed  $value Field value.
	 *
	 * @return array<int, string>
	 */
	private static function render_text_value( string $type, $value ): array {
		if ( ! \is_string( $value ) ) {
			return array();
		}

		$value = \trim( $value );
		if ( '' === $value ) {
			return array();
		}

		if ( 'wysiwyg' === $type ) {
			// Already HTML from the editor.
			return array( $value );
		}

		if ( 'textarea' === $type ) {
			return array( '<p>' . \nl2br( self::esc( $value ) ) . '</p>' );
		}

		return array( '<p>' . self::esc( $value ) . '</p>' );
	}

	/**
	 * Escape a plain-text field value for HTML output. Uses `esc_html()` when
	 * available (always, in WordPress), falling back to `htmlspecialchars()`.
	 */
	private static function esc( string $text ): string {
		if ( \function_exists( 'esc_html' ) ) {
			return \esc_html( $text );
		}
		return \htmlspecialchars( $text, \ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Reduce field objects to their values only — the payload folded into the
	 * cache hash. Keyed by field name for a stable, order-independent digest.
	 *
	 * @param array<string, array<string, mixed>> $objects Field objects.
	 *
	 * @return array<string, mixed>
	 */
	private static function values_only( array $objects ): array {
		$values = array();

		foreach ( $objects as $name => $field ) {
			if ( \is_array( $field ) && \array_key_exists( 'value', $field ) ) {
				$values[ (string) $name ] = $field['value'];
			}
		}

		return $values;
	}
}
