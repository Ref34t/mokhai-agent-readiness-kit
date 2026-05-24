<?php
/**
 * Editorial entries: option registration + sanitisation + save-action firing.
 *
 * Per AgDR-0025, editorial entries are stored in a single versioned `wp_options`
 * entry (`agentready_llms_txt_editorial`) with the shape:
 *
 *     [
 *       'schema_version' => 1,
 *       'entries' => [
 *         [
 *           'title'         => string,
 *           'url'           => string,
 *           'description'   => string,
 *           'section'       => 'Featured'|'Resources'|'Custom',
 *           'section_label' => string,   // only kept when section === 'Custom'
 *         ],
 *         ...
 *       ],
 *     ]
 *
 * Save dispatches the `agentready_llms_txt_editorial_saved` action, which
 * `LlmsTxt\Service::register_hooks()` (Phase A / AgDR-0023) already subscribes
 * to — the regen schedule fires automatically.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Settings API registration for the LLMs Index editorial entries.
 */
final class Editorial_Settings {

	/**
	 * Option key holding the editorial entries.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'agentready_llms_txt_editorial';

	/**
	 * Settings API option group — passed to register_setting() and the
	 * settings-fields nonce.
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'agentready_llms_txt_editorial_group';

	/**
	 * Action fired after the option is updated. `Service::register_hooks`
	 * (Phase A / AgDR-0023) subscribes to this and schedules a debounced
	 * regen.
	 *
	 * @var string
	 */
	public const SAVED_ACTION = 'agentready_llms_txt_editorial_saved';

	/**
	 * Current schema version. Bumped if the stored shape changes.
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Section pick-list. `Custom` is the escape hatch — when an entry's
	 * `section` is `Custom`, a sibling `section_label` field provides the
	 * actually-rendered heading.
	 *
	 * @var string[]
	 */
	public const SECTIONS = array( 'Featured', 'Resources', 'Custom' );

	/**
	 * URL-scheme allowlist for editorial-entry URLs. `mailto` enables linking
	 * an agent at a contact address; the spec doesn't constrain this.
	 *
	 * @var string[]
	 */
	public const ALLOWED_URL_SCHEMES = array( 'http', 'https', 'mailto' );

	/**
	 * Wire the WP hooks owned by this class. Called from `Main::register_hooks`.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_init', array( self::class, 'register_setting' ) );
		\add_action( 'update_option_' . self::OPTION_KEY, array( self::class, 'on_save' ), 10, 2 );
		\add_action( 'add_option_' . self::OPTION_KEY, array( self::class, 'on_save' ), 10, 2 );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * `show_in_rest` is false — the React UI submits via options.php form
	 * round-trip (AgDR-0025 § "REST surface"); no public REST exposure.
	 */
	public static function register_setting(): void {
		\register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'description'       => \__( 'AI Readiness Kit editorial entries for /llms.txt.', 'ai-readiness-kit' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Empty default. A fresh install ships with zero editorial entries —
	 * the LLMs Index editor surfaces as an empty repeater the admin fills in.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'entries'        => array(),
		);
	}

	/**
	 * Public reader. Always returns the versioned shape with a guaranteed
	 * `entries` key — callers don't have to defend against legacy shapes.
	 *
	 * @return array{schema_version: int, entries: array<int, array<string, mixed>>}
	 */
	public static function get_settings(): array {
		$stored = \get_option( self::OPTION_KEY, null );
		return self::normalize_stored( $stored );
	}

