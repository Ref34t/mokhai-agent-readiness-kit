<?php
/**
 * Multi-channel discovery provider detector — detects active sibling
 * AI-readiness plugins that publish their own discovery channels (#22 /
 * AgDR-0043).
 *
 * Sister to {@see Schema_Coordination_Detector}. Where that helper supports
 * the "defer JSON-LD to whichever SEO plugin owns it" posture, this one
 * supports the "credit sibling agent-readiness plugins for the discovery
 * surfaces they emit" posture used by the Context Score's
 * `multi_channel_discovery` sub-score.
 *
 * Coexistence, not competition — a site running AI Layer alongside AI
 * Readiness Kit should not be penalised for using a complementary plugin.
 * The detector also publishes a one-line UX hint + admin URL so the score
 * narrative can name the sibling plugin instead of saying "something is
 * emitting it".
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Detects active sibling agent-readiness plugins.
 *
 * Detection method mirrors {@see Schema_Coordination_Detector}: class-exists
 * is the resilient primary signal; `is_plugin_active()` corroborates when a
 * class might be missing because the plugin loads later. The detected entry
 * carries an admin `config_url` so the score narrative can render a
 * one-click "Configure at X" affordance.
 *
 * The signature registry is filterable so adopters who run other sibling
 * plugins can extend the credited set without patching the plugin.
 */
final class Multi_Channel_Provider_Detector {

	/**
	 * Filter name for extending the signature registry.
	 *
	 * Filter callback receives the default registry (an associative array
	 * keyed by provider slug) and returns the merged registry. The expected
	 * entry shape mirrors {@see self::DEFAULT_SIGNATURES} — `name`, `class`,
	 * `plugin_file`, `config_path`.
	 *
	 * @var string
	 */
	public const PROVIDERS_FILTER = 'ai_readiness_kit_multi_channel_providers';

	/**
	 * Default provider signatures shipped with the plugin.
	 *
	 * Add entries to credit additional sibling agent-readiness plugins.
	 * Each entry needs:
	 *
	 *   - `name`        — human-readable label used in the score narrative
	 *   - `class`       — canonical class loaded by the sibling plugin
	 *   - `plugin_file` — `<dir>/<file>.php` path the plugin registers under
	 *   - `config_path` — relative admin URL (passed through `admin_url()`)
	 *
	 * The shipped AI Layer signature is a best-effort guess at the public
	 * plugin's canonical class — confirmed entries should be validated
	 * against an installed copy before they ship. Operators with the plugin
	 * installed can extend or override via the filter without waiting for a
	 * plugin release.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const DEFAULT_SIGNATURES = array(
		'ai_layer' => array(
			'name'        => 'AI Layer',
			'class'       => 'AILayer\\Plugin',
			'plugin_file' => 'ai-layer/ai-layer.php',
			'config_path' => 'admin.php?page=ai-layer',
		),
	);

	/**
	 * Detect the active sibling provider, if any.
	 *
	 * Returns a structured array when a registered provider is active, or
	 * `null` when none of the signatures match. The first matching provider
	 * wins — the filter callback can re-order the registry to override the
	 * precedence.
	 *
	 * @return array{slug: string, name: string, config_url: string, detected_via: string}|null
	 */
	public static function detect_active(): ?array {
		foreach ( self::signatures() as $slug => $sig ) {
			$class       = isset( $sig['class'] ) ? (string) $sig['class'] : '';
			$plugin_file = isset( $sig['plugin_file'] ) ? (string) $sig['plugin_file'] : '';

			if ( '' !== $class && \class_exists( $class ) ) {
				return self::format_match( (string) $slug, $sig, 'class' );
			}

			if ( '' !== $plugin_file && self::is_plugin_active( $plugin_file ) ) {
				return self::format_match( (string) $slug, $sig, 'plugin_file' );
			}
		}

		return null;
	}

	/**
	 * Resolve the active signature registry, applying the public filter.
	 *
	 * Adopters can add entries OR override defaults by returning a value
	 * whose `class` / `plugin_file` differs from the shipped entry. Returns
	 * the default registry untouched when no filters are registered.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function signatures(): array {
		$default = self::DEFAULT_SIGNATURES;

		if ( ! \function_exists( 'apply_filters' ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered = \apply_filters( self::PROVIDERS_FILTER, $default );
		if ( ! \is_array( $filtered ) ) {
			return $default;
		}

		return $filtered;
	}

	/**
	 * Build the structured match payload, resolving the admin URL.
	 *
	 * @param array<string, string> $sig          Signature entry from the registry.
	 * @param string                $detected_via Either 'class' or 'plugin_file'.
	 *
	 * @return array{slug: string, name: string, config_url: string, detected_via: string}
	 */
	private static function format_match( string $slug, array $sig, string $detected_via ): array {
		$name        = isset( $sig['name'] ) ? (string) $sig['name'] : $slug;
		$config_path = isset( $sig['config_path'] ) ? (string) $sig['config_path'] : '';
		$config_url  = '' !== $config_path && \function_exists( 'admin_url' )
			? (string) \admin_url( $config_path )
			: '';

		return array(
			'slug'         => $slug,
			'name'         => $name,
			'config_url'   => $config_url,
			'detected_via' => $detected_via,
		);
	}

	/**
	 * `is_plugin_active()` shim. WP's helper lives in
	 * `wp-admin/includes/plugin.php` and isn't always loaded outside the
	 * admin context; load it on demand. Pattern mirrors
	 * {@see Schema_Coordination_Detector::is_plugin_active}.
	 *
	 * @param string $plugin_file Plugin file path (relative to plugins dir).
	 */
	private static function is_plugin_active( string $plugin_file ): bool {
		if ( ! \function_exists( 'is_plugin_active' ) ) {
			$plugin_helper = \ABSPATH . 'wp-admin/includes/plugin.php';
			if ( \file_exists( $plugin_helper ) ) {
				require_once $plugin_helper;
			}
		}

		return \function_exists( 'is_plugin_active' ) && \is_plugin_active( $plugin_file );
	}
}
