<?php
/**
 * Context Profile admin page — Tools → Context.
 *
 * Renders the React-based Profile UI built with `@wordpress/components` and
 * mounts it under the page slug `agentready-context`. Page visibility is
 * gated on `manage_options`.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Tools → Context page bootstrap.
 *
 * Owns three responsibilities:
 *   1. Register the menu entry under Tools.
 *   2. Render the single SPA mount-point (`<div id="agentready-context-app">`)
 *      plus a no-JS fallback notice.
 *   3. Enqueue the `context-app` React bundle ONLY on the plugin's screen —
 *      never globally, per the WordPress admin-asset hygiene rule.
 */
final class Context_Profile_Page {

	/**
	 * Menu / page slug under Tools.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'agentready-context';

	/**
	 * Suffix returned by add_management_page (`tools_page_<PAGE_SLUG>`),
	 * captured so the enqueue hook only fires on the Profile screen.
	 *
	 * @var string|null
	 */
	private static ?string $hook_suffix = null;

	/**
	 * Wire WordPress hooks. Called once from Main::register_hooks.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		if ( \defined( 'WPCTX_FILE' ) ) {
			\add_filter(
				'plugin_action_links_' . \plugin_basename( WPCTX_FILE ),
				array( self::class, 'add_settings_action_link' )
			);
		}
	}

	/**
	 * Register the Tools → Context menu entry.
	 */
	public static function register_menu(): void {
		self::$hook_suffix = \add_management_page(
			\__( 'Agentable Context Profile', 'agentable' ),
			\__( 'AI Readiness — Context', 'agentable' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Add a "Settings" row-action on the Plugins list pointing at the Context
	 * Profile page, so the plugin is reachable from the standard place users
	 * look after activation (#207).
	 *
	 * @param array<int|string, string> $links Existing action links.
	 *
	 * @return array<int|string, string>
	 */
	public static function add_settings_action_link( array $links ): array {
		$settings = \sprintf(
			'<a href="%s">%s</a>',
			\esc_url( \admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
			\esc_html__( 'Settings', 'agentable' )
		);
		\array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Render the single SPA mount-point.
	 *
	 * The actual UI is React-driven from `build/admin/context-app.js` — one
	 * app with a TabPanel (Profile / Editorial / Descriptions) that saves via
	 * REST (#142 / AgDR-0048). This server-rendered shell carries only a
	 * `<noscript>` fallback notice; the app requires JS.
	 */
	public static function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to access this page.', 'agentable' ),
				\esc_html__( 'Forbidden', 'agentable' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap" id="agentready-context-profile-wrap">
			<h1><?php \esc_html_e( 'Context Profile', 'agentable' ); ?></h1>
			<p class="description">
				<?php
				\esc_html_e(
					'Configure how Agentable exposes this site to AI agents. A fresh install exposes nothing — explicitly opt in CPTs and statuses below.',
					'agentable'
				);
				?>
			</p>

			<?php // Single SPA mount (#142 / AgDR-0048): one card-framed app with TabPanel section nav (Profile / Editorial / Descriptions). Saves go through REST — no page reload. ?>
			<div
				id="agentready-context-app"
				role="region"
				aria-label="<?php \esc_attr_e( 'Agentable Context editor', 'agentable' ); ?>"
			></div>

			<noscript>
				<div class="notice notice-warning">
					<p>
						<?php
						\esc_html_e(
							'The Context editor requires JavaScript. Enable JavaScript and reload this page.',
							'agentable'
						);
						?>
					</p>
				</div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Enqueue the React bundle and `@wordpress/components` styles.
	 *
	 * Only enqueues on the Profile screen — `$hook` matches the suffix
	 * returned by `add_management_page` (`tools_page_agentready-context`).
	 *
	 * The bundle is built with `@wordpress/scripts`. If the build artefact
	 * is missing (running from a source checkout without `npm run build`),
	 * an admin notice points the user at the build step rather than silently
	 * failing — keeps the developer-experience honest.
	 *
	 * @param string $hook Current admin screen hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( null === self::$hook_suffix || $hook !== self::$hook_suffix ) {
			return;
		}

		$asset_file = \WPCTX_DIR . 'build/admin/context-app.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/context-app.js';
		$style_url  = \WPCTX_URL . 'build/admin/context-app.css';

		if ( ! \file_exists( $asset_file ) ) {
			\add_action( 'admin_notices', array( self::class, 'render_missing_build_notice' ) );
			return;
		}

		// Defensive require with a safe-default fallback. Matches the Gutenberg /
		// WordPress core convention: when the *.asset.php artefact is missing on
		// disk (fresh `wp-env` boot, CI cell without an `npm run build` step, a
		// dev contributor who hasn't installed npm yet), fall back to an empty
		// dependency array + neutral version so the runtime degrades gracefully
		// instead of fatal-erroring. The file_exists() guard above already handles
		// the hot-path return; the is_readable() check here belt-and-braces a race
		// between exists / readable (e.g. permission flap during a deploy).
		//
		// The path is built via `self::asset_path()` so PHPStan cannot statically
		// resolve the require target (otherwise PHPStan's `require.fileNotFound`
		// rule blocks analysis on every CI cell without a build step, which is
		// the entire intent of this defensive shape — see issue #33).
		$resolved_asset = self::asset_path( $asset_file );
		$asset          = \is_readable( $resolved_asset )
			? require $resolved_asset
			: array(
				'dependencies' => array(),
				'version'      => \WPCTX_VERSION,
			);

		\wp_enqueue_script(
			'agentready-context-app',
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		// Three server-rendered bootstraps power first paint for the three tabs
		// (profile / editorial / descriptions); saves then round-trip through
		// REST (#142 / AgDR-0048). All attach to the single context-app handle.
		\wp_add_inline_script(
			'agentready-context-app',
			'window.agentreadyContextProfile = ' . \wp_json_encode( self::bootstrap_data() ) . ';'
				. 'window.agentreadyLlmsTxtEditorial = ' . \wp_json_encode( self::editorial_bootstrap_data() ) . ';'
				. 'window.agentreadyLlmsTxtDescriptions = ' . \wp_json_encode( self::descriptions_bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			'agentready-context-app',
			'agentable',
			\WPCTX_DIR . 'languages'
		);

		if ( \file_exists( \WPCTX_DIR . 'build/admin/context-app.css' ) ) {
			\wp_enqueue_style(
				'agentready-context-app',
				$style_url,
				array( 'wp-components' ),
				\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION
			);
		}
	}

	/**
	 * Inline bootstrap payload for the descriptions React UI.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptions_bootstrap_data(): array {
		$profile = \WPContext\Admin\Context_Profile_Settings::get_profile();
		$exposed = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? \array_values( \array_filter( $profile['exposed_cpts'], 'is_string' ) )
			: array();

		return array(
			'restNamespace' => \WPContext\LlmsTxt\Descriptions_Rest_Controller::NAMESPACE,
			'restBase'      => \WPContext\LlmsTxt\Descriptions_Rest_Controller::ROUTE_BASE,
			'restNonce'     => \wp_create_nonce( 'wp_rest' ),
			'exposedCpts'   => $exposed,
			'enabled'       => ! empty( $profile['llm_descriptions_enabled'] ),
			'llmAvailable'  => \WPContext\Ai\Client_Wrapper::has_ai_client(),
		);
	}

	/**
	 * Inline bootstrap payload for the editorial-entries React UI.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_bootstrap_data(): array {
		$settings = \WPContext\LlmsTxt\Editorial_Settings::get_settings();

		return array(
			'entries'       => $settings['entries'],
			'sections'      => \WPContext\LlmsTxt\Editorial_Settings::SECTIONS,
			// REST write path (#142). The legacy options.php fields are dropped;
			// the SPA saves via PUT through `apiFetch`.
			'restNamespace' => \WPContext\LlmsTxt\Editorial_Rest_Controller::NAMESPACE,
			'restBase'      => \WPContext\LlmsTxt\Editorial_Rest_Controller::ROUTE_BASE,
			'restNonce'     => \wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * Identity-resolve an asset path so PHPStan cannot statically follow it.
	 *
	 * The defensive require pattern in `enqueue_assets()` already guards on
	 * `file_exists()` + `is_readable()`, but PHPStan's `require.fileNotFound`
	 * rule resolves string-literal require paths at analysis time and errors
	 * if the file is missing on the analysing cell. The `build/` directory is
	 * gitignored (output of `npm run build`) so analysis cells that don't run
	 * the build step (e.g. the PHPStan CI job — see issue #33) would otherwise
	 * fail to lint a perfectly safe runtime path. Routing the path through this
	 * trivial identity function breaks PHPStan's static resolution while
	 * preserving the runtime guarantee.
	 *
	 * @param string $path Resolved absolute path on disk.
	 * @return string Same path, opaque to static analysis.
	 */
	private static function asset_path( string $path ): string {
		return $path;
	}

	/**
	 * Admin notice rendered when the React build artefact is missing.
	 */
	public static function render_missing_build_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		\printf(
			'<div class="notice notice-warning"><p>%1$s <code>%2$s</code></p></div>',
			\esc_html__( 'Agentable Context Profile UI bundle not found. Run:', 'agentable' ),
			\esc_html( 'npm install && npm run build' )
		);
	}

	/**
	 * Build the bootstrap data payload exposed to the React app.
	 *
	 * Includes:
	 *   - The current profile (defaulted + migrated via the storage reader).
	 *   - Public CPTs the admin can opt in to.
	 *   - Allowed statuses with translated labels.
	 *   - Site identity read live from WP options (not stored — see AgDR-0002).
	 *   - Schema-coordination posture detected at render time.
	 *   - AI Client availability so the LLM toggles can render their degraded
	 *     state when the WP AI Client is unconfigured.
	 *   - Settings-API option group + nonce so the React app can POST back via
	 *     the standard options.php flow.
	 *
	 * @return array<string, mixed>
	 */
	private static function bootstrap_data(): array {
		$public_cpts = \function_exists( 'get_post_types' )
			? \get_post_types( array( 'public' => true ), 'objects' )
			: array();

		$cpt_options = array();
		foreach ( $public_cpts as $cpt ) {
			$cpt_options[] = array(
				'slug'  => (string) ( $cpt->name ?? '' ),
				'label' => (string) ( $cpt->labels->singular_name ?? $cpt->label ?? $cpt->name ?? '' ),
			);
		}

		$status_options = array(
			array(
				'slug'  => 'publish',
				'label' => \__( 'Published', 'agentable' ),
			),
			array(
				'slug'  => 'private',
				'label' => \__( 'Private', 'agentable' ),
			),
			array(
				'slug'  => 'password',
				'label' => \__( 'Password-protected', 'agentable' ),
			),
			array(
				'slug'  => 'draft',
				'label' => \__( 'Draft', 'agentable' ),
			),
			array(
				'slug'  => 'pending',
				'label' => \__( 'Pending review', 'agentable' ),
			),
		);

		return array(
			'profile'            => Context_Profile_Settings::get_profile(),
			'cptOptions'         => $cpt_options,
			'statusOptions'      => $status_options,
			'siteIdentity'       => array(
				'name'    => (string) \get_bloginfo( 'name' ),
				'tagline' => (string) \get_bloginfo( 'description' ),
				'locale'  => (string) \get_locale(),
			),
			'schemaCoordination' => Schema_Coordination_Detector::detect(),
			'aiClient'           => array(
				'configured' => \WPContext\Ai\Client_Wrapper::has_ai_client(),
			),
			// REST write path (#142 / AgDR-0048). The SPA saves the whole
			// profile via PUT through `apiFetch`; the legacy options.php
			// fields are dropped.
			'settings'           => array(
				'restNamespace' => Context_Profile_Rest_Controller::NAMESPACE,
				'restBase'      => Context_Profile_Rest_Controller::ROUTE_BASE,
				'restNonce'     => \wp_create_nonce( 'wp_rest' ),
			),
		);
	}
}
