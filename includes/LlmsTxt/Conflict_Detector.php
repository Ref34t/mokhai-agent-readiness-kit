<?php
/**
 * Conflict detector for competing `/llms.txt` plugins (#7 Phase B / AgDR-0024).
 *
 * Three detection surfaces, evaluated in order:
 *
 *   1. Plugin slug registry — `is_plugin_active()` against a hard-coded list
 *      of known wp.org plugins that publish `/llms.txt`. Filterable so
 *      adopters can extend the registry without waiting for a release.
 *   2. Filesystem — `file_exists( ABSPATH . 'llms.txt' )`. Static-file
 *      writers (and hand-rolled deployments) leave this fingerprint.
 *   3. Rewrite-rule scan — if another plugin claimed `^llms\.txt/?$` in
 *      `$wp_rewrite->extra_rules_top` with a non-ours value, our route
 *      is shadowed.
 *
 * `Conflict_Detector::detect()` is pure-ish (reads global WP state, no
 * writes). The admin notice and dismissal flow live in
 * `LlmsTxt\Conflict_Notice`; the WP-CLI surface picks up `detect()`
 * results in the Phase A `status` command.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Detector for `/llms.txt` conflicts. No side effects.
 *
 * Return shape from `detect()`:
 *
 *     array<int, array{
 *       kind: 'plugin'|'filesystem'|'rewrite',
 *       // 'plugin' fields:
 *       slug?: string,
 *       name?: string,
 *       url?: string,
 *       shape?: 'static-file'|'rewrite'|'hybrid',
 *       // 'filesystem' fields:
 *       path?: string,
 *       // 'rewrite' fields:
 *       rule?: string,
 *     }>
 */
final class Conflict_Detector {

	/**
	 * Filter for adopters who need to extend the slug registry without
	 * waiting for a release. Receives the default map; returns the
	 * (optionally extended) map.
	 *
	 * @var string
	 */
	public const SLUG_REGISTRY_FILTER = 'agentready_llms_txt_known_plugin_slugs';

	/**
	 * The literal rewrite-regex key our `Router` registers under (must
	 * mirror `Router::add_rewrite_rule` exactly).
	 *
	 * @var string
	 */
	public const REWRITE_KEY = '^llms\.txt/?$';

	/**
	 * Fingerprint our `Router` writes as the rewrite target. If
	 * `extra_rules_top[ self::REWRITE_KEY ]` does NOT contain this
	 * fingerprint, a competing plugin overwrote our entry.
	 *
	 * @var string
	 */
	public const REWRITE_FINGERPRINT = 'agentready_llms_txt';

