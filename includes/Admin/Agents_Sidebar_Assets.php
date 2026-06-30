<?php
/**
 * Enqueue the consolidated "AI agents" Gutenberg sidebar panel (#201).
 *
 * Fires on `enqueue_block_editor_assets` so the script loads only inside the
 * block editor (post edit / post new screens), not on every wp-admin page.
 *
 * Replaces the former Exclude_Sidebar_Assets (#180) and
 * Markdown_Views\Sidebar_Assets (AgDR-0014) pair — the exclude toggle and the
 * Markdown preview now ship as one panel, so one script bundle.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Asset registration for the agents-sidebar React panel (#201).
 *
 * Per AgDR-0015, the preview half of the panel respects the
 * `markdown_views_enabled` flag: we still enqueue the script when the module
 * is off (the React component's mount guard hides the preview section), so
 * re-enabling the toggle doesn't require a hard reload — and the exclude
 * toggle must render regardless, since exclusion also gates /llms.txt.
 *
 * Defensive `asset.php` loading mirrors the Context Profile pattern from #4
 * (file_exists guard + dynamic require path so PHPStan's `require.fileNotFound`
 * rule doesn't trip on CI cells that haven't run `npm run build`).
 */
final class Agents_Sidebar_Assets {

	/**
	 * Script handle registered with WordPress.
	 *
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'agentready-agents-sidebar';

	/**
	 * Wire WordPress hooks. Called from Main::register_hooks().
	 */
	public static function register_hooks(): void {
		\add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the React panel + its dependencies + the bootstrap data
	 * (currently just the module-enabled flag for the preview mount guard).
	 */
	public static function enqueue(): void {
		$asset_file = \MOKHAI_DIR . 'build/admin/agents-sidebar.asset.php';
		$script_url = \MOKHAI_URL . 'build/admin/agents-sidebar.js';
		$style_url  = \MOKHAI_URL . 'build/admin/agents-sidebar.css';

		if ( ! \file_exists( $asset_file ) ) {
			// Build artefact missing — fail silent in the editor rather than
			// surfacing a fatal. Devs running locally without `npm run build`
			// just don't see the panel; CI's check-build job catches missing
			// artefacts before merge.
			return;
		}

		$asset = self::load_asset_manifest( $asset_file );

		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \MOKHAI_VERSION,
			true
		);

		\wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.agentreadyAgentsSidebar = ' . \wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'mokhai-agent-readiness-kit',
			\MOKHAI_DIR . 'languages'
		);

		// Shared admin design-token stylesheet (#70). Carries the
		// `.agentready-md-*` classes the preview markup uses in place of
		// inline styles. Guarded on existence so a source checkout without a
		// build still loads the script (the panel degrades to unstyled <pre>).
		if ( \file_exists( \MOKHAI_DIR . 'build/admin/agents-sidebar.css' ) ) {
			\wp_enqueue_style(
				self::SCRIPT_HANDLE,
				$style_url,
				array( 'wp-components' ),
				\is_string( $asset['version'] ?? null ) ? $asset['version'] : \MOKHAI_VERSION
			);
		}
	}

	/**
	 * Server-rendered bootstrap data exposed to the React panel via the
	 * `window.agentreadyAgentsSidebar` global. Kept minimal: the REST nonce
	 * is auto-attached by `wp.apiFetch`'s nonce middleware on admin pages, so
	 * we only need the module flag here.
	 *
	 * @return array{moduleEnabled: bool}
	 */
	private static function bootstrap_data(): array {
		return array(
			'moduleEnabled' => Context_Profile_Settings::is_module_enabled( 'markdown_views' ),
		);
	}

	/**
	 * Resolve the asset-manifest path via a method call so PHPStan can't
	 * statically resolve the require target — the manifest file is generated
	 * by `npm run build` and isn't present on CI cells that skip the build
	 * step. The defensive-fallback shape was introduced for #4's Context
	 * Profile panel; we mirror it here.
	 *
	 * @return array{dependencies?: array<int,string>, version?: string}
	 */
	private static function load_asset_manifest( string $path ): array {
		if ( ! \is_readable( $path ) ) {
			return array(
				'dependencies' => array(),
				'version'      => \MOKHAI_VERSION,
			);
		}

		$resolved = self::resolved_path( $path );
		$asset    = require $resolved;

		return \is_array( $asset ) ? $asset : array();
	}

	/**
	 * Identity helper. Indirection-through-method-call keeps PHPStan from
	 * statically tracking the require target back to a known file.
	 */
	private static function resolved_path( string $path ): string {
		return $path;
	}
}
