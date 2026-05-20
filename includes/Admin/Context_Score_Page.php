<?php
/**
 * Context Score admin page — Tools → Context Score (#10 / AgDR-0031).
 *
 * Hosts the React UI that consumes the breakdown shipped in AgDR-0030,
 * and is the deep-link target for both the Site Health test (this
 * module's `Site_Health` class) and the recompute REST endpoint.
 *
 * The bundle is built from `src/admin/context-score/index.js` by the
 * project's multi-entry webpack config (`webpack.config.js`); no
 * config change is required to pick it up.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

use WPContext\Context_Score\Engine;
use WPContext\Context_Score\Rest_Controller;
use WPContext\Context_Score\Service;
use WPContext\Seo\Plugin_Coverage;
use WPContext\Seo\Schema_Emitter;

/**
 * Tools → Context Score page bootstrap.
 *
 * Owns three responsibilities:
 *   1. Register the menu entry under Tools.
 *   2. Render the page mount-point (`<div id="agentready-context-score-root">`)
 *      plus a no-JS fallback notice pointing at the CLI alternative.
 *   3. Enqueue the React bundle ONLY on the plugin's screen — never globally.
 */
final class Context_Score_Page {

	/**
	 * Menu / page slug under Tools.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'agentready-context-score';

	/**
	 * Suffix returned by add_management_page (`tools_page_<PAGE_SLUG>`),
	 * captured so the enqueue hook only fires on this screen.
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
	 * Register the Tools → Context Score menu entry.
	 */
	public static function register_menu(): void {
		self::$hook_suffix = \add_management_page(
			\__( 'Agent Ready Context Score', 'agent-ready' ),
			\__( 'Context Score', 'agent-ready' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the page mount-point.
	 *
	 * The actual UI is React-driven from `build/admin/context-score.js`.
	 * This server-rendered shell carries a `<noscript>` fallback pointing
	 * at the CLI as the no-JS path, and a `role="region"` mount-point
	 * with an aria-label for screen readers.
	 */
	public static function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to access this page.', 'agent-ready' ),
				\esc_html__( 'Forbidden', 'agent-ready' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap" id="agentready-context-score-wrap">
			<h1><?php \esc_html_e( 'Context Score', 'agent-ready' ); ?></h1>
			<p class="description">
				<?php
				\esc_html_e(
					'A site-level audit of how prepared this WordPress install is for AI agent traffic. The overall score is a weighted sum of six sub-scores covering discoverability, content readability, schema coverage, exposure safety, integration health, and Markdown conversion quality.',
					'agent-ready'
				);
				?>
			</p>

			<div
				id="agentready-context-score-root"
				role="region"
				aria-label="<?php \esc_attr_e( 'Agent Ready Context Score breakdown', 'agent-ready' ); ?>"
			></div>

			<noscript>
				<div class="notice notice-warning">
					<p>
						<?php
						\esc_html_e(
							'The Context Score panel requires JavaScript. Enable JavaScript and reload this page, or run "wp agent-ready context-score audit" from the command line for an equivalent JSON breakdown.',
							'agent-ready'
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
	 * Only enqueues on the Context Score screen — `$hook` matches the
	 * suffix returned by `add_management_page`
	 * (`tools_page_agentready-context-score`).
	 *
	 * The bundle is built with `@wordpress/scripts`. If the build artefact
	 * is missing (running from a source checkout without `npm run build`),
	 * an admin notice points the user at the build step rather than
	 * silently failing. Same defensive shape as `Context_Profile_Page`.
	 *
	 * @param string $hook Current admin screen hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( null === self::$hook_suffix || $hook !== self::$hook_suffix ) {
			return;
		}

		$asset_file = \WPCTX_DIR . 'build/admin/context-score.asset.php';
		$script_url = \WPCTX_URL . 'build/admin/context-score.js';
		$style_url  = \WPCTX_URL . 'build/admin/context-score.css';

		if ( ! \file_exists( $asset_file ) ) {
			\add_action( 'admin_notices', array( self::class, 'render_missing_build_notice' ) );
			return;
		}

		// Defensive require with safe-default fallback (mirrors
		// Context_Profile_Page::enqueue_assets — see issue #33 for the
		// PHPStan static-resolution rationale around `asset_path()`).
		$resolved_asset = self::asset_path( $asset_file );
		$asset          = \is_readable( $resolved_asset )
			? require $resolved_asset
			: array(
				'dependencies' => array(),
				'version'      => \WPCTX_VERSION,
			);

		\wp_enqueue_script(
			'agentready-context-score',
			$script_url,
			\is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION,
			true
		);

		\wp_add_inline_script(
			'agentready-context-score',
			'window.agentreadyContextScore = ' . \wp_json_encode( self::bootstrap_data() ) . ';',
			'before'
		);

		\wp_set_script_translations(
			'agentready-context-score',
			'agent-ready',
			\WPCTX_DIR . 'languages'
		);

		if ( \file_exists( \WPCTX_DIR . 'build/admin/context-score.css' ) ) {
			\wp_enqueue_style(
				'agentready-context-score',
				$style_url,
				array( 'wp-components' ),
				\is_string( $asset['version'] ?? null ) ? $asset['version'] : \WPCTX_VERSION
			);
		}
	}

	/**
	 * Identity-resolve an asset path so PHPStan cannot statically follow it.
	 * See Context_Profile_Page::asset_path for the full rationale (#33).
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
			\esc_html__( 'Agent Ready Context Score UI bundle not found. Run:', 'agent-ready' ),
			\esc_html( 'npm install && npm run build' )
		);
	}

	/**
	 * Build the bootstrap payload exposed to the React app.
	 *
	 * Server-paints `initialBreakdown` so the UI renders without a fetch
	 * round-trip when the cache is populated. When null, the UI fires
	 * the GET route on mount.
	 *
	 * @return array<string, mixed>
	 */
	private static function bootstrap_data(): array {
		return array(
			'restNamespace'      => Rest_Controller::NAMESPACE,
			'restBase'           => Rest_Controller::ROUTE_BASE,
			'restNonce'          => \wp_create_nonce( 'wp_rest' ),
			'profilePageUrl'     => \admin_url( 'tools.php?page=' . Context_Profile_Page::PAGE_SLUG ),
			'siteHealthUrl'      => \admin_url( 'site-health.php' ),
			'weights'            => Engine::WEIGHTS,
			'initialBreakdown'   => Service::get_breakdown(),
			'schemaCoordination' => self::schema_coordination_payload(),
		);
	}

	/**
	 * Build the schema-coordination payload exposed to the React panel
	 * (#12 / AgDR-0033).
	 *
	 * Surfaces the detected SEO plugin posture and the per-type
	 * deference matrix so the agency lead can see at a glance:
	 *   - Which schema types are deferred to the active SEO plugin
	 *   - Which schema types Agent Ready fills (gap-fill, empty by default)
	 *   - Whether emission is suppressed by the `agentready_schema_emit` filter
	 *
	 * The values are computed at render time — same approach as the live
	 * detect() call on the Profile page — so a freshly-activated SEO
	 * plugin reflects without a recompute.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_coordination_payload(): array {
		$posture = Schema_Coordination_Detector::detect();
		$slug    = (string) ( $posture['posture'] ?? Schema_Coordination_Detector::POSTURE_NONE );

		$baseline = Plugin_Coverage::baseline_types();
		$gap      = Plugin_Coverage::compute_gap( $slug );
		$deferred = Plugin_Coverage::compute_deferred( $slug );

		// Hook name resolves to `agentready_schema_emit` — the constant is
		// prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filter_allows = false !== \apply_filters( Schema_Emitter::FILTER_EMIT_DECISION, true );

		// As of #73 / AgDR-0034, emission is gated by the Context Profile
		// toggle first, then by the per-request filter. The "emitting"
		// boolean reflects the AND of both.
		$profile          = Context_Profile_Settings::get_profile();
		$profile_opted_in = ! empty( $profile['schema_emit_enabled'] );
		$emitting         = $profile_opted_in && $filter_allows;

		return array(
			'posture'      => $slug,
			'label'        => (string) ( $posture['label'] ?? '' ),
			'detectedVia'  => (string) ( $posture['detected_via'] ?? '' ),
			'baseline'     => $baseline,
			'deferred'     => $deferred,
			'filled'       => $gap,
			'emitting'     => $emitting,
			'profileOptIn' => $profile_opted_in,
		);
	}
}
