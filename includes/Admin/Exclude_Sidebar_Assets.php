<?php
/**
 * Enqueue the "Exclude from agent output" Gutenberg sidebar panel (#180).
 *
 * Fires on `enqueue_block_editor_assets` so the script loads only inside the
 * block editor. The panel binds a ToggleControl to the `_agentready_excluded`
 * post meta registered by {@see Exclude_Meta} via `useEntityProp`.
 *
 * Mirrors the defensive asset-loading shape of
 * {@see \WPContext\Markdown_Views\Sidebar_Assets}.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Asset registration for the exclude-sidebar React panel (#180).
 */
final class Exclude_Sidebar_Assets {

	/**
	 * Script handle registered with WordPress.
	 *
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'agentready-exclude-sidebar';

	/**
	 * Wire WordPress hooks. Called from Main::register_hooks().
	 */
	public static function register_hooks(): void {
		\add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the React panel + its dependencies.
	 */
	public static function enqueue(): void {
		$asset_file = \WPCTX_DIR . 'build/admin/exclude-sidebar.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/exclude-sidebar.js';

		if ( ! \file_exists( $asset_file ) ) {
			// Build artefact missing — fail silent in the editor rather than
			// surfacing a fatal. CI's check-build job catches missing
			// artefacts before merge.
			return;
		}

		$asset = self::load_asset_manifest( $asset_file );

		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		\wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'ai-readiness-kit',
			\WPCTX_DIR . 'languages'
		);
	}

	/**
	 * Resolve the asset manifest, defending against a source checkout that
	 * hasn't run `npm run build` (mirrors Markdown_Views\Sidebar_Assets).
	 *
	 * @return array{dependencies?: array<int,string>, version?: string}
	 */
	private static function load_asset_manifest( string $path ): array {
		if ( ! \is_readable( $path ) ) {
			return array(
				'dependencies' => array(),
				'version'      => \WPCTX_VERSION,
			);
		}

		$resolved = self::resolved_path( $path );
		$asset    = require $resolved;

		return \is_array( $asset ) ? $asset : array();
	}

	/**
	 * Identity helper — indirection keeps PHPStan from statically tracking the
	 * require target to a build-time-only file.
	 */
	private static function resolved_path( string $path ): string {
		return $path;
	}
}