	/**
	 * Sanitise the submitted option payload. Single source of truth — every
	 * write (Settings API, REST, WP-CLI, programmatic) routes through this.
	 *
	 * @param mixed $input Raw input from the options.php POST or
	 *                     `update_option` call.
	 *
	 * @return array{schema_version: int, entries: array<int, array<string, mixed>>}
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_defaults();
		}

		// Accept either the versioned shape (writer) or a bare-list shape
		// (legacy fixtures / WP-CLI `wp option update` calls before Phase C).
		$raw_entries = isset( $input['entries'] ) && is_array( $input['entries'] )
			? $input['entries']
			: $input;

		$entries = array();
		foreach ( $raw_entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$title = isset( $entry['title'] ) ? \sanitize_text_field( (string) $entry['title'] ) : '';
			$url   = self::sanitize_url( isset( $entry['url'] ) ? (string) $entry['url'] : '' );

			if ( '' === $title || '' === $url ) {
				continue;
			}

			$description = isset( $entry['description'] )
				? \sanitize_text_field( (string) $entry['description'] )
				: '';

			$section = isset( $entry['section'] ) ? (string) $entry['section'] : 'Featured';
			if ( ! in_array( $section, self::SECTIONS, true ) ) {
				$section = 'Featured';
			}

			$normalised = array(
				'title'       => $title,
				'url'         => $url,
				'description' => $description,
				'section'     => $section,
			);

			if ( 'Custom' === $section ) {
				$label = isset( $entry['section_label'] )
					? \sanitize_text_field( (string) $entry['section_label'] )
					: '';
				if ( '' === $label ) {
					// Custom without a label degrades to Featured — never
					// emit a heading with no name.
					$normalised['section'] = 'Featured';
				} else {
					$normalised['section_label'] = $label;
				}
			}

			$entries[] = $normalised;
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'entries'        => $entries,
		);
	}

	/**
	 * Action callback fired after `update_option` / `add_option` succeeds.
	 * Dispatches the public `agentready_llms_txt_editorial_saved` action
	 * (AgDR-0025 § "Hook firing").
	 *
	 * @param mixed $old_value Previous value (unused).
	 * @param mixed $value     New value (unused — listeners re-read via the option).
	 */
	public static function on_save( $old_value, $value ): void {
		unset( $old_value, $value );

		// Hook name resolves to `agentready_llms_txt_editorial_saved` —
		// constant is prefixed; phpcs can't see through the const ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		\do_action( self::SAVED_ACTION );
	}

	/**
	 * Resolve the rendered section label for an entry. When `section` is
	 * one of the pick-list values, that value IS the rendered label; when
	 * it's `Custom`, the `section_label` field carries the actually-
	 * rendered heading. Returns an empty string for malformed entries —
	 * the composer treats that as the editorial-default section.
	 *
	 * @param array<string, mixed> $entry Single editorial entry.
	 */
	public static function rendered_section_for( array $entry ): string {
		$section = isset( $entry['section'] ) ? (string) $entry['section'] : '';
		if ( 'Custom' === $section ) {
			$label = isset( $entry['section_label'] ) ? (string) $entry['section_label'] : '';
			return $label;
		}
		if ( in_array( $section, self::SECTIONS, true ) ) {
			return $section;
		}
		return '';
	}

	/**
	 * Internal: normalise a raw stored value into the versioned shape.
	 * Handles three cases:
	 *   - null / non-array → defaults
	 *   - bare list `[{...}, {...}]` → wrap with version + entries
	 *   - versioned `{schema_version, entries}` → return as-is (defensively)
	 *
	 * @param mixed $stored Raw `get_option` result.
	 *
	 * @return array{schema_version: int, entries: array<int, array<string, mixed>>}
	 */
	private static function normalize_stored( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return self::get_defaults();
		}

		if ( isset( $stored['entries'] ) && is_array( $stored['entries'] ) ) {
			return array(
				'schema_version' => self::SCHEMA_VERSION,
				'entries'        => array_values(
					array_filter( $stored['entries'], 'is_array' )
				),
			);
		}

		// Bare list — every value is an array (or we drop it).
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'entries'        => array_values( array_filter( $stored, 'is_array' ) ),
		);
	}

	/**
	 * Validate a URL with the scheme allowlist. Internal OR external both
	 * pass — the spec doesn't constrain host. Returns the sanitised URL or
	 * empty string for inputs we reject.
	 */
	private static function sanitize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$cleaned = (string) \esc_url_raw( $url, self::ALLOWED_URL_SCHEMES );
		return $cleaned;
	}
}
