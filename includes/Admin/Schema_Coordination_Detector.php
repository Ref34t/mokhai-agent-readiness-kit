<?php
/**
 * Schema-coordination detector — detects active SEO plugins.
 *
 * Read-only helper: never persists state. Per PRD FR-8 ("Deferral to existing
 * SEO plugins"), AgentReady defers JSON-LD coordination to whichever SEO
 * plugin owns it — Yoast / Rank Math / AIOSEO. The Context Profile screen
 * surfaces the detected state read-only so the agency lead sees which plugin
 * AgentReady is deferring to.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Detects active SEO plugins by class presence and `is_plugin_active()`.
 *
 * Why two signals (class + plugin file)? Some SEO plugins ship under multiple
 * `plugin-name/plugin-name.php` paths (free vs premium), but always expose the
 * same canonical class. Class-presence is the resilient signal; the plugin-
 * file check is the corroborating one used when the class might be loaded by
 * an unrelated drop-in.
 *
 * Detection runs at admin-page render time, never persisted — switching SEO
 * plugins immediately reflects in the Profile screen without a re-save.
 */
final class Schema_Coordination_Detector {

	/**
	 * Slug returned when no recognised SEO plugin is active.
	 *
	 * @var string
	 */
	public const POSTURE_NONE = 'none';

	/**
	 * Plugin signatures: slug => [ 'label', 'class', 'plugin_file' ].
	 *
	 * `class` is the canonical class the plugin loads on init. `plugin_file`
	 * is the conventional active-plugin entry — used as a corroborating
	 * signal only.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SIGNATURES = array(
		'yoast'     => array(
			'label'       => 'Yoast SEO',
			'class'       => 'WPSEO_Options',
			'plugin_file' => 'wordpress-seo/wp-seo.php',
		),
		'rank_math' => array(
			'label'       => 'Rank Math',
			'class'       => 'RankMath',
			'plugin_file' => 'seo-by-rank-math/rank-math.php',
		),
		'aioseo'    => array(
			'label'       => 'All in One SEO',
			'class'       => 'AIOSEO\\Plugin\\AIOSEO',
			'plugin_file' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
		),
	);

	/**
	 * Detect the active SEO plugin, if any.
	 *
	 * Returns an array with `posture` (the slug or 'none'), `label` (the
	 * human-readable name), and `detected_via` (`class`, `plugin_file`, or
	 * empty when posture is 'none'). The Profile UI uses this to render the
	 * read-only deference state.
	 *
	 * @return array{posture: string, label: string, detected_via: string}
	 */
	public static function detect(): array {
		foreach ( self::SIGNATURES as $slug => $sig ) {
			if ( \class_exists( $sig['class'] ) ) {
				return array(
					'posture'      => $slug,
					'label'        => $sig['label'],
					'detected_via' => 'class',
				);
			}

			if ( self::is_plugin_active( $sig['plugin_file'] ) ) {
				return array(
					'posture'      => $slug,
					'label'        => $sig['label'],
					'detected_via' => 'plugin_file',
				);
			}
		}

		return array(
			'posture'      => self::POSTURE_NONE,
			'label'        => '',
			'detected_via' => '',
		);
	}

	/**
	 * `is_plugin_active()` shim. WP's helper lives in wp-admin/includes/plugin.php
	 * and isn't always loaded in admin-init context; load it on demand so the
	 * detector is safe to call from any admin entry point.
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