	/**
	 * Returns the list of detected conflicts. Empty array == clean cohabitation.
	 *
	 * Each entry is one observed signal — admins can have all three
	 * surfaces fire at once (competing plugin → wrote static file → also
	 * registered a rewrite that shadowed us). All three appear in the
	 * notice with distinct resolution steps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function detect(): array {
		$conflicts = array();

		foreach ( self::active_competing_plugins() as $plugin ) {
			$conflicts[] = array(
				'kind'  => 'plugin',
				'slug'  => $plugin['slug'],
				'name'  => $plugin['name'],
				'url'   => $plugin['url'],
				'shape' => $plugin['shape'],
			);
		}

		$fs = self::filesystem_conflict();
		if ( null !== $fs ) {
			$conflicts[] = $fs;
		}

		$rewrite = self::rewrite_conflict();
		if ( null !== $rewrite ) {
			$conflicts[] = $rewrite;
		}

		return $conflicts;
	}

	/**
	 * Stable hash of the conflict list. Used by the notice's per-user
	 * dismissal: dismissing once keys off this fingerprint, so a new
	 * conflict (e.g. admin deactivates X, installs Y) re-surfaces a fresh
	 * notice.
	 *
	 * @param array<int, array<string, mixed>> $conflicts Output of detect().
	 */
	public static function fingerprint( array $conflicts ): string {
		// Sort each conflict's keys + the outer list by serialised content
		// for order-independence. We never compare hashes across versions;
		// the key shape just needs to be stable per (set of detected
		// conflicts) within the running plugin version.
		$normalised = array_map(
			static function ( array $entry ): array {
				ksort( $entry );
				return $entry;
			},
			$conflicts
		);
		usort(
			$normalised,
			static function ( $a, $b ): int {
				return strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) );
			}
		);
		return sha1( (string) wp_json_encode( $normalised ) );
	}

	/**
	 * Default registry of competing wp.org plugins. Adopters extend via
	 * the `SLUG_REGISTRY_FILTER` filter; that's the documented surface.
	 *
	 * Entry-file paths are LITERAL — `llms-full-txt-generator`'s entry
	 * file is `llms-txt-generator.php` (different from its directory),
	 * which would silently miss a directory-only check. See
	 * AgDR-0024 § "The plugin-slug registry".
	 *
	 * @return array<string, array{name: string, url: string, shape: string}>
	 */
	public static function default_known_plugins(): array {
		return array(
			'website-llms-txt/website-llms-txt.php'       => array(
				'name'  => 'Website LLMs.txt',
				'url'   => 'https://wordpress.org/plugins/website-llms-txt/',
				'shape' => 'hybrid',
			),
			'llms-full-txt-generator/llms-txt-generator.php' => array(
				'name'  => 'LLMs.txt and LLMs-Full.txt Generator',
				'url'   => 'https://wordpress.org/plugins/llms-full-txt-generator/',
				'shape' => 'static-file',
			),
			'llms-txt-generator/llms-txt-generator.php'   => array(
				'name'  => 'LLMs.txt Generator',
				'url'   => 'https://wordpress.org/plugins/llms-txt-generator/',
				'shape' => 'rewrite',
			),
			'markdown-mirror/markdown-mirror.php'         => array(
				'name'  => 'Markdown Mirror',
				'url'   => 'https://wordpress.org/plugins/markdown-mirror/',
				'shape' => 'rewrite',
			),
			'jumpsuitai-llms-txt/jumpsuitai-llms-txt.php' => array(
				'name'  => 'JumpsuitAI llms.txt + Markdown Endpoints',
				'url'   => 'https://wordpress.org/plugins/jumpsuitai-llms-txt/',
				'shape' => 'rewrite',
			),
		);
	}

	/**
	 * Resolve the filtered slug registry. Adopter-supplied entries that
	 * don't conform to the expected shape are silently dropped to keep
	 * `detect()` defensive — a malformed filter return must NOT crash
	 * the admin page.
	 *
	 * @return array<string, array{name: string, url: string, shape: string}>
	 */
	public static function known_plugins(): array {
		$default = self::default_known_plugins();

		/**
		 * Filter the registry of competing `/llms.txt` plugin slugs.
		 *
		 * @param array<string, array{name: string, url: string, shape: string}> $slugs Map.
		 */
		// Hook name resolves to `agentready_llms_txt_known_plugin_slugs` —
		// the constant is prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered = \apply_filters( self::SLUG_REGISTRY_FILTER, $default );

		if ( ! is_array( $filtered ) ) {
			return $default;
		}

		$out = array();
		foreach ( $filtered as $slug => $meta ) {
			if ( ! is_string( $slug ) || '' === $slug || ! is_array( $meta ) ) {
				continue;
			}
			$name  = isset( $meta['name'] ) ? (string) $meta['name'] : '';
			$url   = isset( $meta['url'] ) ? (string) $meta['url'] : '';
			$shape = isset( $meta['shape'] ) ? (string) $meta['shape'] : '';
			if ( '' === $name ) {
				continue;
			}
			$out[ $slug ] = array(
				'name'  => $name,
				'url'   => $url,
				'shape' => $shape,
			);
		}
		return $out;
	}

	/**
	 * Slug-registry scan. Calls `is_plugin_active` against every known
	 * slug. `is_plugin_active` is in `wp-admin/includes/plugin.php`,
	 * which isn't loaded on the front end — we guard via `function_exists`
	 * so the detector can also be called from CLI / front-end without
	 * fatal.
	 *
	 * @return array<int, array{slug: string, name: string, url: string, shape: string}>
	 */
	private static function active_competing_plugins(): array {
		if ( ! \function_exists( 'is_plugin_active' ) ) {
			// Load the plugin.php helper from wp-admin so the CLI path can
			// still see active plugins. `wp_admin` constant is unrelated;
			// the require is gated to avoid noise on environments where
			// wp-admin/includes is missing (unlikely but defensive).
			$plugin_helper = \ABSPATH . 'wp-admin/includes/plugin.php';
			if ( \is_readable( $plugin_helper ) ) {
				require_once $plugin_helper;
			}
		}

		if ( ! \function_exists( 'is_plugin_active' ) ) {
			return array();
		}

		$out = array();
		foreach ( self::known_plugins() as $slug => $meta ) {
			if ( \is_plugin_active( $slug ) ) {
				$out[] = array(
					'slug'  => $slug,
					'name'  => $meta['name'],
					'url'   => $meta['url'],
					'shape' => $meta['shape'],
				);
			}
		}
		return $out;
	}

	/**
	 * Filesystem check — competing static-file writers (most notably the
	 * "LLMs.txt and LLMs-Full.txt Generator" plugin) drop a literal
	 * `llms.txt` at the public site root. The web server serves that file
	 * before WP boots, so our rewrite never fires.
	 *
	 * Uses `get_home_path()` (the public document root) rather than ABSPATH
	 * so detection is correct on subdirectory installs where the two differ.
	 *
	 * @return array{kind: string, path: string}|null
	 */
	private static function filesystem_conflict(): ?array {
		if ( ! \function_exists( 'get_home_path' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = \get_home_path() . 'llms.txt';
		if ( ! \file_exists( $path ) ) {
			return null;
		}
		return array(
			'kind' => 'filesystem',
			'path' => $path,
		);
	}

	/**
	 * Rewrite-rule scan — if another plugin claimed our regex key with a
	 * non-ours target, our route is shadowed. The `extra_rules_top`
	 * surface is a flat string→string map; only the last registered
	 * value for a given regex remains.
	 *
	 * Backup signal for plugins we don't have in the registry. Slug-based
	 * detection catches the known competitors; this catches the unknown
	 * ones.
	 *
	 * @return array{kind: string, rule: string}|null
	 */
	private static function rewrite_conflict(): ?array {
		global $wp_rewrite;

		if ( ! isset( $wp_rewrite->extra_rules_top ) || ! is_array( $wp_rewrite->extra_rules_top ) ) {
			return null;
		}
		if ( ! isset( $wp_rewrite->extra_rules_top[ self::REWRITE_KEY ] ) ) {
			// Nobody registered it — neither us nor a competitor. This
			// can happen if our init hook hasn't fired yet (very early
			// request) but in normal admin_init flow we've already
			// registered. Treat absence as "no conflict surfaced via this
			// signal" — the route still works once our init runs.
			return null;
		}

		$value = (string) $wp_rewrite->extra_rules_top[ self::REWRITE_KEY ];
		if ( false !== strpos( $value, self::REWRITE_FINGERPRINT ) ) {
			// Our value is there — we own this regex. No conflict.
			return null;
		}

		return array(
			'kind' => 'rewrite',
			'rule' => $value,
		);
	}
}
