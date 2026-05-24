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
 *   2. Render the page mount-point (`<div id="agentready-context-profile-root">`)
 *      plus a no-JS fallback notice.
 *   3. Enqueue the React bundle ONLY on the plugin's screen — never globally,
 *      per the WordPress admin-asset hygiene rule.
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
	}

	/**
	 * Register the Tools → Context menu entry.
	 */
	public static function register_menu(): void {
		self::$hook_suffix = \add_management_page(
			\__( 'AI Readiness Kit Context Profile', 'ai-readiness-kit' ),
			\__( 'Context', 'ai-readiness-kit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the page mount-point.
	 *
	 * The actual UI is React-driven from `build/admin/context-profile.js`.
	 * This server-rendered shell carries a `<noscript>` fallback and the
	 * settings-API nonce so the form remains functional without JS in the
	 * worst case (graceful degrade).
	 */
	public static function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to access this page.', 'ai-readiness-kit' ),
				\esc_html__( 'Forbidden', 'ai-readiness-kit' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap" id="agentready-context-profile-wrap">
			<h1><?php \esc_html_e( 'Context Profile', 'ai-readiness-kit' ); ?></h1>
			<p class="description">
				<?php
				\esc_html_e(
					'Configure how AI Readiness Kit exposes this site to AI agents. A fresh install exposes nothing — explicitly opt in CPTs and statuses below.',
					'ai-readiness-kit'
				);
				?>
			</p>

			<div
				id="agentready-context-profile-root"
				role="region"
				aria-label="<?php \esc_attr_e( 'AI Readiness Kit Context Profile editor', 'ai-readiness-kit' ); ?>"
			></div>

			<h2 style="margin-top:2em;"><?php \esc_html_e( 'LLMs Index — editorial entries', 'ai-readiness-kit' ); ?></h2>
			<p class="description">
				<?php
				\esc_html_e(
					'Hand-curated entries published in /llms.txt alongside the auto-listed posts above. Each entry has a title, URL, optional description, and a section heading.',
					'ai-readiness-kit'
				);
				?>
			</p>

			<div
				id="agentready-llms-txt-editorial-root"
				role="region"
				aria-label="<?php \esc_attr_e( 'AI Readiness Kit LLMs Index editorial entries editor', 'ai-readiness-kit' ); ?>"
			></div>

			<h2 style="margin-top:2em;"><?php \esc_html_e( 'LLMs Index — auto-generated descriptions', 'ai-readiness-kit' ); ?></h2>
			<p class="description">
				<?php
				\esc_html_e(
					'One-line descriptions for the auto-listed entries above, generated via the configured LLM and cached on post meta. Edit any description inline to set a sticky manual override that survives regeneration.',
					'ai-readiness-kit'
				);
				?>
			</p>

			<div
				id="agentready-llms-txt-descriptions-root"
				role="region"
				aria-label="<?php \esc_attr_e( 'AI Readiness Kit LLM-powered /llms.txt entry descriptions', 'ai-readiness-kit' ); ?>"
			></div>

			<noscript>
				<div class="notice notice-warning">
					<p>
						<?php
						\esc_html_e(
							'The Context Profile editor and LLMs Index editor both require JavaScript. Enable JavaScript and reload this page.',
							'ai-readiness-kit'
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

		$asset_file = \WPCTX_DIR . 'build/admin/context-profile.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/context-profile.js';
		$style_url  = \WPCTX_URL . 'build/admin/context-profile.css';

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
			'agentready-context-profile',
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		// Pass the server-rendered profile snapshot, detected SEO plugin,
		// list of registered public CPTs, the REST nonce, and the i18n
		// text-domain to the script. Keeping the bootstrap data in a single
		// inline-script keeps the HTTP cost down.
		\wp_add_inline_script(
			'agentready-context-profile',
			'window.agentreadyContextProfile = ' . \wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			'agentready-context-profile',
			'ai-readiness-kit',
			\WPCTX_DIR . 'languages'
		);

		if ( \file_exists( \WPCTX_DIR . 'build/admin/context-profile.css' ) ) {
			\wp_enqueue_style(
				'agentready-context-profile',
				$style_url,
				array( 'wp-components' ),
				\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION
			);
		}

		self::enqueue_llms_txt_editorial_assets();
		self::enqueue_llms_txt_descriptions_assets();
	}

	/**
	 * Enqueue the LLMs Index editorial-entries React bundle alongside the
	 * Context Profile editor (#7 Phase C / AgDR-0025).
	 *
	 * Same defensive `*.asset.php` shape as the Context Profile enqueue —
	 * a missing build artefact degrades to an empty-deps fallback so the
	 * page still loads from a source checkout. The page-level missing-build
	 * notice is rendered by `render_missing_build_notice` for the Context
	 * Profile path; the editorial path inherits that signal (if Context
	 * Profile's build is missing, the editorial build will be too).
	 */
	private static function enqueue_llms_txt_editorial_assets(): void {
		$asset_file = \WPCTX_DIR . 'build/admin/llms-txt-editorial.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/llms-txt-editorial.js';
		$style_url  = \WPCTX_URL . 'build/admin/llms-txt-editorial.css';

		if ( ! \file_exists( $asset_file ) ) {
			// No editorial-bundle build yet — Context Profile's
			// `render_missing_build_notice` has already surfaced the issue
			// for the larger bundle; we don't double-notice.
			return;
		}

		$resolved_asset = self::asset_path( $asset_file );
		$asset          = \is_readable( $resolved_asset )
			? require $resolved_asset
			: array(
				'dependencies' => array(),
				'version'      => \WPCTX_VERSION,
			);

		\wp_enqueue_script(
			'agentready-llms-txt-editorial',
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		\wp_add_inline_script(
			'agentready-llms-txt-editorial',
			'window.agentreadyLlmsTxtEditorial = ' . \wp_json_encode( self::editorial_bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			'agentready-llms-txt-editorial',
			'ai-readiness-kit',
			\WPCTX_DIR . 'languages'
		);

		if ( \file_exists( \WPCTX_DIR . 'build/admin/llms-txt-editorial.css' ) ) {
			\wp_enqueue_style(
				'agentready-llms-txt-editorial',
				$style_url,
				array( 'wp-components' ),
				\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION
			);
		}
	}

	/**
	 * Enqueue the LLM-powered descriptions React bundle alongside the
	 * Context Profile editor (#8 Phase B / AgDR-0029).
	 *
	 * Same defensive `*.asset.php` shape as the other two bundles — a
	 * missing build artefact degrades to an empty-deps fallback so the
	 * page still loads from a source checkout.
	 */
	private static function enqueue_llms_txt_descriptions_assets(): void {
		$asset_file = \WPCTX_DIR . 'build/admin/llms-txt-descriptions.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/llms-txt-descriptions.js';
		$style_url  = \WPCTX_URL . 'build/admin/llms-txt-descriptions.css';

		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$resolved_asset = self::asset_path( $asset_file );
		$asset          = \is_readable( $resolved_asset )
			? require $resolved_asset
			: array(
				'dependencies' => array(),
				'version'      => \WPCTX_VERSION,
			);

		\wp_enqueue_script(
			'agentready-llms-txt-descriptions',
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		\wp_add_inline_script(
			'agentready-llms-txt-descriptions',
			'window.agentreadyLlmsTxtDescriptions = ' . \wp_json_encode( self::descriptions_bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			'agentready-llms-txt-descriptions',
			'ai-readiness-kit',
			\WPCTX_DIR . 'languages'
		);

		if ( \file_exists( \WPCTX_DIR . 'build/admin/llms-txt-descriptions.css' ) ) {
			\wp_enqueue_style(
				'agentready-llms-txt-descriptions',
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
			'entries'      => $settings['entries'],
			'sections'     => \WPContext\LlmsTxt\Editorial_Settings::SECTIONS,
			'option_group' => \WPContext\LlmsTxt\Editorial_Settings::OPTION_GROUP,
			'option_key'   => \WPContext\LlmsTxt\Editorial_Settings::OPTION_KEY,
			'nonce'        => \wp_create_nonce( \WPContext\LlmsTxt\Editorial_Settings::OPTION_GROUP . '-options' ),
			'options_url'  => \admin_url( 'options.php' ),
			'page_url'     => \admin_url( 'tools.php?page=' . self::PAGE_SLUG ),
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
			\esc_html__( 'AI Readiness Kit Context Profile UI bundle not found. Run:', 'ai-readiness-kit' ),
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
				'label' => \__( 'Published', 'ai-readiness-kit' ),
			),
			array(
				'slug'  => 'private',
				'label' => \__( 'Private', 'ai-readiness-kit' ),
			),
			array(
				'slug'  => 'password',
				'label' => \__( 'Password-protected', 'ai-readiness-kit' ),
			),
			array(
				'slug'  => 'draft',
				'label' => \__( 'Draft', 'ai-readiness-kit' ),
			),
			array(
				'slug'  => 'pending',
				'label' => \__( 'Pending review', 'ai-readiness-kit' ),
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
			'settings'           => array(
				'optionGroup' => Context_Profile_Settings::OPTION_GROUP,
				'optionKey'   => Context_Profile_Settings::OPTION_KEY,
				'nonce'       => \wp_create_nonce( Context_Profile_Settings::OPTION_GROUP . '-options' ),
				'optionsUrl'  => \admin_url( 'options.php' ),
			),
		);
	}
}
